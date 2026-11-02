<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks S3 API features for one saved profile. It does not change bucket policy.
 */
final class CapabilityProbe {

  public function __construct(
    protected StorageConfig $config,
    protected StorageJournal $journal,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
    protected CredentialVault $credentials,
  ) {}

  /**
   * @return array<string, array<string, string>>
   *   Capability id to label.
   */
  public static function definitions(): array {
    return [
      'connection' => '基础连接',
      'put' => '上传',
      'get' => '下载',
      'delete' => '删除',
      'list' => '列表',
      'copy' => '复制',
      'multipart' => '分片上传',
      'presign' => '预签名',
      'cors' => 'CORS',
      'metadata' => '元数据',
      'cache' => 'Cache-Control',
      'versioning' => '版本控制',
      'lifecycle' => '生命周期',
      'object_lock' => '对象锁',
    ];
  }

  /**
   * @return array<string, mixed>
   *   Public probe result.
   */
  public function probe(string $profileId): array {
    $profile = $this->config->profile($profileId);
    if ($profile === NULL) {
      return $this->store($profileId, 'failed', '供应商配置不存在。', $this->blank('配置不存在。'));
    }
    $resolved = $this->credentials->resolve($profile);
    if (!$resolved['ok']) {
      $status = $resolved['missing'] !== [] ? 'pending_credentials' : 'failed';
      $message = $resolved['missing'] !== []
        ? '待凭证验证，未向供应商发送请求。'
        : implode(' ', $resolved['errors']);
      return $this->store($profileId, $status, $message, $this->blank($message));
    }
    $lock = 'xedc_storage_probe_' . $profileId;
    if (!$this->lock->acquire($lock, 60.0)) {
      return $this->store($profileId, 'blocked', '已有能力检测在进行。', $this->blank('已有检测在进行。'));
    }
    $connection = $resolved['connection'];
    $secrets = [(string) $connection['access_key'], (string) $connection['secret_key']];
    $rows = $this->blank('尚未检测。');
    $bucket = (string) $connection['bucket'];
    $key = BootGuard::TEST_PREFIX . 'probe-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)) . '.txt';
    $copy = $key . '.copy';
    $body = "xedc-capability\n" . bin2hex(random_bytes(16));
    $client = NULL;
    try {
      $client = S3ClientFactory::create($connection);
      $rows['connection'] = $this->connection($client, $bucket);
      if ($rows['connection']['status'] === 'unsupported') {
        foreach ($rows as $name => $row) {
          if ($name !== 'connection') {
            $rows[$name] = $this->row('unverified', '基础连接没有建立，高级能力未检测。');
          }
        }
        return $this->store($profileId, 'failed', '基础连接没有建立。', $rows);
      }
      $rows = $this->objectChecks($client, $bucket, $key, $copy, $body, $connection, $rows);
      $rows['multipart'] = $this->multipart($client, $bucket, $key . '.part');
      $rows['versioning'] = $this->optionalApi($client, 'getBucketVersioning', $bucket, 'NoSuchBucket');
      $rows['lifecycle'] = $this->optionalApi($client, 'getBucketLifecycleConfiguration', $bucket, 'NoSuchLifecycleConfiguration');
      $rows['object_lock'] = $this->optionalApi($client, 'getObjectLockConfiguration', $bucket, 'ObjectLockConfigurationNotFoundError');
      $rows['cors'] = $this->optionalApi($client, 'getBucketCors', $bucket, 'NoSuchCORSConfiguration');
      $failed = 0;
      foreach (['connection', 'put', 'get', 'delete'] as $name) {
        if (($rows[$name]['status'] ?? '') === 'failed' || ($rows[$name]['status'] ?? '') === 'unsupported') {
          $failed++;
        }
      }
      $status = $failed === 0 ? 'passed' : 'partial';
      return $this->store($profileId, $status, '能力检测已完成。测试对象限于 ' . BootGuard::TEST_PREFIX . '，没有修改 Bucket 策略。', $rows);
    }
    catch (\Throwable $error) {
      $message = BootGuard::safeError($error, $secrets);
      $this->logger->error('对象存储能力检测失败：@message', ['@message' => $message]);
      return $this->store($profileId, 'failed', $message, $rows);
    }
    finally {
      if ($client) {
        $this->deleteQuietly($client, $bucket, [$key, $copy, $key . '.part'], $secrets);
      }
      $this->lock->release($lock);
    }
  }

