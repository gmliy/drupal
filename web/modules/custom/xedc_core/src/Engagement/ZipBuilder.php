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

  public const SYNC_BYTES = 33554432;

  public const SYNC_FILES = 10;

  public const CACHE_TTL = 604800;

  public const TEMP_TTL = 21600;

  public const PARTIAL_TTL = 7200;

  public const READY_TTL = self::CACHE_TTL;

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
    $stamp = substr(hash('sha256', json_encode(array_map(static fn(array $file): string => $file['fid'] . ':' . $file['size'] . ':' . ($file['changed'] ?? 0) . ':' . $file['filename'], $plan['files'])) ?: ''), 0, 16);
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
    $uri = $this->readyUri($resource, $node);
    if ($this->freshArchive($uri, $plan['count']) !== NULL) {
      $this->note($resource, 'ready', '缓存命中，未重新压缩。');
      $this->logPack($plan, 0, 0, 0, 0, TRUE);
      return $uri;
    }
    if ($this->shouldQueue($plan['count'], $plan['bytes'])) {
      throw new ZipPendingException($this->progressMessage($plan, '图片较多，已改为后台打包。'));
    }
    return $this->assemble($resource, $plan, $uri);
  }

  public function shouldQueue(int $count, int $bytes): bool {
    if ($count < 1) {
      return FALSE;
    }
    if ($bytes < 1) {
      return TRUE;
    }
    return $count > self::SYNC_FILES || $bytes > self::SYNC_BYTES;
  }

  /**
   * @return array{state: string, message: string, count: int, bytes: int, cached: bool}
   *   Read-only status. Does not build or enqueue.
   */
  public function inspect(DownloadResource $resource): array {
    $node = $resource->get('parent')->entity;
    if (!$node instanceof NodeInterface) {
      return ['state' => 'failed', 'message' => '内容不存在，无法打包。', 'count' => 0, 'bytes' => 0, 'cached' => FALSE];
    }
    $plan = $this->plan($node);
    $base = ['count' => $plan['count'], 'bytes' => $plan['bytes'], 'cached' => FALSE];
    if ($plan['count'] < 1) {
      return array_merge($base, ['state' => 'failed', 'message' => '这篇内容没有可打包的原图。']);
    }
    $uri = $this->readyUri($resource, $node);
    if ($this->freshArchive($uri, $plan['count']) !== NULL) {
      return array_merge($base, ['state' => 'ready', 'cached' => TRUE, 'message' => '压缩包已准备好，即将开始下载。']);
    }
    if (!$this->lock->lockMayBeAvailable('xedc_zip_' . (int) $resource->id())) {
      return array_merge($base, ['state' => 'running', 'message' => $this->progressMessage($plan, '正在生成压缩包。')]);
    }
    if ($this->shouldQueue($plan['count'], $plan['bytes'])) {
      return array_merge($base, ['state' => 'queued', 'message' => $this->progressMessage($plan, '图片较多，已改为后台打包。')]);
    }
    return array_merge($base, ['state' => 'sync', 'message' => $this->progressMessage($plan, '正在准备压缩包。')]);
  }

  public function enqueue(DownloadResource $resource): void {
    $id = (int) $resource->id();
    if (!$this->lock->lockMayBeAvailable('xedc_zip_' . $id)) {
      return;
    }
    $gate = 'xedc_zip_enqueue_' . $id;
    if (!$this->lock->acquire($gate, 10)) {
      return;
    }
    try {
      $database = \Drupal::database();
      if ($database->schema()->tableExists('xedc_zip_job')) {
        $row = $database->select('xedc_zip_job', 'z')
          ->fields('z', ['status', 'changed'])
          ->condition('resource_id', $id)
          ->execute()
          ->fetchObject();
        $now = \Drupal::time()->getRequestTime();
        if ($row && in_array((string) $row->status, ['queued', 'running'], TRUE) && (int) $row->changed >= ($now - 900)) {
          return;
        }
      }
      $this->queueFactory->get('xedc_download_zip')->createItem(['id' => $id]);
    }
    finally {
      $this->lock->release($gate);
    }
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
    $now = time();
    $touched = [];
    foreach (scandir($real) ?: [] as $name) {
      if ($name === '.' || $name === '..' || !preg_match('/^(\d+)-.+\.zip$/', $name, $match)) {
        continue;
      }
      $id = (int) $match[1];
      if (!$this->lock->lockMayBeAvailable('xedc_zip_' . $id)) {
        continue;
      }
      $path = $real . '/' . $name;
      if (!is_file($path)) {
        continue;
      }
      $partial = str_ends_with($name, '.partial.zip');
      $limit = $partial ? self::PARTIAL_TTL : self::CACHE_TTL;
      if (filemtime($path) >= ($now - $limit)) {
        continue;
      }
      if (@unlink($path)) {
        $removed++;
        if (!$partial) {
          $touched[$id] = TRUE;
        }
      }
    }
    foreach (array_keys($touched) as $id) {
      $still = FALSE;
      foreach (scandir($real) ?: [] as $name) {
        if (str_starts_with($name, $id . '-') && str_ends_with($name, '.zip') && !str_ends_with($name, '.partial.zip')) {
          $still = TRUE;
          break;
        }
      }
      if (!$still) {
        $this->markExpired($id);
      }
    }
    $temp = $this->fileSystem->realpath('private://xedc-download-tmp');
    if (is_string($temp) && is_dir($temp)) {
      foreach (scandir($temp) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
          continue;
        }
        $path = $temp . '/' . $name;
        if (!is_dir($path) || filemtime($path) >= ($now - self::TEMP_TTL)) {
          continue;
        }
        $resourceId = (int) $name;
        if ($resourceId > 0 && !$this->lock->lockMayBeAvailable('xedc_zip_' . $resourceId)) {
          continue;
        }
        $this->fileSystem->deleteRecursive($path);
        $removed++;
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
    $plan = $this->plan($node);
    if ($plan['count'] < 1 || $plan['bytes'] > 2147483648) {
      throw new \RuntimeException('图片数量或大小不适合打包。');
    }
    $uri = $this->readyUri($resource, $node);
    if ($this->freshArchive($uri, $plan['count']) !== NULL) {
      $this->note($resource, 'ready', '缓存命中，未重新压缩。');
      $this->logPack($plan, 0, 0, 0, 0, TRUE);
      return $uri;
    }
    return $this->assemble($resource, $plan, $uri);
  }

  /**
   * @param array{files: list<array<string, mixed>>, bytes: int, count: int} $plan
   *   Originals already counted for this content.
   */
  protected function assemble(DownloadResource $resource, array $plan, string $uri): string {
    if (!class_exists(\ZipArchive::class)) {
      throw new \RuntimeException('服务器没有启用 ZIP 扩展。');
    }
    @set_time_limit(300);
    $name = 'xedc_zip_' . (int) $resource->id();
    if (!$this->lock->acquire($name, 300)) {
      throw new ZipPendingException($this->progressMessage($plan, '压缩包正在准备。'));
    }
    $tempFiles = [];
    $realDir = '';
    $started = microtime(TRUE);
    $read = 0.0;
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
        $readStarted = microtime(TRUE);
        $local = $this->localPath((string) $file['uri'], $realDir, $tempFiles);
        $read += microtime(TRUE) - $readStarted;
        $entry = $this->entryName((string) $file['filename'], $used);
        if (!$zip->addFile($local, $entry) || !$zip->setCompressionName($entry, \ZipArchive::CM_STORE)) {
          $zip->close();
          @unlink($partial);
          throw new \RuntimeException('无法把原图写入压缩包：' . $entry);
        }
      }
      $writeStarted = microtime(TRUE);
      if (!$zip->close()) {
        @unlink($partial);
        throw new \RuntimeException('压缩包没有写完。压缩目录需要允许网站进程创建临时文件。');
      }
      $write = microtime(TRUE) - $writeStarted;
      $destination = $realZip . '/' . basename($uri);
      if (is_file($destination)) {
        @unlink($destination);
      }
      if (!@rename($partial, $destination)) {
        @unlink($partial);
        throw new \RuntimeException('压缩包无法保存。');
      }
      $verifyStarted = microtime(TRUE);
      if ($this->freshArchive($destination, $plan['count']) === NULL) {
        @unlink($destination);
        throw new \RuntimeException('压缩包校验没有通过。');
      }
      $verify = microtime(TRUE) - $verifyStarted;
      $this->logPack($plan, $read, $write, $verify, microtime(TRUE) - $started, FALSE);
      $this->note($resource, 'ready', $this->progressMessage($plan, '已生成，未再次压缩图片。'));
      return $uri;
    }
    finally {
      foreach ($tempFiles as $path) {
        if (is_file($path)) {
          @unlink($path);
        }
      }
      if ($realDir !== '' && is_dir($realDir)) {
        @rmdir($realDir);
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

  protected function freshArchive(string $uri, int $expectedCount): ?string {
    $path = $this->fileSystem->realpath($uri);
    if (!is_string($path) || !is_file($path) || is_link($path)) {
      $path = is_file($uri) && !is_link($uri) ? $uri : '';
    }
    if ($path === '' || filesize($path) < 22 || filemtime($path) < (time() - self::CACHE_TTL)) {
      return NULL;
    }
    $header = file_get_contents($path, FALSE, NULL, 0, 4);
    if ($header !== "PK\x03\x04") {
      return NULL;
    }
    $zip = new \ZipArchive();
    if ($zip->open($path) !== TRUE) {
      return NULL;
    }
    $count = $zip->numFiles;
    $zip->close();
    if ($expectedCount > 0 && $count !== $expectedCount) {
      return NULL;
    }
    return $uri;
  }

  /**
   * @param array{count: int, bytes: int} $plan
   *   Counted originals.
   */
  protected function progressMessage(array $plan, string $lead): string {
    $size = $plan['bytes'] > 0 ? \Drupal\xedc_core\Ops\OpsFormat::bytes($plan['bytes']) : '大小待确认';
    return $lead . '共 ' . $plan['count'] . ' 张，约 ' . $size . '。';
  }

  /**
   * @param array{files: list<array<string, mixed>>, bytes: int, count: int} $plan
   *   Originals used for this pack.
   */
  protected function logPack(array $plan, float $read, float $write, float $verify, float $total, bool $cached): void {
    $schemes = [];
    foreach ($plan['files'] as $file) {
      $scheme = strstr((string) ($file['uri'] ?? ''), '://', TRUE);
      $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'unknown';
      $schemes[$scheme] = ($schemes[$scheme] ?? 0) + 1;
    }
    $remote = 0;
    foreach ($schemes as $scheme => $count) {
      if (!in_array($scheme, ['public', 'private'], TRUE)) {
        $remote += $count;
      }
    }
    $labels = [];
    foreach ($schemes as $scheme => $count) {
      $labels[] = $scheme . ':' . $count;
    }
    \Drupal::logger('xedc_zip')->info('count=@count bytes=@bytes read_ms=@read write_ms=@write verify_ms=@verify total_ms=@total memory_mb=@memory remote=@remote cache=@cache schemes=@schemes', [
      '@count' => $plan['count'],
      '@bytes' => $plan['bytes'],
      '@read' => (int) round($read * 1000),
      '@write' => (int) round($write * 1000),
      '@verify' => (int) round($verify * 1000),
      '@total' => (int) round($total * 1000),
      '@memory' => round(memory_get_peak_usage(TRUE) / 1048576, 1),
      '@remote' => $remote,
      '@cache' => $cached ? 1 : 0,
      '@schemes' => implode(',', $labels),
    ]);
  }

  protected function markExpired(int $resourceId): void {
    $database = \Drupal::database();
    if ($resourceId < 1 || !$database->schema()->tableExists('xedc_zip_job')) {
      return;
    }
    $database->update('xedc_zip_job')
      ->fields([
        'status' => 'expired',
        'message' => '成功缓存已超过 7 天，下次下载会重新生成。',
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('resource_id', $resourceId)
      ->execute();
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
