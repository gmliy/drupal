<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * PDF → images import phases (probe, convert, then shared process batch).
 */
final class ComicPdfImportManager {

  public function __construct(
    private readonly Connection $database,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly QueueFactory $queue,
    private readonly ComicPdfConverter $pdf,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @param array{dpi: int, format: string, quality: int, keep_source: bool} $options
   *
   * @return array<string, mixed>
   */
  public function createPdfJob(
    int $comicId,
    int $chapterId,
    int $uid,
    string $pdfUri,
    string $pdfName,
    int $pdfFid,
    array $options,
  ): array {
    $this->pdf->assertReady();
    $real = $this->fileSystem->realpath($pdfUri);
    if (!is_string($real) || !is_file($real)) {
      throw new \InvalidArgumentException('PDF 无法读取。');
    }
    $config = \Drupal::config('xedc_comic.settings');
    $maxPdfBytes = (int) $config->get('pdf.max_bytes') ?: 104857600;
    $this->pdf->validatePdfFile($real, $maxPdfBytes);
    $this->pdf->assertNotEncrypted($real);
    $pages = $this->pdf->countPages($real);
    $maxPages = (int) $config->get('pdf.max_pages') ?: 500;
    if ($pages > $maxPages) {
      throw new \InvalidArgumentException('PDF 页数超过限制（' . $maxPages . '）。');
    }
    $maxConcurrent = (int) $config->get('pdf.max_concurrent') ?: 2;
    $running = (int) $this->database->select('xedc_comic_import', 'i')
      ->condition('import_mode', 'pdf')
      ->condition('status', ['queued', 'processing', 'pending'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($running >= $maxConcurrent) {
      throw new \InvalidArgumentException('当前 PDF 转换任务已达并发上限，请稍后再试。');
    }
    $sha = hash_file('sha256', $real);
    if (!is_string($sha)) {
      throw new \InvalidArgumentException('无法计算 PDF 哈希。');
    }
    $duplicate = $this->database->select('xedc_comic_import', 'i')
      ->fields('i', ['id'])
      ->condition('chapter_nid', $chapterId)
      ->condition('archive_sha256', $sha)
      ->condition('status', ['queued', 'processing', 'completed'], 'IN')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($duplicate) {
      throw new \InvalidArgumentException('该章节已存在相同 PDF 的导入任务。');
    }
    $dpi = $this->pdf->sanitizeDpi((int) $options['dpi']);
    $format = $this->pdf->sanitizeFormat((string) $options['format']);
    $quality = $this->pdf->sanitizeQuality((int) $options['quality']);
    $keep = !empty($options['keep_source']);
    $now = $this->time->getRequestTime();
    $size = (int) filesize($real);
    $id = (int) $this->database->insert('xedc_comic_import')
      ->fields([
        'uuid' => \Drupal::service('uuid')->generate(),
        'comic_nid' => $comicId,
        'chapter_nid' => $chapterId,
        'uid' => $uid,
        'status' => 'pending',
        'import_mode' => 'pdf',
        'archive_name' => $pdfName,
        'archive_uri' => $pdfUri,
        'archive_fid' => $pdfFid,
        'archive_sha256' => $sha,
        'archive_bytes' => $size,
        'total' => $pages,
        'pdf_dpi' => $dpi,
        'pdf_format' => $format,
        'pdf_quality' => $quality,
        'pdf_page_count' => $pages,
        'pdf_converted' => 0,
        'pdf_keep_source' => $keep ? 1 : 0,
        'created' => $now,
        'changed' => $now,
        'message' => '已识别 ' . $pages . ' 页，等待转换。',
      ])
      ->execute();
    $workUri = 'private://xedc-comic-import/pdf/' . $id;
    $base = $this->fileSystem->realpath('private://');
    if (is_string($base)) {
      @mkdir($base . '/xedc-comic-import/pdf/' . $id, 0775, TRUE);
    }
    \Drupal::service('xedc_comic.import')->patchJob($id, ['work_uri' => $workUri, 'status' => 'queued']);
    \Drupal::service('xedc_comic.import')->log($id, 'info', 'PDF 任务创建：' . $pages . ' 页。');
    $this->queue->get(ComicImportManager::QUEUE)->createItem(['import_id' => $id, 'phase' => 'pdf_prepare']);
    return \Drupal::service('xedc_comic.import')->loadJob($id) ?? [];
  }

  /**
   * Insert per-page import items after validation at job creation.
   */
  public function runPrepare(int $importId): void {
    if (!$this->lock->acquire('xedc_comic_pdf_' . $importId, 120)) {
      return;
    }
    $imports = \Drupal::service('xedc_comic.import');
    try {
      $job = $imports->loadJob($importId);
      if (!$job || in_array($job['status'], ['cancelled', 'completed', 'failed'], TRUE)) {
        return;
      }
      $imports->patchJob($importId, ['status' => 'processing', 'message' => '准备页队列']);
      $chapterId = (int) $job['chapter_nid'];
      $startPage = \Drupal::service('xedc_comic.page_repository')->maxPageNumber($chapterId);
      $total = (int) ($job['pdf_page_count'] ?: $job['total']);
      for ($p = 1; $p <= $total; $p++) {
        $this->database->insert('xedc_comic_import_item')
          ->fields([
            'import_id' => $importId,
            'weight' => $startPage + $p,
            'relative' => sprintf('p%04d.jpg', $p),
            'temp_uri' => '',
            'status' => 'convert_pending',
            'pdf_page' => $p,
          ])
          ->execute();
      }
      $imports->patchJob($importId, [
        'total' => $total,
        'processed' => 0,
        'progress' => 0,
        'message' => '共 ' . $total . ' 页待转换',
      ]);
      $this->queue->get(ComicImportManager::QUEUE)->createItem(['import_id' => $importId, 'phase' => 'pdf_convert']);
    }
    catch (\Throwable $e) {
      $imports->failJob($importId, $e->getMessage());
    }
    finally {
      $this->lock->release('xedc_comic_pdf_' . $importId);
    }
  }

  public function runConvertBatch(int $importId): void {
    if (!$this->lock->acquire('xedc_comic_pdf_convert_' . $importId, 120)) {
      return;
    }
    $imports = \Drupal::service('xedc_comic.import');
    try {
      $job = $imports->loadJob($importId);
      if (!$job || in_array($job['status'], ['cancelled', 'completed', 'failed'], TRUE)) {
        return;
      }
      if ($job['status'] === 'pending' || $job['status'] === 'queued') {
        $imports->patchJob($importId, ['status' => 'processing']);
      }
      $batch = (int) \Drupal::config('xedc_comic.settings')->get('pdf.convert_batch') ?: 2;
      $items = $this->database->select('xedc_comic_import_item', 'i')
        ->fields('i')
        ->condition('import_id', $importId)
        ->condition('status', 'convert_pending')
        ->orderBy('pdf_page')
        ->range(0, $batch)
        ->execute()
        ->fetchAllAssoc('id');
      if ($items === []) {
        $pendingUpload = (int) $this->database->select('xedc_comic_import_item', 'i')
          ->condition('import_id', $importId)
          ->condition('status', 'pending')
          ->countQuery()
          ->execute()
          ->fetchField();
        if ($pendingUpload > 0) {
          $this->queue->get(ComicImportManager::QUEUE)->createItem(['import_id' => $importId, 'phase' => 'process']);
        }
        return;
      }
      $pdfReal = $this->fileSystem->realpath((string) $job['archive_uri']);
      if (!is_string($pdfReal) || !is_file($pdfReal)) {
        throw new \RuntimeException('PDF 源文件丢失。');
      }
      $workReal = $this->fileSystem->realpath((string) $job['work_uri']);
      if (!is_string($workReal) || !is_dir($workReal)) {
        throw new \RuntimeException('临时工作目录无效。');
      }
      $dpi = (int) ($job['pdf_dpi'] ?? 150);
      $maxPixels = (int) \Drupal::config('xedc_comic.settings')->get('pdf.max_page_pixels') ?: 25000000;
      foreach ($items as $item) {
        $fresh = $imports->loadJob($importId);
        if ($fresh && $fresh['status'] === 'cancelled') {
          return;
        }
        $pageNum = (int) ($item->pdf_page ?? 0);
        if ($pageNum < 1) {
          $pageNum = (int) $item->weight;
        }
        try {
          $jpg = $this->pdf->renderPage($pdfReal, $pageNum, $workReal, $dpi, $maxPixels);
          $name = basename($jpg);
          $uri = rtrim((string) $job['work_uri'], '/') . '/' . $name;
          if (!is_file($jpg)) {
            throw new \RuntimeException('无法保存转换图片。');
          }
          $this->database->update('xedc_comic_import_item')
            ->fields([
              'status' => 'pending',
              'temp_uri' => $uri,
              'error' => '',
            ])
            ->condition('id', (int) $item->id)
            ->execute();
          $imports->log($importId, 'info', 'PDF 第 ' . $pageNum . ' 页已转换');
        }
        catch (\Throwable $e) {
          $this->database->update('xedc_comic_import_item')
            ->fields(['status' => 'failed', 'error' => $e->getMessage()])
            ->condition('id', (int) $item->id)
            ->execute();
          $imports->log($importId, 'error', 'PDF 第 ' . $pageNum . ' 页：' . $e->getMessage());
        }
      }
      $total = max(1, (int) $job['total']);
      $convertedTotal = (int) $this->database->select('xedc_comic_import_item', 'i')
        ->condition('import_id', $importId)
        ->condition('status', ['pending', 'success'], 'IN')
        ->countQuery()
        ->execute()
        ->fetchField();
      $progress = (int) floor(($convertedTotal / $total) * 50);
      $imports->patchJob($importId, [
        'pdf_converted' => $convertedTotal,
        'progress' => min(49, $progress),
        'message' => '已转换 ' . $convertedTotal . '/' . $total . ' 页',
      ]);
      $stillConvert = (int) $this->database->select('xedc_comic_import_item', 'i')
        ->condition('import_id', $importId)
        ->condition('status', 'convert_pending')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($stillConvert > 0) {
        $this->queue->get(ComicImportManager::QUEUE)->createItem(['import_id' => $importId, 'phase' => 'pdf_convert']);
      }
      else {
        $this->queue->get(ComicImportManager::QUEUE)->createItem(['import_id' => $importId, 'phase' => 'process']);
      }
    }
    catch (\Throwable $e) {
      $imports->failJob($importId, $e->getMessage());
      $this->logger->error('PDF import @id: @msg', ['@id' => $importId, '@msg' => $e->getMessage()]);
    }
    finally {
      $this->lock->release('xedc_comic_pdf_convert_' . $importId);
    }
  }

  public function cancelCleanup(int $importId): void {
    $job = \Drupal::service('xedc_comic.import')->loadJob($importId);
    if (!$job || ($job['import_mode'] ?? '') !== 'pdf') {
      return;
    }
    $workUri = (string) ($job['work_uri'] ?? '');
    if ($workUri !== '') {
      $real = $this->fileSystem->realpath($workUri);
      if (is_string($real) && is_dir($real)) {
        $this->fileSystem->deleteRecursive($workUri);
      }
    }
  }

  public function afterJobComplete(array $job): void {
    if (($job['import_mode'] ?? '') !== 'pdf') {
      return;
    }
    $workUri = (string) ($job['work_uri'] ?? '');
    if ($workUri !== '') {
      $real = $this->fileSystem->realpath($workUri);
      if (is_string($real) && is_dir($real)) {
        $this->fileSystem->deleteRecursive($workUri);
      }
    }
    if (!(int) ($job['pdf_keep_source'] ?? 1)) {
      $failed = (int) $this->database->select('xedc_comic_import_item', 'i')
        ->condition('import_id', (int) $job['id'])
        ->condition('status', 'failed')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($failed === 0) {
        $uri = (string) ($job['archive_uri'] ?? '');
        if ($uri !== '' && str_starts_with($uri, 'private://')) {
          $fid = (int) ($job['archive_fid'] ?? 0);
          if ($fid > 0) {
            $file = \Drupal\file\Entity\File::load($fid);
            if ($file) {
              $usage = \Drupal::service('file.usage')->listUsage($file);
              if ($usage === [] || (count($usage) === 1 && isset($usage['xedc_comic']))) {
                $file->delete();
              }
            }
          }
          else {
            $this->fileSystem->delete($uri);
          }
        }
      }
    }
  }

}