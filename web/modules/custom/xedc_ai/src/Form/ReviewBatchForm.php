<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\ReviewEnqueuer;
use Drupal\xedc_ai\Review\ReviewRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Requeues or ignores the reviews selected on the current page.
 */
final class ReviewBatchForm extends FormBase {

  /**
   * @param list<array<string, mixed>> $rows
   *   Reviews on the current page.
   */
  public function __construct(
    private readonly ReviewEnqueuer $enqueuer,
    private readonly ReviewRepository $reviews,
    private readonly EntityTypeManagerInterface $entities,
    private readonly array $rows = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.enqueuer'),
      $container->get('xedc_ai.reviews'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_review_batch';
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
      $options[(int) $row['id']] = $this->t('#@id @title', [
        '@id' => $row['id'],
        '@title' => $row['title'] !== '' ? $row['title'] : $this->t('无标题'),
      ]);
    }
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['reviews'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('本页记录'),
      '#options' => $options,
    ];
    $form['select_all'] = [
      '#markup' => '<label class="xedc-ai-note"><input type="checkbox" class="xedc-ai-select-all"> ' . $this->t('全选本页') . '</label>',
    ];
    $form['over_budget'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认选中的重新审核可以在超出预算后继续调用'),
      '#access' => $this->currentUser()->hasPermission('administer xedc ai') && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['rerun'] = [
      '#type' => 'submit',
      '#value' => $this->t('重新审核'),
      '#submit' => ['::rerun'],
      '#access' => $this->currentUser()->hasPermission('execute xedc ai reviews'),
    ];
    $form['actions']['ignore'] = [
      '#type' => 'submit',
      '#value' => $this->t('忽略'),
      '#submit' => ['::ignore'],
      '#access' => $this->currentUser()->hasPermission('administer xedc ai reviews'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('取消未执行'),
      '#submit' => ['::cancel'],
      '#access' => $this->currentUser()->hasPermission('execute xedc ai reviews'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Queues a fresh manual review for each selected row.
   */
  public function rerun(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $over = (bool) $form_state->getValue('over_budget')
      && $this->currentUser()->hasPermission('administer xedc ai')
      && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget');
    $count = 0;
    foreach ($this->selected($form_state) as $row) {
      $node = $this->entities->getStorage('node')->load((int) $row['entity_id']);
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $depth = (string) $row['depth'] === 'seo' ? 'seo' : 'quick';
      $this->enqueuer->request($node, (int) $this->currentUser()->id(), (string) $row['source'], $depth, TRUE, $over);
      $count++;
    }
    $this->messenger()->addStatus($this->t('已加入 @count 个重新审核任务。', ['@count' => $count]));
  }

  /**
   * Marks selected reviews ignored without changing the nodes.
   */
  public function cancel(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $count = 0;
    foreach ($this->selected($form_state) as $row) {
      if ($this->enqueuer->cancel((int) $row['id'], (int) $this->currentUser()->id())) {
        $count++;
      }
    }
    $this->messenger()->addStatus($this->t('已取消 @count 个尚未执行的任务。', ['@count' => $count]));
  }

  public function ignore(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer xedc ai reviews')) {
      return;
    }
    foreach ($this->selected($form_state) as $row) {
      $this->enqueuer->ignore((int) $row['id'], (int) $this->currentUser()->id());
    }
    $this->messenger()->addStatus($this->t('已忽略所选审核。内容没有被删除或发布。'));
  }

  /**
   * @return list<array<string, mixed>>
   *   Selected rows that still exist.
   */
  private function selected(FormStateInterface $form_state): array {
    $rows = [];
    foreach ((array) $form_state->getValue('reviews') as $id => $checked) {
      if (empty($checked)) {
        continue;
      }
      $row = $this->reviews->load((int) $id);
      if ($row !== NULL) {
        $rows[] = $row;
      }
    }
    return $rows;
  }

}
