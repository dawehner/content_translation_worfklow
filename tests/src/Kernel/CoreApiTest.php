<?php

namespace Drupal\Tests\content_translation_workflow\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;

/**
 * Tests the core API for revision translations.
 *
 * Note: This test is mostly about checking out the API layer.
 *
 * @group content_translation_revision
 */
class CoreApiTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'user',
    'content_translation_workflow',
    'content_translation',
    'workbench_moderation',
    'language',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->container->get('kernel')->rebuildContainer();

    /** @var \Drupal\node\NodeTypeInterface $node_type */
    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $node_type->setThirdPartySetting('workbench_moderation', 'enabled', TRUE);
    $node_type->setThirdPartySetting('workbench_moderation', 'allowed_moderation_states', ['draft', 'published']);
    $node_type->setThirdPartySetting('workbench_moderation', 'default_moderation_state', 'draft');

    $node_type->save();

    ContentLanguageSettings::create([
      'id' => 'node.article',
      'target_entity_type_id' => 'node',
      'target_bundle' => 'article',
    ]);

    $this->installSchema('system', 'sequence');
    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig('workbench_moderation');
  }

  protected function ensureForwardRevision() {
    \Drupal::service('content_translation_workflow.event_subscriber.end_of_request_queue_executor')
      ->onTerminate();
  }

  /**
   * Tests the Drupal core entity API with revisions and translations.
   */
  public function testApi() {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    /** @var \Drupal\workbench_moderation\ModerationInformationInterface $moderation_information */
    $moderation_information = \Drupal::service('workbench_moderation.moderation_information');
    $entity = Node::create([
      'type' => 'article',
      'title' => 'en-name--0',
      'moderation_state' => ['target_id' => 'published'],
    ]);
    $entity->addTranslation('fr', ['title' => 'fr-name--0', 'moderation_state' => ['target_id' => 'published']]);
    $entity->addTranslation('de', ['title' => 'de-name--0', 'moderation_state' => ['target_id' => 'published']]);
    $this->assertCount(0, $entity->validate());
    $entity->save();

    $entity = $storage->load($entity->id());
    $this->assertEquals('en-name--0', $entity->label());
    $this->assertEquals('fr-name--0', $entity->getTranslation('fr')->label());
    $this->assertEquals('de-name--0', $entity->getTranslation('de')->label());

    /** @var \Drupal\node\NodeInterface $entity_en */
    $entity_en = clone $entity;
    $entity_en->setNewRevision(TRUE);
    $entity_en->set('title', 'en-name--1');
    $entity_en->moderation_state->target_id = 'draft';
    $entity_en->save();

    /** @var \Drupal\node\NodeInterface $entity_fr */
    $entity_fr = $entity_en->getTranslation('fr');
    $entity_fr->setNewRevision(TRUE);
    $entity_fr->set('title', 'fr-name--1');
    $entity_fr->moderation_state->target_id = 'draft';
    $entity_fr->save();

    /** @var \Drupal\node\NodeInterface $entity_fr */
    $entity_de = $entity_en->getTranslation('de');
    $entity_de->setNewRevision(TRUE);
    $entity_de->set('title', 'de-name--1');
    $entity_de->moderation_state->target_id = 'draft';
    $entity_de->save();

    // Publish the english language. of the languages.
    $entity_en = $storage->loadRevision($entity_en->getRevisionId());
    $entity_en->isDefaultRevision(TRUE);
    $entity_en->setPublished(TRUE);
    $entity_en->moderation_state->target_id = 'published';
    $entity_en->save();
    $this->ensureForwardRevision();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $storage->loadUnchanged($entity_en->id());
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('fr')->isPublished());
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--0', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--0', $entity->getTranslation('de')->getTitle());

    // Ensure we also created a new forward revision with the other revisions.
    $latest_revision_id = $moderation_information->getLatestRevisionId('node', $entity->id());
    $this->assertLessThan($latest_revision_id, $entity->getRevisionId());
    /** @var \Drupal\node\NodeInterface $latest_revision */
    $latest_revision = $storage->loadRevision($latest_revision_id);
    $this->assertEquals('published', $latest_revision->getTranslation('en')->get('moderation_state')->target_id);
    $this->assertTrue($latest_revision->isPublished());
    $this->assertEquals('draft', $latest_revision->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertFalse($latest_revision->getTranslation('fr')->isPublished());
    $this->assertEquals('draft', $latest_revision->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertFalse($latest_revision->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $latest_revision->getTitle());
    $this->assertEquals('fr-name--1', $latest_revision->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $latest_revision->getTranslation('de')->getTitle());

    // Now try to publish another of the languages.
    // This requires the existing published english content to be copied over
    // as well.
    $entity_fr = $storage->loadRevision($latest_revision->getRevisionId())->getTranslation('fr');
    $entity_fr->isDefaultRevision(TRUE);
    $entity_fr->setPublished(TRUE);
    $entity_fr->moderation_state->target_id = 'published';
    $entity_fr->save();
    $this->ensureForwardRevision();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $storage->loadUnchanged($entity_en->id());
    // $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('fr')->isPublished());
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--1', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--0', $entity->getTranslation('de')->getTitle());

    // Ensure we also created a new forward revision with the other revisions.
    $latest_revision_id = $moderation_information->getLatestRevisionId('node', $entity->id());
    $this->assertLessThan($latest_revision_id, $entity->getRevisionId());
    /** @var \Drupal\node\NodeInterface $latest_revision */
    $latest_revision = $storage->loadRevision($latest_revision_id);
    $this->assertEquals('published', $latest_revision->getTranslation('en')->get('moderation_state')->target_id);
    $this->assertTrue($latest_revision->isPublished());
    $this->assertEquals('published', $latest_revision->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($latest_revision->getTranslation('fr')->isPublished());
    $this->assertEquals('draft', $latest_revision->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertFalse($latest_revision->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $latest_revision->getTitle());
    $this->assertEquals('fr-name--1', $latest_revision->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $latest_revision->getTranslation('de')->getTitle());

    // Now publish the last language.
    $entity_de = $storage->loadRevision($latest_revision->getRevisionId())->getTranslation('de');
    $entity_de->isDefaultRevision(TRUE);
    $entity_de->setPublished(TRUE);
    $entity_de->moderation_state->target_id = 'published';
    $entity_de->save();
    $this->ensureForwardRevision();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $storage->loadUnchanged($entity_en->id());
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('fr')->isPublished());
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('de')->isPublished());
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--1', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $entity->getTranslation('de')->getTitle());

    // Now that we don't have any further non published translation, we don't
    // expect another forward revision.
    $latest_revision_id = $moderation_information->getLatestRevisionId('node', $entity->id());
    $this->assertEquals($latest_revision_id, $entity->getRevisionId());
  }

  public function testApiWithOneLanguageNeverPublished() {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    /** @var \Drupal\workbench_moderation\ModerationInformationInterface $moderation_information */
    $moderation_information = \Drupal::service('workbench_moderation.moderation_information');
    $entity = Node::create([
      'type' => 'article',
      'title' => 'en-name--0',
      'moderation_state' => ['target_id' => 'published'],
    ]);
    $entity->addTranslation('fr', ['title' => 'fr-name--0', 'moderation_state' => ['target_id' => 'published']]);
    $entity->addTranslation('de', ['title' => 'de-name--0', 'moderation_state' => ['target_id' => 'draft']]);
    $this->assertCount(0, $entity->validate());
    $entity->save();

    $entity = $storage->load($entity->id());
    $this->assertEquals('en-name--0', $entity->label());
    $this->assertEquals('fr-name--0', $entity->getTranslation('fr')->label());
    $this->assertEquals('de-name--0', $entity->getTranslation('de')->label());

    /** @var \Drupal\node\NodeInterface $entity_en */
    $entity_en = clone $entity;
    $entity_en->setNewRevision(TRUE);
    $entity_en->set('title', 'en-name--1');
    $entity_en->moderation_state->target_id = 'draft';
    $entity_en->save();

    /** @var \Drupal\node\NodeInterface $entity_fr */
    $entity_fr = $entity_en->getTranslation('fr');
    $entity_fr->setNewRevision(TRUE);
    $entity_fr->set('title', 'fr-name--1');
    $entity_fr->moderation_state->target_id = 'draft';
    $entity_fr->save();

    /** @var \Drupal\node\NodeInterface $entity_fr */
    $entity_de = $entity_en->getTranslation('de');
    $entity_de->setNewRevision(TRUE);
    $entity_de->set('title', 'de-name--1');
    $entity_de->moderation_state->target_id = 'draft';
    $entity_de->save();

    // Publish the english language. of the languages.
    $entity_en = $storage->loadRevision($entity_en->getRevisionId());
    $entity_en->isDefaultRevision(TRUE);
    $entity_en->setPublished(TRUE);
    $entity_en->moderation_state->target_id = 'published';
    $entity_en->save();
    $this->ensureForwardRevision();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $storage->loadUnchanged($entity_en->id());
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('fr')->isPublished());
    $this->assertEquals('draft', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertFalse($entity->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--0', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $entity->getTranslation('de')->getTitle());

    // Ensure we also created a new forward revision with the other revisions.
    $latest_revision_id = $moderation_information->getLatestRevisionId('node', $entity->id());
    $this->assertLessThan($latest_revision_id, $entity->getRevisionId());
    /** @var \Drupal\node\NodeInterface $latest_revision */
    $latest_revision = $storage->loadRevision($latest_revision_id);
    $this->assertEquals('published', $latest_revision->getTranslation('en')->get('moderation_state')->target_id);
    $this->assertTrue($latest_revision->isPublished());
    $this->assertEquals('draft', $latest_revision->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertFalse($latest_revision->getTranslation('fr')->isPublished());
    $this->assertEquals('draft', $latest_revision->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertFalse($latest_revision->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $latest_revision->getTitle());
    $this->assertEquals('fr-name--1', $latest_revision->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $latest_revision->getTranslation('de')->getTitle());

    // Now try to publish another of the languages.
    // This requires the existing published english content to be copied over
    // as well.
    $entity_fr = $storage->loadRevision($latest_revision->getRevisionId())->getTranslation('fr');
    $entity_fr->isDefaultRevision(TRUE);
    $entity_fr->setPublished(TRUE);
    $entity_fr->moderation_state->target_id = 'published';
    $entity_fr->save();
    $this->ensureForwardRevision();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $storage->loadUnchanged($entity_en->id());
    // $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('fr')->isPublished());
    $this->assertEquals('draft', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertFalse($entity->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--1', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $entity->getTranslation('de')->getTitle());

    // Ensure we also created a new forward revision with the other revisions.
    $latest_revision_id = $moderation_information->getLatestRevisionId('node', $entity->id());
    $this->assertLessThan($latest_revision_id, $entity->getRevisionId());
    /** @var \Drupal\node\NodeInterface $latest_revision */
    $latest_revision = $storage->loadRevision($latest_revision_id);
    $this->assertEquals('published', $latest_revision->getTranslation('en')->get('moderation_state')->target_id);
    $this->assertTrue($latest_revision->isPublished());
    $this->assertEquals('published', $latest_revision->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($latest_revision->getTranslation('fr')->isPublished());
    $this->assertEquals('draft', $latest_revision->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertFalse($latest_revision->getTranslation('de')->isPublished());
    $this->assertEquals('en-name--1', $latest_revision->getTitle());
    $this->assertEquals('fr-name--1', $latest_revision->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $latest_revision->getTranslation('de')->getTitle());

    // Now publish the last language.
    $entity_de = $storage->loadRevision($latest_revision->getRevisionId())->getTranslation('de');
    $entity_de->isDefaultRevision(TRUE);
    $entity_de->setPublished(TRUE);
    $entity_de->moderation_state->target_id = 'published';
    $entity_de->save();
    $this->ensureForwardRevision();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $storage->loadUnchanged($entity_en->id());
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('fr')->isPublished());
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('de')->isPublished());
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--1', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $entity->getTranslation('de')->getTitle());

    // Now that we don't have any further non published translation, we don't
    // expect another forward revision.
    $latest_revision_id = $moderation_information->getLatestRevisionId('node', $entity->id());
    $this->assertEquals($latest_revision_id, $entity->getRevisionId());
  }

  // @fixme add test coverage for the problem by using the status filter in 
  // \_content_translation_workflow_load_previous_published_revision_translation()

}
