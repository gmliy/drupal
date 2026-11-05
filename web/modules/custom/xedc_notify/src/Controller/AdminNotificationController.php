<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\xedc_notify\Service\NotificationManager;
use Drupal\xedc_notify\Service\OfficialCampaignManager;
use Drupal\xedc_notify\Service\VipNotifier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin pages for official and VIP notification management.
 */
final class AdminNotificationController extends ControllerBase {

  public function __construct(
    protected OfficialCampaignManager $campaigns,
    protected VipNotifier $vipNotifier,
    protected NotificationManager $notifications,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_notify.official'),
      $container->get('xedc_notify.vip'),
      $container->get('xedc_notify.notifications'),
      $container->get('date.formatter'),
    );
  }

  public function overview(): array {
    $campaigns = $this->campaigns->listCampaigns(200);
    $total = count($campaigns);
    $sent = 0;
    $pending = 0;
    $failed = 0;
    foreach ($campaigns as $row) {
      $status = (string) $row->status;
      if ($status === 'sent') {
        $sent++;
      }
      elseif (in_array($status, ['draft', 'queued', 'sending'], TRUE)) {
        $pending++;
      }
      elseif (in_array($status, ['failed', 'partial'], TRUE)) {
        $failed++;
      }
    }
    $stats = [
      [$this->t('官方任务总数'), $total],
      [$this->t('已发送'), $sent],
      [$this->t('待处理'), $pending],
      [$this->t('失败/部分失败'), $failed],
    ];
    $statHtml = '';
    foreach ($stats as [$label, $value]) {
      $statHtml .= '<div class="xedc-notify-admin__stat"><strong>' . (int) $value . '</strong><span>' . $label . '</span></div>';
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-notify-admin']],
      '#attached' => ['library' => ['xedc_notify/admin']],
      'header' => [
        '#markup' => '<div class="xedc-notify-admin__header"><h1>' . $this->t('通知管理') . '</h1><p class="xedc-notify-admin__lead">' . $this->t('统一管理站内通知、官方通知及通知发送情况。VIP 提醒与卡密请前往 VIP 管理。') . '</p></div>',
      ],
      'stats' => [
        '#markup' => '<div class="xedc-notify-admin__stats">' . $statHtml . '</div>',
      ],
      'panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-notify-admin__panel']],
        'title' => ['#markup' => '<h2>' . $this->t('功能入口') . '</h2>'],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['xedc-notify-admin__actions']],
          'official' => [
            '#type' => 'link',
            '#title' => $this->t('官方通知列表'),
            '#url' => Url::fromRoute('xedc_notify.admin_official'),
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
          'create' => [
            '#type' => 'link',
            '#title' => $this->t('创建官方通知'),
            '#url' => Url::fromRoute('xedc_notify.admin_official_add'),
            '#attributes' => ['class' => ['button']],
          ],
          'vip' => [
            '#type' => 'link',
            '#title' => $this->t('VIP 管理'),
            '#url' => Url::fromRoute('xedc_core.vip_hub'),
            '#attributes' => ['class' => ['button']],
          ],
        ],
        'note' => [
          '#markup' => '<p class="xedc-notify-admin__lead">' . $this->t('创建官方通知时请明确收件人范围。本页不会自动向全体会员广播。') . '</p>',
        ],
      ],
    ];
  }

  public function officialList(): array {
    $rows = [];
    foreach ($this->campaigns->listCampaigns(100) as $row) {
      $status = (string) $row->status;
      $rows[] = [
        Link::fromTextAndUrl((string) $row->title, Url::fromRoute('xedc_notify.admin_official_view', ['cid' => (int) $row->cid]))->toString(),
        OfficialCampaignManager::CATEGORIES[$row->category] ?? $row->category,
        $row->audience,
        ['data' => ['#markup' => '<span class="xedc-notify-admin__status">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>']],
        $this->dateFormatter->format((int) $row->created, 'short'),
        (int) $row->planned_count,
        (int) $row->success_count,
        (int) $row->fail_count,
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-notify-admin']],
      '#attached' => ['library' => ['xedc_notify/admin']],
      'header' => [
        '#markup' => '<div class="xedc-notify-admin__header"><h1>' . $this->t('官方通知') . '</h1><p class="xedc-notify-admin__lead">' . $this->t('创建、预览并分批发送官方站内通知。发送前请确认收件人范围。') . '</p></div>',
      ],
      'panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-notify-admin__panel']],
        'actions' => [
          '#type' => 'link',
          '#title' => $this->t('创建官方通知'),
          '#url' => Url::fromRoute('xedc_notify.admin_official_add'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('标题'),
            $this->t('分类'),
            $this->t('对象'),
            $this->t('状态'),
            $this->t('创建时间'),
            $this->t('计划'),
            $this->t('成功'),
            $this->t('失败'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('还没有官方通知。'),
        ],
      ],
    ];
  }

  public function officialView(int $cid): array {
    $campaign = $this->campaigns->load($cid);
    if (!$campaign) {
      throw new NotFoundHttpException();
    }
    $read = $this->notifications->campaignReadCount($cid);
    $unread = $this->notifications->campaignUnreadCount($cid);
    $actions = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-notify-admin__actions']],
    ];
    if (in_array($campaign->status, ['draft', 'failed', 'partial', 'cancelled'], TRUE)) {
      $actions['send'] = [
        '#type' => 'link',
        '#title' => $this->t('预览并发送'),
        '#url' => Url::fromRoute('xedc_notify.admin_official_send', ['cid' => $cid]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    if (in_array($campaign->status, ['draft', 'queued', 'sending'], TRUE)) {
      $actions['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('取消'),
        '#url' => Url::fromRoute('xedc_notify.admin_official_cancel', ['cid' => $cid]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    $actions['back'] = [
      '#type' => 'link',
      '#title' => $this->t('返回列表'),
      '#url' => Url::fromRoute('xedc_notify.admin_official'),
      '#attributes' => ['class' => ['button']],
    ];
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-notify-admin']],
      '#attached' => ['library' => ['xedc_notify/admin']],
      'header' => [
        '#markup' => '<div class="xedc-notify-admin__header"><h1>' . $this->t('官方通知详情') . '</h1><p class="xedc-notify-admin__lead">' . $this->t('确认收件人范围后再发送。预览不会自动广播。') . '</p></div>',
      ],
      'panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-notify-admin__panel']],
        'summary' => [
          '#theme' => 'item_list',
          '#items' => [
            $this->t('标题：@t', ['@t' => $campaign->title]),
            $this->t('正文：@b', ['@b' => $campaign->body]),
            $this->t('分类：@c', ['@c' => OfficialCampaignManager::CATEGORIES[$campaign->category] ?? $campaign->category]),
            $this->t('对象：@a', ['@a' => $campaign->audience]),
            $this->t('状态：@s', ['@s' => $campaign->status]),
            $this->t('创建管理员 UID：@u', ['@u' => $campaign->created_by]),
            $this->t('创建时间：@t', ['@t' => $this->dateFormatter->format((int) $campaign->created, 'short')]),
            $this->t('发送时间：@t', ['@t' => $campaign->sent_at ? $this->dateFormatter->format((int) $campaign->sent_at, 'short') : '-']),
            $this->t('计划接收：@n', ['@n' => $campaign->planned_count]),
            $this->t('成功：@n', ['@n' => $campaign->success_count]),
            $this->t('失败：@n', ['@n' => $campaign->fail_count]),
            $this->t('已读：@n / 未读：@u', ['@n' => $read, '@u' => $unread]),
            $this->t('失败原因：@e', ['@e' => $campaign->last_error ?: '-']),
          ],
        ],
        'actions' => $actions,
      ],
    ];
  }

  public function vipList(): array {
    $rows = [];
    foreach ($this->vipNotifier->listSent(100) as $row) {
      $rows[] = [
        (int) $row->uid,
        (string) $row->notify_type,
        (int) $row->offset_days,
        date('Y-m-d H:i', (int) $row->period_expires),
        ['data' => ['#markup' => '<span class="xedc-notify-admin__status">' . htmlspecialchars((string) $row->status, ENT_QUOTES, 'UTF-8') . '</span>']],
        (int) $row->notification_id,
        $this->dateFormatter->format((int) $row->created, 'short'),
        (string) $row->message,
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-notify-admin']],
      '#attached' => ['library' => ['xedc_notify/admin']],
      'settings' => [
        '#type' => 'link',
        '#title' => $this->t('VIP 提醒设置'),
        '#url' => Url::fromRoute('xedc_core.vip_hub', [], ['query' => ['tab' => 'reminders']]),
        '#attributes' => ['class' => ['button']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('UID'),
          $this->t('类型'),
          $this->t('提前天数'),
          $this->t('周期到期'),
          $this->t('状态'),
          $this->t('通知 ID'),
          $this->t('发送时间'),
          $this->t('说明'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('还没有 VIP 通知记录。'),
      ],
    ];
  }

}
