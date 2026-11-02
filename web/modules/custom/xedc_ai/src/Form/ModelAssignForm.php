<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Usage\AuditLog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chooses the default and fallback text models.
 */
final class ModelAssignForm extends FormBase {

  public function __construct(
    private readonly ModelRepository $models,
    private readonly AuditLog $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_model_assign';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = [0 => (string) $this->t('未设置')] + $this->models->options();
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['default_model_id'] = [
      '#type' => 'select',
      '#title' => $this->t('默认文本模型'),
      '#options' => $options,
      '#default_value' => $this->models->defaultId(),
    ];
    $form['fallback_model_id'] = [
      '#type' => 'select',
      '#title' => $this->t('备用文本模型'),
      '#options' => $options,
      '#default_value' => $this->models->fallbackId(),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('保存默认模型')];
    return $form;
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
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->getEditable('xedc_ai.settings');
    $config->set('default_model_id', (int) $form_state->getValue('default_model_id'));
    $config->set('fallback_model_id', (int) $form_state->getValue('fallback_model_id'));
    $config->save();
    $this->audit->log('model_default', '更新了默认模型和备用模型。');
    $this->messenger()->addStatus($this->t('默认模型已保存。'));
  }

}
