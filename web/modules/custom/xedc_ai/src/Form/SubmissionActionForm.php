<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\ReviewEnqueuer;
use Drupal\xedc_ai\Review\SubmissionModerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmed submission decisions and review queueing.
 */
final class SubmissionActionForm extends FormBase {

  /**
   * @param list<array<string, mixed>> $rows
   *   Waiting submissions.
   */
  public function __construct(
    private readonly SubmissionModerator $moderator,
    private readonly ReviewEnqueuer $enqueuer,
    private readonly EntityTypeManagerInterface $entities,
    private readonly array $rows = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.submissions'),
      $container->get('xedc_ai.enqueuer'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_submission_action';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $rows = []): array {
    $rows = $rows !== [] ? $rows : $this->rows;
    if ($rows === []) {
      return [];
    }
    $options = [];
    foreach ($rows as $row) {
      $options[(int) $row['nid']] = $this->t('#@id @title', [
        '@id' => $row['nid'],
        '@title' => $row['title'] !== '' ? $row['title'] : $this->t('无标题'),
      ]);
    }
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['nodes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('待审核投稿'),
      '#options' => $options,
    ];
    $form['select_all'] = [
      '#markup' => '<label class="xedc-ai-note"><input type="checkbox" class="xedc-ai-select-all"> ' . $this->t('全选本页') . '</label>',
    ];
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('确认后执行'),
      '#options' => [
        'review' => (string) $this->t('只加入 AI 文字审核'),
        'approve' => (string) $this->t('通过'),
        'changes' => (string) $this->t('退回修改'),
        'reject' => (string) $this->t('拒绝'),
      ],
      '#default_value' => 'review',
    ];
    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('原因'),
      '#description' => $this->t('拒绝时必填。通过和退回时会写入审核日志。'),
      '#rows' => 3,
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认对选中的投稿执行上面的操作'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('执行选中操作'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->selected($form_state) === []) {
      $form_state->setErrorByName('nodes', $this->t('请至少选择一条投稿。'));
    }
    $action = (string) $form_state->getValue('action');
    if ($action !== 'review' && !(bool) $form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('变更投稿状态前需要勾选确认。'));
    }
    if ($action === 'reject' && trim((string) $form_state->getValue('reason')) === '') {
      $form_state->setErrorByName('reason', $this->t('拒绝时必须填写原因。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $action = (string) $form_state->getValue('action');
    $reason = trim((string) $form_state->getValue('reason'));
    $confirmed = (bool) $form_state->getValue('confirm');
    $done = 0;
    foreach ($this->selected($form_state) as $nid) {
      $node = $this->entities->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface) {
        $this->messenger()->addError($this->t('内容 @id 不存在。', ['@id' => $nid]));
        continue;
      }
      if ($action === 'review') {
        if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
          $this->messenger()->addError($this->t('没有执行 AI 审核的权限。'));
          return;
        }
        $id = $this->enqueuer->request($node, (int) $this->currentUser()->id(), 'content_review', 'quick', TRUE, FALSE);
        $this->messenger()->addStatus($this->t('内容 @nid 已进入审核队列 #@id。', ['@nid' => $nid, '@id' => $id]));
        $done++;
        continue;
      }
      if (!$this->currentUser()->hasPermission('moderate xedc ai submissions')) {
        $this->messenger()->addError($this->t('没有批准投稿的权限。'));
        return;
      }
      $error = $this->moderator->apply($node, $action, $reason, (int) $this->currentUser()->id(), $confirmed);
      if ($error !== NULL) {
        $this->messenger()->addError($this->t('内容 @id：@error', ['@id' => $nid, '@error' => $error]));
        continue;
      }
      $done++;
    }
    if ($done > 0 && $action !== 'review') {
      $this->messenger()->addStatus($this->t('已按确认处理 @count 条投稿。', ['@count' => $done]));
    }
  }

  /**
   * @return list<int>
   *   Selected node ids.
   */
  private function selected(FormStateInterface $form_state): array {
    $ids = [];
    foreach ((array) $form_state->getValue('nodes') as $id => $checked) {
      if (!empty($checked)) {
        $ids[] = (int) $id;
      }
    }
    return $ids;
  }

}
