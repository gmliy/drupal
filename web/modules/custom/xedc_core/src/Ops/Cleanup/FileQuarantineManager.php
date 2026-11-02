<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\FileSecurity\FileSecurity;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;

/**
 * Copies a file into the private quarantine, verifies it, then updates Drupal.
 */
class FileQuarantineManager {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FilePathGuard $guard,
    protected FileReferenceAnalyzer $analyzer,
    protected CleanupRepository $repository,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * @param array<string, mixed> $item
   *   Task item.
   *
   * @return array<string, mixed>
   *   Item update.
   */
  public function quarantine(int $taskId, array $item): array {
    $fid = (int) $item['fid'];
    $file = $this->loadFile($fid);
    $uri = $file->getFileUri();
    if ($uri !== (string) $item['uri']) {
      throw new \RuntimeException('文件 URI 已变化，停止隔离。');
    }
    $real = $this->fileSystem->realpath($uri);
    $info = is_string($real) ? $this->guard->describe($real) : NULL;
    if ($info === NULL || $this->guard->containedKind($info['path']) !== 'content') {
      throw new \RuntimeException('文件不在允许清理的目录内。');
    }
    if ($this->sharedWithOthers($fid, $uri, $info['path'])) {
      throw new \RuntimeException('其他文件实体仍使用同一物理文件，禁止移动。');
    }
    $check = $this->analyzer->recheck($fid);
    if (($check['status'] ?? '') !== 'confirmed_unused') {
      throw new \RuntimeException('引用状态已不是确认未引用：' . (string) ($check['reason'] ?? ''));
    }
    $max = (int) ($this->configFactory->get('xedc_core.settings')->get('file_hash_max_bytes') ?: 67108864);
    $hash = $this->guard->hashFile($info['path'], $max);
    if ($hash === NULL || !hash_equals((string) $item['sha256'], $hash)) {
      throw new \RuntimeException('隔离前的 SHA-256 与方案不一致。');
    }
    $destination = $this->destination($taskId, $fid, $hash, (string) $file->getFilename());
    $this->prepareQuarantine();
    $directory = dirname($destination);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $target = $this->fileSystem->realpath($directory);
    $name = basename(str_replace('\\', '/', $destination));
    if (!is_string($target)) {
      throw new \RuntimeException('隔离目录不可用。');
    }
    $targetFile = $target . '/' . $name;
    if (is_file($targetFile)) {
      throw new \RuntimeException('隔离目标已存在，没有覆盖。');
    }
    $this->copy($info['path'], $targetFile);
    $copied = $this->guard->hashFile($targetFile, $max);
    if ($copied === NULL || !hash_equals($hash, $copied) || !$this->guard->isInsideQuarantine($targetFile)) {
      @unlink($targetFile);
      throw new \RuntimeException('隔离副本校验失败，已停止且未删除原文件。');
    }
    if (!@unlink($info['path'])) {
      @unlink($targetFile);
      throw new \RuntimeException('原文件未能移走。隔离副本已删除，原文件应仍在原地。');
    }
    if (is_file($info['path'])) {
      throw new \RuntimeException('原文件删除后仍然存在，已停止。');
    }
    $meta = [
      'original_uri' => $uri,
      'original_path' => $info['path'],
      'mode' => $info['mode'],
      'mtime' => $info['mtime'],
      'sha256' => $hash,
      'bytes' => $info['bytes'],
      'quarantined' => $this->time->getRequestTime(),
      'filename' => (string) $file->getFilename(),
    ];
    try {
      $file->setFileUri($this->uriFromPath($targetFile));
      $file->setPermanent();
      $file->save();
    }
    catch (\Throwable $exception) {
      if (!$this->restoreCopy($targetFile, $info['path'], $hash, $max)) {
        throw new \RuntimeException('文件已移入隔离目录，但文件实体更新失败，且恢复原路径也失败：' . $exception->getMessage());
      }
      throw new \RuntimeException('文件实体更新失败，原文件已放回：' . $exception->getMessage());
    }
    return [
      'status' => 'done',
      'quarantine_uri' => $file->getFileUri(),
      'path' => $targetFile,
      'meta' => $meta,
      'error' => '',
    ];
  }

