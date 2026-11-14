<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Dashboard metrics for the comic admin overview.
 */
final class ComicAdminMetrics {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ComicStatsManager $stats,
    private readonly ComicImportManager $imports,
  ) {}

  /**
   * @return array<string, int>
   */
  public function statCards(): array {
    $nodeStorage = $this->entities->getStorage('node');
    $comics = (int) $nodeStorage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'comic')->count()->execute();
    $published = (int) $nodeStorage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'comic')->condition('status', 1)->count()->execute();
    $chapters = (int) $nodeStorage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'comic_chapter')->count()->execute();
    $pages = (int) $this->entities->getStorage('comic_page')->getQuery()
      ->accessCheck(FALSE)->count()->execute();
    $tz = new \DateTimeZone('Asia/Shanghai');
    $midnight = (new \DateTimeImmutable('today', $tz))->getTimestamp();
    $chaptersToday = (int) $nodeStorage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'comic_chapter')
      ->condition('created', $midnight, '>=')
      ->count()->execute();
    $ongoing = (int) $nodeStorage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'comic')
      ->condition('field_comic_status', 'ongoing')
      ->count()->execute();
    $vipChapters = (int) $nodeStorage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'comic_chapter')
      ->condition('field_comic_vip', 1)
      ->count()->execute();
    $totalReads = 0;
    if ($this->database->schema()->tableExists('xedc_comic_stats_daily')) {
      $sumQuery = $this->database->select('xedc_comic_stats_daily', 's');
      $sumQuery->condition('chapter_id', 0);
      $sumQuery->addExpression('SUM(views)', 'total');
      $totalReads = (int) $sumQuery->execute()->fetchField();
    }
    if ($totalReads < 1 && $this->database->schema()->tableExists('node__field_comic_views')) {
      $viewsQuery = $this->database->select('node__field_comic_views', 'v');
      $viewsQuery->addExpression('SUM(v.field_comic_views_value)', 's');
      $totalReads = (int) $viewsQuery->execute()->fetchField();
    }
    return [
      'comics' => $comics,
      'published' => $published,
      'ongoing' => $ongoing,
      'chapters' => $chapters,
      'pages' => $pages,
      'chapters_today' => $chaptersToday,
      'reads' => $totalReads,
      'vip_chapters' => $vipChapters,
    ];
  }

  /**
   * @return array<string, int>
   */
  public function importSummary(): array {
    $summary = [
      'total' => 0,
      'queued' => 0,
      'processing' => 0,
      'completed' => 0,
      'failed' => 0,
      'cancelled' => 0,
    ];
    foreach ($this->imports->recentJobs(200) as $job) {
      $summary['total']++;
      $status = (string) $job['status'];
      if (isset($summary[$status])) {
        $summary[$status]++;
      }
      elseif (in_array($status, ['partial', 'pending'], TRUE)) {
        $summary['failed']++;
      }
    }
    return $summary;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function recentComics(int $limit = 5): array {
    $nids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'comic')
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    return $this->nodeRows($nids);
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function recentChapters(int $limit = 5): array {
    $nids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'comic_chapter')
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();
    return $this->nodeRows($nids);
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function recentImportJobs(int $limit = 5): array {
    $rows = [];
    foreach ($this->imports->recentJobs($limit) as $job) {
      $rows[] = [
        'id' => (int) $job['id'],
        'status' => $this->imports->statusLabel((string) $job['status']),
        'mode' => $this->imports->modeLabel((string) ($job['import_mode'] ?? 'zip')),
        'url' => '/admin/xedc/comic/import/status?job=' . (int) $job['id'],
        'created' => (int) $job['created'],
      ];
    }
    return $rows;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function failedImportJobs(int $limit = 5): array {
    $rows = [];
    foreach ($this->imports->recentJobs(50) as $job) {
      if (!in_array($job['status'], ['failed', 'partial'], TRUE) && (int) $job['failed_count'] < 1) {
        continue;
      }
      $rows[] = [
        'id' => (int) $job['id'],
        'status' => $this->imports->statusLabel((string) $job['status']),
        'failures' => (int) $job['failed_count'],
        'url' => '/admin/xedc/comic/import/status?job=' . (int) $job['id'],
      ];
      if (count($rows) >= $limit) {
        break;
      }
    }
    return $rows;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function popularComics(int $limit = 5): array {
    $items = [];
    foreach ($this->stats->topComics(30, $limit) as $row) {
      $node = $this->entities->getStorage('node')->load((int) $row['comic_id']);
      if (!$node) {
        continue;
      }
      $items[] = [
        'title' => $node->label(),
        'views' => (int) ($row['views'] ?? 0),
        'url' => '/node/' . (int) $node->id() . '/edit',
      ];
    }
    return $items;
  }

  /**
   * @param int[] $nids
   *
   * @return list<array<string, mixed>>
   */
  private function nodeRows(array $nids): array {
    if ($nids === []) {
      return [];
    }
    $formatter = \Drupal::service('date.formatter');
    $comics = \Drupal::service('xedc_comic.manager');
    $rows = [];
    foreach ($this->entities->getStorage('node')->loadMultiple($nids) as $node) {
      $row = [
        'title' => $node->label(),
        'url' => '/node/' . (int) $node->id() . '/edit',
        'changed' => $formatter->format((int) $node->getChangedTime(), 'short'),
        'status' => $node->isPublished() ? '已发布' : '草稿',
        'cover' => '',
        'author' => '',
        'parent' => '',
        'number' => '',
        'pages' => '',
      ];
      if ($node->bundle() === 'comic') {
        $card = $comics->comicCard($node);
        $row['cover'] = $card['cover'];
        $row['author'] = $card['author'] ?? '';
        $row['status_label'] = $card['status_label'] ?? $row['status'];
      }
      elseif ($node->bundle() === 'comic_chapter') {
        if ($node->hasField('field_comic_parent') && $node->get('field_comic_parent')->entity) {
          $row['parent'] = $node->get('field_comic_parent')->entity->label();
        }
        $row['number'] = $node->hasField('field_comic_number')
          ? (string) $node->get('field_comic_number')->value : '';
        $row['pages'] = $node->hasField('field_comic_page_count')
          ? (string) (int) $node->get('field_comic_page_count')->value : '';
      }
      $rows[] = $row;
    }
    return $rows;
  }

}