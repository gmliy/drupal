<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stores intended public and private placement. It does not move files.
 */
class StoragePolicyForm extends FormBase {

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
    return 'xedc_storage_policy';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $policy = $this->storageConfig->data()['policy'];
    $options = [
      'public' => '未来放入公共对象存储',
      'private' => '未来放入私有对象存储',
      'inherit' => '保持字段当前方案',
      'local' => '保留本地',
    ];
    $labels = [
      'cover_image' => '美图封面',
      'thumbnail' => '美图缩略图',
      'original_image' => '美图原图',
      'video_poster' => '视频封面',
      'video_file' => '视频文件',
      'zip_archive' => 'ZIP 原图包',
      'vip_download' => 'VIP 下载',
      'article_attachment' => '文章附件',
      'temporary' => '临时文件',
    ];
    $form['intro'] = [
      '#markup' => '<p class="xedc-ops-note">这里只记录未来策略。保存不会移动文件，也不会把 private:// 改成公开地址。</p>',
    ];
    foreach ($labels as $key => $label) {
      $form[$key] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => $options,
        '#default_value' => (string) ($policy[$key] ?? 'inherit'),
        '#disabled' => $key === 'temporary',
      ];
    }
    $form['presign_ttl'] = [
      '#type' => 'number',
      '#title' => '预签名有效期（秒）',
      '#default_value' => (int) ($this->storageConfig->data()['presign_ttl'] ?? 120),
      '#min' => 30,
      '#max' => 300,
      '#description' => '只用于经过权限检查的受保护下载，最长 300 秒。',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => '保存策略'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $policy = [];
    foreach (array_keys($this->storageConfig->data()['policy']) as $key) {
      $policy[$key] = $key === 'temporary' ? 'local' : (string) $form_state->getValue($key);
    }
    $errors = $this->storageConfig->savePolicy($policy, (int) $form_state->getValue('presign_ttl'));
    if ($errors !== []) {
      $this->messenger()->addError(implode(' ', $errors));
      return;
    }
    $this->messenger()->addStatus('存储策略已保存，现有文件仍在原来的位置。');
  }

}
