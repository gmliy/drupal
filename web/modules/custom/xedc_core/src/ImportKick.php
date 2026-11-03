<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\DestructableInterface;

/**
 * Continues a saved import after the HTTP response has been sent.
 */
final class ImportKick implements DestructableInterface {

  /**
   * @var array<int, int>
   */
  private array $ids = [];

  public function __construct(
    protected ImportProcessor $processor,
  ) {}

  public function schedule(int $taskId): void {
    if ($taskId > 0) {
      $this->ids[$taskId] = $taskId;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    $ids = $this->ids;
    $this->ids = [];
    foreach ($ids as $taskId) {
      try {
        $this->processor->processBatch($taskId, 8);
      }
      catch (\Throwable) {
        // The queue and cron keep a saved task moving.
      }
    }
  }

}
