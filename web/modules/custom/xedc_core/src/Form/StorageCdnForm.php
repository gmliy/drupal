<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stores CDN intentions without rewriting existing file URLs.
 */
class StorageCdnForm extends FormBase {

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
    return 'xedc_storage_cdn';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $cdn = $this->storageConfig->data()['cdn'];
    $form['intro'] = [
      '#markup' => '<p class="xedc-ops-note">CDN 域名、Bucket 和 API Endpoint 分开保存。这里的地址还没有生效，也不会替换已经发布的图片地址。</p>',
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => '记录为计划启用 CDN',
      '#default_value' => !empty($cdn['enabled']),
      '#description' => '这只改变对象存储中心的记录。运行时还需单独设置 STORAGE_CDN_ENABLED=1。',
    ];
    $form['base_url'] = [
      '#type' => 'textfield',
      '#title' => 'CDN 基础地址',
      '#default_value' => (string) ($cdn['base_url'] ?? ''),
    ];
    $form['image_domain'] = [
      '#type' => 'textfield',
      '#title' => '图片域名',
      '#default_value' => (string) ($cdn['image_domain'] ?? ''),
    ];
    $form['media_domain'] = [
      '#type' => 'textfield',
      '#title' => '视频域名',
      '#default_value' => (string) ($cdn['media_domain'] ?? ''),
    ];
    $form['cache_control'] = [
      '#type' => 'textfield',
      '#title' => '对象 Cache-Control',
      '#default_value' => (string) ($cdn['cache_control'] ?? ''),
      '#description' => '这是版本化对象的建议值。现有生产文件名没有内容哈希，保存本表不会把 immutable 写到那些对象上。',
    ];
    $form['browser_cache'] = [
      '#type' => 'textfield',
      '#title' => '浏览器缓存策略',
      '#default_value' => (string) ($cdn['browser_cache'] ?? ''),
      '#description' => '内容覆盖同一对象键后，需要手动清除对应 CDN URL。未版本化的现有文件不要使用一年 immutable。',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => '保存 CDN 配置'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $errors = $this->storageConfig->saveCdn([
      'enabled' => (bool) $form_state->getValue('enabled'),
      'base_url' => (string) $form_state->getValue('base_url'),
      'image_domain' => (string) $form_state->getValue('image_domain'),
      'media_domain' => (string) $form_state->getValue('media_domain'),
      'cache_control' => (string) $form_state->getValue('cache_control'),
      'browser_cache' => (string) $form_state->getValue('browser_cache'),
    ]);
    if ($errors !== []) {
      $this->messenger()->addError(implode(' ', $errors));
      return;
    }
    $this->messenger()->addStatus('CDN 配置已保存。线上图片域名没有改变。');
  }

}