  /**
   * @return array<string, mixed>
   *   Stored probes keyed by profile.
   */
  public function stored(): array {
    $all = $this->journal->all();
    $probes = $all['probes'] ?? [];
    return is_array($probes) ? $probes : [];
  }

  /**
   * @param array<string, array<string, string>> $rows
   *   Capability rows.
   *
   * @return array<string, mixed>
   *   Stored result.
   */
  private function store(string $profileId, string $status, string $message, array $rows): array {
    $result = [
      'profile' => $profileId,
      'status' => $status,
      'message' => BootGuard::redact($message),
      'checked' => $this->time->getRequestTime(),
      'rows' => $rows,
    ];
    $stored = $this->journal->all();
    $probes = is_array($stored['probes'] ?? NULL) ? $stored['probes'] : [];
    $probes[$profileId] = $result;
    $this->journal->put('probes', $probes);
    $this->journal->add($status === 'passed' ? 'info' : 'error', $profileId . ' 能力检测：' . $result['message']);
    return $result;
  }

  /**
   * @return array<string, array<string, string>>
   *   Rows filled with one status.
   */
  private function blank(string $detail): array {
    $rows = [];
    foreach (self::definitions() as $id => $label) {
      $rows[$id] = $this->row('unverified', $detail, $label);
    }
    return $rows;
  }

  /**
   * @return array<string, string>
   *   One capability row.
   */
  private function row(string $status, string $detail, string $label = ''): array {
    return [
      'status' => $status,
      'label' => $label,
      'detail' => BootGuard::redact($detail),
    ];
  }

