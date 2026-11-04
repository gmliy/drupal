<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagAiAnalyzer;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagTaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manual AI analysis with human confirmation only.
 */
final class AiAnalyzeForm extends AdminFormBase {

  public function __construct(
    private TagAiAnalyzer $ai,
    private TagCatalog $catalog,
    private TagTaskManager $tasks,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.ai'),
      $container->get('xedc_tags.catalog'),
      $container->get('xedc_tags.tasks'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_ai';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form);
    $status = $this->ai->status();
    $form['status'] = [
      '#markup' => '<p><strong>' . $this->t('AI 状态') . '：</strong> ' . $status['message'] . '</p><p>' . $this->t('AI 只提出建议。不会删除、合并或改内容。API Key 由 xedc_ai 服务端保存，此处不显示。') . '</p>',
    ];
    if (\Drupal::moduleHandler()->moduleExists('xedc_ai') && $this->currentUser()->hasPermission('administer xedc ai')) {
      $form['ai_link'] = [
        '#markup' => '<p><a href="' . Url::fromRoute('xedc_ai.overview')->toString() . '">' . $this->t('打开 AI 服务设置') . '</a></p>',
      ];
    }
    $options = [];
    foreach ($this->catalog->terms() as $term) {
      $options[(int) $term['tid']] = $term['name'] . ' (#' . $term['tid'] . ', ' . $term['published_any'] . ')';
    }
    $form['tid'] = [
      '#type' => 'select',
      '#title' => $this->t('分析标签'),
      '#options' => $options,
      '#required' => TRUE,
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认发起一次 AI 分析（会产生调用成本）'),
    ];
    $form['analyze'] = [
      '#type' => 'submit',
      '#value' => $this->t('分析'),
      '#submit' => ['::analyze'],
      '#access' => $status['ok'] && $this->currentUser()->hasPermission('run xedc tag analysis'),
    ];
    $form['batch'] = [
      '#type' => 'submit',
      '#value' => $this->t('排队批量分析（最多 5 个高频标签）'),
      '#submit' => ['::batch'],
      '#access' => $status['ok'] && $this->currentUser()->hasPermission('run xedc tag analysis'),
      '#limit_validation_errors' => [],
    ];
    $header = [
      $this->t('原标签'),
      $this->t('目标'),
      $this->t('类型'),
      $this->t('置信度'),
      $this->t('原因'),
      $this->t('推荐操作'),
      $this->t('状态'),
      $this->t('人工确认'),
    ];
    $form['table'] = ['#type' => 'table', '#caption' => $this->t('最近 AI 建议（采纳只记日志，不自动改数据）'), '#header' => $header, '#empty' => $this->t('还没有 AI 建议。')];
    foreach ($this->ai->stored(0, 40) as $item) {
      $id = (int) ($item['id'] ?? 0);
      $form['table'][$id]['source'] = ['#plain_text' => (string) ($item['source_name'] ?? '')];
      $form['table'][$id]['target'] = ['#plain_text' => (string) ($item['target_name'] ?? '')];
      $form['table'][$id]['type'] = ['#plain_text' => (string) ($item['suggestion_type'] ?? '')];
      $form['table'][$id]['confidence'] = ['#plain_text' => (string) ($item['confidence'] ?? '')];
      $form['table'][$id]['reason'] = ['#plain_text' => (string) ($item['reason'] ?? '')];
      $form['table'][$id]['action'] = ['#plain_text' => (string) ($item['recommended_action'] ?? '')];
      $form['table'][$id]['status'] = ['#plain_text' => (string) ($item['status'] ?? 'pending')];
      if (($item['status'] ?? '') === 'pending') {
        $form['table'][$id]['ops'] = [
          'accept' => [
            '#type' => 'submit',
            '#value' => $this->t('采纳记录'),
            '#name' => 'accept_' . $id,
            '#suggestion_id' => $id,
            '#decision' => 'accepted',
            '#submit' => ['::decide'],
            '#limit_validation_errors' => [],
          ],
          'reject' => [
            '#type' => 'submit',
            '#value' => $this->t('忽略'),
            '#name' => 'reject_' . $id,
            '#suggestion_id' => $id,
            '#decision' => 'ignored',
            '#submit' => ['::decide'],
            '#limit_validation_errors' => [],
          ],
        ];
      }
      else {
        $form['table'][$id]['ops'] = ['#markup' => ''];
      }
    }
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement()['#submit'][0] ?? '';
    if ($trigger === '::analyze' && !$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('请先确认。'));
    }
  }

  public function analyze(array &$form, FormStateInterface $form_state): void {
    $result = $this->ai->analyzeTerm((int) $form_state->getValue('tid'), (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function batch(array &$form, FormStateInterface $form_state): void {
    $result = $this->tasks->enqueue('ai_batch', [], (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function decide(array &$form, FormStateInterface $form_state): void {
    $id = (int) ($form_state->getTriggeringElement()['#suggestion_id'] ?? 0);
    $decision = (string) ($form_state->getTriggeringElement()['#decision'] ?? 'ignored');
    $result = $this->ai->decide($id, $decision, (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
