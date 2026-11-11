<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\xedc_search\Query\HotRank;
use Drupal\xedc_search\Query\QueryNormalizer;

/**
 * Daily search statistics. Rows come from recorded searches only.
 */
final class StatsRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly QueryNormalizer $normalizer,
    private readonly KeywordRepository $keywords,
  ) {}

  /**
   * Inserts one counted search and updates the daily aggregate.
   */
  public function record(string $keyword, string $key, string $bundle, int $resultCount, bool $newVisitor): void {
    $now = $this->time->getRequestTime();
    $day = (int) date('Ymd', $now);
    $bundle = $this->normalizer->type($bundle);
    $zero = $resultCount <= 0 ? 1 : 0;
    $this->database->insert('xedc_search_log')->fields([
      'keyword' => $keyword,
      'keyword_key' => $key,
      'bundle' => $bundle,
      'result_count' => max(0, $resultCount),
      'zero_result' => $zero,
      'created' => $now,
      'day' => $day,
    ])->execute();
    $this->database->query(
      'INSERT INTO {xedc_search_daily} (day, keyword_key, keyword, bundle, searches, users, result_sum, zero_count)
       VALUES (:day, :keyword_key, :keyword, :bundle, 1, :users, :result_sum, :zero_count)
       ON DUPLICATE KEY UPDATE
         keyword = VALUES(keyword),
         searches = searches + 1,
         users = users + VALUES(users),
         result_sum = result_sum + VALUES(result_sum),
         zero_count = zero_count + VALUES(zero_count)',
      [
        ':day' => $day,
        ':keyword_key' => $key,
        ':keyword' => $keyword,
        ':bundle' => $bundle,
        ':users' => $newVisitor ? 1 : 0,
        ':result_sum' => max(0, $resultCount),
        ':zero_count' => $zero,
      ],
    );
  }

  public function rememberVisitor(int $day, string $key, string $hash): bool {
    if ($hash === '') {
      return FALSE;
    }
    try {
      $this->database->insert('xedc_search_visitor')->fields([
        'day' => $day,
        'keyword_key' => $key,
        'visitor' => $hash,
      ])->execute();
      return TRUE;
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function hot(string $window, int $limit, bool $includeDisabled = FALSE, ?string $bundle = NULL): array {
    $bundle = $bundle === NULL ? 'all' : $this->normalizer->context($bundle);
    $rows = $this->rowsSince($this->startDay($window), $bundle);
    $keywords = $this->keywords->index();
    $bucket = [];
    $today = $this->dayStamp($this->time->getRequestTime());
    foreach ($rows as $row) {
      $key = (string) $row['keyword_key'];
      $keyword = $keywords[$key] ?? NULL;
      if ($keyword && !(int) $keyword['status'] && !$includeDisabled) {
        continue;
      }
      if (!isset($bucket[$key])) {
        $bucket[$key] = [
          'keyword' => (string) $row['keyword'],
          'keyword_key' => $key,
          'searches' => 0,
          'users' => 0,
          'result_sum' => 0,
          'zero_count' => 0,
          'score' => 0.0,
          'weight' => $keyword ? (int) $keyword['weight'] : 0,
          'status' => $keyword ? (int) $keyword['status'] : 1,
          'id' => $keyword ? (int) $keyword['id'] : 0,
        ];
      }
      $age = $this->ageDays($today, (int) $row['day']);
      $bucket[$key]['searches'] += (int) $row['searches'];
      $bucket[$key]['users'] += (int) $row['users'];
      $bucket[$key]['result_sum'] += (int) $row['result_sum'];
      $bucket[$key]['zero_count'] += (int) $row['zero_count'];
      $bucket[$key]['score'] += HotRank::score((int) $row['searches'], (int) $row['users'], 0, $age);
      if ($keyword && (string) $keyword['label'] !== '') {
        $bucket[$key]['keyword'] = (string) $keyword['label'];
      }
    }
    foreach ($bucket as &$item) {
      $item['score'] += $item['weight'];
      $item['results'] = $item['searches'] > 0 ? (int) round($item['result_sum'] / $item['searches']) : 0;
    }
    unset($item);
    usort($bucket, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice(array_values($bucket), 0, max(1, $limit));
  }

  /**
   * @return array<string, mixed>
   */
  public function dashboard(): array {
    $now = $this->time->getRequestTime();
    $today = $this->dayStamp($now);
    $rows = $this->rowsSince($this->dayStamp($now - (30 * 86400)));
    $sum = static function (array $rows, int $start) : array {
      $searches = 0;
      $zero = 0;
      foreach ($rows as $row) {
        if ((int) $row['day'] >= $start) {
          $searches += (int) $row['searches'];
          $zero += (int) $row['zero_count'];
        }
      }
      return [$searches, $zero];
    };
    [$todayCount] = $sum($rows, $today);
    [$weekCount] = $sum($rows, $this->dayStamp($now - (6 * 86400)));
    [$monthCount, $monthZero] = $sum($rows, $this->dayStamp($now - (29 * 86400)));
    $trendMap = [];
    $types = [];
    for ($i = 13; $i >= 0; $i--) {
      $day = $this->dayStamp($now - ($i * 86400));
      $trendMap[$day] = 0;
    }
    foreach ($rows as $row) {
      $day = (int) $row['day'];
      if (isset($trendMap[$day])) {
        $trendMap[$day] += (int) $row['searches'];
      }
      $bundle = (string) $row['bundle'];
      $types[$bundle] = ($types[$bundle] ?? 0) + (int) $row['searches'];
    }
    $trend = [];
    foreach ($trendMap as $day => $count) {
      $trend[] = [
        'day' => $this->labelDay($day),
        'count' => $count,
      ];
    }
    $distribution = [];
    foreach ($types as $bundle => $count) {
      $distribution[] = ['type' => $bundle, 'count' => $count];
    }
    usort($distribution, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    return [
      'today' => $todayCount,
      'week' => $weekCount,
      'month' => $monthCount,
      'zero' => $monthZero,
      'trend' => $trend,
      'types' => $distribution,
      'hot' => $this->hot('week', 8),
    ];
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   */
  public function analysis(string $q, bool $zeroOnly, int $limit, int $offset): array {
    $where = ' WHERE 1=1';
    $args = [];
    $key = $this->normalizer->key($q);
    if ($key !== '') {
      $where .= ' AND keyword_key LIKE :q';
      $args[':q'] = '%' . $this->database->escapeLike($key) . '%';
    }
    if ($zeroOnly) {
      $where .= ' AND zero_result = 1';
    }
    $total = (int) $this->database->query(
      'SELECT COUNT(*) FROM (SELECT keyword_key FROM {xedc_search_log}' . $where . ' GROUP BY keyword_key) t',
      $args,
    )->fetchField();
    $query = $this->database->select('xedc_search_log', 'l');
    $query->addField('l', 'keyword_key');
    $query->addExpression('MAX(l.keyword)', 'keyword');
    $query->addExpression('COUNT(l.id)', 'searches');
    $query->addExpression('MAX(l.created)', 'last_created');
    $query->addExpression('SUM(l.zero_result)', 'zeros');
    $query->addExpression('ROUND(AVG(l.result_count))', 'results');
    if ($key !== '') {
      $query->condition('l.keyword_key', '%' . $this->database->escapeLike($key) . '%', 'LIKE');
    }
    if ($zeroOnly) {
      $query->condition('l.zero_result', 1);
    }
    $query->groupBy('l.keyword_key');
    $query->orderBy('searches', 'DESC');
    $query->orderBy('last_created', 'DESC');
    $query->range(max(0, $offset), max(1, $limit));
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return ['rows' => $rows, 'total' => $total];
  }

  public function purge(int $days): void {
    $days = max(1, $days);
    $cutoff = $this->time->getRequestTime() - ($days * 86400);
    $day = $this->dayStamp($cutoff);
    $this->database->delete('xedc_search_log')->condition('created', $cutoff, '<')->execute();
    $this->database->delete('xedc_search_daily')->condition('day', $day, '<')->execute();
    $this->database->delete('xedc_search_visitor')->condition('day', $day, '<')->execute();
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function rowsSince(int $start, ?string $bundle = NULL): array {
    $query = $this->database->select('xedc_search_daily', 'd')
      ->fields('d')
      ->condition('day', $start, '>=');
    if ($bundle !== NULL) {
      $query->condition('bundle', $this->normalizer->context($bundle));
    }
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  private function startDay(string $window): int {
    $now = $this->time->getRequestTime();
    $days = match ($window) {
      'today' => 0,
      'month' => 29,
      default => 6,
    };
    return $this->dayStamp($now - ($days * 86400));
  }

  private function dayStamp(int $timestamp): int {
    return (int) date('Ymd', $timestamp);
  }

  private function labelDay(int $day): string {
    $text = (string) $day;
    return substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2);
  }

  private function ageDays(int $today, int $day): int {
    $from = \DateTimeImmutable::createFromFormat('!Ymd', (string) $day);
    $to = \DateTimeImmutable::createFromFormat('!Ymd', (string) $today);
    if (!$from || !$to) {
      return 0;
    }
    return max(0, (int) $from->diff($to)->days);
  }

}
