<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Original image files for a photo, never image-style derivatives.
 */
final class OriginalFiles {

  /**
   * @return list<array{fid: int, filename: string, uri: string, size: int, extension: string, mime: string, changed: int}>
   *   Original managed files in gallery order.
   */
  public function forNode(NodeInterface $node): array {
    if ($node->bundle() !== 'photo') {
      return [];
    }
    $media_items = [];
    if ($node->hasField('field_images') && !$node->get('field_images')->isEmpty()) {
      foreach ($node->get('field_images')->referencedEntities() as $media) {
        if ($media instanceof MediaInterface) {
          $media_items[] = $media;
        }
      }
    }
    if ($node->hasField('field_cover_image') && !$node->get('field_cover_image')->isEmpty()) {
      $cover = $node->get('field_cover_image')->entity;
      if ($cover instanceof MediaInterface) {
        $media_items[] = $cover;
      }
    }
    $ids = [];
    foreach ($media_items as $media) {
      $fid = (int) $media->getSource()->getSourceFieldValue($media);
      if ($fid > 0) {
        $ids[] = $fid;
      }
    }
    $files = $ids ? \Drupal::entityTypeManager()->getStorage('file')->loadMultiple(array_values(array_unique($ids))) : [];
    $items = [];
    $seen = [];
    foreach ($media_items as $media) {
      $fid = (int) $media->getSource()->getSourceFieldValue($media);
      if ($fid < 1 || isset($seen[$fid]) || !isset($files[$fid]) || !$files[$fid] instanceof FileInterface) {
        continue;
      }
      $seen[$fid] = TRUE;
      $entity = $files[$fid];
      $name = (string) $entity->getFilename();
      $items[] = [
        'fid' => $fid,
        'filename' => $name,
        'uri' => (string) $entity->getFileUri(),
        'size' => (int) $entity->getSize(),
        'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        'mime' => (string) ($entity->getMimeType() ?: 'application/octet-stream'),
        'changed' => (int) $entity->getChangedTime(),
      ];
    }
    return $items;
  }

  /**
   * @return array{fid: int, filename: string, uri: string, size: int, extension: string, mime: string}|null
   *   One original that belongs to the node.
   */
  public function one(NodeInterface $node, int $fid): ?array {
    foreach ($this->forNode($node) as $item) {
      if ($item['fid'] === $fid) {
        return $item;
      }
    }
    return NULL;
  }

}
