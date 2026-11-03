<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage\Provider;

use Drupal\xedc_core\Ops\Storage\BootGuard;
use Drupal\xedc_core\Ops\Storage\CredentialVault;
use Drupal\xedc_core\Ops\Storage\RemoteFileReader;
use Drupal\xedc_core\Ops\Storage\S3ClientFactory;

/**
 * Adapter over the existing R2 client. It does not open a second write path.
 */
final class CloudflareR2Provider implements StorageProviderInterface {

  /**
   * @param array<string, mixed> $profile
   *   Saved non-secret profile.
   */
  public function __construct(
    private array $profile,
    private CredentialVault $credentials,
    private RemoteFileReader $reader,
  ) {}

  public function getId(): string {
    return (string) $this->profile['id'];
  }

  public function getLabel(): string {
    return (string) ($this->profile['label'] ?? $this->getId());
  }

  public function getType(): string {
    return 'cloudflare_r2';
  }

  public function isOperational(): bool {
    $resolved = $this->credentials->resolve($this->profile);
    $ok = !empty($resolved['ok']);
    unset($resolved);
    return $ok;
  }

  public function capabilities(): array {
    return [
      'read' => TRUE,
      'write' => TRUE,
      'delete' => TRUE,
      'list' => TRUE,
      'public_url' => TRUE,
      'private_url' => FALSE,
      'cdn' => TRUE,
      'drupal_stream' => TRUE,
    ];
  }

  public function summary(): array {
    $endpoint = (string) ($this->profile['endpoint'] ?? '');
    $host = $endpoint === '' ? '' : (string) (parse_url($endpoint, PHP_URL_HOST) ?: '');
    $cdn = trim((string) ($this->profile['public_base_url'] ?? ''));
    if ($cdn === '') {
      $cdn = trim((string) ($this->profile['cdn_base_url'] ?? ''));
    }
    return [
      'endpoint' => $host === '' ? '未填写' : $host,
      'bucket' => (string) ($this->profile['bucket'] ?? ''),
      'region' => (string) ($this->profile['region'] ?? ''),
      'cdn' => $cdn === '' ? '未配置' : $cdn,
      'public_prefix' => (string) ($this->profile['public_folder'] ?? 's3fs-public'),
      'private_prefix' => (string) ($this->profile['private_folder'] ?? 's3fs-private'),
    ];
  }

  public function healthReadOnly(): array {
    $resolved = $this->credentials->resolve($this->profile);
    if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
      unset($resolved);
      return ['status' => 'bad', 'detail' => '凭证未能解析。没有连接 Bucket。'];
    }
    $connection = $resolved['connection'];
    $secrets = [];
    foreach (['access_key', 'secret_key'] as $name) {
      if (isset($connection[$name]) && is_string($connection[$name]) && strlen($connection[$name]) >= 8) {
        $secrets[] = $connection[$name];
      }
    }
    unset($resolved);
    try {
      $client = S3ClientFactory::create($connection);
      $client->headBucket(['Bucket' => (string) $connection['bucket']]);
      $folder = trim((string) ($this->profile['public_folder'] ?? 's3fs-public'), '/');
      $sample = $folder . '/2026-10/IMG_3009.jpeg';
      $head = $this->reader->head($connection, $sample);
      unset($connection, $secrets);
      if (!is_array($head)) {
        return ['status' => 'warn', 'detail' => 'HeadBucket 成功。抽样对象不存在，没有写入。'];
      }
      return ['status' => 'ok', 'detail' => 'HeadBucket 成功。抽样对象 ' . (int) ($head['bytes'] ?? 0) . ' 字节。没有写入。'];
    }
    catch (\Throwable $error) {
      $detail = BootGuard::safeError($error, $secrets);
      unset($connection, $secrets);
      return ['status' => 'bad', 'detail' => $detail];
    }
  }

}
