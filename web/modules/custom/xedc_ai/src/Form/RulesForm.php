<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_ai\Review\RuleLimits;
use Drupal\xedc_ai\Usage\AuditLog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Local review thresholds. These rules do not call a model.
 */
final class RulesForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    $typed_config_manager,
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
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_rules';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['xedc_ai.rules'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $limits = RuleLimits::fromConfig($this->configFactory);
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['#attached']['library'][] = 'xedc_ai/admin';
    $form['intro'] = [
      '#markup' => '<p class="xedc-ai-note">' . $this->t('这些检查在调用模型之前运行。模型不可用时，本地检查仍然可以单独执行。图片视觉审核和视频抽帧保持关闭。') . '</p>',
    ];
    $fields = [
      'min_title' => '标题过短阈值（字）',
      'max_title' => '标题过长阈值（字）',
      'min_article_body' => '文章正文过短阈值（字）',
      'max_tags' => '标签数量上限',
      'min_image_edge' => '图片短边提示阈值（像素）',
      'max_video_seconds' => '视频时长上限（秒）',
      'phash_distance' => 'pHash 疑似重复距离',
      'max_hash_bytes' => '计算哈希的文件大小上限（字节）',
    ];
    foreach ($fields as $name => $title) {
      $form[$name] = [
        '#type' => 'number',
        '#title' => $this->t($title),
        '#default_value' => $limits[$name],
        '#min' => 0,
        '#required' => TRUE,
      ];
    }
    $form['phash_distance']['#description'] = $this->t('距离只表示本地感知哈希接近。它不能单独认定两张图一定重复。');
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ((int) $form_state->getValue('min_title') > (int) $form_state->getValue('max_title')) {
      $form_state->setErrorByName('max_title', $this->t('标题上限需要大于下限。'));
    }
    if ((int) $form_state->getValue('phash_distance') > 64) {
      $form_state->setErrorByName('phash_distance', $this->t('感知哈希距离不能超过 64。'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('xedc_ai.rules');
    foreach (array_keys(RuleLimits::defaults()) as $name) {
      $config->set($name, (int) $form_state->getValue($name));
    }
    $config->save();
    $this->audit->log('rules_save', '保存了本地审核规则阈值。');
    parent::submitForm($form, $form_state);
  }

}
