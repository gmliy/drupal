<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\xedc_ai\Provider\ProviderCatalog;
use Drupal\xedc_ai\Provider\ProviderRepository;
use Drupal\xedc_ai\Provider\SecretStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provider list. Secrets are not rendered.
 */
final class ProviderController extends ControllerBase {

  public function __construct(
    private readonly ProviderRepository $providers,
    private readonly SecretStore $secrets,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.providers'),
      $container->get('xedc_ai.secrets'),
    );
  }

  /**
   * Provider list.
   */
  public function collection(): array {
    $rows = [];
    foreach ($this->providers->all() as $provider) {
      $id = (string) $provider['id'];
      $rows[] = [
        'id' => $id,
        'label' => (string) $provider['label'],
        'driver' => ProviderCatalog::presets()[$provider['driver']]['label'] ?? (string) $provider['driver'],
        'base_url' => (string) $provider['base_url'],
        'enabled' => (int) $provider['enabled'] === 1,
        'key' => $this->secrets->source($id) === 'missing' ? '未设置' : ($this->secrets->source($id) === 'environment' ? '环境变量' : '已保存'),
        'hint' => (string) $provider['key_hint'],
        'edit' => Url::fromRoute('xedc_ai.provider_edit', ['provider' => $id])->toString(),
        'delete' => Url::fromRoute('xedc_ai.provider_delete', ['provider' => $id])->toString(),
      ];
    }
    return [
      '#theme' => 'xedc_ai_providers',
      '#rows' => $rows,
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
