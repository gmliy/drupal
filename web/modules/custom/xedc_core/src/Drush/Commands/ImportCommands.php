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
