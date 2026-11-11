<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\xedc_search\Query\QueryNormalizer;

/**
 * Per-user search history. Every query is scoped by uid.
 */
final class HistoryRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly QueryNormalizer $normalizer,
  ) {}

  /**
   * @return list<array<string, mixed>>
   */
  public function list(int $uid, string $bundle = 'all', int $limit = 20): array {
    if ($uid <= 0) {
      return [];
    }
    $bundle = $this->normalizer->context($bundle);
    $query = $this->database->select('xedc_search_history', 'h')
      ->fields('h', ['id', 'keyword', 'bundle', 'created'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, max(1, min(20, $limit)));
    if ($bundle !== 'all') {
      $query->condition('bundle', $bundle);
    }
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function add(int $uid, string $keyword, string $bundle): int {
    if ($uid <= 0) {
      return 0;
    }
    $keyword = $this->normalizer->clean($keyword);
    if ($keyword === '') {
      return 0;
    }
    $bundle = $this->normalizer->context($bundle);
    $key = $this->normalizer->key($keyword);
    $existing = $this->database->select('xedc_search_history', 'h')
      ->fields('h', ['id'])
      ->condition('uid', $uid)
      ->condition('bundle', $bundle)
      ->where('LOWER(keyword) = :key', [':key' => $key])
      ->execute()
      ->fetchCol();
    if ($existing) {
      $this->database->delete('xedc_search_history')
        ->condition('uid', $uid)
        ->condition('id', $existing, 'IN')
        ->execute();
    }
    $id = (int) $this->database->insert('xedc_search_history')->fields([
      'uid' => $uid,
      'keyword' => $keyword,
      'bundle' => $bundle,
      'created' => $this->time->getRequestTime(),
    ])->execute();
    $this->trim($uid);
    return $id;
  }

  public function delete(int $uid, int $id): bool {
    if ($uid <= 0 || $id <= 0) {
      return FALSE;
    }
    $deleted = $this->database->delete('xedc_search_history')
      ->condition('uid', $uid)
      ->condition('id', $id)
      ->execute();
    return (bool) $deleted;
  }

  public function clear(int $uid, ?string $bundle = NULL): void {
    if ($uid <= 0) {
      return;
    }
    $delete = $this->database->delete('xedc_search_history')->condition('uid', $uid);
    if ($bundle !== NULL && $bundle !== '') {
      $delete->condition('bundle', $this->normalizer->context($bundle));
    }
    $delete->execute();
  }

  public function deleteByKeyword(int $uid, string $keyword, string $bundle): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    $key = $this->normalizer->key($keyword);
    if ($key === '') {
      return FALSE;
    }
    $deleted = $this->database->delete('xedc_search_history')
      ->condition('uid', $uid)
      ->condition('bundle', $this->normalizer->context($bundle))
      ->where('LOWER(keyword) = :key', [':key' => $key])
      ->execute();
    return (bool) $deleted;
  }

  public function purgeOlderThan(int $timestamp): void {
    $this->database->delete('xedc_search_history')
      ->condition('created', $timestamp, '<')
      ->execute();
  }

  private function trim(int $uid): void {
    $ids = $this->database->select('xedc_search_history', 'h')
      ->fields('h', ['id'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(20, 100)
      ->execute()
      ->fetchCol();
    if ($ids) {
      $this->database->delete('xedc_search_history')
        ->condition('uid', $uid)
        ->condition('id', $ids, 'IN')
        ->execute();
    }
  }

}
