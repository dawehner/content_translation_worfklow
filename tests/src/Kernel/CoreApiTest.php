<?php

namespace Drupal\Tests\content_translation_workflow\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

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
    'workbench_moderation',
    'content_translation_workflow',
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

  /**
   * Tests the Drupal core entity API with revisions and translations.
   */
  public function testApi() {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
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
    $entity_fr->set('title', 'fr-name--1');
    $entity_fr->moderation_state->target_id = 'draft';
    $entity_fr->save();

    /** @var \Drupal\node\NodeInterface $entity_fr */
    $entity_de = $entity_en->getTranslation('de');
    $entity_de->set('title', 'de-name--1');
    $entity_de->moderation_state->target_id = 'draft';
    $entity_de->save();

    // Publish the english language. of the languages.
    $entity_en = $storage->loadRevision($entity_en->getRevisionId());
    $entity_en->moderation_state->target_id = 'published';
    $entity_en->save();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = Node::load($entity_en->id());
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--0', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--0', $entity->getTranslation('de')->getTitle());

    // Now try to publish another of the languages.
    // This requires the existing published english content to be copied over
    // as well.
    $entity_fr = $storage->loadRevision($entity_fr->getRevisionId())->getTranslation('fr');
    $entity_fr->moderation_state->target_id = 'published';
    $entity_fr->save();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = Node::load($entity_en->id());
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--1', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--0', $entity->getTranslation('de')->getTitle());

    // Now publish the last language.
    $entity_de = $storage->loadRevision($entity_de->getRevisionId())->getTranslation('de');
    $entity_de->moderation_state->target_id = 'published';
    $entity_de->save();

    /** @var \Drupal\node\NodeInterface $entity */
    $entity = Node::load($entity_en->id());
    $this->assertTrue($entity->isPublished());
    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertEquals('published', $entity->getTranslation('de')->get('moderation_state')->target_id);
    $this->assertEquals('en-name--1', $entity->getTitle());
    $this->assertEquals('fr-name--1', $entity->getTranslation('fr')->getTitle());
    $this->assertEquals('de-name--1', $entity->getTranslation('de')->getTitle());
  }

}
