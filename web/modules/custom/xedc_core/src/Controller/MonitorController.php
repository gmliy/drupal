<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\xedc_core\Ops\ServerMonitor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Server monitoring page. The browser never talks to Netdata directly.
 */
class MonitorController extends ControllerBase {

  public function __construct(
    protected ServerMonitor $monitor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.monitor'));
  }

  /**
   * Monitoring page.
   */
  public function page(Request $request): array {
    $refresh = $request->query->get('refresh') === '1';
    return [
      '#theme' => 'xedc_core_monitor',
      '#snapshot' => $this->monitor->snapshot($refresh),
      '#attached' => [
        'library' => ['xedc_core/ops'],
        'drupalSettings' => [
          'xedcMonitor' => [
            'dataUrl' => Url::fromRoute('xedc_core.monitor_data')->toString(),
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * History JSON for the charts.
   */
  public function data(Request $request): JsonResponse {
    $range = (string) $request->query->get('range', '1h');
    $payload = $this->monitor->history($range, $request->query->get('refresh') === '1');
    $response = new JsonResponse($payload);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
