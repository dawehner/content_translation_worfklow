<?php

namespace Drupal\content_translation_workflow\Plugin\QueueWorker;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\NodeInterface;

/**
 * Resaves a new forward revision.
 *
 * @QueueWorker(
 *   id = "content_translation_workflow__add_new_forward_revision",
 *   title = @Translation("Add new forward revision"),
 *   cron = {"time" = 60}
 * )
 */
class AddNewForwardRevisionWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($forward_revision) {
    if ($forward_revision instanceof NodeInterface) {
      $forward_revision->setNewRevision(TRUE);

      // We need a new forward revision when there is at least one not published
      // translation.
      $need_forward_translation = array_reduce($forward_revision->getTranslationLanguages(), function ($carry, LanguageInterface $language) use ($forward_revision) {
        if (!$forward_revision->getTranslation($language->getId())->moderation_state->entity->isPublishedState()) {
          return [TRUE, $forward_revision->getTranslation($language->getId())];
        }
        else {
          return $carry;
        }
      }, [FALSE, NULL]);

      /** @var \Drupal\node\NodeInterface $forward_translation */
      list($need_forward_translation, $forward_translation) = $need_forward_translation;

      if ($need_forward_translation) {
        foreach ($forward_revision->getTranslationLanguages() as $language) {
          $forward_revision_translation = $forward_revision->getTranslation($language->getId());
          $forward_revision_translation->isDefaultRevision(FALSE);
        }
        $forward_translation->save();
      }
    }
  }

}
