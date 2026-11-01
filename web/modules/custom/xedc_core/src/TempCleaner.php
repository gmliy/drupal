<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes temporary archives without touching published images.
 */
class TempCleaner {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected FileUsageInterface $fileUsage,
    protected TaskRepository $tasks,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Keeps the archive for the retention window after a successful import.
   */
  public function success(object $task): void {
    $hours = (int) $this->configFactory->get('xedc_core.settings')->get('temp_retention_hours');
    if ($hours < 1) {
      $hours = 24;
    }
    $this->tasks->update((int) $task->id, [
      'cleanup_after' => time() + ($hours * 3600),
    ]);
    $this->tasks->log((int) $task->id, 'info', '导入已通过校验。临时压缩包和解压原图保留 ' . $hours . ' 小时，正式图片已保留。');
  }

  /**
   * Keeps a failed archive until the retention window ends.
   */
  public function schedule(object $task): void {
    $hours = (int) $this->configFactory->get('xedc_core.settings')->get('failed_retention_hours');
    if ($hours < 24) {
      $hours = 48;
    }
    if ($hours > 72) {
      $hours = 72;
    }
    $this->tasks->update((int) $task->id, [
      'cleanup_after' => time() + ($hours * 3600),
    ]);
  }

  /**
   * Cron deletion of expired failed archives.
   */
  public function expire(): void {
    $now = time();
    $seen = [];
    foreach ($this->tasks->expired($now) as $task) {
      $seen[(int) $task->id] = TRUE;
      $this->expireOne($task);
    }
    $ready = \Drupal::database()->select('xedc_import_task', 't')
      ->fields('t')
      ->condition('cleanup_after', 0, '>')
      ->condition('cleanup_after', $now, '<')
      ->condition('status', [ImportStatus::REVIEW, ImportStatus::PUBLISHED, ImportStatus::COMPLETED], 'IN')
      ->range(0, 20)
      ->execute()
      ->fetchAll();
    foreach ($ready as $task) {
      if (isset($seen[(int) $task->id])) {
        continue;
      }
      $this->expireOne($task);
    }
  }

  /**
   * Deletes one task's private archive when it is idle and past retention.
   */
  private function expireOne(object $task): void {
    $status = (string) $task->status;
    if (in_array($status, array_merge([ImportStatus::QUEUED], ImportStatus::processing()), TRUE)) {
      return;
    }
    $id = (int) $task->id;
    if (!\Drupal::service('lock')->lockMayBeAvailable('xedc_import:' . $id)) {
      $this->tasks->log($id, 'warning', '任务仍有处理锁，本次跳过临时文件清理。');
      return;
    }
    try {
      $this->deleteTaskTree($task);
      $this->tasks->update($id, [
        'archive_uri' => '',
        'work_uri' => '',
        'cleanup_after' => 0,
      ]);
      $this->tasks->log($id, 'info', '已按保留期限清理临时文件。正式图片已保留。');
    }
    catch (\Throwable $e) {
      $this->logger->error('清理任务 @id 失败：@message', [
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      $this->tasks->log($id, 'error', '清理临时文件失败，将在下次计划任务重试：' . $e->getMessage());
    }
  }

  /**
   * Manual cleanup. Refuses to run while a worker is active.
   */
  public function manual(object $task): void {
    if (in_array((string) $task->status, array_merge([ImportStatus::QUEUED], ImportStatus::processing()), TRUE)) {
      throw new ImportException('任务仍在处理，不能清理临时文件。');
    }
    if (!\Drupal::service('lock')->lockMayBeAvailable('xedc_import:' . (int) $task->id)) {
      throw new ImportException('任务仍有处理锁，不能清理临时文件。');
    }
    $this->deleteTaskTree($task);
    $this->tasks->update((int) $task->id, [
      'archive_uri' => '',
      'work_uri' => '',
      'cleanup_after' => 0,
    ]);
    $this->tasks->log((int) $task->id, 'info', '管理员已清理临时文件。');
  }

  /**
   * Deletes only private://xedc-import/tasks/{id}.
   */
  public function deleteTaskTree(object $task): void {
    $id = (int) $task->id;
    $prefix = 'private://xedc-import/tasks/' . $id;
    $fid = (int) ($task->archive_fid ?? 0);
    if ($fid) {
      $file = File::load($fid);
      if ($file instanceof FileInterface && str_starts_with($file->getFileUri(), $prefix . '/')) {
        $this->fileUsage->delete($file, 'xedc_core', 'xedc_import_task', $id);
        $file->delete();
      }
    }
    $real = $this->fileSystem->realpath($prefix);
    $private = $this->fileSystem->realpath('private://');
    if (!$real || !$private) {
      return;
    }
    $expected = $private . DIRECTORY_SEPARATOR . 'xedc-import' . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . $id;
    if ($real !== $expected || !is_dir($real)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (is_link($path)) {
        $parent = realpath(dirname($path));
        if (!is_string($parent) || !str_starts_with($parent, $expected)) {
          throw new ImportException('拒绝删除任务目录以外的文件。');
        }
        unlink($path);
        continue;
      }
      $resolved = realpath($path) ?: $path;
      if (!str_starts_with($resolved, $expected . DIRECTORY_SEPARATOR) && $resolved !== $expected) {
        throw new ImportException('拒绝删除任务目录以外的文件。');
      }
      if ($file->isFile()) {
        unlink($path);
      }
      else {
        rmdir($path);
      }
    }
    rmdir($real);
  }

}