  /**
   * @param array<string, mixed> $item
   *   Quarantined item.
   *
   * @return array<string, mixed>
   *   Item update.
   */
  public function restore(array $item): array {
    $meta = $item['meta'];
    $fid = (int) $item['fid'];
    $file = $this->loadFile($fid);
    $quarantine = (string) ($item['path'] ?: $this->fileSystem->realpath((string) $item['quarantine_uri']));
    if (!$this->guard->isInsideQuarantine($quarantine)) {
      throw new \RuntimeException('隔离文件不在隔离目录内，已停止。');
    }
    $max = (int) ($this->configFactory->get('xedc_core.settings')->get('file_hash_max_bytes') ?: 67108864);
    $hash = $this->guard->hashFile($quarantine, $max);
    if ($hash === NULL || !hash_equals((string) ($meta['sha256'] ?? ''), $hash)) {
      throw new \RuntimeException('隔离文件的 SHA-256 与记录不一致。');
    }
    $original = (string) ($meta['original_path'] ?? '');
    $originalUri = (string) ($meta['original_uri'] ?? '');
    if ($original === '' || $originalUri === '' || $this->guard->containedKind($original) === 'outside' && !is_file($original)) {
      $parent = dirname($original);
      if ($parent === '' || $this->guard->directoryKind($parent) === 'outside') {
        throw new \RuntimeException('原路径不在允许的目录内。');
      }
    }
    if (is_file($original)) {
      $existing = $this->guard->hashFile($original, $max);
      if ($existing === NULL || !hash_equals($hash, $existing)) {
        throw new \RuntimeException('原路径已有内容不同的文件，没有覆盖。');
      }
    }
    else {
      $parent = dirname($original);
      if (!is_dir($parent) || $this->guard->directoryKind($parent) === 'outside') {
        throw new \RuntimeException('原目录不存在或不在允许范围内。');
      }
      $this->copy($quarantine, $original);
      $restored = $this->guard->hashFile($original, $max);
      if ($restored === NULL || !hash_equals($hash, $restored)) {
        @unlink($original);
        throw new \RuntimeException('恢复后的文件校验失败，没有改文件实体。');
      }
    }
    if ($this->uriUsedByOther($fid, $originalUri)) {
      throw new \RuntimeException('原 URI 已被其他文件实体占用，没有改当前记录。');
    }
    $file->setFileUri($originalUri);
    $file->setPermanent();
    $file->save();
    if (is_file($quarantine) && $original !== $quarantine) {
      @unlink($quarantine);
    }
    return [
      'status' => 'restored',
      'uri' => $originalUri,
      'path' => $original,
      'error' => '',
    ];
  }

  /**
   * @param array<string, mixed> $item
   *   Quarantined item.
   */
  public function purge(array $item): void {
    $meta = $item['meta'];
    $fid = (int) $item['fid'];
    $file = $this->loadFile($fid);
    $uri = $file->getFileUri();
    if (!str_starts_with($uri, 'private://xedc-quarantine/')) {
      throw new \RuntimeException('文件实体不在隔离目录，禁止永久清理。');
    }
    $real = $this->fileSystem->realpath($uri);
    if (!is_string($real) || !$this->guard->isInsideQuarantine($real)) {
      throw new \RuntimeException('隔离路径校验失败。');
    }
    $kept = (int) ($meta['quarantined'] ?? 0);
    $days = (int) ($this->configFactory->get('xedc_core.settings')->get('file_quarantine_days') ?? 14);
    if ($days < 0) {
      $days = 14;
    }
    if ($kept <= 0 || $this->time->getRequestTime() < $kept + ($days * 86400)) {
      throw new \RuntimeException('还没有达到隔离保留期。');
    }
    $max = (int) ($this->configFactory->get('xedc_core.settings')->get('file_hash_max_bytes') ?: 67108864);
    $hash = $this->guard->hashFile($real, $max);
    if ($hash === NULL || !hash_equals((string) ($meta['sha256'] ?? $item['sha256']), $hash)) {
      throw new \RuntimeException('永久清理前的 SHA-256 与隔离记录不一致。');
    }
    $check = $this->analyzer->recheck($fid);
    if (($check['status'] ?? '') !== 'quarantine') {
      throw new \RuntimeException('引用状态不允许永久清理：' . (string) ($check['reason'] ?? ''));
    }
    if ($this->sharedWithOthers($fid, $uri, $real)) {
      throw new \RuntimeException('其他文件实体仍指向该隔离文件。');
    }
    $file->delete();
  }

