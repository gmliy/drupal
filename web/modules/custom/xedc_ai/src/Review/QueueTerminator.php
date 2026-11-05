<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs one queued review after the response has been sent.
 */
final class QueueTerminator implements EventSubscriberInterface {

  public function __construct(
    private readonly StateInterface $state,
    private readonly QueueFactory $queueFactory,
    private readonly QueueWorkerManagerInterface $workers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['onTerminate', -100]];
  }

  public function onTerminate(): void {
    $reviewId = (int) $this->state->get('xedc_ai.queue_kick', 0);
    if ($reviewId < 1) {
      return;
    }
    $this->state->delete('xedc_ai.queue_kick');
    $queue = $this->queueFactory->get('xedc_ai_review');
    $item = $queue->claimItem(180);
    if ($item === FALSE) {
      return;
    }
    try {
      @set_time_limit(180);
      $this->workers->createInstance('xedc_ai_review')->processItem($item->data);
      $queue->deleteItem($item);
    }
    catch (DelayedRequeueException) {
      $queue->releaseItem($item);
    }
    catch (\Throwable) {
      $queue->releaseItem($item);
    }
  }

}
