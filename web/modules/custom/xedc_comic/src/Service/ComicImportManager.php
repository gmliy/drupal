<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\xedc_core\ArchiveManager;
use Psr\Log\LoggerInterface;

/**
 * ZIP / multi / single image import jobs with queue processing.
 */
final class ComicImportManager {

  public const QUEUE = 'xedc_comic_import';

  public function __construct(
    private readonly Connection $database,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly QueueFactory $queue,
    private readonly ArchiveManager $archive,
    private readonly ComicStorageManager $storage,
    private readonly ComicPageManager $pages,
    private readonly EntityTypeManagerInterface $entities,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function createJob(int $comicId, int $chapterId, int $uid, string $archiveUri, string $archiveName, int $archiveFid = 0): array {
    $real = $this->fileSystem->realpath($archiveUri);
    if (!is_string($real) || !is_file($real)) {
      throw new \InvalidArgumentException('压缩包无法读取。');
    }
    $sha = hash_file('sha256', $real);
    if (!is_string($sha)) {
      throw new \InvalidArgumentException('无法校验压缩包。');
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
      throw new \InvalidArgumentException('该章节已导入过相同内容的压缩包。');
    }
    $now = $this->time->getRequestTime();
    $id = (int) $this->database->insert('xedc_comic_import')
      ->fields([
        'uuid' => \Drupal::service('uuid')->generate(),
        'comic_nid' => $comicId,
        'chapter_nid' => $chapterId,
        'uid' => $uid,
        'status' => 'queued',
        'import_mode' => 'zip',
        'archive_name' => $archiveName,
        'archive_uri' => $archiveUri,
        'archive_fid' => $archiveFid,
        'archive_sha256' => $sha,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
    $this->log($id, 'info', 'ZIP 任务已创建，等待解压。');
    $this->queue->get(self::QUEUE)->createItem(['import_id' => $id, 'phase' => 'extract']);
    return $this->loadJob($id);
  }

  /**
   * Queue job for uploaded image files (multi or single).
   *
   * @param list<array{uri: string, name: string}> $files
   *
   * @return array<string, mixed>
   */
  public function createImagesJob(int $comicId, int $chapterId, int $uid, string $mode, array $files, string $label = ''): array {
    if (!in_array($mode, ['multi', 'single'], TRUE)) {
      throw new \InvalidArgumentException('无效的导入模式。');
    }
    if ($files === []) {
      throw new \InvalidArgumentException('没有可处理的图片。');
    }
    usort($files, static fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));
    $config = \Drupal::config('xedc_comic.settings');
    $maxFiles = (int) $config->get('import.max_files');
    if (count($files) > $maxFiles) {
      throw new \InvalidArgumentException('图片数量超过限制。');
    }
    $startPage = \Drupal::service('xedc_comic.page_repository')->maxPageNumber($chapterId);
    $now = $this->time->getRequestTime();
    $title = $label !== '' ? $label : ($mode === 'single' ? '单图上传' : '多图上传');
    $id = (int) $this->database->insert('xedc_comic_import')
      ->fields([
        'uuid' => \Drupal::service('uuid')->generate(),
        'comic_nid' => $comicId,
        'chapter_nid' => $chapterId,
        'uid' => $uid,
        'status' => 'queued',
        'import_mode' => $mode,
        'archive_name' => $title,
        'archive_uri' => '',
        'archive_fid' => 0,
        'archive_sha256' => '',
        'total' => count($files),
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
    $weight = 0;
    foreach ($files as $file) {
      $weight++;
      $this->database->insert('xedc_comic_import_item')
        ->fields([
          'import_id' => $id,
          'weight' => $startPage + $weight,
          'relative' => (string) $file['name'],
          'temp_uri' => (string) $file['uri'],
          'status' => 'pending',
        ])
        ->execute();
    }
    $this->log($id, 'info', $title . '：共 ' . count($files) . ' 张，等待处理。');
    $this->patchJob($id, ['status' => 'processing', 'message' => '排队处理中']);
    $this->queue->get(self::QUEUE)->createItem(['import_id' => $id, 'phase' => 'process']);
    return $this->loadJob($id);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function loadJob(int $id): ?array {
    $row = $this->database->select('xedc_comic_import', 'i')
      ->fields('i')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  public function cancelJob(int $id): bool {
    $updated = $this->database->update('xedc_comic_import')
      ->fields(['status' => 'cancelled', 'changed' => $this->time->getRequestTime()])
      ->condition('id', $id)
      ->condition('status', ['queued', 'processing', 'pending'], 'IN')
      ->execute();
    if ($updated) {
      $this->log($id, 'warning', '任务已取消。');
      $job = $this->loadJob($id);
      if ($job && ($job['import_mode'] ?? '') === 'pdf') {
        \Drupal::service('xedc_comic.pdf_import')->cancelCleanup($id);
      }
    }
    return (bool) $updated;
  }

  public function retryFailed(int $importId): bool {
    $job = $this->loadJob($importId);
    if (!$job || in_array($job['status'], ['queued', 'processing'], TRUE)) {
      return FALSE;
    }
    $failed = $this->database->select('xedc_comic_import_item', 'i')
      ->fields('i')
      ->condition('import_id', $importId)
      ->condition('status', 'failed')
      ->execute();
    $count = 0;
    foreach ($failed as $item) {
      $status = ((string) $item->temp_uri === '') ? 'convert_pending' : 'pending';
      $this->database->update('xedc_comic_import_item')
        ->fields(['status' => $status, 'error' => ''])
        ->condition('id', (int) $item->id)
        ->execute();
      $count++;
    }
    if ($count < 1) {
      return FALSE;
    }
    $success = (int) $this->database->select('xedc_comic_import_item', 'i')
      ->condition('import_id', $importId)
      ->condition('status', 'success')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->patchJob($importId, [
      'status' => 'processing',
      'message' => '重试失败项',
      'finished' => 0,
      'success_count' => $success,
    ]);
    $this->log($importId, 'info', '已重置 ' . $count . ' 个失败项。');
    $phase = ($job['import_mode'] ?? '') === 'pdf' ? 'pdf_convert' : 'process';
    $this->queue->get(self::QUEUE)->createItem(['import_id' => $importId, 'phase' => $phase]);
    return TRUE;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function failedItems(int $importId): array {
    return $this->database->select('xedc_comic_import_item', 'i')
      ->fields('i')
      ->condition('import_id', $importId)
      ->condition('status', 'failed')
      ->orderBy('weight')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Extract phase for queue worker.
   */
  public function runExtract(int $importId): void {
    if (!$this->lock->acquire('xedc_comic_import_' . $importId, 120)) {
      return;
    }
    try {
      $job = $this->loadJob($importId);
      if (!$job || in_array($job['status'], ['cancelled', 'completed', 'failed'], TRUE)) {
        return;
      }
      $this->patchJob($importId, ['status' => 'processing', 'message' => '正在解压']);
      $task = (object) [
        'id' => $importId,
        'archive_uri' => $job['archive_uri'],
      ];
      $opened = $this->archive->openAndExtract($task);
      $workUri = (string) $opened['work_uri'];
      $files = $opened['files'] ?? [];
      $config = \Drupal::config('xedc_comic.settings');
      $maxFiles = (int) $config->get('import.max_files');
      if (count($files) > $maxFiles) {
        throw new \RuntimeException('图片数量超过限制。');
      }
      $startPage = \Drupal::service('xedc_comic.page_repository')->maxPageNumber((int) $job['chapter_nid']);
      $weight = 0;
      foreach ($files as $file) {
        $weight++;
        $this->database->insert('xedc_comic_import_item')
          ->fields([
            'import_id' => $importId,
            'weight' => $startPage + $weight,
            'relative' => (string) $file['relative'],
            'temp_uri' => (string) $file['uri'],
            'status' => 'pending',
          ])
          ->execute();
      }
      $total = count($files);
      $this->patchJob($importId, [
        'work_uri' => $workUri,
        'total' => $total,
        'processed' => 0,
        'progress' => $total > 0 ? 0 : 100,
        'message' => '解压完成，共 ' . $total . ' 张图片。',
      ]);
      $this->log($importId, 'info', '解压完成：' . $total . ' 张图片。');
      if ($total > 0) {
        $this->queue->get(self::QUEUE)->createItem(['import_id' => $importId, 'phase' => 'process']);
      }
      else {
        $this->patchJob($importId, ['status' => 'completed', 'finished' => $this->time->getRequestTime(), 'progress' => 100]);
      }
    }
    catch (\Throwable $e) {
      $this->failJob($importId, $e->getMessage());
    }
    finally {
      $this->lock->release('xedc_comic_import_' . $importId);
    }
  }

  /**
   * Process next batch of images.
   */
  public function runProcessBatch(int $importId): void {
    if (!$this->lock->acquire('xedc_comic_import_process_' . $importId, 90)) {
      return;
    }
    try {
      $job = $this->loadJob($importId);
      if (!$job || in_array($job['status'], ['cancelled', 'completed', 'failed'], TRUE)) {
        return;
      }
      if ($job['status'] === 'queued') {
        $this->patchJob($importId, ['status' => 'processing']);
        $job['status'] = 'processing';
      }
      $batch = (int) \Drupal::config('xedc_comic.settings')->get('import.batch_size') ?: 5;
      $items = $this->database->select('xedc_comic_import_item', 'i')
        ->fields('i')
        ->condition('import_id', $importId)
        ->condition('status', 'pending')
        ->orderBy('weight')
        ->range(0, $batch)
        ->execute()
        ->fetchAllAssoc('id');
      if ($items === []) {
        $convertPending = (int) $this->database->select('xedc_comic_import_item', 'i')
          ->condition('import_id', $importId)
          ->condition('status', 'convert_pending')
          ->countQuery()
          ->execute()
          ->fetchField();
        if ($convertPending > 0) {
          $this->queue->get(self::QUEUE)->createItem(['import_id' => $importId, 'phase' => 'pdf_convert']);
          return;
        }
        $failed = (int) $this->database->select('xedc_comic_import_item', 'i')
          ->condition('import_id', $importId)
          ->condition('status', 'failed')
          ->countQuery()
          ->execute()
          ->fetchField();
        $status = $failed > 0 ? 'partial' : 'completed';
        $this->patchJob($importId, [
          'status' => $status,
          'progress' => 100,
          'finished' => $this->time->getRequestTime(),
          'message' => $status === 'completed' ? '导入完成。' : '部分图片失败。',
        ]);
        \Drupal::service('xedc_comic.manager')->syncChapterPageCount((int) $job['chapter_nid']);
        $freshJob = $this->loadJob($importId);
        if ($freshJob) {
          if (($freshJob['import_mode'] ?? '') === 'pdf') {
            \Drupal::service('xedc_comic.pdf_import')->afterJobComplete($freshJob);
          }
          else {
            $this->cleanupWorkDir((string) ($freshJob['work_uri'] ?? ''));
            $this->cleanupUploadItems($importId, (string) ($freshJob['import_mode'] ?? 'zip'));
          }
        }
        return;
      }
      $comicId = (int) $job['comic_nid'];
      $chapterId = (int) $job['chapter_nid'];
      $success = (int) $job['success_count'];
      $failed = (int) $job['failed_count'];
      $skipped = (int) ($job['skipped_count'] ?? 0);
      $processed = (int) $job['processed'];
      foreach ($items as $item) {
        $fresh = $this->loadJob($importId);
        if ($fresh && $fresh['status'] === 'cancelled') {
          return;
        }
        $processed++;
        try {
          $real = $this->resolveSourcePath((string) $item->temp_uri);
          if ($real === NULL) {
            throw new \RuntimeException('临时图片不存在。');
          }
          $sourceHash = hash_file('sha256', $real);
          if (is_string($sourceHash) && $this->pages->chapterHasSourceHash($chapterId, $sourceHash)) {
            $this->database->update('xedc_comic_import_item')
              ->fields(['status' => 'skipped', 'error' => '重复图片（SHA-256）'])
              ->condition('id', (int) $item->id)
              ->execute();
            $skipped++;
            $this->log($importId, 'info', (string) $item->relative . '：已跳过（重复）。');
            continue;
          }
          $created = $this->storage->createPageFromPath($comicId, $chapterId, (int) $item->weight, $real, (string) $item->relative);
          $this->database->update('xedc_comic_import_item')
            ->fields([
              'status' => 'success',
              'page_id' => (int) $created['page']->id(),
              'error' => '',
            ])
            ->condition('id', (int) $item->id)
            ->execute();
          $success++;
        }
        catch (\Throwable $e) {
          $failed++;
          $this->database->update('xedc_comic_import_item')
            ->fields(['status' => 'failed', 'error' => $e->getMessage()])
            ->condition('id', (int) $item->id)
            ->execute();
          $this->log($importId, 'error', (string) $item->relative . '：' . $e->getMessage());
        }
      }
      $total = max(1, (int) $job['total']);
      $isPdf = ($job['import_mode'] ?? '') === 'pdf';
      $progress = $isPdf
        ? min(99, 50 + (int) floor(($processed / $total) * 50))
        : (int) floor(($processed / $total) * 100);
      $this->patchJob($importId, [
        'processed' => $processed,
        'success_count' => $success,
        'failed_count' => $failed,
        'skipped_count' => $skipped,
        'progress' => min(99, $progress),
        'message' => '已处理 ' . $processed . '/' . $total,
      ]);
      $stillPending = (int) $this->database->select('xedc_comic_import_item', 'i')
        ->condition('import_id', $importId)
        ->condition('status', 'pending')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($stillPending > 0) {
        $this->queue->get(self::QUEUE)->createItem(['import_id' => $importId, 'phase' => 'process']);
      }
      else {
        $failedLeft = (int) $this->database->select('xedc_comic_import_item', 'i')
          ->condition('import_id', $importId)
          ->condition('status', 'failed')
          ->countQuery()
          ->execute()
          ->fetchField();
        $status = $failedLeft > 0 ? 'partial' : 'completed';
        $this->patchJob($importId, [
          'status' => $status,
          'progress' => 100,
          'finished' => $this->time->getRequestTime(),
          'message' => $status === 'completed' ? '导入完成。' : '部分图片失败。',
        ]);
        $freshJob = $this->loadJob($importId);
        if ($freshJob) {
          \Drupal::service('xedc_comic.manager')->syncChapterPageCount((int) $freshJob['chapter_nid']);
          if (($freshJob['import_mode'] ?? '') === 'pdf') {
            \Drupal::service('xedc_comic.pdf_import')->afterJobComplete($freshJob);
          }
          else {
            $this->cleanupWorkDir((string) ($freshJob['work_uri'] ?? ''));
            $this->cleanupUploadItems($importId, (string) ($freshJob['import_mode'] ?? 'zip'));
          }
        }
      }
    }
    finally {
      $this->lock->release('xedc_comic_import_process_' . $importId);
    }
  }

  /**
   * @param array<string, mixed> $fields
   */
  public function patchJob(int $id, array $fields): void {
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_comic_import')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  public function failJob(int $id, string $message): void {
    $this->patchJob($id, [
      'status' => 'failed',
      'error' => $message,
      'finished' => $this->time->getRequestTime(),
    ]);
    $this->log($id, 'error', $message);
    $this->logger->error('Comic import @id failed: @msg', ['@id' => $id, '@msg' => $message]);
  }

  public function log(int $importId, string $level, string $message): void {
    $this->database->insert('xedc_comic_import_log')
      ->fields([
        'import_id' => $importId,
        'level' => $level,
        'message' => $message,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function recentJobs(int $limit = 20): array {
    return $this->database->select('xedc_comic_import', 'i')
      ->fields('i')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function modeLabel(string $mode): string {
    return match ($mode) {
      'zip' => 'ZIP',
      'multi' => '多图',
      'single' => '单图',
      'pdf' => 'PDF',
      default => $mode,
    };
  }

  public function statusLabel(string $status): string {
    return match ($status) {
      'pending' => '等待中',
      'queued' => '排队中',
      'processing' => '处理中',
      'completed' => '已完成',
      'partial' => '部分失败',
      'failed' => '失败',
      'cancelled' => '已取消',
      default => $status,
    };
  }

  private function resolveSourcePath(string $uri): ?string {
    if ($uri !== '' && str_starts_with($uri, '/') && is_file($uri)) {
      return $uri;
    }
    $real = $this->fileSystem->realpath($uri);
    if (is_string($real) && is_file($real)) {
      return $real;
    }
    $wrapper = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
    if ($wrapper) {
      $real = $wrapper->realpath();
      if (is_string($real) && is_file($real)) {
        return $real;
      }
    }
    return NULL;
  }

  private function cleanupWorkDir(string $workUri): void {
    if ($workUri === '') {
      return;
    }
    $real = $this->fileSystem->realpath($workUri);
    if (is_string($real) && is_dir($real)) {
      $this->fileSystem->deleteRecursive($workUri);
    }
  }

  private function cleanupUploadItems(int $importId, string $mode): void {
    if ($mode === 'zip') {
      return;
    }
    $items = $this->database->select('xedc_comic_import_item', 'i')
      ->fields('i', ['temp_uri'])
      ->condition('import_id', $importId)
      ->execute();
    foreach ($items as $item) {
      $uri = (string) $item->temp_uri;
      if ($uri !== '' && str_starts_with($uri, 'private://xedc-comic-import/')) {
        $this->fileSystem->delete($uri);
      }
    }
  }

}