  /**
   * @return array<string, string>
   *   Connection row.
   */
  private function connection(object $client, string $bucket): array {
    try {
      $client->headBucket(['Bucket' => $bucket]);
      return $this->row('supported', 'HeadBucket 成功。');
    }
    catch (\Throwable $error) {
      $head = BootGuard::safeError($error);
    }
    try {
      $client->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => BootGuard::TEST_PREFIX,
        'MaxKeys' => 1,
      ]);
      return $this->row('partial', 'HeadBucket 未成功（' . $head . '）。限定前缀的 ListObjectsV2 成功。');
    }
    catch (\Throwable $error) {
      return $this->row('unsupported', $head . '；列表也失败：' . BootGuard::safeError($error));
    }
  }

  /**
   * @param array<string, mixed> $connection
   *   Connection values.
   * @param array<string, array<string, string>> $rows
   *   Mutable rows.
   *
   * @return array<string, array<string, string>>
   *   Updated rows.
   */
  private function objectChecks(object $client, string $bucket, string $key, string $copy, string $body, array $connection, array $rows): array {
    $source = IntegrityChecker::fingerprint($body, 'text/plain');
    try {
      $client->putObject([
        'Bucket' => $bucket,
        'Key' => $key,
        'Body' => $body,
        'ContentType' => 'text/plain',
        'CacheControl' => 'private, max-age=60',
        'Metadata' => ['xedc-sha256' => $source['sha256']],
      ]);
      $rows['put'] = $this->row('supported', 'PutObject 成功，对象位于测试前缀。');
    }
    catch (\Throwable $error) {
      $rows['put'] = $this->classify($error, 'PutObject');
      $rows['get'] = $this->row('unverified', '上传未成功，下载未检测。');
      $rows['delete'] = $this->row('unverified', '上传未成功，删除未检测。');
      $rows['list'] = $this->row('unverified', '上传未成功，列表未检测。');
      $rows['copy'] = $this->row('unverified', '上传未成功，复制未检测。');
      $rows['metadata'] = $this->row('unverified', '上传未成功，元数据未检测。');
      $rows['cache'] = $this->row('unverified', '上传未成功，缓存头未检测。');
      $rows['presign'] = $this->row('unverified', '上传未成功，预签名未检测。');
      return $rows;
    }
    try {
      $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
      $metadata = strtolower((string) ($head['Metadata']['xedc-sha256'] ?? ''));
      $rows['metadata'] = $metadata === $source['sha256']
        ? $this->row('supported', '自定义元数据已读回。')
        : $this->row('partial', '对象可读，但自定义元数据没有原样读回。');
      $rows['cache'] = (string) ($head['CacheControl'] ?? '') === 'private, max-age=60'
        ? $this->row('supported', 'Cache-Control 已读回。')
        : $this->row('partial', '对象可读，但 Cache-Control 没有原样保存。');
    }
    catch (\Throwable $error) {
      $rows['metadata'] = $this->classify($error, 'HeadObject');
      $rows['cache'] = $this->classify($error, 'Cache-Control');
    }
    try {
      $downloaded = (string) $client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'];
      $compare = IntegrityChecker::compare($source, IntegrityChecker::fingerprint($downloaded, 'text/plain'));
      $rows['get'] = $compare['ok']
        ? $this->row('supported', 'GetObject 与 SHA-256 一致。')
        : $this->row('failed', 'GetObject 返回的内容没有通过 SHA-256 或大小校验。');
    }
    catch (\Throwable $error) {
      $rows['get'] = $this->classify($error, 'GetObject');
    }
    try {
      $client->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => BootGuard::TEST_PREFIX,
        'MaxKeys' => 5,
      ]);
      $rows['list'] = $this->row('supported', '只列出了测试前缀，MaxKeys 为 5。');
    }
    catch (\Throwable $error) {
      $rows['list'] = $this->classify($error, 'ListObjectsV2');
    }
    try {
      $client->copyObject([
        'Bucket' => $bucket,
        'Key' => $copy,
        'CopySource' => $bucket . '/' . $key,
      ]);
      $rows['copy'] = $this->row('supported', 'CopyObject 在测试前缀内成功。');
    }
    catch (\Throwable $error) {
      $rows['copy'] = $this->classify($error, 'CopyObject');
    }
    $rows['presign'] = $this->presign($client, $bucket, $key, $body, (string) ($connection['endpoint'] ?? ''));
    try {
      $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
      $rows['delete'] = $client->doesObjectExistV2($bucket, $key)
        ? $this->row('partial', 'DeleteObject 已调用，但对象仍然存在。')
        : $this->row('supported', 'DeleteObject 后对象已不存在。');
    }
    catch (\Throwable $error) {
      $rows['delete'] = $this->classify($error, 'DeleteObject');
    }
    return $rows;
  }

  /**
   * @return array<string, string>
   *   Presign row.
   */
  private function presign(object $client, string $bucket, string $key, string $body, string $endpoint): array {
    try {
      $command = $client->getCommand('GetObject', [
        'Bucket' => $bucket,
        'Key' => $key,
      ]);
      $url = (string) $client->createPresignedRequest($command, '+60 seconds')->getUri();
    }
    catch (\Throwable $error) {
      return $this->classify($error, '预签名');
    }
    $host = parse_url($url, PHP_URL_HOST);
    $endpointHost = $endpoint === '' ? '' : parse_url($endpoint, PHP_URL_HOST);
    $allowed = is_string($host) && (
      ($endpointHost !== '' && ($host === $endpointHost || str_ends_with($host, '.' . $endpointHost)))
      || ($endpoint === '' && ($host === 's3.amazonaws.com' || str_ends_with($host, '.amazonaws.com')))
    );
    if (!$allowed || !str_contains($url, 'X-Amz-Signature=')) {
      return $this->row('failed', '预签名地址的主机不在允许范围内，已拒绝请求。');
    }
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'connect_timeout' => 5,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
      ]);
      $downloaded = (string) $response->getBody();
      $ok = $response->getStatusCode() === 200 && hash_equals(hash('sha256', $body), hash('sha256', $downloaded));
      return $ok
        ? $this->row('supported', '60 秒预签名地址返回了原始内容。地址没有写入日志。')
        : $this->row('partial', '已生成预签名地址，但读取状态为 ' . $response->getStatusCode() . '。');
    }
    catch (\Throwable $error) {
      return $this->row('partial', '已生成预签名地址，读取失败：' . BootGuard::safeError($error));
    }
  }

  /**
   * @return array<string, string>
   *   Multipart row.
   */
  private function multipart(object $client, string $bucket, string $key): array {
    $uploadId = '';
    try {
      $created = $client->createMultipartUpload([
        'Bucket' => $bucket,
        'Key' => $key,
        'ContentType' => 'text/plain',
      ]);
      $uploadId = (string) ($created['UploadId'] ?? '');
      if ($uploadId === '') {
        return $this->row('failed', '分片上传没有返回 UploadId。');
      }
      $client->abortMultipartUpload([
        'Bucket' => $bucket,
        'Key' => $key,
        'UploadId' => $uploadId,
      ]);
      return $this->row('partial', 'CreateMultipartUpload 和 AbortMultipartUpload 成功。S3 File System 3.12 的普通写入仍使用单次 PutObject，媒体上传流程没有改为分片。');
    }
    catch (\Throwable $error) {
      if ($uploadId !== '') {
        try {
          $client->abortMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
          ]);
        }
        catch (\Throwable) {
        }
      }
      return $this->classify($error, '分片上传');
    }
  }

  /**
   * @return array<string, string>
   *   Optional API row.
   */
  private function optionalApi(object $client, string $method, string $bucket, string $emptyCode): array {
    try {
      $client->{$method}(['Bucket' => $bucket]);
      return $this->row('supported', 'API 有响应。这不表示对应规则已经启用，也不表示可以自动修改供应商配置。');
    }
    catch (\Throwable $error) {
      $code = $error instanceof \Aws\S3\Exception\S3Exception ? (string) $error->getAwsErrorCode() : '';
      if ($code === $emptyCode) {
        return $this->row('supported', 'API 可用，当前没有启用这项配置。');
      }
      return $this->classify($error, $method);
    }
  }

  /**
   * @return array<string, string>
   *   Classified error row.
   */
  private function classify(\Throwable $error, string $action): array {
    $code = $error instanceof \Aws\S3\Exception\S3Exception ? (string) $error->getAwsErrorCode() : '';
    $message = $action . '：' . BootGuard::safeError($error);
    if (in_array($code, ['NotImplemented', 'NotSupported', 'MethodNotAllowed', 'UnsupportedOperation'], TRUE)) {
      return $this->row('unsupported', $message);
    }
    if (in_array($code, ['AccessDenied', '403', 'Unauthorized', 'InvalidAccessKeyId', 'SignatureDoesNotMatch'], TRUE)) {
      return $this->row('unverified', $message . ' 权限不足不能当成功能不存在。');
    }
    return $this->row('failed', $message);
  }

  /**
   * @param list<string> $keys
   *   Keys that belong to this probe.
   * @param list<string> $secrets
   *   Secrets to redact.
   */
  private function deleteQuietly(object $client, string $bucket, array $keys, array $secrets): void {
    foreach ($keys as $key) {
      if (BootGuard::testKey($key) !== NULL) {
        continue;
      }
      try {
        $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
      }
      catch (\Throwable $error) {
        $this->logger->error('测试对象清理失败：@message', ['@message' => BootGuard::safeError($error, $secrets)]);
      }
    }
  }

}
