<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Comic favorites stored in-module (xedc_member ALLOWED_BUNDLES unchanged).
 */
final class ComicFavoriteStore {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function toggle(int $comicId): bool {
    $uid = (int) $this->currentUser->id();
    if ($uid < 1) {
      throw new \RuntimeException('请先登录。');
    }
    $exists = $this->database->select('xedc_comic_favorite', 'f')
      ->condition('uid', $uid)
      ->condition('comic_id', $comicId)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($exists) {
      $this->database->delete('xedc_comic_favorite')
        ->condition('uid', $uid)
        ->condition('comic_id', $comicId)
        ->execute();
      $this->syncFavoriteCount($comicId);
      return FALSE;
    }
    $this->database->insert('xedc_comic_favorite')
      ->fields([
        'uid' => $uid,
        'comic_id' => $comicId,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    $this->syncFavoriteCount($comicId);
    return TRUE;
  }

  public function isFavorited(int $comicId, ?int $uid = NULL): bool {
    $uid = $uid ?? (int) $this->currentUser->id();
    if ($uid < 1) {
      return FALSE;
    }
    return (bool) $this->database->select('xedc_comic_favorite', 'f')
      ->condition('uid', $uid)
      ->condition('comic_id', $comicId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function countForComic(int $comicId): int {
    return (int) $this->database->select('xedc_comic_favorite', 'f')
      ->condition('comic_id', $comicId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * @return list<array{comic_id: int, created: int}>
   */
  public function listForUser(int $uid, int $limit, int $offset): array {
    if ($uid < 1) {
      return [];
    }
    return $this->database->select('xedc_comic_favorite', 'f')
      ->fields('f', ['comic_id', 'created'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->range($offset, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function countForUser(int $uid): int {
    if ($uid < 1) {
      return 0;
    }
    return (int) $this->database->select('xedc_comic_favorite', 'f')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  private function syncFavoriteCount(int $comicId): void {
    $count = $this->countForComic($comicId);
    \Drupal::service('xedc_comic.manager')->setCounterField($comicId, 'field_comic_favorites', $count);
  }

}