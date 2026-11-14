<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\xedc_comic\Entity\ComicPage;

/**
 * Page queries with paging.
 */
final class ComicPageRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  /**
   * @return \Drupal\xedc_comic\Entity\ComicPage[]
   */
  public function loadChapterPages(int $chapterId, int $offset = 0, int $limit = 20): array {
    $ids = $this->entities->getStorage('comic_page')->getQuery()
      ->accessCheck(FALSE)
      ->condition('chapter_id', $chapterId)
      ->sort('page_number')
      ->range($offset, $limit)
      ->execute();
    if ($ids === []) {
      return [];
    }
    $pages = $this->entities->getStorage('comic_page')->loadMultiple($ids);
    $ordered = [];
    foreach ($ids as $id) {
      if (isset($pages[$id]) && $pages[$id] instanceof ComicPage) {
        $ordered[] = $pages[$id];
      }
    }
    return $ordered;
  }

  public function countChapterPages(int $chapterId): int {
    return (int) $this->entities->getStorage('comic_page')->getQuery()
      ->accessCheck(FALSE)
      ->condition('chapter_id', $chapterId)
      ->count()
      ->execute();
  }

  public function maxPageNumber(int $chapterId): int {
    $value = $this->database->select('comic_page', 'p')
      ->condition('chapter_id', $chapterId)
      ->fields('p', [])
      ->orderBy('page_number', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($value) ? (int) ($value['page_number'] ?? 0) : 0;
  }

  /**
   * @return int[]
   */
  public function pageIdsForChapter(int $chapterId): array {
    return array_map('intval', $this->entities->getStorage('comic_page')->getQuery()
      ->accessCheck(FALSE)
      ->condition('chapter_id', $chapterId)
      ->sort('page_number')
      ->execute());
  }

}