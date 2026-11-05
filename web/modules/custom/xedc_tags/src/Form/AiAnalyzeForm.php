<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagAiAnalyzer;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagLabels;
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
    private TagLabels $labels,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.ai'),
      $container->get('xedc_tags.catalog'),
      $container->get('xedc_tags.tasks'),
      $container->get('xedc_tags.labels'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_ai';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('AI 语义分析'),
      'description' => $this->t('仅生成建议，不自动改标签，也不在页面暴露密钥。'),
    ]);
    $status = $this->ai->status();
    $form['notice'] = $this->callout(
      (string) $this->t('AI 只提出建议。管理员确认后才会执行变更。不会删除、合并或改写内容。API Key 由服务端私密保存，此处不显示。')
      . ' <strong>' . $this->t('当前状态') . '：</strong> ' . htmlspecialchars((string) $status['message'], ENT_QUOTES, 'UTF-8'),
      $status['ok'] ? 'info' : 'warning'
    );
    if (\Drupal::moduleHandler()->moduleExists('xedc_ai') && $this->currentUser()->hasPermission('administer xedc ai')) {
      $form['ai_link'] = [
        '#markup' => '<p class="xt-admin__note"><a href="' . Url::fromRoute('xedc_ai.overview')->toString() . '">' . $this->t('打开 AI 运营中心') . '</a></p>',
      ];
    }
    $options = [];
    foreach ($this->catalog->terms() as $term) {
      $options[(int) $term['tid']] = $term['name'] . ' (#' . $term['tid'] . '，已发布 ' . $term['published_any'] . ')';
    }

    $analyzeChildren = [
      'tid' => [
        '#type' => 'select',
        '#title' => $this->t('分析标签'),
        '#options' => $options,
        '#required' => TRUE,
      ],
      'confirm' => [
        '#type' => 'checkbox',
        '#title' => $this->t('我确认发起一次 AI 分析（会产生调用成本）'),
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xt-admin__actions']],
        'analyze' => [
          '#type' => 'submit',
          '#value' => $this->t('分析'),
          '#submit' => ['::analyze'],
          '#access' => $status['ok'] && $this->currentUser()->hasPermission('run xedc tag analysis'),
          '#attributes' => ['class' => ['button--primary']],
        ],
        'batch' => [
          '#type' => 'submit',
          '#value' => $this->t('排队批量分析（最多 5 个高频标签）'),
          '#submit' => ['::batch'],
          '#access' => $status['ok'] && $this->currentUser()->hasPermission('run xedc tag analysis'),
          '#limit_validation_errors' => [],
        ],
      ],
    ];
    $form['analyze_panel'] = $this->panel($analyzeChildren, ['xt-form-narrow']);

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
    $table = [
      '#type' => 'table',
      '#attributes' => ['class' => ['xt-table']],
      '#caption' => $this->t('最近 AI 建议（采纳只记日志，不自动改数据）'),
      '#header' => $header,
      '#empty' => $this->t('还没有 AI 建议。'),
    ];
    foreach ($this->ai->stored(0, 40) as $item) {
      $id = (int) ($item['id'] ?? 0);
      $rowStatus = (string) ($item['status'] ?? 'pending');
      $table[$id]['source'] = ['#plain_text' => (string) ($item['source_name'] ?? '')];
      $table[$id]['target'] = ['#plain_text' => (string) (($item['target_name'] ?? '') !== '' ? $item['target_name'] : '—')];
      $table[$id]['type'] = ['#plain_text' => (string) $this->labels->suggestionType((string) ($item['suggestion_type'] ?? ''))];
      $table[$id]['confidence'] = ['#plain_text' => (string) ($item['confidence'] ?? '')];
      $table[$id]['reason'] = ['#plain_text' => (string) ($item['reason'] ?? '')];
      $table[$id]['action'] = ['#plain_text' => (string) $this->labels->recommendedAction((string) ($item['recommended_action'] ?? ''))];
      $table[$id]['status'] = ['#markup' => $this->badge((string) $this->labels->status($rowStatus), $this->labels->statusTone($rowStatus))];
      if ($rowStatus === 'pending') {
        $table[$id]['ops'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['xt-ops']],
          'accept' => [
            '#type' => 'submit',
            '#value' => $this->t('接受'),
            '#name' => 'accept_' . $id,
            '#suggestion_id' => $id,
            '#decision' => 'accepted',
            '#submit' => ['::decide'],
            '#limit_validation_errors' => [],
          ],
          'reject' => [
            '#type' => 'submit',
            '#value' => $this->t('拒绝'),
            '#name' => 'reject_' . $id,
            '#suggestion_id' => $id,
            '#decision' => 'rejected',
            '#submit' => ['::decide'],
            '#limit_validation_errors' => [],
          ],
          'ignore' => [
            '#type' => 'submit',
            '#value' => $this->t('忽略'),
            '#name' => 'ignore_' . $id,
            '#suggestion_id' => $id,
            '#decision' => 'ignored',
            '#submit' => ['::decide'],
            '#limit_validation_errors' => [],
          ],
        ];
      }
      else {
        $table[$id]['ops'] = ['#markup' => '—'];
      }
    }
    $form['suggestions'] = $this->tableCard($table);
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
