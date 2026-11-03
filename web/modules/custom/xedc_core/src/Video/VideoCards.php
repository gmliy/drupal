<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds the shared video card data used by lists, tags and recommendations.
 */
final class VideoCards {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly EntityRepositoryInterface $repository,
    private readonly FileUrlGeneratorInterface $urls,
    private readonly DateFormatterInterface $dates,
    private readonly DurationFormatter $formatter,
    private readonly PlaybackStats $stats,
  ) {}

  /**
   * @param int[] $nids
   * @return array{items: array<int, array<string, mixed>>, cache_tags: string[]}
   */
  public function build(array $nids): array {
    $ordered = [];
    foreach ($nids as $nid) {
      $nid = (int) $nid;
      if ($nid > 0) {
        $ordered[] = $nid;
      }
    }
    if (!$ordered) {
      return ['items' => [], 'cache_tags' => ['config:image.style.video_cover']];
    }
    $nodes = $this->entities->getStorage('node')->loadMultiple($ordered);
    $counts = $this->stats->counts($ordered);
    $owners = $this->owners($nodes);
    $items = [];
    $tags = ['config:image.style.video_cover', 'config:image.style.xa_hero'];
    foreach ($ordered as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node instanceof NodeInterface || $node->bundle() !== 'video') {
        continue;
      }
      $node = $this->repository->getTranslationFromContext($node);
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
      $cover = $this->cover($node, $tags);
      $seconds = 0;
      if ($node->hasField('field_duration') && !$node->get('field_duration')->isEmpty()) {
        $seconds = (int) $node->get('field_duration')->value;
      }
      $items[] = [
        'nid' => (int) $node->id(),
        'url' => $node->toUrl()->toString(),
        'title' => $node->label(),
        'cover' => $cover['url'],
        'srcset' => $cover['srcset'],
        'alt' => $cover['alt'] !== '' ? $cover['alt'] : $node->label(),
        'width' => $cover['width'],
        'height' => $cover['height'],
        'duration' => $this->formatter->format($seconds),
        'views' => $counts[(int) $node->id()] ?? 0,
        'date' => $this->dates->format($node->getCreatedTime(), 'custom', 'Y-m-d'),
        'category' => $this->categoryLabel($node),
        'author' => $owners[(int) $node->getOwnerId()]['name'] ?? '',
        'author_url' => $owners[(int) $node->getOwnerId()]['url'] ?? '',
        'heading' => 'h2',
      ];
    }
    return ['items' => $items, 'cache_tags' => $tags];
  }

  /**
   * @param string[] $tags
   * @return array{url: string, srcset: string, alt: string, width: int, height: int}
   */
  /**
   * @param array<int, \Drupal\node\NodeInterface> $nodes
   * @return array<int, array{name: string, url: string}>
   */
  private function owners(array $nodes): array {
    $ids = [];
    foreach ($nodes as $node) {
      $uid = (int) $node->getOwnerId();
      if ($uid > 0) {
        $ids[$uid] = $uid;
      }
    }
    if (!$ids) {
      return [];
    }
    $owners = [];
    foreach ($this->entities->getStorage('user')->loadMultiple($ids) as $user) {
      if (!(int) $user->id()) {
        continue;
      }
      $owners[(int) $user->id()] = [
        'name' => $user->getDisplayName(),
        'url' => $user->toUrl()->toString(),
      ];
    }
    return $owners;
  }

  private function categoryLabel(NodeInterface $node): string {
    if (!$node->hasField('field_categories') || $node->get('field_categories')->isEmpty()) {
      return '';
    }
    $term = $node->get('field_categories')->entity;
    if (!$term instanceof \Drupal\taxonomy\TermInterface) {
      return '';
    }
    return $this->repository->getTranslationFromContext($term)->label();
  }

  private function cover(NodeInterface $node, array &$tags): array {
    $empty = ['url' => '', 'srcset' => '', 'alt' => '', 'width' => 640, 'height' => 360];
    if (!$node->hasField('field_video_cover') || $node->get('field_video_cover')->isEmpty()) {
      return $empty;
    }
    $media = $node->get('field_video_cover')->entity;
    if (!$media instanceof MediaInterface || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return $empty;
    }
    $item = $media->get('field_media_image')->first();
    $file = $item ? $item->entity : NULL;
    if (!$file instanceof FileInterface) {
      return $empty;
    }
    $tags = Cache::mergeTags($tags, $media->getCacheTags(), $file->getCacheTags());
    $uri = $file->getFileUri();
    $card = ImageStyle::load('video_cover');
    $hero = ImageStyle::load('xa_hero');
    $card_url = $card ? $card->buildUrl($uri) : $this->urls->generateAbsoluteString($uri);
    $relative = $this->urls->transformRelative($card_url);
    $srcset = '';
    if ($card && $hero) {
      $hero_url = $this->urls->transformRelative($hero->buildUrl($uri));
      $srcset = $relative . ' 640w, ' . $hero_url . ' 1200w';
      $tags = Cache::mergeTags($tags, $card->getCacheTags(), $hero->getCacheTags());
    }
    elseif ($card) {
      $tags = Cache::mergeTags($tags, $card->getCacheTags());
    }
    return [
      'url' => $relative,
      'srcset' => $srcset,
      'alt' => trim((string) ($item->alt ?? '')),
      'width' => 640,
      'height' => 360,
    ];
  }

}
