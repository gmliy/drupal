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
    '1m' => 60,
    '5m' => 300,
    '15m' => 900,
    '1h' => 3600,
    '6h' => 21600,
    '24h' => 86400,
    '7d' => 604800,
  ];

  public const SECTIONS = [
    'overview' => ['label' => '实时总览', 'refresh' => 10],
    'cpu' => ['label' => 'CPU', 'refresh' => 3],
    'memory' => ['label' => '内存', 'refresh' => 3],
    'disk' => ['label' => '磁盘', 'refresh' => 5],
    'network' => ['label' => '网络', 'refresh' => 3],
    'system' => ['label' => '系统', 'refresh' => 10],
    'nginx' => ['label' => 'Nginx', 'refresh' => 10],
    'phpfpm' => ['label' => 'PHP-FPM', 'refresh' => 10],
    'mariadb' => ['label' => 'MariaDB', 'refresh' => 10],
    'redis' => ['label' => 'Redis', 'refresh' => 10],
    'docker' => ['label' => 'Docker', 'refresh' => 8],
    'browser' => ['label' => '完整指标', 'refresh' => 60],
    'collectors' => ['label' => '采集器', 'refresh' => 60],
    'alarms' => ['label' => '告警', 'refresh' => 15],
  ];

  /**
   * @var array<string, array<string, mixed>>
   */
  private array $bag = [];

  protected NetdataReader $reader;

  protected MonitorBoard $board;

  public function __construct(
    protected NetdataClient $client,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected NetdataCatalog $catalog,
  ) {
    $this->reader = new NetdataReader($client);
    $this->board = new MonitorBoard($dateFormatter);
  }

  /**
   * @return array<string, mixed>
   *   Display-ready snapshot. Missing metrics stay empty.
   */
  public function snapshot(bool $refresh = FALSE): array {
    $cid = 'xedc_core:monitor:snapshot:' . date_default_timezone_get();
    if ($refresh) {
      $this->invalidate();
    }
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $snapshot = $this->buildSnapshot();
    $expire = $this->time->getRequestTime() + ($snapshot['ok'] ? 8 : 15);
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
    $cid = 'xedc_core:monitor:history:' . $range . ':' . date_default_timezone_get();
    if (!$refresh) {
      $cached = $this->cache->get($cid);
      if ($cached && is_array($cached->data)) {
        return $cached->data;
      }
    }
    $history = $this->buildHistory($range);
    $ttl = match ($range) {
      '1m' => 5,
      '5m' => 15,
      '15m' => 20,
      '1h' => 30,
      '6h' => 60,
      '24h' => 120,
      default => 300,
    };
    $this->cache->set($cid, $history, $this->time->getRequestTime() + $ttl);
    return $history;
  }

  public function invalidate(): void {
    $zone = date_default_timezone_get();
    $this->cache->delete('xedc_core:monitor:snapshot');
    $this->cache->delete('xedc_core:monitor:snapshot:' . $zone);
    $this->cache->delete('xedc_core:monitor:charts');
    $this->catalog->invalidate();
    foreach (array_keys(self::RANGES) as $range) {
      $this->cache->delete('xedc_core:monitor:history:' . $range);
      $this->cache->delete('xedc_core:monitor:history:' . $range . ':' . $zone);
    }
    foreach (array_keys(self::SECTIONS) as $section) {
      $this->cache->delete('xedc_core:monitor:section:' . $section);
      $this->cache->delete('xedc_core:monitor:section:' . $section . ':' . $zone);
      foreach (array_keys(self::RANGES) as $range) {
        $this->cache->delete('xedc_core:monitor:section:' . $section . ':' . $range);
        $this->cache->delete('xedc_core:monitor:section:' . $section . ':' . $range . ':' . $zone);
      }
    }
  }

  /**
   * @return array<string, mixed>
   *   Snapshot payload.
   */
  private function buildSnapshot(): array {
    $empty = $this->emptySnapshot('Netdata 暂不可用。');
    $info = $this->trackedGet('/api/v1/info');
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
    $this->bag = [];
    $this->warm($this->overviewIds($charts));

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
        $this->unavailableMetric('活动连接', '未采集：没有 Nginx stub_status 图表。'),
        $this->unavailableMetric('请求速率', '未采集：没有 Nginx 请求图表。进程数不能代替请求量。'),
      ]),
      $this->service('PHP-FPM', 'app.phpfpm_cpu_utilization', 'app.phpfpm_mem_usage', [
        $this->metric('进程数', 'app.phpfpm_processes', 'processes', '个'),
        $this->unavailableMetric('空闲进程', '未采集：没有 PHP-FPM status 图表。'),
        $this->unavailableMetric('活动进程', '未采集：进程数是进程总数，不是正在处理请求的进程。'),
      ]),
      $this->service('MariaDB', 'app.mariadb_cpu_utilization', 'app.mariadb_mem_usage', [
        $this->metric('活动连接', 'mysql_local.connections_active', 'active', '个'),
        $this->metric('连接上限', 'mysql_local.connections_active', 'limit', '个'),
        $this->metric('查询速率', 'mysql_local.queries', 'queries', '次/秒'),
        $this->metric('慢查询速率', 'mysql_local.queries', 'slow_queries', '次/秒'),
      ]),
      $this->service('Redis', 'app.redis_cpu_utilization', 'app.redis_mem_usage', [
        $this->metric('已连接客户端', 'redis_local.clients', 'connected', '个'),
        $this->metricBytes('Redis 内存', 'redis_local.memory', 'used'),
        $this->metric('命中率', 'redis_local.key_lookup_hit_rate', 'lookup_hit_rate', '%'),
        $this->metric('命令速率', 'redis_local.commands', 'processed', '次/秒'),
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
            : '用户 ' . OpsFormat::percent($user) . ' · 系统 ' . OpsFormat::percent($system) . ' · I/O 等待 ' . OpsFormat::percent($iowait) . ' · 空闲 ' . ($cpuTotal !== NULL && $cpuTotal >= 0 && $cpuTotal <= 100.5 ? OpsFormat::percent(max(0, 100 - $cpuTotal)) . '（推算）' : '未采集'),
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
      'totals_note' => '接口表只显示实时速率，累计字节列保持未采集。累计流量在网络分类，来自物理网卡计数器，不由这里的速率累加。',
      'cpu_rows' => $this->cpuRows($cpu),
      'refresh_seconds' => self::SECTIONS['overview']['refresh'],
      'nav' => $this->navigation(),
      'memory_mode' => (string) ($this->catalog->meta()['memory_mode'] ?? ''),
      'retention' => $this->retentionNote($charts),
      'section' => 'overview',
      'detail' => [],
    ];
  }

  /**
   * @return array<string, mixed>
   *   History payload.
   */
  private function buildHistory(string $range): array {
    $window = self::RANGES[$range];
    $points = match ($range) {
      '1m' => 30,
      '5m' => 60,
      '15m' => 60,
      '1h' => 60,
      '6h' => 72,
      '24h' => 96,
      default => 84,
    };
    $info = $this->trackedGet('/api/v1/info');
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
    $charts = $this->catalog->charts();
    if ($charts === NULL) {
      $this->logger->warning('Netdata charts failed.');
    }
    return $charts;
  }

  /**
   * @return array<string, float>|null
   *   Latest dimension values.
   */
  private function latest(string $chart): ?array {
    if (!array_key_exists($chart, $this->bag)) {
      $this->warm([$chart]);
    }
    $row = $this->bag[$chart] ?? NULL;
    if (!is_array($row) || ($row['status'] ?? '') !== 'ok' || !is_array($row['values'] ?? NULL) || $row['values'] === []) {
      return NULL;
    }
    return $row['values'];
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
    $response = $this->reader->series($this->baseUrl(), $chart, $after, $points);
    $this->recordStats($this->reader->takeStats());
    if (!empty($response['partial']) && ($response['notice'] ?? '') === '' && !empty($response['first_entry'])) {
      $response['notice'] = '该指标最早的采样是 ' . $this->dateFormatter->format((int) $response['first_entry'], 'custom', 'Y-m-d H:i') . '。';
    }
    return $response;
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
        'kind' => preg_match('/^(lo|docker\\d*|br-|veth|virbr|tun|tap|dummy)/', $name) ? '虚拟' : '物理',
        'rx' => OpsFormat::rate($this->kilobitsToBytes($values['received'] ?? NULL)),
        'tx' => OpsFormat::rate($this->kilobitsToBytes($values['sent'] ?? NULL)),
        'state' => $state,
        'totals' => '未采集',
        'packets' => isset($charts['net_packets.' . $name]) ? $this->ifaceRate($this->latest('net_packets.' . $name), 'received', 'sent', '包/秒') : '未采集',
        'errors' => isset($charts['net_errors.' . $name]) ? $this->ifaceRate($this->latest('net_errors.' . $name), 'inbound', 'outbound', '错误/秒') : '未采集',
        'drops' => isset($charts['net_drops.' . $name]) ? $this->ifaceRate($this->latest('net_drops.' . $name), 'inbound', 'outbound', '丢包/秒') : '未采集',
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
      $image = (string) ($chart['labels']['image'] ?? '');
      if ($image === '' || $image === '[none]') {
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
        'label' => (string) (($chart['labels']['cgroup_name'] ?? '') !== '' ? $chart['labels']['cgroup_name'] : $matches[1]),
        'value' => 'CPU ' . OpsFormat::percent(is_numeric($cpuSum) ? (float) $cpuSum : NULL) . ' · 内存 ' . OpsFormat::bytes($this->mib(is_numeric($memValue) ? (float) $memValue : NULL)),
        'reason' => $image,
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
      'cpu_rows' => [],
      'refresh_seconds' => self::SECTIONS['overview']['refresh'],
      'nav' => $this->navigation(),
      'memory_mode' => '',
      'retention' => '',
      'section' => 'overview',
      'detail' => [],
    ];
  }

  /**
   * @return list<array{id: string, label: string, refresh: int}>
   *   Section navigation.
   */
  public function navigation(): array {
    $items = [];
    foreach (self::SECTIONS as $id => $section) {
      $items[] = [
        'id' => $id,
        'label' => $section['label'],
        'refresh' => $section['refresh'],
      ];
    }
    return $items;
  }

  public function normalizeRange(string $range): string {
    return isset(self::RANGES[$range]) ? $range : '1h';
  }

  /**
   * @param array<string, mixed> $filters
   *   Browser filters.
   *
   * @return array<string, mixed>
   *   One monitor section.
   */
  public function section(string $section, string $range, bool $refresh, bool $series, array $filters = []): array {
    if ($section === 'overview') {
      $snapshot = $this->snapshot($refresh);
      $snapshot['section'] = 'overview';
      return $snapshot;
    }
    if (!isset(self::SECTIONS[$section])) {
      return [
        'ok' => FALSE,
        'error' => '未知的监控分类。',
        'section' => $section,
        'groups' => [],
        'charts' => [],
      ];
    }
    $range = $this->normalizeRange($range);
    $page = max(0, (int) ($filters['page'] ?? 0));
    $query = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 80);
    $category = (string) ($filters['category'] ?? '');
    $cid = 'xedc_core:monitor:section:' . $section . ($series ? ':' . $range : '');
    if ($section === 'browser') {
      $cid .= ':' . substr(hash('sha256', $category . '|' . $query . '|' . $page), 0, 16);
    }
    $cid .= ':' . date_default_timezone_get();
    if (!$refresh) {
      $cached = $this->cache->get($cid);
      if ($cached && is_array($cached->data)) {
        return $cached->data;
      }
    }
    $before = $this->stats();
    $charts = $this->charts();
    if ($charts === NULL) {
      return [
        'ok' => FALSE,
        'error' => '没有读到 Netdata 指标目录。',
        'section' => $section,
        'title' => self::SECTIONS[$section]['label'],
        'groups' => [],
        'charts' => [],
        'refresh_seconds' => self::SECTIONS[$section]['refresh'],
      ];
    }
    $ids = $this->board->wantedIds($section, $charts);
    $found = NULL;
    if ($section === 'browser') {
      $found = $this->catalog->search($category, $query, $page);
      $ids = array_map(static fn (array $row): string => (string) $row['id'], $found['rows']);
    }
    $samples = $this->readIntoBag($ids);
    $context = [
      'title' => self::SECTIONS[$section]['label'],
      'refresh' => self::SECTIONS[$section]['refresh'],
      'updated' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d H:i:s'),
      'now' => $this->time->getRequestTime(),
      'notes' => [],
      'stats' => $this->stats(),
    ];
    if ($section === 'alarms') {
      $alarmPayload = $this->alarmPayload();
      $context['alarm_payload'] = $alarmPayload;
      $context['notes'][] = '这里只显示 Netdata 原生告警。页面没有另造阈值告警。';
      if (!empty($alarmPayload['reachable']) && empty($alarmPayload['has_rows'])) {
        $context['notes'][] = '告警接口没有返回告警定义。本机 Netdata 健康检查关闭时就是这样，空白不会被写成正常或警告。';
      }
    }
    if ($section === 'collectors') {
      $context['collector_rows'] = $this->collectorRows();
      $meta = $this->catalog->meta();
      $context['notes'][] = '存储后端 ' . ((string) ($meta['memory_mode'] ?? '') !== '' ? $meta['memory_mode'] : '未知') . '。图表 ' . (int) ($meta['charts_count'] ?? count($charts)) . '，维度 ' . (int) ($meta['dimensions_count'] ?? 0) . '。已保存跨度 ' . $this->retentionNote($charts) . '。';
    }
    if ($found !== NULL) {
      $context['browser'] = $this->browserRows($found, $samples, $query, $category);
    }
    $payload = $this->board->build($section, $charts, $samples, $context);
    $payload['refresh_seconds'] = self::SECTIONS[$section]['refresh'];
    $payload['updated'] = $context['updated'];
    if ($series) {
      $payload['charts'] = $this->sectionCharts($section, $charts, $range);
    }
    $after = $this->stats();
    $payload['elapsed_ms'] = max(0, (int) $after['ms'] - (int) $before['ms']);
    $payload['charts_fetched'] = max(0, (int) $after['calls'] - (int) $before['calls']);
    $this->cache->set($cid, $payload, $this->time->getRequestTime() + (int) self::SECTIONS[$section]['refresh']);
    return $payload;
  }

  /**
   * @return array<string, mixed>
   *   History for one catalog chart.
   */
  public function chartHistory(string $chart, string $range, bool $refresh = FALSE): array {
    unset($refresh);
    if (!$this->catalog->safeId($chart) || !$this->catalog->allows($chart)) {
      return [
        'ok' => FALSE,
        'error' => '图表不存在或未采集。',
        'charts' => [],
      ];
    }
    $range = $this->normalizeRange($range);
    $meta = $this->charts()[$chart] ?? ['title' => $chart, 'units' => ''];
    $series = $this->series($chart, -self::RANGES[$range], 60);
    return [
      'ok' => $series['rows'] !== [],
      'error' => $series['rows'] === [] ? (($series['notice'] ?? '') !== '' ? $series['notice'] : '这个图表没有历史采样。') : '',
      'range' => $range,
      'notice' => (string) ($series['notice'] ?? ''),
      'charts' => [
        'detail' => $this->genericChart($meta, $series),
      ],
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   *
   * @return list<string>
   *   Charts needed by the existing overview.
   */
  private function overviewIds(array $charts): array {
    $ids = [
      'system.cpu', 'system.ram', 'mem.available', 'mem.swap', 'system.load',
      'system.uptime', 'system.processes', 'system.active_processes', 'system.io',
      'system.net', 'disk_space./',
      'app.nginx_cpu_utilization', 'app.nginx_mem_usage', 'app.nginx_processes',
      'app.phpfpm_cpu_utilization', 'app.phpfpm_mem_usage', 'app.phpfpm_processes',
      'app.mariadb_cpu_utilization', 'app.mariadb_mem_usage',
      'mysql_local.connections_active', 'mysql_local.queries',
      'app.redis_cpu_utilization', 'app.redis_mem_usage',
      'redis_local.clients', 'redis_local.memory', 'redis_local.key_lookup_hit_rate', 'redis_local.commands',
    ];
    foreach ($charts as $id => $chart) {
      $context = (string) ($chart['context'] ?? '');
      if (in_array($context, ['cpu.cpu', 'net.net', 'net.operstate', 'net.packets', 'net.errors', 'net.drops', 'disk.ops'], TRUE)) {
        $ids[] = (string) $id;
      }
      if (in_array($context, ['cgroup.cpu', 'cgroup.mem_usage'], TRUE)) {
        $image = (string) ($chart['labels']['image'] ?? '');
        if ($image !== '' && $image !== '[none]') {
          $ids[] = (string) $id;
        }
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * @param list<string> $ids
   *   Chart ids.
   */
  private function warm(array $ids): void {
    $charts = $this->catalog->charts() ?? [];
    $need = [];
    foreach ($ids as $id) {
      if (!is_string($id) || $id === '' || array_key_exists($id, $this->bag)) {
        continue;
      }
      if (!isset($charts[$id])) {
        $this->bag[$id] = [
          'status' => 'missing',
          'values' => NULL,
          'previous' => NULL,
          'sampled' => 0,
          'error' => '',
        ];
        continue;
      }
      $need[] = $id;
    }
    if ($need === []) {
      return;
    }
    foreach ($this->reader->latestMany($this->baseUrl(), $need) as $id => $row) {
      $this->bag[$id] = $row;
    }
    $this->recordStats($this->reader->takeStats());
  }

  /**
   * @param list<string> $ids
   *   Chart ids.
   *
   * @return array<string, array<string, mixed>>
   *   Samples.
   */
  private function readIntoBag(array $ids): array {
    $this->bag = [];
    $this->warm($ids);
    $out = [];
    foreach ($ids as $id) {
      if (isset($this->bag[$id])) {
        $out[$id] = $this->bag[$id];
      }
    }
    return $out;
  }

  /**
   * @param array<string, float>|null $values
   *   CPU dimensions.
   *
   * @return list<array{label: string, value: string}>
   *   CPU rows for the overview.
   */
  private function cpuRows(?array $values): array {
    if ($values === NULL) {
      return [];
    }
    $rows = [];
    foreach ([
      'user' => '用户',
      'system' => '系统',
      'nice' => 'Nice',
      'iowait' => 'I/O 等待',
      'irq' => '硬中断',
      'softirq' => '软中断',
      'steal' => 'Steal',
    ] as $key => $label) {
      $rows[] = [
        'label' => $label,
        'value' => array_key_exists($key, $values) ? OpsFormat::percent($values[$key]) : '未采集',
      ];
    }
    $sum = array_sum($values);
    $rows[] = [
      'label' => '空闲（推算）',
      'value' => ($sum >= 0 && $sum <= 100.5) ? OpsFormat::percent(max(0, 100 - $sum)) : '未采集',
    ];
    return $rows;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   */
  private function retentionNote(array $charts): string {
    $chart = $charts['system.cpu'] ?? NULL;
    if (!is_array($chart) || empty($chart['first_entry']) || empty($chart['last_entry'])) {
      return '暂无已保存跨度';
    }
    $span = max(0, (int) $chart['last_entry'] - (int) $chart['first_entry']);
    return OpsFormat::duration($span);
  }

  /**
   * @param array<string, float>|null $values
   *   Rate dimensions.
   */
  private function ifaceRate(?array $values, string $left, string $right, string $suffix): string {
    if ($values === NULL) {
      return '暂不可用';
    }
    $a = array_key_exists($left, $values) ? OpsFormat::number(abs($values[$left]), 1) : '未采集';
    $b = array_key_exists($right, $values) ? OpsFormat::number(abs($values[$right]), 1) : '未采集';
    return '↓ ' . $a . ' · ↑ ' . $b . ' ' . $suffix;
  }

  /**
   * @param array<string, mixed> $chart
   *   Chart metadata.
   * @param array{rows: list<array{time: int, values: array<string, float|null>}>} $series
   *   History.
   *
   * @return array<string, mixed>
   *   Browser chart spec.
   */
  private function genericChart(array $chart, array $series): array {
    $dims = [];
    foreach ($series['rows'] as $row) {
      foreach ($row['values'] as $name => $value) {
        if ($value !== NULL) {
          $dims[(string) $name] = TRUE;
        }
      }
    }
    $names = array_slice(array_keys($dims), 0, 6);
    $colors = ['#171717', '#52525b', '#a1a1aa', '#d97706', '#0369a1', '#15803d'];
    $lines = [];
    foreach ($names as $index => $name) {
      $lines[] = [
        'key' => 'd' . $index,
        'label' => $name,
        'color' => $colors[$index],
      ];
    }
    // Area charts point outbound and writes downward. The live numbers already
    // use the absolute rate, so the section chart uses that same direction.
    $absolute = preg_match('/\/s|kilobit|kib/i', (string) ($chart['units'] ?? '')) === 1;
    $points = [];
    foreach ($series['rows'] as $row) {
      $point = ['t' => $this->dateFormatter->format($row['time'], 'custom', 'm-d H:i')];
      foreach ($names as $index => $name) {
        $value = $row['values'][$name] ?? NULL;
        if (is_numeric($value)) {
          $number = (float) $value;
          $point['d' . $index] = round($absolute ? abs($number) : $number, 3);
        }
        else {
          $point['d' . $index] = NULL;
        }
      }
      $points[] = $point;
    }
    return [
      'title' => (string) ($chart['title'] ?? ''),
      'unit' => (string) ($chart['units'] ?? ''),
      'lines' => $lines,
      'points' => $points,
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   *
   * @return array<string, array<string, mixed>>
   *   Section charts.
   */
  private function sectionCharts(string $section, array $charts, string $range): array {
    $points = match ($range) {
      '1m' => 30,
      '5m', '15m', '1h' => 60,
      '6h' => 72,
      '24h' => 96,
      default => 84,
    };
    $chartsOut = [];
    foreach ($this->board->seriesIds($section, $charts) as $index => $id) {
      $chartsOut['s' . $index] = $this->genericChart(
        $charts[$id] ?? ['title' => $id, 'units' => ''],
        $this->series($id, -self::RANGES[$range], $points),
      );
    }
    return $chartsOut;
  }

  /**
   * @return array<string, mixed>
   *   Native alarm documents.
   */
  private function alarmPayload(): array {
    $current = $this->trackedGet('/api/v1/alarms', ['all' => '1']);
    $log = $this->trackedGet('/api/v1/alarm_log');
    $currentData = $current['ok'] ? $current['data'] : [];
    $logData = $log['ok'] ? $log['data'] : [];
    $defined = is_array($currentData['alarms'] ?? NULL) ? $currentData['alarms'] : [];
    $history = is_array($logData) ? $logData : [];
    return [
      'current' => $currentData,
      'log' => $logData,
      'reachable' => $current['ok'] && $log['ok'],
      'has_rows' => $defined !== [] || $history !== [],
    ];
  }

  /**
   * @return list<array{name: string, charts: string, state: string}>
   *   Collector rows.
   */
  private function collectorRows(): array {
    $rows = [];
    foreach ($this->catalog->collectorCounts() as $name => $count) {
      $rows[] = [
        'name' => (string) $name,
        'charts' => (string) $count,
        'state' => '已发现图表',
      ];
    }
    return $rows;
  }

  /**
   * @param array{total: int, pages: int, page: int, rows: list<array<string, mixed>>} $found
   *   Catalog page.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return array<string, mixed>
   *   Browser payload.
   */
  private function browserRows(array $found, array $samples, string $query, string $category): array {
    $rows = [];
    foreach ($found['rows'] as $chart) {
      $sample = $samples[$chart['id']] ?? ['status' => 'missing', 'values' => NULL, 'sampled' => 0];
      $bits = [];
      if (is_array($sample['values'] ?? NULL)) {
        $slice = array_slice($sample['values'], 0, 3, TRUE);
        foreach ($slice as $dimension => $value) {
          $bits[] = $dimension . ' ' . OpsFormat::number((float) $value, 2);
        }
      }
      $rows[] = [
        'id' => (string) $chart['id'],
        'title' => (string) $chart['title'],
        'context' => (string) $chart['context'],
        'units' => (string) $chart['units'],
        'category' => (string) $chart['category'],
        'dimensions' => implode(', ', array_slice($chart['dimensions'] ?? [], 0, 8)),
        'plugin' => trim((string) $chart['plugin'] . '/' . (string) $chart['module'], '/'),
        'status' => match ((string) ($sample['status'] ?? 'missing')) {
          'ok' => '正常',
          'failed' => '采集失败',
          'empty' => '暂不可用',
          default => '未采集',
        },
        'value' => $bits === [] ? '' : implode(' · ', $bits),
        'updated' => !empty($sample['sampled']) ? $this->dateFormatter->format((int) $sample['sampled'], 'custom', 'H:i:s') : '',
      ];
    }
    return [
      'total' => $found['total'],
      'pages' => $found['pages'],
      'page' => $found['page'],
      'query' => $query,
      'category' => $category,
      'categories' => $this->catalog->categoryCounts(),
      'rows' => $rows,
    ];
  }

  /**
   * @param array<string, mixed> $query
   *   API query.
   *
   * @return array{ok: bool, error: string, data: array<string, mixed>, status: int, elapsed_ms: int}
   *   API result, counted in the monitor stats.
   */
  private function trackedGet(string $path, array $query = []): array {
    $response = $this->client->get($this->baseUrl(), $path, $query);
    $this->recordStats([
      'calls' => 1,
      'fails' => empty($response['ok']) ? 1 : 0,
      'ms' => (int) ($response['elapsed_ms'] ?? 0),
    ]);
    return $response;
  }

  /**
   * @param array{calls?: int, fails?: int, ms?: int} $delta
   *   One batch.
   */
  private function recordStats(array $delta): void {
    $current = $this->stats();
    $current['calls'] += (int) ($delta['calls'] ?? 0);
    $current['fails'] += (int) ($delta['fails'] ?? 0);
    $current['ms'] += (int) ($delta['ms'] ?? 0);
    $current['last'] = $this->time->getRequestTime();
    $this->cache->set('xedc_core:monitor:stats', $current, $this->time->getRequestTime() + 86400);
  }

  /**
   * @return array{calls: int, fails: int, ms: int, last: int}
   *   Cumulative API stats.
   */
  private function stats(): array {
    $cached = $this->cache->get('xedc_core:monitor:stats');
    $data = is_array($cached->data ?? NULL) ? $cached->data : [];
    return [
      'calls' => (int) ($data['calls'] ?? 0),
      'fails' => (int) ($data['fails'] ?? 0),
      'ms' => (int) ($data['ms'] ?? 0),
      'last' => (int) ($data['last'] ?? 0),
    ];
  }

  private function baseUrl(): string {
    $configured = (string) $this->configFactory->get('xedc_core.settings')->get('netdata_base_url');
    return $configured !== '' ? $configured : 'http://127.0.0.1:19999';
  }

}
