<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_core\Video\VideoCards;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Public tag index and detail listings.
 */
final class TagListing {

  private const INDEX_PAGE = 24;
  private const TERM_PAGE = 12;

  public function __construct(
    private Connection $database,
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityRepositoryInterface $repository,
    private TagCatalog $catalog,
    private TagRelations $relations,
    private TagAudit $audit,
    private VideoCards $videoCards,
    private FileUrlGeneratorInterface $fileUrls,
    private DateFormatterInterface $dates,
    private PagerManagerInterface $pagerManager,
    private RequestStack $requests,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function index(): array {
    $request = $this->requests->getCurrentRequest();
    $q = trim((string) $request?->query->get('q', ''));
    $sort = (string) $request?->query->get('sort', 'count');
    $type = (string) $request?->query->get('type', '');
    $summary = $this->catalog->summary();
    $allowed = ['count', 'name', 'new'];
    if ($summary['views_available']) {
      $allowed[] = 'hot';
    }
    if (!in_array($sort, $allowed, TRUE)) {
      $sort = 'count';
    }
    if (!in_array($type, ['photo', 'video', 'article'], TRUE)) {
      $type = '';
    }
    $rows = [];
    foreach ($this->catalog->terms() as $term) {
      if (!(int) $term['status'] || (int) $term['published'] < 1) {
        continue;
      }
      if ($type !== '' && (int) ($term[$type . '_pub'] ?? $term[$type] ?? 0) < 1) {
        continue;
      }
      if ($q !== '' && !$this->matches($term, $q)) {
        continue;
      }
      $rows[] = $term;
    }
    usort($rows, fn(array $a, array $b): int => $this->compareIndex($a, $b, $sort));
    $pager = $this->pagerManager->createPager(count($rows), self::INDEX_PAGE);
    $page = $pager->getCurrentPage();
    $slice = array_slice($rows, $page * self::INDEX_PAGE, self::INDEX_PAGE);
    $popular = $this->catalog->terms();
    $popular = array_values(array_filter($popular, static fn(array $term): bool => (int) $term['status'] && (int) $term['published'] > 0));
    usort($popular, fn(array $a, array $b): int => $this->compareIndex($a, $b, $summary['views_available'] ? 'hot' : 'count'));
    $popular = array_slice($popular, 0, 10);
    $newest = $this->catalog->terms();
    $newest = array_values(array_filter($newest, static fn(array $term): bool => (int) $term['status'] && (int) $term['published'] > 0));
    usort($newest, fn(array $a, array $b): int => $this->compareIndex($a, $b, 'new'));
    $newest = array_slice($newest, 0, 8);
    $query = array_filter(['q' => $q, 'type' => $type], static fn($v): bool => $v !== '');
    $sortUrl = function (string $key) use ($query): string {
      $params = $query;
      if ($key !== 'count') {
        $params['sort'] = $key;
      }
      return Url::fromRoute('xedc_tags.index', [], ['query' => $params])->toString();
    };
    $typeUrl = function (string $bundle) use ($q, $sort): string {
      $params = [];
      if ($q !== '') {
        $params['q'] = $q;
      }
      if ($bundle !== '') {
        $params['type'] = $bundle;
      }
      if ($sort !== 'count') {
        $params['sort'] = $sort;
      }
      return Url::fromRoute('xedc_tags.index', [], ['query' => $params])->toString();
    };
    $typeFilters = [
      ['id' => '', 'label' => '全部', 'url' => $typeUrl(''), 'active' => $type === ''],
      ['id' => 'photo', 'label' => '图片', 'url' => $typeUrl('photo'), 'active' => $type === 'photo'],
      ['id' => 'video', 'label' => '视频', 'url' => $typeUrl('video'), 'active' => $type === 'video'],
      ['id' => 'article', 'label' => '文章', 'url' => $typeUrl('article'), 'active' => $type === 'article'],
    ];
    $clearQuery = array_filter(['type' => $type, 'sort' => $sort === 'count' ? '' : $sort], static fn($v): bool => $v !== '');
    $tags = array_map(fn(array $term): array => $this->chip($term), $slice);
    return [
      'page' => [
        '#theme' => 'xedc_tags_index',
        '#tags' => $tags,
        '#popular' => array_map(fn(array $term): array => $this->chip($term), $popular),
        '#newest' => array_map(fn(array $term): array => $this->chip($term), $newest),
        '#total_tags' => count($rows),
        '#total_items' => $this->publishedInLanguage(),
        '#q' => $q,
        '#sort' => $sort,
        '#type' => $type,
        '#types' => $typeFilters,
        '#sorts' => $this->indexSorts($summary['views_available'], $sort, $sortUrl),
        '#clear_url' => Url::fromRoute('xedc_tags.index', [], ['query' => $clearQuery])->toString(),
        '#index_url' => Url::fromRoute('xedc_tags.index')->toString(),
        '#home_url' => Url::fromRoute('<front>')->toString(),
        '#attached' => ['library' => ['xedc_tags/xedc_tags']],
      ],
      'pager' => ['#type' => 'pager', '#quantity' => 5],
      '#cache' => [
        'tags' => $this->catalog->cacheTags(),
        'contexts' => ['url.query_args', 'languages:language_content', 'languages:language_interface', 'user.permissions'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function term(TermInterface $term): array {
    $term = $this->repository->getTranslationFromContext($term);
    $tid = (int) $term->id();
    $request = $this->requests->getCurrentRequest();
    $sort = (string) $request?->query->get('sort', 'new');
    $type = (string) $request?->query->get('type', '');
    $hot = $this->database->schema()->tableExists('node_counter');
    $allowedSort = $hot ? ['new', 'old', 'hot'] : ['new', 'old'];
    if (!in_array($sort, $allowedSort, TRUE)) {
      $sort = 'new';
    }
    if (!in_array($type, ['photo', 'video', 'article'], TRUE)) {
      $type = '';
    }
    $language = $this->catalog->contentLanguage();
    $counts = $this->typeCounts($tid, $language);
    $available = array_values(array_filter(['photo', 'video', 'article'], static fn(string $bundle): bool => ($counts[$bundle] ?? 0) > 0));
    $query = $this->database->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1 AND n.langcode = :lang', [':lang' => $language]);
    $query->condition('t.field_tags_target_id', $tid);
    $query->condition('t.deleted', 0);
    if ($type !== '') {
      $query->condition('n.type', $type);
    }
    $query->addField('n', 'nid', 'nid');
    $query->addField('n', 'type', 'type');
    $query->addExpression('MAX(n.created)', 'created');
    $query->groupBy('n.nid');
    $query->groupBy('n.type');
    if ($hot) {
      $query->leftJoin('node_counter', 'c', 'c.nid = n.nid');
      $query->addExpression('COALESCE(MAX(c.totalcount), 0)', 'hot');
    }
    if ($sort === 'hot' && $hot) {
      $query->orderBy('hot', 'DESC');
      $query->orderBy('created', 'DESC');
    }
    elseif ($sort === 'old') {
      $query->orderBy('created', 'ASC');
    }
    else {
      $query->orderBy('created', 'DESC');
    }
    $query->orderBy('n.nid', $sort === 'old' ? 'ASC' : 'DESC');
    /** @var \Drupal\Core\Database\Query\PagerSelectExtender $pager */
    $pager = $query->extend(PagerSelectExtender::class)->limit(self::TERM_PAGE);
    $result = $pager->execute()->fetchAll();
    $nids = array_map(static fn(object $row): int => (int) $row->nid, $result);
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $ordered = [];
    foreach ($result as $row) {
      $node = $nodes[(int) $row->nid] ?? NULL;
      if ($node instanceof NodeInterface && $node->access('view')) {
        $ordered[] = $this->repository->getTranslationFromContext($node);
      }
    }
    $description = '';
    if (!$term->get('description')->isEmpty()) {
      $raw = trim(strip_tags((string) $term->get('description')->value));
      if ($raw !== '') {
        $description = check_markup((string) $term->get('description')->value, (string) $term->get('description')->format);
      }
    }
    $base = [];
    if ($type !== '') {
      $base['type'] = $type;
    }
    $sortUrl = function (string $key) use ($term, $base): string {
      $query = $base;
      if ($key !== 'new') {
        $query['sort'] = $key;
      }
      return $term->toUrl('canonical', ['query' => $query])->toString();
    };
    $filterUrl = function (string $bundle) use ($term, $sort): string {
      $query = [];
      if ($bundle !== '') {
        $query['type'] = $bundle;
      }
      if ($sort !== 'new') {
        $query['sort'] = $sort;
      }
      return $term->toUrl('canonical', ['query' => $query])->toString();
    };
    $filters = [];
    if (count($available) > 1) {
      $filters[] = ['id' => '', 'label' => '全部', 'count' => array_sum($counts), 'url' => $filterUrl(''), 'active' => $type === ''];
      $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
      foreach ($available as $bundle) {
        $filters[] = [
          'id' => $bundle,
          'label' => $labels[$bundle],
          'count' => $counts[$bundle],
          'url' => $filterUrl($bundle),
          'active' => $type === $bundle,
        ];
      }
    }
    $notice = '';
    $noticeUrl = '';
    $merge = $this->audit->sourceNotice($tid);
    if ($merge) {
      $target = $this->catalog->terms()[(int) $merge->target_tid] ?? NULL;
      if ($target) {
        $notice = (string) $target['name'];
        $noticeUrl = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => (int) $merge->target_tid])->toString();
      }
    }
    $libraries = ['xedc_tags/xedc_tags'];
    $groups = $this->groups($ordered, $libraries);
    return [
      'page' => [
        '#theme' => 'xedc_tags_term',
        '#term_name' => $term->label(),
        '#description' => $description,
        '#total' => array_sum($counts),
        '#counts' => $counts,
        '#filters' => $filters,
        '#groups' => $groups,
        '#sort' => $sort,
        '#sorts' => $this->termSorts($hot, $sort, $sortUrl),
        '#related' => $this->related($tid),
        '#notice' => $notice,
        '#notice_url' => $noticeUrl,
        '#index_url' => Url::fromRoute('xedc_tags.index')->toString(),
        '#home_url' => Url::fromRoute('<front>')->toString(),
        '#attached' => ['library' => array_values(array_unique($libraries))],
      ],
      'pager' => ['#type' => 'pager', '#quantity' => 5],
      '#cache' => [
        'tags' => array_merge($this->catalog->cacheTags(), ['taxonomy_term:' . $tid]),
        'contexts' => ['url.query_args', 'languages:language_content', 'languages:language_interface', 'user.permissions'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $term
   */
  private function matches(array $term, string $q): bool {
    $needle = mb_strtolower($q, 'UTF-8');
    if ((string) $term['tid'] === $q) {
      return TRUE;
    }
    foreach ($term['names'] as $name) {
      if (mb_stripos((string) $name, $needle) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $a
   * @param array<string, mixed> $b
   */
  private function compareIndex(array $a, array $b, string $sort): int {
    if ($sort === 'name') {
      return strcmp((string) $a['name'], (string) $b['name']);
    }
    if ($sort === 'new') {
      return ((int) $b['created'] <=> (int) $a['created']) ?: strcmp((string) $a['name'], (string) $b['name']);
    }
    if ($sort === 'hot') {
      return ((int) $b['views'] <=> (int) $a['views']) ?: ((int) $b['published'] <=> (int) $a['published']);
    }
    return ((int) $b['published'] <=> (int) $a['published']) ?: ((int) $b['latest'] <=> (int) $a['latest']) ?: strcmp((string) $a['name'], (string) $b['name']);
  }

  /**
   * @param array<string, mixed> $term
   * @return array{name: string, url: string, count: int}
   */
  private function chip(array $term): array {
    return [
      'name' => (string) $term['name'],
      'url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => (int) $term['tid']])->toString(),
      'count' => (int) $term['published'],
    ];
  }

  /**
   * @return array<int, array{id: string, label: string, url: string, active: bool}>
   */
  private function indexSorts(bool $hot, string $current, callable $url): array {
    $sorts = [
      'count' => '内容数量',
      'name' => '名称',
      'new' => '最新',
    ];
    if ($hot) {
      $sorts['hot'] = '热门';
    }
    $out = [];
    foreach ($sorts as $id => $label) {
      $out[] = ['id' => $id, 'label' => $label, 'url' => $url($id), 'active' => $current === $id];
    }
    return $out;
  }

  /**
   * @return array<int, array{id: string, label: string, url: string, active: bool}>
   */
  private function termSorts(bool $hot, string $current, callable $url): array {
    $sorts = ['new' => '最新发布', 'old' => '最早发布'];
    if ($hot) {
      $sorts['hot'] = '热门';
    }
    $out = [];
    foreach ($sorts as $id => $label) {
      $out[] = ['id' => $id, 'label' => $label, 'url' => $url($id), 'active' => $current === $id];
    }
    return $out;
  }

  /**
   * @return array<string, int>
   */
  private function typeCounts(int $tid, string $language): array {
    $query = $this->database->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1 AND n.langcode = :lang', [':lang' => $language]);
    $query->condition('t.field_tags_target_id', $tid);
    $query->condition('t.deleted', 0);
    $query->addField('n', 'type', 'type');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    $query->groupBy('n.type');
    $counts = ['photo' => 0, 'video' => 0, 'article' => 0];
    foreach ($query->execute() as $row) {
      if (isset($counts[$row->type])) {
        $counts[$row->type] = (int) $row->cnt;
      }
    }
    return $counts;
  }

  private function publishedInLanguage(): int {
    $query = $this->database->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1 AND n.langcode = :lang', [':lang' => $this->catalog->contentLanguage()]);
    $query->condition('t.deleted', 0);
    $query->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    return (int) $query->execute()->fetchField();
  }

  /**
   * @return array<int, array{name: string, url: string, count: int}>
   */
  private function related(int $tid): array {
    $terms = $this->catalog->terms();
    $out = [];
    foreach ($this->relations->forTerm($tid, 8, TRUE) as $item) {
      $other = (int) $item['tid'];
      if ($other === $tid || !isset($terms[$other]) || !(int) $terms[$other]['status']) {
        continue;
      }
      $out[] = [
        'name' => (string) $terms[$other]['name'],
        'url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $other])->toString(),
        'count' => (int) $terms[$other]['published'],
      ];
    }
    return $out;
  }

  /**
   * @param \Drupal\node\NodeInterface[] $nodes
   * @param string[] $libraries
   * @return array<int, array{kind: string, items: array<int, mixed>}>
   */
  private function groups(array $nodes, array &$libraries): array {
    $videoIds = [];
    foreach ($nodes as $node) {
      if ($node->bundle() === 'video') {
        $videoIds[] = (int) $node->id();
      }
    }
    $videos = [];
    if ($videoIds) {
      $libraries[] = 'xedc/video_list';
      foreach ($this->videoCards->build($videoIds)['items'] as $card) {
        $card['heading'] = 'h2';
        $videos[(int) $card['nid']] = $card;
      }
    }
    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $groups = [];
    foreach ($nodes as $node) {
      $kind = $node->bundle();
      if (!in_array($kind, ['photo', 'video', 'article'], TRUE)) {
        continue;
      }
      $item = NULL;
      if ($kind === 'photo') {
        $libraries[] = 'xedc/meitu-card';
        $item = $this->photoCard($node);
      }
      elseif ($kind === 'video') {
        $item = $videos[(int) $node->id()] ?? NULL;
      }
      else {
        $libraries[] = 'xedc/article-list';
        $item = ['build' => $viewBuilder->view($node, 'teaser')];
      }
      if ($item === NULL) {
        continue;
      }
      $last = $groups ? array_key_last($groups) : NULL;
      if ($last === NULL || $groups[$last]['kind'] !== $kind) {
        $groups[] = ['kind' => $kind, 'items' => [$item]];
      }
      else {
        $groups[$last]['items'][] = $item;
      }
    }
    return $groups;
  }

  /**
   * @return array<string, mixed>
   */
  private function photoCard(NodeInterface $node): array {
    $image = $this->cover($node, 'meitu_card_cover');
    $category = '';
    if ($node->hasField('field_categories') && !$node->get('field_categories')->isEmpty()) {
      $categoryTerm = $node->get('field_categories')->entity;
      if ($categoryTerm) {
        $category = (string) $this->repository->getTranslationFromContext($categoryTerm)->label();
      }
    }
    return [
      'url' => $node->toUrl()->toString(),
      'title' => $node->label(),
      'img' => $image['url'],
      'count' => $node->hasField('field_images') ? (int) $node->get('field_images')->count() : 0,
      'category' => $category,
      'date' => $this->dates->format($node->getCreatedTime(), 'custom', 'Y-m-d'),
      'loading' => 'lazy',
      'fetchpriority' => '',
      'width' => $image['width'],
      'height' => $image['height'],
    ];
  }

  /**
   * @return array{url: string, width: int, height: int}
   */
  private function cover(NodeInterface $node, string $styleName): array {
    $empty = ['url' => '', 'width' => 0, 'height' => 0];
    foreach (['field_cover_image', 'field_images'] as $field) {
      if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
        continue;
      }
      $entity = $node->get($field)->entity;
      $file = NULL;
      if ($entity instanceof MediaInterface && $entity->hasField('field_media_image') && !$entity->get('field_media_image')->isEmpty()) {
        $file = $entity->get('field_media_image')->entity;
      }
      elseif ($entity instanceof FileInterface) {
        $file = $entity;
      }
      if (!$file instanceof FileInterface) {
        continue;
      }
      try {
        $style = ImageStyle::load($styleName);
        $url = $style ? $style->buildUrl($file->getFileUri()) : $this->fileUrls->generateString($file->getFileUri());
      }
      catch (\Throwable) {
        return $empty;
      }
      return ['url' => $url, 'width' => 0, 'height' => 0];
    }
    return $empty;
  }

}
