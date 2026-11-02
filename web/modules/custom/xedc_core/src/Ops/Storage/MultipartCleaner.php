<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Aborts stale multipart uploads inside the test prefix only.
 */
final class MultipartCleaner {

  private const RETENTION_SECONDS = 86400;

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
   *   Cleanup result.
   */
  public function cleanup(string $profileId): array {
    $profile = $this->config->profile($profileId);
    if ($profile === NULL) {
      return ['status' => 'failed', 'message' => '供应商配置不存在。', 'aborted' => 0];
    }
    $resolved = $this->credentials->resolve($profile);
    if (!$resolved['ok']) {
      $status = $resolved['missing'] !== [] ? 'pending_credentials' : 'failed';
      $message = $resolved['missing'] !== [] ? '待凭证验证，没有列出分片。' : implode(' ', $resolved['errors']);
      return ['status' => $status, 'message' => $message, 'aborted' => 0];
    }
    if (!$this->lock->acquire('xedc_storage_multipart_' . $profileId, 40.0)) {
      return ['status' => 'blocked', 'message' => '已有分片清理在进行。', 'aborted' => 0];
    }
    $secrets = [
      (string) $resolved['connection']['access_key'],
      (string) $resolved['connection']['secret_key'],
    ];
    try {
      $client = S3ClientFactory::create($resolved['connection']);
      $listed = $client->listMultipartUploads([
        'Bucket' => $resolved['connection']['bucket'],
        'Prefix' => BootGuard::TEST_PREFIX,
        'MaxUploads' => 20,
      ]);
      $uploads = $listed['Uploads'] ?? [];
      if (!is_array($uploads)) {
        $uploads = [];
      }
      $aborted = 0;
      $kept = 0;
      $now = $this->time->getRequestTime();
      foreach ($uploads as $upload) {
        if (!is_array($upload)) {
          continue;
        }
        $key = (string) ($upload['Key'] ?? '');
        $uploadId = (string) ($upload['UploadId'] ?? '');
        if ($uploadId === '' || BootGuard::testKey($key) !== NULL) {
          continue;
        }
        $initiated = $upload['Initiated'] ?? NULL;
        $started = $initiated instanceof \DateTimeInterface ? $initiated->getTimestamp() : 0;
        if ($started <= 0 || ($now - $started) < self::RETENTION_SECONDS) {
          $kept++;
          continue;
        }
        $client->abortMultipartUpload([
          'Bucket' => $resolved['connection']['bucket'],
          'Key' => $key,
          'UploadId' => $uploadId,
        ]);
        $aborted++;
      }
      $message = '已中止 ' . $aborted . ' 个超过 24 小时的测试分片，保留 ' . $kept . ' 个较新的测试分片。没有处理其他前缀。';
      $this->journal->add('info', $profileId . ' ' . $message);
      return ['status' => 'passed', 'message' => $message, 'aborted' => $aborted];
    }
    catch (\Throwable $error) {
      $message = BootGuard::safeError($error, $secrets);
      $this->logger->error('测试分片清理失败：@message', ['@message' => $message]);
      $this->journal->add('error', $profileId . ' 分片清理失败：' . $message);
      return ['status' => 'failed', 'message' => $message, 'aborted' => 0];
    }
    finally {
      $this->lock->release('xedc_storage_multipart_' . $profileId);
    }
  }

  public function cron(): void {
    try {
      if ((int) ($this->config->data()['multipart_cleanup'] ?? 0) !== 1) {
        return;
      }
      $done = 0;
      foreach ($this->config->profiles() as $profile) {
        if ($done >= 3) {
          return;
        }
        $this->cleanup((string) $profile['id']);
        $done++;
      }
    }
    catch (\Throwable $error) {
      $this->logger->error('对象存储分片清理已跳过：@message', [
        '@message' => BootGuard::safeError($error),
      ]);
    }
  }

}
