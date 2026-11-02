<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Aws\S3\Exception\S3Exception;

/**
 * Streams object bodies. It does not load a whole object into a string.
 */
final class RemoteFileReader {

  public function __construct(
    protected LocalTempFileManager $temp,
  ) {}

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection. The caller keeps the secret values.
   *
   * @return array<string, mixed>|null
   *   Head fields, or NULL when the object is missing.
   */
  public function head(array $connection, string $key): ?array {
    try {
      $result = $this->client($connection)->headObject([
        'Bucket' => (string) $connection['bucket'],
        'Key' => $key,
      ]);
    }
    catch (S3Exception $error) {
      if ($error->getStatusCode() === 404) {
        return NULL;
      }
      throw $error;
    }
    return [
      'bytes' => (int) ($result['ContentLength'] ?? 0),
      'mime' => $this->baseMime((string) ($result['ContentType'] ?? '')),
      'cache_control' => (string) ($result['CacheControl'] ?? ''),
      'etag' => trim((string) ($result['ETag'] ?? ''), '"'),
    ];
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  public function hash(array $connection, string $key): ?string {
    $result = $this->client($connection)->getObject([
      'Bucket' => (string) $connection['bucket'],
      'Key' => $key,
    ]);
    $body = $result['Body'] ?? NULL;
    if (!is_object($body) || !method_exists($body, 'read') || !method_exists($body, 'eof')) {
      return NULL;
    }
    $context = hash_init('sha256');
    while (!$body->eof()) {
      $chunk = $body->read(1048576);
      if (!is_string($chunk) || $chunk === '') {
        break;
      }
      hash_update($context, $chunk);
    }
    $hash = hash_final($context);
    return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : NULL;
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  public function readRange(array $connection, string $key, int $start, int $end): string {
    if ($start < 0 || $end < $start || ($end - $start) > 1048576) {
      throw new \RuntimeException('读取范围无效。');
    }
    $result = $this->client($connection)->getObject([
      'Bucket' => (string) $connection['bucket'],
      'Key' => $key,
      'Range' => 'bytes=' . $start . '-' . $end,
    ]);
    $body = $result['Body'] ?? NULL;
    if (!is_object($body) || !method_exists($body, 'read')) {
      return '';
    }
    $data = '';
    while (method_exists($body, 'eof') && !$body->eof() && strlen($data) <= 1048576) {
      $chunk = $body->read(8192);
      if (!is_string($chunk) || $chunk === '') {
        break;
      }
      $data .= $chunk;
    }
    return $data;
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  public function streamTo(array $connection, string $key, string $destination): bool {
    if (!$this->keyAllowed($key) || !$this->temp->contains($destination) || is_link($destination)) {
      return FALSE;
    }
    $secrets = $this->secrets($connection);
    try {
      $result = $this->client($connection)->getObject([
        'Bucket' => (string) $connection['bucket'],
        'Key' => $key,
      ]);
    }
    catch (\Throwable $error) {
      $this->temp->recordDiagnostic('read', BootGuard::safeError($error, $secrets), $secrets);
      return FALSE;
    }
    $body = $result['Body'] ?? NULL;
    if (!is_object($body) || !method_exists($body, 'read')) {
      $this->temp->recordDiagnostic('read', '对象没有可读内容。', $secrets);
      return FALSE;
    }
    $output = fopen($destination, 'wb');
    if ($output === FALSE) {
      return FALSE;
    }
    $written = 0;
    $failed = FALSE;
    while (method_exists($body, 'eof') && !$body->eof()) {
      $chunk = $body->read(1048576);
      if (!is_string($chunk) || $chunk === '') {
        break;
      }
      $saved = fwrite($output, $chunk);
      if ($saved === FALSE) {
        $failed = TRUE;
        break;
      }
      $written += $saved;
    }
    fclose($output);
    $expected = (int) ($result['ContentLength'] ?? -1);
    $complete = !$failed && ($expected < 0 || $written === $expected);
    if (!$complete || is_link($destination) || !$this->temp->contains($destination)) {
      if (is_file($destination) && !is_link($destination)) {
        $this->temp->delete($destination);
      }
      $this->temp->recordDiagnostic('read', '对象没有完整写入临时文件。', $secrets);
      return FALSE;
    }
    chmod($destination, 0600);
    return TRUE;
  }

  /**
   * Copies an object into a new temporary file. The caller deletes it.
   *
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  public function copyToTemp(array $connection, string $key): string {
    $suffix = pathinfo($key, PATHINFO_EXTENSION);
    $path = $this->temp->createFile('object', $suffix === '' ? '' : '.' . $suffix);
    if (!$this->streamTo($connection, $key, $path)) {
      $this->temp->delete($path);
      throw new \RuntimeException('远程对象没有写入本地临时文件。');
    }
    return $path;
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  private function client(array $connection): \Aws\S3\S3Client {
    $connection['sdk_attempts'] = 1;
    $connection['timeout'] = (int) ($connection['timeout'] ?? 60);
    return S3ClientFactory::create($connection);
  }

  private function baseMime(string $mime): string {
    return strtolower(trim(explode(';', $mime)[0]));
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   *
   * @return list<string>
   *   Secret values to redact from diagnostics.
   */
  private function secrets(array $connection): array {
    $secrets = [];
    foreach (['access_key', 'secret_key'] as $name) {
      $value = $connection[$name] ?? '';
      if (is_string($value) && strlen($value) >= 8) {
        $secrets[] = $value;
      }
    }
    return $secrets;
  }

  private function keyAllowed(string $key): bool {
    $key = str_replace('\\', '/', $key);
    if ($key === '' || str_starts_with($key, '/') || str_contains($key, "\0") || str_contains($key, '..') || str_contains($key, '//')) {
      return FALSE;
    }
    return strlen($key) <= 900 && !preg_match('/[\x00-\x1F\x7F]/', $key);
  }

}
