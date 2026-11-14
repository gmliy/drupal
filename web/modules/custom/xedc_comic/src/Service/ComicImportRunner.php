<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

/**
 * Runs import queue work inline (admin requests), avoiding cron-only delay.
 */
final class ComicImportRunner {

  private const MAX_ITERATIONS = 200;

  public function __construct(
    private readonly ComicImportManager $imports,
    private readonly ComicPdfImportManager $pdfImports,
  ) {}

  /**
   * Process queue items until the given import job reaches a terminal state.
   */
  public function drainForJob(int $importId): void {
    for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
      $job = $this->imports->loadJob($importId);
      if (!$job) {
        return;
      }
      if (in_array((string) $job['status'], ['completed', 'partial', 'failed', 'cancelled'], TRUE)) {
        return;
      }
      $phase = $this->nextPhase($job);
      match ($phase) {
        'extract' => $this->imports->runExtract($importId),
        'pdf_prepare' => $this->pdfImports->runPrepare($importId),
        'pdf_convert' => $this->pdfImports->runConvertBatch($importId),
        default => $this->imports->runProcessBatch($importId),
      };
    }
  }

  /**
   * @param array<string, mixed> $job
   */
  private function nextPhase(array $job): string {
    $mode = (string) ($job['import_mode'] ?? 'zip');
    $status = (string) ($job['status'] ?? 'queued');
    if ($mode === 'pdf') {
      if ($status === 'queued' || ($job['work_uri'] ?? '') === '') {
        return 'pdf_prepare';
      }
      $pendingConvert = (int) \Drupal::database()->select('xedc_comic_import_item', 'i')
        ->condition('import_id', (int) $job['id'])
        ->condition('status', 'convert_pending')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($pendingConvert > 0) {
        return 'pdf_convert';
      }
      return 'process';
    }
    if ($mode === 'zip' && (int) ($job['total'] ?? 0) === 0 && in_array($status, ['queued', 'processing'], TRUE)) {
      return 'extract';
    }
    return 'process';
  }

  /**
   * Recover jobs stuck in processing longer than $seconds (best-effort).
   */
  public function recoverStaleProcessing(int $seconds = 900): int {
    $cutoff = \Drupal::time()->getRequestTime() - $seconds;
    $ids = \Drupal::database()->select('xedc_comic_import', 'i')
      ->fields('i', ['id'])
      ->condition('status', 'processing')
      ->condition('changed', $cutoff, '<')
      ->execute()
      ->fetchCol();
    $count = 0;
    foreach ($ids as $id) {
      $this->imports->patchJob((int) $id, [
        'status' => 'queued',
        'message' => '已从超时 processing 恢复为 queued',
      ]);
      $job = $this->imports->loadJob((int) $id);
      $phase = $this->nextPhase($job ?? ['status' => 'queued', 'import_mode' => 'zip']);
      \Drupal::queue(ComicImportManager::QUEUE)->createItem(['import_id' => (int) $id, 'phase' => $phase]);
      $count++;
    }
    return $count;
  }

}