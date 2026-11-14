<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Reader data: pages, navigation, progress.
 */
final class ComicReaderManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ComicAccessManager $access,
    private readonly ComicStorageManager $storage,
    private readonly \Drupal\xedc_core\Ops\Storage\DeliveryUrl $delivery,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * @return array{items: array<int, array<string, mixed>>, total: int, offset: int, limit: int}
   */
  public function pageBatch(NodeInterface $chapter, int $offset, int $limit): array {
    if (!$this->access->canViewChapter($chapter)) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    $repo = \Drupal::service('xedc_comic.page_repository');
    $pages = $repo->loadChapterPages((int) $chapter->id(), $offset, $limit);
    $items = [];
    foreach ($pages as $page) {
      $items[] = $this->storage->pagePayload($page);
    }
    return [
      'items' => $items,
      'total' => $repo->countChapterPages((int) $chapter->id()),
      'offset' => $offset,
      'limit' => $limit,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function readerContext(NodeInterface $comic, NodeInterface $chapter): array {
    $chapters = \Drupal::service('xedc_comic.manager')->listChapters((int) $comic->id(), 500);
    $ids = array_values(array_map(static fn(NodeInterface $n) => (int) $n->id(), $chapters));
    $current = (int) $chapter->id();
    $index = array_search($current, $ids, TRUE);
    $prev = $index !== FALSE && $index > 0 ? $ids[$index - 1] : NULL;
    $next = $index !== FALSE && $index < count($ids) - 1 ? $ids[$index + 1] : NULL;
    $chapterNumber = $chapter->hasField('field_comic_number')
      ? (float) $chapter->get('field_comic_number')->value
      : 0;
    $chapterIndex = $index !== FALSE ? $index + 1 : 0;
    $comicCard = \Drupal::service('xedc_comic.manager')->comicCard($comic);
    $cover = (string) ($comicCard['cover'] ?? '');
    $chapterRows = [];
    foreach ($chapters as $ch) {
      if (!$ch instanceof NodeInterface) {
        continue;
      }
      $chapterRows[] = $this->chapterRow($comic, $ch);
    }
    usort($chapterRows, static fn(array $a, array $b) => $a['number'] <=> $b['number']);
    $prevChapter = NULL;
    $nextChapter = NULL;
    if ($prev) {
      $prevNode = $this->entities->getStorage('node')->load($prev);
      if ($prevNode instanceof NodeInterface) {
        $prevChapter = $this->chapterRow($comic, $prevNode);
      }
    }
    if ($next) {
      $nextNode = $this->entities->getStorage('node')->load($next);
      if ($nextNode instanceof NodeInterface) {
        $nextChapter = $this->chapterRow($comic, $nextNode, TRUE);
      }
    }
    if ($nextChapter && $cover !== '') {
      $nextChapter['preview'] = $nextChapter['preview'] ?: $cover;
    }
    return [
      'comic' => [
        'id' => (int) $comic->id(),
        'title' => $comic->label(),
        'url' => Url::fromRoute('xedc_comic.detail', ['comic' => $comic->id()])->toString(),
        'cover' => $cover,
      ],
      'chapter' => [
        'id' => $current,
        'title' => $chapter->label(),
        'number' => $chapterNumber,
        'index' => $chapterIndex,
        'total_chapters' => count($ids),
        'vip' => $this->access->chapterIsVip($chapter) && !$this->access->chapterIsFree($chapter),
        'pages' => \Drupal::service('xedc_comic.page_repository')->countChapterPages($current),
      ],
      'nav' => [
        'prev' => $prev,
        'next' => $next,
        'prev_url' => $prev
          ? Url::fromRoute('xedc_comic.reader', ['comic' => $comic->id(), 'chapter' => $prev])->toString()
          : '',
        'next_url' => $next
          ? Url::fromRoute('xedc_comic.reader', ['comic' => $comic->id(), 'chapter' => $next])->toString()
          : '',
      ],
      'chapters' => $chapterRows,
      'prev_chapter' => $prevChapter,
      'next_chapter' => $nextChapter,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function chapterRow(NodeInterface $comic, NodeInterface $chapter, bool $withPreview = FALSE): array {
    $published = $chapter->hasField('field_comic_publish_time') && !$chapter->get('field_comic_publish_time')->isEmpty()
      ? $this->dates->format((int) $chapter->get('field_comic_publish_time')->value, 'custom', 'Y-m-d', 'Asia/Shanghai')
      : $this->dates->format((int) $chapter->getCreatedTime(), 'custom', 'Y-m-d', 'Asia/Shanghai');
    $vip = $this->access->chapterIsVip($chapter) && !$this->access->chapterIsFree($chapter);
    $preview = '';
    if ($withPreview) {
      $repo = \Drupal::service('xedc_comic.page_repository');
      $pages = $repo->loadChapterPages((int) $chapter->id(), 0, 1);
      if ($pages) {
        $preview = (string) ($this->storage->pagePayload($pages[0])['url'] ?? '');
      }
    }
    return [
      'id' => (int) $chapter->id(),
      'title' => $chapter->label(),
      'number' => $chapter->hasField('field_comic_number') ? (float) $chapter->get('field_comic_number')->value : 0,
      'url' => Url::fromRoute('xedc_comic.reader', ['comic' => $comic->id(), 'chapter' => $chapter->id()])->toString(),
      'published' => $published,
      'locked' => $vip && !$this->access->canViewChapter($chapter),
      'preview' => $preview,
    ];
  }

}