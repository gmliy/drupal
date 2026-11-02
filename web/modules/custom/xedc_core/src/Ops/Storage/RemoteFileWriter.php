<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

/**
 * Uploads a local file, checks the result, and removes a failed new object.
 */
final class RemoteFileWriter {

  public function __construct(
    protected RemoteFileReader $reader,
    protected StorageUriResolver $uris,
    protected StorageJournal $journal,
    protected ?StorageOperationGuard $guard = NULL,
  ) {}

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   * @param array<string, mixed> $options
   *   mime, cache_control, overwrite, purpose, retries, timeout, multipart_at.
   *
   * @return array<string, mixed>
   *   Public result. It does not include credentials.
   */
  public function putFile(array $connection, string $localPath, string $key, array $options = []): array {
    $purpose = (string) ($options['purpose'] ?? 'test');
    if (!in_array($purpose, ['test', 'migration', 'processed', 'integration'], TRUE)) {
      return $this->fail('rejected', '未知的对象写入用途。');
    }
    $folders = $this->uris->folders();
    $allowed = $this->uris->allowsKey($key, $purpose, $folders);
    if ($allowed !== NULL) {
      return $this->fail('rejected', $allowed);
    }
    if (!is_file($localPath) || is_link($localPath)) {
      return $this->fail('rejected', '本地源文件不存在。');
    }
    $bytes = filesize($localPath);
    $hash = hash_file('sha256', $localPath);
    if ($bytes === FALSE || !is_string($hash)) {
      return $this->fail('rejected', '无法读取本地源文件。');
    }
    $mime = $this->mime((string) ($options['mime'] ?? 'application/octet-stream'));
    $cache = trim((string) ($options['cache_control'] ?? ''));
    if ($cache !== '' && BootGuard::cacheControlError($cache) !== NULL) {
      return $this->fail('rejected', 'Cache-Control 无效。');
    }
    $overwrite = !empty($options['overwrite']);
    $retries = (int) ($options['retries'] ?? 3);
    if ($retries < 1 || $retries > 5) {
      $retries = 3;
    }
    $secrets = [(string) ($connection['access_key'] ?? ''), (string) ($connection['secret_key'] ?? '')];
    $last = '上传失败。';
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
      $created = FALSE;
      try {
        $client = $this->client($connection, (int) ($options['timeout'] ?? 60));
        $existing = $this->reader->head($connection, $key);
        if ($existing !== NULL) {
          $remoteHash = $this->reader->hash($connection, $key);
          if (is_string($remoteHash) && hash_equals($hash, $remoteHash) && (int) $existing['bytes'] === (int) $bytes) {
            return $this->ok('exists', '远程对象已存在且 SHA-256 一致，未覆盖。', $hash, (int) $bytes, $mime, (string) $existing['etag'], FALSE);
          }
          if (!$overwrite) {
            return $this->fail('conflict', '远程对象已存在且内容不同，未覆盖。');
          }
        }
        $this->upload($client, $connection, $localPath, $key, $mime, $cache, (int) $bytes, (int) ($options['multipart_at'] ?? 8388608));
        $created = $existing === NULL;
        $head = $this->reader->head($connection, $key);
        $remoteHash = $this->reader->hash($connection, $key);
        $mimeOk = is_array($head) && ($head['mime'] === '' || $head['mime'] === $mime);
        $cacheOk = $cache === '' || (is_array($head) && $head['cache_control'] === $cache);
        $requireCache = !empty($options['require_cache']);
        if (!is_array($head) || (int) $head['bytes'] !== (int) $bytes || !is_string($remoteHash) || !hash_equals($hash, $remoteHash) || !$mimeOk || ($requireCache && !$cacheOk)) {
          throw new \RuntimeException('上传后的大小、SHA-256 或响应头与源文件不一致。');
        }
        $this->journal->increment(['upload_ok' => 1]);
        return $this->ok('uploaded', '上传已校验。', $hash, (int) $bytes, (string) $head['mime'], (string) $head['etag'], FALSE) + [
          'cache_control' => (string) $head['cache_control'],
          'cache_ok' => $cacheOk,
        ];
      }
      catch (\Throwable $error) {
        $last = BootGuard::safeError($error, $secrets);
        $deleted = FALSE;
        if ($created) {
          $deleted = $this->removeNewObject($connection, $key, $purpose);
        }
        if ($attempt === $retries) {
          $this->journal->increment(['upload_failed' => 1]);
          return $this->fail('failed', $last, $deleted);
        }
        usleep(200000);
      }
    }
    return $this->fail('failed', $last);
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  public function delete(array $connection, string $key, string $purpose, int $fid = 0): bool {
    if ($purpose === 'processed') {
      $reason = $this->guard instanceof StorageOperationGuard
        ? $this->guard->objectDeleteBlockReason($key, $fid)
        : '删除远程对象前必须确认文件实体。';
      if ($reason !== NULL) {
        $this->journal->add('error', '拒绝删除对象：' . $reason);
        return FALSE;
      }
    }
    return $this->removeNewObject($connection, $key, $purpose);
  }

