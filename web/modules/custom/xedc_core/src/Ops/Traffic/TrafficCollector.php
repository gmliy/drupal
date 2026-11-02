<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Traffic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Records physical NIC counters. Page requests must not call this.
 */
final class TrafficCollector {

  public const MIN_INTERVAL = 45;

  public const RETENTION_DAYS = 90;

  public function __construct(
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly CounterSource $reader,
  ) {}

  /**
   * @return list<array<string, mixed>>
   *   One result per physical NIC.
   */
  public function samplePhysical(): array {
    if (!$this->database->schema()->tableExists('xedc_traffic_sample')) {
      return [];
    }
    $results = [];
    foreach (NicGuard::physical() as $iface) {
      $results[] = $this->sampleInterface($iface, NULL, TRUE);
    }
    $this->purge($this->time->getRequestTime());
    return $results;
  }

  /**
   * @return array<string, mixed>
   *   Status for one interface. A skipped or failed read does not add traffic.
   */
  public function sampleInterface(string $iface, ?int $now = NULL, bool $requirePhysical = TRUE, ?CounterSource $reader = NULL): array {
    if (!NicGuard::name($iface) || ($requirePhysical && !NicGuard::isPhysical($iface))) {
      return ['iface' => '', 'status' => 'rejected', 'counted' => FALSE];
    }
    $now = $now ?? $this->time->getRequestTime();
    $lockName = 'xedc_traffic_' . $iface;
    if (!$this->lock->acquire($lockName, 8.0)) {
      return ['iface' => $iface, 'status' => 'skipped', 'counted' => FALSE, 'detail' => '已有采集在进行'];
    }
    try {
      return $this->record($iface, $now, $reader ?? $this->reader);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * @return array{removed: int, kept: int}
   *   Snapshot rows removed after the daily total matched, and days left untouched.
   */
  public function purge(int $now): array {
    $zone = $this->zone();
    $cutoff = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d');
    $removed = 0;
    $kept = 0;
    $rows = $this->database->select('xedc_traffic_day', 'd')
      ->fields('d')
      ->condition('day', $cutoff, '<')
      ->orderBy('day')
      ->range(0, 40)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
      if (!is_array($row) || !NicGuard::name((string) $row['iface'])) {
        $kept++;
        continue;
      }
      $iface = (string) $row['iface'];
      $day = (string) $row['day'];
      $window = $this->window($day, $zone);
      $sum = $this->database->select('xedc_traffic_sample', 's')
        ->condition('iface', $iface)
        ->condition('sampled', $window['start'], '>=')
        ->condition('sampled', $window['end'], '<=');
      $sum->addExpression("COALESCE(SUM(CASE WHEN counted = 1 THEN rx_delta ELSE 0 END), 0)", 'rx');
      $sum->addExpression("COALESCE(SUM(CASE WHEN counted = 1 THEN tx_delta ELSE 0 END), 0)", 'tx');
      $sum->addExpression("COALESCE(SUM(CASE WHEN status = 'cross_day' THEN rx_delta ELSE 0 END), 0)", 'urx');
      $sum->addExpression("COALESCE(SUM(CASE WHEN status = 'cross_day' THEN tx_delta ELSE 0 END), 0)", 'utx');
      $totals = $sum->execute()->fetchAssoc();
      $sampleCount = (int) $this->database->select('xedc_traffic_sample', 's')
        ->condition('iface', $iface)
        ->condition('sampled', $window['start'], '>=')
        ->condition('sampled', $window['end'], '<=')
        ->countQuery()
        ->execute()
        ->fetchField();
      if (!is_array($totals) || $sampleCount === 0) {
        continue;
      }
      $match = bccomp((string) $totals['rx'], (string) $row['rx_bytes'], 0) === 0
        && bccomp((string) $totals['tx'], (string) $row['tx_bytes'], 0) === 0
        && bccomp((string) $totals['urx'], (string) $row['unallocated_rx'], 0) === 0
        && bccomp((string) $totals['utx'], (string) $row['unallocated_tx'], 0) === 0;
      if (!$match) {
        $kept++;
        $this->logger->warning('保留 {iface} {day} 的原始快照，因为合计与每日汇总不一致。', [
          'iface' => $iface,
          'day' => $day,
        ]);
        continue;
      }
      $removed += (int) $this->database->delete('xedc_traffic_sample')
        ->condition('iface', $iface)
        ->condition('sampled', $window['start'], '>=')
        ->condition('sampled', $window['end'], '<=')
        ->execute();
    }
    return ['removed' => $removed, 'kept' => $kept];
  }

  /**
   * @return array<string, mixed>
   *   Stored result.
   */
  private function record(string $iface, int $now, CounterSource $reader): array {
    $transaction = $this->database->startTransaction();
    try {
      $last = $this->database->select('xedc_traffic_sample', 's')
        ->fields('s')
        ->condition('iface', $iface)
        ->orderBy('sampled', 'DESC')
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->forUpdate()
        ->execute()
        ->fetchAssoc();
      if (is_array($last) && ($now - (int) $last['sampled']) < self::MIN_INTERVAL) {
        return ['iface' => $iface, 'status' => 'skipped', 'counted' => FALSE, 'detail' => '距上次采样太近'];
      }
      if (is_array($last) && $now <= (int) $last['sampled']) {
        return ['iface' => $iface, 'status' => 'skipped', 'counted' => FALSE, 'detail' => '采样时间没有前进'];
      }
      $reading = $reader->read($iface);
      $day = $this->day($now);
      if ($reading === NULL) {
        $this->insert($iface, $now, $day, NULL, NULL, NULL, NULL, 0, 'failed', 0, '读取计数器失败');
        $this->bump($iface, $day, $now, 'failed', '0', '0');
        return ['iface' => $iface, 'status' => 'failed', 'counted' => FALSE];
      }
      $previous = $this->previousCounter($iface);
      if ($previous === NULL) {
        $this->insert($iface, $now, $day, $reading['rx'], $reading['tx'], NULL, NULL, 0, 'baseline', 0, '建立基线');
        $this->bump($iface, $day, $now, 'baseline', '0', '0');
        return ['iface' => $iface, 'status' => 'baseline', 'counted' => FALSE];
      }
      $delta = TrafficDelta::between(
        $previous['rx'],
        $previous['tx'],
        (int) $previous['sampled'],
        $this->day((int) $previous['sampled']),
        $reading['rx'],
        $reading['tx'],
        $now,
        $day,
      );
      $detail = match ($delta['status']) {
        'reset' => '计数器回绕或重置，已重新建立基线',
        'cross_day' => '与上次采样不在同一天，增量未分配到某一天',
        'gap' => '采样间隔 ' . $delta['gap'] . ' 秒，超过 6 小时，当天合计仍使用计数器差值',
        'sparse' => '采样间隔 ' . $delta['gap'] . ' 秒',
        default => '采样间隔 ' . $delta['gap'] . ' 秒',
      };
      $this->insert(
        $iface,
        $now,
        $day,
        $reading['rx'],
        $reading['tx'],
        $delta['rx_delta'],
        $delta['tx_delta'],
        $delta['gap'],
        $delta['status'],
        $delta['counted'] ? 1 : 0,
        $detail,
      );
      $rx = $delta['status'] === 'cross_day' ? (string) $delta['rx_delta'] : ($delta['counted'] ? (string) $delta['rx_delta'] : '0');
      $tx = $delta['status'] === 'cross_day' ? (string) $delta['tx_delta'] : ($delta['counted'] ? (string) $delta['tx_delta'] : '0');
      $this->bump($iface, $day, $now, $delta['status'], $rx, $tx);
      if (in_array($delta['status'], ['failed', 'reset', 'cross_day', 'gap'], TRUE)) {
        $this->logger->warning('网卡 {iface} 采样状态 {status}。', [
          'iface' => $iface,
          'status' => $delta['status'],
        ]);
      }
      return [
        'iface' => $iface,
        'status' => $delta['status'],
        'counted' => $delta['counted'],
        'rx_delta' => $delta['rx_delta'],
        'tx_delta' => $delta['tx_delta'],
      ];
    }
    catch (IntegrityConstraintViolationException $e) {
      $transaction->rollBack();
      $this->logger->error('网卡 {iface} 采样冲突：{message}', [
        'iface' => $iface,
        'message' => $e->getMessage(),
      ]);
      return ['iface' => $iface, 'status' => 'skipped', 'counted' => FALSE, 'detail' => '同一采样时间已记录'];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->logger->error('网卡 {iface} 采样没有写入：{message}', [
        'iface' => $iface,
        'message' => $e->getMessage(),
      ]);
      return ['iface' => $iface, 'status' => 'failed', 'counted' => FALSE];
    }
  }

  private function previousCounter(string $iface): ?array {
    $row = $this->database->select('xedc_traffic_sample', 's')
      ->fields('s', ['sampled', 'rx_bytes', 'tx_bytes'])
      ->condition('iface', $iface)
      ->isNotNull('rx_bytes')
      ->isNotNull('tx_bytes')
      ->orderBy('sampled', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      return NULL;
    }
    return [
      'sampled' => (int) $row['sampled'],
      'rx' => (string) $row['rx_bytes'],
      'tx' => (string) $row['tx_bytes'],
    ];
  }

  private function insert(string $iface, int $now, string $day, ?string $rx, ?string $tx, ?string $rxDelta, ?string $txDelta, int $gap, string $status, int $counted, string $detail): void {
    unset($day);
    $this->database->insert('xedc_traffic_sample')
      ->fields([
        'iface' => $iface,
        'sampled' => $now,
        'rx_bytes' => $rx,
        'tx_bytes' => $tx,
        'rx_delta' => $rxDelta,
        'tx_delta' => $txDelta,
        'gap_seconds' => $gap,
        'status' => $status,
        'counted' => $counted,
        'detail' => $detail,
        'created' => $now,
      ])
      ->execute();
  }

  private function bump(string $iface, string $day, int $now, string $status, string $rx, string $tx): void {
    $this->database->merge('xedc_traffic_day')
      ->insertFields([
        'rx_bytes' => 0,
        'tx_bytes' => 0,
        'unallocated_rx' => 0,
        'unallocated_tx' => 0,
        'samples' => 0,
        'counted' => 0,
        'sparse' => 0,
        'gaps' => 0,
        'crosses' => 0,
        'failures' => 0,
        'resets' => 0,
        'integrity' => 'baseline',
        'updated' => $now,
      ])
      ->keys(['iface' => $iface, 'day' => $day])
      ->updateFields(['updated' => $now])
      ->execute();
    $update = $this->database->update('xedc_traffic_day')
      ->condition('iface', $iface)
      ->condition('day', $day)
      ->expression('samples', 'samples + 1');
    if ($status === 'cross_day') {
      $update->expression('unallocated_rx', 'unallocated_rx + :rx', [':rx' => $rx]);
      $update->expression('unallocated_tx', 'unallocated_tx + :tx', [':tx' => $tx]);
      $update->expression('crosses', 'crosses + 1');
    }
    elseif (in_array($status, ['ok', 'sparse', 'gap'], TRUE)) {
      $update->expression('rx_bytes', 'rx_bytes + :rx', [':rx' => $rx]);
      $update->expression('tx_bytes', 'tx_bytes + :tx', [':tx' => $tx]);
      $update->expression('counted', 'counted + 1');
      if ($status === 'sparse') {
        $update->expression('sparse', 'sparse + 1');
      }
      if ($status === 'gap') {
        $update->expression('gaps', 'gaps + 1');
      }
    }
    elseif ($status === 'failed') {
      $update->expression('failures', 'failures + 1');
    }
    elseif ($status === 'reset') {
      $update->expression('resets', 'resets + 1');
    }
    $update->execute();
    $row = $this->database->select('xedc_traffic_day', 'd')
      ->fields('d')
      ->condition('iface', $iface)
      ->condition('day', $day)
      ->execute()
      ->fetchAssoc();
    if (is_array($row)) {
      $this->database->update('xedc_traffic_day')
        ->fields(['integrity' => $this->integrity($row)])
        ->condition('iface', $iface)
        ->condition('day', $day)
        ->execute();
    }
  }

  /**
   * @param array<string, mixed> $row
   *   Daily row after the increment.
   */
  private function integrity(array $row): string {
    $counted = (int) $row['counted'];
    $unallocated = bccomp((string) $row['unallocated_rx'], '0', 0) > 0 || bccomp((string) $row['unallocated_tx'], '0', 0) > 0;
    if ($counted === 0 && (int) $row['failures'] > 0 && !$unallocated) {
      return 'failed';
    }
    if ($counted === 0 && ((int) $row['crosses'] > 0 || $unallocated)) {
      return 'gap';
    }
    if ($counted === 0) {
      return 'baseline';
    }
    if ((int) $row['gaps'] > 0 || (int) $row['crosses'] > 0 || $unallocated || (int) $row['failures'] > 0 || (int) $row['resets'] > 0) {
      return 'gap';
    }
    if ((int) $row['sparse'] > 0) {
      return 'partial';
    }
    return 'complete';
  }

  private function day(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone())->format('Y-m-d');
  }

  /**
   * @return array{start: int, end: int}
   *   Inclusive local-day window.
   */
  private function window(string $day, \DateTimeZone $zone): array {
    $start = new \DateTimeImmutable($day . ' 00:00:00', $zone);
    $end = new \DateTimeImmutable($day . ' 23:59:59', $zone);
    return ['start' => $start->getTimestamp(), 'end' => $end->getTimestamp()];
  }

  private function zone(): \DateTimeZone {
    $name = (string) $this->configFactory->get('system.date')->get('timezone.default');
    try {
      return new \DateTimeZone($name !== '' ? $name : 'Asia/Shanghai');
    }
    catch (\Exception) {
      return new \DateTimeZone('Asia/Shanghai');
    }
  }

}
