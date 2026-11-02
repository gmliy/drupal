<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Resolves a profile to credentials without exposing them to storage or logs.
 */
final class CredentialResolver {

  /**
   * @param array<string, mixed> $profile
   *   Saved profile.
   *
   * @return array<string, mixed>
   *   Presence data and, only in memory, the connection values.
   */
  public static function resolve(array $profile, ?string $access = NULL, ?string $secret = NULL, string $source = ''): array {
    $accessEnv = (string) ($profile['access_key_env'] ?? '');
    $secretEnv = (string) ($profile['secret_key_env'] ?? '');
    $bucketEnv = (string) ($profile['bucket_env'] ?? '');
    $globalNames = $accessEnv === 'STORAGE_ACCESS_KEY' && $secretEnv === 'STORAGE_SECRET_KEY';
    if ($source === '') {
      $access = self::envName($accessEnv) ? BootGuard::env($accessEnv) : NULL;
      $secret = self::envName($secretEnv) ? BootGuard::env($secretEnv) : NULL;
      $source = ($access !== NULL || $secret !== NULL) ? ($globalNames ? 'global_env' : 'profile_env') : 'none';
    }
    $bucket = self::envName($bucketEnv) ? BootGuard::env($bucketEnv) : NULL;
    if ($bucket === NULL && is_string($profile['bucket'] ?? NULL) && $profile['bucket'] !== '') {
      $bucket = (string) $profile['bucket'];
    }
    $region = strtolower((string) ($profile['region'] ?? ''));
    $endpoint = (string) ($profile['endpoint'] ?? '');
    $missing = [];
    if ($source === 'admin') {
      if ($access === NULL) {
        $missing[] = '后台 Access Key ID';
      }
      if ($secret === NULL) {
        $missing[] = '后台 Secret Access Key';
      }
    }
    else {
      if ($access === NULL) {
        $missing[] = $accessEnv !== '' ? $accessEnv : 'access key';
      }
      if ($secret === NULL) {
        $missing[] = $secretEnv !== '' ? $secretEnv : 'secret key';
      }
    }
    if ($bucket === NULL) {
      $missing[] = $bucketEnv !== '' ? $bucketEnv : 'bucket';
    }
    $errors = [];
    if ($bucket !== NULL && BootGuard::bucketError($bucket) !== NULL) {
      $errors[] = 'Bucket 名称无效。';
    }
    if (BootGuard::regionError($region) !== NULL) {
      $errors[] = 'Region 无效。';
    }
    if ($endpoint !== '' && BootGuard::endpointError($endpoint) !== NULL) {
      $errors[] = 'Endpoint 无效。';
    }
    if ((string) ($profile['provider'] ?? '') !== 'aws_s3' && $endpoint === '') {
      $errors[] = '这个供应商需要填写 API Endpoint。';
    }
    return [
      'ok' => $missing === [] && $errors === [],
      'missing' => $missing,
      'errors' => $errors,
      'bucket' => $bucket ?? '',
      'region' => $region,
      'endpoint' => $endpoint,
      'source' => $source,
      'access_present' => $access !== NULL,
      'secret_present' => $secret !== NULL,
      'connection' => [
        'bucket' => $bucket ?? '',
        'region' => $region,
        'endpoint' => $endpoint,
        'path_style' => !empty($profile['path_style']),
        'access_key' => $access ?? '',
        'secret_key' => $secret ?? '',
      ],
    ];
  }

  public static function envName(string $name): bool {
    return (bool) preg_match('/^[A-Z][A-Z0-9_]{0,80}$/', $name);
  }

}
