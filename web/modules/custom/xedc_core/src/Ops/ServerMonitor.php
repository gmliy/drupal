<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the operations dashboard from the local Netdata API.
 */
class ServerMonitor {

  private const RANGES = [
    '1h' => 3600,
    '6h' => 21600,
    '24h' => 86400,
    '7d' => 604800,
  ];

  public function __construct(
    protected NetdataClient $client,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, mixed>
   *   Display-ready snapshot. Missing metrics stay empty.
   */
  public function snapshot(bool $refresh = FALSE): array {
    $cid = 'xedc_core:monitor:snapshot';
    if ($refresh) {
      $this->invalidate();
    }
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $snapshot = $this->buildSnapshot();
    $expire = $this->time->getRequestTime() + ($snapshot['ok'] ? 20 : 15);
    $this->cache->set($cid, $snapshot, $expire);
    return $snapshot;
  }

  /**
   * @return array<string, mixed>
   *   Chart series for one allowed window.
   */
  public function history(string $range, bool $refresh = FALSE): array {
    if (!isset(self::RANGES[$range])) {
      return [
        'ok' => FALSE,
        'error' => '不支持这个时间范围。',
        'range' => $range,
      ];
    }
    $cid = 'xedc_core:monitor:history:' . $range;
    if (!$refresh) {
      $cached = $this->cache->get($cid);
      if ($cached && is_array($cached->data)) {
        return $cached->data;
      }
    }
    $history = $this->buildHistory($range);
    $ttl = match ($range) {
      '1h' => 30,
      '6h' => 60,
      '24h' => 120,
      default => 300,
    };
    $this->cache->set($cid, $history, $this->time->getRequestTime() + $ttl);
    return $history;
  }

  public function invalidate(): void {
    $this->cache->delete('xedc_core:monitor:snapshot');
    $this->cache->delete('xedc_core:monitor:charts');
    foreach (array_keys(self::RANGES) as $range) {
      $this->cache->delete('xedc_core:monitor:history:' . $range);
    }
  }

  /**
   * @return array<string, mixed>
   *   Snapshot payload.
   */
  private function buildSnapshot(): array {
    $empty = $this->emptySnapshot('Netdata 暂不可用。');
    $info = $this->client->get($this->baseUrl(), '/api/v1/info');
    if (!$info['ok']) {
      $this->logger->warning('Netdata info failed: @message', ['@message' => $info['error']]);
      $empty['error'] = $info['error'];
      return $empty;
    }
    $charts = $this->charts();
    if ($charts === NULL) {
      $empty['error'] = '已连上 Netdata，但没有读到指标目录。';
      $empty['hostname'] = $this->hostname($info['data']);
      $empty['os'] = $this->operatingSystem($info['data']);
      $empty['agent'] = (string) ($info['data']['version'] ?? '');
      return $empty;
    }

    $cpu = $this->latest('system.cpu');
    $ram = $this->latest('system.ram');
    $available = $this->latest('mem.available');
    $swap = $this->latest('mem.swap');
    $load = $this->latest('system.load');
    $uptime = $this->latest('system.uptime');
    $processes = $this->latest('system.processes');
    $active = $this->latest('system.active_processes');
    $io = $this->latest('system.io');
    $space = $this->latest('disk_space./');
    $net = $this->latest('system.net');

    $cpuTotal = $this->sum($cpu);
    $user = $cpu['user'] ?? NULL;
    $system = $cpu['system'] ?? NULL;
    $iowait = $cpu['iowait'] ?? NULL;
    $memory = $this->memorySummary($ram, $available);
    $disk = $this->diskSummary($space);
    $rx = $this->kilobitsToBytes($net['received'] ?? $net['InOctets'] ?? NULL);
    $tx = $this->kilobitsToBytes($net['sent'] ?? $net['OutOctets'] ?? NULL);
    $sampled = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d H:i:s');

    $services = [
      $this->service('Nginx', 'app.nginx_cpu_utilization', 'app.nginx_mem_usage', [
        $this->metric('进程数', 'app.nginx_processes', 'processes', '个'),
        $this->unavailableMetric('活动连接', '未开启 Nginx stub_status。本次没有修改站点配置，所以没有连接数。'),
        $this->unavailableMetric('请求速率', '未采集 Nginx 请求指标。进程数不能代替请求量。'),
      ]),
      $this->service('PHP-FPM', 'app.phpfpm_cpu_utilization', 'app.phpfpm_mem_usage', [
        $this->metric('进程数', 'app.phpfpm_processes', 'processes', '个'),
        $this->unavailableMetric('空闲进程', '未开启 PHP-FPM status 页面，空闲和活动进程数暂不可用。'),
        $this->unavailableMetric('活动进程', '进程数是进程总数，不是正在处理请求的进程数。'),
      ]),
      $this->service('MariaDB', 'app.mariadb_cpu_utilization', 'app.mariadb_mem_usage', [
        $this->metric('活动连接', 'mysql_local.connections_active', 'active', '个'),
        $this->metric('连接上限', 'mysql_local.connections_active', 'limit', '个'),
      ]),
      $this->service('Redis', 'app.redis_cpu_utilization', 'app.redis_mem_usage', [
        $this->metric('已连接客户端', 'redis_local.clients', 'connected', '个'),
        $this->metricBytes('Redis 内存', 'redis_local.memory', 'used'),
      ]),
      $this->dockerService(),
    ];

    return [
      'ok' => TRUE,
      'error' => '',
      'hostname' => $this->hostname($info['data']),
      'os' => $this->operatingSystem($info['data']),
      'kernel' => trim((string) ($info['data']['kernel_name'] ?? 'Linux') . ' ' . (string) ($info['data']['kernel_version'] ?? '')),
      'uptime' => isset($uptime['uptime']) ? OpsFormat::duration((int) round($uptime['uptime'])) : '暂无数据',
      'agent' => (string) ($info['data']['version'] ?? ''),
      'connection' => '已连接',
      'updated' => $sampled,
      'cards' => [
        [
          'label' => 'CPU 使用率',
          'value' => OpsFormat::percent($cpuTotal),
          'detail' => $cpu === NULL
            ? '暂无数据'
            : '用户 ' . OpsFormat::percent($user) . ' · 系统 ' . OpsFormat::percent($system) . ' · I/O 等待 ' . OpsFormat::percent($iowait),
          'state' => $cpuTotal === NULL ? 'empty' : 'ok',
        ],
        [
          'label' => '内存使用率',
          'value' => OpsFormat::percent($memory['percent']),
          'detail' => $memory['detail'],
          'state' => $memory['percent'] === NULL ? 'empty' : 'ok',
        ],
        [
          'label' => '磁盘使用率',
          'value' => OpsFormat::percent($disk['percent']),
          'detail' => $disk['detail'],
          'state' => $disk['percent'] === NULL ? 'empty' : 'ok',
        ],
        [
          'label' => '网络速度',
          'value' => ($rx === NULL && $tx === NULL) ? '暂无数据' : '↓ ' . OpsFormat::rate($rx) . '  ↑ ' . OpsFormat::rate($tx),
          'detail' => '物理网卡合计。100% 不是这里的单位。',
          'state' => ($rx === NULL && $tx === NULL) ? 'empty' : 'ok',
        ],
      ],
      'cores' => $this->cores($charts),
      'cores_note' => $this->coreNote($charts),
      'memory' => $memory['rows'],
      'load' => [
        ['label' => '1 分钟', 'value' => OpsFormat::number($load['load1'] ?? NULL)],
        ['label' => '5 分钟', 'value' => OpsFormat::number($load['load5'] ?? NULL)],
        ['label' => '15 分钟', 'value' => OpsFormat::number($load['load15'] ?? NULL)],
      ],
      'processes' => $this->processLine($processes, $active),
      'io' => $this->ioSummary($io, $charts),
      'interfaces' => $this->interfaces($charts),
      'services' => $services,
      'totals_note' => '累计流量暂不可用。Netdata 保存的是带宽速率，没有网卡累计字节计数器，这里不会把速率换算成总流量。',
    ];
  }

  /**
   * @return array<string, mixed>
   *   History payload.
   */
  private function buildHistory(string $range): array {
    $window = self::RANGES[$range];
    $points = match ($range) {
      '1h' => 60,
      '6h' => 72,
      '24h' => 96,
      default => 84,
    };
    $info = $this->client->get($this->baseUrl(), '/api/v1/info');
    if (!$info['ok']) {
      return [
        'ok' => FALSE,
        'error' => $info['error'],
        'range' => $range,
        'partial' => FALSE,
        'notice' => '',
        'charts' => [],
      ];
    }
    $cpu = $this->series('system.cpu', -$window, $points);
    $ram = $this->series('system.ram', -$window, $points);
    $available = $this->series('mem.available', -$window, $points);
    $disk = $this->series('disk_space./', -$window, $points);
    $net = $this->series('system.net', -$window, $points);
    $charts = [
      'cpu' => $this->cpuChart($cpu),
      'memory' => $this->memoryChart($ram, $available),
      'disk' => $this->diskChart($disk),
      'network' => $this->networkChart($net),
    ];
    $partial = FALSE;
    $notices = [];
    foreach ([$cpu, $ram, $disk, $net] as $series) {
      if (!empty($series['partial'])) {
        $partial = TRUE;
      }
      if (!empty($series['notice'])) {
        $notices[] = $series['notice'];
      }
    }
    $notice = $partial
      ? '部分曲线的历史短于所选范围。图上只有 Netdata 实际保留的点，没有补 0。' . ($notices ? ' ' . $notices[0] : '')
      : '';
    $any = FALSE;
    foreach ($charts as $chart) {
      if (!empty($chart['points'])) {
        $any = TRUE;
      }
    }
    return [
      'ok' => TRUE,
      'error' => $any ? '' : '这个时间范围里没有可用的历史采样。',
      'range' => $range,
      'partial' => $partial || !$any,
      'notice' => $any ? $notice : 'Netdata 还没有这段时间的采样，或指标暂时读不到。',
      'charts' => $charts,
    ];
  }

  /**
   * @param array<string, mixed> $info
   *   Netdata info payload.
   */
  private function hostname(array $info): string {
    if (!empty($info['hostname']) && is_string($info['hostname'])) {
      return $info['hostname'];
    }
    if (!empty($info['mirrored_hosts'][0]) && is_string($info['mirrored_hosts'][0])) {
      return $info['mirrored_hosts'][0];
    }
    return '未知主机';
  }

  /**
   * @param array<string, mixed> $info
   *   Netdata info payload.
   */
  private function operatingSystem(array $info): string {
    $name = trim((string) ($info['os_name'] ?? '') . ' ' . (string) ($info['os_version'] ?? ''));
    return $name !== '' ? $name : '未知系统';
  }

  /**
   * @return array<string, mixed>|null
   *   Chart index keyed by id.
   */
  private function charts(): ?array {
    $cid = 'xedc_core:monitor:charts';
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $response = $this->client->get($this->baseUrl(), '/api/v1/charts');
    if (!$response['ok'] || !is_array($response['data']['charts'] ?? NULL)) {
      $this->logger->warning('Netdata charts failed: @message', ['@message' => $response['error']]);
      return NULL;
    }
    $charts = $response['data']['charts'];
    $this->cache->set($cid, $charts, $this->time->getRequestTime() + 120);
    return $charts;
  }

  /**
   * @return array<string, float>|null
   *   Latest dimension values.
   */
  private function latest(string $chart): ?array {
    $series = $this->series($chart, -40, 1);
    $row = $series['rows'][array_key_last($series['rows'])] ?? NULL;
    if (!is_array($row) || empty($row['values'])) {
      return NULL;
    }
    $values = [];
    foreach ($row['values'] as $name => $value) {
      if (is_float($value) || is_int($value)) {
        $values[(string) $name] = (float) $value;
      }
    }
    return $values === [] ? NULL : $values;
  }

  /**
   * @return array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string}
   *   Chronological rows with null samples removed.
   */
  private function series(string $chart, int $after, int $points): array {
    $response = $this->fetchChart($chart, $after, $points);
    if ($response['rows'] === [] && $response['first_entry'] > 0 && $after < 0) {
      $response = $this->fetchChart($chart, $response['first_entry'], min($points, 60));
      $response['partial'] = TRUE;
      if ($response['notice'] === '') {
        $response['notice'] = '该指标最早的采样是 ' . $this->dateFormatter->format($response['first_entry'], 'custom', 'Y-m-d H:i') . '。';
      }
    }
    return $response;
  }

  /**
   * @return array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string, first_entry: int}
   *   One Netdata data response.
   */
  private function fetchChart(string $chart, int $after, int $points): array {
    $response = $this->client->get($this->baseUrl(), '/api/v1/data', [
      'chart' => $chart,
      'after' => $after,
      'points' => max(1, $points),
      'group' => 'average',
      'format' => 'json',
      'options' => 'jsonwrap',
    ]);
    if (!$response['ok']) {
      return [
        'rows' => [],
        'partial' => TRUE,
        'notice' => $response['error'],
        'first_entry' => 0,
      ];
    }
    $payload = $response['data'];
    $result = $payload['result'] ?? [];
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
        if ($index === 0 || !is_string($label)) {
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
    $rows = array_reverse($rows);
    $oldest = $rows[0]['time'] ?? 0;
    $firstEntry = (int) ($payload['first_entry'] ?? 0);
    $requestedStart = $after > 0 ? $after : $this->time->getRequestTime() + $after;
    $partial = $rows === [] || ($oldest > 0 && $oldest > $requestedStart + 120);
    $notice = '';
    if ($partial && $firstEntry > 0) {
      $notice = '该指标最早的采样是 ' . $this->dateFormatter->format($firstEntry, 'custom', 'Y-m-d H:i') . '。';
    }
    return [
      'rows' => $rows,
      'partial' => $partial,
      'notice' => $notice,
      'first_entry' => $firstEntry,
    ];
  }

  /**
   * @param array<string, float>|null $values
   *   Dimension map.
   */
  private function sum(?array $values): ?float {
    if ($values === NULL || $values === []) {
      return NULL;
    }
    return array_sum($values);
  }

  /**
   * @param array<string, float>|null $ram
   *   system.ram dimensions in MiB.
   * @param array<string, float>|null $available
   *   mem.available dimensions in MiB.
   *
   * @return array{percent: float|null, detail: string, rows: list<array{label: string, value: string}>}
   *   Memory card and detail rows.
   */
  private function memorySummary(?array $ram, ?array $available): array {
    $missing = [
      'percent' => NULL,
      'detail' => '暂无数据',
      'rows' => [
        ['label' => '总内存', 'value' => '暂无数据'],
        ['label' => '已用（不含缓存）', 'value' => '暂无数据'],
        ['label' => '可用', 'value' => '暂无数据'],
        ['label' => '缓存', 'value' => '暂无数据'],
        ['label' => '空闲', 'value' => '暂无数据'],
      ],
    ];
    if ($ram === NULL) {
      return $missing;
    }
    $free = $ram['free'] ?? NULL;
    $used = $ram['used'] ?? NULL;
    $cached = $ram['cached'] ?? NULL;
    $buffers = $ram['buffers'] ?? NULL;
    if ($free === NULL || $used === NULL || $cached === NULL || $buffers === NULL) {
      return $missing;
    }
    $total = $free + $used + $cached + $buffers;
    $avail = $available['avail'] ?? NULL;
    $percent = NULL;
    if ($avail !== NULL && $total > 0) {
      $percent = (($total - $avail) / $total) * 100;
    }
    $cache = $cached + $buffers;
    $detail = '可用 ' . OpsFormat::bytes($this->mib($avail)) . ' · 缓存 ' . OpsFormat::bytes($this->mib($cache));
    $swap = $this->latest('mem.swap');
    $swapUsed = isset($swap['used']) ? OpsFormat::bytes($this->mib($swap['used'])) : '暂无数据';
    return [
      'percent' => $percent,
      'detail' => $detail,
      'rows' => [
        ['label' => '总内存', 'value' => OpsFormat::bytes($this->mib($total))],
        ['label' => '已用（不含缓存）', 'value' => OpsFormat::bytes($this->mib($used))],
        ['label' => '可用', 'value' => OpsFormat::bytes($this->mib($avail))],
        ['label' => '缓存', 'value' => OpsFormat::bytes($this->mib($cache))],
        ['label' => '空闲', 'value' => OpsFormat::bytes($this->mib($free))],
        ['label' => 'Swap 已用', 'value' => $swapUsed],
      ],
    ];
  }

  /**
   * @param array<string, float>|null $space
   *   disk_space./ dimensions in GiB.
   *
   * @return array{percent: float|null, detail: string}
   *   Root filesystem summary.
   */
  private function diskSummary(?array $space): array {
    if ($space === NULL || !isset($space['used'], $space['avail'])) {
      return ['percent' => NULL, 'detail' => '暂无数据'];
    }
    $used = $space['used'];
    $avail = $space['avail'];
    $reserved = $space['reserved for root'] ?? 0.0;
    $denom = $used + $avail;
    $percent = $denom > 0 ? ($used / $denom) * 100 : NULL;
    $total = $used + $avail + $reserved;
    return [
      'percent' => $percent,
      'detail' => '已用 ' . OpsFormat::bytes($this->gib($used)) . ' · 剩余 ' . OpsFormat::bytes($this->gib($avail)) . ' · 共 ' . OpsFormat::bytes($this->gib($total)),
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Chart index.
   *
   * @return list<array{label: string, value: string, state: string}>
   *   Per-core utilization.
   */
  private function cores(array $charts): array {
    $ids = [];
    foreach ($charts as $id => $chart) {
      if (preg_match('/^cpu\.cpu(\d+)$/', (string) $id, $matches)) {
        $ids[(int) $matches[1]] = (string) $id;
      }
    }
    ksort($ids);
    $rows = [];
    foreach ($ids as $index => $id) {
      $values = $this->latest($id);
      $rows[] = [
        'label' => 'CPU ' . $index,
        'value' => OpsFormat::percent($this->sum($values)),
        'state' => $values === NULL ? 'empty' : 'ok',
      ];
    }
    return $rows;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Chart index.
   */
  private function coreNote(array $charts): string {
    foreach ($charts as $id => $chart) {
      if (preg_match('/^cpu\.cpu\d+$/', (string) $id)) {
        return '每个核心的使用率是该核心各状态之和，不包含 idle。';
      }
    }
    return '各 CPU 核心暂不可用。Netdata 没有提供 per-core 图表。';
  }

  /**
   * @param array<string, float>|null $processes
   *   Running and blocked.
   * @param array<string, float>|null $active
   *   Active process count.
   */
  private function processLine(?array $processes, ?array $active): string {
    if ($processes === NULL && $active === NULL) {
      return '进程数量暂不可用。';
    }
    $parts = [];
    if (isset($active['active'])) {
      $parts[] = '活动进程 ' . (int) round($active['active']);
    }
    if (isset($processes['running'])) {
      $parts[] = '运行中 ' . (int) round($processes['running']);
    }
    if (isset($processes['blocked'])) {
      $parts[] = '阻塞 ' . (int) round($processes['blocked']);
    }
    return $parts === [] ? '进程数量暂不可用。' : implode(' · ', $parts);
  }

  /**
   * @param array<string, float>|null $io
   *   system.io in KiB/s.
   * @param array<string, array<string, mixed>> $charts
   *   Chart index.
   *
   * @return list<array{label: string, value: string}>
   *   Disk throughput and IOPS.
   */
  private function ioSummary(?array $io, array $charts): array {
    $readRate = $io['reads'] ?? $io['in'] ?? NULL;
    $writeRate = $io['writes'] ?? $io['out'] ?? NULL;
    $read = is_numeric($readRate) ? OpsFormat::rate(abs((float) $readRate) * 1024) : '暂无数据';
    $write = is_numeric($writeRate) ? OpsFormat::rate(abs((float) $writeRate) * 1024) : '暂无数据';
    $iops = '暂无数据';
    foreach ($charts as $id => $chart) {
      if (($chart['context'] ?? '') !== 'disk.ops') {
        continue;
      }
      $ops = $this->latest((string) $id);
      if ($ops === NULL) {
        continue;
      }
      $sum = 0.0;
      $seen = FALSE;
      foreach ($ops as $value) {
        $sum += abs($value);
        $seen = TRUE;
      }
      if ($seen) {
        $iops = OpsFormat::number($sum, 1) . ' 次/秒';
      }
      break;
    }
    return [
      ['label' => '读取', 'value' => $read],
      ['label' => '写入', 'value' => $write],
      ['label' => 'IOPS', 'value' => $iops],
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Chart index.
   *
   * @return list<array<string, string>>
   *   Physical interfaces.
   */
  private function interfaces(array $charts): array {
    $rows = [];
    foreach ($charts as $id => $chart) {
      if (($chart['context'] ?? '') !== 'net.net') {
        continue;
      }
      $name = (string) ($chart['family'] ?? $id);
      $values = $this->latest((string) $id);
      $state = '暂无数据';
      $stateChart = 'net_operstate.' . $name;
      if (isset($charts[$stateChart])) {
        $flags = $this->latest($stateChart);
        $state = $this->operstate($flags);
      }
      $rows[] = [
        'name' => $name,
        'rx' => OpsFormat::rate($this->kilobitsToBytes($values['received'] ?? NULL)),
        'tx' => OpsFormat::rate($this->kilobitsToBytes($values['sent'] ?? NULL)),
        'state' => $state,
        'totals' => '暂不可用',
      ];
    }
    return $rows;
  }

  /**
   * @param array<string, float>|null $flags
   *   Operstate dimensions.
   */
  private function operstate(?array $flags): string {
    if ($flags === NULL) {
      return '暂无数据';
    }
    $labels = [
      'up' => '已连接',
      'down' => '未连接',
      'notpresent' => '不存在',
      'lowerlayerdown' => '下层断开',
      'testing' => '测试',
      'dormant' => '休眠',
      'unknown' => '未知',
    ];
    foreach ($labels as $key => $label) {
      if (($flags[$key] ?? 0) >= 0.5) {
        return $label;
      }
    }
    return '未知';
  }

  /**
   * @param list<array<string, string>> $metrics
   *   Extra rows.
   *
   * @return array<string, mixed>
   *   One service card.
   */
  private function service(string $name, string $cpuChart, string $memChart, array $metrics): array {
    $cpu = $this->latest($cpuChart);
    $mem = $this->latest($memChart);
    $cpuValue = $cpu === NULL ? NULL : ($cpu['user'] ?? 0) + ($cpu['system'] ?? 0);
    $memValue = $mem['rss'] ?? NULL;
    return [
      'name' => $name,
      'state' => ($cpu === NULL && $mem === NULL) ? '暂不可用' : '已采集资源',
      'cpu' => $cpuValue === NULL ? '暂无数据' : OpsFormat::percent($cpuValue),
      'cpu_note' => '100% 表示一个核心。这是进程占用，不是服务是否正常。',
      'memory' => OpsFormat::bytes($this->mib($memValue)),
      'metrics' => $metrics,
    ];
  }

  /**
   * @return array<string, mixed>
   *   Docker daemon plus container cgroups.
   */
  private function dockerService(): array {
    $card = $this->service('Docker', 'app.docker_cpu_utilization', 'app.docker_mem_usage', []);
    $card['name'] = 'Docker';
    $charts = $this->charts() ?? [];
    $containers = [];
    foreach ($charts as $id => $chart) {
      if (($chart['context'] ?? '') !== 'cgroup.cpu' || !preg_match('/^cgroup_(.+)\.cpu$/', (string) $id, $matches)) {
        continue;
      }
      $cpu = $this->latest((string) $id);
      $memId = 'cgroup_' . $matches[1] . '.mem_usage';
      $mem = isset($charts[$memId]) ? $this->latest($memId) : NULL;
      $cpuSum = $cpu === NULL ? NULL : array_sum($cpu);
      $memValue = NULL;
      if (is_array($mem)) {
        $memValue = $mem['ram'] ?? $mem['usage'] ?? $mem['used'] ?? (count($mem) === 1 ? reset($mem) : NULL);
        if ($memValue !== NULL && !is_numeric($memValue)) {
          $memValue = array_sum($mem);
        }
      }
      $containers[] = [
        'label' => $matches[1],
        'value' => 'CPU ' . OpsFormat::percent(is_numeric($cpuSum) ? (float) $cpuSum : NULL) . ' · 内存 ' . OpsFormat::bytes($this->mib(is_numeric($memValue) ? (float) $memValue : NULL)),
        'reason' => '',
      ];
    }
    if ($containers === []) {
      $containers[] = [
        'label' => '容器资源',
        'value' => '暂不可用',
        'reason' => '没有读到容器 cgroup。未授予 Docker socket，避免把监控进程变成等同 root 的权限。',
      ];
    }
    else {
      $containers[] = [
        'label' => '说明',
        'value' => '容器数据来自 cgroup',
        'reason' => '上面的 Docker 卡片是 dockerd 进程。容器行来自 cgroup，不是 Docker API。',
      ];
    }
    $card['metrics'] = array_map(static function (array $row): array {
      return [
        'label' => $row['label'],
        'value' => $row['value'],
        'reason' => $row['reason'],
      ];
    }, $containers);
    return $card;
  }

  /**
   * @return array{label: string, value: string, reason: string}
   *   A metric row.
   */
  private function metric(string $label, string $chart, string $dimension, string $unit): array {
    $values = $this->latest($chart);
    if ($values === NULL || !array_key_exists($dimension, $values)) {
      return $this->unavailableMetric($label, 'Netdata 没有返回 ' . $chart . ' 的 ' . $dimension . '。');
    }
    $number = $unit === '个'
      ? (string) (int) round($values[$dimension])
      : OpsFormat::number($values[$dimension], 1);
    return ['label' => $label, 'value' => $number . ' ' . $unit, 'reason' => ''];
  }

  /**
   * @return array{label: string, value: string, reason: string}
   *   Byte metric from a raw byte dimension.
   */
  private function metricBytes(string $label, string $chart, string $dimension): array {
    $values = $this->latest($chart);
    if ($values === NULL || !isset($values[$dimension])) {
      return $this->unavailableMetric($label, 'Netdata 没有返回这项内存指标。');
    }
    return [
      'label' => $label,
      'value' => OpsFormat::bytes($values[$dimension]),
      'reason' => '',
    ];
  }

  /**
   * @return array{label: string, value: string, reason: string}
   *   Explicit empty metric.
   */
  private function unavailableMetric(string $label, string $reason): array {
    return ['label' => $label, 'value' => '暂不可用', 'reason' => $reason];
  }

  /**
   * @param array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string} $series
   *   CPU samples.
   *
   * @return array<string, mixed>
   *   Chart spec for the browser.
   */
  private function cpuChart(array $series): array {
    $points = [];
    foreach ($series['rows'] as $row) {
      $values = $row['values'];
      $total = 0.0;
      $seen = FALSE;
      foreach ($values as $value) {
        if ($value !== NULL) {
          $total += $value;
          $seen = TRUE;
        }
      }
      if (!$seen) {
        continue;
      }
      $points[] = [
        't' => $this->dateFormatter->format($row['time'], 'custom', 'm-d H:i'),
        'total' => round($total, 2),
        'user' => isset($values['user']) ? round((float) $values['user'], 2) : NULL,
        'system' => isset($values['system']) ? round((float) $values['system'], 2) : NULL,
        'iowait' => isset($values['iowait']) ? round((float) $values['iowait'], 2) : NULL,
      ];
    }
    return [
      'title' => 'CPU 趋势',
      'unit' => '%',
      'lines' => [
        ['key' => 'total', 'label' => '合计', 'color' => '#171717'],
        ['key' => 'user', 'label' => '用户', 'color' => '#52525b'],
        ['key' => 'system', 'label' => '系统', 'color' => '#a1a1aa'],
        ['key' => 'iowait', 'label' => 'I/O 等待', 'color' => '#d97706'],
      ],
      'points' => $points,
    ];
  }

  /**
   * @param array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string} $ram
   *   RAM samples.
   * @param array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string} $available
   *   Available memory samples.
   *
   * @return array<string, mixed>
   *   Memory chart.
   */
  private function memoryChart(array $ram, array $available): array {
    $points = [];
    foreach ($ram['rows'] as $row) {
      $values = $row['values'];
      if (!isset($values['free'], $values['used'], $values['cached'], $values['buffers'])) {
        continue;
      }
      $total = $values['free'] + $values['used'] + $values['cached'] + $values['buffers'];
      $avail = $this->nearestValue($available['rows'], $row['time'], 'avail');
      if ($avail === NULL || $total <= 0) {
        continue;
      }
      $points[] = [
        't' => $this->dateFormatter->format($row['time'], 'custom', 'm-d H:i'),
        'used' => round((($total - $avail) / $total) * 100, 2),
      ];
    }
    return [
      'title' => '内存趋势',
      'unit' => '%',
      'lines' => [
        ['key' => 'used', 'label' => '已用（相对可用内存）', 'color' => '#171717'],
      ],
      'points' => $points,
    ];
  }

  /**
   * @param array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string} $disk
   *   Disk space samples.
   *
   * @return array<string, mixed>
   *   Disk chart.
   */
  private function diskChart(array $disk): array {
    $points = [];
    foreach ($disk['rows'] as $row) {
      $used = $row['values']['used'] ?? NULL;
      $avail = $row['values']['avail'] ?? NULL;
      if ($used === NULL || $avail === NULL || ($used + $avail) <= 0) {
        continue;
      }
      $points[] = [
        't' => $this->dateFormatter->format($row['time'], 'custom', 'm-d H:i'),
        'used' => round(($used / ($used + $avail)) * 100, 2),
      ];
    }
    return [
      'title' => '磁盘趋势',
      'unit' => '%',
      'lines' => [
        ['key' => 'used', 'label' => '根分区使用率', 'color' => '#171717'],
      ],
      'points' => $points,
    ];
  }

  /**
   * @param array{rows: list<array{time: int, values: array<string, float|null>}>, partial: bool, notice: string} $net
   *   Aggregate bandwidth.
   *
   * @return array<string, mixed>
   *   Network chart in bytes per second.
   */
  private function networkChart(array $net): array {
    $points = [];
    foreach ($net['rows'] as $row) {
      $rx = $this->kilobitsToBytes($row['values']['received'] ?? $row['values']['InOctets'] ?? NULL);
      $tx = $this->kilobitsToBytes($row['values']['sent'] ?? $row['values']['OutOctets'] ?? NULL);
      if ($rx === NULL && $tx === NULL) {
        continue;
      }
      $points[] = [
        't' => $this->dateFormatter->format($row['time'], 'custom', 'm-d H:i'),
        'rx' => $rx === NULL ? NULL : round($rx, 1),
        'tx' => $tx === NULL ? NULL : round($tx, 1),
      ];
    }
    return [
      'title' => '网络流量趋势',
      'unit' => 'B/s',
      'lines' => [
        ['key' => 'rx', 'label' => '接收', 'color' => '#171717'],
        ['key' => 'tx', 'label' => '发送', 'color' => '#71717a'],
      ],
      'points' => $points,
    ];
  }

  /**
   * @param list<array{time: int, values: array<string, float|null>}> $rows
   *   Samples from another chart.
   */
  private function nearestValue(array $rows, int $time, string $dimension): ?float {
    $best = NULL;
    $delta = 180;
    foreach ($rows as $row) {
      $value = $row['values'][$dimension] ?? NULL;
      if ($value === NULL) {
        continue;
      }
      $distance = abs($row['time'] - $time);
      if ($distance < $delta) {
        $delta = $distance;
        $best = $value;
      }
    }
    return $best;
  }

  private function kilobitsToBytes(int|float|null $kilobits): ?float {
    if ($kilobits === NULL || !is_finite((float) $kilobits)) {
      return NULL;
    }
    // Netdata stores outbound bandwidth as a negative number.
    return abs((float) $kilobits) * 1000 / 8;
  }

  private function mib(int|float|null $value): ?float {
    if ($value === NULL || !is_finite((float) $value)) {
      return NULL;
    }
    return ((float) $value) * 1024 * 1024;
  }

  private function gib(int|float|null $value): ?float {
    if ($value === NULL || !is_finite((float) $value)) {
      return NULL;
    }
    return ((float) $value) * 1024 * 1024 * 1024;
  }

  /**
   * @return array<string, mixed>
   *   Disconnected snapshot.
   */
  private function emptySnapshot(string $error): array {
    return [
      'ok' => FALSE,
      'error' => $error,
      'hostname' => '暂无数据',
      'os' => '暂无数据',
      'kernel' => '',
      'uptime' => '暂无数据',
      'agent' => '',
      'connection' => '未连接',
      'updated' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d H:i:s'),
      'cards' => [],
      'cores' => [],
      'cores_note' => '',
      'memory' => [],
      'load' => [],
      'processes' => '',
      'io' => [],
      'interfaces' => [],
      'services' => [],
      'totals_note' => '',
    ];
  }

  private function baseUrl(): string {
    $configured = (string) $this->configFactory->get('xedc_core.settings')->get('netdata_base_url');
    return $configured !== '' ? $configured : 'http://127.0.0.1:19999';
  }

}
