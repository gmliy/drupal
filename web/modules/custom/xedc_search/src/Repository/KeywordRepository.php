<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\xedc_search\Query\QueryNormalizer;

/**
 * Admin keywords and synonyms.
 */
final class KeywordRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTags,
    private readonly QueryNormalizer $normalizer,
  ) {}

  /**
   * @return array<string, mixed>|null
   */
  public function load(int $id): ?array {
    $row = $this->database->select('xedc_search_keyword', 'k')
      ->fields('k')
      ->condition('id', $id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['synonyms'] = $this->synonymText((int) $row['id']);
    return $row;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function findByKey(string $key): ?array {
    if ($key === '') {
      return NULL;
    }
    $row = $this->database->select('xedc_search_keyword', 'k')
      ->fields('k')
      ->condition('keyword_key', $key)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function match(string $q, int $limit, bool $enabledOnly = TRUE): array {
    $key = $this->normalizer->key($q);
    if ($key === '') {
      return [];
    }
    $query = $this->database->select('xedc_search_keyword', 'k')
      ->fields('k')
      ->condition('keyword_key', '%' . $this->database->escapeLike($key) . '%', 'LIKE');
    if ($enabledOnly) {
      $query->condition('status', 1);
    }
    $query->orderBy('weight', 'DESC');
    $query->orderBy('keyword', 'ASC');
    $query->range(0, max(1, $limit));
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Enabled keyword weights keyed by comparison key.
   *
   * @return array<string, array<string, mixed>>
   */
  public function index(): array {
    $rows = $this->database->select('xedc_search_keyword', 'k')
      ->fields('k')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $index = [];
    foreach ($rows as $row) {
      $index[$row['keyword_key']] = $row;
    }
    return $index;
  }

  /**
   * Synonyms and related term IDs for a query.
   *
   * @return array{synonyms: list<string>, tags: list<int>, categories: list<int>, weight: int}
   */
  public function expansion(string $q): array {
    $empty = ['synonyms' => [], 'tags' => [], 'categories' => [], 'weight' => 0];
    $key = $this->normalizer->key($q);
    if ($key === '') {
      return $empty;
    }
    $keyword = $this->findByKey($key);
    $synonyms = [];
    $query = $this->database->select('xedc_search_synonym', 's');
    $query->join('xedc_search_keyword', 'k', 'k.id = s.keyword_id');
    $query->fields('s', ['synonym']);
    $query->condition('s.status', 1);
    $query->condition('k.status', 1);
    $or = $query->orConditionGroup()
      ->condition('k.keyword_key', $key)
      ->condition('s.synonym_key', $key);
    $query->condition($or);
    $query->range(0, 5);
    foreach ($query->execute() as $row) {
      $text = $this->normalizer->clean((string) $row->synonym);
      if ($text !== '' && $this->normalizer->key($text) !== $key) {
        $synonyms[] = $text;
      }
    }
    if (!$keyword || !(int) $keyword['status']) {
      return ['synonyms' => $synonyms, 'tags' => [], 'categories' => [], 'weight' => 0];
    }
    return [
      'synonyms' => $synonyms,
      'tags' => $this->ids((string) $keyword['tags']),
      'categories' => $this->ids((string) $keyword['categories']),
      'weight' => (int) $keyword['weight'],
    ];
  }

  /**
   * @param array<string, mixed> $values
   */
  public function save(array $values, ?int $id = NULL): int {
    $keyword = $this->normalizer->clean((string) ($values['keyword'] ?? ''));
    $now = $this->time->getRequestTime();
    $fields = [
      'keyword' => $keyword,
      'keyword_key' => $this->normalizer->key($keyword),
      'label' => $this->normalizer->clean((string) ($values['label'] ?? '')),
      'weight' => max(-100, min(100, (int) ($values['weight'] ?? 0))),
      'status' => empty($values['status']) ? 0 : 1,
      'tags' => $this->idString($values['tags'] ?? ''),
      'categories' => $this->idString($values['categories'] ?? ''),
      'changed' => $now,
    ];
    if ($id) {
      $this->database->update('xedc_search_keyword')->fields($fields)->condition('id', $id)->execute();
    }
    else {
      $fields['created'] = $now;
      $id = (int) $this->database->insert('xedc_search_keyword')->fields($fields)->execute();
    }
    $this->replaceSynonyms($id, (string) ($values['synonyms'] ?? ''));
    $this->cacheTags->invalidateTags(['xedc_search']);
    return $id;
  }

  public function delete(int $id): void {
    $this->database->delete('xedc_search_synonym')->condition('keyword_id', $id)->execute();
    $this->database->delete('xedc_search_keyword')->condition('id', $id)->execute();
    $this->cacheTags->invalidateTags(['xedc_search']);
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   */
  public function adminList(string $q, string $status, int $limit, int $offset): array {
    $query = $this->database->select('xedc_search_keyword', 'k')->fields('k');
    $key = $this->normalizer->key($q);
    if ($key !== '') {
      $query->condition('keyword_key', '%' . $this->database->escapeLike($key) . '%', 'LIKE');
    }
    if ($status === '1' || $status === '0') {
      $query->condition('status', (int) $status);
    }
    $count = (int) (clone $query)->countQuery()->execute()->fetchField();
    $query->orderBy('weight', 'DESC');
    $query->orderBy('changed', 'DESC');
    $query->range(max(0, $offset), max(1, $limit));
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return ['rows' => $rows, 'total' => $count];
  }

  private function replaceSynonyms(int $keywordId, string $text): void {
    $this->database->delete('xedc_search_synonym')->condition('keyword_id', $keywordId)->execute();
    $parts = preg_split('/[,，\n]+/u', $text) ?: [];
    $seen = [];
    $count = 0;
    foreach ($parts as $part) {
      $synonym = $this->normalizer->clean((string) $part);
      $key = $this->normalizer->key($synonym);
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $this->database->insert('xedc_search_synonym')->fields([
        'keyword_id' => $keywordId,
        'synonym' => $synonym,
        'synonym_key' => $key,
        'status' => 1,
      ])->execute();
      $count++;
      if ($count >= 20) {
        break;
      }
    }
  }

  private function synonymText(int $keywordId): string {
    $rows = $this->database->select('xedc_search_synonym', 's')
      ->fields('s', ['synonym'])
      ->condition('keyword_id', $keywordId)
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchCol();
    return implode("\n", $rows);
  }

  /**
   * @return list<int>
   */
  private function ids(string $value): array {
    if ($value === '') {
      return [];
    }
    $ids = [];
    foreach (explode(',', $value) as $part) {
      $id = (int) $part;
      if ($id > 0) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  private function idString(mixed $value): string {
    if (is_array($value)) {
      $ids = array_map('intval', $value);
    }
    else {
      $ids = $this->ids((string) $value);
    }
    $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    $string = implode(',', array_slice($ids, 0, 40));
    return mb_substr($string, 0, 255);
  }

}
