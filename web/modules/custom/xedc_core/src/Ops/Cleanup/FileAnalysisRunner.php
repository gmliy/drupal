<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Advances read-only analysis. It never confirms or deletes files.
 */
class FileAnalysisRunner {

  public function __construct(
    protected DuplicateFileScanner $duplicates,
    protected FileReferenceAnalyzer $references,
    protected UnusedFileScanner $unused,
    protected FileCleanupManager $manager,
    protected CleanupRepository $repository,
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected LockBackendInterface $lock,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, mixed>
   *   Progress for the page.
   */
  public function summary(): array {
    if (!$this->repository->ready()) {
      return ['ready' => FALSE, 'status' => 'missing', 'message' => '分析数据表还没有安装。'];
    }
    $latest = $this->repository->latestBatch();
    $done = $this->repository->latestBatch('done');
    return [
      'ready' => TRUE,
      'status' => (string) ($latest['status'] ?? 'idle'),
      'phase' => (string) ($latest['phase'] ?? 'idle'),
      'message' => (string) ($latest['message'] ?? ''),
      'processed' => (int) ($latest['processed'] ?? 0),
      'total' => (int) ($latest['total'] ?? 0),
      'failures' => (int) ($latest['failures'] ?? 0),
      'batch_id' => (int) ($latest['id'] ?? 0),
      'finished_batch' => (int) ($done['id'] ?? 0),
      'finished' => (int) ($done['finished'] ?? 0),
      'summary' => $done['summary'] ?? [],
      'running_id' => ($latest && ($latest['status'] ?? '') === 'running') ? (int) $latest['id'] : 0,
    ];
  }

  public function start(): string {
    if (!$this->repository->ready()) {
      return 'missing';
    }
    if (!$this->lock->acquire('xedc_core_file_analysis', 30.0)) {
      return 'busy';
    }
    try {
      $latest = $this->repository->latestBatch();
      if ($latest && $latest['status'] === 'running') {
        return 'running';
      }
      $id = $this->repository->createBatch();
      $this->state->set('xedc_file_analysis_last', $this->time->getRequestTime());
      $this->enqueue();
      return $id > 0 ? 'started' : 'error';
    }
    finally {
      $this->lock->release('xedc_core_file_analysis');
    }
  }

  public function pause(): string {
    $latest = $this->repository->latestBatch();
    if (!$latest || $latest['status'] !== 'running') {
      return 'idle';
    }
    $this->repository->saveBatch((int) $latest['id'], [
      'status' => 'paused',
      'message' => '分析已暂停。',
    ]);
    return 'paused';
  }

  public function resume(): string {
    $latest = $this->repository->latestBatch();
    if (!$latest || !in_array($latest['status'], ['paused', 'error'], TRUE)) {
      return 'idle';
    }
    $this->repository->saveBatch((int) $latest['id'], [
      'status' => 'running',
      'message' => '继续分析。',
    ]);
    $this->enqueue();
    return 'resumed';
  }

  /**
   * One bounded step. Returns more, done, paused, idle, busy, or error.
   */
  public function step(): string {
    if (!$this->repository->ready()) {
      return 'idle';
    }
    $seconds = max(2, min(8, (int) ($this->configFactory->get('xedc_core.settings')->get('file_analysis_seconds') ?: 4)));
    if (!$this->lock->acquire('xedc_core_file_analysis', (float) ($seconds + 20))) {
      return 'busy';
    }
    try {
      $batch = $this->repository->latestBatch();
      if (!$batch || $batch['status'] === 'paused') {
        return $batch ? 'paused' : 'idle';
      }
      if ($batch['status'] !== 'running') {
        return $batch['status'] === 'done' ? 'done' : 'idle';
      }
      $deadline = microtime(TRUE) + $seconds;
      $budget = max(10, min(80, (int) ($this->configFactory->get('xedc_core.settings')->get('file_analysis_batch') ?: 40)));
      $maxBytes = max(1048576, (int) ($this->configFactory->get('xedc_core.settings')->get('file_hash_max_bytes') ?: 67108864));
      $phase = (string) $batch['phase'];
      $more = TRUE;
      if ($phase === 'enumerate') {
        $more = $this->duplicates->enumerate($batch, $budget);
        if (!$more) {
          $batch['phase'] = 'hash';
          $batch['message'] = '正在计算同大小文件的 SHA-256。';
        }
      }
      elseif ($phase === 'hash') {
        $more = $this->duplicates->hash($batch, $deadline, $maxBytes);
        if (!$more) {
          $this->duplicates->group($batch);
          $batch['phase'] = 'references';
        }
      }
      elseif ($phase === 'references') {
        $more = $this->references->referenceStep($batch, $deadline);
        if (!$more) {
          $batch['phase'] = 'text';
        }
      }
      elseif ($phase === 'text') {
        $more = $this->references->textStep($batch, $deadline);
        if (!$more) {
          $batch['phase'] = 'classify';
          $batch['state']['classify_fid'] = 0;
        }
      }
      elseif ($phase === 'classify') {
        $more = $this->unused->classify($batch, $deadline, $maxBytes);
        if (!$more) {
          $batch['phase'] = 'walk';
        }
      }
      elseif ($phase === 'walk') {
        $more = $this->unused->walk($batch, $deadline);
        if (!$more) {
          $batch['phase'] = 'suggest';
        }
      }
      elseif ($phase === 'suggest') {
        $this->finish($batch);
        $more = FALSE;
      }
      $this->repository->saveBatch((int) $batch['id'], [
        'status' => $batch['status'],
        'phase' => $batch['phase'],
        'cursor_fid' => (int) $batch['cursor_fid'],
        'processed' => (int) $batch['processed'],
        'total' => (int) $batch['total'],
        'failures' => (int) $batch['failures'],
        'message' => (string) $batch['message'],
        'state' => $batch['state'],
        'summary' => $batch['summary'],
        'finished' => (int) $batch['finished'],
      ]);
      return $batch['status'] === 'running' ? 'more' : (string) $batch['status'];
    }
    catch (\Throwable $exception) {
      $this->logger->error('File analysis stopped: @message', ['@message' => $exception->getMessage()]);
      $batch = $this->repository->latestBatch();
      if ($batch && $batch['status'] === 'running') {
        $this->repository->saveBatch((int) $batch['id'], [
          'status' => 'error',
          'message' => '分析中断：' . $exception->getMessage(),
        ]);
      }
      return 'error';
    }
    finally {
      $this->lock->release('xedc_core_file_analysis');
    }
  }

  public function cron(): void {
    if (!$this->repository->ready()) {
      return;
    }
    $config = $this->configFactory->get('xedc_core.settings');
    if (!(int) $config->get('file_analysis_auto')) {
      return;
    }
    $latest = $this->repository->latestBatch();
    if ($latest && $latest['status'] === 'running') {
      $this->enqueue();
      return;
    }
    if ($latest && $latest['status'] === 'paused') {
      return;
    }
    $interval = max(3600, (int) ($config->get('file_analysis_interval') ?: 86400));
    $last = (int) $this->state->get('xedc_file_analysis_last', 0);
    if ($this->time->getRequestTime() - $last < $interval) {
      return;
    }
    if ($this->start() === 'started') {
      $this->step();
    }
  }

  /**
   * @param array<string, mixed> $batch
   *   Completed batch.
   */
  private function finish(array &$batch): void {
    $batchId = (int) $batch['id'];
    $counts = $this->repository->findingSummary($batchId);
    $groups = (int) $this->database->select('xedc_file_dup_group', 'g')
      ->condition('batch_id', $batchId)
      ->countQuery()
      ->execute()
      ->fetchField();
    $reclaim = (int) $this->database->query(
      'SELECT COALESCE(SUM(reclaimable_bytes), 0) FROM {xedc_file_dup_group} WHERE batch_id = :batch',
      [':batch' => $batchId]
    )->fetchField();
    $managedRow = $this->database->query(
      'SELECT COUNT(*) AS n, COALESCE(SUM(filesize), 0) AS bytes FROM {file_managed}'
    )->fetchAssoc() ?: [];
    $managed = (int) ($managedRow['n'] ?? 0);
    $managedBytes = (int) ($managedRow['bytes'] ?? 0);
    $batch['summary'] = [
      'duplicate_groups' => $groups,
      'reclaimable_bytes' => $reclaim,
      'managed_files' => $managed,
      'managed_bytes' => $managedBytes,
      'findings' => $counts,
      'batch_id' => $batchId,
    ];
    $batch['status'] = 'done';
    $batch['phase'] = 'done';
    $batch['finished'] = $this->time->getRequestTime();
    $batch['message'] = '分析完成。这只是扫描结果，没有删除文件。';
    if ((int) $this->configFactory->get('xedc_core.settings')->get('file_analysis_suggest')
      && !$this->repository->hasTask($batchId, 'quarantine', 'pending_review')) {
      $rows = $this->repository->confirmedUnused($batchId, 20);
      if ($rows !== []) {
        try {
          $this->manager->createQuarantinePlan(array_map(static fn(array $row): int => (int) $row['fid'], $rows), 0);
        }
        catch (\Throwable $exception) {
          $this->logger->error('Cleanup suggestion was not created: @message', ['@message' => $exception->getMessage()]);
        }
      }
    }
  }

  private function enqueue(): void {
    $queue = $this->queueFactory->get('xedc_file_cleanup');
    if ($queue->numberOfItems() < 3) {
      $queue->createItem(['op' => 'analyze']);
    }
  }

}
