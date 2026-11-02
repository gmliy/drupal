<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Discovers the local Netdata chart index and classifies it.
 */
class NetdataCatalog {

  private const CACHE_ID = 'xedc_core:monitor:catalog';

  public function __construct(
    protected NetdataClient $client,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, array<string, mixed>>|null
   *   Slim charts keyed by id.
   */
  public function charts(bool $refresh = FALSE): ?array {
    $loaded = $this->load($refresh);
    return $loaded['charts'] ?? NULL;
  }

  /**
   * @return array<string, mixed>
   *   Agent fields that describe storage, not live samples.
   */
  public function meta(bool $refresh = FALSE): array {
    $loaded = $this->load($refresh);
    return is_array($loaded['meta'] ?? NULL) ? $loaded['meta'] : [];
  }

  public function invalidate(): void {
    $this->cache->delete(self::CACHE_ID);
  }

  public function allows(string $id, bool $refresh = FALSE): bool {
    if (!$this->safeId($id)) {
      return FALSE;
    }
    $charts = $this->charts($refresh);
    return is_array($charts) && isset($charts[$id]);
  }

  public function safeId(string $id): bool {
    if ($id === '' || strlen($id) > 180) {
      return FALSE;
    }
    if (!preg_match('/^[A-Za-z0-9_.:\/-]+$/', $id)) {
      return FALSE;
    }
    return !str_contains($id, '..') && !str_contains($id, '//');
  }

  /**
   * @return array{total: int, pages: int, page: int, rows: list<array<string, mixed>>}
   *   One page of chart metadata.
   */
  public function search(string $category, string $query, int $page, int $perPage = 30): array {
    $charts = $this->charts() ?? [];
    $query = mb_substr(trim($query), 0, 80);
    if ($query !== '' && !preg_match('/^[\p{L}\p{N} _.:\/+-]+$/u', $query)) {
      $query = '';
    }
    $category = $this->knownCategory($category);
    $rows = [];
    foreach ($charts as $chart) {
      if ($category !== '' && ($chart['category'] ?? '') !== $category) {
        continue;
      }
      if ($query !== '' && !$this->matches($chart, $query)) {
        continue;
      }
      $rows[] = $chart;
    }
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(0, min($pages - 1, $page));
    return [
      'total' => $total,
      'pages' => $pages,
      'page' => $page,
      'rows' => array_slice($rows, $page * $perPage, $perPage),
    ];
  }

  /**
   * @return array<string, int>
   *   Chart counts by category.
   */
  public function categoryCounts(): array {
    $counts = [];
    foreach ($this->charts() ?? [] as $chart) {
      $category = (string) ($chart['category'] ?? 'other');
      $counts[$category] = ($counts[$category] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
  }

  /**
   * @return array<string, int>
   *   Chart counts by plugin and module.
   */
  public function collectorCounts(): array {
    $counts = [];
    foreach ($this->charts() ?? [] as $chart) {
      $key = trim((string) ($chart['plugin'] ?? '')) . '/' . trim((string) ($chart['module'] ?? ''));
      $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
  }

  /**
   * @return array{charts: array<string, array<string, mixed>>, meta: array<string, mixed>}|null
   *   Cached catalog.
   */
  private function load(bool $refresh): ?array {
    if ($refresh) {
      $this->invalidate();
    }
    $cached = $this->cache->get(self::CACHE_ID);
    if ($cached && is_array($cached->data) && isset($cached->data['charts'])) {
      return $cached->data;
    }
    $response = $this->client->get($this->baseUrl(), '/api/v1/charts');
    if (!$response['ok'] || !is_array($response['data']['charts'] ?? NULL)) {
      return NULL;
    }
    $charts = [];
    $dimensions = 0;
    foreach ($response['data']['charts'] as $id => $chart) {
      if (!is_string($id) || !is_array($chart) || !$this->safeId($id)) {
        continue;
      }
      $dims = [];
      foreach ($chart['dimensions'] ?? [] as $name => $dimension) {
        $label = is_string($name) ? $name : '';
        if ($label === '' && is_array($dimension)) {
          $label = (string) ($dimension['name'] ?? '');
        }
        if ($label !== '') {
          $dims[] = $label;
        }
      }
      $dimensions += count($dims);
      $labels = is_array($chart['chart_labels'] ?? NULL) ? $chart['chart_labels'] : [];
      $slim = [
        'id' => $id,
        'context' => (string) ($chart['context'] ?? ''),
        'family' => (string) ($chart['family'] ?? ''),
        'title' => str_replace('%%', '%', (string) ($chart['title'] ?? $id)),
        'units' => (string) ($chart['units'] ?? ''),
        'plugin' => (string) ($chart['plugin'] ?? ''),
        'module' => (string) ($chart['module'] ?? ''),
        'dimensions' => $dims,
        'first_entry' => (int) ($chart['first_entry'] ?? 0),
        'last_entry' => (int) ($chart['last_entry'] ?? 0),
        'update_every' => (int) ($chart['update_every'] ?? 0),
        'labels' => [
          'image' => (string) ($labels['image'] ?? ''),
          'cgroup_name' => (string) ($labels['cgroup_name'] ?? ''),
        ],
      ];
      $slim['category'] = $this->category($id, $slim);
      $charts[$id] = $slim;
    }
    $payload = [
      'charts' => $charts,
      'meta' => [
        'hostname' => (string) ($response['data']['hostname'] ?? ''),
        'version' => (string) ($response['data']['version'] ?? ''),
        'memory_mode' => (string) ($response['data']['memory_mode'] ?? ''),
        'history' => (int) ($response['data']['history'] ?? 0),
        'update_every' => (int) ($response['data']['update_every'] ?? 0),
        'charts_count' => count($charts),
        'dimensions_count' => $dimensions,
        'alarms_count' => (int) ($response['data']['alarms_count'] ?? 0),
      ],
    ];
    $this->cache->set(self::CACHE_ID, $payload, $this->time->getRequestTime() + 90);
    return $payload;
  }

  /**
   * @param array<string, mixed> $chart
   *   Slim chart.
   */
  private function category(string $id, array $chart): string {
    $context = (string) ($chart['context'] ?? '');
    if (str_starts_with($id, 'user.') || str_starts_with($id, 'usergroup.')) {
      return 'apps';
    }
    if (str_starts_with($context, 'net.') || str_starts_with($id, 'net.') || str_starts_with($id, 'net_') || $context === 'system.net' || str_starts_with($context, 'ipv4.') || str_starts_with($context, 'ipv6.') || str_starts_with($context, 'ip.') || str_starts_with($context, 'netfilter.')) {
      return 'network';
    }
    if (str_starts_with($id, 'mysql_') || str_starts_with($context, 'mysql.') || str_starts_with($id, 'app.mariadb') || str_starts_with($id, 'systemd_mariadb')) {
      return 'mariadb';
    }
    if (str_starts_with($id, 'redis_') || str_starts_with($context, 'redis.') || str_starts_with($id, 'app.redis') || str_starts_with($id, 'systemd_redis')) {
      return 'redis';
    }
    if (str_starts_with($id, 'app.nginx') || str_starts_with($id, 'systemd_nginx') || str_starts_with($context, 'nginx.')) {
      return 'nginx';
    }
    if (str_starts_with($id, 'app.phpfpm') || str_starts_with($id, 'systemd_php') || str_starts_with($context, 'phpfpm.') || str_starts_with($context, 'php_fpm.')) {
      return 'phpfpm';
    }
    if (str_starts_with($id, 'cgroup_')) {
      $image = (string) ($chart['labels']['image'] ?? '');
      return ($image !== '' && $image !== '[none]') ? 'docker' : 'cgroup';
    }
    if (str_starts_with($id, 'app.docker') || str_starts_with($id, 'systemd_docker')) {
      return 'docker';
    }
    if ($context === 'system.cpu' || $context === 'cpu.cpu' || str_starts_with($id, 'cpu.') || in_array($context, ['system.intr', 'system.ctxt', 'system.load', 'system.forks'], TRUE) || str_contains($context, 'cpufreq') || str_contains($id, 'cpufreq')) {
      return 'cpu';
    }
    if ($context === 'system.ram' || str_starts_with($context, 'mem.') || str_starts_with($id, 'mem.')) {
      return 'memory';
    }
    if (str_starts_with($context, 'disk') || str_starts_with($id, 'disk') || in_array($context, ['system.io', 'system.pgpgio'], TRUE)) {
      return 'disk';
    }
    if (str_starts_with($context, 'system.') || str_starts_with($id, 'system.')) {
      return 'system';
    }
    if (str_starts_with($id, 'systemd_')) {
      return 'systemd';
    }
    if (str_starts_with($id, 'app.')) {
      return 'apps';
    }
    if (str_starts_with($id, 'netdata.') || ($chart['plugin'] ?? '') === 'netdata') {
      return 'netdata';
    }
    return 'other';
  }

  private function knownCategory(string $category): string {
    $allowed = ['cpu', 'memory', 'disk', 'network', 'system', 'nginx', 'phpfpm', 'mariadb', 'redis', 'docker', 'apps', 'systemd', 'netdata', 'cgroup', 'other'];
    return in_array($category, $allowed, TRUE) ? $category : '';
  }

  /**
   * @param array<string, mixed> $chart
   *   Slim chart.
   */
  private function matches(array $chart, string $query): bool {
    $haystack = strtolower(implode(' ', [
      (string) ($chart['id'] ?? ''),
      (string) ($chart['title'] ?? ''),
      (string) ($chart['context'] ?? ''),
      (string) ($chart['family'] ?? ''),
      (string) ($chart['plugin'] ?? ''),
      (string) ($chart['module'] ?? ''),
      implode(' ', $chart['dimensions'] ?? []),
    ]));
    return str_contains($haystack, strtolower($query));
  }

  private function baseUrl(): string {
    $configured = (string) $this->configFactory->get('xedc_core.settings')->get('netdata_base_url');
    return $configured !== '' ? $configured : 'http://127.0.0.1:19999';
  }

}
