<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Review\TextExtractor;
use Drupal\xedc_ai\Seo\SeoAdvisor;
use Drupal\xedc_ai\Seo\SeoResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adopts or rejects individual SEO suggestions after a person confirms them.
 */
final class SeoApplyForm extends FormBase {

  /**
   * @param array<string, mixed> $review
   *   Review row.
   */
  public function __construct(
    private readonly SeoAdvisor $seo,
    private readonly ReviewRepository $reviews,
    private readonly TextExtractor $extractor,
    private readonly array $review = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.seo'),
      $container->get('xedc_ai.reviews'),
      $container->get('xedc_ai.extractor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_seo_apply';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $review = []): array {
    $review = $review !== [] ? $review : $this->review;
    $form_state->set('review_id', (int) ($review['id'] ?? 0));
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['#attributes']['class'][] = 'xedc-ai-seo-apply';
    $result = json_decode((string) ($review['result'] ?? ''), TRUE);
    $items = is_array($result) ? SeoResult::itemRows($result) : [];
    $form_state->set('allowed_keys', array_values(array_map(static fn (array $item): string => (string) ($item['key'] ?? ''), $items)));
    $applicable = FALSE;
    $form['items'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['xedc-ai-apply-items']],
    ];
    foreach ($items as $item) {
      $state = (string) ($item['state'] ?? '');
      if ($state === '') {
        $state = 'pending';
      }
      if (empty($item['applicable']) || !in_array($state, ['pending', 'adopted', 'failed'], TRUE)) {
        continue;
      }
      $applicable = TRUE;
      $safe = 'k' . substr(hash('sha256', (string) $item['key']), 0, 16);
      $form['items'][$safe] = [
        '#type' => 'checkbox',
        '#title' => (string) ($item['label'] ?? $item['key']) . '（' . SeoResult::stateLabel($state) . '）',
        '#return_value' => (string) $item['key'],
        '#default_value' => 0,
        '#attributes' => ['data-xedc-apply-state' => $state],
        '#wrapper_attributes' => ['data-xedc-apply-state' => $state],
        '#description' => $this->t('当前：@current。建议：@suggested', [
          '@current' => (string) ($item['current'] ?? ''),
          '@suggested' => (string) ($item['suggested'] ?? ''),
        ]),
      ];
    }
    if ($applicable) {
      $form['select_all'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '全选待处理建议',
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'xedc-ai-apply-all'],
          'data-xedc-select-label' => '全选待处理建议',
          'data-xedc-clear-label' => '取消全选',
        ],
        '#weight' => -10,
      ];
      $form['selected_count'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => '已选择 0 条建议',
        '#attributes' => [
          'class' => ['xedc-ai-selected-count'],
          'data-xedc-selected-count' => '1',
        ],
        '#weight' => -9,
      ];
    }
    $node = $this->extractor->loadRevision('node', (int) ($review['entity_id'] ?? 0), 0);
    $route = $node instanceof NodeInterface ? $this->seo->publishRoute($node) : ['ok' => FALSE, 'archived' => FALSE, 'message' => '', 'permission' => '', 'targets' => []];
    if (!empty($route['archived'])) {
      $form['republish'] = [
        '#type' => 'details',
        '#title' => '采纳并重新发布',
        '#open' => FALSE,
        '#tree' => FALSE,
        '#weight' => 20,
        '#attributes' => ['class' => ['xedc-ai-republish']],
      ];
      $form['republish']['notice'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => '当前标题：' . $node->label() . '。当前状态：已撤下。这个操作和「确认采纳所选」分开。重新发布后内容会对公众可见。' . (string) $route['message'],
      ];
      $form['republish']['republish_confirm'] = [
        '#type' => 'checkbox',
        '#title' => '我确认重新发布后，这篇内容会对公众可见',
        '#return_value' => 'yes',
      ];
      $form['republish']['go'] = [
        '#type' => 'submit',
        '#value' => '确认重新发布',
        '#submit' => ['::republish'],
        '#attributes' => ['class' => ['xedc-ai-republish-submit']],
      ];
      $form['republish']['cancel'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '取消',
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'xedc-ai-republish-cancel'],
        ],
      ];
    }
    if (!$applicable) {
      $form['empty'] = ['#markup' => '<p class="xedc-ai-empty">' . $this->t('没有等待确认的可写入建议。') . '</p>'];
    }
    $form['actions'] = ['#type' => 'actions', '#access' => $applicable && $this->currentUser()->hasPermission('apply xedc ai changes')];
    $form['actions']['adopt'] = [
      '#type' => 'submit',
      '#value' => $this->t('确认采纳所选'),
      '#button_type' => 'primary',
      '#submit' => ['::adopt'],
    ];
    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('拒绝所选'),
      '#submit' => ['::reject'],
    ];
    $form['actions']['restore'] = [
      '#type' => 'submit',
      '#value' => $this->t('恢复所选原值'),
      '#submit' => ['::restore'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function adopt(array &$form, FormStateInterface $form_state): void {
    $this->act($form_state, FALSE);
  }

  public function republish(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('apply xedc ai changes')) {
      $this->messenger()->addError($this->t('没有采纳 SEO 建议的权限，内容没有修改。'));
      return;
    }
    $review = $this->reviews->load((int) $form_state->get('review_id'));
    if ($review === NULL) {
      $this->messenger()->addError($this->t('分析记录不存在。'));
      return;
    }
    $node = $this->extractor->loadRevision('node', (int) $review['entity_id'], 0);
    if (!$node instanceof NodeInterface) {
      $this->messenger()->addError($this->t('没有可处理的建议。'));
      return;
    }
    if ((string) $form_state->getValue('republish_confirm') !== 'yes') {
      $this->messenger()->addError($this->t('已取消重新发布，内容没有修改。'));
      return;
    }
    $route = $this->seo->publishRoute($node);
    if (empty($route['ok'])) {
      $this->messenger()->addError((string) ($route['message'] !== '' ? $route['message'] : '当前工作流不允许重新发布，内容没有修改。'));
      return;
    }
    if ($route['permission'] !== '' && !$this->currentUser()->hasPermission($route['permission'])) {
      $this->messenger()->addError($this->t('没有使用这个发布转换的权限，内容没有修改。'));
      return;
    }
    $selected = $this->chosen($form_state);
    if ($selected === []) {
      $this->messenger()->addError($this->t('请至少选择一条要处理的建议。没有重新发布。'));
      return;
    }
    $before = (string) $node->get('moderation_state')->value;
    $this->act($form_state, FALSE);
    $saved = $this->reviews->load((int) $review['id']);
    $savedResult = json_decode((string) ($saved['result'] ?? ''), TRUE);
    $adopted = 0;
    if (is_array($savedResult)) {
      foreach (SeoResult::itemRows($savedResult) as $item) {
        if (in_array((string) ($item['key'] ?? ''), $selected, TRUE) && (string) ($item['state'] ?? '') === 'adopted') {
          $adopted++;
        }
      }
    }
    if ($adopted === 0) {
      $this->messenger()->addError($this->t('建议没有写入，没有重新发布。'));
      return;
    }
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([(int) $node->id()]);
    $fresh = $this->extractor->loadRevision('node', (int) $review['entity_id'], 0);
    if (!$fresh instanceof NodeInterface || (string) $fresh->get('moderation_state')->value !== 'archived') {
      $this->messenger()->addError($this->t('内容已经不是已撤下，没有继续改为已发布。'));
      return;
    }
    $fresh->set('moderation_state', 'published');
    $fresh->setNewRevision(TRUE);
    $fresh->setRevisionUserId((int) $this->currentUser()->id());
    $fresh->setRevisionLogMessage('SEO 建议确认后，按工作流重新发布。原状态：' . $before);
    try {
      $fresh->save();
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('重新发布被工作流拒绝，内容没有公开。@message', ['@message' => $exception->getMessage()]));
      return;
    }
    if ((string) $fresh->get('moderation_state')->value !== 'published') {
      $this->messenger()->addError($this->t('工作流没有接受发布转换，内容没有公开。'));
      return;
    }
    $this->messenger()->addStatus($this->t('已按工作流重新发布。'));
  }

  public function reject(array &$form, FormStateInterface $form_state): void {
    $this->act($form_state, TRUE);
  }

  public function restore(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('apply xedc ai changes')) {
      return;
    }
    $review = $this->reviews->load((int) $form_state->get('review_id'));
    if ($review === NULL) {
      $this->messenger()->addError($this->t('分析记录不存在。'));
      return;
    }
    $node = $this->extractor->loadRevision('node', (int) $review['entity_id'], 0);
    $result = json_decode((string) $review['result'], TRUE);
    if (!$node instanceof NodeInterface || !is_array($result)) {
      $this->messenger()->addError($this->t('没有可处理的建议。'));
      return;
    }
    $selected = $this->chosen($form_state);
    if ($selected === []) {
      $this->messenger()->addError($this->t('请至少选择一条要处理的建议。'));
      return;
    }
    $outcome = $this->seo->restoreSelected(
      $node,
      $result,
      $selected,
      (int) $this->currentUser()->id(),
      (int) $review['revision_id'],
      (int) ($result['applied_revision_id'] ?? 0),
      TRUE,
      (int) $review['id'],
    );
    $this->reviews->update((int) $review['id'], [
      'result' => json_encode($outcome['result'], JSON_UNESCAPED_UNICODE) ?: (string) $review['result'],
      'handled_uid' => (int) $this->currentUser()->id(),
      'handled_at' => \Drupal::time()->getRequestTime(),
    ]);
    $this->report($outcome, TRUE);
  }

  private function act(FormStateInterface $form_state, bool $reject): void {
    if (!$this->currentUser()->hasPermission('apply xedc ai changes') && !$reject) {
      return;
    }
    if ($reject && !$this->currentUser()->hasPermission('administer xedc ai reviews') && !$this->currentUser()->hasPermission('apply xedc ai changes')) {
      return;
    }
    $review = $this->reviews->load((int) $form_state->get('review_id'));
    if ($review === NULL) {
      $this->messenger()->addError($this->t('分析记录不存在。'));
      return;
    }
    $node = $this->extractor->loadRevision('node', (int) $review['entity_id'], 0);
    $result = json_decode((string) $review['result'], TRUE);
    if (!$node instanceof NodeInterface || !is_array($result)) {
      $this->messenger()->addError($this->t('没有可处理的建议。'));
      return;
    }
    $selected = $this->chosen($form_state);
    if ($selected === []) {
      $this->messenger()->addError($this->t('请至少选择一条要处理的建议。'));
      return;
    }
    $outcome = $this->seo->applySelected(
      $node,
      $result,
      $selected,
      (int) $this->currentUser()->id(),
      (int) $review['revision_id'],
      (int) ($result['applied_revision_id'] ?? 0),
      $reject,
      TRUE,
      (int) $review['id'],
    );
    $updated = $outcome['result'];
    $ops = SeoResult::opsStatus('completed', (string) $review['handled_status'], $updated, FALSE);
    $handled = (string) $review['handled_status'];
    if ($ops === 'adopted') {
      $handled = 'confirmed';
    }
    elseif ($ops === 'ignored') {
      $handled = 'ignored';
    }
    $this->reviews->update((int) $review['id'], [
      'result' => json_encode($updated, JSON_UNESCAPED_UNICODE) ?: (string) $review['result'],
      'handled_status' => $handled,
      'handled_uid' => (int) $this->currentUser()->id(),
      'handled_at' => \Drupal::time()->getRequestTime(),
    ]);
    $this->report($outcome, FALSE);
  }

  /**
   * @return list<string>
   *   Keys the person checked that belong to this analysis.
   */
  private function chosen(FormStateInterface $form_state): array {
    $allowed = $form_state->get('allowed_keys');
    return SeoResult::chosenKeys($form_state->getValue('items'), is_array($allowed) ? $allowed : []);
  }

  /**
   * @param array{error: ?string, result: array<string, mixed>, revision_id: int, tally?: array<string, int>} $outcome
   *   Apply or restore result.
   */
  private function report(array $outcome, bool $restore): void {
    $tally = $outcome['tally'] ?? [];
    $parts = [];
    if (($tally['adopted'] ?? 0) > 0) {
      $parts[] = '成功采纳 ' . (int) $tally['adopted'] . ' 条。';
    }
    if (($tally['rejected'] ?? 0) > 0) {
      $parts[] = '已忽略 ' . (int) $tally['rejected'] . ' 条。';
    }
    if (($tally['conflict'] ?? 0) > 0) {
      $parts[] = '版本冲突 ' . (int) $tally['conflict'] . ' 条。';
    }
    if (($tally['failed'] ?? 0) > 0) {
      $parts[] = '采纳失败 ' . (int) $tally['failed'] . ' 条。';
    }
    if (($tally['restored'] ?? 0) > 0) {
      $parts[] = '已恢复 ' . (int) $tally['restored'] . ' 条。';
    }
    if ($parts !== []) {
      $suffix = $restore
        ? '发布状态和工作流没有被改成发布。'
        : (($tally['adopted'] ?? 0) > 0 || ($tally['restored'] ?? 0) > 0 ? '发布状态和工作流没有被改成发布。' : '');
      $this->messenger()->addStatus(implode('', $parts) . $suffix);
    }
    if ($outcome['error'] !== NULL) {
      $succeeded = ($tally['adopted'] ?? 0) + ($tally['rejected'] ?? 0) + ($tally['restored'] ?? 0) > 0;
      if ($succeeded) {
        $this->messenger()->addWarning($outcome['error']);
      }
      else {
        $this->messenger()->addError($outcome['error']);
      }
    }
  }

}