  /**
   * Removes an object this writer just created. It does not delete the local source.
   *
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  private function removeNewObject(array $connection, string $key, string $purpose): bool {
    $folders = $this->uris->folders();
    $mapped = match ($purpose) {
      'migration' => 'migration-rollback',
      'processed' => 'processed',
      'integration' => 'integration',
      default => 'test',
    };
    if ($this->uris->allowsKey($key, $mapped, $folders) !== NULL) {
      return FALSE;
    }
    try {
      $this->client($connection, 30)->deleteObject([
        'Bucket' => (string) $connection['bucket'],
        'Key' => $key,
      ]);
      $this->journal->increment(['delete_ok' => 1]);
      return $this->reader->head($connection, $key) === NULL;
    }
    catch (\Throwable $error) {
      $this->journal->add('error', '删除对象失败：' . BootGuard::safeError($error, [
        (string) ($connection['access_key'] ?? ''),
        (string) ($connection['secret_key'] ?? ''),
      ]));
      return FALSE;
    }
  }

  /**
   * Uploads a processed local file. The local source is never deleted.
   *
   * @param array<string, mixed> $connection
   *   Resolved connection.
   * @param array<string, mixed> $options
   *   mime, cache_control, overwrite.
   *
   * @return array<string, mixed>
   *   Public result.
   */
  public function putProcessed(array $connection, string $localPath, string $key, array $options = []): array {
    $options['purpose'] = 'processed';
    $existed = is_file($localPath);
    $result = $this->putFile($connection, $localPath, $key, $options);
    if ($existed && !is_file($localPath)) {
      return $this->fail('failed', '本地源文件在写回后丢失。');
    }
    return $result;
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  private function upload(S3Client $client, array $connection, string $path, string $key, string $mime, string $cache, int $bytes, int $threshold): void {
    $params = [
      'Bucket' => (string) $connection['bucket'],
      'Key' => $key,
      'SourceFile' => $path,
      'ContentType' => $mime,
    ];
    if ($cache !== '') {
      $params['CacheControl'] = $cache;
    }
    if ($threshold < 262144) {
      $threshold = 262144;
    }
    if ($bytes < $threshold) {
      $client->putObject($params);
      return;
    }
    $uploader = new MultipartUploader($client, $path, [
      'bucket' => (string) $connection['bucket'],
      'key' => $key,
      'before_initiate' => static function ($command) use ($mime, $cache): void {
        $command['ContentType'] = $mime;
        if ($cache !== '') {
          $command['CacheControl'] = $cache;
        }
      },
    ]);
    $uploader->upload();
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  private function client(array $connection, int $timeout): S3Client {
    $connection['sdk_attempts'] = 1;
    $connection['timeout'] = $timeout;
    return S3ClientFactory::create($connection);
  }

  private function mime(string $mime): string {
    return BootGuard::contentType($mime);
  }

  /**
   * @return array<string, mixed>
   *   Success result.
   */
  private function ok(string $status, string $message, string $hash, int $bytes, string $mime, string $etag, bool $deleted): array {
    return [
      'ok' => TRUE,
      'status' => $status,
      'message' => $message,
      'sha256' => $hash,
      'bytes' => $bytes,
      'mime' => $mime,
      'etag' => $etag,
      'deleted_orphan' => $deleted,
    ];
  }

  /**
   * @return array<string, mixed>
   *   Failure result.
   */
  private function fail(string $status, string $message, bool $deleted = FALSE): array {
    return [
      'ok' => FALSE,
      'status' => $status,
      'message' => $message,
      'sha256' => '',
      'bytes' => 0,
      'mime' => '',
      'etag' => '',
      'deleted_orphan' => $deleted,
    ];
  }

}
