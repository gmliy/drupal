<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Storage\MultipartCleaner;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controls test-prefix cleanup. It cannot purge Drupal files.
 */
class StorageSecurityForm extends FormBase {

  public function __construct(
    protected StorageConfig $storageConfig,
    protected MultipartCleaner $cleaner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.storage_config'),
      $container->get('xedc_core.storage_multipart'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_storage_security';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $data = $this->storageConfig->data();
    $form['multipart_cleanup'] = [
      '#type' => 'checkbox',
      '#title' => '在 Cron 中清理测试前缀里超过 24 小时的未完成分片',
      '#default_value' => (int) ($data['multipart_cleanup'] ?? 0) === 1,
      '#description' => '只处理 _xedc_storage_test/。默认关闭。不会删除 Drupal 正在引用的文件。',
    ];
    $options = [];
    foreach ($this->storageConfig->profiles() as $profile) {
      $options[$profile['id']] = $profile['label'];
    }
    $form['profile'] = [
      '#type' => 'select',
      '#title' => '立即清理的供应商配置',
      '#options' => $options,
      '#empty_option' => '选择配置',
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => '确认只清理 _xedc_storage_test/ 中超过 24 小时的未完成分片',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#name' => 'save',
      '#value' => '保存安全设置',
    ];
    $form['actions']['cleanup'] = [
      '#type' => 'submit',
      '#name' => 'cleanup',
      '#value' => '立即清理测试分片',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = (string) ($form_state->getTriggeringElement()['#name'] ?? 'save');
    if ($name === 'save') {
      $this->storageConfig->saveMultipartCleanup((bool) $form_state->getValue('multipart_cleanup'));
      $this->messenger()->addStatus('安全设置已保存。文件隔离策略没有改变。');
      return;
    }
    if (!(bool) $form_state->getValue('confirm')) {
      $this->messenger()->addError('需要先确认清理范围。');
      return;
    }
    $result = $this->cleaner->cleanup((string) $form_state->getValue('profile'));
    if (($result['status'] ?? '') === 'passed') {
      $this->messenger()->addStatus((string) $result['message']);
      return;
    }
    $this->messenger()->addError((string) $result['message']);
  }

}
