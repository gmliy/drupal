<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_search\Query\QueryNormalizer;
use Drupal\xedc_search\Repository\KeywordRepository;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Published-content search. Titles and terms use bound LIKE patterns.
 */
final class SearchEngine {

  /**
   * @var array<string, array<string, int>>
   */
  private array $countCache = [];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountProxyInterface $currentUser,
    private readonly QueryNormalizer $normalizer,
    private readonly KeywordRepository $keywords,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @param array<string, mixed> $filters
   * @return array{nids: list<int>, total: int, limit: int}
   */
  public function page(string $q, string $type, string $sort, int $page, array $filters = []): array {
    $empty = ['nids' => [], 'total' => 0, 'limit' => $this->pageSize()];
    $q = $this->normalizer->clean($q);
    if (!$this->enabled() || mb_strlen($q) < $this->minLength()) {
      return $empty;
    }
    $type = $this->normalizer->type($type);
    if (!in_array($type, ['all', 'photo', 'video', 'article'], TRUE)) {
      return $empty;
    }
    $sort = $this->normalizer->sort($sort);
    $page = max(0, min(1000, $page));
    $limit = $this->pageSize();
    $cid = $this->cid('page', [$q, $type, $sort, $page, $limit, $filters]);
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    try {
      $total = $this->count($q, $type, $filters);
      $query = $this->matchQuery($q, $this->bundles($type), $filters);
      $query->addField('n', 'nid');
      $query->groupBy('n.nid');
      if ($sort === 'latest') {
        $query->addExpression('MAX(n.created)', 'created_sort');
        $query->orderBy('created_sort', 'DESC');
      }
      elseif ($sort === 'hot' && $this->database->schema()->tableExists('node_counter')) {
        $query->leftJoin('node_counter', 'nc', 'nc.nid = n.nid');
        $query->addExpression('COALESCE(MAX(nc.totalcount), 0)', 'hits');
        $query->addExpression('MAX(n.created)', 'created_sort');
        $query->orderBy('hits', 'DESC');
        $query->orderBy('created_sort', 'DESC');
      }
      else {
        $score = $this->scoreExpression($q);
        $query->addExpression($score['sql'], 'score', $score['args']);
        $query->addExpression('MAX(n.created)', 'created_sort');
        $query->orderBy('score', 'DESC');
        $query->orderBy('created_sort', 'DESC');
      }
      $query->range($page * $limit, $limit);
      $nids = array_map('intval', $query->execute()->fetchCol());
      $result = ['nids' => $nids, 'total' => $total, 'limit' => $limit];
      $this->cache->set($cid, $result, $this->expire(), ['xedc_search', 'node_list']);
      return $result;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Search page failed: @message', ['@message' => $exception->getMessage()]);
      throw $exception;
    }
  }

  /**
   * @param array<string, mixed> $filters
   */
  public function count(string $q, string $type, array $filters = []): int {
    $counts = $this->counts($q, $filters);
    return $counts[$this->normalizer->type($type)] ?? 0;
  }

  /**
   * @param array<string, mixed> $filters
   * @return array<string, int>
   */
  public function counts(string $q, array $filters = []): array {
    $base = ['all' => 0, 'photo' => 0, 'video' => 0, 'article' => 0];
    $q = $this->normalizer->clean($q);
    if (!$this->enabled() || mb_strlen($q) < $this->minLength()) {
      return $base;
    }
    $memory = $this->normalizer->key($q) . ':' . md5(serialize($filters));
    if (isset($this->countCache[$memory])) {
      return $this->countCache[$memory];
    }
    $cid = $this->cid('counts', [$q, $filters]);
    if ($cached = $this->cache->get($cid)) {
      $this->countCache[$memory] = $cached->data;
      return $cached->data;
    }
    $query = $this->matchQuery($q, ['photo', 'video', 'article'], $filters);
    $query->addField('n', 'type');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    $query->groupBy('n.type');
    foreach ($query->execute() as $row) {
      $bundle = (string) $row->type;
      if (isset($base[$bundle])) {
        $base[$bundle] = (int) $row->cnt;
        $base['all'] += (int) $row->cnt;
      }
    }
    $this->countCache[$memory] = $base;
    $this->cache->set($cid, $base, $this->expire(), ['xedc_search', 'node_list']);
    return $base;
  }

