<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Storage\ConnectionTester;
use Drupal\xedc_core\Ops\Storage\CredentialVault;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Saves per-profile credentials without sending stored secrets back.
 */
class StorageCredentialForm extends FormBase {

  public function __construct(
    protected StorageConfig $storageConfig,
    protected CredentialVault $credentials,
    protected ConnectionTester $tester,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.storage_config'),
      $container->get('xedc_core.storage_credentials'),
      $container->get('xedc_core.storage_tester'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_storage_credentials_' . $this->profileId(new \Drupal\Core\Form\FormState());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $id = $this->profileId($form_state);
    $profile = $id !== '' ? $this->storageConfig->profile($id) : NULL;
    $form['#attributes']['class'][] = 'xedc-ops-panel';
    $form['#attributes']['autocomplete'] = 'off';
    $form['#attached']['library'][] = 'xedc_core/storage_credentials';
    if ($profile === NULL) {
      $form['missing'] = ['#markup' => '<p>供应商配置不存在，还不能保存 API 凭证。</p>'];
      return $form;
    }
    $status = $this->credentials->describe($profile);
    $form['notice'] = [
      '#markup' => '<p class="xedc-ops-note">密钥通过 Drupal Key 模块的 File Provider 保存为 Web 根目录外的明文文件，权限 0600。这不是加密存储。配置导出只保存 Key 引用和文件路径。环境变量方式仍然有效；这份配置已有后台凭证时，连接测试和运行时都优先使用它，不会改用其他供应商的密钥。保存凭证不会开启对象存储接管，也不会迁移文件。</p>',
    ];
    $form['status'] = [
      '#markup' => '<p><span class="xedc-ops-badge is-' . $this->tone((string) $status['status']) . '">' . $status['label'] . '</span> ' . $status['source_label'] . '</p>',
    ];
    if (empty($status['provider_ready'])) {
      $form['provider'] = [
        '#markup' => '<p class="xedc-ops-note">Key Provider 不可用。现在不能在后台保存密钥。</p>',
      ];
    }
    if (empty($status['permissions_ok'])) {
      $form['permissions'] = [
        '#markup' => '<p class="xedc-ops-note">凭证文件权限宽于 0600。请收紧私有目录中的密钥文件。</p>',
      ];
    }
    $form['access_key'] = [
      '#type' => 'textfield',
      '#title' => 'Access Key ID',
      '#default_value' => '',
      '#maxlength' => 256,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
      '#description' => $status['access_hint'] !== ''
        ? '已配置，页面只显示 ' . $status['access_hint'] . '。留空表示保持原值。'
        : '留空表示不修改。新增时需要和 Secret Access Key 一起填写。',
    ];
    $form['secret_key'] = [
      '#type' => 'password',
      '#title' => 'Secret Access Key',
      '#maxlength' => 256,
      '#attributes' => [
        'id' => 'xedc-storage-secret-key',
        'autocomplete' => 'new-password',
        'spellcheck' => 'false',
      ],
      '#description' => !empty($status['secret_configured'])
        ? '已配置。页面不会回填 Secret Access Key，修改时需要重新输入。留空表示保持原值。'
        : '不会从服务器回填。',
    ];
    $form['toggle_secret'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => '显示输入',
      '#attributes' => [
        'type' => 'button',
        'class' => ['xedc-ops-button'],
        'data-xedc-toggle-secret' => 'xedc-storage-secret-key',
      ],
    ];
    $form['confirm_clear'] = [
      '#type' => 'checkbox',
      '#title' => '确认清除这份配置的后台凭证',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#name' => 'save_credentials',
      '#value' => !empty($status['secret_configured']) ? '更新凭证' : '保存凭证',
      '#attributes' => ['class' => ['xedc-ops-button']],
    ];
    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#name' => 'clear_credentials',
      '#value' => '清除凭证',
      '#attributes' => ['class' => ['xedc-ops-button', 'xedc-ops-danger']],
    ];
    $form['actions']['test'] = [
      '#type' => 'submit',
      '#name' => 'test_credentials',
      '#value' => '测试连接',
      '#attributes' => ['class' => ['xedc-ops-button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = $this->profileId($form_state);
    $access = trim((string) $form_state->getValue('access_key'));
    $secret = trim((string) $form_state->getValue('secret_key'));
    $confirmed = (bool) $form_state->getValue('confirm_clear');
    $action = (string) ($form_state->getTriggeringElement()['#name'] ?? 'save_credentials');
    $this->wipe($form_state);
    if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id)) {
      $this->messenger()->addError('供应商配置无效。');
      return;
    }
    if ($action === 'clear_credentials') {
      if (!$confirmed) {
        $this->messenger()->addError('清除凭证前需要勾选确认。环境变量不会被删除。');
        return;
      }
      $error = $this->credentials->clear($id);
      if ($error !== NULL) {
        $this->messenger()->addError($error);
        return;
      }
      $this->messenger()->addStatus('已清除这份配置的后台凭证。文件位置和运行时开关都没有改变。');
      return;
    }
    if ($action === 'test_credentials') {
      $result = $this->tester->test($id);
      $message = (string) ($result['message'] ?? '测试失败。');
      if (in_array(($result['status'] ?? ''), ['passed', 'partial', 'pending_credentials', 'incomplete'], TRUE)) {
        $this->messenger()->addStatus($message);
      }
      else {
        $this->messenger()->addError($message);
      }
      return;
    }
    $errors = $this->credentials->save($id, $access, $secret);
    if ($errors !== []) {
      $this->messenger()->addError(implode(' ', $errors));
      return;
    }
    $this->messenger()->addStatus('凭证已保存。Secret Access Key 不会在页面上显示。对象存储接管仍然关闭。');
  }

  private function profileId(FormStateInterface $form_state): string {
    $route = (string) ($this->getRouteMatch()->getParameter('profile') ?: '');
    if ($route !== '') {
      return $route;
    }
    return (string) $form_state->get('profile_id');
  }

  private function wipe(FormStateInterface $form_state): void {
    $form_state->setValue('access_key', '');
    $form_state->setValue('secret_key', '');
    $input = $form_state->getUserInput();
    unset($input['access_key'], $input['secret_key']);
    $form_state->setUserInput($input);
  }

  private function tone(string $status): string {
    return match ($status) {
      'configured', 'env' => 'ok',
      'incomplete', 'provider_unavailable' => 'warn',
      default => 'muted',
    };
  }

}
