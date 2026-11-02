<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_ai\Review\DuplicateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queues local hash scans. The request does not hash the files itself.
 */
final class DuplicateScanForm extends FormBase {

  public function __construct(
    private readonly DuplicateFinder $duplicates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_ai.duplicates'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_duplicate_scan';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['nids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('内容 ID'),
      '#description' => $this->t('多个 ID 用逗号分隔，一次最多 20 个。任务进入本地扫描队列，不调用模型，也不在这个请求里读取全部文件。当前队列中有 @count 个任务。', [
        '@count' => $this->duplicates->queueSize(),
      ]),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('加入本地扫描'),
      '#access' => $this->currentUser()->hasPermission('execute xedc ai reviews'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ids = $this->ids((string) $form_state->getValue('nids'));
    if ($ids === []) {
      $form_state->setErrorByName('nids', $this->t('请填写至少一个内容 ID。'));
    }
    elseif (count($ids) > 20) {
      $form_state->setErrorByName('nids', $this->t('一次最多加入 20 个内容。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $ids = array_slice($this->ids((string) $form_state->getValue('nids')), 0, 20);
    foreach ($ids as $nid) {
      $this->duplicates->enqueue($nid);
    }
    $this->messenger()->addStatus($this->t('已加入 @count 个本地扫描任务。', ['@count' => count($ids)]));
  }

  /**
   * @return list<int>
   *   Positive node ids.
   */
  private function ids(string $value): array {
    $ids = [];
    foreach (preg_split('/[^\d]+/', $value) ?: [] as $part) {
      $id = (int) $part;
      if ($id > 0) {
        $ids[$id] = $id;
      }
    }
    return array_values($ids);
  }

}
