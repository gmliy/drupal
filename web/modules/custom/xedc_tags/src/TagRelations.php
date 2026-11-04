<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Co-occurrence between tags. Public results use published nodes only.
 */
final class TagRelations {

  public function __construct(
    private Connection $database,
    private TagCatalog $catalog,
    private CacheBackendInterface $cache,
    private LanguageManagerInterface $languageManager,
  ) {}

  /**
   * @return array<int, array{tid: int, shared: int, jaccard: float}>
   */
  public function forTerm(int $tid, int $limit = 8, bool $publishedOnly = TRUE): array {
    $language = $publishedOnly ? $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId() : 'all';
    $cid = 'xedc_tags:rel:' . $tid . ':' . ($publishedOnly ? 'pub:' . $language : 'all');
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return array_slice($cached->data, 0, $limit);
    }
    $rows = $this->compute($tid, $publishedOnly, $language);
    $this->cache->set($cid, $rows, Cache::PERMANENT, $this->catalog->cacheTags());
    return array_slice($rows, 0, $limit);
  }

  /**
   * @return array<int, array{left: int, right: int, shared: int, jaccard: float}>
   */
  public function topPairs(int $limit = 30, bool $publishedOnly = TRUE): array {
    $cid = 'xedc_tags:pairs:' . ($publishedOnly ? 'pub' : 'all');
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return array_slice($cached->data, 0, $limit);
    }
    $query = $this->database->select('node__field_tags', 'a');
    $query->join('node__field_tags', 'b', 'b.entity_id = a.entity_id AND b.deleted = 0 AND b.field_tags_target_id > a.field_tags_target_id');
    $query->join('node_field_data', 'n', 'n.nid = a.entity_id' . ($publishedOnly ? ' AND n.status = 1' : ''));
    $query->condition('a.deleted', 0);
    $query->addField('a', 'field_tags_target_id', 'left_tid');
    $query->addField('b', 'field_tags_target_id', 'right_tid');
    $query->addExpression('COUNT(DISTINCT a.entity_id)', 'shared');
    $query->groupBy('a.field_tags_target_id');
    $query->groupBy('b.field_tags_target_id');
    $query->orderBy('shared', 'DESC');
    $query->range(0, 80);
    $terms = $this->catalog->terms();
    $rows = [];
    foreach ($query->execute() as $row) {
      $left = (int) $row->left_tid;
      $right = (int) $row->right_tid;
      $shared = (int) $row->shared;
      $leftCount = (int) ($terms[$left]['published_any'] ?? $terms[$left]['all'] ?? 0);
      $rightCount = (int) ($terms[$right]['published_any'] ?? $terms[$right]['all'] ?? 0);
      if (!$publishedOnly) {
        $leftCount = (int) ($terms[$left]['all'] ?? 0);
        $rightCount = (int) ($terms[$right]['all'] ?? 0);
      }
      $union = $leftCount + $rightCount - $shared;
      $rows[] = [
        'left' => $left,
        'right' => $right,
        'shared' => $shared,
        'jaccard' => $union > 0 ? $shared / $union : 0,
      ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['jaccard'] <=> $a['jaccard'] ?: $b['shared'] <=> $a['shared']);
    $this->cache->set($cid, $rows, Cache::PERMANENT, $this->catalog->cacheTags());
    return array_slice($rows, 0, $limit);
  }

  /**
   * @return array<int, array{tid: int, shared: int, jaccard: float}>
   */
  private function compute(int $tid, bool $publishedOnly, string $language): array {
    $query = $this->database->select('node__field_tags', 'a');
    $query->join('node__field_tags', 'b', 'b.entity_id = a.entity_id AND b.deleted = 0 AND b.field_tags_target_id <> a.field_tags_target_id');
    $join = 'n.nid = a.entity_id';
    if ($publishedOnly) {
      $join .= ' AND n.status = 1 AND n.langcode = :lang';
      $query->addJoin('INNER', 'node_field_data', 'n', $join, [':lang' => $language]);
    }
    else {
      $query->join('node_field_data', 'n', $join);
    }
    $query->condition('a.deleted', 0);
    $query->condition('a.field_tags_target_id', $tid);
    $query->addField('b', 'field_tags_target_id', 'tid');
    $query->addExpression('COUNT(DISTINCT a.entity_id)', 'shared');
    $query->groupBy('b.field_tags_target_id');
    $query->orderBy('shared', 'DESC');
    $query->range(0, 40);
    $terms = $this->catalog->terms();
    $self = $publishedOnly ? (int) ($terms[$tid]['published'] ?? 0) : (int) ($terms[$tid]['all'] ?? 0);
    $rows = [];
    foreach ($query->execute() as $row) {
      $other = (int) $row->tid;
      if ($other === $tid) {
        continue;
      }
      $shared = (int) $row->shared;
      $otherCount = $publishedOnly ? (int) ($terms[$other]['published'] ?? 0) : (int) ($terms[$other]['all'] ?? 0);
      $union = $self + $otherCount - $shared;
      $rows[] = [
        'tid' => $other,
        'shared' => $shared,
        'jaccard' => $union > 0 ? $shared / $union : 0,
      ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['jaccard'] <=> $a['jaccard'] ?: $b['shared'] <=> $a['shared']);
    return $rows;
  }

}
