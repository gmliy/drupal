<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Traffic;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Reads daily totals. It does not sample counters.
 */
final class TrafficReport {

  /**
   * Test-only interface override. HTTP never sets this.
   */
  private string $locked = '';

  private const SPANS = [7, 30, 90];

  private const LABELS = [
    'complete' => '完整',
    'partial' => '采样偏疏',
    'gap' => '有缺口',
    'baseline' => '仅基线',
    'failed' => '采集失败',
    'empty' => '未采集',
    'stale' => '已过期',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * @return array<string, mixed>
   *   Display payload. The interface name is one discovered physical NIC.
   */
  public function inspect(string $iface, int $span, string $day): array {
    $previous = $this->locked;
    $this->locked = NicGuard::name($iface) ? $iface : '';
    try {
      return $this->report('', $span, $day);
    }
    finally {
      $this->locked = $previous;
    }
  }

  public function report(string $requested, int $span, string $day, string $rxRate = '未采集', string $txRate = '未采集'): array {
    $span = in_array($span, self::SPANS, TRUE) ? $span : 7;
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : '';
    $physical = $this->locked !== '' ? [$this->locked] : NicGuard::physical();
    $iface = $this->locked !== '' ? $this->locked : NicGuard::choose($physical, $requested, NicGuard::defaultRoute());
    $virtual = NicGuard::virtual();
    $notes = ['单位按 1024 进制：1 KB = 1024 B。入站是服务器接收（下载），出站是服务器发送（上传）。'];
    if ($virtual !== []) {
      $shown = array_slice($virtual, 0, 8);
      $extra = count($virtual) - count($shown);
      $notes[] = '未计入虚拟接口 ' . implode('、', $shown) . ($extra > 0 ? ' 等 ' . count($virtual) . ' 个' : '') . '。主机流量只统计选定的物理网卡。';
    }
    if (count($physical) > 1) {
      $notes[] = '有多块物理网卡。页面一次只显示一块，不把它们相加。';
    }
    $empty = $this->emptyReport($iface, $physical, $span, $day, $notes, $rxRate, $txRate);
    if ($iface === '' || !$this->database->schema()->tableExists('xedc_traffic_day')) {
      $empty['notes'][] = $iface === '' ? '没有发现可统计的物理网卡。' : '流量统计表还没有安装。';
      return $empty;
    }
    $zone = $this->zone();
    $now = $this->time->getRequestTime();
    $today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
    $todayKey = $today->format('Y-m-d');
    $monthStart = $today->format('Y-m-01');
    $todayRow = $this->dayRow($iface, $todayKey);
    $month = $this->month($iface, $monthStart, $todayKey);
    $started = $this->started($iface);
    if ($month && $started !== NULL && $started > $monthStart) {
      $month['integrity'] = 'gap';
    }
    $last = $this->lastSample($iface);
    $lastGood = $this->lastCounter($iface);
    $stale = $lastGood !== NULL && ($now - (int) $lastGood['sampled']) > 300;
    $integrity = $this->overall($todayRow, $month, $stale, $started, $monthStart);
    if ($started !== NULL && $started > $monthStart) {
      $notes[] = '本月统计从 ' . $started . ' 开始，之前没有累计记录。';
    }
    if ($todayRow && (bccomp((string) $todayRow['unallocated_rx'], '0', 0) > 0 || bccomp((string) $todayRow['unallocated_tx'], '0', 0) > 0)) {
      $notes[] = '有跨日采样增量未分配到某一天：下载 ' . $this->bytes((string) $todayRow['unallocated_rx']) . '，上传 ' . $this->bytes((string) $todayRow['unallocated_tx']) . '。';
    }
    if ($stale) {
      $notes[] = '最近一次成功采集已超过 5 分钟。下面的累计值仍是上次记入的结果，没有改成 0。';
    }
    $points = $this->trend($iface, $today, $span, $zone);
    $rows = $day === '' ? $this->history($iface, $today, $span, $zone) : $this->historyDay($iface, $day);
    return [
      'iface' => $iface,
      'choices' => $physical,
      'operstate' => $this->stateLabel(NicGuard::operstate($iface)),
      'kind' => '物理网卡',
      'rx_rate' => $rxRate,
      'tx_rate' => $txRate,
      'rate_source' => $rxRate === '未采集' && $txRate === '未采集' ? '未采集' : 'Netdata',
      'last_sample' => $last ? $this->dateFormatter->format((int) $last['sampled'], 'custom', 'Y-m-d H:i:s', $this->zone()->getName()) : '',
      'last_status' => $last ? (string) $last['status'] : '',
      'last_detail' => $last ? (string) $last['detail'] : '',
      'started' => $started ? $this->dateFormatter->format((int) $this->startedStamp($iface), 'custom', 'Y-m-d H:i:s', $this->zone()->getName()) : '',
      'integrity' => $integrity,
      'integrity_label' => self::LABELS[$integrity] ?? '未知',
      'span' => $span,
      'day' => $day,
      'unit_note' => '1 KB = 1024 B',
      'cards' => [
        $this->card('今日下载流量', $todayRow, 'rx_bytes', 'counted'),
        $this->card('今日上传流量', $todayRow, 'tx_bytes', 'counted'),
        $this->card('本月下载流量', $month, 'rx', 'counted'),
        $this->card('本月上传流量', $month, 'tx', 'counted'),
      ],
      'chart' => [
        'title' => '每日入站和出站流量',
        'unit' => 'B',
        'lines' => [
          ['key' => 'rx', 'label' => '入站流量（下载）', 'color' => '#171717'],
          ['key' => 'tx', 'label' => '出站流量（上传）', 'color' => '#0369a1'],
        ],
        'points' => $points,
      ],
      'days' => $rows,
      'notes' => $notes,
    ];
  }

  /**
   * @param list<string> $physical
   *   Physical names.
   * @param list<string> $notes
   *   Notes.
   *
   * @return array<string, mixed>
   *   Empty payload.
   */
  private function emptyReport(string $iface, array $physical, int $span, string $day, array $notes, string $rxRate, string $txRate): array {
    $blank = ['label' => '', 'value' => '暂无数据', 'detail' => '未采集', 'state' => 'muted'];
    return [
      'iface' => $iface,
      'choices' => $physical,
      'operstate' => $iface === '' ? '未知' : $this->stateLabel(NicGuard::operstate($iface)),
      'kind' => $iface === '' ? '' : '物理网卡',
      'rx_rate' => $rxRate,
      'tx_rate' => $txRate,
      'rate_source' => '未采集',
      'last_sample' => '',
      'last_status' => '',
      'last_detail' => '',
      'started' => '',
      'integrity' => 'empty',
      'integrity_label' => '未采集',
      'span' => $span,
      'day' => $day,
      'unit_note' => '1 KB = 1024 B',
      'cards' => [
        ['label' => '今日下载流量', 'value' => '暂无数据', 'detail' => '未采集', 'state' => 'muted'] + $blank,
        ['label' => '今日上传流量', 'value' => '暂无数据', 'detail' => '未采集', 'state' => 'muted'],
        ['label' => '本月下载流量', 'value' => '暂无数据', 'detail' => '未采集', 'state' => 'muted'],
        ['label' => '本月上传流量', 'value' => '暂无数据', 'detail' => '未采集', 'state' => 'muted'],
      ],
      'chart' => [
        'title' => '每日入站和出站流量',
        'unit' => 'B',
        'lines' => [
          ['key' => 'rx', 'label' => '入站流量（下载）', 'color' => '#171717'],
          ['key' => 'tx', 'label' => '出站流量（上传）', 'color' => '#0369a1'],
        ],
        'points' => [],
      ],
      'days' => [],
      'notes' => $notes,
    ];
  }

  /**
   * @param array<string, mixed>|null $row
   *   Day or month aggregate.
   *
   * @return array{label: string, value: string, detail: string, state: string}
   *   Card.
   */
  private function card(string $label, ?array $row, string $valueKey, string $countKey): array {
    if ($row === NULL || (int) ($row[$countKey] ?? 0) === 0) {
      return ['label' => $label, 'value' => '暂无累计', 'detail' => '还没有可计入的增量', 'state' => 'muted'];
    }
    $integrity = (string) ($row['integrity'] ?? 'complete');
    return [
      'label' => $label,
      'value' => $this->bytes((string) $row[$valueKey]),
      'detail' => self::LABELS[$integrity] ?? '完整',
      'state' => in_array($integrity, ['gap', 'failed'], TRUE) ? 'bad' : 'ok',
    ];
  }

  /**
   * @return array<string, mixed>|null
   *   Daily row.
   */
  private function dayRow(string $iface, string $day): ?array {
    $row = $this->database->select('xedc_traffic_day', 'd')
      ->fields('d')
      ->condition('iface', $iface)
      ->condition('day', $day)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * @return array<string, mixed>|null
   *   Month sum. Counted days contribute; missing days stay out of the total.
   */
  private function month(string $iface, string $start, string $end): ?array {
    $query = $this->database->select('xedc_traffic_day', 'd')
      ->condition('iface', $iface)
      ->condition('day', $start, '>=')
      ->condition('day', $end, '<=');
    $query->addExpression('COALESCE(SUM(rx_bytes), 0)', 'rx');
    $query->addExpression('COALESCE(SUM(tx_bytes), 0)', 'tx');
    $query->addExpression('COALESCE(SUM(counted), 0)', 'counted');
    $query->addExpression("COALESCE(SUM(CASE WHEN integrity <> 'complete' THEN 1 ELSE 0 END), 0)", 'incomplete');
    $row = $query->execute()->fetchAssoc();
    if (!is_array($row) || (int) $row['counted'] === 0) {
      return is_array($row) ? $row + ['integrity' => 'baseline'] : NULL;
    }
    $row['integrity'] = (int) $row['incomplete'] > 0 ? 'gap' : 'complete';
    return $row;
  }

  /**
   * @return list<array<string, mixed>>
   *   One point per day. Missing days have no numeric value.
   */
  private function trend(string $iface, \DateTimeImmutable $today, int $span, \DateTimeZone $zone): array {
    $start = $today->modify('-' . ($span - 1) . ' days')->format('Y-m-d');
    $rows = $this->daysBetween($iface, $start, $today->format('Y-m-d'));
    $points = [];
    for ($i = $span - 1; $i >= 0; $i--) {
      $key = $today->modify('-' . $i . ' days')->format('Y-m-d');
      $row = $rows[$key] ?? NULL;
      $point = ['label' => substr($key, 5)];
      if (is_array($row) && (int) $row['counted'] > 0) {
        $point['rx'] = (float) $row['rx_bytes'];
        $point['tx'] = (float) $row['tx_bytes'];
      }
      $points[] = $point;
    }
    unset($zone);
    return $points;
  }

  /**
   * @return list<array<string, string>>
   *   Table rows for the selected span.
   */
  private function history(string $iface, \DateTimeImmutable $today, int $span, \DateTimeZone $zone): array {
    unset($zone);
    $start = $today->modify('-' . ($span - 1) . ' days')->format('Y-m-d');
    $rows = $this->daysBetween($iface, $start, $today->format('Y-m-d'));
    $out = [];
    for ($i = 0; $i < $span; $i++) {
      $key = $today->modify('-' . $i . ' days')->format('Y-m-d');
      $out[] = $this->historyLine($key, $rows[$key] ?? NULL);
    }
    return $out;
  }

  /**
   * @return list<array<string, string>>
   *   One requested day.
   */
  private function historyDay(string $iface, string $day): array {
    return [$this->historyLine($day, $this->dayRow($iface, $day))];
  }

  /**
   * @param array<string, mixed>|null $row
   *   Daily row.
   *
   * @return array<string, string>
   *   Display row.
   */
  private function historyLine(string $day, ?array $row): array {
    if ($row === NULL) {
      return [
        'day' => $day,
        'rx' => '未采集',
        'tx' => '未采集',
        'integrity' => '未采集',
        'note' => '这一天没有累计记录',
      ];
    }
    $counted = (int) $row['counted'] > 0;
    $note = '';
    if ((int) $row['crosses'] > 0) {
      $note = '另有未分配下载 ' . $this->bytes((string) $row['unallocated_rx']) . '、上传 ' . $this->bytes((string) $row['unallocated_tx']);
    }
    elseif ((int) $row['failures'] > 0) {
      $note = '含采集失败，失败没有记成 0 流量';
    }
    elseif ((int) $row['gaps'] > 0) {
      $note = '含超过 6 小时的采样间隔';
    }
    elseif ((int) $row['counted'] === 0) {
      $note = '只有基线或重置，没有可计入的增量';
    }
    return [
      'day' => $day,
      'rx' => $counted ? $this->bytes((string) $row['rx_bytes']) : '暂无累计',
      'tx' => $counted ? $this->bytes((string) $row['tx_bytes']) : '暂无累计',
      'integrity' => self::LABELS[(string) $row['integrity']] ?? '未知',
      'note' => $note,
    ];
  }

  /**
   * @return array<string, array<string, mixed>>
   *   Rows keyed by day.
   */
  private function daysBetween(string $iface, string $start, string $end): array {
    $result = $this->database->select('xedc_traffic_day', 'd')
      ->fields('d')
      ->condition('iface', $iface)
      ->condition('day', $start, '>=')
      ->condition('day', $end, '<=')
      ->execute();
    $rows = [];
    foreach ($result as $row) {
      $rows[(string) $row->day] = (array) $row;
    }
    return $rows;
  }

  /**
   * @param array<string, mixed>|null $today
   *   Today row.
   * @param array<string, mixed>|null $month
   *   Month aggregate.
   */
  private function overall(?array $today, ?array $month, bool $stale, ?string $started, string $monthStart): string {
    if ($stale) {
      return 'stale';
    }
    if ($started === NULL) {
      return 'empty';
    }
    if ($started > $monthStart) {
      return 'gap';
    }
    foreach ([$today, $month] as $row) {
      $integrity = (string) ($row['integrity'] ?? '');
      if (in_array($integrity, ['gap', 'failed', 'partial', 'baseline'], TRUE)) {
        return $integrity === 'baseline' ? 'gap' : $integrity;
      }
    }
    return 'complete';
  }

  /**
   * @return array<string, mixed>|null
   *   Latest snapshot that stored a counter.
   */
  private function lastCounter(string $iface): ?array {
    $row = $this->database->select('xedc_traffic_sample', 's')
      ->fields('s', ['sampled'])
      ->condition('iface', $iface)
      ->isNotNull('rx_bytes')
      ->orderBy('sampled', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * @return array<string, mixed>|null
   *   Latest snapshot.
   */
  private function lastSample(string $iface): ?array {
    $row = $this->database->select('xedc_traffic_sample', 's')
      ->fields('s', ['sampled', 'status', 'detail', 'rx_bytes'])
      ->condition('iface', $iface)
      ->orderBy('sampled', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  private function started(string $iface): ?string {
    $stamp = $this->startedStamp($iface);
    if ($stamp === NULL) {
      return NULL;
    }
    return (new \DateTimeImmutable('@' . $stamp))->setTimezone($this->zone())->format('Y-m-d');
  }

  private function startedStamp(string $iface): ?int {
    $value = $this->database->select('xedc_traffic_sample', 's')
      ->fields('s', ['sampled'])
      ->condition('iface', $iface)
      ->isNotNull('rx_bytes')
      ->orderBy('sampled')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $value === FALSE ? NULL : (int) $value;
  }

  public function bytes(string $value): string {
    if (!TrafficDelta::counter($value)) {
      return '暂无数据';
    }
    if (bccomp($value, '0', 0) === 0) {
      return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $number = (float) $value;
    $index = 0;
    while ($number >= 1024 && $index < 4) {
      $number /= 1024;
      $index++;
    }
    if ($index === 0) {
      return $value . ' B';
    }
    $decimals = $number >= 100 ? 0 : 1;
    return number_format($number, $decimals, '.', '') . ' ' . $units[$index];
  }

  private function stateLabel(string $state): string {
    return match ($state) {
      'up' => '已连接',
      'down' => '未连接',
      default => '未知',
    };
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
