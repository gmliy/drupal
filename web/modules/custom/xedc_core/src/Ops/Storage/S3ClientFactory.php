<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Aws\S3\S3Client;

/**
 * Builds a short-lived S3 client for tests. It does not change Drupal's stream wrappers.
 */
final class S3ClientFactory {

  /**
   * @param array<string, mixed> $connection
   *   Bucket, region, endpoint and credentials.
   *
   * @throws \RuntimeException
   *   The destination or client library is not usable.
   */
  public static function create(array $connection): S3Client {
    if (!class_exists(S3Client::class)) {
      throw new \RuntimeException('没有加载 AWS SDK，不能建立对象存储连接。');
    }
    $bucket = (string) ($connection['bucket'] ?? '');
    $region = strtolower((string) ($connection['region'] ?? ''));
    $endpoint = (string) ($connection['endpoint'] ?? '');
    $access = (string) ($connection['access_key'] ?? '');
    $secret = (string) ($connection['secret_key'] ?? '');
    if (BootGuard::bucketError($bucket) !== NULL || BootGuard::regionError($region) !== NULL) {
      throw new \RuntimeException('Bucket 或 Region 无效。');
    }
    if ($access === '' || $secret === '') {
      throw new \RuntimeException('缺少存储凭证。');
    }
    if ($endpoint !== '') {
      $destination = BootGuard::destinationError($endpoint);
      if ($destination !== NULL) {
        throw new \RuntimeException($destination);
      }
    }
    $client = [
      'version' => '2006-03-01',
      'region' => $region,
      'credentials' => [
        'key' => $access,
        'secret' => $secret,
      ],
      'use_path_style_endpoint' => !empty($connection['path_style']),
      'use_aws_shared_config_files' => FALSE,
      'http' => [
        'connect_timeout' => 5,
        'timeout' => 20,
      ],
      'retries' => [
        'mode' => 'standard',
        'max_attempts' => 3,
      ],
    ];
    if (isset($connection['timeout']) && is_numeric($connection['timeout'])) {
      $timeout = (int) $connection['timeout'];
      if ($timeout >= 5 && $timeout <= 300) {
        $client['http']['timeout'] = $timeout;
      }
    }
    if (isset($connection['sdk_attempts']) && is_numeric($connection['sdk_attempts'])) {
      $attempts = (int) $connection['sdk_attempts'];
      if ($attempts >= 1 && $attempts <= 5) {
        $client['retries']['max_attempts'] = $attempts;
      }
    }
    if ($endpoint !== '') {
      $client['endpoint'] = $endpoint;
    }
    return new S3Client($client);
  }

}
