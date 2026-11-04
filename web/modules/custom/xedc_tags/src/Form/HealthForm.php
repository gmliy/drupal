<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagHealthInspector;
use Drupal\xedc_tags\TagTaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the latest health report and can enqueue a rescan.
 */
final class HealthForm extends AdminFormBase {

  public function __construct(
    private TagHealthInspector $health,
    private TagTaskManager $tasks,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.health'),
      $container->get('xedc_tags.tasks'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_health';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form);
    $report = $this->health->report();
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('健康检查只生成建议，不会自动删除或合并标签。高低频阈值按当前数据分布动态计算：低频 ≤ @low，高频 ≥ @high。', [
        '@low' => $report['threshold']['low'],
        '@high' => $report['threshold']['high'],
      ]) . '</p>',
    ];
    $metrics = [
      [$this->t('问题总数'), $report['totals']['issues']],
      [$this->t('低质量'), $report['totals']['low_quality']],
      [$this->t('命名问题'), $report['totals']['naming']],
      [$this->t('孤立/未使用'), $report['totals']['orphan']],
      [$this->t('完全重复组'), $report['totals']['exact_duplicate_groups']],
      [$this->t('相似组'), $report['totals']['similar_groups']],
      [$this->t('高频'), $report['trend']['high']],
      [$this->t('中频'), $report['trend']['mid']],
      [$this->t('低频'), $report['trend']['low']],
      [$this->t('长期无增长'), $report['trend']['stale']],
    ];
    $rows = [];
    foreach ($metrics as [$label, $value]) {
      $rows[] = [$label, $value];
    }
    $form['summary'] = [
      '#type' => 'table',
      '#caption' => $this->t('检查摘要'),
      '#header' => [$this->t('指标'), $this->t('数量')],
      '#rows' => $rows,
    ];
    if ($this->currentUser()->hasPermission('run xedc tag analysis')) {
      $form['enqueue'] = [
        '#type' => 'submit',
        '#value' => $this->t('排队重新扫描'),
        '#submit' => ['::enqueue'],
      ];
    }
    $code = (string) $this->getRequest()->query->get('code', '');
    $issueRows = [];
    foreach ($report['issues'] as $issue) {
      if ($code !== '' && $issue['code'] !== $code) {
        continue;
      }
      $issueRows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $issue['name'],
            '#url' => Url::fromRoute('xedc_tags.admin_term', ['taxonomy_term' => $issue['tid']]),
          ],
        ],
        $issue['code'],
        $issue['severity'],
        $issue['message'],
        $issue['action'],
      ];
    }
    $form['filters'] = [
      '#markup' => '<p class="xt-admin-links">' . implode(' · ', [
        '<a href="' . Url::fromRoute('xedc_tags.admin_health')->toString() . '">' . $this->t('全部') . '</a>',
        '<a href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'low_quality']])->toString() . '">' . $this->t('低质量') . '</a>',
        '<a href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'naming']])->toString() . '">' . $this->t('命名') . '</a>',
        '<a href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'orphan']])->toString() . '">' . $this->t('孤立') . '</a>',
        '<a href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'low_frequency']])->toString() . '">' . $this->t('低频') . '</a>',
      ]) . '</p>',
    ];
    $form['issues'] = [
      '#type' => 'table',
      '#caption' => $this->t('问题明细'),
      '#header' => [$this->t('标签'), $this->t('类型'), $this->t('级别'), $this->t('说明'), $this->t('建议操作')],
      '#rows' => $issueRows,
      '#empty' => $this->t('没有发现问题。'),
    ];
    return $form;
  }

  public function enqueue(array &$form, FormStateInterface $form_state): void {
    $result = $this->tasks->enqueue('health_scan', [], (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
