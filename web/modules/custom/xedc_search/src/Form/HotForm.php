<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_search\Query\QueryNormalizer;
use Drupal\xedc_search\Repository\KeywordRepository;
use Drupal\xedc_search\Repository\StatsRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adjust weight and status for real hot keywords.
 */
final class HotForm extends FormBase {

  public function __construct(
    private readonly StatsRepository $stats,
    private readonly KeywordRepository $keywords,
    private readonly QueryNormalizer $normalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_search.stats'),
      $container->get('xedc_search.keywords'),
      $container->get('xedc_search.normalizer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_search_hot';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $window = $this->normalizer->window((string) $this->getRequest()->query->get('window', 'week'));
    $form['#attributes']['class'][] = 'xedc-search-admin';
    $form['#attached']['library'][] = 'xedc_search/admin';
    $form['windows'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-search-admin__filters']],
    ];
    foreach (['today' => '今日', 'week' => '本周', 'month' => '本月'] as $id => $label) {
      $form['windows'][$id] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('xedc_search.admin.hot', [], ['query' => ['window' => $id]]),
        '#attributes' => ['class' => ['button', $window === $id ? 'button--primary' : '']],
      ];
    }
    try {
      $rows = $this->stats->hot($window, 50, TRUE);
      $error = FALSE;
    }
    catch (\Throwable $exception) {
      $this->logger('xedc_search')->error($exception->getMessage());
      $rows = [];
      $error = TRUE;
    }
    $form['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('关键词'),
        $this->t('搜索次数'),
        $this->t('独立用户'),
        $this->t('平均结果数'),
        $this->t('无结果次数'),
        $this->t('权重'),
        $this->t('启用'),
      ],
      '#empty' => $error ? $this->t('热门榜暂时读不出来。') : $this->t('这个周期还没有搜索记录。'),
      '#tree' => TRUE,
      '#prefix' => '<div class="xedc-search-admin__table-wrap">',
      '#suffix' => '</div>',
    ];
    foreach ($rows as $row) {
      $key = $row['keyword_key'];
      $form['table'][$key]['keyword'] = ['#markup' => Html::escape((string) $row['keyword'])];
      $form['table'][$key]['searches'] = ['#markup' => (string) (int) $row['searches']];
      $form['table'][$key]['users'] = ['#markup' => (string) (int) $row['users']];
      $form['table'][$key]['results'] = ['#markup' => (string) (int) $row['results']];
      $form['table'][$key]['zeros'] = ['#markup' => (string) (int) $row['zero_count']];
      $form['table'][$key]['weight'] = [
        '#type' => 'number',
        '#title' => $this->t('权重'),
        '#title_display' => 'invisible',
        '#min' => -100,
        '#max' => 100,
        '#default_value' => (int) $row['weight'],
      ];
      $form['table'][$key]['status'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('启用'),
        '#title_display' => 'invisible',
        '#default_value' => (int) $row['status'],
      ];
      $form['table'][$key]['label'] = [
        '#type' => 'value',
        '#value' => $row['keyword'],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('保存权重'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $table = $form_state->getValue('table') ?? [];
    if (!is_array($table)) {
      return;
    }
    foreach ($table as $key => $row) {
      if (!is_array($row)) {
        continue;
      }
      $keyword = $this->normalizer->clean((string) ($row['label'] ?? ''));
      if ($keyword === '') {
        continue;
      }
      $existing = $this->keywords->findByKey((string) $key);
      $this->keywords->save([
        'keyword' => $existing['keyword'] ?? $keyword,
        'label' => $existing['label'] ?? '',
        'weight' => $row['weight'] ?? 0,
        'status' => !empty($row['status']),
        'tags' => $existing['tags'] ?? '',
        'categories' => $existing['categories'] ?? '',
        'synonyms' => $existing ? ($this->keywords->load((int) $existing['id'])['synonyms'] ?? '') : '',
      ], $existing ? (int) $existing['id'] : NULL);
    }
    $this->messenger()->addStatus($this->t('已保存热门词权重。'));
  }

}
