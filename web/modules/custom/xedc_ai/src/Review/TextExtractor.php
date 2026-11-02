<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Reads the text fields that a review is allowed to see.
 */
final class TextExtractor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
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

  public function loadRevision(string $entityType, int $entityId, int $revisionId): ?NodeInterface {
    if ($entityType !== 'node') {
      return NULL;
    }
    $storage = $this->entities->getStorage('node');
    $node = $revisionId > 0 ? $storage->loadRevision($revisionId) : NULL;
    if (!$node instanceof NodeInterface) {
      $node = $storage->load($entityId);
    }
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

  private function plain(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $value = $node->get($field)->value;
    $text = trim(strip_tags((string) $value));
    return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
  }

}