  private function loadFile(int $fid): FileInterface {
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException('文件实体不存在。');
    }
    return $file;
  }

  private function sharedWithOthers(int $fid, string $uri, string $real): bool {
    $rows = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    foreach ($rows as $other) {
      if ((int) $other->id() !== $fid) {
        return TRUE;
      }
    }
    $candidates = $this->entityTypeManager->getStorage('file')->getQuery()
      ->accessCheck(FALSE)
      ->condition('fid', $fid, '<>')
      ->condition('filesize', (int) filesize($real))
      ->range(0, 200)
      ->execute();
    foreach ($this->entityTypeManager->getStorage('file')->loadMultiple($candidates) as $other) {
      $path = $this->fileSystem->realpath($other->getFileUri());
      if (is_string($path) && $path === $real) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function uriUsedByOther(int $fid, string $uri): bool {
    $rows = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    foreach ($rows as $other) {
      if ((int) $other->id() !== $fid) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function destination(int $taskId, int $fid, string $hash, string $filename): string {
    $base = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $filename))) ?: 'file';
    $base = substr($base, 0, 80);
    return 'private://xedc-quarantine/' . $taskId . '/' . $fid . '-' . substr($hash, 0, 12) . '-' . $base;
  }

  private function prepareQuarantine(): void {
    $directory = 'private://xedc-quarantine';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $root = $this->fileSystem->realpath($directory);
    if (!is_string($root) || $this->guard->directoryKind($root) !== 'quarantine') {
      throw new \RuntimeException('隔离目录没有落在私有文件目录内。');
    }
    FileSecurity::writeHtaccess($root, TRUE);
  }

  private function copy(string $from, string $to): void {
    $dir = dirname($to);
    if (!is_dir($dir) && !@mkdir($dir, 0770, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException('无法创建隔离子目录。');
    }
    if ($this->guard->directoryKind($dir) === 'outside') {
      throw new \RuntimeException('目标目录超出允许范围。');
    }
    $in = fopen($from, 'rb');
    $out = fopen($to, 'wb');
    if ($in === FALSE || $out === FALSE) {
      if (is_resource($in)) {
        fclose($in);
      }
      if (is_resource($out)) {
        fclose($out);
      }
      throw new \RuntimeException('无法复制文件。');
    }
    try {
      while (!feof($in)) {
        $chunk = fread($in, 1048576);
        if ($chunk === FALSE) {
          throw new \RuntimeException('读取原文件失败。');
        }
        if ($chunk === '') {
          break;
        }
        $written = fwrite($out, $chunk);
        if ($written !== strlen($chunk)) {
          throw new \RuntimeException('写入隔离文件失败。');
        }
      }
    }
    catch (\Throwable $exception) {
      fclose($in);
      fclose($out);
      @unlink($to);
      throw $exception;
    }
    fclose($in);
    fclose($out);
  }

  private function restoreCopy(string $from, string $to, string $hash, int $max): bool {
    try {
      if (!is_file($to)) {
        $this->copy($from, $to);
      }
      $check = $this->guard->hashFile($to, $max);
      if ($check === NULL || !hash_equals($hash, $check)) {
        return FALSE;
      }
      @unlink($from);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function uriFromPath(string $path): string {
    $root = $this->guard->quarantineRoot();
    if ($root === NULL || !str_starts_with($path, $root . '/')) {
      throw new \RuntimeException('无法生成隔离 URI。');
    }
    return 'private://xedc-quarantine/' . substr($path, strlen($root) + 1);
  }

}
