<?php

/**
 * @file
 * Search page helpers for the xedc theme.
 */

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\search\SearchQuery;

/**
 * Returns supported search types.
 */
function _xedc_search_types(): array {
  return [
    'all' => t('全部'),
    'photo' => t('美图'),
    'video' => t('视频'),
    'article' => t('文章'),
    'model' => t('模特'),
  ];
}

/**
 * Whether the current route renders a content search page.
 */
function _xedc_search_is_route(): bool {
  static $is_search = NULL;
  if ($is_search !== NULL) {
    return $is_search;
  }
  $name = \Drupal::routeMatch()->getRouteName();
  $is_search = in_array($name, ['search.view_search', 'search.view_node_search'], TRUE);
  return $is_search;
}

/**
 * Returns sanitized search parameters: [q, type, sort, page].
 */
function _xedc_search_params(): array {
  static $params = NULL;
  if ($params !== NULL) {
    return $params;
  }

  $request = \Drupal::request();
  $q = $request->query->get('q');
  if (!is_string($q) || trim($q) === '') {
    $keys = $request->query->get('keys');
    if (is_string($keys)) {
      $q = $keys;
    }
  }
  $q = is_string($q) ? $q : '';
  $q = trim(strip_tags($q));
  $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $q) ?? '';
  $q = preg_replace('/\s+/u', ' ', $q) ?? '';
  if (mb_strlen($q) > 100) {
    $q = mb_substr($q, 0, 100);
  }

  $type = $request->query->get('type');
  $type = is_string($type) ? strtolower($type) : 'all';
  if (!array_key_exists($type, _xedc_search_types())) {
    $type = 'all';
  }

  $sort = $request->query->get('sort');
  $sort = is_string($sort) && in_array($sort, ['latest', 'hot', 'relevance'], TRUE) ? $sort : 'relevance';

  $page = $request->query->get('page');
  if (is_array($page)) {
    $page = end($page);
  }
  $page = is_numeric($page) && (int) $page > 0 ? (int) $page : 0;
  if ($page > 1000) {
    $page = 1000;
  }

  $params = [$q, $type, $sort, $page];
  return $params;
}

/**
 * Sanitized category, tag, model and date filters.
 *
 * @return array{category: int, tag: int, model: int, from: string, to: string}
 */
function _xedc_search_filters(): array {
  static $filters = NULL;
  if ($filters !== NULL) {
    return $filters;
  }
  $request = \Drupal::request();
  $integer = static function (string $key) use ($request): int {
    $value = $request->query->get($key);
    if (!is_scalar($value)) {
      return 0;
    }
    $value = (int) $value;
    return $value > 0 && $value < 100000000 ? $value : 0;
  };
  $day = static function (string $key) use ($request): string {
    $value = $request->query->get($key, '');
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return '';
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
  };
  $filters = [
    'category' => $integer('category'),
    'tag' => $integer('tag'),
    'model' => $integer('model'),
    'from' => $day('from'),
    'to' => $day('to'),
  ];
  return $filters;
}

/**
 * Builds a search URL while preserving q/type/sort and active filters.
 */
function _xedc_search_url(string $q, string $type = 'all', string $sort = 'relevance', int $page = 0, ?array $filters = NULL): string {
  $filters = $filters ?? _xedc_search_filters();
  $query = [];
  if ($q !== '') {
    $query['q'] = $q;
  }
  if ($type !== 'all') {
    $query['type'] = $type;
  }
  if (in_array($sort, ['latest', 'hot'], TRUE)) {
    $query['sort'] = $sort;
  }
  foreach (['category', 'tag', 'model'] as $key) {
    if (!empty($filters[$key])) {
      $query[$key] = (int) $filters[$key];
    }
  }
  foreach (['from', 'to'] as $key) {
    if (!empty($filters[$key])) {
      $query[$key] = $filters[$key];
    }
  }
  if ($page > 0) {
    $query['page'] = $page;
  }
  return Url::fromUserInput('/search', ['query' => $query])->toString();
}

/**
 * The search engine, when the module is enabled.
 */
function _xedc_search_engine(): ?\Drupal\xedc_search\Service\SearchEngine {
  if (!\Drupal::moduleHandler()->moduleExists('xedc_search')) {
    return NULL;
  }
  if (!\Drupal::config('xedc_search.settings')->get('enabled')) {
    return NULL;
  }
  return \Drupal::service('xedc_search.engine');
}

