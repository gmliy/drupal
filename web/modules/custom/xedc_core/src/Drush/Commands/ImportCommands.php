<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Drush\Commands;

use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\xedc_core\ImportProcessor;
use Drupal\xedc_core\TempCleaner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the image import queue.
 */
final class ImportCommands extends DrushCommands {

  public function __construct(
    private readonly ImportProcessor $processor,
    private readonly TempCleaner $cleaner,
    private readonly QueueFactory $queueFactory,
    private readonly QueueWorkerManagerInterface $workerManager,
  ) {
    parent::__construct();
  }

  /**
   * Recovers stuck tasks and runs the import queue.
   */
  #[CLI\Command(name: 'xedc:import-run', aliases: ['xedc-import-run'])]
  #[CLI\Option(name: 'time', description: 'Seconds to spend processing the queue')]
  #[CLI\Usage(name: 'drush xedc:import-run --time=60', description: 'Process image imports for 60 seconds')]
  public function run(array $options = ['time' => 60]): void {
    $this->processor->recover();
    $this->cleaner->expire();
    $queue = $this->queueFactory->get('xedc_image_import');
    $worker = $this->workerManager->createInstance('xedc_image_import');
    $end = time() + max(1, (int) $options['time']);
    $count = 0;
    while (time() < $end && ($item = $queue->claimItem(30))) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $count++;
      }
      catch (DelayedRequeueException $e) {
        if ($queue instanceof DelayableQueueInterface) {
          $queue->delayItem($item, $e->getDelay());
        }
        else {
          $queue->releaseItem($item);
        }
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        $count++;
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
        $this->logger()->error($e->getMessage());
        break;
      }
    }
    $this->logger()->notice('已处理 {count} 个导入队列项。', ['count' => $count]);
  }

  /**
   * Keeps draining the import queue until idle time or the process budget ends.
   */
  #[CLI\Command(name: 'xedc:import-work', aliases: ['xedc-import-work'])]
  #[CLI\Option(name: 'max', description: 'Maximum seconds this process stays alive')]
  #[CLI\Usage(name: 'drush xedc:import-work --max=600', description: 'Drain image imports for up to 10 minutes')]
  public function work(array $options = ['max' => 600]): void {
    $lock = \Drupal::lock();
    $end = time() + max(30, (int) $options['max']);
    $acquired = $lock->acquire('xedc_import_runner', 60);
    if (!$acquired) {
      $this->logger()->warning('导入工作进程正在等待上一个进程释放锁。');
      while (time() < $end && !$acquired) {
        sleep(3);
        $acquired = $lock->acquire('xedc_import_runner', 60);
      }
    }
    if (!$acquired) {
      $this->logger()->warning('已有导入工作进程，本次退出。');
      return;
    }
    $queue = $this->queueFactory->get('xedc_image_import');
    $worker = $this->workerManager->createInstance('xedc_image_import');
    $processed = 0;
    try {
      while (time() < $end) {
        if (memory_get_usage(TRUE) > 402653184) {
          $this->logger()->warning('导入工作进程内存达到 384 MiB，退出后由服务重新启动。');
          break;
        }
        \Drupal::keyValue('state')->set('xedc_import.runner', time());
        \Drupal::state()->resetCache();
        $lock->acquire('xedc_import_runner', 60);
        $this->dedupeQueue();
        $this->processor->releaseExpiredClaims();
        $item = $queue->claimItem(90);
        if (!$item) {
          $this->processor->ensureQueued();
          $item = $queue->claimItem(90);
        }
        if (!$item) {
          sleep(2);
          continue;
        }
        try {
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          $processed++;
          $this->logger()->notice('导入队列已处理 {count} 项。', ['count' => $processed]);
        }
        catch (DelayedRequeueException $e) {
          if ($queue instanceof DelayableQueueInterface) {
            $queue->delayItem($item, $e->getDelay());
          }
          else {
            $queue->releaseItem($item);
          }
          sleep(1);
        }
        catch (RequeueException) {
          $queue->releaseItem($item);
          $processed++;
        }
        catch (\Throwable $e) {
          $queue->releaseItem($item);
          $this->logger()->error($e->getMessage());
          sleep(1);
        }
      }
    }
    finally {
      \Drupal::keyValue('state')->delete('xedc_import.runner');
      $lock->release('xedc_import_runner');
    }
    $this->logger()->notice('导入工作进程结束，处理了 {count} 个队列项。', ['count' => $processed]);
  }

  /**
   * Drops extra unclaimed queue rows for the same task.
   */
  private function dedupeQueue(): void {
    $rows = \Drupal::database()->select('queue', 'q')
      ->fields('q', ['item_id', 'data'])
      ->condition('name', 'xedc_image_import')
      ->condition('expire', 0)
      ->orderBy('item_id')
      ->execute();
    $seen = [];
    foreach ($rows as $row) {
      $data = unserialize((string) $row->data, ['allowed_classes' => FALSE]);
      $taskId = (int) (is_array($data) ? ($data['task_id'] ?? 0) : 0);
      if ($taskId < 1) {
        continue;
      }
      if (isset($seen[$taskId])) {
        \Drupal::database()->delete('queue')->condition('item_id', (int) $row->item_id)->execute();
        continue;
      }
      $seen[$taskId] = TRUE;
    }
  }

  /**
   * Prints how many tasks are in each status.
   */
  #[CLI\Command(name: 'xedc:import-status', aliases: ['xedc-import-status'])]
  public function status(): void {
    $rows = \Drupal::database()->query('SELECT status, COUNT(*) AS c FROM {xedc_import_task} GROUP BY status')->fetchAllKeyed();
    if (!$rows) {
      $this->logger()->notice('没有导入任务。');
      return;
    }
    foreach ($rows as $status => $count) {
      $this->logger()->notice('{status}: {count}', ['status' => $status, 'count' => $count]);
    }
  }

}
