<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Reads the text fields that a review is allowed to see.
 */
final class TextExtractor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, mixed>
   *   Text payload. It does not contain file bytes.
   */
  public function extract(NodeInterface $node): array {
    $extra = [];
    foreach (['field_author', 'field_source'] as $name) {
      if ($node->hasField($name) && !$node->get($name)->isEmpty()) {
        $extra[] = trim((string) $node->get($name)->value);
      }
    }
    $alts = $this->alts($node);
    foreach ($alts as $alt) {
      $extra[] = 'ALT ' . $alt['mid'] . '：' . $alt['alt'];
    }
    $duration = $node->hasField('field_duration') ? (int) $node->get('field_duration')->value : 0;
    if ($duration > 0) {
      $extra[] = '时长：' . $duration;
    }
    $meta = $this->meta($node);
    return [
      'bundle' => $node->bundle(),
      'title' => (string) $node->label(),
      'summary' => $this->plain($node, 'field_summary'),
      'body' => $this->plain($node, 'field_body'),
      'tags' => $this->labels($node, 'field_tags'),
      'categories' => $this->labels($node, 'field_categories'),
      'people' => $this->labels($node, 'field_model'),
      'extra' => implode("\n", array_filter($extra)),
      'langcode' => $node->language()->getId(),
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'alts' => $alts,
      'duration' => $duration,
      'has_summary_field' => $node->hasField('field_summary'),
      'has_tags_field' => $node->hasField('field_tags'),
      'has_body_field' => $node->hasField('field_body'),
      'has_category_field' => $node->hasField('field_categories'),
      'has_duration_field' => $node->hasField('field_duration'),
      'has_image_field' => $node->hasField('field_images') || $node->hasField('field_cover_image') || $node->hasField('field_cover') || $node->hasField('field_video_cover'),
      'meta_title' => $meta['title'],
      'meta_description' => $meta['description'],
      'meta_title_source' => $meta['title_source'],
      'meta_description_source' => $meta['description_source'],
      'published' => $node->isPublished(),
      'moderation_state' => $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '',
    ];
  }

  /**
   * @return list<int>
   *   File ids referenced by the node. The files themselves are not read here.
   */
  public function fileIds(NodeInterface $node): array {
    $ids = [];
    foreach (['field_images', 'field_cover_image', 'field_cover', 'field_video_cover', 'field_video_file'] as $name) {
      if (!$node->hasField($name)) {
        continue;
      }
      foreach ($node->get($name) as $item) {
        $media = $item->entity;
        if ($media === NULL) {
          continue;
        }
        foreach (['field_media_image', 'field_media_video_file'] as $fileField) {
          if ($media->hasField($fileField) && !$media->get($fileField)->isEmpty()) {
            $ids[] = (int) $media->get($fileField)->target_id;
          }
        }
      }
    }
    return array_values(array_unique(array_filter($ids)));
  }

  /**
   * @return array<string, mixed>
   *   What this analysis could read. File bytes are not included.
   */
  public function snapshot(NodeInterface $node): array {
    $fields = $this->extract($node);
    $fields['raw_body'] = $node->hasField('field_body') ? (string) $node->get('field_body')->value : '';
    return \Drupal\xedc_ai\Seo\SeoResult::sourceSnapshot($fields);
  }

  public function loadRevision(string $entityType, int $entityId, int $revisionId, bool $strict = FALSE): ?NodeInterface {
    if ($entityType !== 'node') {
      return NULL;
    }
    $storage = $this->entities->getStorage('node');
    if ($revisionId > 0) {
      $node = $storage->loadRevision($revisionId);
      if ($node instanceof NodeInterface && (int) $node->id() === $entityId) {
        return $node;
      }
      if ($strict) {
        return NULL;
      }
    }
    $node = $storage->load($entityId);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * @return list<string>
   *   Referenced labels.
   */
  private function labels(NodeInterface $node, string $field): array {
    if (!$node->hasField($field)) {
      return [];
    }
    $labels = [];
    foreach ($node->get($field) as $item) {
      $entity = $item->entity;
      if ($entity !== NULL) {
        $labels[] = (string) $entity->label();
      }
    }
    return $labels;
  }

  /**
   * @return list<array{mid: int, alt: string, label: string}>
   *   ALT text already stored on media. File bytes are not read.
   */
  private function alts(NodeInterface $node): array {
    $rows = [];
    foreach (['field_images', 'field_cover_image', 'field_cover', 'field_video_cover'] as $name) {
      if (!$node->hasField($name)) {
        continue;
      }
      foreach ($node->get($name) as $item) {
        $media = $item->entity;
        if ($media === NULL || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
          continue;
        }
        $image = $media->get('field_media_image');
        $file = $image->entity;
        $rows[] = [
          'mid' => (int) $media->id(),
          'fid' => (int) $image->target_id,
          'field' => $name,
          'alt' => $image->alt,
          'filename' => $file !== NULL && method_exists($file, 'getFilename') ? (string) $file->getFilename() : '',
          'label' => (string) $media->label(),
        ];
      }
    }
    return $rows;
  }

  /**
   * @return array{title: string, description: string, title_source: string, description_source: string}
   *   Values inherited from Metatag defaults. There is no per-node metatag field.
   */
  private function meta(NodeInterface $node): array {
    $config = $this->configFactory->get('metatag.metatag_defaults.node__' . $node->bundle());
    $tags = $config->get('tags');
    if (!is_array($tags) || $tags === []) {
      $tags = $this->configFactory->get('metatag.metatag_defaults.node')->get('tags');
    }
    $tags = is_array($tags) ? $tags : [];
    $site = (string) $this->configFactory->get('system.site')->get('name');
    $replace = [
      '[node:title]' => (string) $node->label(),
      '[node:field_summary]' => $this->plain($node, 'field_summary'),
      '[node:field_categories:entity:name]' => implode('、', $this->labels($node, 'field_categories')),
      '[site:name]' => $site,
      '[node:summary]' => $this->plain($node, 'field_summary'),
    ];
    $fill = static function (string $pattern) use ($replace): string {
      $text = strtr($pattern, $replace);
      $text = preg_replace('/\[[^\]\s]+\]/', '', $text) ?? $text;
      return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    };
    return [
      'title' => $fill((string) ($tags['title'] ?? '')),
      'description' => $fill((string) ($tags['description'] ?? '')),
      'title_source' => '页面使用站点默认的 SEO 标题规则，内容本身没有单独的标题字段。规则：' . (string) ($tags['title'] ?? ''),
      'description_source' => '页面使用站点默认的描述规则，内容本身没有单独的描述字段。规则：' . (string) ($tags['description'] ?? ''),
    ];
  }

  private function plain(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $value = $node->get($field)->value;
    $text = trim(strip_tags((string) $value));
    return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
  }

}
