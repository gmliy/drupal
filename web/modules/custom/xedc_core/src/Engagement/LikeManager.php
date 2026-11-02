<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\node\NodeInterface;

/**
 * Stores member likes separately from favorites.
 */
final class LikeManager {

  public const ALLOWED_BUNDLES = ['photo', 'video', 'article'];

  public const PER_PAGE = 12;

  public const PAGER_ELEMENT = 11;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected CacheBackendInterface $cache,
  ) {}

  public function isAllowedNode(NodeInterface $node): bool {
    return in_array($node->bundle(), self::ALLOWED_BUNDLES, TRUE) && $node->isPublished();
  }

  public function isLiked(int $uid, int $nid): bool {
    if ($uid < 1 || $nid < 1) {
      return FALSE;
    }
    $query = $this->database->select('xedc_like', 'l');
    $query->condition('uid', $uid);
    $query->condition('entity_type', 'node');
    $query->condition('entity_id', $nid);
    $query->condition('status', 1);
    return (bool) $query->countQuery()->execute()->fetchField();
  }

  public function count(string $entityType, int $entityId): int {
    $cid = 'xedc_like_count:' . $entityType . ':' . $entityId;
    $cached = $this->cache->get($cid);
    if ($cached) {
      return (int) $cached->data;
    }
    $query = $this->database->select('xedc_like', 'l');
    $query->condition('entity_type', $entityType);
    $query->condition('entity_id', $entityId);
    $query->condition('status', 1);
    $count = (int) $query->countQuery()->execute()->fetchField();
    $this->cache->set($cid, $count, Cache::PERMANENT, [$this->tag($entityType, $entityId)]);
    return $count;
  }

  /**
   * Adds or removes the current user's like.
   *
   * @return array{liked: bool, count: int}
   *   The state after the change.
   */
  public function toggle(int $uid, NodeInterface $node): array {
    if ($uid < 1 || !$this->isAllowedNode($node) || !$node->access('view')) {
      throw new \InvalidArgumentException('当前用户不能点赞这篇内容。');
    }
    $nid = (int) $node->id();
    $now = $this->time->getRequestTime();
    $transaction = $this->database->startTransaction();
    try {
      if ($this->isLiked($uid, $nid)) {
        $this->database->update('xedc_like')
          ->fields(['status' => 0, 'changed' => $now])
          ->condition('uid', $uid)
          ->condition('entity_type', 'node')
          ->condition('entity_id', $nid)
          ->execute();
        $liked = FALSE;
      }
      else {
        $this->activate($uid, $node, $now);
        $liked = TRUE;
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    $this->invalidate('node', $nid);
    return [
      'liked' => $liked,
      'count' => $this->count('node', $nid),
    ];
  }

  /**
   * Inserts a like if it does not exist. Used by concurrency checks.
   */
  public function add(int $uid, NodeInterface $node): bool {
    if ($uid < 1 || !$this->isAllowedNode($node)) {
      throw new \InvalidArgumentException('当前用户不能点赞这篇内容。');
    }
    $added = $this->activate($uid, $node, $this->time->getRequestTime());
    if ($added) {
      $this->invalidate('node', (int) $node->id());
    }
    return $added;
  }

  public function remove(int $uid, int $nid): bool {
    $updated = (int) $this->database->update('xedc_like')
      ->fields(['status' => 0, 'changed' => $this->time->getRequestTime()])
      ->condition('uid', $uid)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nid)
      ->condition('status', 1)
      ->execute();
    if ($updated > 0) {
      $this->invalidate('node', $nid);
    }
    return $updated > 0;
  }

  /**
   * Inserts an active like, or reactivates the same user's cancelled row.
   */
  protected function activate(int $uid, NodeInterface $node, int $now): bool {
    try {
      $this->database->insert('xedc_like')
        ->fields([
          'uid' => $uid,
          'entity_type' => 'node',
          'entity_id' => (int) $node->id(),
          'bundle' => $node->bundle(),
          'status' => 1,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      $updated = (int) $this->database->update('xedc_like')
        ->fields([
          'status' => 1,
          'bundle' => $node->bundle(),
          'created' => $now,
          'changed' => $now,
        ])
        ->condition('uid', $uid)
        ->condition('entity_type', 'node')
        ->condition('entity_id', (int) $node->id())
        ->condition('status', 0)
        ->execute();
      return $updated > 0;
    }
  }

  public function countVisible(int $uid, ?string $bundle = NULL): int {
    return (int) $this->baseQuery($uid, $bundle)->countQuery()->execute()->fetchField();
  }

  /**
   * @return object[]
   *   Rows with entity_id, bundle, created and title.
   */
  public function getRows(int $uid, ?string $bundle = NULL): array {
    $query = $this->baseQuery($uid, $bundle);
    $query->orderBy('l.changed', 'DESC');
    $query = $query->extend(PagerSelectExtender::class);
    $query->element(self::PAGER_ELEMENT);
    $query->limit(self::PER_PAGE);
    return $query->execute()->fetchAll();
  }

  public function deleteForEntity(string $entityType, int $entityId): void {
    $this->database->delete('xedc_like')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->execute();
    $this->invalidate($entityType, $entityId);
  }

  public function deleteForUser(int $uid): void {
    if ($uid < 1) {
      return;
    }
    $ids = $this->database->select('xedc_like', 'l')
      ->fields('l', ['entity_id'])
      ->condition('uid', $uid)
      ->condition('entity_type', 'node')
      ->execute()
      ->fetchCol();
    $this->database->delete('xedc_like')
      ->condition('uid', $uid)
      ->execute();
    foreach (array_unique(array_map('intval', $ids)) as $id) {
      $this->invalidate('node', $id);
    }
  }

  /**
   * @return array{total: int, bundles: array<string, int>, days: array<string, int>}
   *   Aggregate counts for the admin report.
   */
  public function statistics(int $days = 14): array {
    $total = (int) $this->database->select('xedc_like', 'l')->condition('status', 1)->countQuery()->execute()->fetchField();
    $bundles = [];
    $grouped = $this->database->select('xedc_like', 'l');
    $grouped->addField('l', 'bundle');
    $grouped->addExpression('COUNT(*)', 'total');
    $grouped->condition('status', 1);
    $grouped->groupBy('bundle');
    foreach ($grouped->execute() as $row) {
      $bundles[(string) $row->bundle] = (int) $row->total;
    }
    $since = $this->time->getRequestTime() - ($days * 86400);
    $trend = [];
    for ($i = $days - 1; $i >= 0; $i--) {
      $trend[date('Y-m-d', $this->time->getRequestTime() - ($i * 86400))] = 0;
    }
    $query = $this->database->select('xedc_like', 'l');
    $query->fields('l', ['changed']);
    $query->condition('status', 1);
    $query->condition('changed', $since, '>=');
    foreach ($query->execute() as $row) {
      $day = date('Y-m-d', (int) $row->changed);
      if (isset($trend[$day])) {
        $trend[$day]++;
      }
    }
    return [
      'total' => $total,
      'bundles' => $bundles,
      'days' => $trend,
    ];
  }

  protected function baseQuery(int $uid, ?string $bundle): SelectInterface {
    $query = $this->database->select('xedc_like', 'l');
    $query->join('node_field_data', 'n', 'n.nid = l.entity_id AND n.default_langcode = 1');
    $query->fields('l', ['entity_id', 'bundle', 'created', 'changed', 'status']);
    $query->addField('n', 'title');
    $query->condition('l.uid', $uid);
    $query->condition('l.entity_type', 'node');
    $query->condition('l.status', 1);
    $query->condition('l.bundle', self::ALLOWED_BUNDLES, 'IN');
    $query->condition('n.status', 1);
    if ($bundle !== NULL && in_array($bundle, self::ALLOWED_BUNDLES, TRUE)) {
      $query->condition('l.bundle', $bundle);
    }
    return $query;
  }

  public function tag(string $entityType, int $entityId): string {
    return 'xedc_like:' . $entityType . ':' . $entityId;
  }

  public function invalidate(string $entityType, int $entityId): void {
    $this->cache->delete('xedc_like_count:' . $entityType . ':' . $entityId);
    Cache::invalidateTags([$this->tag($entityType, $entityId), $entityType . ':' . $entityId]);
  }

}
