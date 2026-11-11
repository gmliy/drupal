<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_search\Query\QueryNormalizer;
use Drupal\xedc_search\Repository\KeywordRepository;
use Drupal\xedc_search\Repository\StatsRepository;

/**
 * Grouped suggestions and one completion string, both from stored content.
 */
final class Suggester {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly QueryNormalizer $normalizer,
    private readonly KeywordRepository $keywords,
    private readonly StatsRepository $stats,
    private readonly SearchEngine $engine,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function suggest(string $q, string $type): array {
    $config = $this->configFactory->get('xedc_search.settings');
    $q = $this->normalizer->clean($q);
    $type = $this->normalizer->type($type);
    $limit = max(3, min(5, (int) $config->get('group_limit') ?: 4));
    $empty = [
      'q' => $q,
      'completion' => NULL,
      'groups' => [],
    ];
    if (!(bool) $config->get('enabled') || !(bool) $config->get('suggest_enabled')) {
      return $empty;
    }
    if (mb_strlen($q) < max(1, (int) $config->get('min_length'))) {
      return $empty;
    }
    $cid = 'xedc_search:suggest:' . hash('sha256', $q . '|' . $type . '|' . $limit . '|' . implode(',', $this->languages()));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $groups = [
      $this->group('keywords', '搜索关键词', $this->keywordItems($q, $type, $limit)),
      $this->group('models', '人物', $this->termItems($q, ['models'], $limit, 'model')),
      $this->group('categories', '分类', $this->termItems($q, $this->categoryVocabularies($type), $limit, 'category')),
      $this->group('tags', '标签', $this->termItems($q, ['tags'], $limit, 'tag')),
      $this->group('hot', '热门建议', $this->hotItems($q, $type, $limit)),
    ];
    $groups = array_values(array_filter($groups, static fn(array $group): bool => $group['items'] !== []));
    $completion = (bool) $config->get('complete_enabled') ? $this->pickCompletion($q, $type) : NULL;
    $payload = ['q' => $q, 'completion' => $completion, 'groups' => $groups];
    $seconds = max(0, (int) $config->get('cache_seconds'));
    $this->cache->set($cid, $payload, \Drupal::time()->getRequestTime() + $seconds, ['xedc_search', 'node_list', 'taxonomy_term_list']);
    return $payload;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function complete(string $q, string $type): ?array {
    $config = $this->configFactory->get('xedc_search.settings');
    $q = $this->normalizer->clean($q);
    if (!(bool) $config->get('enabled') || !(bool) $config->get('complete_enabled')) {
      return NULL;
    }
    if (mb_strlen($q) < max(1, (int) $config->get('min_length'))) {
      return NULL;
    }
    return $this->pickCompletion($q, $this->normalizer->type($type));
  }

  /**
   * @param list<array<string, mixed>> $items
   * @return array<string, mixed>
   */
  private function group(string $id, string $label, array $items): array {
    return ['id' => $id, 'label' => $label, 'items' => $items];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function keywordItems(string $q, string $type, int $limit): array {
    $items = [];
    $seen = [];
    $push = function (string $label, string $kind, string $bundle = '') use (&$items, &$seen, $q, $type, $limit): void {
      $label = $this->normalizer->clean($label);
      $key = $this->normalizer->key($label);
      if ($key === '' || isset($seen[$key]) || count($items) >= $limit) {
        return;
      }
      $seen[$key] = TRUE;
      $searchType = in_array($bundle, ['photo', 'video', 'article'], TRUE) ? $bundle : $this->contentType($type);
      $items[] = [
        'label' => $label,
        'kind' => $kind,
        'url' => $this->searchUrl($label, $searchType),
        'image' => '',
      ];
    };
    foreach ($this->keywords->match($q, $limit) as $row) {
      $push((string) ($row['label'] !== '' ? $row['label'] : $row['keyword']), 'keyword');
    }
    $bundles = $this->bundles($type);
    $query = $this->database->select('node_field_data', 'n');
    $query->addTag('node_access');
    $query->fields('n', ['title', 'type']);
    $query->condition('n.status', 1);
    $query->condition('n.type', $bundles, 'IN');
    $query->condition('n.langcode', $this->languages(), 'IN');
    $query->condition('n.title', '%' . $this->database->escapeLike($q) . '%', 'LIKE');
    $query->orderBy('n.created', 'DESC');
    $query->range(0, $limit * 3);
    $rows = $query->execute()->fetchAll();
    usort($rows, function ($a, $b) use ($q): int {
      return $this->rank((string) $a->title, $q) <=> $this->rank((string) $b->title, $q);
    });
    foreach ($rows as $row) {
      $push((string) $row->title, 'title', (string) $row->type);
    }
    return $items;
  }

  /**
   * @param list<string> $vids
   * @return list<array<string, mixed>>
   */
  private function termItems(string $q, array $vids, int $limit, string $kind): array {
    if (!$vids) {
      return [];
    }
    $found = $this->engine->terms($q, $vids, $limit * 2, 0);
    usort($found['items'], function (array $a, array $b) use ($q): int {
      return $this->rank((string) $a['label'], $q) <=> $this->rank((string) $b['label'], $q);
    });
    $items = array_slice($found['items'], 0, $limit);
    if ($kind === 'model') {
      $items = $this->attachModelImages($items);
    }
    $type = match ($kind) {
      'model' => 'model',
      'tag' => '',
      default => '',
    };
    foreach ($items as &$item) {
      $item['kind'] = $kind;
      $item['image'] = $item['image'] ?? '';
      if ($kind === 'category') {
        $bundle = match ($item['vid'] ?? '') {
          'video_category' => 'video',
          'article_category' => 'article',
          default => 'photo',
        };
        $item['url'] = $this->searchUrl((string) $item['label'], $bundle, ['category' => (int) $item['id']]);
      }
      elseif ($kind === 'tag') {
        $item['url'] = $this->searchUrl((string) $item['label'], $type, ['tag' => (int) $item['id']]);
      }
    }
    unset($item);
    return $items;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function hotItems(string $q, string $type, int $limit): array {
    $config = $this->configFactory->get('xedc_search.settings');
    if (!(bool) $config->get('hot_enabled')) {
      return [];
    }
    $window = $this->normalizer->window((string) $config->get('hot_window'));
    $needle = $this->normalizer->key($q);
    $items = [];
    $bundle = $this->normalizer->type($type);
    foreach ($this->stats->hot($window, 30, FALSE, $bundle) as $row) {
      $label = (string) $row['keyword'];
      if (!str_contains($this->normalizer->key($label), $needle)) {
        continue;
      }
      $items[] = [
        'label' => $label,
        'kind' => 'hot',
        'url' => $this->searchUrl($label, $this->contentType($type)),
        'image' => '',
        'count' => (int) $row['searches'],
      ];
      if (count($items) >= $limit) {
        break;
      }
    }
    return $items;
  }

  /**
   * Flat site keywords from published content and taxonomies (no group titles).
   *
   * @return list<array<string, mixed>>
   */
  public function dynamicKeywords(string $q, string $type, int $limit = 8): array {
    $config = $this->configFactory->get('xedc_search.settings');
    if (!(bool) $config->get('enabled') || !(bool) $config->get('suggest_enabled')) {
      return [];
    }
    $q = $this->normalizer->clean($q);
    $type = $this->normalizer->type($type);
    $limit = max(1, min(12, $limit));
    if ($q !== '' && mb_strlen($q) < max(1, (int) $config->get('min_length'))) {
      return [];
    }
    $needle = $this->normalizer->key($q);
    $items = [];
    $seen = [];
    $push = function (array $item) use (&$items, &$seen, $needle, $limit): void {
      if (count($items) >= $limit) {
        return;
      }
      $label = $this->normalizer->clean((string) ($item['label'] ?? ''));
      $key = $this->normalizer->key($label);
      if ($key === '' || isset($seen[$key])) {
        return;
      }
      if ($needle !== '' && $key === $needle) {
        return;
      }
      if ($needle !== '' && !str_contains($key, $needle)) {
        return;
      }
      $seen[$key] = TRUE;
      $item['label'] = $label;
      $items[] = $item;
    };

    $slice = max(2, (int) ceil($limit / 2));
    match ($type) {
      'model' => (function () use ($push, $q, $limit): void {
        $source = $q === '' ? $this->termBrowse(['models'], $limit, 'model') : $this->termItems($q, ['models'], $limit, 'model');
        foreach ($source as $item) {
          $push($item);
        }
      })(),
      'tag' => (function () use ($push, $q, $limit): void {
        $source = $q === '' ? $this->termBrowse(['tags'], $limit, 'tag') : $this->termItems($q, ['tags'], $limit, 'tag');
        foreach ($source as $item) {
          $push($item);
        }
      })(),
      'category' => (function () use ($push, $q, $limit): void {
        $vids = $this->categoryVocabularies('all');
        $source = $q === '' ? $this->termBrowse($vids, $limit, 'category') : $this->termItems($q, $vids, $limit, 'category');
        foreach ($source as $item) {
          $push($item);
        }
      })(),
      'photo' => (function () use ($push, $q, $slice, $limit): void {
        foreach ($this->keywordItems($q, 'photo', $slice) as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['photo_category'], min(4, $limit), 'category') as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['tags'], min(3, $limit), 'tag') as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['models'], min(3, $limit), 'model') as $item) {
          $push($item);
        }
      })(),
      'video' => (function () use ($push, $q, $slice, $limit): void {
        foreach ($this->keywordItems($q, 'video', $slice) as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['video_category'], min(4, $limit), 'category') as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['tags'], min(3, $limit), 'tag') as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['models'], min(3, $limit), 'model') as $item) {
          $push($item);
        }
      })(),
      'article' => (function () use ($push, $q, $slice, $limit): void {
        foreach ($this->keywordItems($q, 'article', $slice) as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['article_category'], min(4, $limit), 'category') as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['tags'], min(3, $limit), 'tag') as $item) {
          $push($item);
        }
      })(),
      default => (function () use ($push, $q, $slice, $limit): void {
        foreach ($this->keywordItems($q, 'all', $slice) as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['tags'], min(4, $limit), 'tag') as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, ['models'], min(4, $limit), 'model') as $item) {
          $push($item);
        }
        foreach ($this->termItems($q, $this->categoryVocabularies('all'), min(4, $limit), 'category') as $item) {
          $push($item);
        }
      })(),
    };

    return array_slice($items, 0, $limit);
  }

  /**
   * @param list<string> $vids
   * @return list<array<string, mixed>>
   */
  private function termBrowse(array $vids, int $limit, string $kind): array {
    if (!$vids) {
      return [];
    }
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->addTag('taxonomy_term_access');
    $query->condition('t.status', 1);
    $query->condition('t.vid', $vids, 'IN');
    $query->condition('t.langcode', $this->languages(), 'IN');
    $query->addField('t', 'tid');
    $query->addExpression('MAX(t.name)', 'name');
    $query->addExpression('MAX(t.vid)', 'vid');
    $query->groupBy('t.tid');
    $query->orderBy('name', 'ASC');
    $query->range(0, max(1, $limit));
    $items = [];
    foreach ($query->execute() as $row) {
      $items[] = [
        'id' => (int) $row->tid,
        'label' => (string) $row->name,
        'vid' => (string) $row->vid,
      ];
    }
    if ($kind === 'model') {
      $items = $this->attachModelImages($items);
    }
    foreach ($items as &$item) {
      $item['kind'] = $kind;
      $item['image'] = $item['image'] ?? '';
      if ($kind === 'category') {
        $bundle = match ($item['vid'] ?? '') {
          'video_category' => 'video',
          'article_category' => 'article',
          default => 'photo',
        };
        $item['url'] = $this->searchUrl((string) $item['label'], $bundle, ['category' => (int) $item['id']]);
      }
      elseif ($kind === 'tag') {
        $item['url'] = $this->searchUrl((string) $item['label'], '', ['tag' => (int) $item['id']]);
      }
      elseif ($kind === 'model') {
        $item['url'] = $this->searchUrl((string) $item['label'], 'model');
      }
    }
    unset($item);
    return $items;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function pickCompletion(string $q, string $type): ?array {
    $candidates = [];
    foreach ($this->keywordItems($q, $type, 5) as $item) {
      $candidates[] = [(string) $item['label'], 'keyword'];
    }
    foreach (['models', 'tags', 'photo_category', 'video_category', 'article_category'] as $vid) {
      foreach ($this->engine->terms($q, [$vid], 3, 0)['items'] as $item) {
        $candidates[] = [(string) $item['label'], $vid === 'models' ? 'model' : ($vid === 'tags' ? 'tag' : 'category')];
      }
    }
    $best = NULL;
    $bestRank = PHP_INT_MAX;
    $source = '';
    foreach ($candidates as [$label, $kind]) {
      if ($this->normalizer->key($label) === $this->normalizer->key($q)) {
        continue;
      }
      $rank = $this->rank($label, $q);
      if ($rank < $bestRank) {
        $bestRank = $rank;
        $best = $label;
        $source = $kind;
      }
    }
    if ($best === NULL || $bestRank >= 500) {
      return NULL;
    }
    $prefix = mb_substr($best, 0, mb_strlen($q));
    $suffix = '';
    if (mb_strtolower($prefix) === mb_strtolower($q)) {
      $suffix = mb_substr($best, mb_strlen($q));
    }
    return [
      'text' => $best,
      'suffix' => $suffix,
      'source' => $source,
    ];
  }

  /**
   * Lower rank is a better match. Prefix beats a mid-string match.
   */
  private function rank(string $label, string $q): int {
    $hay = mb_strtolower($label);
    $needle = mb_strtolower($q);
    if (str_starts_with($hay, $needle)) {
      return mb_strlen($label);
    }
    $pos = mb_strpos($hay, $needle);
    if ($pos === FALSE) {
      return 900;
    }
    return 200 + $pos + mb_strlen($label);
  }

  /**
   * @param list<array<string, mixed>> $items
   * @return list<array<string, mixed>>
   */
  private function attachModelImages(array $items): array {
    $tids = array_values(array_filter(array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), $items)));
    if (!$tids || !$this->database->schema()->tableExists('node__field_model')) {
      return $items;
    }
    $query = $this->database->select('node__field_model', 'm');
    $query->join('node_field_data', 'n', 'n.nid = m.entity_id AND n.status = 1');
    $query->condition('m.deleted', 0);
    $query->condition('m.field_model_target_id', $tids, 'IN');
    $query->addField('m', 'field_model_target_id', 'tid');
    $query->addExpression('MAX(n.nid)', 'nid');
    $query->groupBy('m.field_model_target_id');
    $map = [];
    foreach ($query->execute() as $row) {
      $map[(int) $row->tid] = (int) $row->nid;
    }
    $nodes = $map ? $this->entityTypeManager->getStorage('node')->loadMultiple(array_values($map)) : [];
    foreach ($items as &$item) {
      $nid = $map[(int) $item['id']] ?? 0;
      $node = $nodes[$nid] ?? NULL;
      $item['image'] = $node instanceof NodeInterface && $node->access('view') ? $this->cover($node) : '';
    }
    unset($item);
    return $items;
  }

  private function cover(NodeInterface $node): string {
    foreach (['field_cover_image', 'field_video_cover', 'field_cover', 'field_images'] as $field) {
      if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
        continue;
      }
      $url = $this->fileUrl($node->get($field)->entity);
      if ($url !== '') {
        return $url;
      }
    }
    return '';
  }

  private function fileUrl(mixed $entity): string {
    if ($entity instanceof MediaInterface) {
      foreach (['field_media_image', 'thumbnail'] as $field) {
        if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
          return $this->fileUrl($entity->get($field)->entity);
        }
      }
      return '';
    }
    if (!$entity instanceof FileInterface) {
      return '';
    }
    $uri = $entity->getFileUri();
    if (!$uri) {
      return '';
    }
    $style = ImageStyle::load('thumbnail');
    return $style ? $style->buildUrl($uri) : $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * @param array<string, int> $extra
   */
  private function searchUrl(string $q, string $type = '', array $extra = []): string {
    $query = ['q' => $q];
    if (in_array($type, ['photo', 'video', 'article', 'model'], TRUE)) {
      $query['type'] = $type;
    }
    foreach ($extra as $key => $value) {
      if ($value > 0) {
        $query[$key] = $value;
      }
    }
    return \Drupal\Core\Url::fromUserInput('/search', ['query' => $query])->toString();
  }

  /**
   * @return list<string>
   */
  private function bundles(string $type): array {
    return in_array($type, ['photo', 'video', 'article'], TRUE) ? [$type] : ['photo', 'video', 'article'];
  }

  private function contentType(string $type): string {
    return in_array($type, ['photo', 'video', 'article'], TRUE) ? $type : '';
  }

  /**
   * @return list<string>
   */
  private function categoryVocabularies(string $type): array {
    return match ($type) {
      'photo' => ['photo_category'],
      'video' => ['video_category'],
      'article' => ['article_category'],
      'tag', 'model' => [],
      default => ['photo_category', 'video_category', 'article_category'],
    };
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

}
