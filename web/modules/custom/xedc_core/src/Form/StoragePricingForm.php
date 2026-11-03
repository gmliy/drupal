<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stores unit prices used only for estimates.
 */
class StoragePricingForm extends FormBase {

  public function __construct(
    protected StorageConfig $storageConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.storage_config'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_storage_pricing';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $pricing = $this->storageConfig->data()['pricing'];
    $form['currency'] = [
      '#type' => 'textfield',
      '#title' => '货币',
      '#default_value' => (string) ($pricing['currency'] ?? 'USD'),
      '#size' => 6,
    ];
    foreach ([
      'storage_price_per_gb_month' => '每 GB 每月存储单价',
      'download_price_per_gb' => '每 GB 下载单价',
      'request_price_per_thousand' => '每千次请求单价',
      'free_storage_gb' => '免费存储 GB',
      'free_download_gb' => '免费下载 GB',
      'other_monthly' => '其他每月费用',
    ] as $key => $label) {
      $form[$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => (float) ($pricing[$key] ?? 0),
        '#min' => 0,
        '#step' => 0.000001,
      ];
    }
    $form['free_requests'] = [
      '#type' => 'number',
      '#title' => '免费请求次数',
      '#default_value' => (int) ($pricing['free_requests'] ?? 0),
      '#min' => 0,
      '#step' => 1,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => '保存单价'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    if (!$account->hasPermission('administer xedc object storage') && !$account->hasPermission('manage xedc storage policy')) {
      $this->messenger()->addError('没有管理存储策略的权限。');
      return;
    }
    $values = [];
    foreach ([
      'currency',
      'storage_price_per_gb_month',
      'download_price_per_gb',
      'request_price_per_thousand',
      'free_storage_gb',
      'free_download_gb',
      'free_requests',
      'other_monthly',
    ] as $key) {
      $values[$key] = $form_state->getValue($key);
    }
    $errors = $this->storageConfig->savePricing($values);
    if ($errors !== []) {
      $this->messenger()->addError(implode(' ', $errors));
      return;
    }
    $this->messenger()->addStatus('单价已保存。估算结果仍然不是供应商账单。');
  }

}
