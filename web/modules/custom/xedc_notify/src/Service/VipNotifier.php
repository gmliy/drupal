<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Engagement\MembershipManager;

/**
 * VIP lifecycle notifications with period-based idempotency.
 */
final class VipNotifier {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected MembershipManager $membership,
    protected NotificationManager $notifications,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Called after MembershipManager grant/extend writes succeed.
   */
  public function onWrite(int $uid, string $action, int $starts, int $expires, int $actor): void {
    if (!in_array($action, ['grant', 'extend'], TRUE) || $uid < 1 || $expires <= $starts) {
      return;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      return;
    }
    $benefits = MembershipManager::benefits();
    $benefitText = implode("\n", array_map(static fn(string $line): string => '· ' . $line, $benefits));
    $expiresText = date('Y-m-d H:i', $expires);
    if ($action === 'grant') {
      $type = 'vip_grant';
      $title = 'VIP 开通成功';
      $body = "VIP 已成功开通。\n当前有效期至 {$expiresText}。\n\n当前会员权益：\n{$benefitText}";
      $offset = 0;
    }
    else {
      $type = 'vip_extend';
      $title = 'VIP 续期成功';
      $body = "VIP 续期成功。\n新的有效期至 {$expiresText}。\n\n当前会员权益：\n{$benefitText}";
      $offset = 0;
    }
    $this->sendOnce($uid, $expires, $type, $offset, $title, $body, TRUE);
  }

  /**
   * Called after a membership is marked expired.
   */
  public function onExpired(int $uid, int $starts, int $expires): void {
    if ($uid < 1 || $expires < 1) {
      return;
    }
    // Re-check: if already renewed to a later window, skip.
    $row = $this->membership->load($uid);
    $now = $this->time->getRequestTime();
    if ($row && $row->status === 'active' && (int) $row->expires > $now) {
      return;
    }
    $title = 'VIP 已到期';
    $body = '你的 VIP 已于 ' . date('Y-m-d H:i', $expires) . ' 到期，当前已恢复为普通会员。原有点赞、收藏和下载记录仍保留。';
    $this->sendOnce($uid, $expires, 'vip_expired', 0, $title, $body, TRUE);
  }

  /**
   * Cron: queue soon-to-expire reminders based on configured offsets.
   */
  public function scanExpiringReminders(): int {
    $config = $this->configFactory->get('xedc_notify.settings');
    if (!$config->get('vip_reminders_enabled')) {
      return 0;
    }
    $offsets = $config->get('vip_reminder_offsets');
    if (!is_array($offsets) || !$offsets) {
      $offsets = [7, 3, 1];
    }
    $offsets = array_values(array_unique(array_map('intval', $offsets)));
    sort($offsets);
    $now = $this->time->getRequestTime();
    $created = 0;
    foreach ($offsets as $days) {
      if ($days < 1) {
        continue;
      }
      $windowStart = $now + (($days - 1) * 86400);
      $windowEnd = $now + ($days * 86400);
      $uids = $this->database->select('xedc_vip', 'v')
        ->fields('v', ['uid', 'expires', 'starts', 'status'])
        ->condition('status', 'active')
        ->condition('expires', $windowStart, '>')
        ->condition('expires', $windowEnd, '<=')
        ->execute()
        ->fetchAll();
      foreach ($uids as $row) {
        $uid = (int) $row->uid;
        $expires = (int) $row->expires;
        $account = $this->entityTypeManager->getStorage('user')->load($uid);
        if (!$account instanceof UserInterface || !$this->membership->isActive($account)) {
          continue;
        }
        // Skip if period already changed since the select.
        $fresh = $this->membership->load($uid);
        if (!$fresh || (int) $fresh->expires !== $expires) {
          continue;
        }
        $remaining = (int) ceil(($expires - $now) / 86400);
        $title = 'VIP 即将到期';
        $body = "当前 VIP 状态：有效\n到期时间：" . date('Y-m-d H:i', $expires) . "\n剩余有效天数：{$remaining} 天\n\n请前往会员中心查看会员等级并联系管理员续期。";
        if ($this->sendOnce($uid, $expires, 'vip_expiring', $days, $title, $body, TRUE)) {
          $created++;
        }
      }
    }
    return $created;
  }