  /**
   * @param list<string> $vids
   * @return array{total: int, items: list<array<string, mixed>>}
   */
  public function terms(string $q, array $vids, int $limit, int $offset): array {
    $q = $this->normalizer->clean($q);
    if ($q === '' || !$vids) {
      return ['total' => 0, 'items' => []];
    }
    $langs = $this->languages();
    $like = '%' . $this->database->escapeLike($q) . '%';
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->condition('t.status', 1);
    $query->condition('t.vid', $vids, 'IN');
    $query->condition('t.langcode', $langs, 'IN');
    $query->condition('t.name', $like, 'LIKE');
    $query->addField('t', 'tid');
    $query->groupBy('t.tid');
    $count = (int) (clone $query)->countQuery()->execute()->fetchField();
    $query->addExpression('MAX(t.name)', 'name');
    $query->addExpression('MAX(t.vid)', 'vid');
    $query->orderBy('name', 'ASC');
    $query->range(max(0, $offset), max(1, $limit));
    $items = [];
    foreach ($query->execute() as $row) {
      $items[] = [
        'id' => (int) $row->tid,
        'label' => (string) $row->name,
        'vid' => (string) $row->vid,
        'url' => $this->termUrl((int) $row->tid, (string) $row->vid),
      ];
    }
    return ['total' => $count, 'items' => $items];
  }

