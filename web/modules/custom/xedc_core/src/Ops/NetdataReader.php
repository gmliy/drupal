<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

/**
 * Reads live samples and history for charts already discovered in the catalog.
 */
class NetdataReader {

  /**
   * @var array{calls: int, fails: int, ms: int}
   */
  private array $stats = ['calls' => 0, 'fails' => 0, 'ms' => 0];

  public function __construct(
    protected NetdataClient $client,
  ) {}

  /**
   * @return array{calls: int, fails: int, ms: int}
   *   Counters since the previous take.
   */
  public function takeStats(): array {
    $stats = $this->stats;
    $this->stats = ['calls' => 0, 'fails' => 0, 'ms' => 0];
    return $stats;
  }

  /**
   * @param list<string> $ids
   *   Chart ids from the catalog.
   *
   * @return array<string, array{status: string, values: array<string, float>|null, previous: array<string, float>|null, sampled: int, error: string}>
   *   Latest sample, plus the point before it when Netdata returned one.
   */
  public function latestMany(string $baseUrl, array $ids): array {
    $ids = array_values(array_unique(array_filter($ids, 'is_string')));
    $out = [];
    foreach (array_chunk($ids, 12) as $chunk) {
      $jobs = [];
      foreach ($chunk as $id) {
        $jobs[] = [
          'key' => $id,
          'path' => '/api/v1/data',
          'query' => [
            'chart' => $id,
            'after' => -12,
            'points' => 2,
            'group' => 'average',
            'format' => 'json',
            'options' => 'jsonwrap',
          ],
        ];
      }
      foreach ($this->client->getMany($baseUrl, $jobs, 3.5) as $id => $response) {
        $this->record($response);
        $out[$id] = $this->latestFromResponse($response);
      }
    }
    return $out;
  }

  /**
   * @return array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string, first_entry: int}
   *   Chronological history rows.
   */
  public function series(string $baseUrl, string $chart, int $after, int $points): array {
    $response = $this->client->get($baseUrl, '/api/v1/data', [
      'chart' => $chart,
      'after' => $after,
      'points' => max(1, min(120, $points)),
      'group' => 'average',
      'format' => 'json',
      'options' => 'jsonwrap',
    ]);
    $this->record($response);
    if (!$response['ok']) {
      return [
        'rows' => [],
        'partial' => TRUE,
        'notice' => $response['error'],
        'first_entry' => 0,
      ];
    }
    $parsed = $this->rowsFromPayload($response['data']);
    $oldest = $parsed['rows'][0]['time'] ?? 0;
    $requestedStart = $after > 0 ? $after : time() + $after;
    $partial = $parsed['rows'] === [] || ($oldest > 0 && $oldest > $requestedStart + 120);
    return [
      'rows' => $parsed['rows'],
      'partial' => $partial,
      'notice' => '',
      'first_entry' => $parsed['first_entry'],
    ];
  }

  /**
   * @param array{ok: bool, error: string, data: array<string, mixed>, elapsed_ms?: int} $response
   *   Client result.
   *
   * @return array{status: string, values: array<string, float>|null, previous: array<string, float>|null, sampled: int, error: string}
   *   One chart sample.
   */
  private function latestFromResponse(array $response): array {
    if (!$response['ok']) {
      return [
        'status' => 'failed',
        'values' => NULL,
        'previous' => NULL,
        'sampled' => 0,
        'error' => $response['error'],
      ];
    }
    $parsed = $this->rowsFromPayload($response['data']);
    if ($parsed['rows'] === []) {
      return [
        'status' => 'empty',
        'values' => NULL,
        'previous' => NULL,
        'sampled' => 0,
        'error' => '',
      ];
    }
    $last = $parsed['rows'][array_key_last($parsed['rows'])];
    $previous = count($parsed['rows']) > 1 ? $parsed['rows'][count($parsed['rows']) - 2] : NULL;
    return [
      'status' => 'ok',
      'values' => $this->numeric($last['values']),
      'previous' => is_array($previous) ? $this->numeric($previous['values']) : NULL,
      'sampled' => (int) $last['time'],
      'error' => '',
    ];
  }

  /**
   * @param array<string, mixed> $payload
   *   Netdata data payload.
   *
   * @return array{rows: list<array{time: int, values: array<string, float|null>}>, first_entry: int}
   *   Parsed rows.
   */
  private function rowsFromPayload(array $payload): array {
    $result = is_array($payload['result'] ?? NULL) ? $payload['result'] : [];
    $labels = is_array($result['labels'] ?? NULL) ? $result['labels'] : [];
    $data = is_array($result['data'] ?? NULL) ? $result['data'] : [];
    $rows = [];
    foreach ($data as $row) {
      if (!is_array($row) || !isset($row[0]) || !is_numeric($row[0])) {
        continue;
      }
      $values = [];
      $seen = FALSE;
      foreach ($labels as $index => $label) {
        if ($index === 0 || !is_string($label) || $label === '') {
          continue;
        }
        $value = $row[$index] ?? NULL;
        if (is_numeric($value)) {
          $values[$label] = (float) $value;
          $seen = TRUE;
        }
        else {
          $values[$label] = NULL;
        }
      }
      if ($seen) {
        $rows[] = ['time' => (int) $row[0], 'values' => $values];
      }
    }
    return [
      'rows' => array_reverse($rows),
      'first_entry' => (int) ($payload['first_entry'] ?? 0),
    ];
  }

  /**
   * @param array<string, float|null> $values
   *   Dimension map.
   *
   * @return array<string, float>
   *   Numeric dimensions only.
   */
  private function numeric(array $values): array {
    $out = [];
    foreach ($values as $name => $value) {
      if (is_float($value) || is_int($value)) {
        $out[(string) $name] = (float) $value;
      }
    }
    return $out;
  }

  /**
   * @param array{ok: bool, elapsed_ms?: int} $response
   *   Client result.
   */
  private function record(array $response): void {
    $this->stats['calls']++;
    $this->stats['ms'] += (int) ($response['elapsed_ms'] ?? 0);
    if (empty($response['ok'])) {
      $this->stats['fails']++;
    }
  }

}