/**
 * Builds the core search query used for content results and counts.
 */
function _xedc_search_query(string $q, string $type = 'all') {
  $query = _xedc_database()
    ->select('search_index', 'i')
    ->extend(SearchQuery::class);
  $query->join('node_field_data', 'n', '[n].[nid] = [i].[sid] AND [n].[langcode] = [i].[langcode]');
  $query->condition('n.status', 1)->addTag('node_access');
  $query->searchExpression($q, 'node_search');
  // 搜索页只展示美图、视频、文章。索引里的 page 等类型不参与分页，否则一页会不足 12 条。
  $bundles = in_array($type, ['photo', 'video', 'article'], TRUE) ? [$type] : ['photo', 'video', 'article'];
  $query->condition('n.type', $bundles, 'IN');
  return $query;
}

/**
 * Counts content results for a type.
 */
function _xedc_search_count(string $q, string $type = 'all'): int {
  if ($q === '') {
    return 0;
  }
  $engine = _xedc_search_engine();
  if ($engine && in_array($type, ['all', 'photo', 'video', 'article'], TRUE)) {
    try {
      return $engine->count($q, $type, _xedc_search_filters());
    }
    catch (\Throwable $exception) {
      \Drupal::logger('xedc_search')->error($exception->getMessage());
    }
  }
  $query = _xedc_search_query($q, $type);
  if (!$query->prepareAndNormalize()) {
    return 0;
  }
  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Returns result node IDs for content search.
 */
function _xedc_search_result_nids(string $q, string $type, string $sort, int $limit = 12): array {
  if ($q === '' || !in_array($type, ['all', 'photo', 'video', 'article'], TRUE)) {
    return [];
  }
  $engine = _xedc_search_engine();
  if ($engine) {
    try {
      [, , , $page] = _xedc_search_params();
      $found = $engine->page($q, $type, $sort, $page, _xedc_search_filters());
      \Drupal::service('pager.manager')->createPager($found['total'], $found['limit'] ?: $limit, 0);
      return $found['nids'];
    }
    catch (\Throwable $exception) {
      \Drupal::logger('xedc_search')->error($exception->getMessage());
    }
  }
  $query = _xedc_search_query($q, $type);
  if (!$query->prepareAndNormalize()) {
    return [];
  }
  if ($sort === 'latest') {
    $query->orderBy('n.created', 'DESC')->groupBy('n.created');
  }
  $pager = $query->extend(PagerSelectExtender::class);
  $pager->element(0)->limit($limit);
  $result = $pager->execute();
  $nids = [];
  if ($result) {
    foreach ($result as $row) {
      $nids[] = (int) $row->sid;
    }
  }
  return $nids;
}

/**
 * Escapes text and highlights search keywords.
 */
function _xedc_search_highlight(string $text, string $q): Markup {
  $text = (string) $text;
  $words = array_values(array_filter(
    array_map(fn($word) => trim($word, '"'), preg_split('/\s+/u', trim($q)) ?: []),
    fn($word) => $word !== ''
  ));
  if (!$words) {
    return Markup::create(Html::escape($text));
  }
  $pattern = '/(' . implode('|', array_map(fn($word) => preg_quote($word, '/'), $words)) . ')/iu';
  $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
  if ($parts === FALSE) {
    return Markup::create(Html::escape($text));
  }
  $out = '';
  foreach ($parts as $index => $part) {
    if ($part === '') {
      continue;
    }
    $escaped = Html::escape($part);
    $out .= ($index % 2 === 1) ? '<mark class="highlight sr-mark">' . $escaped . '</mark>' : $escaped;
  }
  return Markup::create($out);
}

/**
 * Returns a safe image URL from a file or media entity.
 */
function _xedc_search_image_entity($entity, string $style_id = 'photo_card_640'): array {
  if (!$entity) {
    return ['', []];
  }
  $tags = [];
  if ($entity instanceof MediaInterface) {
    $tags = $entity->getCacheTags();
    if ($entity->hasField('field_media_image') && !$entity->get('field_media_image')->isEmpty()) {
      $entity = $entity->get('field_media_image')->entity;
    }
    elseif ($entity->hasField('thumbnail') && !$entity->get('thumbnail')->isEmpty()) {
      $entity = $entity->get('thumbnail')->entity;
    }
    else {
      return ['', $tags];
    }
  }
  if ($entity instanceof FileInterface) {
    $tags = Cache::mergeTags($tags, $entity->getCacheTags());
    $uri = $entity->getFileUri();
    $style = ImageStyle::load($style_id);
    $url = $style
      ? $style->buildUrl($uri)
      : \Drupal::service('file_url_generator')->generateString($uri);
    return [$url, $tags];
  }
  return ['', $tags];
}

/**
 * Checks that an inline image URL is safe to render.
 */
function _xedc_search_safe_image_url(string $url): bool {
  $url = trim($url);
  if ($url === '' || str_starts_with($url, '//')) {
    return FALSE;
  }
  if (str_starts_with($url, '/')) {
    return TRUE;
  }
  return (bool) preg_match('#^https?://#i', $url);
}

/**
 * Returns node cover URL and cache tags.
 */
function _xedc_search_node_cover(NodeInterface $node, string $type = 'all'): array {
  $bundle = $node->bundle();
  $field = match ($bundle) {
    'photo' => 'field_cover_image',
    'video' => 'field_video_cover',
    'article' => 'field_cover',
    default => '',
  };
  if ($field !== '' && $node->hasField($field) && !$node->get($field)->isEmpty()) {
    $style = ($bundle === 'video' && $type === 'video') ? 'video_cover' : 'photo_card_640';
    [$url, $tags] = _xedc_search_image_entity($node->get($field)->entity, $style);
    if ($url !== '') {
      return [$url, $tags];
    }
  }

  if ($bundle === 'photo' && $node->hasField('field_images') && !$node->get('field_images')->isEmpty()) {
    [$url, $tags] = _xedc_search_image_entity($node->get('field_images')->first()->entity, 'photo_card_640');
    if ($url !== '') {
      return [$url, $tags];
    }
  }

  if ($bundle === 'article' && $node->hasField('field_body') && !$node->get('field_body')->isEmpty()) {
    $html = (string) $node->get('field_body')->value;
    if (preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $html, $match)) {
      $src = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if (str_starts_with($src, 'sites/') || str_starts_with($src, 'files/')) {
        $src = '/' . $src;
      }
      if (_xedc_search_safe_image_url($src)) {
        return [$src, []];
      }
    }
  }

  return ['', []];
}