  /**
   * @param list<int> $nids
   * @return list<array<string, mixed>>
   */
  public function items(array $nids): array {
    if (!$nids) {
      return [];
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $items = [];
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node instanceof NodeInterface || !$node->isPublished() || !$node->access('view')) {
        continue;
      }
      $translated = $this->entityRepository->getTranslationFromContext($node);
      $items[] = [
        'id' => (int) $node->id(),
        'type' => $node->bundle(),
        'title' => (string) $translated->label(),
        'url' => $node->toUrl()->toString(),
        'created' => (int) $node->getCreatedTime(),
      ];
    }
    return $items;
  }

  /**
   * @param list<string> $bundles
   * @param array<string, mixed> $filters
   */
  private function matchQuery(string $q, array $bundles, array $filters): SelectInterface {
    $query = $this->database->select('node_field_data', 'n');
    $query->addTag('node_access');
    $query->addMetaData('base_table', 'node_field_data');
    $query->condition('n.status', 1);
    $query->condition('n.type', $bundles, 'IN');
    $query->condition('n.langcode', $this->languages(), 'IN');
    $expansion = $this->keywords->expansion($q);
    $or = $query->orConditionGroup();
    $or->condition('n.title', '%' . $this->database->escapeLike($q) . '%', 'LIKE');
    foreach ($expansion['synonyms'] as $synonym) {
      $or->condition('n.title', '%' . $this->database->escapeLike($synonym) . '%', 'LIKE');
    }
    $terms = $this->matchingTermIds($q);
    $tags = array_values(array_unique(array_merge($terms['tags'], $expansion['tags'])));
    $categories = array_values(array_unique(array_merge($terms['categories'], $expansion['categories'])));
    if ($tags) {
      $or->exists($this->termExists('node__field_tags', 'field_tags_target_id', $tags));
    }
    if ($terms['models']) {
      $or->exists($this->termExists('node__field_model', 'field_model_target_id', $terms['models']));
    }
    if ($categories) {
      $or->exists($this->termExists('node__field_categories', 'field_categories_target_id', $categories));
    }
    $query->condition($or);
    $category = (int) ($filters['category'] ?? 0);
    $tag = (int) ($filters['tag'] ?? 0);
    $model = (int) ($filters['model'] ?? 0);
    if ($category > 0) {
      $query->exists($this->termExists('node__field_categories', 'field_categories_target_id', [$category]));
    }
    if ($tag > 0) {
      $query->exists($this->termExists('node__field_tags', 'field_tags_target_id', [$tag]));
    }
    if ($model > 0) {
      $query->exists($this->termExists('node__field_model', 'field_model_target_id', [$model]));
    }
    [$from, $to] = $this->range($filters);
    if ($from) {
      $query->condition('n.created', $from, '>=');
    }
    if ($to) {
      $query->condition('n.created', $to, '<=');
    }
    return $query;
  }

  /**
   * @return array{sql: string, args: array<string, string>}
   */
  private function scoreExpression(string $q): array {
    return [
      'sql' => 'MAX(CASE WHEN n.title LIKE :xs_pre THEN 100 WHEN n.title LIKE :xs_like THEN 60 ELSE 30 END)',
      'args' => [
        ':xs_pre' => $this->database->escapeLike($q) . '%',
        ':xs_like' => '%' . $this->database->escapeLike($q) . '%',
      ],
    ];
  }

  /**
   * @param list<int> $ids
   */
  private function termExists(string $table, string $column, array $ids): SelectInterface {
    $sub = $this->database->select($table, 'ref');
    $sub->addExpression('1');
    $sub->where('[ref].[entity_id] = [n].[nid]');
    $sub->condition('ref.deleted', 0);
    $sub->condition('ref.' . $column, $ids, 'IN');
    return $sub;
  }

  /**
   * @return array{tags: list<int>, models: list<int>, categories: list<int>}
   */
  private function matchingTermIds(string $q): array {
    $found = ['tags' => [], 'models' => [], 'categories' => []];
    $like = '%' . $this->database->escapeLike($q) . '%';
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->fields('t', ['tid', 'vid']);
    $query->condition('t.status', 1);
    $query->condition('t.langcode', $this->languages(), 'IN');
    $query->condition('t.vid', ['tags', 'models', 'photo_category', 'video_category', 'article_category'], 'IN');
    $query->condition('t.name', $like, 'LIKE');
    $query->range(0, 30);
    foreach ($query->execute() as $row) {
      $id = (int) $row->tid;
      if ($row->vid === 'tags') {
        $found['tags'][] = $id;
      }
      elseif ($row->vid === 'models') {
        $found['models'][] = $id;
      }
      else {
        $found['categories'][] = $id;
      }
    }
    return $found;
  }

  /**
   * @param array<string, mixed> $filters
   * @return array{0: int, 1: int}
   */
  private function range(array $filters): array {
    $from = $this->stamp((string) ($filters['from'] ?? ''), FALSE);
    $to = $this->stamp((string) ($filters['to'] ?? ''), TRUE);
    if ($from && $to && $from > $to) {
      return [$to, $from];
    }
    return [$from, $to];
  }

  private function stamp(string $day, bool $end): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
      return 0;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone(date_default_timezone_get()));
    if (!$date || $date->format('Y-m-d') !== $day) {
      return 0;
    }
    if ($end) {
      $date = $date->setTime(23, 59, 59);
    }
    return $date->getTimestamp();
  }

  private function termUrl(int $tid, string $vid): string {
    if ($vid === 'models' && $this->moduleHandler->moduleExists('xedc_models')) {
      return \Drupal\Core\Url::fromRoute('xedc_models.detail', ['taxonomy_term' => $tid])->toString();
    }
    return \Drupal\Core\Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid])->toString();
  }

  /**
   * @return list<string>
   */
  private function bundles(string $type): array {
    return in_array($type, ['photo', 'video', 'article'], TRUE) ? [$type] : ['photo', 'video', 'article'];
  }

  /**
   * @return list<string>
   */
  private function languages(): array {
    return array_values(array_unique([
      $this->languageManager->getCurrentLanguage()->getId(),
      $this->languageManager->getDefaultLanguage()->getId(),
    ]));
  }

  /**
   * @param array<int|string, mixed> $parts
   */
  private function cid(string $name, array $parts): string {
    $account = $this->currentUser->getAccount();
    $scope = $account->hasPermission('bypass node access') ? 'bypass:' . $account->id() : 'public';
    $grants = $this->moduleHandler->invokeAll('node_grants', [$account, 'view']);
    return 'xedc_search:' . $name . ':' . hash('sha256', serialize([$parts, $scope, $grants, $this->languages()]));
  }

  private function enabled(): bool {
    return (bool) $this->configFactory->get('xedc_search.settings')->get('enabled');
  }

  private function minLength(): int {
    return max(1, (int) $this->configFactory->get('xedc_search.settings')->get('min_length'));
  }

  private function pageSize(): int {
    return max(1, min(24, (int) $this->configFactory->get('xedc_search.settings')->get('page_size') ?: 12));
  }

  private function expire(): int {
    $seconds = max(0, (int) $this->configFactory->get('xedc_search.settings')->get('cache_seconds'));
    return $this->time() + $seconds;
  }

  private function time(): int {
    return \Drupal::time()->getRequestTime();
  }

}
