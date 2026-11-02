<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Storage\CapabilityProbe;
use Drupal\xedc_core\Ops\Storage\ConnectionTester;
use Drupal\xedc_core\Ops\Storage\CredentialVault;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests, selects or deletes a saved provider profile.
 */
class StorageProviderOpsForm extends FormBase {

  public function __construct(
    protected StorageConfig $storageConfig,
    protected ConnectionTester $tester,
    protected CapabilityProbe $probe,
    protected CredentialVault $credentials,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.storage_config'),
      $container->get('xedc_core.storage_tester'),
      $container->get('xedc_core.storage_probe'),
      $container->get('xedc_core.storage_credentials'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_storage_provider_ops';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;
    $profiles = $this->storageConfig->profiles();
    if ($profiles === []) {
      $form['empty'] = ['#markup' => '<p class="xedc-ops-note">还没有保存的供应商配置。</p>'];
      return $form;
    }
    foreach ($profiles as $profile) {
      $id = (string) $profile['id'];
      $form['rows'][$id]['test'] = [
        '#type' => 'submit',
        '#name' => 'test__' . $id,
        '#value' => '测试连接：' . $profile['label'],
        '#attributes' => ['class' => ['xedc-ops-button']],
      ];
      $form['rows'][$id]['probe'] = [
        '#type' => 'submit',
        '#name' => 'probe__' . $id,
        '#value' => '检测能力：' . $profile['label'],
        '#attributes' => ['class' => ['xedc-ops-button']],
      ];
      $form['rows'][$id]['activate'] = [
        '#type' => 'submit',
        '#name' => 'activate__' . $id,
        '#value' => '设为当前配置：' . $profile['label'],
        '#attributes' => ['class' => ['xedc-ops-button']],
      ];
      $form['rows'][$id]['confirm'] = [
        '#type' => 'checkbox',
        '#title' => '确认只删除这条配置',
      ];
      $form['rows'][$id]['delete'] = [
        '#type' => 'submit',
        '#name' => 'delete__' . $id,
        '#value' => '删除配置：' . $profile['label'],
        '#attributes' => ['class' => ['xedc-ops-button', 'xedc-ops-danger']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    [$action, $id] = array_pad(explode('__', $name, 2), 2, '');
    if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id)) {
      $this->messenger()->addError('供应商配置无效。');
      return;
    }
    if ($action === 'test') {
      $result = $this->tester->test($id);
      $this->report($result);
      return;
    }
    if ($action === 'probe') {
      @set_time_limit(60);
      $result = $this->probe->probe($id);
      $this->report($result);
      return;
    }
    if ($action === 'activate') {
      $error = $this->storageConfig->activate($id);
      if ($error) {
        $this->messenger()->addError($error);
        return;
      }
      $this->credentials->markActive($id);
      $this->messenger()->addStatus('已切换当前配置。文件仍在原位置。运行时未启用时，本地存储保持不变；启用后只使用这份配置自己的凭证。');
      return;
    }
    if ($action === 'delete') {
      $confirmed = (bool) ($form_state->getValue(['rows', $id, 'confirm']) ?? FALSE);
      if (!$confirmed) {
        $this->messenger()->addError('删除配置前需要勾选确认。Bucket 里的文件不会被删除。');
        return;
      }
      $error = $this->storageConfig->deleteProfile($id);
      if ($error) {
        $this->messenger()->addError($error);
        return;
      }
      $this->messenger()->addStatus('配置已删除。Bucket 和 Drupal 文件都没有删除。');
    }
  }

  /**
   * @param array<string, mixed> $result
   *   Operation result.
   */
  private function report(array $result): void {
    $message = (string) ($result['message'] ?? '');
    if (in_array(($result['status'] ?? ''), ['passed', 'partial', 'pending_credentials'], TRUE)) {
      $this->messenger()->addStatus($message);
      return;
    }
    $this->messenger()->addError($message);
  }

}
