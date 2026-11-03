<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage\Provider;

use Drupal\xedc_core\Ops\Storage\CredentialVault;
use Drupal\xedc_core\Ops\Storage\ProviderCatalog;
use Drupal\xedc_core\Ops\Storage\RemoteFileReader;
use Drupal\xedc_core\Ops\Storage\StorageConfig;

/**
 * Registers operational providers and keeps unimplemented vendors separate.
 */
final class StorageProviderRegistry {

  public function __construct(
    private StorageConfig $config,
    private CredentialVault $credentials,
    private RemoteFileReader $reader,
  ) {}

  /**
   * @return list<StorageProviderInterface>
   *   Local storage plus saved Cloudflare R2 profiles.
   */
  public function operational(): array {
    $providers = [new LocalStorageProvider()];
    foreach ($this->config->profiles() as $profile) {
      if (($profile['provider'] ?? '') === 'cloudflare_r2') {
        $providers[] = new CloudflareR2Provider($profile, $this->credentials, $this->reader);
      }
    }
    return $providers;
  }

  public function get(string $id): ?StorageProviderInterface {
    foreach ($this->operational() as $provider) {
      if ($provider->getId() === $id) {
        return $provider;
      }
    }
    return NULL;
  }

  /**
   * @return list<array<string, string>>
   *   Catalog entries that are not operational adapters.
   */
  public function reserved(): array {
    $rows = [];
    foreach (ProviderCatalog::all() as $id => $item) {
      if ($id === 'cloudflare_r2') {
        $rows[] = [
          'id' => $id,
          'label' => (string) $item['label'],
          'status' => '适配器已实现',
          'detail' => '当前生产连接使用已保存的 R2 配置。这份目录只是新建配置的模板。',
        ];
        continue;
      }
      $rows[] = [
        'id' => $id,
        'label' => (string) $item['label'],
        'status' => '未实现',
        'detail' => '只有配置模板。不能连接，也不能读写。',
      ];
    }
    return $rows;
  }

  /**
   * @return array<string, mixed>
   *   Safe card for one operational provider.
   */
  public function card(StorageProviderInterface $provider, bool $probeHealth): array {
    $health = $probeHealth ? $provider->healthReadOnly() : ['status' => '未检查', 'detail' => '打开健康检查页才会做只读检查。'];
    return [
      'id' => $provider->getId(),
      'label' => $provider->getLabel(),
      'type' => $provider->getType(),
      'operational' => $provider->isOperational(),
      'capabilities' => $provider->capabilities(),
      'summary' => $provider->summary(),
      'health' => $health,
    ];
  }

}
