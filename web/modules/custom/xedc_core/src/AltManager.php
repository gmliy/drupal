<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Generates per-image ALT text and remembers manual edits.
 */
class AltManager {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * ALT text: title plus a 1-based sequence.
   */
  public function format(string $title, int $sequence, int $total): string {
    unset($total);
    return $this->cleanTitle($title) . '_第' . max(1, $sequence) . '张图';
  }

  /**
   * ALT for a cover that is not part of the gallery sequence.
   */
  public function cover(string $title): string {
    return $this->cleanTitle($title) . '_封面';
  }

  /**
   * Readable title with control characters and markup removed.
   */
  public function cleanTitle(string $title): string {
    $title = preg_replace('/[\x00-\x1F\x7F]+/u', '', $title) ?? $title;
    $title = str_replace(['<', '>', '"', "'", '\\'], '', $title);
    $title = preg_replace('/\s+/u', ' ', trim($title)) ?? '';
    $title = trim($title, " ._");
    if ($title === '') {
      return '图片';
    }
    return mb_substr($title, 0, 180);
  }

  /**
   * Stores the current ALT and whether it is still automatic.
   */
  public function remember(int $mid, string $alt, string $source): void {
    $this->database->merge('xedc_image_alt')
      ->keys(['mid' => $mid])
      ->fields([
        'source' => $source === 'manual' ? 'manual' : 'auto',
        'alt' => mb_substr($alt, 0, 512),
      ])
      ->execute();
  }

  /**
   * Marks an ALT manual when an editor changes the generated text.
   */
  public function markManualIfEdited(int $mid, string $alt): void {
    $row = $this->database->select('xedc_image_alt', 'a')
      ->fields('a', ['source', 'alt'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchObject();
    if (!$row || $row->source !== 'auto') {
      return;
    }
    if ((string) $row->alt !== $alt) {
      $this->remember($mid, $alt, 'manual');
    }
  }

  /**
   * Rewrites automatic ALT text from the current title and image order.
   *
   * Manual ALT values are left untouched.
   */
  public function refresh(NodeInterface $node): int {
    if (!$node->hasField('field_images') || $node->get('field_images')->isEmpty()) {
      return 0;
    }
    $mids = [];
    foreach ($node->get('field_images')->getValue() as $item) {
      $mid = (int) ($item['target_id'] ?? 0);
      if ($mid) {
        $mids[] = $mid;
      }
    }
    if (!$mids) {
      return 0;
    }
    $sources = $this->database->select('xedc_image_alt', 'a')
      ->fields('a', ['mid', 'source'])
      ->condition('mid', $mids, 'IN')
      ->execute()
      ->fetchAllKeyed();
    $medias = $this->entityTypeManager->getStorage('media')->loadMultiple($mids);
    $shared = $this->sharedMedia($mids, (int) $node->id());
    $total = count($mids);
    $updated = 0;
    foreach ($mids as $delta => $mid) {
      if (($sources[$mid] ?? '') !== 'auto' || isset($shared[$mid])) {
        continue;
      }
      $media = $medias[$mid] ?? NULL;
      if (!$media || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
        continue;
      }
      $alt = $this->format($node->label(), $delta + 1, $total);
      $media->xedc_import_internal = TRUE;
      $media->get('field_media_image')->first()->set('alt', $alt);
      $media->save();
      $this->remember($mid, $alt, 'auto');
      $updated++;
    }
    return $updated;
  }

  /**
   * Media already used by another photo, whose ALT must stay unchanged.
   *
   * @param int[] $mids
   *   Media ids on the current node.
   *
   * @return array<int, true>
   *   Shared media ids.
   */
  private function sharedMedia(array $mids, int $nid): array {
    $shared = [];
    $tables = [
      'node__field_images' => 'field_images_target_id',
      'node__field_cover_image' => 'field_cover_image_target_id',
    ];
    foreach ($tables as $table => $column) {
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }
      $ids = $this->database->select($table, 't')
        ->fields('t', [$column])
        ->condition($column, $mids, 'IN')
        ->condition('entity_id', $nid, '<>')
        ->execute()
        ->fetchCol();
      foreach ($ids as $id) {
        $shared[(int) $id] = TRUE;
      }
    }
    return $shared;
  }

}
