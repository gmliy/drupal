<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
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
    return [
      'comic' => [
        'id' => (int) $comic->id(),
        'title' => $comic->label(),
        'url' => Url::fromRoute('xedc_comic.detail', ['comic' => $comic->id()])->toString(),
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
      ],
    ];
  }

}