<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_search\Query\QueryNormalizer;
use Drupal\xedc_search\Repository\KeywordRepository;
use Drupal\xedc_search\Repository\StatsRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Search admin pages that are not forms.
 */
final class AdminController extends ControllerBase {

  public function __construct(
    private readonly StatsRepository $stats,
    private readonly KeywordRepository $keywords,
    private readonly QueryNormalizer $normalizer,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_search.stats'),
      $container->get('xedc_search.keywords'),
      $container->get('xedc_search.normalizer'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function overview(): array {
    try {
      $stats = $this->stats->dashboard();
      $error = '';
    }
    catch (\Throwable $exception) {
      $this->getLogger('xedc_search')->error($exception->getMessage());
      $stats = [
        'today' => 0,
        'week' => 0,
        'month' => 0,
        'zero' => 0,
        'trend' => [],
        'types' => [],
        'hot' => [],
      ];
      $error = '统计暂时读不出来。搜索功能本身不受影响。';
    }
    $max = 1;
    foreach ($stats['trend'] as $point) {
      $max = max($max, (int) $point['count']);
    }
    foreach ($stats['trend'] as &$point) {
      $point['width'] = (int) round(((int) $point['count'] / $max) * 100);
    }
    unset($point);
    $typeMax = 1;
    foreach ($stats['types'] as $type) {
      $typeMax = max($typeMax, (int) $type['count']);
    }
    $labels = [
      'all' => '全部',
      'photo' => '美图',
      'video' => '视频',
      'article' => '文章',
      'model' => '人物',
      'tag' => '标签',
      'category' => '分类',
    ];
    foreach ($stats['types'] as &$type) {
      $type['label'] = $labels[$type['type']] ?? $type['type'];
      $type['width'] = (int) round(((int) $type['count'] / $typeMax) * 100);
    }
    unset($type);
    return [
      '#theme' => 'xedc_search_admin_overview',
      '#stats' => $stats,
      '#error' => $error,
      '#attached' => ['library' => ['xedc_search/admin']],
      '#cache' => [
        'max-age' => 60,
        'tags' => ['xedc_search'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function keywords(Request $request): array {
    $q = $this->normalizer->clean((string) $request->query->get('q', ''));
    $status = (string) $request->query->get('status', 'all');
    if (!in_array($status, ['all', '1', '0'], TRUE)) {
      $status = 'all';
    }
    $page = max(0, (int) $request->query->get('page', 0));
    $limit = 20;
    $result = $this->keywords->adminList($q, $status === 'all' ? '' : $status, $limit, $page * $limit);
    $this->pagerManager->createPager($result['total'], $limit);
    $rows = [];
    foreach ($result['rows'] as $row) {
      $id = (int) $row['id'];
      $rows[] = [
        'keyword' => $row['keyword'],
        'label' => $row['label'],
        'weight' => (int) $row['weight'],
        'status' => (int) $row['status'] ? '启用' : '停用',
        'status_on' => (bool) $row['status'],
        'tags' => $this->termLabels((string) $row['tags']),
        'categories' => $this->termLabels((string) $row['categories']),
        'edit_url' => Url::fromRoute('xedc_search.admin.keyword_edit', ['id' => $id])->toString(),
        'delete_url' => Url::fromRoute('xedc_search.admin.keyword_delete', ['id' => $id])->toString(),
      ];
    }
    return [
      '#theme' => 'xedc_search_admin_keywords',
      '#rows' => $rows,
      '#total' => $result['total'],
      '#q' => $q,
      '#status' => $status,
      '#empty' => $result['total'] === 0,
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['xedc_search/admin', 'core/drupal.dialog.ajax']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function history(Request $request): array {
    $q = $this->normalizer->clean((string) $request->query->get('q', ''));
    $zero = (string) $request->query->get('zero', '') === '1';
    $page = max(0, (int) $request->query->get('page', 0));
    $limit = 20;
    try {
      $result = $this->stats->analysis($q, $zero, $limit, $page * $limit);
      $error = '';
    }
    catch (\Throwable $exception) {
      $this->getLogger('xedc_search')->error($exception->getMessage());
      $result = ['rows' => [], 'total' => 0];
      $error = '历史分析暂时读不出来。';
    }
    $this->pagerManager->createPager($result['total'], $limit);
    $rows = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [
        'keyword' => $row['keyword'],
        'searches' => (int) $row['searches'],
        'results' => (int) $row['results'],
        'zeros' => (int) $row['zeros'],
        'last' => $this->dateFormatter->format((int) $row['last_created'], 'custom', 'Y-m-d H:i'),
      ];
    }
    return [
      '#theme' => 'xedc_search_admin_history',
      '#rows' => $rows,
      '#total' => $result['total'],
      '#q' => $q,
      '#zero' => $zero,
      '#error' => $error,
      '#empty' => $rows === [] && $error === '',
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['xedc_search/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  private function termLabels(string $ids): string {
    $tids = array_values(array_filter(array_map('intval', explode(',', $ids))));
    if (!$tids) {
      return '';
    }
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($tids);
    $names = [];
    foreach ($tids as $tid) {
      $term = $terms[$tid] ?? NULL;
      if ($term instanceof TermInterface) {
        $names[] = $term->label();
      }
    }
    return implode('、', $names);
  }

}
