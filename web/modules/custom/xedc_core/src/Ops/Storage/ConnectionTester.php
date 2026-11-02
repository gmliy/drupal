<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Uploads, reads and deletes one object under the test prefix.
 */
final class ConnectionTester {

  public function __construct(
    protected StorageConfig $config,
    protected StorageJournal $journal,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected CredentialVault $credentials,
  ) {}

  /**
   * @return array<string, mixed>
   *   Public test result.
   */
  public function test(string $profileId): array {
    $profile = $this->config->profile($profileId);
    if ($profile === NULL) {
      return $this->finish($profileId, 'failed', '供应商配置不存在。', []);
    }
    $resolved = $this->credentials->resolve($profile);
    if (!$resolved['ok']) {
      $reason = $resolved['missing'] !== []
        ? '配置不完整：未读取到 ' . implode('、', $resolved['missing']) . '。网站仍使用本地文件。'
        : implode(' ', $resolved['errors']);
      $status = $resolved['source'] === 'admin' ? 'incomplete' : ($resolved['missing'] !== [] ? 'pending_credentials' : 'failed');
      return $this->finish($profileId, $status, $reason, [
        'bucket' => (string) $resolved['bucket'],
        'endpoint' => $this->host((string) $resolved['endpoint']),
      ]);
    }
    $connection = $resolved['connection'];
    $secrets = [(string) $connection['access_key'], (string) $connection['secret_key']];
    $lock = 'xedc_storage_test_' . $profileId;
    if (!$this->lock->acquire($lock, 45.0)) {
      return $this->finish($profileId, 'blocked', '已有连接测试在进行。', []);
    }
    $started = microtime(TRUE);
    $key = BootGuard::TEST_PREFIX . 'connection-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.txt';
    $body = "xedc-storage-test\n" . bin2hex(random_bytes(32)) . "\n";
    $source = IntegrityChecker::fingerprint($body, 'text/plain');
    $deleted = FALSE;
    $client = NULL;
    try {
      if (BootGuard::testKey($key) !== NULL) {
        throw new \RuntimeException('测试对象键无效。');
      }
      $client = S3ClientFactory::create($connection);
      $listOk = TRUE;
      try {
        $client->listObjectsV2([
          'Bucket' => $connection['bucket'],
          'Prefix' => BootGuard::TEST_PREFIX,
          'MaxKeys' => 1,
        ]);
      }
      catch (\Aws\S3\Exception\S3Exception $listError) {
        $listCode = (string) $listError->getAwsErrorCode();
        if (!in_array($listCode, ['AccessDenied', 'AllAccessDisabled'], TRUE)) {
          throw $listError;
        }
        $listOk = FALSE;
      }
      $client->putObject([
        'Bucket' => $connection['bucket'],
        'Key' => $key,
        'Body' => $body,
        'ContentType' => 'text/plain',
        'CacheControl' => 'private, max-age=60',
        'Metadata' => [
          'xedc-sha256' => $source['sha256'],
        ],
      ]);
      $head = $client->headObject([
        'Bucket' => $connection['bucket'],
        'Key' => $key,
      ]);
      $object = $client->getObject([
        'Bucket' => $connection['bucket'],
        'Key' => $key,
      ]);
      $downloaded = (string) $object['Body'];
      $etag = isset($head['ETag']) ? (string) $head['ETag'] : '';
      $target = IntegrityChecker::fingerprint($downloaded, (string) ($head['ContentType'] ?? ''), $etag);
      $compare = IntegrityChecker::compare($source, $target);
      $metadata = strtolower((string) ($head['Metadata']['xedc-sha256'] ?? '')) === $source['sha256'];
      $cache = (string) ($head['CacheControl'] ?? '') === 'private, max-age=60';
      $client->deleteObject([
        'Bucket' => $connection['bucket'],
        'Key' => $key,
      ]);
      $deleted = !$client->doesObjectExistV2($connection['bucket'], $key);
      $ok = $compare['ok'] && $compare['mime'] && $metadata && $deleted;
      $message = $ok
        ? '连接成功。测试对象已上传、读取、校验并删除。没有改动 Drupal 文件。'
        : '测试失败。测试对象已处理，但完整性或删除确认没有全部通过。业务文件没有删除。';
      if (!$listOk) {
        $message .= ' 列表权限不足。';
      }
      if (!$deleted) {
        $message = '测试失败。测试对象删除确认失败，位置：' . $key . '。业务文件没有删除。';
      }
      $result = $this->finish($profileId, $ok && $listOk ? 'passed' : ($ok ? 'partial' : 'failed'), $message, [
        'bucket' => (string) $connection['bucket'],
        'endpoint' => $this->host((string) $connection['endpoint']),
        'latency_ms' => (int) round((microtime(TRUE) - $started) * 1000),
        'sha256' => $compare['sha256'],
        'bytes' => $compare['bytes'],
        'mime' => $compare['mime'],
        'metadata' => $metadata,
        'cache_control' => $cache,
        'etag' => BootGuard::redact($etag),
        'etag_is_md5' => $target['etag_is_md5'],
        'deleted' => $deleted,
        'key_prefix' => BootGuard::TEST_PREFIX,
      ]);
      $this->journal->increment([
        'upload_ok' => 1,
        'read_ok' => $compare['ok'] ? 1 : 0,
        'delete_ok' => $deleted ? 1 : 0,
        'upload_failed' => 0,
      ]);
      return $result;
    }
    catch (\Throwable $error) {
      $code = $error instanceof \Aws\S3\Exception\S3Exception ? (string) $error->getAwsErrorCode() : '';
      $message = $this->failureLabel($error) . ($code !== '' ? '（' . $code . '）' : '') . '。没有改动 Drupal 文件。';
      $message = BootGuard::redact($message, $secrets);
      if ($client && !$deleted) {
        try {
          if (BootGuard::testKey($key) === NULL) {
            $client->deleteObject([
              'Bucket' => $connection['bucket'],
              'Key' => $key,
            ]);
          }
        }
        catch (\Throwable $cleanup) {
          $message .= ' 测试对象清理失败，位置：' . $key . '。';
        }
      }
      $this->logger->error('对象存储连接测试失败：@message', ['@message' => $message]);
      $this->journal->increment(['upload_failed' => 1]);
      return $this->finish($profileId, 'failed', $message, [
        'bucket' => (string) $connection['bucket'],
        'endpoint' => $this->host((string) $connection['endpoint']),
        'latency_ms' => (int) round((microtime(TRUE) - $started) * 1000),
        'deleted' => FALSE,
      ]);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * @return array<string, mixed>
   *   Last stored results keyed by profile.
   */
  public function stored(): array {
    $all = $this->journal->all();
    $tests = $all['tests'] ?? [];
    return is_array($tests) ? $tests : [];
  }

  /**
   * @param array<string, mixed> $extra
   *   Public fields.
   *
   * @return array<string, mixed>
   *   Stored result.
   */
  private function finish(string $profileId, string $status, string $message, array $extra): array {
    $result = $extra + [
      'profile' => $profileId,
      'status' => $status,
      'message' => BootGuard::redact($message),
      'checked' => $this->time->getRequestTime(),
      'sha256' => FALSE,
      'bytes' => FALSE,
      'mime' => FALSE,
      'metadata' => FALSE,
      'deleted' => FALSE,
    ];
    unset($result['access_key'], $result['secret_key'], $result['connection']);
    $stored = $this->journal->all();
    $tests = is_array($stored['tests'] ?? NULL) ? $stored['tests'] : [];
    $tests[$profileId] = $result;
    $this->journal->put('tests', $tests);
    $this->journal->add($status === 'passed' ? 'info' : 'error', $profileId . ' 连接测试：' . $result['message']);
    return $result;
  }

  private function failureLabel(\Throwable $error): string {
    if ($error instanceof \Aws\S3\Exception\S3Exception) {
      return match ((string) $error->getAwsErrorCode()) {
        'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidClientTokenId', 'UnrecognizedClientException', 'InvalidSignature' => '凭证错误',
        'NoSuchBucket' => 'Bucket 不存在',
        'AccessDenied', 'AllAccessDisabled' => '权限不足',
        'RequestTimeout', 'RequestTimeTooSkewed' => '网络超时',
        default => '测试失败',
      };
    }
    $text = $error->getMessage();
    if (stripos($text, 'timed out') !== FALSE || str_contains($text, 'cURL error 28')) {
      return '网络超时';
    }
    if (str_contains($text, 'cURL error 6') || str_contains($text, 'cURL error 7') || str_contains($text, 'Could not resolve')) {
      return 'Endpoint 无法连接';
    }
    return '测试失败';
  }

  private function host(string $endpoint): string {
    if ($endpoint === '') {
      return 'AWS 默认 Endpoint';
    }
    $host = parse_url($endpoint, PHP_URL_HOST);
    return is_string($host) ? $host : '已配置';
  }

}
