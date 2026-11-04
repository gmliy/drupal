<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Aggregated tag statistics. Callers should use this instead of per-tag counts.
 */
final class TagCatalog {

  public const VOCAB = 'tags';

  public function __construct(
    private Connection $database,
    private EntityTypeManagerInterface $entityTypeManager,
    private CacheBackendInterface $cache,
    private LanguageManagerInterface $languageManager,
  ) {}

  /**
   * @return array<int, array<string, mixed>>
   */
  public function terms(): array {
    $language = $this->contentLanguage();
    $cid = 'xedc_tags:catalog:' . $language;
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $terms = $this->build($language);
    $this->cache->set($cid, $terms, Cache::PERMANENT, $this->cacheTags());
    return $terms;
  }

  /**
   * @return array<string, mixed>
   */
  public function summary(): array {
    $language = $this->contentLanguage();
    $cid = 'xedc_tags:summary:' . $language;
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $terms = $this->terms();
    $month = $this->monthStart();
    $used = 0;
    $unused = 0;
    $publishedLinks = 0;
    $monthNew = 0;
    $views = FALSE;
    $photoTags = $videoTags = $articleTags = 0;
    $counts = [];
    foreach ($terms as $term) {
      if ((int) $term['all'] > 0) {
        $used++;
      }
      else {
        $unused++;
      }
      $publishedLinks += (int) $term['published_any'];
      if ((int) $term['created'] >= $month) {
        $monthNew++;
      }
      if ((int) $term['views'] > 0) {
        $views = TRUE;
      }
      if ((int) $term['photo'] > 0) {
        $photoTags++;
      }
      if ((int) $term['video'] > 0) {
        $videoTags++;
      }
      if ((int) $term['article'] > 0) {
        $articleTags++;
      }
      $counts[] = (int) $term['published_any'];
    }
    $threshold = $this->frequencyThreshold($counts);
    $high = $low = 0;
    $changedMonth = 0;
    foreach ($terms as $term) {
      $pub = (int) $term['published_any'];
      if ($pub >= $threshold['high'] && $threshold['high'] > 0) {
        $high++;
      }
      if ($pub > 0 && $pub <= $threshold['low']) {
        $low++;
      }
      if ((int) $term['changed'] >= $month) {
        $changedMonth++;
      }
    }
    $summary = [
      'total' => count($terms),
      'used' => $used,
      'unused' => $unused,
      'photo_tags' => $photoTags,
      'video_tags' => $videoTags,
      'article_tags' => $articleTags,
      'high_frequency' => $high,
      'low_frequency' => $low,
      'frequency_threshold' => $threshold,
      'published_links' => $publishedLinks,
      'published_nodes' => $this->publishedNodes(),
      'month_new' => $monthNew,
      'month_changed' => $changedMonth,
      'views_available' => $views,
      'trend' => $this->trend(),
      'language' => $language,
    ];
    $this->cache->set($cid, $summary, Cache::PERMANENT, $this->cacheTags());
    return $summary;
  }

