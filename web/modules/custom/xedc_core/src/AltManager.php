<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Generates image ALT as model name + work title and remembers manual edits.
 */
class AltManager {

  /**
   * Soft cap for generated ALT text in Chinese characters / code points.
   */
  private const MAX_LENGTH = 60;

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds ALT from a photo node: model names then work title.
   */
  public function forNode(NodeInterface $node): string {
    return $this->compose($this->modelsFromNode($node), (string) $node->label());
  }

  /**
   * Builds ALT from raw model label(s) and work title.
   *
   * @param string|string[] $models
   *   One model name or ordered list of model names.
   */
  public function compose(string|array $models, string $title): string {
    $modelText = $this->modelsText($models);
    $titleText = $this->cleanPart($title);
    if ($modelText === '' && $titleText === '') {
      return '';
    }
    if ($modelText === '') {
      return $this->limit($titleText, self::MAX_LENGTH);
    }
    if ($titleText === '') {
      return $this->limit($modelText, self::MAX_LENGTH);
    }
    // Avoid "小雪小雪夏日" when the title already starts with the model name.
    if ($titleText === $modelText || str_starts_with($titleText, $modelText)) {
      return $this->limit($titleText, self::MAX_LENGTH);
    }
    $remain = self::MAX_LENGTH - mb_strlen($modelText);
    if ($remain < 1) {
      return $this->limit($modelText, self::MAX_LENGTH);
    }
    return $modelText . $this->limit($titleText, $remain);
  }

  /**
   * Legacy entry used by older callers. Sequence is ignored; models optional.
   *
   * @param string|string[] $models
   *   Optional model name(s). When omitted, only the title is used.
   */
  public function format(string $title, int $sequence = 0, int $total = 0, string|array $models = ''): string {
    unset($sequence, $total);
    return $this->compose($models, $title);
  }

  /**
   * Cover ALT uses the same model + title rule.
   */
  public function cover(string $title, string|array $models = ''): string {
    return $this->compose($models, $title);
  }

  /**
   * Readable title with control characters and markup removed.
   *
   * Empty input stays empty. Callers that need a non-empty fallback must
   * decide separately; ALT generation does not invent placeholder text.
   */
  public function cleanTitle(string $title): string {
    return $this->cleanPart($title);
  }

  /**
   * Model labels from field_model in field order, without duplicates.
   */
  public function modelsFromNode(NodeInterface $node): string {
    if (!$node->hasField('field_model') || $node->get('field_model')->isEmpty()) {
      return '';
    }
    $names = [];
    foreach ($node->get('field_model')->referencedEntities() as $term) {
      $name = $this->cleanPart((string) $term->label());
      if ($name !== '' && !in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }
    return implode('', $names);
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
   * Rewrites automatic ALT text from the current models and title.
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
    $alt = $this->forNode($node);
    $updated = 0;
    foreach ($mids as $mid) {
      if (($sources[$mid] ?? '') === 'manual' || isset($shared[$mid])) {
        continue;
      }
      // Only rewrite rows we previously marked auto, or empty ALT with no row.
      $source = $sources[$mid] ?? '';
      $media = $medias[$mid] ?? NULL;
      if (!$media || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
        continue;
      }
      $current = trim((string) $media->get('field_media_image')->alt);
      if ($source === 'manual') {
        continue;
      }
      if ($source !== 'auto' && $current !== '') {
        // Existing unexplained ALT: treat as manual and never overwrite.
        $this->remember($mid, $current, 'manual');
        continue;
      }
      if ($alt === '' || $current === $alt) {
        if ($alt !== '' && $source !== 'auto') {
          $this->remember($mid, $alt, 'auto');
        }
        continue;
      }
      $media->xedc_import_internal = TRUE;
      $media->get('field_media_image')->first()->set('alt', $alt);
      $media->save();
      $this->remember($mid, $alt, 'auto');
      $updated++;
    }
    return $updated;
  }

  /**
   * @param string|string[] $models
   *   Model names.
   */
  private function modelsText(string|array $models): string {
    if (is_string($models)) {
      return $this->cleanPart($models);
    }
    $names = [];
    foreach ($models as $model) {
      $name = $this->cleanPart((string) $model);
      if ($name !== '' && !in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }
    return implode('', $names);
  }

  private function cleanPart(string $text): string {
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', '', $text) ?? $text;
    $text = str_replace(['<', '>', '"', "'", '\\'], '', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    return trim($text, " ._");
  }

  private function limit(string $text, int $max): string {
    if ($max < 1 || $text === '') {
      return '';
    }
    return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max);
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
