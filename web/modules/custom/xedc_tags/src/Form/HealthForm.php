<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagHealthInspector;
use Drupal\xedc_tags\TagLabels;
use Drupal\xedc_tags\TagTaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the latest health report and can enqueue a rescan.
 */
final class HealthForm extends AdminFormBase {

  public function __construct(
    private TagHealthInspector $health,
    private TagTaskManager $tasks,
    private TagLabels $labels,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.health'),
      $container->get('xedc_tags.tasks'),
      $container->get('xedc_tags.labels'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_health';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('标签健康检查'),
      'description' => $this->t('检查孤立、异常和风险标签，不自动修改内容。'),
    ]);
    $report = $this->health->report();
    $form['intro'] = $this->callout((string) $this->t('健康检查只生成建议，不会自动删除或合并标签。高低频阈值按当前数据分布动态计算：低频 ≤ @low，高频 ≥ @high。', [
      '@low' => $report['threshold']['low'],
      '@high' => $report['threshold']['high'],
    ]));

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
    $statHtml = '<div class="xt-stat-grid" aria-label="' . $this->t('检查摘要') . '">';
    foreach ($metrics as [$label, $value]) {
      $statHtml .= '<div class="xt-stat"><span class="xt-stat__label">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</span>';
      $statHtml .= '<span class="xt-stat__value">' . (int) $value . '</span></div>';
    }
    $statHtml .= '</div>';
    $form['summary'] = [
      '#markup' => '<section class="xt-admin__section"><h2 class="xt-admin__section-title">' . $this->t('检查摘要') . '</h2>' . $statHtml . '</section>',
    ];

    if ($this->currentUser()->hasPermission('run xedc tag analysis')) {
      $form['actions_bar'] = $this->panel([
        'enqueue' => [
          '#type' => 'submit',
          '#value' => $this->t('排队重新扫描'),
          '#submit' => ['::enqueue'],
          '#attributes' => ['class' => ['button--primary']],
        ],
      ]);
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
        (string) $this->labels->healthCode((string) $issue['code']),
        [
          'data' => [
            '#markup' => $this->badge((string) $this->labels->severity((string) $issue['severity']), $this->labels->statusTone((string) $issue['severity'])),
          ],
        ],
        $issue['message'],
        $issue['action'],
      ];
    }
    $form['filters'] = [
      '#markup' => '<div class="xt-chip-row">' . implode('', [
        '<a class="xt-chip' . ($code === '' ? ' is-active' : '') . '" href="' . Url::fromRoute('xedc_tags.admin_health')->toString() . '">' . $this->t('全部') . '</a>',
        '<a class="xt-chip' . ($code === 'low_quality' ? ' is-active' : '') . '" href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'low_quality']])->toString() . '">' . $this->t('低质量') . '</a>',
        '<a class="xt-chip' . ($code === 'naming' ? ' is-active' : '') . '" href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'naming']])->toString() . '">' . $this->t('命名') . '</a>',
        '<a class="xt-chip' . ($code === 'orphan' ? ' is-active' : '') . '" href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'orphan']])->toString() . '">' . $this->t('孤立') . '</a>',
        '<a class="xt-chip' . ($code === 'low_frequency' ? ' is-active' : '') . '" href="' . Url::fromRoute('xedc_tags.admin_health', [], ['query' => ['code' => 'low_frequency']])->toString() . '">' . $this->t('低频') . '</a>',
      ]) . '</div>',
    ];
    $form['issues_wrap'] = $this->tableCard([
      '#type' => 'table',
      '#attributes' => ['class' => ['xt-table']],
      '#caption' => $this->t('问题明细'),
      '#header' => [$this->t('标签'), $this->t('类型'), $this->t('级别'), $this->t('说明'), $this->t('建议操作')],
      '#rows' => $issueRows,
      '#empty' => $this->t('没有发现问题。'),
    ]);
    return $form;
  }

  public function enqueue(array &$form, FormStateInterface $form_state): void {
    $result = $this->tasks->enqueue('health_scan', [], (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
