<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\xedc_core\Ops\ServerMonitor;
use Drupal\xedc_core\Ops\Traffic\TrafficReport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Server monitoring page. The browser never talks to Netdata directly.
 */
class MonitorController extends ControllerBase {

  public function __construct(
    protected ServerMonitor $monitor,
    protected TrafficReport $traffic,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.monitor'),
      $container->get('xedc_core.traffic_report'),
    );
  }

  /**
   * Monitoring page.
   */
  public function page(Request $request): array {
    $refresh = $request->query->get('refresh') === '1';
    $section = (string) $request->query->get('section', 'overview');
    if (!isset(ServerMonitor::SECTIONS[$section])) {
      $section = 'overview';
    }
    $range = $this->monitor->normalizeRange((string) $request->query->get('range', '1h'));
    $snapshot = $this->monitor->snapshot($refresh);
    $snapshot['section'] = $section;
    $snapshot['range'] = $range;
    $snapshot['nav'] = $this->monitor->navigation();
    if ($section !== 'overview') {
      $snapshot['detail'] = $this->withTraffic($this->monitor->section($section, $range, $refresh, TRUE, [
        'q' => (string) $request->query->get('q', ''),
        'category' => (string) $request->query->get('category', ''),
        'page' => (int) $request->query->get('page', 0),
      ]), $request, $section);
    }
    return [
      '#theme' => 'xedc_core_monitor',
      '#snapshot' => $snapshot,
      '#attached' => [
        'library' => ['xedc_core/ops'],
        'drupalSettings' => [
          'xedcMonitor' => [
            'dataUrl' => Url::fromRoute('xedc_core.monitor_data')->toString(),
            'section' => $section,
            'range' => $range,
            'refresh' => (int) (($snapshot['detail']['refresh_seconds'] ?? 0) ?: ($snapshot['refresh_seconds'] ?? 10)),
            'detailCharts' => $snapshot['detail']['charts'] ?? [],
            'trafficChart' => $snapshot['detail']['traffic']['chart'] ?? NULL,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => [
          'user.permissions',
          'url.query_args:section',
          'url.query_args:range',
          'url.query_args:refresh',
          'url.query_args:q',
          'url.query_args:category',
          'url.query_args:page',
          'url.query_args:nic',
          'url.query_args:span',
          'url.query_args:day',
        ],
      ],
    ];
  }

  /**
   * JSON for live sections and history. The browser never receives a Netdata URL.
   */
  public function data(Request $request): JsonResponse {
    $refresh = $request->query->get('refresh') === '1';
    $range = (string) $request->query->get('range', '1h');
    $chart = (string) $request->query->get('chart', '');
    if ($chart !== '') {
      $payload = $this->monitor->chartHistory($chart, $range, $refresh);
    }
    elseif ($request->query->has('section')) {
      $section = (string) $request->query->get('section');
      $payload = $this->withTraffic($this->monitor->section($section, $range, $refresh, $request->query->get('series') === '1', [
        'q' => (string) $request->query->get('q', ''),
        'category' => (string) $request->query->get('category', ''),
        'page' => (int) $request->query->get('page', 0),
      ]), $request, $section);
    }
    else {
      $payload = $this->monitor->history($range, $refresh);
    }
    $response = new JsonResponse($payload);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Adds stored traffic totals for the network section. This does not sample.
   *
   * @param array<string, mixed> $payload
   *   Section payload.
   *
   * @return array<string, mixed>
   *   Payload with a traffic key when the section is network.
   */
  private function withTraffic(array $payload, Request $request, string $section): array {
    if ($section !== 'network') {
      return $payload;
    }
    $span = (int) $request->query->get('span', 7);
    $requested = (string) $request->query->get('nic', '');
    $day = (string) $request->query->get('day', '');
    $report = $this->traffic->report($requested, $span, $day);
    foreach ($payload['groups'] ?? [] as $group) {
      if (!is_array($group)) {
        continue;
      }
      foreach ($group['tables'] ?? [] as $table) {
        if (!is_array($table)) {
          continue;
        }
        foreach ($table['rows'] ?? [] as $row) {
          if (is_array($row) && ($row[0] ?? '') === $report['iface']) {
            $report['rx_rate'] = (string) ($row[3] ?? '未采集');
            $report['tx_rate'] = (string) ($row[4] ?? '未采集');
            $report['rate_source'] = 'Netdata';
          }
        }
      }
    }
    $payload['traffic'] = $report;
    return $payload;
  }

}
