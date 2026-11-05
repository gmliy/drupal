<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\xedc_core\Engagement\MembershipManager;
use Drupal\xedc_core\Engagement\VipCodeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unified VIP administration hub under 用户管理.
 */
final class VipHubController extends ControllerBase {

  public function __construct(
    protected MembershipManager $membership,
    protected VipCodeManager $codes,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.membership'),
      $container->get('xedc_core.vip_codes'),
      $container->get('date.formatter'),
    );
  }

  public function hub(Request $request): array|RedirectResponse {
    $tab = (string) $request->query->get('tab', 'overview');
    $tabs = $this->availableTabs();
    if (!isset($tabs[$tab])) {
      $tab = array_key_first($tabs) ?: 'overview';
    }

    $nav = [
      '#theme' => 'item_list',
      '#items' => [],
      '#attributes' => ['class' => ['xedc-vip-hub__tabs']],
    ];
    foreach ($tabs as $key => $meta) {
      $url = Url::fromRoute('xedc_core.vip_hub', [], ['query' => ['tab' => $key]]);
      $nav['#items'][] = [
        '#markup' => Link::fromTextAndUrl($meta['title'], $url)->toString(),
        '#wrapper_attributes' => [
          'class' => array_filter([$key === $tab ? 'is-active' : '']),
        ],
      ];
    }

    $stats = $this->buildHeaderStats();
    $content = match ($tab) {
      'grant' => $this->buildGrant(),
      'codes' => $this->buildCodes($request),
      'reminders' => $this->buildReminders(),
      'records' => $this->buildRecords(),
      default => $this->buildOverview(),
    };

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-hub', 'xedc-saas-admin']],
      'header' => [
        '#markup' => '<div class="xedc-vip-hub__header"><h1>' . $this->t('VIP 管理') . '</h1><p class="xedc-vip-hub__lead">' . $this->t('统一管理会员 VIP 权益、开通续期、兑换卡密及到期提醒。') . '</p></div>',
      ],
      'summary' => $stats,
      'tabs' => $nav,
      'panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-vip-hub__panel']],
        'content' => $content,
      ],
      '#attached' => [
        'library' => [
          'xedc_core/vip_hub',
          // Grant/list reuse engagement form & table chrome.
          'xedc_admin/engagement',
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Compatibility redirect from legacy VIP pages into the hub.
   */
  public function redirectTab(string $tab): RedirectResponse {
    return $this->redirect('xedc_core.vip_hub', [], ['query' => ['tab' => $tab]]);
  }

  /**
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, permission: string}>
   */
  protected function availableTabs(): array {
    $all = [
      'overview' => ['title' => $this->t('概览'), 'permission' => 'administer xedc vip'],
      'grant' => ['title' => $this->t('开通与续期'), 'permission' => 'administer xedc vip'],
      'codes' => ['title' => $this->t('卡密管理'), 'permission' => 'administer xedc vip codes'],
      'reminders' => ['title' => $this->t('提醒设置'), 'permission' => 'administer xedc vip notifications'],
      'records' => ['title' => $this->t('通知记录'), 'permission' => 'view xedc notification records'],
    ];
    $out = [];
    foreach ($all as $key => $meta) {
      if ($this->currentUser()->hasPermission($meta['permission'])) {
        $out[$key] = $meta;
      }
    }
    if (!isset($out['records']) && $this->currentUser()->hasPermission('administer xedc vip notifications')) {
      $out['records'] = $all['records'];
    }
    return $out;
  }

  protected function buildHeaderStats(): array {
    $db = \Drupal::database();
    $now = \Drupal::time()->getRequestTime();
    $active = (int) $db->select('xedc_vip', 'v')->condition('status', 'active')->condition('expires', $now, '>')->countQuery()->execute()->fetchField();
    $total = (int) $db->select('xedc_vip', 'v')->countQuery()->execute()->fetchField();
    $recentGrant = (int) $db->select('xedc_vip_log', 'l')->condition('action', 'grant')->condition('created', $now - 7 * 86400, '>=')->countQuery()->execute()->fetchField();
    $soon = (int) $db->select('xedc_vip', 'v')->condition('status', 'active')->condition('expires', $now, '>')->condition('expires', $now + 7 * 86400, '<=')->countQuery()->execute()->fetchField();
    $cards = [
      [$this->t('当前有效 VIP'), $active],
      [$this->t('累计 VIP 会员'), $total],
      [$this->t('近 7 日新增'), $recentGrant],
      [$this->t('近 7 日到期'), $soon],
    ];
    $items = [];
    foreach ($cards as [$label, $value]) {
      $items[] = '<div class="xedc-vip-hub__stat"><strong>' . (int) $value . '</strong><span>' . $label . '</span></div>';
    }
    return [
      '#markup' => '<div class="xedc-vip-hub__stats xedc-vip-hub__stats--header">' . implode('', $items) . '</div>',
    ];
  }

  protected function buildOverview(): array {
    $db = \Drupal::database();
    $now = \Drupal::time()->getRequestTime();

    $recentRows = [];
    if ($db->schema()->tableExists('xedc_vip_log')) {
      $q = $db->select('xedc_vip_log', 'l');
      $q->fields('l', ['uid', 'action', 'created', 'note', 'actor']);
      $q->condition('action', ['grant', 'extend'], 'IN');
      $q->orderBy('created', 'DESC');
      $q->range(0, 8);
      foreach ($q->execute() as $row) {
        $name = $this->accountName((int) $row->uid);
        $recentRows[] = [
          $name,
          (string) $row->uid,
          (string) $row->action,
          $this->dateFormatter->format((int) $row->created, 'short'),
          (string) ($row->note ?? ''),
        ];
      }
    }

    $soonRows = [];
    $soon = $db->select('xedc_vip', 'v');
    $soon->fields('v', ['uid', 'expires', 'starts']);
    $soon->condition('status', 'active');
    $soon->condition('expires', $now, '>');
    $soon->condition('expires', $now + 7 * 86400, '<=');
    $soon->orderBy('expires', 'ASC');
    $soon->range(0, 8);
    foreach ($soon->execute() as $row) {
      $days = (int) ceil(((int) $row->expires - $now) / 86400);
      $soonRows[] = [
        $this->accountName((int) $row->uid),
        (string) $row->uid,
        $this->dateFormatter->format((int) $row->expires, 'short'),
        (string) $days,
      ];
    }

    $actions = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-hub__actions']],
    ];
    if ($this->currentUser()->hasPermission('administer xedc vip')) {
      $actions['grant'] = [
        '#type' => 'link',
        '#title' => $this->t('手动开通 VIP'),
        '#url' => Url::fromRoute('xedc_core.vip_hub', [], ['query' => ['tab' => 'grant']]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    if ($this->currentUser()->hasPermission('administer xedc vip codes')) {
      $actions['codes'] = [
        '#type' => 'link',
        '#title' => $this->t('生成卡密'),
        '#url' => Url::fromRoute('xedc_core.vip_hub', [], ['query' => ['tab' => 'codes']]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    if ($this->currentUser()->hasPermission('administer xedc vip notifications')) {
      $actions['reminders'] = [
        '#type' => 'link',
        '#title' => $this->t('管理提醒'),
        '#url' => Url::fromRoute('xedc_core.vip_hub', [], ['query' => ['tab' => 'reminders']]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-hub__overview']],
      'actions_title' => ['#markup' => '<h2 class="xedc-vip-hub__section">' . $this->t('常用操作') . '</h2>'],
      'actions' => $actions,
      'recent_title' => ['#markup' => '<h2 class="xedc-vip-hub__section">' . $this->t('最近开通 / 续期') . '</h2>'],
      'recent' => [
        '#type' => 'table',
        '#header' => [
          $this->t('会员'),
          $this->t('UID'),
          $this->t('操作'),
          $this->t('时间'),
          $this->t('备注'),
        ],
        '#rows' => $recentRows,
        '#empty' => $this->t('暂无开通记录。'),
      ],
      'soon_title' => ['#markup' => '<h2 class="xedc-vip-hub__section">' . $this->t('即将到期') . '</h2>'],
      'soon' => [
        '#type' => 'table',
        '#header' => [
          $this->t('会员'),
          $this->t('UID'),
          $this->t('到期时间'),
          $this->t('剩余天数'),
        ],
        '#rows' => $soonRows,
        '#empty' => $this->t('近 7 日没有即将到期的 VIP。'),
      ],
    ];
  }

  protected function buildGrant(): array {
    /** @var \Drupal\xedc_core\Controller\EngagementAdminController $legacy */
    $legacy = \Drupal::classResolver()->getInstanceFromDefinition(EngagementAdminController::class);
    $build = $legacy->vip();
    // Overview already shows VIP stats; grant tab keeps form + member list only.
    unset($build['cards'], $build['lead'], $build['header']);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-hub__grant']],
      'note' => [
        '#markup' => '<p class="xedc-vip-hub__note">' . $this->t('按用户名开通或延长 VIP。已有有效 VIP 时请选择「延长」，避免静默覆盖有效期。开通通过 MembershipManager 写入 xedc_vip。') . '</p>',
      ],
      'legacy' => $build,
    ];
  }

  protected function buildCodes(Request $request): array {
    /** @var \Drupal\xedc_core\Controller\VipCodeAdminController $codes */
    $codes = \Drupal::classResolver()->getInstanceFromDefinition(VipCodeAdminController::class);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-hub__codes']],
      'legacy' => $codes->overview($request),
    ];
  }

  protected function buildReminders(): array {
    if (!\Drupal::moduleHandler()->moduleExists('xedc_notify')) {
      return ['#markup' => '<p>' . $this->t('通知模块未启用。') . '</p>'];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-hub__reminders']],
      'intro' => [
        '#markup' => '<p class="xedc-vip-hub__note">' . $this->t('以下配置写入 xedc_notify.settings，并驱动现有 VIP 到期提醒。') . '</p>',
      ],
      'form' => \Drupal::formBuilder()->getForm('Drupal\xedc_notify\Form\VipNotifySettingsForm'),
    ];
  }

  protected function buildRecords(): array {
    if (!\Drupal::moduleHandler()->moduleExists('xedc_notify')) {
      return ['#markup' => '<p>' . $this->t('通知模块未启用。') . '</p>'];
    }
    /** @var \Drupal\xedc_notify\Controller\AdminNotificationController $admin */
    $admin = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_notify\Controller\AdminNotificationController::class);
    $build = $admin->vipList();
    unset($build['settings']);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-hub__records']],
      'legacy' => $build,
    ];
  }

  protected function accountName(int $uid): string {
    if ($uid < 1) {
      return '-';
    }
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    return $account ? $account->getAccountName() : (string) $uid;
  }

}
