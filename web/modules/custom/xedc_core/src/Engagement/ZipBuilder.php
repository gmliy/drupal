<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Entity\DownloadResource;

/**
 * Builds a ZIP of original images on disk, one source file at a time.
 */
final class ZipBuilder {

  public const SYNC_BYTES = 268435456;

  public const SYNC_FILES = 80;

  public const READY_TTL = 21600;

  public function __construct(
    protected OriginalFiles $originals,
    protected FileSystemInterface $fileSystem,
    protected LockBackendInterface $lock,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * @return array{files: list<array<string, mixed>>, bytes: int, count: int}
   *   The originals that would be packed.
   */
  public function plan(NodeInterface $node): array {
    $files = $this->originals->forNode($node);
    $bytes = 0;
    foreach ($files as $file) {
      $bytes += (int) $file['size'];
    }
    return [
      'files' => $files,
      'bytes' => $bytes,
      'count' => count($files),
    ];
  }

  public function readyUri(DownloadResource $resource, NodeInterface $node): string {
    $plan = $this->plan($node);
    $stamp = substr(hash('sha256', json_encode(array_map(static fn(array $file): string => $file['fid'] . ':' . $file['size'] . ':' . $file['filename'], $plan['files'])) ?: ''), 0, 16);
    return 'private://xedc-zips/' . (int) $resource->id() . '-' . $stamp . '.zip';
  }

  /**
   * Returns a private URI for a finished archive.
   */
  public function build(DownloadResource $resource): string {
    $node = $resource->get('parent')->entity;
    if (!$node instanceof NodeInterface) {
      throw new \RuntimeException('内容不存在，无法打包。');
    }
    $plan = $this->plan($node);
    if ($plan['count'] < 1) {
      throw new \RuntimeException('这篇内容没有可打包的原图。');
    }
    if ($plan['bytes'] > self::SYNC_BYTES || $plan['count'] > self::SYNC_FILES) {
      throw new ZipPendingException('图片较多，已改为后台打包。请稍后再次下载。');
    }
    if (!class_exists(\ZipArchive::class)) {
      throw new \RuntimeException('服务器没有启用 ZIP 扩展。');
    }
    $uri = $this->readyUri($resource, $node);
    if (is_file($uri) && filemtime($uri) >= (time() - self::READY_TTL)) {
      return $uri;
    }
    $name = 'xedc_zip_' . (int) $resource->id();
    if (!$this->lock->acquire($name, 180)) {
      throw new ZipPendingException('压缩包正在准备，请稍后再次下载。');
    }
    $tempFiles = [];
    try {
      $realZip = $this->writableDir('private://xedc-zips');
      $realDir = $this->writableDir('private://xedc-download-tmp/' . $name . '-' . bin2hex(random_bytes(4)));
      $partial = $realZip . '/' . (int) $resource->id() . '-' . bin2hex(random_bytes(4)) . '.partial.zip';
      $zip = new \ZipArchive();
      if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
        throw new \RuntimeException('无法创建压缩包。');
      }
      $used = [];
      foreach ($plan['files'] as $file) {
        $local = $this->localPath((string) $file['uri'], $realDir, $tempFiles);
        $entry = $this->entryName((string) $file['filename'], $used);
        if (!$zip->addFile($local, $entry)) {
          $zip->close();
          @unlink($partial);
          throw new \RuntimeException('无法把原图写入压缩包。');
        }
      }
      if (!$zip->close()) {
        @unlink($partial);
        throw new \RuntimeException('压缩包没有写完。压缩目录需要允许网站进程创建临时文件。');
      }
      $destination = $this->fileSystem->realpath($uri);
      if (!is_string($destination)) {
        $destination = $realZip . '/' . basename($uri);
      }
      if (is_file($destination)) {
        @unlink($destination);
      }
      if (!@rename($partial, $destination)) {
        throw new \RuntimeException('压缩包无法保存。');
      }
      return $uri;
    }
    finally {
      foreach ($tempFiles as $path) {
        if (is_file($path)) {
          @unlink($path);
        }
      }
      $this->lock->release($name);
    }
  }

