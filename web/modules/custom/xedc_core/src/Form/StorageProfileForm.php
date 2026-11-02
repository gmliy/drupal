<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\Ops\Storage\CredentialVault;
use Drupal\xedc_core\Ops\Storage\ProviderCatalog;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits one non-secret provider profile.
 */
class StorageProfileForm extends FormBase {

  public function __construct(
    protected StorageConfig $storageConfig,
    protected CredentialVault $credentials,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.storage_config'),
      $container->get('xedc_core.storage_credentials'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    $mode = (string) ($this->getRouteMatch()->getParameter('mode') ?: 'add');
    $profile = (string) ($this->getRouteMatch()->getParameter('profile') ?: 'new');
    return 'xedc_storage_profile_' . $mode . '_' . $profile;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $mode = (string) ($this->getRouteMatch()->getParameter('mode') ?: 'add');
    $id = (string) ($this->getRouteMatch()->getParameter('profile') ?: '');
    $existing = $mode === 'edit' ? $this->storageConfig->profile($id) : NULL;
    if ($mode === 'edit' && $existing === NULL) {
      $form['missing'] = ['#markup' => '<p>供应商配置不存在。</p>'];
      return $form;
    }
    $provider = (string) ($existing['provider'] ?? $this->getRequest()->query->get('provider', 'custom'));
    if (ProviderCatalog::get($provider) === NULL) {
      $provider = 'custom';
    }
    $catalog = ProviderCatalog::get($provider);
    $options = [];
    foreach (ProviderCatalog::all() as $key => $item) {
      $options[$key] = $item['label'];
    }
    $form['#attributes']['class'][] = 'xedc-ops-panel';
    $form['id'] = [
      '#type' => 'textfield',
      '#title' => '配置 ID',
      '#default_value' => $existing['id'] ?? '',
      '#required' => TRUE,
      '#disabled' => $mode === 'edit',
      '#description' => '小写字母、数字和下划线。保存后不能修改。',
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => '显示名称',
      '#default_value' => $existing['label'] ?? ($catalog['label'] ?? ''),
      '#required' => TRUE,
    ];
    $form['provider'] = [
      '#type' => 'select',
      '#title' => '供应商类型',
      '#options' => $options,
      '#default_value' => $provider,
      '#description' => (string) ($catalog['note'] ?? ''),
    ];
    $form['region'] = [
      '#type' => 'textfield',
      '#title' => 'Region',
      '#default_value' => $existing['region'] ?? ($catalog['region_hint'] ?? ''),
      '#required' => TRUE,
      '#description' => '按该供应商文档填写。R2 常用 auto，不能据此假设其他供应商也使用 auto。',
    ];
    $form['endpoint'] = [
      '#type' => 'textfield',
      '#title' => 'API Endpoint',
      '#default_value' => $existing['endpoint'] ?? '',
      '#placeholder' => (string) ($catalog['endpoint_hint'] ?? ''),
      '#description' => '这是 S3 API 地址，不是图片或视频的公开域名。Amazon S3 可以留空。',
    ];
    $form['bucket'] = [
      '#type' => 'textfield',
      '#title' => 'Bucket',
      '#default_value' => $existing['bucket'] ?? '',
      '#description' => '可以留空，改由下方的 Bucket 环境变量提供。',
    ];
    $form['path_style'] = [
      '#type' => 'checkbox',
      '#title' => '使用 Path-style',
      '#default_value' => $existing['path_style'] ?? !empty($catalog['path_style']),
    ];
    $form['public_base_url'] = [
      '#type' => 'textfield',
      '#title' => '公开访问域名',
      '#default_value' => $existing['public_base_url'] ?? '',
      '#description' => 'HTTPS 公开域名。保存这里不会替换现有文件 URL。',
    ];
    $form['cdn_base_url'] = [
      '#type' => 'textfield',
      '#title' => 'CDN 域名',
      '#default_value' => $existing['cdn_base_url'] ?? '',
      '#description' => '与 API Endpoint 分开保存。运行时只有 STORAGE_CDN_ENABLED=1 才会交给 S3 File System。',
    ];
    $form['public_folder'] = [
      '#type' => 'textfield',
      '#title' => '公共文件夹',
      '#default_value' => $existing['public_folder'] ?? 's3fs-public',
      '#required' => TRUE,
    ];
    $form['private_folder'] = [
      '#type' => 'textfield',
      '#title' => '私有文件夹',
      '#default_value' => $existing['private_folder'] ?? 's3fs-private',
      '#required' => TRUE,
    ];
    $form['root_folder'] = [
      '#type' => 'textfield',
      '#title' => '根文件夹',
      '#default_value' => $existing['root_folder'] ?? '',
    ];
    $form['cache_control'] = [
      '#type' => 'textfield',
      '#title' => '对象 Cache-Control',
      '#default_value' => $existing['cache_control'] ?? 'public, max-age=31536000, immutable',
      '#description' => '版本化对象键才适合一年 immutable。现有生产文件不会因为保存这里而被改名或改写缓存头。',
    ];
    $form['disable_version_sync'] = [
      '#type' => 'checkbox',
      '#title' => '不使用 ListObjectVersions',
      '#default_value' => $existing['disable_version_sync'] ?? !empty($catalog['disable_version_sync']),
      '#description' => '部分兼容平台没有版本列表。关闭它不会删除已有版本。',
    ];
    $form['upload_as_private'] = [
      '#type' => 'checkbox',
      '#title' => '上传时不设置 public-read ACL',
      '#default_value' => $existing['upload_as_private'] ?? TRUE,
      '#description' => 'Cloudflare R2 和禁用 ACL 的 Bucket 需要这个选项。公开访问改由 Bucket 策略或 CDN 控制。',
    ];
    $global = ($existing['access_key_env'] ?? 'STORAGE_ACCESS_KEY') === 'STORAGE_ACCESS_KEY';
    $form['use_global'] = [
      '#type' => 'checkbox',
      '#title' => '凭证使用全局环境变量 STORAGE_ACCESS_KEY、STORAGE_SECRET_KEY 和 STORAGE_BUCKET',
      '#default_value' => $existing ? $global : $this->storageConfig->profiles() === [],
    ];
    $suffix = strtoupper((string) ($existing['id'] ?? 'PROFILE'));
    $form['access_key_env'] = [
      '#type' => 'textfield',
      '#title' => 'Access Key 环境变量名',
      '#default_value' => $existing['access_key_env'] ?? 'STORAGE_PROFILE_' . $suffix . '_ACCESS_KEY',
      '#description' => '只填写变量名。不要在这里填写密钥。',
    ];
    $form['secret_key_env'] = [
      '#type' => 'textfield',
      '#title' => 'Secret Key 环境变量名',
      '#default_value' => $existing['secret_key_env'] ?? 'STORAGE_PROFILE_' . $suffix . '_SECRET_KEY',
    ];
    $form['bucket_env'] = [
      '#type' => 'textfield',
      '#title' => 'Bucket 环境变量名',
      '#default_value' => $existing['bucket_env'] ?? 'STORAGE_PROFILE_' . $suffix . '_BUCKET',
    ];
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => '备注',
      '#default_value' => $existing['notes'] ?? '',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => '保存配置',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => '返回',
      '#url' => Url::fromRoute('xedc_core.storage_providers'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mode = (string) ($this->getRouteMatch()->getParameter('mode') ?: 'add');
    $values = $form_state->getValues();
    if ($mode === 'edit') {
      $values['id'] = (string) $this->getRouteMatch()->getParameter('profile');
    }
    if (!empty($values['use_global'])) {
      $values['access_key_env'] = 'STORAGE_ACCESS_KEY';
      $values['secret_key_env'] = 'STORAGE_SECRET_KEY';
      $values['bucket_env'] = 'STORAGE_BUCKET';
    }
    $values['path_style'] = !empty($values['path_style']);
    $values['disable_version_sync'] = !empty($values['disable_version_sync']);
    $values['upload_as_private'] = !empty($values['upload_as_private']);
    $errors = $this->storageConfig->saveProfile($values, $mode !== 'edit');
    if ($errors !== []) {
      $this->messenger()->addError(implode(' ', $errors));
      return;
    }
    $savedId = $mode === 'edit' ? (string) $this->getRouteMatch()->getParameter('profile') : strtolower(trim((string) ($values['id'] ?? '')));
    $this->credentials->writeMeta($savedId);
    $this->messenger()->addStatus('供应商配置已保存。文件位置和运行时连接都没有改变。');
    $form_state->setRedirect('xedc_core.storage_providers');
  }

}
