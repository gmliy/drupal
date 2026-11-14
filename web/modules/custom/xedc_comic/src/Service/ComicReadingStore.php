<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Per-user reading history and progress.
 */
final class ComicReadingStore {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function saveProgress(int $comicId, int $chapterId, int $page, float $progress, string $mode): void {
    $uid = (int) $this->currentUser->id();
    if ($uid < 1) {
      return;
    }
    $now = $this->time->getRequestTime();
    $this->database->merge('xedc_comic_reading')
      ->keys(['uid' => $uid, 'comic_id' => $comicId])
      ->fields([
        'chapter_id' => $chapterId,
        'page_number' => max(1, $page),
        'progress' => min(1, max(0, $progress)),
        'reading_mode' => in_array($mode, ['scroll', 'single', 'spread'], TRUE) ? $mode : 'scroll',
        'last_read' => $now,
      ])
      ->execute();
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function recentForUser(int $uid, int $limit = 12): array {
    if ($uid < 1) {
      return [];
    }
    return $this->database->select('xedc_comic_reading', 'r')
      ->fields('r')
      ->condition('uid', $uid)
      ->orderBy('last_read', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function load(int $comicId, ?int $uid = NULL): ?array {
    $uid = $uid ?? (int) $this->currentUser->id();
    if ($uid < 1) {
      return NULL;
    }
    $row = $this->database->select('xedc_comic_reading', 'r')
      ->fields('r')
      ->condition('uid', $uid)
      ->condition('comic_id', $comicId)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listForUser(int $uid, int $limit, int $offset): array {
    if ($uid < 1) {
      return [];
    }
    return $this->database->select('xedc_comic_reading', 'r')
      ->fields('r')
      ->condition('uid', $uid)
      ->orderBy('last_read', 'DESC')
      ->range($offset, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function countForUser(int $uid): int {
    if ($uid < 1) {
      return 0;
    }
    return (int) $this->database->select('xedc_comic_reading', 'r')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function deleteForUser(int $uid, int $comicId): bool {
    if ($uid < 1 || $comicId < 1) {
      return FALSE;
    }
    return (bool) $this->database->delete('xedc_comic_reading')
      ->condition('uid', $uid)
      ->condition('comic_id', $comicId)
      ->execute();
  }

}