  public function enqueue(DownloadResource $resource): void {
    $this->queueFactory->get('xedc_download_zip')->createItem(['id' => (int) $resource->id()]);
  }

  /**
   * Builds an archive from a queue item. Large packs are allowed here.
   */
  public function buildQueued(int $id): void {
    $resource = \Drupal::entityTypeManager()->getStorage('xedc_download')->load($id);
    if (!$resource instanceof DownloadResource || $resource->get('source_type')->value !== 'zip') {
      return;
    }
    $this->note($resource, 'running', '正在后台生成压缩包。');
    try {
      $node = $resource->get('parent')->entity;
      if (!$node instanceof NodeInterface) {
        throw new \RuntimeException('内容不存在，无法打包。');
      }
      $plan = $this->plan($node);
      if ($plan['count'] < 1 || $plan['bytes'] > 2147483648) {
        throw new \RuntimeException('图片数量或大小不适合打包。');
      }
      try {
        $this->buildForced($resource);
      }
      catch (ZipPendingException) {
        $this->buildForced($resource);
      }
      $this->note($resource, 'ready', '压缩包已生成。');
    }
    catch (\Throwable $e) {
      $this->note($resource, 'failed', $e->getMessage());
      throw $e;
    }
  }

  public function purgeExpired(): int {
    try {
      $real = $this->writableDir('private://xedc-zips');
    }
    catch (\Throwable) {
      return 0;
    }
    if (!is_string($real) || !is_dir($real)) {
      return 0;
    }
    $removed = 0;
    $cutoff = time() - self::READY_TTL;
    foreach (scandir($real) ?: [] as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $path = $real . '/' . $name;
      if (is_file($path) && filemtime($path) < $cutoff) {
        if (@unlink($path)) {
          $removed++;
        }
      }
    }
    $temp = $this->fileSystem->realpath('private://xedc-download-tmp');
    if (is_string($temp) && is_dir($temp)) {
      foreach (scandir($temp) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
          continue;
        }
        $path = $temp . '/' . $name;
        if (is_dir($path) && filemtime($path) < $cutoff) {
          $this->fileSystem->deleteRecursive($path);
          $removed++;
        }
      }
    }
    return $removed;
  }

  /**
   * Builds without the synchronous size cap. Still streams each file to disk.
   */
  public function buildForced(DownloadResource $resource): string {
    $node = $resource->get('parent')->entity;
    if (!$node instanceof NodeInterface) {
      throw new \RuntimeException('内容不存在，无法打包。');
    }
    $uri = $this->readyUri($resource, $node);
    if (is_file($uri) && filemtime($uri) >= (time() - self::READY_TTL)) {
      return $uri;
    }
    $plan = $this->plan($node);
    if (!class_exists(\ZipArchive::class)) {
      throw new \RuntimeException('服务器没有启用 ZIP 扩展。');
    }
    @set_time_limit(300);
    $name = 'xedc_zip_' . (int) $resource->id();
    if (!$this->lock->acquire($name, 300)) {
      throw new ZipPendingException('压缩包正在准备，请稍后再次下载。');
    }
    $tempFiles = [];
    try {
      $realZip = $this->writableDir('private://xedc-zips');
      $realDir = $this->writableDir('private://xedc-download-tmp/' . $name . '-' . bin2hex(random_bytes(4)));
      $partial = $realZip . '/' . (int) $resource->id() . '-' . bin2hex(random_bytes(4)) . '.partial.zip';
      $zip = new \ZipArchive();
      if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
        throw new \RuntimeException('无法创建压缩包。');
      }
      $used = [];
      foreach ($plan['files'] as $file) {
        $local = $this->localPath((string) $file['uri'], $realDir, $tempFiles);
        if (!$zip->addFile($local, $this->entryName((string) $file['filename'], $used))) {
          $zip->close();
          @unlink($partial);
          throw new \RuntimeException('无法把原图写入压缩包。');
        }
      }
      if (!$zip->close()) {
        @unlink($partial);
        throw new \RuntimeException('压缩包没有写完。压缩目录需要允许网站进程创建临时文件。');
      }
      $destination = $realZip . '/' . basename($uri);
      if (is_file($destination)) {
        @unlink($destination);
      }
      if (!@rename($partial, $destination)) {
        throw new \RuntimeException('压缩包无法保存。');
      }
      return $uri;
    }
    finally {
      foreach ($tempFiles as $path) {
        if (is_file($path)) {
          @unlink($path);
        }
      }
      $this->lock->release($name);
    }
  }

  /**
   * Creates a private directory the website user can write ZIP temp files into.
   */
  public function writableDir(string $uri): string {
    $this->fileSystem->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $real = $this->fileSystem->realpath($uri);
    if (!is_string($real) || !is_dir($real)) {
      throw new \RuntimeException('无法创建临时目录。');
    }
    if (!is_writable($real)) {
      @chmod($real, 0775);
    }
    if (!is_writable($real) && function_exists('posix_geteuid') && posix_geteuid() === 0 && function_exists('posix_getpwnam')) {
      $user = posix_getpwnam('www-data');
      if (is_array($user)) {
        @chown($real, (string) $user['name']);
        @chgrp($real, (string) $user['name']);
        @chmod($real, 0775);
      }
    }
    if (!is_writable($real)) {
      @chmod($real, 0777);
    }
    if (!is_writable($real)) {
      throw new \RuntimeException('压缩目录不可写。');
    }
    return $real;
  }

  /**
   * Remembers the latest ZIP job for the admin list. One row per resource.
   */
  public function note(DownloadResource $resource, string $status, string $message = ''): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('xedc_zip_job')) {
      return;
    }
    $now = \Drupal::time()->getRequestTime();
    $fields = [
      'content_id' => (int) $resource->get('parent')->target_id,
      'status' => mb_substr($status, 0, 16),
      'message' => mb_substr($message, 0, 255),
      'changed' => $now,
    ];
    $id = $database->select('xedc_zip_job', 'z')
      ->fields('z', ['id'])
      ->condition('resource_id', (int) $resource->id())
      ->execute()
      ->fetchField();
    if ($id) {
      $database->update('xedc_zip_job')->fields($fields)->condition('id', $id)->execute();
      return;
    }
    $database->insert('xedc_zip_job')->fields($fields + [
      'resource_id' => (int) $resource->id(),
      'created' => $now,
    ])->execute();
  }

  /**
   * @param list<string> $tempFiles
   *   Local copies created for remote files. The caller deletes them.
   */
  protected function localPath(string $uri, string $realDir, array &$tempFiles): string {
    $real = $this->fileSystem->realpath($uri);
    if (is_string($real) && is_file($real) && !is_link($real)) {
      return $real;
    }
    $target = $realDir . '/' . bin2hex(random_bytes(8)) . '.bin';
    $in = @fopen($uri, 'rb');
    if ($in === FALSE) {
      throw new \RuntimeException('无法读取原图。');
    }
    $out = fopen($target, 'wb');
    if ($out === FALSE) {
      fclose($in);
      throw new \RuntimeException('无法写入临时文件。');
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    $tempFiles[] = $target;
    return $target;
  }

  /**
   * @param array<string, int> $used
   *   Names already added to this archive.
   */
  protected function entryName(string $filename, array &$used): string {
    $base = basename(str_replace('\\', '/', $filename));
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?: 'image';
    if (!isset($used[$base])) {
      $used[$base] = 1;
      return $base;
    }
    $used[$base]++;
    $info = pathinfo($base);
    $suffix = '_' . $used[$base];
    $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
    return ($info['filename'] ?? 'image') . $suffix . $extension;
  }

}