  public function contentLanguage(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

  /**
   * @return string[]
   */
  public function cacheTags(): array {
    return [
      'taxonomy_term_list:' . self::VOCAB,
      'node_list:photo',
      'node_list:article',
      'node_list:video',
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function build(string $language): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCAB)
      ->condition('default_langcode', 1)
      ->execute();
    if (!$ids) {
      return [];
    }
    $loaded = $storage->loadMultiple($ids);
    $usage = $this->usage();
    $views = $this->views($language);
    $aliases = $this->aliases(array_map('intval', array_keys($loaded)));
    $repository = \Drupal::service('entity.repository');
    $rows = [];
    foreach ($loaded as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $tid = (int) $term->id();
      $names = [];
      $descriptions = [];
      foreach ($term->getTranslationLanguages() as $langcode => $languageObject) {
        $translation = $term->getTranslation($langcode);
        $names[$langcode] = (string) $translation->label();
        $text = trim(strip_tags((string) $translation->get('description')->value));
        if ($text !== '') {
          $descriptions[$langcode] = $text;
        }
      }
      $translated = $repository->getTranslationFromContext($term);
      $bucket = $usage[$tid] ?? [];
      $created = (int) ($term->get('content_translation_created')->value ?? 0);
      if ($created <= 0) {
        $created = (int) $term->getChangedTime();
      }
      $alias = $aliases[$tid][$language] ?? $aliases[$tid][$term->language()->getId()] ?? ('/taxonomy/term/' . $tid);
      $rows[$tid] = [
        'tid' => $tid,
        'name' => (string) $translated->label(),
        'default_name' => (string) $term->label(),
        'names' => $names,
        'langcodes' => array_keys($names),
        'language' => $term->language()->getId(),
        'description' => $descriptions !== [],
        'descriptions' => $descriptions,
        'created' => $created,
        'changed' => (int) $term->getChangedTime(),
        'status' => $term->isPublished() ? 1 : 0,
        'alias' => $alias,
        'aliases' => $aliases[$tid] ?? [],
        'all' => (int) ($bucket['all'] ?? 0),
        'published' => (int) (($bucket['published'] ?? [])[$language] ?? 0),
        'published_any' => (int) ($bucket['published_any'] ?? 0),
        'unpublished' => (int) ($bucket['unpublished'] ?? 0),
        'photo' => (int) (($bucket['types'] ?? [])['photo'] ?? 0),
        'video' => (int) (($bucket['types'] ?? [])['video'] ?? 0),
        'article' => (int) (($bucket['types'] ?? [])['article'] ?? 0),
        'photo_pub' => (int) (($bucket['published_types'] ?? [])['photo'] ?? 0),
        'video_pub' => (int) (($bucket['published_types'] ?? [])['video'] ?? 0),
        'article_pub' => (int) (($bucket['published_types'] ?? [])['article'] ?? 0),
        'latest' => (int) ($bucket['latest'] ?? 0),
        'views' => (int) ($views[$tid] ?? 0),
      ];
    }
    return $rows;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function usage(): array {
    $out = [];
    $totals = $this->database->select('node__field_tags', 't');
    $totals->join('node_field_data', 'n', 'n.nid = t.entity_id');
    $totals->condition('t.deleted', 0);
    $totals->addField('t', 'field_tags_target_id', 'tid');
    $totals->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    $totals->addExpression('MAX(n.created)', 'latest');
    $totals->groupBy('t.field_tags_target_id');
    foreach ($totals->execute() as $row) {
      $out[(int) $row->tid]['all'] = (int) $row->cnt;
      $out[(int) $row->tid]['latest'] = (int) $row->latest;
    }

    $status = $this->database->select('node__field_tags', 't');
    $status->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1');
    $status->condition('t.deleted', 0);
    $status->addField('t', 'field_tags_target_id', 'tid');
    $status->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    $status->groupBy('t.field_tags_target_id');
    foreach ($status->execute() as $row) {
      $out[(int) $row->tid]['published_any'] = (int) $row->cnt;
    }
    foreach ($out as $tid => $bucket) {
      $published = (int) ($bucket['published_any'] ?? 0);
      $out[$tid]['published_any'] = $published;
      $out[$tid]['unpublished'] = max(0, (int) ($bucket['all'] ?? 0) - $published);
    }

    $types = $this->database->select('node__field_tags', 't');
    $types->join('node_field_data', 'n', 'n.nid = t.entity_id');
    $types->condition('t.deleted', 0);
    $types->addField('t', 'field_tags_target_id', 'tid');
    $types->addField('n', 'type', 'type');
    $types->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    $types->groupBy('t.field_tags_target_id');
    $types->groupBy('n.type');
    foreach ($types->execute() as $row) {
      $out[(int) $row->tid]['types'][$row->type] = (int) $row->cnt;
    }

    $publishedTypes = $this->database->select('node__field_tags', 't');
    $publishedTypes->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1');
    $publishedTypes->condition('t.deleted', 0);
    $publishedTypes->addField('t', 'field_tags_target_id', 'tid');
    $publishedTypes->addField('n', 'type', 'type');
    $publishedTypes->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    $publishedTypes->groupBy('t.field_tags_target_id');
    $publishedTypes->groupBy('n.type');
    foreach ($publishedTypes->execute() as $row) {
      $out[(int) $row->tid]['published_types'][$row->type] = (int) $row->cnt;
    }

    $languages = $this->database->select('node__field_tags', 't');
    $languages->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1');
    $languages->condition('t.deleted', 0);
    $languages->addField('t', 'field_tags_target_id', 'tid');
    $languages->addField('n', 'langcode', 'langcode');
    $languages->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    $languages->groupBy('t.field_tags_target_id');
    $languages->groupBy('n.langcode');
    foreach ($languages->execute() as $row) {
      $out[(int) $row->tid]['published'][$row->langcode] = (int) $row->cnt;
    }
    return $out;
  }

  /**
   * @return array<int, int>
   */
  private function views(string $language): array {
    if (!$this->database->schema()->tableExists('node_counter')) {
      return [];
    }
    $query = $this->database->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1 AND n.langcode = :lang', [':lang' => $language]);
    $query->join('node_counter', 'c', 'c.nid = n.nid');
    $query->condition('t.deleted', 0);
    $query->addField('t', 'field_tags_target_id', 'tid');
    $query->addExpression('SUM(c.totalcount)', 'views');
    $query->groupBy('t.field_tags_target_id');
    $out = [];
    foreach ($query->execute() as $row) {
      $out[(int) $row->tid] = (int) $row->views;
    }
    return $out;
  }

  /**
   * @param int[] $tids
   * @return array<int, array<string, string>>
   */
  private function aliases(array $tids): array {
    if (!$tids || !$this->database->schema()->tableExists('path_alias')) {
      return [];
    }
    $paths = [];
    foreach ($tids as $tid) {
      $paths['/taxonomy/term/' . $tid] = $tid;
    }
    $query = $this->database->select('path_alias', 'p');
    $query->fields('p', ['path', 'alias', 'langcode']);
    $query->condition('path', array_keys($paths), 'IN');
    $out = [];
    foreach ($query->execute() as $row) {
      $tid = $paths[$row->path] ?? 0;
      if ($tid) {
        $out[$tid][$row->langcode] = (string) $row->alias;
      }
    }
    return $out;
  }

  private function publishedNodes(): int {
    $query = $this->database->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1');
    $query->condition('t.deleted', 0);
    $query->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
    return (int) $query->execute()->fetchField();
  }

  /**
   * @return array<int, array{label: string, tags: int, content: int}>
   */
  private function trend(): array {
    $tz = new \DateTimeZone($this->timezone());
    $start = (new \DateTimeImmutable('first day of -5 months midnight', $tz))->getTimestamp();
    $tags = [];
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->condition('vid', self::VOCAB);
    $query->condition('default_langcode', 1);
    $query->condition('content_translation_created', $start, '>=');
    $query->addField('t', 'content_translation_created', 'created');
    foreach ($query->execute() as $row) {
      $label = (new \DateTimeImmutable('@' . (int) $row->created))->setTimezone($tz)->format('Y-m');
      $tags[$label] = ($tags[$label] ?? 0) + 1;
    }
    $content = [];
    $nodes = $this->database->select('node__field_tags', 't');
    $nodes->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1 AND n.created >= :start', [':start' => $start]);
    $nodes->condition('t.deleted', 0);
    $nodes->addField('n', 'created', 'created');
    $nodes->addField('n', 'nid', 'nid');
    $nodes->distinct();
    foreach ($nodes->execute() as $row) {
      $label = (new \DateTimeImmutable('@' . (int) $row->created))->setTimezone($tz)->format('Y-m');
      $content[$label] = ($content[$label] ?? 0) + 1;
    }
    $out = [];
    $cursor = new \DateTimeImmutable('first day of -5 months midnight', $tz);
    for ($i = 0; $i < 6; $i++) {
      $label = $cursor->format('Y-m');
      $out[] = [
        'label' => $label,
        'tags' => (int) ($tags[$label] ?? 0),
        'content' => (int) ($content[$label] ?? 0),
      ];
      $cursor = $cursor->modify('+1 month');
    }
    return $out;
  }

  public function monthStart(): int {
    $tz = new \DateTimeZone($this->timezone());
    return (new \DateTimeImmutable('first day of this month midnight', $tz))->getTimestamp();
  }

  private function timezone(): string {
    $configured = (string) \Drupal::config('system.date')->get('timezone.default');
    return $configured !== '' ? $configured : 'UTC';
  }

  /**
   * @param int[] $counts
   * @return array{high: int, low: int}
   */
  private function frequencyThreshold(array $counts): array {
    $positive = array_values(array_filter($counts, static fn(int $n): bool => $n > 0));
    if (!$positive) {
      return ['high' => 0, 'low' => 0];
    }
    sort($positive);
    $n = count($positive);
    $low = $positive[(int) max(0, floor(($n - 1) * 0.25))];
    $high = $positive[(int) min($n - 1, floor(($n - 1) * 0.75))];
    if ($high <= $low) {
      $high = max($low + 1, (int) ceil(array_sum($positive) / $n));
    }
    return ['high' => $high, 'low' => max(1, $low)];
  }

}
