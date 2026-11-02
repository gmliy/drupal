<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\xedc_ai\Form\ModelAssignForm;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Provider\ProviderRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Model list and default-model form.
 */
final class ModelController extends ControllerBase {

  public function __construct(
    private readonly ModelRepository $models,
    private readonly ProviderRepository $providers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.providers'),
    );
  }

  /**
   * Model list.
   */
  public function collection(): array {
    $names = [];
    foreach ($this->providers->all() as $provider) {
      $names[$provider['id']] = $provider['label'];
    }
    $default = $this->models->defaultId();
    $fallback = $this->models->fallbackId();
    $rows = [];
    foreach ($this->models->all() as $model) {
      $price = ((string) $model['input_price'] !== '' && (string) $model['output_price'] !== '')
        ? $model['input_price'] . ' / ' . $model['output_price']
        : '未核算';
      $rows[] = [
        'id' => (int) $model['id'],
        'label' => (string) $model['label'],
        'name' => (string) $model['model_name'],
        'provider' => $names[$model['provider_id']] ?? (string) $model['provider_id'],
        'enabled' => (int) $model['enabled'] === 1,
        'price' => $price,
        'capabilities' => Labels::capabilityList((string) ($model['capabilities'] ?? '')),
        'default' => (int) $model['id'] === $default,
        'fallback' => (int) $model['id'] === $fallback,
        'edit' => Url::fromRoute('xedc_ai.model_edit', ['model' => $model['id']])->toString(),
        'delete' => Url::fromRoute('xedc_ai.model_delete', ['model' => $model['id']])->toString(),
      ];
    }
    $defaultRow = $default > 0 ? $this->models->load($default) : NULL;
    $fallbackRow = $fallback > 0 ? $this->models->load($fallback) : NULL;
    return [
      '#theme' => 'xedc_ai_models',
      '#rows' => $rows,
      '#default_model' => $defaultRow ? $defaultRow['label'] . ' / ' . $defaultRow['model_name'] : '未设置',
      '#fallback_model' => $fallbackRow ? $fallbackRow['label'] . ' / ' . $fallbackRow['model_name'] : '未设置',
      '#assign' => $this->formBuilder()->getForm(ModelAssignForm::class),
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
