<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Comic listings, chapters and counters.
 */
final class ComicManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  /**
   * Admin comic list with optional publish filter.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function listComicsAdmin(array $filters = [], int $limit = 25, int $offset = 0): array {
    $query = $this->buildComicAdminQuery($filters);
    $query->range($offset, $limit);
    $nids = $query->execute();
    return $this->entities->getStorage('node')->loadMultiple($nids);
  }

  public function countComicsAdmin(array $filters = []): int {
    return (int) $this->buildComicAdminQuery($filters)->count()->execute();
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  public function listChaptersAdmin(array $filters = [], int $limit = 25, int $offset = 0): array {
    $query = $this->buildChapterAdminQuery($filters);
    $query->range($offset, $limit);
    return $this->entities->getStorage('node')->loadMultiple($query->execute());
  }

  public function countChaptersAdmin(array $filters = []): int {
    return (int) $this->buildChapterAdminQuery($filters)->count()->execute();
  }

  private function buildComicAdminQuery(array $filters): \Drupal\Core\Entity\Query\QueryInterface {
    $query = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'comic');
    if (($filters['publish'] ?? '') === 'published') {
      $query->condition('status', 1);
    }
    elseif (($filters['publish'] ?? '') === 'draft') {
      $query->condition('status', 0);
    }
    if (!empty($filters['category'])) {
      $query->condition('field_comic_category.target_id', (int) $filters['category']);
    }
    if (!empty($filters['status'])) {
      $query->condition('field_comic_status', (string) $filters['status']);
    }
    if (!empty($filters['q'])) {
      $query->condition('title', '%' . $this->database->escapeLike((string) $filters['q']) . '%', 'LIKE');
    }
    $sort = (string) ($filters['sort'] ?? 'changed');
    match ($sort) {
      'views' => $query->sort('field_comic_views', 'DESC'),
      'title' => $query->sort('title'),
      'created' => $query->sort('created', 'DESC'),
      default => $query->sort('changed', 'DESC'),
    };
    return $query;
  }

  private function buildChapterAdminQuery(array $filters): \Drupal\Core\Entity\Query\QueryInterface {
    $query = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'comic_chapter');
    if (!empty($filters['comic'])) {
      $query->condition('field_comic_parent.target_id', (int) $filters['comic']);
    }
    if (!empty($filters['q'])) {
      $query->condition('title', '%' . $this->database->escapeLike((string) $filters['q']) . '%', 'LIKE');
    }
    if (($filters['vip'] ?? '') === 'vip') {
      $query->condition('field_comic_vip', 1);
    }
    elseif (($filters['vip'] ?? '') === 'free') {
      $query->condition('field_comic_vip', 0);
    }
    if (($filters['publish'] ?? '') === 'published') {
      $query->condition('status', 1);
    }
    elseif (($filters['publish'] ?? '') === 'draft') {
      $query->condition('status', 0);
    }
    $sort = (string) ($filters['sort'] ?? 'number');
    match ($sort) {
      'changed' => $query->sort('changed', 'DESC'),
      'created' => $query->sort('created', 'DESC'),
      default => $query->sort('field_comic_number', 'ASC'),
    };
    return $query;
  }

  public function listComics(array $filters = [], int $limit = 24, int $offset = 0): array {
    $query = $this->buildPublicComicQuery($filters);
    $query->range($offset, $limit);
    $nids = $query->execute();
    return $this->entities->getStorage('node')->loadMultiple($nids);
  }

  public function countPublicComics(array $filters = []): int {
    return (int) $this->buildPublicComicQuery($filters)->count()->execute();
  }

  private function buildPublicComicQuery(array $filters): \Drupal\Core\Entity\Query\QueryInterface {
    $allowedStatus = ['ongoing', 'completed', 'paused'];
    $allowedSort = ['update', 'created', 'views', 'favorites', 'title'];
    $query = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'comic')
      ->condition('status', 1);
    if (!empty($filters['category'])) {
      $query->condition('field_comic_category.target_id', (int) $filters['category']);
    }
    if (!empty($filters['tag'])) {
      $query->condition('field_comic_tags.target_id', (int) $filters['tag']);
    }
    $serial = (string) ($filters['status'] ?? '');
    if ($serial !== '' && in_array($serial, $allowedStatus, TRUE)) {
      $query->condition('field_comic_status', $serial);
    }
    if (!empty($filters['q'])) {
      $query->condition('title', '%' . $this->database->escapeLike((string) $filters['q']) . '%', 'LIKE');
    }
    $access = (string) ($filters['access'] ?? '');
    if ($access === 'vip' || $access === 'free') {
      $vipParents = $this->comicIdsWithVipChapters();
      if ($access === 'vip') {
        if ($vipParents === []) {
          $query->condition('nid', 0);
        }
        else {
          $query->condition('nid', $vipParents, 'IN');
        }
      }
      elseif ($vipParents !== []) {
        $query->condition('nid', $vipParents, 'NOT IN');
      }
    }
    $sort = (string) ($filters['sort'] ?? 'update');
    if (!in_array($sort, $allowedSort, TRUE)) {
      $sort = 'update';
    }
    match ($sort) {
      'views' => $query->sort('field_comic_views', 'DESC'),
      'favorites' => $query->sort('field_comic_favorites', 'DESC'),
      'title' => $query->sort('title'),
      'created' => $query->sort('created', 'DESC'),
      default => $query->sort('field_comic_update_time', 'DESC'),
    };
    return $query;
  }

  /**
   * @return list<int>
   */
  private function comicIdsWithVipChapters(): array {
    $nids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'comic_chapter')
      ->condition('status', 1)
      ->condition('field_comic_chapter_status', 'published')
      ->condition('field_comic_vip', 1)
      ->condition('field_comic_free', 0)
      ->execute();
    if ($nids === []) {
      return [];
    }
    $parents = [];
    foreach ($this->entities->getStorage('node')->loadMultiple($nids) as $chapter) {
      if ($chapter instanceof NodeInterface && $chapter->hasField('field_comic_parent') && !$chapter->get('field_comic_parent')->isEmpty()) {
        $parents[(int) $chapter->get('field_comic_parent')->target_id] = TRUE;
      }
    }
    return array_keys($parents);
  }

  public function publicChapterCount(int $comicId): int {
    return (int) $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'comic_chapter')
      ->condition('field_comic_parent.target_id', $comicId)
      ->condition('status', 1)
      ->condition('field_comic_chapter_status', 'published')
      ->count()
      ->execute();
  }

  public function totalPagesForComic(int $comicId): int {
    $sum = $this->database->select('node__field_comic_page_count', 'p');
    $sum->join('node__field_comic_parent', 'par', 'par.entity_id = p.entity_id');
    $sum->addExpression('SUM(p.field_comic_page_count_value)', 'total');
    $sum->condition('par.field_comic_parent_target_id', $comicId);
    $value = $sum->execute()->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * @param list<int> $comicIds
   *
   * @return array<int, array{title: string, number: float}>
   */
  public function latestChapterSummaries(array $comicIds): array {
    if ($comicIds === []) {
      return [];
    }
    $chapterNids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'comic_chapter')
      ->condition('field_comic_parent.target_id', $comicIds, 'IN')
      ->condition('status', 1)
      ->condition('field_comic_chapter_status', 'published')
      ->sort('field_comic_number', 'DESC')
      ->execute();
    $map = [];
    foreach ($this->entities->getStorage('node')->loadMultiple($chapterNids) as $chapter) {
      if (!$chapter instanceof NodeInterface || !$chapter->hasField('field_comic_parent')) {
        continue;
      }
      $parent = (int) $chapter->get('field_comic_parent')->target_id;
      if (isset($map[$parent])) {
        continue;
      }
      $map[$parent] = [
        'title' => $chapter->label(),
        'number' => $chapter->hasField('field_comic_number') ? (float) $chapter->get('field_comic_number')->value : 0,
      ];
    }
    return $map;
  }

  /**
   * @param list<int> $comicIds
   *
   * @return array<int, bool>
   */
  public function vipFlagsForComics(array $comicIds): array {
    $flags = array_fill_keys($comicIds, FALSE);
    $vipSet = array_fill_keys($this->comicIdsWithVipChapters(), TRUE);
    foreach ($comicIds as $id) {
      $flags[$id] = isset($vipSet[$id]);
    }
    return $flags;
  }

  /**
   * @param \Drupal\node\NodeInterface[] $comics
   *
   * @return list<array<string, mixed>>
   */
  public function comicCardsBatch(array $comics): array {
    $ids = [];
    foreach ($comics as $comic) {
      $ids[] = (int) $comic->id();
    }
    $latest = $this->latestChapterSummaries($ids);
    $vip = $this->vipFlagsForComics($ids);
    $formatter = \Drupal::service('date.formatter');
    $cards = [];
    foreach ($comics as $comic) {
      if (!$comic instanceof NodeInterface) {
        continue;
      }
      $card = $this->comicCard($comic);
      $id = (int) $comic->id();
      $card['is_vip'] = $vip[$id] ?? FALSE;
      $card['latest_chapter'] = $latest[$id]['title'] ?? '';
      $updated = $comic->hasField('field_comic_update_time') && !$comic->get('field_comic_update_time')->isEmpty()
        ? (int) $comic->get('field_comic_update_time')->value
        : (int) $comic->getChangedTime();
      $card['updated'] = $formatter->format($updated, 'custom', 'Y-m-d', 'Asia/Shanghai');
      $card['status_label'] = $this->serialStatusLabel((string) ($card['status'] ?? ''));
      $cards[] = $card;
    }
    return $cards;
  }

  private function serialStatusLabel(string $status): string {
    return match ($status) {
      'ongoing' => '连载中',
      'completed' => '已完结',
      'paused' => '暂停',
      default => '',
    };
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function relatedComics(NodeInterface $comic, int $limit = 8): array {
    $filters = ['sort' => 'views'];
    $exclude = (int) $comic->id();
    $related = [];
    if ($comic->hasField('field_comic_category') && !$comic->get('field_comic_category')->isEmpty()) {
      $filters['category'] = (int) $comic->get('field_comic_category')->target_id;
      foreach ($this->listComics($filters, $limit + 1) as $node) {
        if ((int) $node->id() === $exclude) {
          continue;
        }
        $related[] = $this->comicCard($node);
        if (count($related) >= $limit) {
          return $related;
        }
      }
    }
    if ($comic->hasField('field_comic_tags') && !$comic->get('field_comic_tags')->isEmpty()) {
      $tagId = (int) $comic->get('field_comic_tags')->first()->target_id;
      foreach ($this->listComics(['tag' => $tagId, 'sort' => 'views'], $limit + 1) as $node) {
        if ((int) $node->id() === $exclude) {
          continue;
        }
        foreach ($related as $row) {
          if ((int) $row['id'] === (int) $node->id()) {
            continue 2;
          }
        }
        $related[] = $this->comicCard($node);
        if (count($related) >= $limit) {
          return $related;
        }
      }
    }
    foreach (\Drupal::service('xedc_comic.stats')->topComics(30, $limit + 1) as $row) {
      if ((int) $row['comic_id'] === $exclude) {
        continue;
      }
      $node = $this->entities->getStorage('node')->load((int) $row['comic_id']);
      if ($node instanceof NodeInterface) {
        $related[] = $this->comicCard($node);
      }
      if (count($related) >= $limit) {
        break;
      }
    }
    return $related;
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  public function listChapters(int $comicId, int $limit = 50, int $offset = 0): array {
    $query = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'comic_chapter')
      ->condition('field_comic_parent.target_id', $comicId)
      ->sort('field_comic_number', 'ASC')
      ->range($offset, $limit);
    if (!\Drupal::currentUser()->hasPermission('manage comic chapters')) {
      $query->condition('status', 1)
        ->condition('field_comic_chapter_status', 'published');
    }
    $nids = $query->execute();
    return $this->entities->getStorage('node')->loadMultiple($nids);
  }

  public function chapterCount(int $comicId): int {
    return (int) $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'comic_chapter')
      ->condition('field_comic_parent.target_id', $comicId)
      ->count()
      ->execute();
  }

  public function touchComicUpdate(NodeInterface $comic): void {
    if (!$comic->hasField('field_comic_update_time')) {
      return;
    }
    $comic->set('field_comic_update_time', $this->time->getRequestTime());
    $comic->save();
    Cache::invalidateTags(['node:' . $comic->id()]);
  }

  public function syncChapterPageCount(int $chapterId): void {
    $chapter = $this->entities->getStorage('node')->load($chapterId);
    if (!$chapter instanceof NodeInterface || !$chapter->hasField('field_comic_page_count')) {
      return;
    }
    $count = (int) $this->entities->getStorage('comic_page')->getQuery()
      ->accessCheck(FALSE)
      ->condition('chapter_id', $chapterId)
      ->count()
      ->execute();
    $chapter->set('field_comic_page_count', $count);
    $chapter->save();
    $parent = $chapter->hasField('field_comic_parent') ? $chapter->get('field_comic_parent')->target_id : 0;
    if ($parent) {
      $comic = $this->entities->getStorage('node')->load((int) $parent);
      if ($comic instanceof NodeInterface) {
        $this->touchComicUpdate($comic);
      }
    }
  }

  /**
   * @return array<string, mixed>
   */
  public function comicCard(NodeInterface $comic): array {
    $cover = '';
    if ($comic->hasField('field_comic_cover') && !$comic->get('field_comic_cover')->isEmpty()) {
      $file = $comic->get('field_comic_cover')->entity;
      if ($file && method_exists($file, 'getFileUri')) {
        $cover = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }
    }
    return [
      'id' => (int) $comic->id(),
      'title' => $comic->label(),
      'cover' => $cover,
      'author' => $comic->hasField('field_comic_author') ? (string) $comic->get('field_comic_author')->value : '',
      'status' => $comic->hasField('field_comic_status') ? (string) $comic->get('field_comic_status')->value : '',
      'views' => $comic->hasField('field_comic_views') ? (int) $comic->get('field_comic_views')->value : 0,
      'favorites' => $comic->hasField('field_comic_favorites') ? (int) $comic->get('field_comic_favorites')->value : 0,
      'detail_url' => Url::fromRoute('xedc_comic.detail', ['comic' => $comic->id()])->toString(),
      'url' => Url::fromRoute('xedc_comic.detail', ['comic' => $comic->id()])->toString(),
    ];
  }

  public function setCounterField(int $comicId, string $field, int $value): void {
    $comic = $this->entities->getStorage('node')->load($comicId);
    if ($comic instanceof NodeInterface && $comic->hasField($field)) {
      $comic->set($field, $value);
      $comic->save();
    }
  }

  /**
   * Tags used on at least one published comic, with comic counts.
   *
   * @return list<array{id: int, label: string, url: string, count: int, letter: string}>
   */
  public function comicTagsWithCounts(): array {
    $cid = 'xedc_comic:tags_with_counts:v2';
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $query = $this->database->select('node__field_comic_tags', 'ft');
    $query->join('node_field_data', 'n', 'n.nid = ft.entity_id');
    $query->condition('n.default_langcode', 1);
    $query->addField('ft', 'field_comic_tags_target_id', 'tid');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'comic_count');
    $query->condition('n.type', 'comic');
    $query->condition('n.status', 1);
    $query->isNotNull('ft.field_comic_tags_target_id');
    $query->groupBy('ft.field_comic_tags_target_id');
    $query->having('comic_count > 0');
    $query->orderBy('comic_count', 'DESC');
    $query->orderBy('tid', 'ASC');
    $rows = [];
    $termStorage = $this->entities->getStorage('taxonomy_term');
    foreach ($query->execute() as $record) {
      $tid = (int) $record->tid;
      $term = $termStorage->load($tid);
      if ($term === NULL || $term->bundle() !== 'tags') {
        continue;
      }
      $label = $term->label();
      $rows[] = [
        'id' => $tid,
        'label' => $label,
        'url' => Url::fromRoute('xedc_comic.tag', ['taxonomy_term' => $tid])->toString(),
        'count' => (int) $record->comic_count,
        'letter' => $this->comicTagLetter($label),
      ];
    }
    usort($rows, static function (array $a, array $b): int {
      if ($a['count'] !== $b['count']) {
        return $b['count'] <=> $a['count'];
      }
      return strnatcasecmp($a['label'], $b['label']);
    });
    $this->cache->set($cid, $rows, Cache::PERMANENT, ['node_list:comic', 'taxonomy_term_list:tags']);
    return $rows;
  }

  /**
   * Tags that co-occur on comics with the given tag (comic-only).
   *
   * @return list<array{id: int, label: string, url: string, count: int, letter: string}>
   */
  public function relatedComicTags(int $tagId, int $limit = 16): array {
    if ($tagId < 1) {
      return [];
    }
    $sub = $this->database->select('node__field_comic_tags', 'c');
    $sub->join('node_field_data', 'n', 'n.nid = c.entity_id');
    $sub->condition('n.default_langcode', 1);
    $sub->condition('c.field_comic_tags_target_id', $tagId);
    $sub->condition('n.type', 'comic');
    $sub->condition('n.status', 1);
    $sub->fields('c', ['entity_id']);
    $nids = $sub->execute()->fetchCol();
    if ($nids === []) {
      return [];
    }
    $query = $this->database->select('node__field_comic_tags', 'o');
    $query->join('node_field_data', 'n', 'n.nid = o.entity_id');
    $query->condition('n.default_langcode', 1);
    $query->addField('o', 'field_comic_tags_target_id', 'tid');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'comic_count');
    $query->condition('n.nid', $nids, 'IN');
    $query->condition('n.type', 'comic');
    $query->condition('n.status', 1);
    $query->condition('o.field_comic_tags_target_id', $tagId, '<>');
    $query->groupBy('o.field_comic_tags_target_id');
    $query->orderBy('comic_count', 'DESC');
    $query->range(0, $limit);
    $indexed = $this->indexComicTagsById($this->comicTagsWithCounts());
    $out = [];
    foreach ($query->execute() as $record) {
      $tid = (int) $record->tid;
      if (!isset($indexed[$tid])) {
        continue;
      }
      $out[] = $indexed[$tid];
    }
    return $out;
  }

  public function comicTagComicCount(int $tagId): int {
    if ($tagId < 1) {
      return 0;
    }
    foreach ($this->comicTagsWithCounts() as $row) {
      if ($row['id'] === $tagId) {
        return (int) $row['count'];
      }
    }
    return 0;
  }

  /**
   * @param list<array{id: int, label: string, url: string, count: int, letter: string}> $rows
   *
   * @return array<int, array{id: int, label: string, url: string, count: int, letter: string}>
   */
  private function indexComicTagsById(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
      $map[(int) $row['id']] = $row;
    }
    return $map;
  }

  private function comicTagLetter(string $label): string {
    $trim = trim($label);
    if ($trim === '') {
      return '#';
    }
    $first = mb_substr($trim, 0, 1);
    if (preg_match('/^[0-9]$/u', $first) || preg_match('/^\p{S}|\p{P}/u', $first)) {
      return '#';
    }
    if (preg_match('/^[A-Za-z]$/u', $first)) {
      return mb_strtoupper($first);
    }
    $roman = \Drupal::service('transliteration')->transliterate($trim, 'zh-hans', '');
    if (preg_match('/[a-zA-Z]/', $roman, $match)) {
      return strtoupper($match[0]);
    }
    return '#';
  }

}