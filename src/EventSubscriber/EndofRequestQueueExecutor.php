<?php

namespace Drupal\content_translation_workflow\EventSubscriber;

use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proceeds a queue at the end of the request.
 *
 * Note: Use this with care.
 */
class EndofRequestQueueExecutor implements EventSubscriberInterface {

  protected $queueName = 'content_translation_workflow__add_new_forward_revision';

  public function onTerminate() {
    /** @var \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager */
    $queue_manager = \Drupal::service('plugin.manager.queue_worker');
    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = \Drupal::queue($this->queueName);
    $queue->createQueue();

    $queue_worker = $queue_manager->createInstance($this->queueName);
    $lease_time = isset($info['cron']['time']) ?: NULL;
    // Note: This run forever until every queue item is processed. The idea is
    // to actually never have more than one item in the queue basically.
    // @todo Maybe add some time protection.
    while (($item = $queue->claimItem($lease_time))) {
      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (RequeueException $e) {
        // The worker requested the task be immediately requeued.
        $queue->releaseItem($item);
      }
      catch (SuspendQueueException $e) {
        // If the worker indicates there is a problem with the whole queue,
        // release the item and skip to the next queue.
        $queue->releaseItem($item);

        watchdog_exception('cron', $e);
      }
      catch (\Exception $e) {
        // In case of any other kind of exception, log it and leave the item
        // in the queue to be processed again later.
        watchdog_exception('cron', $e);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Execute the terminate event quite early.
    $events[KernelEvents::TERMINATE][] = ['onTerminate', 250];
    return $events;
  }

}
