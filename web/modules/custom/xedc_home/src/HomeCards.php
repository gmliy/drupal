<?php

namespace Drupal\xedc_home;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\views\Views;

/**
 * Builds homepage cards from Views and a short slideshow query.
 */
class HomeCards {

  /**
   * Card rows already built during this request.
   *
   * @var array<string, array<int, array<string, mixed>>>
   */
  protected array $runtime = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected DateFormatterInterface $dateFormatter,
    protected Connection $database,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Homepage carousel photos.
   *
   * Only published photos with field_slideshow checked are shown.
   * field_featured is the category-list pin and is not read here.
   * Empty slots are not filled with other photos.
   * Slide images come from field_cover_image.
   *
   * @return array<int, array<string, mixed>>
   *   Card rows, at most $limit.
   */
  public function slideshow(int $limit = 5): array {
    $key = 'slideshow:' . $limit;
    if (array_key_exists($key, $this->runtime)) {
      return $this->runtime[$key];
    }
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'photo')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_slideshow', 1)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    $picked = array_map('intval', array_values($nids));
    return $this->runtime[$key] = $this->cardsInOrder($picked, 'field_cover_image', 'xa_hero', 'slide');
  }

  /**
   * Loads one homepage View display and maps the rows to cards.
   *
   * @return array<int, array<string, mixed>>
   *   Card rows in view order.
   */
  public function fromView(string $display, string $imageField, string $style, string $kind): array {
    $key = $display . ':' . $imageField . ':' . $style . ':' . $kind;
    if (array_key_exists($key, $this->runtime)) {
      return $this->runtime[$key];
    }
    $view = Views::getView('xedc_home');
    if (!$view || !$view->setDisplay($display)) {
      return $this->runtime[$key] = [];
    }
    $view->execute();
    $nids = [];
    foreach ($view->result as $row) {
      $nid = 0;
      if (!empty($row->_entity) && $row->_entity instanceof NodeInterface) {
        $nid = (int) $row->_entity->id();
      }
      elseif (!empty($row->nid)) {
        $nid = (int) $row->nid;
      }
      if ($nid && !in_array($nid, $nids, TRUE)) {
        $nids[] = $nid;
      }
    }
    return $this->runtime[$key] = $this->cardsInOrder($nids, $imageField, $style, $kind);
  }

  /**
   * Photo categories that have at least one published photo.
   *
   * Terms come from the photo_category vocabulary referenced by
   * field_categories. Counts include published photo nodes only.
   * Order is vocabulary weight, then name. The URL is the term alias.
   *
   * @return array<int, array{name: string, url: string, count: int}>
   *   Category chips.
   */
  public function categories(): array {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $term_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'photo_category')
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC')
      ->range(0, 200)
      ->execute();
    if (!$tids) {
      return [];
    }
    $cached = $this->cache->get('xedc_home:photo_category_counts');
    if ($cached) {
      $counts = $cached->data;
    }
    else {
      $counts = $this->database->query(
        "SELECT c.field_categories_target_id AS tid, COUNT(DISTINCT c.entity_id) AS cnt
         FROM {node__field_categories} c
         INNER JOIN {node_field_data} n ON n.nid = c.entity_id AND n.langcode = c.langcode
         WHERE c.deleted = 0 AND n.status = :status AND n.type = :type
         GROUP BY c.field_categories_target_id",
        [':status' => NodeInterface::PUBLISHED, ':type' => 'photo']
      )->fetchAllKeyed();
      $this->cache->set('xedc_home:photo_category_counts', $counts, Cache::PERMANENT, ['node_list:photo']);
    }
    $terms = $term_storage->loadMultiple($tids);
    $repository = \Drupal::service('entity.repository');
    $out = [];
    foreach ($tids as $tid) {
      $count = (int) ($counts[$tid] ?? 0);
      if ($count < 1 || !isset($terms[$tid])) {
        continue;
      }
      $term = $repository->getTranslationFromContext($terms[$tid]);
      $out[] = [
        'name' => $term->label(),
        'url' => $term->toUrl()->toString(),
        'count' => $count,
      ];
    }
    return $out;
  }

  /**
   * @param int[] $nids
   *   Node IDs in display order.
   *
   * @return array<int, array<string, mixed>>
   *   Card rows.
   */
  protected function cardsInOrder(array $nids, string $image_field, string $style, string $kind): array {
    if (!$nids) {
      return [];
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $this->preloadCardReferences($nodes, $image_field, $kind);
    $items = [];
    foreach ($nids as $nid) {
      if (isset($nodes[$nid]) && $nodes[$nid] instanceof NodeInterface) {
        $items[] = $this->card($nodes[$nid], $image_field, $style, $kind);
      }
    }
    return $items;
  }

  /**
   * @return array<string, mixed>
   *   One card.
   */
  protected function card(NodeInterface $node, string $image_field, string $style, string $kind): array {
    $translated = \Drupal::service('entity.repository')->getTranslationFromContext($node);
    if ($translated instanceof NodeInterface) {
      $node = $translated;
    }
    $image = $this->image($node, $image_field, $style);
    $summary = '';
    if ($kind === 'slide') {
      $summary = $this->plainText($node, 'field_summary', 60);
    }
    elseif ($kind === 'article') {
      $summary = $this->plainText($node, 'field_body', 120);
    }
    $count = 0;
    if ($kind === 'photo' && $node->hasField('field_images')) {
      $count = (int) $node->get('field_images')->count();
    }
    return [
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
      'img' => $image['url'],
      'srcset' => $image['srcset'] ?? '',
      'width' => $image['width'],
      'height' => $image['height'],
      'count' => $count,
      'duration' => $kind === 'video' ? $this->formatDuration($node) : '',
      'model' => $kind === 'photo' ? $this->modelName($node) : '',
      'summary' => $summary,
      'date' => $kind === 'article' ? $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'm-d') : '',
    ];
  }

  /**
   * Loads cover media, files and model terms before cards are built.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Nodes about to be rendered.
   */
  protected function preloadCardReferences(array $nodes, string $image_field, string $kind): void {
    $media_ids = [];
    $term_ids = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($node->hasField($image_field) && !$node->get($image_field)->isEmpty()) {
        $media_ids[] = $node->get($image_field)->target_id;
      }
      if ($kind === 'photo' && $node->hasField('field_model')) {
        foreach ($node->get('field_model')->getValue() as $item) {
          if (!empty($item['target_id'])) {
            $term_ids[] = $item['target_id'];
          }
        }
      }
    }
    $medias = $media_ids ? $this->entityTypeManager->getStorage('media')->loadMultiple($media_ids) : [];
    $file_ids = [];
    foreach ($medias as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }
      if ($media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
        $file_ids[] = $media->get('field_media_image')->target_id;
      }
      elseif ($media->hasField('thumbnail') && !$media->get('thumbnail')->isEmpty()) {
        $file_ids[] = $media->get('thumbnail')->target_id;
      }
    }
    if ($file_ids) {
      $this->entityTypeManager->getStorage('file')->loadMultiple($file_ids);
    }
    if ($term_ids) {
      $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids);
    }
  }

  /**
   * Model names from field_model, joined for a single line.
   */
  protected function modelName(NodeInterface $node): string {
    if (!$node->hasField('field_model') || $node->get('field_model')->isEmpty()) {
      return '';
    }
    $names = [];
    $repository = \Drupal::service('entity.repository');
    foreach ($node->get('field_model')->referencedEntities() as $term) {
      $label = trim((string) $repository->getTranslationFromContext($term)->label());
      if ($label !== '') {
        $names[] = $label;
      }
    }
    return implode(' · ', $names);
  }

  /**
   * @return array{url: string, width: int, height: int, srcset: string}
   *   Styled image URL and dimensions.
   */
  protected function image(NodeInterface $node, string $field, string $style_name): array {
    $empty = ['url' => '', 'width' => 0, 'height' => 0, 'srcset' => ''];
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return $empty;
    }
    $entity = $node->get($field)->entity;
    $file = NULL;
    $width = 0;
    $height = 0;
    if ($entity instanceof MediaInterface) {
      if ($entity->hasField('field_media_image') && !$entity->get('field_media_image')->isEmpty()) {
        $item = $entity->get('field_media_image')->first();
        if ($item) {
          $width = (int) ($item->width ?? 0);
          $height = (int) ($item->height ?? 0);
          $file = $item->entity;
        }
      }
      if (!$file instanceof FileInterface && $entity->hasField('thumbnail') && !$entity->get('thumbnail')->isEmpty()) {
        $file = $entity->get('thumbnail')->entity;
      }
    }
    elseif ($entity instanceof FileInterface) {
      $file = $entity;
    }
    if (!$file instanceof FileInterface) {
      return $empty;
    }
    $style = ImageStyle::load($style_name);
    $url = $style ? $style->buildUrl($file->getFileUri()) : $this->fileUrlGenerator->generateString($file->getFileUri());
    $srcset = '';
    if ($style_name === 'xa_hero' && $style) {
      $small = ImageStyle::load('xa_hero_840');
      if ($small) {
        $srcset = $small->buildUrl($file->getFileUri()) . ' 840w, ' . $url . ' 1200w';
      }
    }
    if ($style) {
      foreach ($style->getEffects() as $effect) {
        $data = $effect->getConfiguration()['data'] ?? [];
        if (!empty($data['width']) && !empty($data['height'])) {
          $width = (int) $data['width'];
          $height = (int) $data['height'];
          break;
        }
      }
    }
    return ['url' => $url, 'width' => $width, 'height' => $height, 'srcset' => $srcset];
  }

  /**
   * Formats a duration stored as whole seconds.
   */
  protected function formatDuration(NodeInterface $node): string {
    if (!$node->hasField('field_duration') || $node->get('field_duration')->isEmpty()) {
      return '';
    }
    $seconds = (int) $node->get('field_duration')->value;
    if (\Drupal::hasService('xedc_core.video_duration_formatter')) {
      return \Drupal::service('xedc_core.video_duration_formatter')->format($seconds);
    }
    return '';
  }

  /**
   * Plain excerpt trimmed to a character count.
   */
  protected function plainText(NodeInterface $node, string $field, int $length): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $item = $node->get($field)->first();
    if (!$item) {
      return '';
    }
    $text = '';
    if (isset($item->summary) && trim((string) $item->summary) !== '') {
      $text = (string) $item->summary;
    }
    else {
      $text = (string) ($item->value ?? '');
    }
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if ($text === '') {
      return '';
    }
    if (mb_strlen($text) <= $length) {
      return $text;
    }
    return mb_substr($text, 0, $length) . '…';
  }

}
