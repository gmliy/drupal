<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Usage\AuditLog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runtime settings for review, budget and model defaults.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    $typed_config_manager,
    private readonly ModelRepository $models,
    private readonly AuditLog $audit,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['xedc_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('xedc_ai.settings');
    $options = [0 => (string) $this->t('未设置')] + $this->models->options();
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['#attached']['library'][] = 'xedc_ai/admin';
    $form['models'] = [
      '#type' => 'details',
      '#title' => $this->t('模型配置'),
      '#open' => TRUE,
    ];
    $form['models']['default_model_id'] = [
      '#type' => 'select',
      '#title' => $this->t('默认文本模型'),
      '#options' => $options,
      '#default_value' => (int) $config->get('default_model_id'),
    ];
    $form['models']['fallback_model_id'] = [
      '#type' => 'select',
      '#title' => $this->t('备用文本模型'),
      '#options' => $options,
      '#default_value' => (int) $config->get('fallback_model_id'),
    ];
    foreach ([
      'model_content_review' => '文本审核模型',
      'model_quality' => '内容质量分析模型',
      'model_seo' => 'SEO 建议模型',
      'model_translation' => '翻译模型',
      'model_general' => '通用文本模型',
    ] as $name => $title) {
      $form['models'][$name] = [
        '#type' => 'select',
        '#title' => $title,
        '#options' => [0 => (string) $this->t('使用默认模型')] + $options,
        '#default_value' => (int) $config->get($name),
      ];
    }
    $form['models']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('默认超时（秒）'),
      '#min' => 5,
      '#max' => 120,
      '#default_value' => (int) $config->get('timeout'),
    ];
    $form['models']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('默认最大输出 Token'),
      '#min' => 32,
      '#max' => 8192,
      '#default_value' => (int) $config->get('max_tokens'),
    ];
    $form['models']['temperature'] = [
      '#type' => 'textfield',
      '#title' => $this->t('默认温度'),
      '#default_value' => (string) $config->get('temperature'),
    ];
    $form['review'] = [
      '#type' => 'details',
      '#title' => $this->t('审核配置'),
      '#open' => TRUE,
    ];
    foreach ([
      'auto_review_article' => '新建文章自动初审',
      'auto_review_photo' => '新建图片内容的文字自动初审',
      'auto_review_video' => '新建视频内容的文字自动初审',
      'auto_review_submission' => '会员投稿自动初审',
      'deep_analysis' => '允许手动深度分析',
    ] as $name => $title) {
      $form['review'][$name] = [
        '#type' => 'checkbox',
        '#title' => $this->t($title),
        '#default_value' => (bool) $config->get($name),
      ];
    }
    $form['review']['note'] = [
      '#markup' => '<p class="xedc-ai-note">' . $this->t('自动初审默认关闭。打开页面、刷新列表或上传文件都不会调用模型。本次没有图片视觉审核，也没有视频抽帧。') . '</p>',
    ];
    $form['review']['retry_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('自动重试次数'),
      '#min' => 0,
      '#max' => 3,
      '#default_value' => (int) $config->get('retry_limit'),
    ];
    $form['review']['max_concurrent'] = [
      '#type' => 'number',
      '#title' => $this->t('最大并发任务'),
      '#min' => 1,
      '#max' => 8,
      '#default_value' => (int) $config->get('max_concurrent'),
    ];
    $form['review']['rule_version'] = [
      '#type' => 'number',
      '#title' => $this->t('审核规则版本'),
      '#min' => 1,
      '#default_value' => (int) $config->get('rule_version'),
      '#description' => $this->t('调高后，旧结果不会再被当成新规则的结果。'),
    ];
    $form['review']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('记录保留天数'),
      '#min' => 0,
      '#default_value' => (int) $config->get('retention_days'),
      '#description' => $this->t('0 表示不自动删除。'),
    ];
    $form['cost'] = [
      '#type' => 'details',
      '#title' => $this->t('成本控制'),
      '#open' => TRUE,
    ];
    $form['cost']['daily_budget'] = [
      '#type' => 'textfield',
      '#title' => $this->t('每日预算（元）'),
      '#default_value' => (string) $config->get('daily_budget'),
    ];
    $form['cost']['monthly_budget'] = [
      '#type' => 'textfield',
      '#title' => $this->t('每月预算（元）'),
      '#default_value' => (string) $config->get('monthly_budget'),
    ];
    $form['cost']['budget_action'] = [
      '#type' => 'select',
      '#title' => $this->t('达到预算后'),
      '#options' => ['pause_auto' => (string) $this->t('暂停新的自动调用，保留历史记录')],
      '#default_value' => 'pause_auto',
    ];
    $form['cost']['allow_manual_over_budget'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('允许拥有管理权限的人在确认后手动超预算调用'),
      '#default_value' => (bool) $config->get('allow_manual_over_budget'),
    ];
    $form['cost']['allow_unpriced_manual'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('价格未知时允许手动调用，并标明费用未核算'),
      '#default_value' => (bool) $config->get('allow_unpriced_manual'),
    ];
    $form['seo_schedule'] = [
      '#type' => 'details',
      '#title' => $this->t('定期 SEO 检查'),
      '#open' => FALSE,
    ];
    $form['seo_schedule']['seo_schedule_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用定期检查'),
      '#default_value' => (bool) $config->get('seo_schedule_enabled'),
      '#description' => $this->t('默认关闭。关闭时不会扫描历史内容，也不会产生 API 费用。'),
    ];
    foreach ([
      'seo_schedule_daily_new' => '每日检查新发布内容',
      'seo_schedule_weekly' => '每周一检查本周有变化、且已经分析过的内容',
      'seo_schedule_changed' => '只检查修订版本已变化、且已经分析过的内容',
      'seo_schedule_stale' => '重新检查长期没有新分析的已分析内容',
    ] as $name => $title) {
      $form['seo_schedule'][$name] = [
        '#type' => 'checkbox',
        '#title' => $this->t($title),
        '#default_value' => (bool) $config->get($name),
      ];
    }
    $form['seo_schedule']['seo_schedule_stale_days'] = [
      '#type' => 'number',
      '#title' => $this->t('多少天没有新分析算作长期未优化'),
      '#min' => 7,
      '#default_value' => (int) ($config->get('seo_schedule_stale_days') ?: 30),
    ];
    $form['seo_schedule']['seo_schedule_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('每次最多加入队列的内容数'),
      '#min' => 1,
      '#max' => 10,
      '#default_value' => (int) ($config->get('seo_schedule_limit') ?: 3),
    ];
    foreach ([
      'seo_schedule_photo' => '美图',
      'seo_schedule_video' => '视频',
      'seo_schedule_article' => '文章',
      'seo_schedule_page' => '基础页',
    ] as $name => $title) {
      $form['seo_schedule'][$name] = [
        '#type' => 'checkbox',
        '#title' => $this->t($title),
        '#default_value' => (bool) $config->get($name),
      ];
    }
    $form['cost']['unpriced_daily_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('未标价模型的每日 Token 上限'),
      '#min' => 0,
      '#default_value' => (int) $config->get('unpriced_daily_tokens'),
      '#description' => $this->t('这不是折算后的金额。超过后按达到预算处理。'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $default = (int) $form_state->getValue('default_model_id');
    $fallback = (int) $form_state->getValue('fallback_model_id');
    if ($default > 0 && $default === $fallback) {
      $form_state->setErrorByName('fallback_model_id', $this->t('备用模型需要和默认模型不同。'));
    }
    $temperature = trim((string) $form_state->getValue('temperature'));
    if (!preg_match('/^\d(\.\d{1,2})?$/', $temperature) || (float) $temperature > 2) {
      $form_state->setErrorByName('temperature', $this->t('温度需在 0 到 2 之间。'));
    }
    foreach (['daily_budget', 'monthly_budget'] as $name) {
      if (Money::toMicro(trim((string) $form_state->getValue($name))) === NULL) {
        $form_state->setErrorByName($name, $this->t('预算必须是非负金额。'));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('xedc_ai.settings');
    $names = [
      'default_model_id', 'fallback_model_id', 'model_content_review', 'model_quality', 'model_seo', 'model_translation', 'model_general', 'timeout', 'max_tokens', 'temperature',
      'auto_review_article', 'auto_review_photo', 'auto_review_video', 'auto_review_submission',
      'deep_analysis', 'retry_limit', 'max_concurrent', 'rule_version', 'retention_days',
      'daily_budget', 'monthly_budget', 'budget_action', 'allow_manual_over_budget',
      'allow_unpriced_manual', 'unpriced_daily_tokens',
      'seo_schedule_enabled', 'seo_schedule_daily_new', 'seo_schedule_weekly', 'seo_schedule_changed', 'seo_schedule_stale',
      'seo_schedule_stale_days', 'seo_schedule_limit', 'seo_schedule_photo', 'seo_schedule_video', 'seo_schedule_article', 'seo_schedule_page',
    ];
    foreach ($names as $name) {
      $value = $form_state->getValue($name);
      if (in_array($name, ['default_model_id', 'fallback_model_id', 'model_content_review', 'model_quality', 'model_seo', 'model_translation', 'model_general', 'timeout', 'max_tokens', 'retry_limit', 'max_concurrent', 'rule_version', 'retention_days', 'unpriced_daily_tokens', 'seo_schedule_stale_days', 'seo_schedule_limit'], TRUE)) {
        $value = (int) $value;
      }
      elseif (in_array($name, ['auto_review_article', 'auto_review_photo', 'auto_review_video', 'auto_review_submission', 'deep_analysis', 'allow_manual_over_budget', 'allow_unpriced_manual', 'seo_schedule_enabled', 'seo_schedule_daily_new', 'seo_schedule_weekly', 'seo_schedule_changed', 'seo_schedule_stale', 'seo_schedule_photo', 'seo_schedule_video', 'seo_schedule_article', 'seo_schedule_page'], TRUE)) {
        $value = (bool) $value;
      }
      else {
        $value = trim((string) $value);
      }
      $config->set($name, $value);
    }
    $config->save();
    $this->audit->log('settings_save', '保存了 AI 运行配置。');
    parent::submitForm($form, $form_state);
  }

}
