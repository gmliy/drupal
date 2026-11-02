<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\xedc_ai\Usage\AuditLog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pauses or resumes queued model calls. Pending rows stay in the queue.
 */
final class QueueControlForm extends FormBase {

  public function __construct(
    private readonly StateInterface $state,
    private readonly AuditLog $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_queue_control';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $paused = (bool) $this->state->get('xedc_ai.paused', FALSE);
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['status'] = [
      '#markup' => '<p class="xedc-ai-note">' . ($paused
        ? $this->t('队列已暂停。尚未执行的任务会保留，不会调用模型。')
        : $this->t('队列正在运行。暂停只阻止新的模型调用，不删除审核记录。')) . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['pause'] = [
      '#type' => 'submit',
      '#value' => $this->t('暂停队列'),
      '#submit' => ['::pause'],
      '#access' => !$paused && $this->currentUser()->hasPermission('execute xedc ai reviews'),
    ];
    $form['actions']['resume'] = [
      '#type' => 'submit',
      '#value' => $this->t('恢复队列'),
      '#submit' => ['::resume'],
      '#access' => $paused && $this->currentUser()->hasPermission('execute xedc ai reviews'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function pause(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $this->state->set('xedc_ai.paused', TRUE);
    $this->audit->log('queue_pause', '暂停了 AI 审核队列。', NULL, [
      'target_type' => 'queue',
      'target_id' => 'xedc_ai_review',
      'confirmed' => 1,
      'result' => 'paused',
    ]);
    $this->messenger()->addStatus($this->t('已暂停。正在执行的单条任务会结束，新的模型调用会等待恢复。'));
  }

  public function resume(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $this->state->set('xedc_ai.paused', FALSE);
    $this->audit->log('queue_resume', '恢复了 AI 审核队列。', NULL, [
      'target_type' => 'queue',
      'target_id' => 'xedc_ai_review',
      'confirmed' => 1,
      'result' => 'running',
    ]);
    $this->messenger()->addStatus($this->t('队列已恢复。'));
  }

}
