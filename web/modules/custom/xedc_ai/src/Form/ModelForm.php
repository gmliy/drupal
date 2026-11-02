<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Provider\ProviderCatalog;
use Drupal\xedc_ai\Provider\ProviderRepository;
use Drupal\xedc_ai\Usage\AuditLog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Add or edit a text model. The name is stored, not hardcoded later.
 */
final class ModelForm extends FormBase {

  public function __construct(
    private readonly ModelRepository $models,
    private readonly ProviderRepository $providers,
    private readonly AuditLog $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.providers'),
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_model';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $id = (int) $this->getRouteMatch()->getParameter('model');
    $model = NULL;
    if ($id > 0) {
      $model = $this->models->load($id);
      if ($model === NULL) {
        throw new NotFoundHttpException();
      }
    }
    $options = [];
    foreach ($this->providers->all() as $provider) {
      $options[$provider['id']] = $provider['label'];
    }
    if ($options === []) {
      $form['empty'] = ['#markup' => '<p>' . $this->t('请先新增供应商。') . '</p>'];
      return $form;
    }
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['#attached']['library'][] = 'xedc_ai/admin';
    $form['provider_id'] = [
      '#type' => 'select',
      '#title' => $this->t('供应商'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $model['provider_id'] ?? array_key_first($options),
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('显示名称'),
      '#required' => TRUE,
      '#default_value' => $model['label'] ?? '',
    ];
    $form['model_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('模型名称'),
      '#required' => TRUE,
      '#default_value' => $model['model_name'] ?? '',
      '#description' => $this->t('填写供应商文档中的模型 ID。业务代码不会把某个模型名写死。'),
    ];
    $form['input_price'] = [
      '#type' => 'textfield',
      '#title' => $this->t('输入单价（元 / 百万 Token）'),
      '#default_value' => $model['input_price'] ?? '',
      '#description' => $this->t('留空表示价格未知。未知价格不会被编造成费用。'),
    ];
    $form['output_price'] = [
      '#type' => 'textfield',
      '#title' => $this->t('输出单价（元 / 百万 Token）'),
      '#default_value' => $model['output_price'] ?? '',
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用'),
      '#default_value' => $model === NULL ? 1 : (int) $model['enabled'],
    ];
    $selected = array_fill_keys(array_filter(explode(',', (string) ($model['capabilities'] ?? ''))), TRUE);
    $form['capabilities'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('模型能力'),
      '#options' => Labels::capabilities(),
      '#default_value' => array_keys($selected),
    ];
    $form['suggestions'] = [
      '#markup' => '<p class="xedc-ai-note">' . $this->t('预设名称只是填写参考：@list', [
        '@list' => implode('、', array_filter(array_map(static fn (array $preset): string => $preset['suggested_model'], ProviderCatalog::presets()))),
      ]) . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('保存'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('返回'),
      '#url' => Url::fromRoute('xedc_ai.models'),
      '#attributes' => ['class' => ['button']],
    ];
    if ($model !== NULL) {
      $form['model_id'] = ['#type' => 'value', '#value' => (int) $model['id']];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) $form_state->getValue('model_name'));
    if ($name === '' || strlen($name) > 128) {
      $form_state->setErrorByName('model_name', $this->t('模型名称不正确。'));
    }
    $provider = (string) $form_state->getValue('provider_id');
    $current = (int) $form_state->getValue('model_id');
    foreach ($this->models->all() as $row) {
      if ((string) $row['provider_id'] === $provider && (string) $row['model_name'] === $name && (int) $row['id'] !== $current) {
        $form_state->setErrorByName('model_name', $this->t('这个供应商下已经有同名模型。'));
      }
    }
    foreach (['input_price', 'output_price'] as $field) {
      $value = trim((string) $form_state->getValue($field));
      if ($value !== '' && Money::toMicro($value) === NULL) {
        $form_state->setErrorByName($field, $this->t('单价必须是非负数字，最多 6 位小数。'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->providers->load((string) $form_state->getValue('provider_id')) === NULL) {
      $this->messenger()->addError($this->t('供应商不存在。'));
      return;
    }
    $id = $this->models->save([
      'id' => (int) $form_state->getValue('model_id'),
      'provider_id' => (string) $form_state->getValue('provider_id'),
      'model_name' => trim((string) $form_state->getValue('model_name')),
      'label' => trim((string) $form_state->getValue('label')),
      'enabled' => (int) $form_state->getValue('enabled'),
      'capabilities' => (array) $form_state->getValue('capabilities'),
      'input_price' => trim((string) $form_state->getValue('input_price')),
      'output_price' => trim((string) $form_state->getValue('output_price')),
    ]);
    $this->audit->log('model_save', '保存了模型 #' . $id . '。');
    $this->messenger()->addStatus($this->t('模型已保存。'));
    $form_state->setRedirect('xedc_ai.models');
  }

}
