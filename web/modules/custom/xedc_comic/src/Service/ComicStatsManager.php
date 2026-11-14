<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * View counts with flood protection and daily aggregates.
 */
final class ComicStatsManager {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheBackendInterface $cache,
    private readonly FloodInterface $flood,
    private readonly RequestStack $requestStack,
  ) {}

  public function recordChapterView(int $comicId, int $chapterId): void {
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request?->getClientIp() ?? '0';
    $identifier = $ip . ':' . $chapterId;
    $window = (int) \Drupal::config('xedc_comic.settings')->get('reading.view_flood_window') ?: 3600;
    $threshold = (int) \Drupal::config('xedc_comic.settings')->get('reading.view_flood_threshold') ?: 30;
    if (!$this->flood->isAllowed('xedc_comic_view', $threshold, $window, $identifier)) {
      return;
    }
    $this->flood->register('xedc_comic_view', $window, $identifier);
    $day = (int) gmdate('Ymd', $this->time->getRequestTime());
    $this->database->merge('xedc_comic_stats_daily')
      ->keys(['day' => $day, 'comic_id' => $comicId, 'chapter_id' => $chapterId])
      ->fields(['views' => 1])
      ->expression('views', 'views + 1')
      ->execute();
    $this->database->merge('xedc_comic_stats_daily')
      ->keys(['day' => $day, 'comic_id' => $comicId, 'chapter_id' => 0])
      ->fields(['views' => 1])
      ->expression('views', 'views + 1')
      ->execute();
    $total = (int) $this->database->select('xedc_comic_stats_daily', 's')
      ->condition('comic_id', $comicId)
      ->condition('chapter_id', 0)
      ->fields('s', [])
      ->execute()
      ->fetchCol();
    $sum = 0;
    foreach ($this->database->select('xedc_comic_stats_daily', 's')
      ->condition('comic_id', $comicId)
      ->condition('chapter_id', 0)
      ->fields('s', ['views'])
      ->execute()
      ->fetchCol() as $v) {
      $sum += (int) $v;
    }
    \Drupal::service('xedc_comic.manager')->setCounterField($comicId, 'field_comic_views', $sum);
    Cache::invalidateTags(['xedc_comic_stats:' . $comicId]);
  }

  /**
   * @return array<string, mixed>
   */
  public function comicSummary(int $comicId): array {
    $cid = 'xedc_comic:stats:' . $comicId;
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }
    $now = $this->time->getRequestTime();
    $today = (int) gmdate('Ymd', $now);
    $week = (int) gmdate('Ymd', $now - 6 * 86400);
    $month = (int) gmdate('Ymd', $now - 29 * 86400);
    $sum = 0;
    $todayViews = 0;
    $weekViews = 0;
    $monthViews = 0;
    $rows = $this->database->select('xedc_comic_stats_daily', 's')
      ->fields('s', ['day', 'views'])
      ->condition('comic_id', $comicId)
      ->condition('chapter_id', 0)
      ->execute()
      ->fetchAll();
    foreach ($rows as $row) {
      $views = (int) $row->views;
      $day = (int) $row->day;
      $sum += $views;
      if ($day === $today) {
        $todayViews += $views;
      }
      if ($day >= $week) {
        $weekViews += $views;
      }
      if ($day >= $month) {
        $monthViews += $views;
      }
    }
    $data = [
      'total' => $sum,
      'today' => $todayViews,
      'week' => $weekViews,
      'month' => $monthViews,
    ];
    $this->cache->set($cid, $data, $this->time->getRequestTime() + 300, ['xedc_comic_stats:' . $comicId]);
    return $data;
  }

  /**
   * @return list<array{comic_id: int, views: int}>
   */
  public function topComics(int $days = 7, int $limit = 10): array {
    $since = (int) gmdate('Ymd', $this->time->getRequestTime() - ($days - 1) * 86400);
    $query = $this->database->select('xedc_comic_stats_daily', 's');
    $query->addExpression('SUM(views)', 'views');
    $query->fields('s', ['comic_id']);
    $query->condition('chapter_id', 0);
    $query->condition('day', $since, '>=');
    $query->groupBy('comic_id');
    $query->orderBy('views', 'DESC');
    $query->range(0, $limit);
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

}