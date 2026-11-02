<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Turns discovered Netdata charts into monitor sections.
 *
 * Missing charts stay "未采集". Empty or failed reads are not shown as zero.
 */
class MonitorBoard {

  private int $now = 0;

  private const CPU_LABELS = [
    'user' => '用户',
    'system' => '系统',
    'nice' => 'Nice',
    'iowait' => 'I/O 等待',
    'irq' => '硬中断',
    'softirq' => '软中断',
    'steal' => 'Steal',
    'guest' => 'Guest',
    'guest_nice' => 'Guest Nice',
  ];

  public function __construct(
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog index.
   * @param array<string, array<string, mixed>> $samples
   *   Reader samples keyed by chart id.
   * @param array<string, mixed> $context
   *   Section extras such as alarms, browser rows, and collector info.
   *
   * @return array<string, mixed>
   *   Section payload.
   */
  public function build(string $section, array $charts, array $samples, array $context): array {
    $this->now = (int) ($context['now'] ?? time());
    $groups = match ($section) {
      'cpu' => $this->cpu($charts, $samples),
      'memory' => $this->memory($charts, $samples),
      'disk' => $this->disk($charts, $samples),
      'network' => $this->network($charts, $samples),
      'system' => $this->system($charts, $samples),
      'nginx' => $this->serviceSection($charts, $samples, 'nginx', 'app.nginx_', 'systemd_nginx.', ['nginx.']),
      'phpfpm' => $this->serviceSection($charts, $samples, 'phpfpm', 'app.phpfpm_', 'systemd_php', ['phpfpm.', 'php_fpm.']),
      'mariadb' => $this->mariadb($charts, $samples),
      'redis' => $this->redis($charts, $samples),
      'docker' => $this->docker($charts, $samples),
      'collectors' => $this->collectors($charts, $samples, $context),
      'alarms' => [],
      'browser' => [],
      default => [],
    };
    $payload = $this->shell($section, $groups, $context);
    if ($section === 'alarms') {
      $payload['alarms'] = $this->alarms($context);
    }
    if ($section === 'browser') {
      $payload['browser'] = $context['browser'] ?? [];
    }
    if ($section === 'collectors') {
      $payload['collectors'] = $context['collector_rows'] ?? [];
    }
    return $payload;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog index.
   *
   * @return list<string>
   *   Chart ids to read for this section.
   */
  public function wantedIds(string $section, array $charts): array {
    $ids = match ($section) {
      'cpu' => $this->mergeIds($charts, [
        'system.cpu', 'system.load', 'system.intr', 'system.ctxt', 'system.forks',
      ], ['cpu.cpu']),
      'memory' => $this->mergeIds($charts, [
        'system.ram', 'mem.available', 'mem.swap', 'mem.swapio', 'mem.reclaiming',
        'mem.oom_kill', 'mem.kernel', 'mem.writeback', 'system.shared_memory_bytes',
      ]),
      'disk' => $this->idsByContexts($charts, [
        'disk.space', 'disk.inodes', 'disk.io', 'disk.ops', 'disk.util', 'disk.await',
        'disk.backlog', 'disk.qops', 'disk.iotime', 'disk.busy', 'disk.svctm', 'system.io',
      ]),
      'network' => $this->mergeIds($charts, ['system.net'], [], [
        'net.net', 'net.packets', 'net.errors', 'net.drops', 'net.operstate', 'net.speed',
        'net.fifo', 'ipv4.sockstat_tcp_sockets', 'ipv4.sockstat_udp_sockets',
        'ipv6.sockstat6_tcp_sockets', 'netfilter.conntrack_sockets', 'ip.sockstat_sockets',
      ]),
      'system' => $this->mergeIds($charts, [
        'system.uptime', 'system.load', 'system.processes', 'system.active_processes',
        'system.processes_state', 'system.forks', 'system.file_nr_used',
        'system.file_nr_utilization', 'system.ctxt', 'system.intr', 'system.entropy',
        'system.post_update_reboot_status', 'mem.kernel',
      ]),
      'nginx' => $this->prefixIds($charts, ['app.nginx_', 'systemd_nginx.']),
      'phpfpm' => $this->prefixIds($charts, ['app.phpfpm_', 'systemd_php']),
      'mariadb' => $this->mergeIds($charts, [], ['app.mariadb'], [
        'mysql.connections_active', 'mysql.queries', 'mysql.threads', 'mysql.handlers',
        'mysql.innodb_buffer_pool_pages', 'mysql.innodb_buffer_pool_bytes',
        'mysql.innodb_cur_row_lock', 'mysql.net',
      ]),
      'redis' => $this->mergeIds($charts, [], [], [
        'redis.memory', 'redis.clients', 'redis.commands', 'redis.database_keys',
        'redis.database_expires_keys', 'redis.keyspace_lookup_hit_rate', 'redis.net',
        'redis.bgsave_health', 'redis.rdb_changes',
      ]),
      'docker' => $this->dockerIds($charts),
      'collectors' => $this->idsByContextPrefix($charts, 'netdata.plugin_data_collection'),
      default => [],
    };
    return array_slice(array_values(array_unique($ids)), 0, 80);
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog index.
   *
   * @return list<string>
   *   Up to two charts for the section trend.
   */
  public function seriesIds(string $section, array $charts): array {
    $pick = match ($section) {
      'cpu' => ['system.cpu'],
      'memory' => ['system.ram'],
      'disk' => [$this->firstContext($charts, 'disk.space'), $this->firstContext($charts, 'disk.io')],
      'network' => [$this->firstPhysicalNet($charts), 'system.net'],
      'system' => ['system.load'],
      'nginx' => ['app.nginx_cpu_utilization'],
      'phpfpm' => ['app.phpfpm_cpu_utilization'],
      'mariadb' => [$this->firstContext($charts, 'mysql.queries')],
      'redis' => [$this->firstContext($charts, 'redis.commands')],
      'docker' => [$this->firstContext($charts, 'cgroup.cpu')],
      default => [],
    };
    $ids = [];
    foreach ($pick as $id) {
      if (is_string($id) && $id !== '' && isset($charts[$id])) {
        $ids[] = $id;
      }
    }
    return array_slice(array_values(array_unique($ids)), 0, 2);
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog index.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function cpu(array $charts, array $samples): array {
    $groups = [];
    $metrics = [];
    $sample = $samples['system.cpu'] ?? NULL;
    if (!isset($charts['system.cpu'])) {
      $metrics[] = $this->absent('cpu.total', 'CPU 总使用率', '没有 system.cpu 图表。');
    }
    elseif (!is_array($sample) || ($sample['status'] ?? '') !== 'ok') {
      $metrics[] = $this->fromSample('cpu.total', 'CPU 总使用率', 'system.cpu', $sample, NULL, 'percent');
    }
    else {
      $values = $sample['values'] ?? [];
      $sum = array_sum($values);
      $previous = isset($sample['previous']) ? array_sum($sample['previous']) : NULL;
      $metrics[] = $this->metric('cpu.total', '忙时合计', $this->format('percent', $sum), 'ok', '%', 'system.cpu', (int) $sample['sampled'], '各已采集状态之和，不含空闲。', $sum, $previous);
      foreach (self::CPU_LABELS as $dimension => $label) {
        $metrics[] = $this->dimensionMetric('cpu.' . $dimension, $label, 'system.cpu', $sample, $dimension, 'percent');
      }
      foreach ($values as $dimension => $value) {
        if (!isset(self::CPU_LABELS[$dimension])) {
          $metrics[] = $this->dimensionMetric('cpu.' . $dimension, (string) $dimension, 'system.cpu', $sample, (string) $dimension, 'percent');
        }
      }
      $idle = ($sum >= 0 && $sum <= 100.5) ? max(0, 100 - $sum) : NULL;
      if ($idle === NULL) {
        $metrics[] = $this->absent('cpu.idle', '空闲', '各状态合计超出 0–100，不能反推空闲。');
      }
      else {
        $metrics[] = $this->metric('cpu.idle', '空闲', OpsFormat::percent($idle), 'derived', '%', 'system.cpu', (int) $sample['sampled'], '由 100% 减去已采集状态得出，Netdata 这张图没有单独的 idle 维度。');
      }
    }
    if (!$this->hasContext($charts, 'cpufreq') && !$this->hasIdFragment($charts, 'cpufreq')) {
      $metrics[] = $this->absent('cpu.freq', 'CPU 频率', '没有 cpufreq 图表。主机标签里的频率不是实时采样。');
    }
    $groups[] = ['title' => 'CPU 状态', 'metrics' => $metrics];

    $cores = [];
    foreach ($this->idsByContext($charts, 'cpu.cpu') as $id) {
      $index = preg_match('/(\d+)$/', $id, $matches) ? $matches[1] : $id;
      $sample = $samples[$id] ?? NULL;
      if (!is_array($sample) || ($sample['status'] ?? '') !== 'ok') {
        $cores[] = $this->fromSample('core.' . $index, 'CPU ' . $index, $id, $sample, NULL, 'percent');
        continue;
      }
      $sum = array_sum($sample['values']);
      $previous = isset($sample['previous']) ? array_sum($sample['previous']) : NULL;
      $cores[] = $this->metric('core.' . $index, 'CPU ' . $index, OpsFormat::percent($sum), 'ok', '%', $id, (int) $sample['sampled'], '该核心各状态之和。', $sum, $previous);
    }
    if ($cores === []) {
      $cores[] = $this->absent('core.none', '每核心使用率', '没有 cpu.cpu 图表。');
    }
    $groups[] = ['title' => '每核心', 'metrics' => $cores];
    $groups[] = [
      'title' => '负载与调度',
      'metrics' => [
        $this->dimensionMetric('load.1', '负载 1 分钟', 'system.load', $samples['system.load'] ?? NULL, 'load1', 'number'),
        $this->dimensionMetric('load.5', '负载 5 分钟', 'system.load', $samples['system.load'] ?? NULL, 'load5', 'number'),
        $this->dimensionMetric('load.15', '负载 15 分钟', 'system.load', $samples['system.load'] ?? NULL, 'load15', 'number'),
        $this->dimensionMetric('cpu.intr', '中断', 'system.intr', $samples['system.intr'] ?? NULL, 'interrupts', 'number'),
        $this->dimensionMetric('cpu.ctxt', '上下文切换', 'system.ctxt', $samples['system.ctxt'] ?? NULL, 'switches', 'number'),
        $this->dimensionMetric('cpu.forks', '新建进程', 'system.forks', $samples['system.forks'] ?? NULL, 'started', 'number'),
      ],
    ];
    return $groups;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function memory(array $charts, array $samples): array {
    $metrics = [];
    $ram = $samples['system.ram'] ?? NULL;
    $values = is_array($ram) ? ($ram['values'] ?? NULL) : NULL;
    if (is_array($values) && isset($values['free'], $values['used'], $values['cached'], $values['buffers'])) {
      $total = $values['free'] + $values['used'] + $values['cached'] + $values['buffers'];
      $metrics[] = $this->metric('mem.total', '总内存', OpsFormat::bytes($this->mib($total)), 'derived', 'bytes', 'system.ram', (int) $ram['sampled'], 'free、used、cached、buffers 合计。');
    }
    else {
      $metrics[] = $this->absent('mem.total', '总内存', 'system.ram 缺少 free/used/cached/buffers，没有改用 0。');
    }
    foreach (['used' => '已用（不含缓存）', 'free' => '空闲', 'cached' => 'Cached', 'buffers' => 'Buffers'] as $dimension => $label) {
      $metrics[] = $this->dimensionMetric('mem.' . $dimension, $label, 'system.ram', $ram, $dimension, 'mib');
    }
    $metrics[] = $this->dimensionMetric('mem.avail', '可用', 'mem.available', $samples['mem.available'] ?? NULL, 'avail', 'mib');
    $metrics[] = $this->dimensionMetric('mem.active', 'Active', 'mem.reclaiming', $samples['mem.reclaiming'] ?? NULL, 'active', 'mib');
    $metrics[] = $this->dimensionMetric('mem.inactive', 'Inactive', 'mem.reclaiming', $samples['mem.reclaiming'] ?? NULL, 'inactive', 'mib');
    if (isset($charts['system.shared_memory_bytes'])) {
      $metrics[] = $this->dimensionMetric('mem.sysv', 'System V 共享内存', 'system.shared_memory_bytes', $samples['system.shared_memory_bytes'] ?? NULL, 'bytes', 'bytes');
    }
    $metrics[] = $this->absent('mem.shmem', 'Shmem', '没有 Meminfo Shmem 维度。System V 共享内存不能代替它。');
    $swap = $samples['mem.swap'] ?? NULL;
    $swapValues = is_array($swap) ? ($swap['values'] ?? NULL) : NULL;
    if (is_array($swapValues) && isset($swapValues['free'], $swapValues['used'])) {
      $metrics[] = $this->metric('mem.swap.total', 'Swap 总量', OpsFormat::bytes($this->mib($swapValues['free'] + $swapValues['used'])), 'derived', 'bytes', 'mem.swap', (int) $swap['sampled'], 'free 与 used 合计。');
    }
    else {
      $metrics[] = $this->absent('mem.swap.total', 'Swap 总量', '没有同时读到 swap free 和 used。');
    }
    $metrics[] = $this->dimensionMetric('mem.swap.used', 'Swap 已用', 'mem.swap', $swap, 'used', 'mib');
    $metrics[] = $this->dimensionMetric('mem.swap.free', 'Swap 空闲', 'mem.swap', $swap, 'free', 'mib');
    $metrics[] = $this->dimensionMetric('mem.swap.in', 'Swap 读', 'mem.swapio', $samples['mem.swapio'] ?? NULL, 'in', 'kib_rate');
    $metrics[] = $this->dimensionMetric('mem.swap.out', 'Swap 写', 'mem.swapio', $samples['mem.swapio'] ?? NULL, 'out', 'kib_rate');
    if ($this->hasContext($charts, 'system.pressure') || $this->hasIdFragment($charts, 'system.ram_pressure') || $this->hasIdFragment($charts, 'mem.pressure')) {
      $metrics[] = $this->metric('mem.pressure', '内存压力', '已发现压力图表', 'ok', '', '', 0, '具体曲线在完整指标里按图表 ID 打开。');
    }
    else {
      $metrics[] = $this->absent('mem.pressure', '内存压力', '宿主机没有 PSI / pressure 图表。容器压力在 Docker 分类，不能当成宿主机压力。');
    }
    $metrics[] = $this->dimensionMetric('mem.oom', 'OOM 速率', 'mem.oom_kill', $samples['mem.oom_kill'] ?? NULL, 'kills', 'number');
    $groups = [['title' => '内存', 'metrics' => $metrics]];
    $groups[] = $this->genericGroup('内核内存', $charts, $samples, ['mem.kernel', 'mem.writeback']);
    return $groups;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function disk(array $charts, array $samples): array {
    $groups = [];
    $mounts = [];
    foreach ($this->idsByContext($charts, 'disk.space') as $id) {
      $mount = (string) ($charts[$id]['family'] ?? $id);
      $sample = $samples[$id] ?? NULL;
      $values = is_array($sample) ? ($sample['values'] ?? NULL) : NULL;
      $inodeId = 'disk_inodes.' . $mount;
      $inodes = $samples[$inodeId] ?? NULL;
      $inodeValues = is_array($inodes) ? ($inodes['values'] ?? NULL) : NULL;
      $used = is_array($values) ? ($values['used'] ?? NULL) : NULL;
      $avail = is_array($values) ? ($values['avail'] ?? NULL) : NULL;
      $reserved = is_array($values) ? ($values['reserved for root'] ?? $values['reserved_for_root'] ?? NULL) : NULL;
      $percent = (is_numeric($used) && is_numeric($avail) && ($used + $avail) > 0) ? ($used / ($used + $avail)) * 100 : NULL;
      $inodePercent = NULL;
      if (is_array($inodeValues) && isset($inodeValues['used'], $inodeValues['avail']) && ($inodeValues['used'] + $inodeValues['avail']) > 0) {
        $inodePercent = ($inodeValues['used'] / ($inodeValues['used'] + $inodeValues['avail'])) * 100;
      }
      $mounts[] = [
        'mount' => $mount,
        'kind' => '挂载点',
        'total' => (is_numeric($used) && is_numeric($avail)) ? OpsFormat::bytes($this->gib($used + $avail + (is_numeric($reserved) ? $reserved : 0))) : $this->sampleText($sample, isset($charts[$id])),
        'used' => is_numeric($used) ? OpsFormat::bytes($this->gib((float) $used)) : $this->sampleText($sample, isset($charts[$id])),
        'avail' => is_numeric($avail) ? OpsFormat::bytes($this->gib((float) $avail)) : $this->sampleText($sample, isset($charts[$id])),
        'percent' => $percent === NULL ? $this->sampleText($sample, isset($charts[$id])) : OpsFormat::percent($percent),
        'inodes' => is_array($inodeValues) && isset($inodeValues['used'], $inodeValues['avail']) ? OpsFormat::number($inodeValues['used'] + $inodeValues['avail'], 0) : (isset($charts[$inodeId]) ? $this->sampleText($inodes, TRUE) : '未采集'),
        'inode_percent' => $inodePercent === NULL ? (isset($charts[$inodeId]) ? '暂不可用' : '未采集') : OpsFormat::percent($inodePercent),
      ];
    }
    $devices = [];
    foreach ($this->idsByContext($charts, 'disk.io') as $id) {
      if (!str_starts_with($id, 'disk.')) {
        continue;
      }
      $name = substr($id, 5);
      $devices[] = [
        'name' => $name,
        'kind' => '物理设备',
        'read' => $this->pairValue($samples, 'disk.' . $name, 'reads', 'kib_rate'),
        'write' => $this->pairValue($samples, 'disk.' . $name, 'writes', 'kib_rate'),
        'read_iops' => $this->pairValue($samples, 'disk_ops.' . $name, 'reads', 'number'),
        'write_iops' => $this->pairValue($samples, 'disk_ops.' . $name, 'writes', 'number'),
        'util' => $this->pairValue($samples, 'disk_util.' . $name, 'utilization', 'percent'),
        'await_read' => $this->pairValue($samples, 'disk_await.' . $name, 'reads', 'number'),
        'await_write' => $this->pairValue($samples, 'disk_await.' . $name, 'writes', 'number'),
        'queue' => isset($charts['disk_qops.' . $name]) ? $this->pairValue($samples, 'disk_qops.' . $name, 'operations', 'number') : '未采集',
        'backlog' => isset($charts['disk_backlog.' . $name]) ? $this->pairValue($samples, 'disk_backlog.' . $name, 'backlog', 'number') : '未采集',
        'requests' => '未采集',
      ];
    }
    $groups[] = [
      'title' => '磁盘与文件系统',
      'metrics' => [
        $this->metric('disk.note', '统计范围', '挂载点与物理设备分开', 'ok', '', '', 0, '容量看挂载点，吞吐和 IOPS 看物理设备。分区、逻辑卷和丢弃操作没有并进容量。累计读写请求数没有计数器，只显示速率。'),
      ],
      'tables' => [
        [
          'title' => '挂载点',
          'headers' => ['挂载点', '类型', '容量', '已用', '可用', '使用率', 'inode', 'inode 使用率'],
          'rows' => array_map(static fn (array $row): array => [
            $row['mount'], $row['kind'], $row['total'], $row['used'], $row['avail'], $row['percent'], $row['inodes'], $row['inode_percent'],
          ], $mounts),
        ],
        [
          'title' => '物理设备',
          'headers' => ['设备', '类型', '读', '写', '读 IOPS', '写 IOPS', '利用率', '读延迟 ms', '写延迟 ms', '队列', '积压 ms', '累计请求'],
          'rows' => array_map(static fn (array $row): array => [
            $row['name'], $row['kind'], $row['read'], $row['write'], $row['read_iops'], $row['write_iops'], $row['util'], $row['await_read'], $row['await_write'], $row['queue'], $row['backlog'], $row['requests'],
          ], $devices),
        ],
      ],
    ];
    $host = $samples['system.io'] ?? NULL;
    $groups[] = [
      'title' => '内核聚合吞吐',
      'metrics' => [
        $this->dimensionMetric('disk.sys.read', 'system.io 读', 'system.io', $host, 'in', 'kib_rate'),
        $this->dimensionMetric('disk.sys.write', 'system.io 写', 'system.io', $host, 'out', 'kib_rate'),
      ],
    ];
    return $groups;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function network(array $charts, array $samples): array {
    $rows = [];
    foreach ($this->idsByContext($charts, 'net.net') as $id) {
      $name = (string) ($charts[$id]['family'] ?? $id);
      $sample = $samples[$id] ?? NULL;
      $values = is_array($sample) ? ($sample['values'] ?? NULL) : NULL;
      $rx = is_array($values) ? ($values['received'] ?? NULL) : NULL;
      $tx = is_array($values) ? ($values['sent'] ?? NULL) : NULL;
      $speedId = 'net_speed.' . $name;
      $speed = is_array($samples[$speedId]['values'] ?? NULL) ? ($samples[$speedId]['values']['speed'] ?? NULL) : NULL;
      $util = '未采集';
      if (is_numeric($speed) && (float) $speed > 0 && (is_numeric($rx) || is_numeric($tx))) {
        $peak = max(abs((float) ($rx ?? 0)), abs((float) ($tx ?? 0)));
        $util = OpsFormat::percent($peak / (float) $speed * 100);
      }
      elseif (isset($charts[$speedId]) && is_numeric($speed) && (float) $speed <= 0) {
        $util = '未采集';
      }
      $rows[] = [
        $name,
        $this->ifaceKind($name),
        isset($charts['net_operstate.' . $name]) ? $this->operstate($samples['net_operstate.' . $name] ?? NULL) : '未采集',
        is_numeric($rx) ? OpsFormat::rate($this->kilobits((float) $rx)) : $this->sampleText($sample, TRUE),
        is_numeric($tx) ? OpsFormat::rate($this->kilobits((float) $tx)) : $this->sampleText($sample, TRUE),
        '未采集',
        '未采集',
        $this->ifacePair($charts, $samples, 'net_packets.' . $name, 'received', 'sent', '包/秒'),
        $this->ifacePair($charts, $samples, 'net_errors.' . $name, 'inbound', 'outbound', '错误/秒'),
        $this->ifacePair($charts, $samples, 'net_drops.' . $name, 'inbound', 'outbound', '丢包/秒'),
        $util,
      ];
    }
    $tcp = [
      $this->dimensionMetric('tcp.alloc', 'TCP alloc', 'ipv4.sockstat_tcp_sockets', $samples['ipv4.sockstat_tcp_sockets'] ?? NULL, 'alloc', 'number'),
      $this->dimensionMetric('tcp.inuse', 'TCP inuse', 'ipv4.sockstat_tcp_sockets', $samples['ipv4.sockstat_tcp_sockets'] ?? NULL, 'inuse', 'number'),
      $this->dimensionMetric('tcp.timewait', 'TCP timewait', 'ipv4.sockstat_tcp_sockets', $samples['ipv4.sockstat_tcp_sockets'] ?? NULL, 'timewait', 'number'),
      $this->dimensionMetric('tcp.orphan', 'TCP orphan', 'ipv4.sockstat_tcp_sockets', $samples['ipv4.sockstat_tcp_sockets'] ?? NULL, 'orphan', 'number'),
      $this->dimensionMetric('udp.inuse', 'UDP inuse', 'ipv4.sockstat_udp_sockets', $samples['ipv4.sockstat_udp_sockets'] ?? NULL, 'inuse', 'number'),
      $this->dimensionMetric('tcp6.inuse', 'TCP6 inuse', 'ipv6.sockstat6_tcp_sockets', $samples['ipv6.sockstat6_tcp_sockets'] ?? NULL, 'inuse', 'number'),
      $this->dimensionMetric('nf.conn', 'conntrack', 'netfilter.conntrack_sockets', $samples['netfilter.conntrack_sockets'] ?? NULL, 'connections', 'number'),
    ];
    if (!$this->hasIdFragment($charts, 'handshake') && !$this->hasContextPrefix($charts, 'ipv4.tcp')) {
      $tcp[] = $this->absent('tcp.handshake', 'TCP 建立/失败/重传', '没有 tcphandshake、tcpconnaborts 或 tcpretrans 图表。sockstat 的 inuse 不是这些计数。');
    }
    $aggregate = $samples['system.net'] ?? NULL;
    return [
      [
        'title' => '网卡',
        'metrics' => [
          $this->metric('net.note', '速率', '由 Netdata 计数器差分', 'ok', '', 'net.net', 0, '接收/发送速度来自 Netdata 采样窗口。累计流量在下方单独统计，读取物理网卡计数器，不把速率积分成总流量，也不把虚拟网卡计入主机流量。'),
          $this->dimensionMetricAny('net.agg.rx', '聚合接收', 'system.net', $aggregate, ['received', 'InOctets'], 'kilobits'),
          $this->dimensionMetricAny('net.agg.tx', '聚合发送', 'system.net', $aggregate, ['sent', 'OutOctets'], 'kilobits'),
        ],
        'tables' => [[
          'title' => '接口',
          'headers' => ['接口', '类型', '状态', '接收', '发送', '累计接收', '累计发送', '数据包', '错误', '丢包', '带宽使用率'],
          'rows' => $rows,
        ]],
      ],
      ['title' => '协议', 'metrics' => $tcp],
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function system(array $charts, array $samples): array {
    $metrics = [
      $this->dimensionMetric('sys.uptime', '运行时间', 'system.uptime', $samples['system.uptime'] ?? NULL, 'uptime', 'duration'),
      $this->dimensionMetric('sys.load1', '负载 1 分钟', 'system.load', $samples['system.load'] ?? NULL, 'load1', 'number'),
      $this->dimensionMetric('sys.load5', '负载 5 分钟', 'system.load', $samples['system.load'] ?? NULL, 'load5', 'number'),
      $this->dimensionMetric('sys.load15', '负载 15 分钟', 'system.load', $samples['system.load'] ?? NULL, 'load15', 'number'),
      $this->dimensionMetric('sys.active', '活动进程', 'system.active_processes', $samples['system.active_processes'] ?? NULL, 'active', 'number'),
      $this->dimensionMetric('sys.running', '运行进程', 'system.processes', $samples['system.processes'] ?? NULL, 'running', 'number'),
      $this->dimensionMetric('sys.blocked', '阻塞进程', 'system.processes', $samples['system.processes'] ?? NULL, 'blocked', 'number'),
      $this->dimensionMetric('sys.files', '文件描述符', 'system.file_nr_used', $samples['system.file_nr_used'] ?? NULL, 'used', 'number'),
      $this->dimensionMetric('sys.files_pct', '文件描述符使用率', 'system.file_nr_utilization', $samples['system.file_nr_utilization'] ?? NULL, 'utilization', 'percent'),
      $this->dimensionMetric('sys.ctxt', '上下文切换', 'system.ctxt', $samples['system.ctxt'] ?? NULL, 'switches', 'number'),
      $this->dimensionMetric('sys.intr', '中断', 'system.intr', $samples['system.intr'] ?? NULL, 'interrupts', 'number'),
      $this->dimensionMetric('sys.forks', '新建进程', 'system.forks', $samples['system.forks'] ?? NULL, 'started', 'number'),
      $this->dimensionMetric('sys.entropy', '熵', 'system.entropy', $samples['system.entropy'] ?? NULL, 'entropy', 'number'),
    ];
    if (!$this->hasIdFragment($charts, 'temperature') && !$this->hasContextPrefix($charts, 'sensors.') && !$this->hasContextPrefix($charts, 'ipmi')) {
      $metrics[] = $this->absent('sys.temp', '温度和传感器', '没有 sensors 或 ipmi 图表。');
    }
    if (!$this->hasIdFragment($charts, 'system.cpu_pressure') && !$this->hasContextPrefix($charts, 'system.pressure')) {
      $metrics[] = $this->absent('sys.pressure', '系统压力', '没有宿主机 PSI 图表。');
    }
    $groups = [['title' => '系统', 'metrics' => $metrics]];
    $state = $samples['system.processes_state'] ?? NULL;
    if (isset($charts['system.processes_state'])) {
      $groups[] = $this->genericGroup('进程状态', $charts, $samples, ['system.processes_state']);
    }
    $groups[] = $this->genericGroup('内核内存', $charts, $samples, ['mem.kernel']);
    if (isset($charts['system.post_update_reboot_status'])) {
      $groups[] = $this->genericGroup('重启标记', $charts, $samples, ['system.post_update_reboot_status']);
    }
    unset($state);
    return $groups;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   * @param list<string> $directPrefixes
   *   Context prefixes that mean a real service collector.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function serviceSection(array $charts, array $samples, string $kind, string $appPrefix, string $systemdPrefix, array $directPrefixes): array {
    $direct = [];
    foreach ($directPrefixes as $prefix) {
      $direct = array_merge($direct, $this->idsByContextPrefix($charts, $prefix));
    }
    $groups = [];
    if ($direct === []) {
      $missing = $kind === 'nginx'
        ? [
          $this->absent('nginx.connections', '活跃连接', '没有 Nginx stub_status 图表。'),
          $this->absent('nginx.reading', 'Reading', '没有 Nginx stub_status 图表。'),
          $this->absent('nginx.writing', 'Writing', '没有 Nginx stub_status 图表。'),
          $this->absent('nginx.waiting', 'Waiting', '没有 Nginx stub_status 图表。'),
          $this->absent('nginx.requests', '请求总量', '没有 Nginx 请求计数器。'),
          $this->absent('nginx.rate', '请求速率', '进程数不能代替请求速率。'),
        ]
        : [
          $this->absent('php.idle', '空闲进程', '没有 PHP-FPM status 图表。'),
          $this->absent('php.active', '活跃进程', 'apps.plugin 的进程数是进程总数，不是正在处理请求的进程。'),
          $this->absent('php.max', '最大进程', '没有 PHP-FPM status 图表。'),
          $this->absent('php.queue', 'Listen Queue', '没有 PHP-FPM status 图表。'),
          $this->absent('php.maxqueue', 'Max Listen Queue', '没有 PHP-FPM status 图表。'),
          $this->absent('php.slow', '慢请求', '没有 PHP-FPM status 图表。'),
        ];
      $groups[] = ['title' => '服务状态页', 'metrics' => $missing];
    }
    else {
      $groups[] = $this->genericGroup('服务采集器', $charts, $samples, $direct);
    }
    $ids = array_values(array_filter(array_keys($charts), static fn (string $id): bool => str_starts_with($id, $appPrefix) || str_starts_with($id, $systemdPrefix)));
    $groups[] = $this->genericGroup('进程资源（apps / systemd）', $charts, $samples, array_slice($ids, 0, 16));
    $groups[array_key_last($groups)]['metrics'][] = $this->metric(
      $kind . '.scope',
      '统计范围',
      '进程资源，不是请求日志',
      'ok',
      '',
      '',
      0,
      '这些数字来自进程或 cgroup。没有 status 页面时，不会把它们显示成连接数或请求数。',
    );
    return $groups;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function mariadb(array $charts, array $samples): array {
    $ids = $this->prefixIds($charts, ['mysql_']);
    if ($ids === [] && $this->prefixIds($charts, ['app.mariadb']) === []) {
      return [['title' => 'MariaDB', 'metrics' => [
        $this->absent('db.all', 'MariaDB', '没有 mysql.* 或 app.mariadb 图表。'),
      ]]];
    }
    $queries = $this->firstByContext($charts, 'mysql.queries');
    $threads = $this->firstByContext($charts, 'mysql.threads');
    $active = $this->firstByContext($charts, 'mysql.connections_active');
    $handlers = $this->firstByContext($charts, 'mysql.handlers');
    $pool = $this->firstByContext($charts, 'mysql.innodb_buffer_pool_pages');
    $poolBytes = $this->firstByContext($charts, 'mysql.innodb_buffer_pool_bytes');
    $locks = $this->firstByContext($charts, 'mysql.innodb_cur_row_lock');
    $net = $this->firstByContext($charts, 'mysql.net');
    $metrics = [
      $this->dimensionMetric('db.active', '当前连接', (string) $active, $samples[$active] ?? NULL, 'active', 'number'),
      $this->dimensionMetric('db.limit', '最大连接', (string) $active, $samples[$active] ?? NULL, 'limit', 'number'),
      $this->dimensionMetric('db.qps', '查询速率', (string) $queries, $samples[$queries] ?? NULL, 'queries', 'number'),
      $this->dimensionMetric('db.slow', '慢查询速率', (string) $queries, $samples[$queries] ?? NULL, 'slow_queries', 'number'),
      $this->dimensionMetric('db.running', '运行线程', (string) $threads, $samples[$threads] ?? NULL, 'running', 'number'),
      $this->dimensionMetric('db.connected', '已连接线程', (string) $threads, $samples[$threads] ?? NULL, 'connected', 'number'),
      $this->absent('db.tps', 'TPS', '没有独立事务计数器。handler commit 是提交次数速率，不能叫做 TPS。'),
      $this->dimensionMetric('db.commit', '提交速率', (string) $handlers, $samples[$handlers] ?? NULL, 'commit', 'number'),
      $this->dimensionMetric('db.pool.total', 'Buffer Pool 页', (string) $pool, $samples[$pool] ?? NULL, 'total', 'number'),
      $this->dimensionMetric('db.pool.data', 'Buffer Pool 数据页', (string) $pool, $samples[$pool] ?? NULL, 'data', 'number'),
      $this->dimensionMetric('db.pool.free', 'Buffer Pool 空闲页', (string) $pool, $samples[$pool] ?? NULL, 'free', 'number'),
      $this->dimensionMetric('db.pool.bytes', 'Buffer Pool 数据量', (string) $poolBytes, $samples[$poolBytes] ?? NULL, 'data', 'mib'),
      $this->dimensionMetric('db.locks', '行锁等待', (string) $locks, $samples[$locks] ?? NULL, 'current waits', 'number'),
      $this->dimensionMetric('db.net.in', '数据库网络入', (string) $net, $samples[$net] ?? NULL, 'in', 'kilobits'),
      $this->dimensionMetric('db.net.out', '数据库网络出', (string) $net, $samples[$net] ?? NULL, 'out', 'kilobits'),
      $this->metric('db.scope', '统计范围', '本机 MySQL 采集器', 'ok', '', (string) $queries, 0, '这是 Netdata 的 mysql 采集任务，不是 Matomo 数据库容器的 cgroup。容器资源在 Docker 分类，两边不要相加。'),
    ];
    return [
      ['title' => 'MariaDB', 'metrics' => $metrics],
      $this->genericGroup('进程资源', $charts, $samples, $this->prefixIds($charts, ['app.mariadb'])),
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function redis(array $charts, array $samples): array {
    $memory = $this->firstByContext($charts, 'redis.memory');
    $clients = $this->firstByContext($charts, 'redis.clients');
    $commands = $this->firstByContext($charts, 'redis.commands');
    $keys = $this->firstByContext($charts, 'redis.database_keys');
    $expires = $this->firstByContext($charts, 'redis.database_expires_keys');
    $hit = $this->firstByContext($charts, 'redis.keyspace_lookup_hit_rate');
    $net = $this->firstByContext($charts, 'redis.net');
    $save = $this->firstByContext($charts, 'redis.bgsave_health');
    $changes = $this->firstByContext($charts, 'redis.rdb_changes');
    if ($memory === '' && $clients === '') {
      return [['title' => 'Redis', 'metrics' => [
        $this->absent('redis.all', 'Redis', '没有 redis.* 图表。'),
      ]]];
    }
    $metrics = [
      $this->dimensionMetric('redis.used', '内存使用', $memory, $samples[$memory] ?? NULL, 'used', 'bytes'),
      $this->dimensionMetric('redis.peak', '内存峰值', $memory, $samples[$memory] ?? NULL, 'peak', 'bytes'),
      $this->dimensionMetric('redis.rss', 'RSS', $memory, $samples[$memory] ?? NULL, 'rss', 'bytes'),
      $this->dimensionMetric('redis.max', '内存上限', $memory, $samples[$memory] ?? NULL, 'max', 'bytes'),
      $this->dimensionMetric('redis.clients', '已连接客户端', $clients, $samples[$clients] ?? NULL, 'connected', 'number'),
      $this->dimensionMetric('redis.blocked', '阻塞客户端', $clients, $samples[$clients] ?? NULL, 'blocked', 'number'),
      $this->dimensionMetric('redis.commands', '命令速率', $commands, $samples[$commands] ?? NULL, 'processed', 'number'),
      $this->dimensionMetric('redis.keys', 'Key 数量', $keys, $samples[$keys] ?? NULL, 'db0', 'number'),
      $this->dimensionMetric('redis.expires', '即将过期 Key', $expires, $samples[$expires] ?? NULL, 'db0', 'number'),
      $this->dimensionMetric('redis.hit', '查找命中率', $hit, $samples[$hit] ?? NULL, 'lookup_hit_rate', 'percent'),
      $this->absent('redis.miss', 'Miss 数量', '没有 miss 计数图表。命中率不能反推 miss 次数。'),
      $this->dimensionMetric('redis.rx', '网络接收', $net, $samples[$net] ?? NULL, 'received', 'kilobits'),
      $this->dimensionMetric('redis.tx', '网络发送', $net, $samples[$net] ?? NULL, 'sent', 'kilobits'),
      $this->dimensionMetric('redis.bgsave', '最近 bgsave', $save, $samples[$save] ?? NULL, 'last_bgsave', 'number'),
      $this->dimensionMetric('redis.rdb', 'RDB 变更', $changes, $samples[$changes] ?? NULL, 'changes', 'number'),
    ];
    return [['title' => 'Redis', 'metrics' => $metrics]];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function docker(array $charts, array $samples): array {
    $containers = $this->containers($charts);
    if ($containers === []) {
      return [['title' => 'Docker', 'metrics' => [
        $this->absent('docker.containers', '容器', '没有带镜像名的 cgroup。未把临时 scope 当成容器，也没有读取 Docker socket。'),
      ]]];
    }
    $groups = [];
    foreach ($containers as $name => $container) {
      $cpuId = $container['charts']['cgroup.cpu'][0] ?? '';
      $memId = $container['charts']['cgroup.mem_usage'][0] ?? '';
      $limitId = $container['charts']['cgroup.mem_usage_limit'][0] ?? '';
      $ioId = $container['charts']['cgroup.io'][0] ?? '';
      $pidId = $container['charts']['cgroup.pids_current'][0] ?? '';
      $utilId = $container['charts']['cgroup.mem_utilization'][0] ?? '';
      $metrics = [
        $this->metric('docker.' . $name . '.image', '镜像', (string) $container['image'], 'ok', '', $cpuId, 0, ''),
        $this->metric('docker.' . $name . '.state', '状态', $this->containerState($samples, $cpuId), $cpuId !== '' && isset($samples[$cpuId]) ? 'ok' : 'unavailable', '', $cpuId, 0, '没有容器状态采集器。这里只表示 cgroup 指标是否还在更新。'),
        $this->sumMetric('docker.' . $name . '.cpu', 'CPU', $cpuId, $samples[$cpuId] ?? NULL, 'percent'),
        $this->dimensionMetric('docker.' . $name . '.ram', '内存', $memId, $samples[$memId] ?? NULL, 'ram', 'mib'),
        $this->dimensionMetric('docker.' . $name . '.swap', '容器 Swap', $memId, $samples[$memId] ?? NULL, 'swap', 'mib'),
        $this->dimensionMetric('docker.' . $name . '.limit_used', '限制内已用', $limitId, $samples[$limitId] ?? NULL, 'used', 'mib'),
        $this->dimensionMetric('docker.' . $name . '.limit_avail', '限制内可用', $limitId, $samples[$limitId] ?? NULL, 'available', 'mib'),
        $this->dimensionMetric('docker.' . $name . '.mem_pct', '内存使用率', $utilId, $samples[$utilId] ?? NULL, 'utilization', 'percent'),
        $this->dimensionMetric('docker.' . $name . '.read', '磁盘读', $ioId, $samples[$ioId] ?? NULL, 'read', 'kib_rate'),
        $this->dimensionMetric('docker.' . $name . '.write', '磁盘写', $ioId, $samples[$ioId] ?? NULL, 'write', 'kib_rate'),
        $this->dimensionMetric('docker.' . $name . '.pids', '进程数', $pidId, $samples[$pidId] ?? NULL, 'pids', 'number'),
        $this->absent('docker.' . $name . '.uptime', '容器运行时间', 'cgroup 图表没有运行时间维度。'),
      ];
      $rx = 0.0;
      $tx = 0.0;
      $seenNet = FALSE;
      foreach ($container['charts']['cgroup.net_net'] ?? [] as $netId) {
        $values = $samples[$netId]['values'] ?? NULL;
        if (!is_array($values)) {
          continue;
        }
        if (isset($values['received'])) {
          $seenNet = TRUE;
          $rx += abs((float) $values['received']);
        }
        if (isset($values['sent'])) {
          $seenNet = TRUE;
          $tx += abs((float) $values['sent']);
        }
      }
      if ($seenNet) {
        $metrics[] = $this->metric('docker.' . $name . '.rx', '网络接收', OpsFormat::rate($this->kilobits($rx)), 'ok', 'B/s', '', 0, '容器网卡速率，不并入宿主机 ens5。');
        $metrics[] = $this->metric('docker.' . $name . '.tx', '网络发送', OpsFormat::rate($this->kilobits($tx)), 'ok', 'B/s', '', 0, '');
      }
      else {
        $metrics[] = $this->absent('docker.' . $name . '.net', '容器网络', '没有这个容器的 cgroup 网卡图。');
      }
      $image = strtolower((string) $container['image'] . ' ' . $name);
      if (str_contains($image, 'matomo') || str_contains($image, 'mariadb')) {
        $metrics[] = $this->metric('docker.' . $name . '.note', '识别', 'Matomo 相关容器', 'ok', '', '', 0, '与宿主机 MariaDB 采集器和宿主机内存分开显示，不要相加。');
      }
      $groups[] = ['title' => $name, 'metrics' => $metrics];
    }
    return $groups;
  }

  /**
   * @param array<string, mixed> $context
   *   Collector context.
   *
   * @return list<array<string, mixed>>
   *   Groups.
   */
  private function collectors(array $charts, array $samples, array $context): array {
    $metrics = [];
    foreach ($this->idsByContextPrefix($charts, 'netdata.plugin_data_collection') as $id) {
      $sample = $samples[$id] ?? NULL;
      if (!is_array($sample) || !is_array($sample['values'] ?? NULL)) {
        $metrics[] = $this->fromSample('collector.' . $id, (string) ($charts[$id]['title'] ?? $id), $id, $sample, NULL, 'number');
        continue;
      }
      foreach ($sample['values'] as $dimension => $value) {
        $metrics[] = $this->dimensionMetric('collector.' . $id . '.' . $dimension, (string) $dimension, $id, $sample, (string) $dimension, 'number');
      }
    }
    $expected = [
      ['nginx stub_status', $this->idsByContextPrefix($charts, 'nginx.') !== []],
      ['PHP-FPM status', $this->idsByContextPrefix($charts, 'phpfpm.') !== [] || $this->idsByContextPrefix($charts, 'php_fpm.') !== []],
      ['MariaDB / MySQL', $this->prefixIds($charts, ['mysql_']) !== []],
      ['Redis', $this->prefixIds($charts, ['redis_']) !== []],
      ['Docker cgroup', $this->containers($charts) !== []],
      ['CPU 频率', $this->hasIdFragment($charts, 'cpufreq')],
      ['温度传感器', $this->hasContextPrefix($charts, 'sensors.') || $this->hasIdFragment($charts, 'temperature')],
      ['宿主机压力', $this->hasContextPrefix($charts, 'system.pressure')],
    ];
    foreach ($expected as [$label, $present]) {
      $metrics[] = $present
        ? $this->metric('expect.' . $label, (string) $label, '已发现图表', 'ok', '', '', 0, '')
        : $this->absent('expect.' . $label, (string) $label, '目录里没有对应图表。');
    }
    $stats = is_array($context['stats'] ?? NULL) ? $context['stats'] : [];
    $calls = (int) ($stats['calls'] ?? 0);
    $fails = (int) ($stats['fails'] ?? 0);
    $metrics[] = $this->metric('api.calls', '已记录 API 次数', (string) $calls, 'ok', '', '', 0, '');
    $metrics[] = $this->metric('api.fails', '已记录失败次数', (string) $fails, 'ok', '', '', 0, $calls > 0 ? ('失败率 ' . OpsFormat::percent($fails / max(1, $calls) * 100)) : '');
    $metrics[] = $this->metric('api.ms', '累计耗时', (int) ($stats['ms'] ?? 0) . ' ms', 'ok', '', '', 0, '只统计这次进程里通过监控读取器发出的 Netdata 请求。');
    return [['title' => '采集器', 'metrics' => array_slice($metrics, 0, 80)]];
  }

  /**
   * @param array<string, mixed> $context
   *   Alarm payload from the API.
   *
   * @return list<array<string, string>>
   *   Alarm rows.
   */
  private function alarms(array $context): array {
    $rows = [];
    $this->walkAlarms($context['alarm_payload'] ?? [], $rows);
    return $rows;
  }

  /**
   * @param mixed $node
   *   Alarm tree.
   * @param list<array<string, string>> $rows
   *   Collected rows.
   */
  private function walkAlarms(mixed $node, array &$rows): void {
    if (!is_array($node)) {
      return;
    }
    $status = $node['status'] ?? $node['last_status'] ?? NULL;
    $name = $node['name'] ?? $node['alarm'] ?? NULL;
    if (is_string($status) && (is_string($name) || isset($node['chart']))) {
      $rows[] = [
        'name' => (string) ($name ?: ($node['chart'] ?? '告警')),
        'level' => $this->alarmLevel((string) $status),
        'when' => $this->alarmTime($node),
        'state' => (string) $status,
        'chart' => (string) ($node['chart'] ?? ''),
        'info' => (string) ($node['info'] ?? $node['summary'] ?? ''),
      ];
      return;
    }
    foreach ($node as $child) {
      if (is_array($child)) {
        $this->walkAlarms($child, $rows);
      }
    }
  }

  /**
   * @param array<string, mixed> $alarm
   *   Alarm entry.
   */
  private function alarmTime(array $alarm): string {
    foreach (['last_status_change', 'last_updated', 'when', 'timestamp'] as $key) {
      if (isset($alarm[$key]) && is_numeric($alarm[$key]) && (int) $alarm[$key] > 0) {
        return $this->dateFormatter->format((int) $alarm[$key], 'custom', 'Y-m-d H:i:s');
      }
    }
    return '未知';
  }

  private function alarmLevel(string $status): string {
    $status = strtoupper($status);
    return match (TRUE) {
      str_contains($status, 'CRIT') => '严重',
      str_contains($status, 'WARN') => '警告',
      str_contains($status, 'CLEAR'), str_contains($status, 'NORMAL'), str_contains($status, 'OK'), str_contains($status, 'RECOVER') => '正常',
      default => '未知',
    };
  }

  /**
   * @param list<array<string, mixed>> $groups
   *   Groups.
   * @param array<string, mixed> $context
   *   Context.
   *
   * @return array<string, mixed>
   *   Section shell.
   */
  private function shell(string $section, array $groups, array $context): array {
    $any = FALSE;
    $failed = FALSE;
    foreach ($groups as $group) {
      foreach ($group['metrics'] ?? [] as $metric) {
        if (($metric['status'] ?? '') === 'ok' || ($metric['status'] ?? '') === 'derived') {
          $any = TRUE;
        }
        if (($metric['status'] ?? '') === 'failed') {
          $failed = TRUE;
        }
      }
    }
    return [
      'ok' => $any || $section === 'alarms' || $section === 'browser' || $section === 'collectors',
      'section' => $section,
      'title' => (string) ($context['title'] ?? $section),
      'refresh_seconds' => (int) ($context['refresh'] ?? 10),
      'updated' => (string) ($context['updated'] ?? ''),
      'generated' => (int) ($context['now'] ?? 0),
      'status' => $failed && !$any ? 'failed' : ($any ? 'ok' : 'uncollected'),
      'error' => (string) ($context['error'] ?? ''),
      'notes' => $context['notes'] ?? [],
      'groups' => $groups,
      'alarms' => [],
      'collectors' => [],
      'browser' => [],
      'charts' => [],
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   * @param list<string> $ids
   *   Chart ids.
   *
   * @return array{title: string, metrics: list<array<string, mixed>>}
   *   Generic group.
   */
  private function genericGroup(string $title, array $charts, array $samples, array $ids): array {
    $metrics = [];
    foreach ($ids as $id) {
      if ($id === '' || !isset($charts[$id])) {
        continue;
      }
      $sample = $samples[$id] ?? ['status' => 'missing', 'values' => NULL, 'sampled' => 0, 'error' => ''];
      $kind = $this->kindFromUnits((string) ($charts[$id]['units'] ?? ''));
      $values = is_array($sample['values'] ?? NULL) ? $sample['values'] : [];
      if ($values === []) {
        $metrics[] = $this->fromSample($id, (string) ($charts[$id]['title'] ?? $id), $id, $sample, NULL, $kind);
        continue;
      }
      foreach ($values as $dimension => $value) {
        if (count($metrics) >= 40) {
          break 2;
        }
        $metrics[] = $this->dimensionMetric($id . '.' . $dimension, (string) ($charts[$id]['family'] ?? $id) . ' ' . $dimension, $id, $sample, (string) $dimension, $kind);
      }
    }
    if ($metrics === []) {
      $metrics[] = $this->absent($title . '.none', $title, '这次没有读到图表。');
    }
    return ['title' => $title, 'metrics' => $metrics];
  }

  /**
   * @param list<string> $dimensions
   *   Candidate dimension names.
   * @param array<string, mixed>|null $sample
   *   Sample.
   */
  private function dimensionMetricAny(string $key, string $label, string $chart, ?array $sample, array $dimensions, string $kind): array {
    foreach ($dimensions as $dimension) {
      if (is_array($sample) && is_array($sample['values'] ?? NULL) && array_key_exists($dimension, $sample['values'])) {
        return $this->dimensionMetric($key, $label, $chart, $sample, $dimension, $kind);
      }
    }
    return $this->dimensionMetric($key, $label, $chart, $sample, $dimensions[0] ?? '', $kind);
  }

  /**
   * @param array<string, mixed>|null $sample
   *   Sample.
   */
  private function dimensionMetric(string $key, string $label, string $chart, ?array $sample, string $dimension, string $kind): array {
    if ($chart === '' || $sample === NULL) {
      return $this->absent($key, $label, '没有 ' . ($chart !== '' ? $chart : $label) . ' 图表。');
    }
    if (($sample['status'] ?? '') !== 'ok' || !is_array($sample['values'] ?? NULL) || !array_key_exists($dimension, $sample['values'])) {
      $missingDimension = ($sample['status'] ?? '') === 'ok';
      return $this->fromSample($key, $label, $chart, $missingDimension ? ['status' => 'missing', 'error' => '', 'sampled' => 0] : $sample, NULL, $kind);
    }
    $value = (float) $sample['values'][$dimension];
    $previous = is_array($sample['previous'] ?? NULL) && isset($sample['previous'][$dimension]) ? (float) $sample['previous'][$dimension] : NULL;
    return $this->metric($key, $label, $this->format($kind, $value), 'ok', '', $chart, (int) ($sample['sampled'] ?? 0), '', $value, $previous);
  }

  /**
   * @param array<string, mixed>|null $sample
   *   Sample.
   */
  private function sumMetric(string $key, string $label, string $chart, ?array $sample, string $kind): array {
    if ($chart === '' || !is_array($sample) || ($sample['status'] ?? '') !== 'ok' || !is_array($sample['values'] ?? NULL)) {
      return $this->fromSample($key, $label, $chart, $sample, NULL, $kind);
    }
    $value = array_sum($sample['values']);
    $previous = is_array($sample['previous'] ?? NULL) ? array_sum($sample['previous']) : NULL;
    return $this->metric($key, $label, $this->format($kind, $value), 'ok', '', $chart, (int) $sample['sampled'], '', $value, $previous);
  }

  /**
   * @param array<string, mixed>|null $sample
   *   Sample.
   */
  private function fromSample(string $key, string $label, string $chart, ?array $sample, ?float $value, string $kind): array {
    $status = (string) ($sample['status'] ?? 'missing');
    $mapped = match ($status) {
      'failed' => 'failed',
      'empty' => 'unavailable',
      'ok' => 'ok',
      default => 'uncollected',
    };
    $text = match ($mapped) {
      'failed' => '采集失败',
      'unavailable' => '暂不可用',
      'ok' => $this->format($kind, $value),
      default => '未采集',
    };
    return $this->metric($key, $label, $text, $mapped, '', $chart, (int) ($sample['sampled'] ?? 0), (string) ($sample['error'] ?? ''));
  }

  private function absent(string $key, string $label, string $reason): array {
    return $this->metric($key, $label, '未采集', 'uncollected', '', '', 0, $reason);
  }

  private function metric(string $key, string $label, string $value, string $status, string $unit, string $chart, int $sampled, string $note = '', ?float $numeric = NULL, ?float $previous = NULL): array {
    $trend = 'unknown';
    if ($status === 'ok' && $numeric !== NULL && $previous !== NULL) {
      $delta = $numeric - $previous;
      $band = max(0.05, abs($numeric) * 0.01);
      $trend = abs($delta) <= $band ? 'flat' : ($delta > 0 ? 'up' : 'down');
    }
    if ($status === 'ok' && $sampled > 0 && $this->now > 0 && ($this->now - $sampled) > 30) {
      $status = 'stale';
    }
    $labels = [
      'ok' => '正常',
      'derived' => '推算',
      'uncollected' => '未采集',
      'unavailable' => '暂不可用',
      'failed' => '采集失败',
      'stale' => '已过期',
    ];
    $trends = ['up' => '上升', 'down' => '下降', 'flat' => '持平', 'unknown' => ''];
    return [
      'key' => preg_replace('/[^A-Za-z0-9_.:-]/', '_', $key) ?: $key,
      'label' => $label,
      'value' => $value,
      'unit' => $unit,
      'status' => $status,
      'status_label' => $labels[$status] ?? '未知',
      'trend' => $trend,
      'trend_label' => $trends[$trend] ?? '',
      'sampled' => $sampled > 0 ? $this->dateFormatter->format($sampled, 'custom', 'H:i:s') : '',
      'chart' => $chart,
      'note' => $note,
    ];
  }

  private function format(string $kind, ?float $value): string {
    if ($value === NULL || !is_finite($value)) {
      return '暂不可用';
    }
    return match ($kind) {
      'percent' => OpsFormat::percent($value),
      'mib' => OpsFormat::bytes($this->mib($value)),
      'gib' => OpsFormat::bytes($this->gib($value)),
      'kib_rate' => OpsFormat::rate(abs($value) * 1024),
      'kilobits' => OpsFormat::rate($this->kilobits($value)),
      'bytes' => OpsFormat::bytes($value),
      'duration' => OpsFormat::duration((int) round($value)),
      default => OpsFormat::number($value, abs($value) >= 100 ? 0 : 2),
    };
  }

  private function unitLabel(string $kind): string {
    return match ($kind) {
      'percent' => '%',
      'kib_rate', 'kilobits' => 'B/s',
      'duration' => 's',
      default => '',
    };
  }

  private function kindFromUnits(string $units): string {
    $units = strtolower($units);
    return match (TRUE) {
      str_contains($units, 'percent'), $units === '%' => 'percent',
      $units === 'mib' => 'mib',
      $units === 'gib' => 'gib',
      str_contains($units, 'kib/s') => 'kib_rate',
      str_contains($units, 'kilobit') => 'kilobits',
      $units === 'bytes' => 'bytes',
      $units === 'seconds' => 'duration',
      default => 'number',
    };
  }

  /**
   * @param array<string, mixed>|null $sample
   *   Sample.
   */
  private function sampleText(?array $sample, bool $exists): string {
    if (!$exists) {
      return '未采集';
    }
    return match ($sample['status'] ?? 'missing') {
      'failed' => '采集失败',
      'empty' => '暂不可用',
      default => '暂不可用',
    };
  }

  /**
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   */
  private function pairValue(array $samples, string $chart, string $dimension, string $kind): string {
    if (!array_key_exists($chart, $samples)) {
      return '未采集';
    }
    $sample = $samples[$chart];
    if (($sample['status'] ?? '') !== 'ok' || !isset($sample['values'][$dimension])) {
      return ($sample['status'] ?? '') === 'failed' ? '采集失败' : '暂不可用';
    }
    return $this->format($kind, abs((float) $sample['values'][$dimension]));
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   */
  private function ifacePair(array $charts, array $samples, string $chart, string $left, string $right, string $suffix): string {
    if (!isset($charts[$chart])) {
      return '未采集';
    }
    $values = $samples[$chart]['values'] ?? NULL;
    if (!is_array($values)) {
      return ($samples[$chart]['status'] ?? '') === 'failed' ? '采集失败' : '暂不可用';
    }
    $a = isset($values[$left]) ? OpsFormat::number(abs((float) $values[$left]), 1) : '未采集';
    $b = isset($values[$right]) ? OpsFormat::number(abs((float) $values[$right]), 1) : '未采集';
    return '↓ ' . $a . ' · ↑ ' . $b . ' ' . $suffix;
  }

  /**
   * @param array<string, mixed>|null $sample
   *   Operstate sample.
   */
  private function operstate(?array $sample): string {
    $flags = is_array($sample) ? ($sample['values'] ?? NULL) : NULL;
    if (!is_array($flags)) {
      return '暂不可用';
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

  private function ifaceKind(string $name): string {
    return preg_match('/^(lo|docker\\d*|br-|veth|virbr|tun|tap|dummy|cni|flannel)/', $name) ? '虚拟' : '物理';
  }

  /**
   * @param array<string, array<string, mixed>> $samples
   *   Samples.
   */
  private function containerState(array $samples, string $chart): string {
    if ($chart === '' || !isset($samples[$chart])) {
      return '暂不可用';
    }
    return ($samples[$chart]['status'] ?? '') === 'ok' ? '指标更新中' : '暂不可用';
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   *
   * @return array<string, array{image: string, charts: array<string, list<string>>}>
   *   Containers that have a real image label.
   */
  private function containers(array $charts): array {
    $groups = [];
    foreach ($charts as $id => $chart) {
      if (!str_starts_with((string) $id, 'cgroup_')) {
        continue;
      }
      $image = (string) ($chart['labels']['image'] ?? '');
      $name = (string) ($chart['labels']['cgroup_name'] ?? '');
      if ($image === '' || $image === '[none]' || $name === '') {
        continue;
      }
      $groups[$name]['image'] = $image;
      $groups[$name]['charts'][(string) $chart['context']][] = (string) $id;
    }
    return $groups;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   *
   * @return list<string>
   *   Docker summary charts.
   */
  private function dockerIds(array $charts): array {
    $wanted = [
      'cgroup.cpu', 'cgroup.mem_usage', 'cgroup.mem_usage_limit', 'cgroup.mem_utilization',
      'cgroup.io', 'cgroup.net_net', 'cgroup.pids_current',
    ];
    $ids = [];
    foreach ($this->containers($charts) as $container) {
      foreach ($wanted as $context) {
        foreach ($container['charts'][$context] ?? [] as $id) {
          $ids[] = $id;
        }
      }
    }
    return $ids;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param list<string> $fixed
   *   Exact ids.
   * @param list<string> $prefixes
   *   Id prefixes.
   * @param list<string> $contexts
   *   Contexts.
   *
   * @return list<string>
   *   Ids that exist.
   */
  private function mergeIds(array $charts, array $fixed = [], array $prefixes = [], array $contexts = []): array {
    $ids = [];
    foreach ($fixed as $id) {
      if (isset($charts[$id])) {
        $ids[] = $id;
      }
    }
    foreach ($charts as $id => $chart) {
      foreach ($prefixes as $prefix) {
        if (str_starts_with((string) $id, $prefix)) {
          $ids[] = (string) $id;
        }
      }
      if (in_array((string) ($chart['context'] ?? ''), $contexts, TRUE)) {
        $ids[] = (string) $id;
      }
    }
    return $ids;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param list<string> $contexts
   *   Contexts.
   *
   * @return list<string>
   *   Matching ids.
   */
  private function idsByContexts(array $charts, array $contexts): array {
    return $this->mergeIds($charts, [], [], $contexts);
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   *
   * @return list<string>
   *   Ids for one context.
   */
  private function idsByContext(array $charts, string $context): array {
    return $this->idsByContexts($charts, [$context]);
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   * @param list<string> $prefixes
   *   Id prefixes.
   *
   * @return list<string>
   *   Matching ids.
   */
  private function prefixIds(array $charts, array $prefixes): array {
    return $this->mergeIds($charts, [], $prefixes);
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   *
   * @return list<string>
   *   Ids whose context starts with the prefix.
   */
  private function idsByContextPrefix(array $charts, string $prefix): array {
    $ids = [];
    foreach ($charts as $id => $chart) {
      if (str_starts_with((string) ($chart['context'] ?? ''), $prefix)) {
        $ids[] = (string) $id;
      }
    }
    return $ids;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   */
  private function firstContext(array $charts, string $context): string {
    $ids = $this->idsByContext($charts, $context);
    return $ids[0] ?? '';
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   */
  private function firstByContext(array $charts, string $context): string {
    return $this->firstContext($charts, $context);
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   */
  private function firstPhysicalNet(array $charts): string {
    foreach ($this->idsByContext($charts, 'net.net') as $id) {
      $name = (string) ($charts[$id]['family'] ?? '');
      if ($this->ifaceKind($name) === '物理') {
        return $id;
      }
    }
    return '';
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   */
  private function hasContext(array $charts, string $fragment): bool {
    foreach ($charts as $chart) {
      if (str_contains((string) ($chart['context'] ?? ''), $fragment)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   */
  private function hasContextPrefix(array $charts, string $prefix): bool {
    return $this->idsByContextPrefix($charts, $prefix) !== [];
  }

  /**
   * @param array<string, array<string, mixed>> $charts
   *   Catalog.
   */
  private function hasIdFragment(array $charts, string $fragment): bool {
    foreach ($charts as $id => $chart) {
      if (str_contains((string) $id, $fragment) || str_contains((string) ($chart['context'] ?? ''), $fragment)) {
        return TRUE;
      }
    }
    return FALSE;
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

  private function kilobits(int|float|null $value): ?float {
    if ($value === NULL || !is_finite((float) $value)) {
      return NULL;
    }
    return abs((float) $value) * 1000 / 8;
  }

}
