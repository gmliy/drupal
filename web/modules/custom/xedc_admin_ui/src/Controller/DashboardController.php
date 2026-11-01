<?php

declare(strict_types=1);

namespace Drupal\xedc_admin_ui\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\xedc_admin_ui\DashboardStats;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * XEDC admin dashboard page.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly DashboardStats $stats,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_admin_ui.dashboard_stats'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('logger.factory')->get('xedc_admin_ui'),
    );
  }

  /**
   * Dashboard page.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function page(): array {
    $account = $this->currentUser();
    $error = FALSE;
    try {
      $payload = $this->stats->get($account);
    }
    catch (\Throwable $exception) {
      $this->logger->error('XEDC dashboard statistics failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      $payload = [
        'date_label' => '',
        'includes_unpublished' => FALSE,
        'content' => NULL,
        'members' => NULL,
        'queue' => NULL,
        'recent' => NULL,
        'variant' => 'error',
        'expires' => $this->time->getRequestTime(),
      ];
      $error = TRUE;
    }

    $recent = NULL;
    if (is_array($payload['recent'] ?? NULL)) {
      $recent = [];
      foreach ($payload['recent'] as $item) {
        $item['changed_label'] = $this->dateFormatter->format((int) $item['changed'], 'custom', 'Y-m-d H:i', 'Asia/Shanghai');
        $recent[] = $item;
      }
    }

    $expires = (int) ($payload['expires'] ?? $this->time->getRequestTime());
    $maxAge = $error ? 0 : max(0, $expires - $this->time->getRequestTime());

    return [
      '#theme' => 'xedc_admin_dashboard',
      '#account_name' => $account->getDisplayName(),
      '#date_label' => (string) ($payload['date_label'] ?? ''),
      '#includes_unpublished' => !empty($payload['includes_unpublished']),
      '#content' => $payload['content'],
      '#members' => $payload['members'],
      '#queue' => $payload['queue'],
      '#recent' => $recent,
      '#operations' => $this->operations($account),
      '#health' => $this->healthLinks($account),
      '#error' => $error,
      '#attached' => [
        'library' => [
          'xedc_admin_ui/dashboard',
        ],
      ],
      '#cache' => [
        'keys' => ['xedc_admin_dashboard', (string) ($payload['variant'] ?? 'missing')],
        'contexts' => ['user', 'user.permissions', 'languages:language_interface'],
        'tags' => [
          'node_list',
          'user_list',
          'config:workflows.workflow.xedc_editorial',
        ],
        'max-age' => $maxAge,
      ],
    ];
  }

  /**
   * @return list<array{label: string, url: string}>
   *   Shortcut links the account may follow.
   */
  private function operations(AccountInterface $account): array {
    $links = [];
    $this->addLink($links, $account->hasPermission('create photo content'), 'node.add', ['node_type' => 'photo'], '新建美图');
    $this->addLink($links, $account->hasPermission('create video content'), 'node.add', ['node_type' => 'video'], '新建视频');
    $this->addLink($links, $account->hasPermission('create article content'), 'node.add', ['node_type' => 'article'], '新建文章');
    $this->addLink($links, $account->hasPermission('administer nodes'), 'xedc_quick.page', [], '快速发布');
    $import = $account->hasPermission('import photo archives') || $account->hasPermission('administer xedc image imports');
    $this->addLink($links, $import, 'xedc_core.import_list', [], '图片导入');
    $this->addLink($links, $account->hasPermission('access content overview'), 'system.admin_content', [], '内容列表');
    $this->addLink($links, $account->hasPermission('view any unpublished content'), 'view.moderated_content.moderated_content', [], '审核队列');
    return $links;
  }

  /**
   * @return list<array{label: string, url: string}>
   *   Report links. These routes only display existing reports.
   */
  private function healthLinks(AccountInterface $account): array {
    $links = [];
    $site = $account->hasPermission('administer site configuration');
    $this->addLink($links, $site, 'system.status', [], '状态报告');
    $this->addLink($links, $site, 'site_audit.report', [], 'Site Audit');
    $this->addLink($links, $account->hasPermission('view audit results'), 'audit.reports', [], 'Audit');
    return $links;
  }

  /**
   * @param list<array{label: string, url: string}> $links
   *   Collected links.
   */
  private function addLink(array &$links, bool $allowed, string $route, array $parameters, string $label): void {
    if (!$allowed) {
      return;
    }
    try {
      $url = Url::fromRoute($route, $parameters);
    }
    catch (\Exception) {
      return;
    }
    if (!$url->access($this->currentUser())) {
      return;
    }
    $links[] = [
      'label' => $label,
      'url' => $url->toString(),
    ];
  }

}
