<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Usage\AuditLog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Deletes a model only when it is not assigned or referenced.
 */
final class ModelDeleteForm extends ConfirmFormBase {

  private int $modelId = 0;

  private string $blocked = '';

  public function __construct(
    private readonly ModelRepository $models,
    private readonly ReviewRepository $reviews,
    private readonly AuditLog $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.reviews'),
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_model_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('删除模型 #@id？', ['@id' => $this->modelId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('xedc_ai.models');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $model = NULL): array {
    $this->modelId = (int) $model;
    $row = $this->models->load($this->modelId);
    if ($row === NULL) {
      throw new NotFoundHttpException();
    }
    $reasons = [];
    if ($this->models->defaultId() === $this->modelId || $this->models->fallbackId() === $this->modelId) {
      $reasons[] = (string) $this->t('它是默认模型或备用模型。');
    }
    $count = $this->reviews->countForModel($this->modelId);
    if ($count > 0) {
      $reasons[] = (string) $this->t('已有 @count 条审核记录引用它。', ['@count' => $count]);
    }
    if ($reasons !== []) {
      $this->blocked = implode(' ', $reasons);
      $form['blocked'] = ['#markup' => '<p>' . $this->t('不能删除。请先修改默认模型或保留历史记录。') . ' ' . $this->blocked . '</p>'];
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->blocked !== '') {
      return;
    }
    $this->models->delete($this->modelId);
    $this->audit->log('model_delete', '删除了模型 #' . $this->modelId . '。');
    $this->messenger()->addStatus($this->t('模型已删除。'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
