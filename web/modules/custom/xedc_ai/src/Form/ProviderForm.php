<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Budget\BudgetService;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Client\OpenAiChatClient;
use Drupal\xedc_ai\Provider\ModelProfile;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Provider\ProviderCatalog;
use Drupal\xedc_ai\Provider\ProviderRepository;
use Drupal\xedc_ai\Provider\SecretStore;
use Drupal\xedc_ai\Usage\AuditLog;
use Drupal\xedc_ai\Usage\UsageLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Add or edit one provider. The API key is write-only.
 */
final class ProviderForm extends FormBase {

  public function __construct(
    private readonly ProviderRepository $providers,
    private readonly ModelRepository $models,
    private readonly SecretStore $secrets,
    private readonly OpenAiChatClient $client,
    private readonly BudgetService $budget,
    private readonly UsageLogger $usage,
    private readonly AuditLog $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.providers'),
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.secrets'),
      $container->get('xedc_ai.client'),
      $container->get('xedc_ai.budget'),
      $container->get('xedc_ai.usage'),
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_provider';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $id = (string) $this->getRouteMatch()->getParameter('provider');
    $provider = NULL;
    if ($id !== '') {
      $provider = $this->providers->load($id);
      if ($provider === NULL) {
        throw new NotFoundHttpException();
      }
    }
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['#attached']['library'][] = 'xedc_ai/admin';
    if ($provider === NULL) {
      $form['id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('机器名'),
        '#required' => TRUE,
        '#description' => $this->t('小写字母开头，可包含数字和下划线。保存后不能修改。'),
      ];
    }
    else {
      $form['id'] = ['#type' => 'value', '#value' => $provider['id']];
      $source = $this->secrets->source((string) $provider['id']);
      $hint = (string) $provider['key_hint'];
      $form['key_status'] = [
        '#markup' => '<p class="xedc-ai-note">' . $this->t('密钥状态：@source。页面不显示完整密钥。@hint', [
          '@source' => $source === 'environment' ? '环境变量' : ($source === 'file' ? '已保存' : '未设置'),
          '@hint' => $hint !== '' ? '文件末四位 ' . $hint : '',
        ]) . '</p>',
      ];
    }
    $driver = (string) ($provider['driver'] ?? 'deepseek');
    $form['driver'] = [
      '#type' => 'select',
      '#title' => $this->t('供应商类型'),
      '#options' => ProviderCatalog::options(),
      '#default_value' => $driver,
      '#required' => TRUE,
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('名称'),
      '#required' => TRUE,
      '#default_value' => $provider['label'] ?? '',
    ];
    $form['base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Base URL'),
      '#required' => TRUE,
      '#default_value' => $provider['base_url'] ?? ProviderCatalog::presets()[$driver]['base_url'],
      '#description' => $this->t('使用 OpenAI Compatible 的 /chat/completions。只接受 HTTPS，本机测试可以填 http://127.0.0.1。'),
    ];
    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#maxlength' => 512,
      '#description' => $this->t('留空表示不修改已保存的密钥。密钥写入私有文件，不会进入配置同步。'),
    ];
    $form['clear_key'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('清除已保存的密钥'),
      '#access' => $provider !== NULL,
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('超时（秒）'),
      '#required' => TRUE,
      '#min' => 5,
      '#max' => 120,
      '#default_value' => $provider['timeout'] ?? 30,
    ];
    $form['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('最大输出 Token'),
      '#required' => TRUE,
      '#min' => 32,
      '#max' => 8192,
      '#default_value' => $provider['max_tokens'] ?? 4096,
    ];
    $form['temperature'] = [
      '#type' => 'textfield',
      '#title' => $this->t('温度'),
      '#required' => TRUE,
      '#default_value' => $provider['temperature'] ?? '0.2',
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用'),
      '#default_value' => $provider === NULL ? 1 : (int) $provider['enabled'],
    ];
    $form['test_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('测试用模型名称'),
      '#description' => $this->t('只在点击测试时使用，不会写进业务代码。建议：@name', [
        '@name' => ProviderCatalog::presets()[$driver]['suggested_model'] !== '' ? ProviderCatalog::presets()[$driver]['suggested_model'] : $this->t('由供应商控制台提供'),
      ]),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('保存'), '#button_type' => 'primary'];
    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('测试连接'),
      '#submit' => ['::testConnection'],
      '#access' => $provider !== NULL,
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('返回'),
      '#url' => Url::fromRoute('xedc_ai.providers'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $id = (string) $form_state->getValue('id');
    $existing = $this->getRouteMatch()->getParameter('provider');
    if ($existing === NULL && !ProviderCatalog::validId($id)) {
      $form_state->setErrorByName('id', $this->t('机器名格式不正确。'));
    }
    if ($existing === NULL && $this->providers->load($id) !== NULL) {
      $form_state->setErrorByName('id', $this->t('这个机器名已经存在。'));
    }
    if (!ProviderCatalog::validDriver((string) $form_state->getValue('driver'))) {
      $form_state->setErrorByName('driver', $this->t('不支持这个供应商类型。'));
    }
    if (!ProviderCatalog::validBaseUrl((string) $form_state->getValue('base_url'))) {
      $form_state->setErrorByName('base_url', $this->t('地址必须是 HTTPS，或本机的 http://127.0.0.1 / localhost。'));
    }
    $temperature = trim((string) $form_state->getValue('temperature'));
    if (!preg_match('/^\d(\.\d{1,2})?$/', $temperature) || (float) $temperature > 2) {
      $form_state->setErrorByName('temperature', $this->t('温度需在 0 到 2 之间。'));
    }
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#submit'][0] ?? '') === '::testConnection' && trim((string) $form_state->getValue('test_model')) === '') {
      $form_state->setErrorByName('test_model', $this->t('测试连接需要填写模型名称。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = (string) $form_state->getValue('id');
    $existing = $this->providers->load($id);
    $hint = $existing['key_hint'] ?? '';
    $secret = trim((string) $form_state->getValue('api_key'));
    if ((int) $form_state->getValue('clear_key') === 1) {
      $this->secrets->delete($id);
      $hint = '';
      $this->audit->log('key_clear', '清除了供应商 ' . $id . ' 的密钥。');
    }
    elseif ($secret !== '') {
      if (!$this->secrets->write($id, $secret)) {
        $reason = $this->secrets->failure();
        $this->messenger()->addError($reason !== '' ? $reason : (string) $this->t('密钥没有保存。请确认私有文件目录和 Key 模块可用。'));
        return;
      }
      $hint = $this->secrets->hint($id);
      $this->audit->log('key_set', '更新了供应商 ' . $id . ' 的密钥。');
    }
    $this->providers->save([
      'id' => $id,
      'label' => trim((string) $form_state->getValue('label')),
      'driver' => (string) $form_state->getValue('driver'),
      'base_url' => rtrim(trim((string) $form_state->getValue('base_url')), '/'),
      'enabled' => (int) $form_state->getValue('enabled'),
      'timeout' => (int) $form_state->getValue('timeout'),
      'max_tokens' => (int) $form_state->getValue('max_tokens'),
      'temperature' => trim((string) $form_state->getValue('temperature')),
      'key_hint' => $hint,
    ]);
    $this->audit->log('provider_save', '保存了供应商 ' . $id . '。');
    $this->messenger()->addStatus($this->t('供应商已保存。'));
    $form_state->setRedirect('xedc_ai.provider_edit', ['provider' => $id]);
  }

  /**
   * Sends one short text request. The key is not shown in the result.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $id = (string) $form_state->getValue('id');
    $provider = $this->providers->load($id);
    if ($provider === NULL) {
      $this->messenger()->addError($this->t('请先保存供应商。'));
      return;
    }
    $secret = trim((string) $form_state->getValue('api_key'));
    if ($secret === '') {
      $secret = $this->secrets->read($id);
    }
    if ($secret === '') {
      $this->messenger()->addError($this->t('没有可用的 API Key。'));
      return;
    }
    $modelName = trim((string) $form_state->getValue('test_model'));
    $inputPrice = '';
    $outputPrice = '';
    foreach ($this->models->all() as $row) {
      if ((string) $row['provider_id'] === $id && (string) $row['model_name'] === $modelName && (int) $row['enabled'] === 1) {
        $inputPrice = trim((string) $row['input_price']);
        $outputPrice = trim((string) $row['output_price']);
        break;
      }
    }
    $profile = new ModelProfile(
      0,
      $id,
      (string) $form_state->getValue('driver'),
      (string) $form_state->getValue('label'),
      $modelName,
      rtrim(trim((string) $form_state->getValue('base_url')), '/'),
      $secret,
      (int) $form_state->getValue('timeout'),
      32,
      '0',
      $inputPrice,
      $outputPrice,
      TRUE,
    );
    $decision = $this->budget->authorize($profile, FALSE, FALSE);
    if (!$decision['allowed']) {
      $this->messenger()->addError($decision['reason']);
      return;
    }
    $usageId = $this->usage->start(0, 'connection_test', $profile->providerId, $profile->modelName, (int) $this->currentUser()->id());
    $response = $this->client->once($profile, [
      ['role' => 'user', 'content' => 'Reply with OK'],
    ], 16, '0');
    $cost = NULL;
    if ($response->ok && $profile->priceKnown()) {
      $cost = Money::costMicro($response->promptTokens, $response->completionTokens, $profile->inputPrice, $profile->outputPrice);
    }
    $this->usage->finish($usageId, $response, $cost, (int) $this->currentUser()->id());
    if ($response->ok) {
      $this->messenger()->addStatus($cost === NULL
        ? $this->t('连接成功。模型没有单价，费用未核算。Token：@count。', ['@count' => $response->promptTokens + $response->completionTokens])
        : $this->t('连接成功。Token：@count。估算费用 @cost 元。', [
          '@count' => $response->promptTokens + $response->completionTokens,
          '@cost' => Money::yuan($cost),
        ]));
      return;
    }
    $this->messenger()->addError($this->t('连接失败：@message', ['@message' => $response->errorMessage !== '' ? $response->errorMessage : $response->errorCode]));
  }

}