  /**
   * Notifies active VIP members about benefit text changes.
   *
   * @param list<string> $before
   * @param list<string> $after
   */
  public function notifyBenefitsChanged(array $before, array $after, int $actor, bool $notifyMembers): int {
    if (!$notifyMembers || $before === $after) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $beforeText = implode("\n", array_map(static fn($l) => '· ' . $l, $before));
    $afterText = implode("\n", array_map(static fn($l) => '· ' . $l, $after));
    $title = 'VIP 权益发生变化';
    $body = "权益变更时间：" . date('Y-m-d H:i', $now) . "\n\n变更前：\n{$beforeText}\n\n变更后：\n{$afterText}";
    $uids = $this->database->select('xedc_vip', 'v')
      ->fields('v', ['uid', 'expires'])
      ->condition('status', 'active')
      ->condition('expires', $now, '>')
      ->execute()
      ->fetchAll();
    $count = 0;
    foreach ($uids as $row) {
      $uid = (int) $row->uid;
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      // Only currently valid VIP members from MembershipManager.
      if (!$account instanceof UserInterface || !$this->membership->isActive($account)) {
        continue;
      }
      $fresh = $this->membership->load($uid);
      if (!$fresh || (int) $fresh->expires !== (int) $row->expires) {
        continue;
      }
      $expires = (int) $fresh->expires;
      $dedupeOffset = (int) abs(crc32(implode('|', $after))) % 100000;
      if ($this->sendOnce($uid, $expires, 'vip_benefits', $dedupeOffset, $title, $body, TRUE)) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Safe manual resend: still subject to unique dedupe key.
   */
  public function retry(int $logId): array {
    $row = $this->database->select('xedc_vip_notify_sent', 's')
      ->fields('s')
      ->condition('id', $logId)
      ->execute()
      ->fetchObject();
    if (!$row) {
      return ['ok' => FALSE, 'message' => '记录不存在。'];
    }
    if ($row->status === 'sent' && (int) $row->notification_id > 0) {
      return ['ok' => FALSE, 'message' => '已发送过，幂等校验阻止重复补发。'];
    }
    // Rebuild content from type.
    $uid = (int) $row->uid;
    $expires = (int) $row->period_expires;
    $type = (string) $row->notify_type;
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      return ['ok' => FALSE, 'message' => '会员不存在。'];
    }
    if ($type === 'vip_expiring') {
      $current = $this->membership->load($uid);
      if (!$current || $current->status !== 'active' || (int) $current->expires !== $expires) {
        $this->database->update('xedc_vip_notify_sent')
          ->fields(['status' => 'skipped', 'message' => '有效期已变更，跳过旧提醒'])
          ->condition('id', $logId)
          ->execute();
        return ['ok' => FALSE, 'message' => '有效期已变更，已跳过。'];
      }
    }
    return ['ok' => FALSE, 'message' => '失败记录需从业务入口重新触发；已发送记录不可重复。'];
  }

  /**
   * @return list<object>
   */
  public function listSent(int $limit = 50, ?string $type = NULL): array {
    $query = $this->database->select('xedc_vip_notify_sent', 's')->fields('s');
    if ($type) {
      $query->condition('notify_type', $type);
    }
    $query->orderBy('id', 'DESC')->range(0, $limit);
    return $query->execute()->fetchAll();
  }

  protected function sendOnce(int $uid, int $periodExpires, string $type, int $offsetDays, string $title, string $body, bool $important): bool {
    $now = $this->time->getRequestTime();
    $logId = 0;
    try {
      $logId = (int) $this->database->insert('xedc_vip_notify_sent')
        ->fields([
          'uid' => $uid,
          'period_expires' => $periodExpires,
          'notify_type' => $type,
          'offset_days' => $offsetDays,
          'notification_id' => 0,
          'status' => 'pending',
          'message' => '',
          'created' => $now,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Another cron may have crashed after inserting pending/failed. Reclaim
      // only unfinished rows; never reclaim successfully sent ones.
      $existing = $this->database->select('xedc_vip_notify_sent', 's')
        ->fields('s')
        ->condition('uid', $uid)
        ->condition('period_expires', $periodExpires)
        ->condition('notify_type', $type)
        ->condition('offset_days', $offsetDays)
        ->execute()
        ->fetchObject();
      if (!$existing || !in_array((string) $existing->status, ['pending', 'failed'], TRUE)) {
        return FALSE;
      }
      $claimed = (int) $this->database->update('xedc_vip_notify_sent')
        ->fields(['status' => 'pending', 'message' => 'reclaimed', 'created' => $now])
        ->condition('id', (int) $existing->id)
        ->condition('status', ['pending', 'failed'], 'IN')
        ->execute();
      if ($claimed < 1) {
        return FALSE;
      }
      $logId = (int) $existing->id;
    }

    // Re-validate expiring reminders against current VIP window.
    if ($type === 'vip_expiring') {
      $row = $this->membership->load($uid);
      if (!$row || $row->status !== 'active' || (int) $row->expires !== $periodExpires || (int) $row->expires <= $now) {
        $this->database->update('xedc_vip_notify_sent')
          ->fields(['status' => 'skipped', 'message' => '有效期不匹配'])
          ->condition('id', $logId)
          ->execute();
        return FALSE;
      }
    }

    $nid = $this->notifications->create([
      'uid' => $uid,
      'category' => NotificationManager::CATEGORY_VIP,
      'type' => $type,
      'title' => $title,
      'body' => $body,
      'route_name' => 'xedc_member.membership',
      'route_params' => [],
      'dedupe_key' => 'vip:' . $type . ':' . $uid . ':' . $periodExpires . ':' . $offsetDays,
      'important' => $important,
    ]);
    // If the member notification already exists from a previous reclaim, treat
    // as success so the audit row is not left failed forever.
    if ($nid < 1) {
      $existingNid = (int) $this->database->select('xedc_notification', 'n')
        ->fields('n', ['id'])
        ->condition('uid', $uid)
        ->condition('dedupe_key', 'vip:' . $type . ':' . $uid . ':' . $periodExpires . ':' . $offsetDays)
        ->execute()
        ->fetchField();
      if ($existingNid > 0) {
        $nid = $existingNid;
      }
    }
    $this->database->update('xedc_vip_notify_sent')
      ->fields([
        'notification_id' => $nid,
        'status' => $nid > 0 ? 'sent' : 'failed',
        'message' => $nid > 0 ? '' : '通知写入失败或重复',
      ])
      ->condition('id', $logId)
      ->execute();
    return $nid > 0;
  }

}