/**
 * Formats a video duration.
 */
function _xedc_search_duration(NodeInterface $node): string {
  if (!$node->hasField('field_duration') || $node->get('field_duration')->isEmpty()) {
    return '';
  }
  $seconds = (int) $node->get('field_duration')->value;
  if ($seconds <= 0) {
    return '';
  }
  if ($seconds >= 3600) {
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
  }
  return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

/**
 * Builds card data for a list of node IDs.
 */
function _xedc_search_cards(array $nids, string $q, string $type): array {
  if (!$nids) {
    return [[], []];
  }
  $nodes = Node::loadMultiple($nids);
  _xedc_warm_media_files($nodes, ['field_cover_image', 'field_video_cover', 'field_cover', 'field_images']);
  $term_ids = [];
  foreach ($nodes as $node) {
    if ($node->hasField('field_model')) {
      foreach ($node->get('field_model')->getValue() as $item) {
        if (!empty($item['target_id'])) {
          $term_ids[] = $item['target_id'];
        }
      }
    }
  }
  _xedc_warm_targets('taxonomy_term', $term_ids);
  $date_formatter = \Drupal::service('date.formatter');
  $cards = [];
  $tags = ['node_list:photo', 'node_list:article', 'node_list:video'];
  foreach ($nodes as $node) {
    if (!$node instanceof NodeInterface || !$node->isPublished()) {
      continue;
    }
    $bundle = $node->bundle();
    if (!in_array($bundle, ['photo', 'video', 'article'], TRUE)) {
      continue;
    }
    $node = _xedc_translated($node);
    $title = $node->label();
    [$cover, $cover_tags] = _xedc_search_node_cover($node, $type);
    $tags = Cache::mergeTags($tags, $node->getCacheTags(), $cover_tags);
    $date = $date_formatter->format($node->getCreatedTime(), 'custom', 'Y-m-d');
    $model = '';
    if ($node->hasField('field_model') && !$node->get('field_model')->isEmpty()) {
      foreach ($node->get('field_model')->referencedEntities() as $term) {
        if ($term instanceof \Drupal\taxonomy\TermInterface) {
          $model = _xedc_translated($term)->label();
          break;
        }
      }
    }
    $author = '';
    if ($node->hasField('field_author') && !$node->get('field_author')->isEmpty()) {
      $author = trim((string) $node->get('field_author')->value);
    }
    if ($author === '' && $model !== '') {
      $author = $model;
    }
    if ($author === '' && $bundle === 'video') {
      $owner = $node->getOwner();
      if ($owner && (int) $owner->id() > 0) {
        $tags = Cache::mergeTags($tags, $owner->getCacheTags());
        if ($owner->hasField('displayname') && !$owner->get('displayname')->isEmpty()) {
          $author = trim((string) $owner->get('displayname')->value);
        }
      }
    }
    $image_count = 0;
    if ($bundle === 'photo' && $node->hasField('field_images')) {
      $image_count = (int) $node->get('field_images')->count();
    }
    $cards[] = [
      'url' => $node->toUrl()->toString(),
      'title' => _xedc_search_highlight($title, $q),
      'cover' => $cover,
      'alt' => $title,
      'type' => $bundle,
      'image_count' => $image_count,
      'video' => $bundle === 'video',
      'duration' => $bundle === 'video' ? _xedc_search_duration($node) : '',
      'author' => $author,
      'model' => $model,
      'date' => $date,
      'initial' => mb_substr($title, 0, 1),
    ];
  }
  return [$cards, $tags];
}

/**
 * Finds model terms whose name matches the keyword.
 */
function _xedc_search_model_groups(string $q): array {
  if ($q === '') {
    return [];
  }
  $db = _xedc_database();
  $terms = $db->select('taxonomy_term_field_data', 't')
    ->fields('t', ['tid', 'name'])
    ->condition('vid', 'models')
    ->condition('default_langcode', 1)
    ->execute()
    ->fetchAllKeyed();
  if (!$terms) {
    return [];
  }

  $alias = [];
  if ($db->schema()->tableExists('taxonomy_term__field_alias_of')) {
    $alias = $db->select('taxonomy_term__field_alias_of', 'a')
      ->fields('a', ['entity_id', 'field_alias_of_target_id'])
      ->condition('a.deleted', 0)
      ->execute()
      ->fetchAllKeyed();
  }

  $like = '%' . $db->escapeLike($q) . '%';
  $matched = $db->select('taxonomy_term_field_data', 't')
    ->fields('t', ['tid'])
    ->condition('vid', 'models')
    ->condition('default_langcode', 1)
    ->condition('name', $like, 'LIKE')
    ->execute()
    ->fetchCol();
  if (!$matched) {
    return [];
  }

  $groups = [];
  foreach ($matched as $tid) {
    $tid = (int) $tid;
    $main = isset($alias[$tid], $terms[$alias[$tid]]) ? (int) $alias[$tid] : $tid;
    if (!isset($terms[$main])) {
      continue;
    }
    $groups[$main]['main'] = $main;
    $groups[$main]['name'] = $terms[$main];
    $groups[$main]['tids'][$tid] = $tid;
  }
  if (!$groups) {
    return [];
  }

  foreach ($alias as $tid => $main) {
    $main = (int) $main;
    $tid = (int) $tid;
    if (isset($groups[$main], $terms[$tid])) {
      $groups[$main]['tids'][$tid] = $tid;
    }
  }

  $all_tids = [];
  foreach ($groups as $main => $group) {
    $groups[$main]['tids'] = array_values(array_unique($group['tids']));
    foreach ($groups[$main]['tids'] as $tid) {
      $all_tids[(int) $tid] = (int) $tid;
    }
  }
  $pairs = [];
  if ($all_tids) {
    $works = $db->select('node__field_model', 'm');
    $works->join('node_field_data', 'n', 'n.nid = m.entity_id AND n.default_langcode = 1');
    $works->fields('m', ['field_model_target_id']);
    $works->fields('n', ['nid', 'created']);
    $works->condition('n.status', 1)
      ->condition('m.deleted', 0)
      ->condition('m.field_model_target_id', array_values($all_tids), 'IN');
    foreach ($works->execute() as $row) {
      $pairs[(int) $row->field_model_target_id][(int) $row->nid] = (int) $row->created;
    }
  }
  $cover_nids = [];
  foreach ($groups as $main => $group) {
    $nids = [];
    $best_nid = 0;
    $best_created = -1;
    foreach ($group['tids'] as $tid) {
      foreach ($pairs[(int) $tid] ?? [] as $nid => $created) {
        $nids[$nid] = TRUE;
        if ($created > $best_created) {
          $best_created = $created;
          $best_nid = $nid;
        }
      }
    }
    $groups[$main]['count'] = count($nids);
    $groups[$main]['cover_nid'] = $best_nid;
    if ($best_nid) {
      $cover_nids[$best_nid] = $best_nid;
    }
  }
  $cover_nodes = $cover_nids ? Node::loadMultiple($cover_nids) : [];
  _xedc_warm_media_files($cover_nodes, ['field_cover_image', 'field_images']);
  foreach ($groups as &$group) {
    $group['cover'] = '';
    $node = $cover_nodes[$group['cover_nid']] ?? NULL;
    if ($node) {
      [$cover] = _xedc_search_node_cover($node, 'photo');
      $group['cover'] = $cover;
    }
    $group['name_html'] = _xedc_search_highlight($group['name'], $q);
    $group['initial'] = mb_substr($group['name'], 0, 1);
    $group['url'] = Url::fromRoute('xedc_models.detail', ['taxonomy_term' => $group['main']])->toString();
  }
  unset($group);

  uasort($groups, fn($a, $b) => $b['count'] <=> $a['count']);
  return array_values($groups);
}

/**
 * Returns all model term IDs represented by matched model groups.
 */
function _xedc_search_model_tids(array $groups): array {
  $tids = [];
  foreach ($groups as $group) {
    $tids = array_merge($tids, $group['tids']);
  }
  return array_values(array_unique(array_map('intval', $tids)));
}

/**
 * Counts all content attached to matched model groups.
 */
function _xedc_search_model_content_count(array $groups): int {
  $tids = _xedc_search_model_tids($groups);
  if (!$tids) {
    return 0;
  }
  $query = _xedc_database()->select('node__field_model', 'm');
  $query->join('node_field_data', 'n', 'n.nid = m.entity_id AND n.default_langcode = 1');
  $query->condition('n.status', 1)
    ->condition('m.deleted', 0)
    ->condition('m.field_model_target_id', $tids, 'IN');
  $query->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
  $query->addTag('node_access');
  return (int) $query->execute()->fetchField();
}

/**
 * Returns a page of content attached to matched model groups.
 */
function _xedc_search_model_nids(array $groups, int $limit = 12): array {
  $tids = _xedc_search_model_tids($groups);
  if (!$tids) {
    return [];
  }
  $query = _xedc_database()
    ->select('node__field_model', 'm')
    ->extend(PagerSelectExtender::class);
  $query->join('node_field_data', 'n', 'n.nid = m.entity_id AND n.default_langcode = 1');
  $query->condition('n.status', 1)
    ->condition('m.deleted', 0)
    ->condition('m.field_model_target_id', $tids, 'IN');
  $query->fields('n', ['nid'])
    ->distinct()
    ->orderBy('n.created', 'DESC')
    ->element(0)
    ->addTag('node_access')
    ->limit($limit);
  $nids = [];
  foreach ($query->execute() as $row) {
    $nids[] = (int) $row->nid;
  }
  return $nids;
}

/**
 * Returns popular models for empty search states.
 */
function _xedc_search_hot_models(int $limit = 6): array {
  $db = _xedc_database();
  $query = $db->select('node__field_model', 'm');
  $query->join('node_field_data', 'n', 'n.nid = m.entity_id AND n.default_langcode = 1');
  $query->condition('n.status', 1)->condition('m.deleted', 0);
  $query->addField('m', 'field_model_target_id', 'tid');
  $query->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
  $query->groupBy('m.field_model_target_id')->orderBy('cnt', 'DESC')->range(0, $limit);
  $query->addTag('node_access');
  $rows = $query->execute()->fetchAll();
  if (!$rows) {
    return [];
  }
  $counts = [];
  foreach ($rows as $row) {
    $counts[(int) $row->tid] = (int) $row->cnt;
  }
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple(array_keys($counts));
  $models = [];
  foreach ($terms as $tid => $term) {
    if ($term->bundle() !== 'models') {
      continue;
    }
    $models[] = [
      'name' => _xedc_translated($term)->label(),
      'count' => $counts[$tid],
      'url' => Url::fromRoute('xedc_models.detail', ['taxonomy_term' => $tid])->toString(),
    ];
  }
  usort($models, fn($a, $b) => $b['count'] <=> $a['count']);
  return $models;
}

/**
 * Builds xa-pager data from the search pager (element 0).
 *
 * Page numbers in the template are 1-based. The page query parameter stays
 * 0-based, matching PagerSelectExtender and _xedc_search_params().
 */
function _xedc_search_xa_pager(string $q, string $type, string $sort): array {
  $pager = \Drupal::service('pager.manager')->getPager(0);
  if (!$pager) {
    return [];
  }
  $per = (int) $pager->getLimit();
  $total = (int) $pager->getTotalItems();
  if ($per < 1 || $total <= $per) {
    return [];
  }
  $last = (int) ceil($total / $per);
  $cur = (int) $pager->getCurrentPage() + 1;
  $href = function (int $n) use ($q, $type, $sort) {
    return _xedc_search_url($q, $type, $sort, $n > 1 ? $n - 1 : 0);
  };
  $win = array_unique(array_filter([1, $cur - 1, $cur, $cur + 1, $last], fn($n) => $n >= 1 && $n <= $last));
  sort($win);
  $items = [];
  $prev = 0;
  foreach ($win as $n) {
    if ($prev && $n - $prev > 1) {
      $items[] = ['gap' => TRUE];
    }
    $items[] = ['n' => $n, 'url' => $href($n), 'active' => $n === $cur];
    $prev = $n;
  }
  return [
    'items' => $items,
    'cur' => $cur,
    'last' => $last,
    'prev' => $cur > 1 ? $href($cur - 1) : '',
    'next' => $cur < $last ? $href($cur + 1) : '',
  ];
}

/**
 * Hot keywords for the empty search page. Uses the existing pill list.
 *
 * @return list<array{label: string, count: int, url: string}>
 */
function _xedc_search_hot_terms(int $limit = 8): array {
  if (!\Drupal::moduleHandler()->moduleExists('xedc_search')) {
    return [];
  }
  $config = \Drupal::config('xedc_search.settings');
  if (!$config->get('enabled') || !$config->get('hot_enabled')) {
    return [];
  }
  try {
    $rows = \Drupal::service('xedc_search.stats')->hot((string) ($config->get('hot_window') ?: 'week'), $limit, FALSE, 'all');
  }
  catch (\Throwable $exception) {
    \Drupal::logger('xedc_search')->error($exception->getMessage());
    return [];
  }
  $terms = [];
  foreach ($rows as $row) {
    $label = trim((string) ($row['keyword'] ?? ''));
    if ($label === '') {
      continue;
    }
    $terms[] = [
      'label' => $label,
      'count' => (int) ($row['searches'] ?? 0),
      'url' => _xedc_search_url($label),
    ];
  }
  return $terms;
}

/**
 * Builds the full search page render array.
 */
function _xedc_search_build_page(): array {
  [$q, $type, $sort, $page] = _xedc_search_params();
  $types = _xedc_search_types();
  $counts = ['all' => 0, 'photo' => 0, 'video' => 0, 'article' => 0, 'model' => 0];
  $groups = [];
  $model_total = 0;

  if ($q !== '') {
    foreach (['all', 'photo', 'video', 'article'] as $key) {
      $counts[$key] = _xedc_search_count($q, $key);
    }
    $groups = _xedc_search_model_groups($q);
    $model_total = $groups ? _xedc_search_model_content_count($groups) : 0;
    $counts['model'] = $model_total;
  }

  $cards = [];
  $tags = ['node_list', 'taxonomy_term_list', 'media_list', 'file_list'];
  if ($q !== '') {
    if ($type === 'model' && $groups) {
      $nids = _xedc_search_model_nids($groups);
      [$cards, $card_tags] = _xedc_search_cards($nids, $q, $type);
      $tags = Cache::mergeTags($tags, $card_tags);
    }
    elseif (in_array($type, ['all', 'photo', 'video', 'article'], TRUE)) {
      $nids = _xedc_search_result_nids($q, $type, $sort);
      [$cards, $card_tags] = _xedc_search_cards($nids, $q, $type);
      $tags = Cache::mergeTags($tags, $card_tags);
    }
  }

  $model_cards = in_array($type, ['all', 'model'], TRUE) ? $groups : [];
  $tabs = [];
  foreach ($types as $key => $label) {
    $tabs[] = [
      'key' => $key,
      'label' => $label,
      'count' => $counts[$key] ?? 0,
      'url' => _xedc_search_url($q, $key, $sort, 0),
      'active' => $key === $type,
    ];
  }

  $info_count = match ($type) {
    'photo', 'video', 'article', 'model' => $counts[$type],
    default => $counts['all'],
  };

  $sorts = [
    [
      'key' => 'relevance',
      'label' => t('相关'),
      'active' => $sort === 'relevance',
      'url' => _xedc_search_url($q, $type, 'relevance', 0),
    ],
    [
      'key' => 'latest',
      'label' => t('最新'),
      'active' => $sort === 'latest',
      'url' => _xedc_search_url($q, $type, 'latest', 0),
    ],
    [
      'key' => 'hot',
      'label' => t('热门'),
      'active' => $sort === 'hot',
      'url' => _xedc_search_url($q, $type, 'hot', 0),
    ],
  ];

  $explore = [
    ['label' => t('最新美图'), 'url' => Url::fromUserInput('/meitu')->toString()],
    ['label' => t('热门视频'), 'url' => Url::fromUserInput('/video')->toString()],
    ['label' => t('最新文章'), 'url' => Url::fromUserInput('/articles')->toString()],
    ['label' => t('全部模特'), 'url' => Url::fromUserInput('/models')->toString()],
  ];
  $filters = _xedc_search_filters();
  $chips = [];
  foreach (['category' => t('分类'), 'tag' => t('标签'), 'model' => t('人物')] as $key => $label) {
    if (empty($filters[$key])) {
      continue;
    }
    $term = \Drupal\taxonomy\Entity\Term::load((int) $filters[$key]);
    $clear = $filters;
    $clear[$key] = 0;
    $chips[] = [
      'label' => $term ? $term->label() : (string) $label,
      'url' => _xedc_search_url($q, $type, $sort, 0, $clear),
    ];
  }
  foreach (['from' => t('从'), 'to' => t('至')] as $key => $label) {
    if ($filters[$key] === '') {
      continue;
    }
    $clear = $filters;
    $clear[$key] = '';
    $chips[] = [
      'label' => $label . ' ' . $filters[$key],
      'url' => _xedc_search_url($q, $type, $sort, 0, $clear),
    ];
  }

  return [
    '#theme' => 'xedc_search_page',
    '#q' => $q,
    '#type' => $type,
    '#sort' => $sort,
    '#page' => $page,
    '#category' => $filters['category'],
    '#tag' => $filters['tag'],
    '#model' => $filters['model'],
    '#from' => $filters['from'],
    '#to' => $filters['to'],
    '#chips' => $chips,
    '#form_action' => Url::fromUserInput('/search')->toString(),
    '#tabs' => $tabs,
    '#sorts' => $sorts,
    '#info_count' => $info_count,
    '#cards' => $cards,
    '#models' => $model_cards,
    '#xa_pager' => $cards ? _xedc_search_xa_pager($q, $type, $sort) : [],
    '#hot_models' => _xedc_search_hot_models(6),
    '#hot_terms' => $q === '' ? _xedc_search_hot_terms(8) : [],
    '#explore' => $explore,
    '#attached' => ['library' => ['xedc/search-page']],
    '#cache' => [
      'contexts' => [
        'url.query_args:q',
        'url.query_args:type',
        'url.query_args:sort',
        'url.query_args:page',
        'url.query_args:category',
        'url.query_args:tag',
        'url.query_args:model',
        'url.query_args:from',
        'url.query_args:to',
      ],
      'tags' => Cache::mergeTags($tags, ['xedc_search', 'config:xedc_search.settings']),
    ],
  ];
}
