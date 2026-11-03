<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
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
    $tags = ['config:image.style.video_cover', 'config:image.style.xa_hero'];
    $owners = $this->owners($nodes, $tags);
    $items = [];
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
        'author_avatar' => $owners[(int) $node->getOwnerId()]['avatar'] ?? '',
        'heading' => 'h2',
      ];
    }
    return ['items' => $items, 'cache_tags' => $tags];
  }

  /**
   * @param array<int, \Drupal\node\NodeInterface> $nodes
   * @param string[] $tags
   * @return array<int, array{name: string, url: string, avatar: string}>
   */
  private function owners(array $nodes, array &$tags): array {
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
    $slugs = [];
    if (\Drupal::moduleHandler()->moduleExists('xedc_member') && \Drupal::hasService('xedc_member.alias_manager')) {
      $slugs = \Drupal::service('xedc_member.alias_manager')->slugsFor($ids);
    }
    $owners = [];
    foreach ($this->entities->getStorage('user')->loadMultiple($ids) as $user) {
      $uid = (int) $user->id();
      if ($uid < 1) {
        continue;
      }
      $tags = Cache::mergeTags($tags, $user->getCacheTags());
      $name = $user->getDisplayName();
      if ($user->hasField('field_xedc_nickname') && !$user->get('field_xedc_nickname')->isEmpty()) {
        $nickname = trim((string) $user->get('field_xedc_nickname')->value);
        if ($nickname !== '') {
          $name = $nickname;
        }
      }
      $avatar = '';
      if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
        $picture = $user->get('user_picture')->entity;
        if ($picture instanceof FileInterface) {
          $avatar = $this->urls->generateString($picture->getFileUri());
          $tags = Cache::mergeTags($tags, $picture->getCacheTags());
        }
      }
      $url = '';
      $slug = $slugs[$uid] ?? '';
      if (is_string($slug) && $slug !== '' && !str_contains($slug, '/')) {
        try {
          $url = Url::fromRoute('xedc_member.public_profile', ['slug' => $slug])->toString();
        }
        catch (\Throwable) {
          $url = '';
        }
      }
      $owners[$uid] = [
        'name' => $name,
        'url' => $url,
        'avatar' => $avatar,
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
