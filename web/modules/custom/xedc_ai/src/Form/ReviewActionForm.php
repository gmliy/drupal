<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\ReviewEnqueuer;
use Drupal\xedc_ai\Review\TextExtractor;
use Drupal\xedc_ai\Seo\SeoAdvisor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detail actions. None of them publish or delete the node by themselves.
 */
final class ReviewActionForm extends FormBase {

  /**
   * @param array<string, mixed> $review
   *   Review row.
   */
  public function __construct(
    private readonly ReviewEnqueuer $enqueuer,
    private readonly TextExtractor $extractor,
    private readonly SeoAdvisor $seo,
    private readonly array $review = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.enqueuer'),
      $container->get('xedc_ai.extractor'),
      $container->get('xedc_ai.seo'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_review_action';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $review = []): array {
    $review = $review !== [] ? $review : $this->review;
    $form_state->set('review_id', (int) ($review['id'] ?? 0));
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $result = json_decode((string) ($review['result'] ?? ''), TRUE);
    if ((string) ($review['source'] ?? '') === 'seo_optimization' && is_array($result)) {
      $form['use_title'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('把建议标题写入内容标题'),
        '#access' => $this->currentUser()->hasPermission('apply xedc ai changes'),
      ];
      $form['use_summary'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('把建议描述写入简介。站点没有可单独保存的 Meta Description 字段。'),
        '#access' => $this->currentUser()->hasPermission('apply xedc ai changes'),
      ];
      $form['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('确认后写入所选文字'),
        '#submit' => ['::apply'],
        '#access' => $this->currentUser()->hasPermission('apply xedc ai changes'),
      ];
    }
    $form['over_budget'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认重新审核可以在超出预算后继续调用'),
      '#access' => $this->currentUser()->hasPermission('administer xedc ai') && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['rerun'] = [
      '#type' => 'submit',
      '#value' => $this->t('重新审核'),
      '#submit' => ['::rerun'],
      '#access' => $this->currentUser()->hasPermission('execute xedc ai reviews'),
    ];
    if ($this->config('xedc_ai.settings')->get('deep_analysis') && (string) ($review['source'] ?? '') !== 'seo_optimization') {
      $form['actions']['deep'] = [
        '#type' => 'submit',
        '#value' => $this->t('深度分析'),
        '#submit' => ['::deep'],
        '#access' => $this->currentUser()->hasPermission('execute xedc ai reviews'),
      ];
    }
    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('确认已处理'),
      '#submit' => ['::confirm'],
      '#access' => $this->currentUser()->hasPermission('administer xedc ai reviews'),
    ];
    $form['actions']['ignore'] = [
      '#type' => 'submit',
      '#value' => $this->t('忽略'),
      '#submit' => ['::ignore'],
      '#access' => $this->currentUser()->hasPermission('administer xedc ai reviews'),
    ];
    return $form;
  }

  /**
   * Queues another text review.
   */
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function rerun(array &$form, FormStateInterface $form_state): void {
    $this->queue($form_state, 'quick');
  }

  /**
   * Queues a manual deep review.
   */
  public function deep(array &$form, FormStateInterface $form_state): void {
    $this->queue($form_state, 'deep');
  }

  /**
   * Records a human confirmation without changing the node.
   */
  public function confirm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer xedc ai reviews')) {
      return;
    }
    $this->enqueuer->confirm((int) $form_state->get('review_id'), (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('已记录人工确认。内容状态没有改变。'));
  }

  /**
   * Ignores the review without changing the node.
   */
  public function ignore(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer xedc ai reviews')) {
      return;
    }
    $this->enqueuer->ignore((int) $form_state->get('review_id'), (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('已忽略。内容没有被删除或发布。'));
  }

  /**
   * Writes selected SEO text only when that cannot trigger translation.
   */
  public function apply(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('apply xedc ai changes')) {
      return;
    }
    $review = $this->reviewRow((int) $form_state->get('review_id'));
    if ($review === NULL) {
      $this->messenger()->addError($this->t('没有可写入的建议。'));
      return;
    }
    $node = $this->node($review);
    $result = json_decode((string) ($review['result'] ?? ''), TRUE);
    if (!$node instanceof NodeInterface || !is_array($result)) {
      $this->messenger()->addError($this->t('没有可写入的建议。'));
      return;
    }
    $keys = [];
    if ($form_state->getValue('use_title')) {
      $keys[] = 'title';
    }
    if ($form_state->getValue('use_summary')) {
      $keys[] = 'summary';
    }
    $default = $this->extractor->loadRevision('node', (int) $review['entity_id'], 0);
    $target = $default instanceof NodeInterface ? $default : $node;
    $outcome = $this->seo->applySelected($target, $result, $keys, (int) $this->currentUser()->id(), (int) $review['revision_id'], (int) ($result['applied_revision_id'] ?? 0), FALSE, TRUE, (int) $review['id']);
    \Drupal::service('xedc_ai.reviews')->update((int) $review['id'], [
      'result' => json_encode($outcome['result'], JSON_UNESCAPED_UNICODE) ?: (string) $review['result'],
    ]);
    if ($outcome['error'] !== NULL) {
      $this->messenger()->addError($outcome['error']);
      return;
    }
    $this->messenger()->addStatus($this->t('已写入所选文字。发布状态没有改变。'));
  }

  private function queue(FormStateInterface $form_state, string $depth): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $review = $this->reviewRow((int) $form_state->get('review_id'));
    $node = $review ? $this->node($review) : NULL;
    if (!$review || !$node instanceof NodeInterface) {
      $this->messenger()->addError($this->t('内容不存在，不能重新审核。'));
      return;
    }
    if ((string) $review['source'] === 'seo_optimization') {
      $depth = 'seo';
    }
    $over = (bool) $form_state->getValue('over_budget')
      && $this->currentUser()->hasPermission('administer xedc ai')
      && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget');
    $id = $this->enqueuer->request($node, (int) $this->currentUser()->id(), (string) $review['source'], $depth, TRUE, $over);
    $this->messenger()->addStatus($this->t('已加入队列 #@id。', ['@id' => $id]));
  }

  /**
   * @return array<string, mixed>|null
   *   Review row.
   */
  private function reviewRow(int $id): ?array {
    $storage = \Drupal::service('xedc_ai.reviews');
    $row = $storage->load($id);
    return is_array($row) ? $row : NULL;
  }

  /**
   * @param array<string, mixed> $review
   *   Review row.
   */
  private function node(array $review): ?NodeInterface {
    return $this->extractor->loadRevision((string) $review['entity_type'], (int) $review['entity_id'], (int) $review['revision_id']);
  }

}
