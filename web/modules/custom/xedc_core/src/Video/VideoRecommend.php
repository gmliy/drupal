<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Independent homepage and category recommendation queries for videos.
 *
 * Reuses photo flag fields on the video bundle:
 * - field_slideshow = homepage recommendation
 * - field_featured = category recommendation
 *
 * Published status is required. Draft / needs-review / rejected / unpublished
 * nodes never appear in public recommendation regions.
 */
final class VideoRecommend {

  public const HOME_TAG = 'xedc_video_home_recommend';

  public const HOT_TAG = 'xedc_video_hot';

  public const LATEST_TAG = 'xedc_video_latest';

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Published homepage-recommended video nids, newest first.
   *
   * @return int[]
   */
  public function homeNids(int $limit = 12): array {
    $limit = max(1, min(48, $limit));
    $cid = 'xedc_video:home_recommend:' . $limit;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? array_map('intval', $cached->data) : [];
    }
    if (!$this->fieldReady('field_slideshow')) {
      return [];
    }
    $nids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'video')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_slideshow', 1)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    $ordered = array_values(array_map('intval', $nids));
    $this->cache->set($cid, $ordered, Cache::PERMANENT, [self::HOME_TAG, 'node_list:video']);
    return $ordered;
  }

  /**
   * Published category-recommended video nids for one term, newest first.
   *
   * Multi-value categories are supported by the same field join: a video that
   * belongs to several terms appears in each selected category region.
   *
   * @return int[]
   */
  public function categoryNids(int $tid, int $limit = 12): array {
    $tid = max(0, $tid);
    $limit = max(1, min(48, $limit));
    if ($tid < 1) {
      return [];
    }
    $cid = 'xedc_video:category_recommend:' . $tid . ':' . $limit;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? array_map('intval', $cached->data) : [];
    }
    if (!$this->fieldReady('field_featured')) {
      return [];
    }
    $nids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'video')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_featured', 1)
      ->condition('field_categories', $tid)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    $ordered = array_values(array_map('intval', $nids));
    $this->cache->set($cid, $ordered, Cache::PERMANENT, [
      $this->categoryTag($tid),
      'node_list:video',
      'taxonomy_term:' . $tid,
    ]);
    return $ordered;
  }

  /**
   * Hot videos by real view counts, then created DESC. Independent of flags.
   *
   * @param int[] $exclude
   *
   * @return int[]
   */
  public function hotNids(int $limit = 8, array $exclude = []): array {
    $limit = max(1, min(48, $limit));
    $exclude = array_values(array_unique(array_filter(array_map('intval', $exclude))));
    sort($exclude);
    $cid = 'xedc_video:hot:' . $limit . ':' . implode(',', $exclude);
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? array_map('intval', $cached->data) : [];
    }
    $fetch = $limit + count($exclude) + 8;
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.type', 'video');
    $query->condition('n.status', NodeInterface::PUBLISHED);
    $query->condition('n.default_langcode', 1);
    if ($exclude) {
      $query->condition('n.nid', $exclude, 'NOT IN');
    }
    if ($this->database->schema()->tableExists('node_counter')) {
      $query->leftJoin('node_counter', 'c', 'c.nid = n.nid');
      $query->addExpression('COALESCE(c.totalcount, 0)', 'xedc_views');
      $query->orderBy('xedc_views', 'DESC');
    }
    $query->orderBy('n.created', 'DESC');
    $query->range(0, $fetch);
    $nids = array_map('intval', $query->execute()->fetchCol());
    $ordered = $this->filterViewable($nids, $limit);
    // View counts change without node saves; keep a short TTL so hot ranking moves.
    $expire = \Drupal::time()->getRequestTime() + 900;
    $this->cache->set($cid, $ordered, $expire, [self::HOT_TAG, 'node_list:video']);
    return $ordered;
  }

  /**
   * Latest published videos by created DESC. Independent of flags.
   *
   * @param int[] $exclude
   *
   * @return int[]
   */
  public function latestNids(int $limit = 12, array $exclude = []): array {
    $limit = max(1, min(48, $limit));
    $exclude = array_values(array_unique(array_filter(array_map('intval', $exclude))));
    sort($exclude);
    $cid = 'xedc_video:latest:' . $limit . ':' . implode(',', $exclude);
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? array_map('intval', $cached->data) : [];
    }
    $query = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'video')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->range(0, $limit + count($exclude));
    $nids = array_values(array_map('intval', $query->execute()));
    if ($exclude) {
      $nids = array_values(array_filter($nids, static fn(int $nid): bool => !in_array($nid, $exclude, TRUE)));
    }
    $ordered = array_slice($nids, 0, $limit);
    $this->cache->set($cid, $ordered, Cache::PERMANENT, [self::LATEST_TAG, 'node_list:video']);
    return $ordered;
  }

  /**
   * Cache tag for one category recommendation list.
   */
  public function categoryTag(int $tid): string {
    return 'xedc_video_category_recommend:' . max(0, $tid);
  }

  /**
   * Invalidates recommendation caches after a video save or delete.
   */
  public function invalidateNode(NodeInterface $node, ?NodeInterface $original = NULL): void {
    if ($node->bundle() !== 'video') {
      return;
    }
    $tags = [self::HOT_TAG, self::LATEST_TAG, 'node_list:video'];
    $home_now = $this->flagValue($node, 'field_slideshow');
    $home_was = $original ? $this->flagValue($original, 'field_slideshow') : 0;
    if ($home_now || $home_was || $this->publishChanged($node, $original)) {
      $tags[] = self::HOME_TAG;
    }
    $cat_now = $this->flagValue($node, 'field_featured');
    $cat_was = $original ? $this->flagValue($original, 'field_featured') : 0;
    $tids = array_unique(array_merge(
      $this->categoryIds($node),
      $original ? $this->categoryIds($original) : [],
    ));
    if ($cat_now || $cat_was || $this->publishChanged($node, $original) || ($original && $this->categoryIds($node) !== $this->categoryIds($original))) {
      foreach ($tids as $tid) {
        $tags[] = $this->categoryTag($tid);
      }
    }
    // Status / delete always clears every related region the node could touch.
    if (!$node->isPublished() || ($original && $original->isPublished() !== $node->isPublished())) {
      $tags[] = self::HOME_TAG;
      foreach ($tids as $tid) {
        $tags[] = $this->categoryTag($tid);
      }
    }
    Cache::invalidateTags(array_values(array_unique($tags)));
  }

  /**
   * Invalidates all public video recommendation regions.
   */
  public function invalidateAll(): void {
    Cache::invalidateTags([self::HOME_TAG, self::HOT_TAG, self::LATEST_TAG, 'node_list:video']);
  }

  private function fieldReady(string $field_name): bool {
    $definitions = $this->entities->getStorage('field_storage_config')->load('node.' . $field_name);
    if (!$definitions) {
      return FALSE;
    }
    return (bool) $this->entities->getStorage('field_config')->load('node.video.' . $field_name);
  }

  private function flagValue(NodeInterface $node, string $field): int {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return 0;
    }
    return (int) $node->get($field)->value ? 1 : 0;
  }

  /**
   * @return int[]
   */
  private function categoryIds(NodeInterface $node): array {
    if (!$node->hasField('field_categories') || $node->get('field_categories')->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($node->get('field_categories')->getValue() as $item) {
      $tid = (int) ($item['target_id'] ?? 0);
      if ($tid > 0) {
        $ids[] = $tid;
      }
    }
    return array_values(array_unique($ids));
  }

  private function publishChanged(NodeInterface $node, ?NodeInterface $original): bool {
    if (!$original) {
      return TRUE;
    }
    return $original->isPublished() !== $node->isPublished();
  }

  /**
   * @param int[] $nids
   *
   * @return int[]
   */
  private function filterViewable(array $nids, int $limit): array {
    if (!$nids) {
      return [];
    }
    $nodes = $this->entities->getStorage('node')->loadMultiple($nids);
    $out = [];
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      $out[] = (int) $nid;
      if (count($out) >= $limit) {
        break;
      }
    }
    return $out;
  }

}
