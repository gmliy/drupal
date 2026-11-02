<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * VIP membership is a dated record. The vip role only mirrors that record.
 */
final class MembershipManager {

  public const ROLE = 'vip';

  public const PRESETS = [7, 30, 90, 365];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function tier(AccountInterface $account): string {
    if (!$account->isAuthenticated()) {
      return 'anonymous';
    }
    return $this->isActive($account) ? 'vip' : 'member';
  }

  public function isActive(AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    $uid = (int) $account->id();
    $row = $this->load($uid);
    $now = $this->time->getRequestTime();
    if ($row === NULL || $row->status !== 'active' || (int) $row->starts > $now) {
      $this->syncRole($account, FALSE);
      return FALSE;
    }
    if ((int) $row->expires <= $now) {
      $this->expireOne($uid);
      return FALSE;
    }
    $this->syncRole($account, TRUE);
    return TRUE;
  }

  /**
   * @return array<string, mixed>
   *   Display data for the member center. Times are raw timestamps.
   */
  public function snapshot(AccountInterface $account): array {
    $tier = $this->tier($account);
    $row = $account->isAuthenticated() ? $this->load((int) $account->id()) : NULL;
    $now = $this->time->getRequestTime();
    $expires = $row ? (int) $row->expires : 0;
    $remaining = 0;
    if ($tier === 'vip' && $expires > $now) {
      $remaining = (int) ceil(($expires - $now) / 86400);
    }
    $status = $row ? (string) $row->status : '';
    return [
      'tier' => $tier,
      'label' => $tier === 'vip' ? 'VIP 会员' : ($tier === 'member' ? '普通会员' : '游客'),
      'active' => $tier === 'vip',
      'status' => $status,
      'starts' => $row ? (int) $row->starts : 0,
      'expires' => $expires,
      'remaining' => $remaining,
      'expiring_soon' => $tier === 'vip' && $remaining > 0 && $remaining <= 7,
      'expired' => $status === 'expired' || ($status === 'active' && $expires > 0 && $expires <= $now),
      'cancelled' => $status === 'cancelled',
      'benefits' => self::benefits(),
    ];
  }

  /**
   * @return list<string>
   *   Benefit lines shown to members. There is no payment flow.
   */
  public static function benefits(): array {
    return [
      '下载标记为 VIP 专属的资源。',
      '公开资源和登录会员资源仍按各自权限提供。',
      '到期或被取消后，立即恢复为普通会员，原有点赞、收藏和下载记录保留。',
      'VIP 由管理员手动开通，本站不提供在线支付。',
    ];
  }

  public function grant(int $uid, int $starts, int $expires, int $actor, string $note = ''): void {
    $this->write($uid, $starts, $expires, $actor, 'grant', $note);
  }

  public function extend(int $uid, int $days, int $actor, string $note = ''): void {
    if (!in_array($days, self::PRESETS, TRUE) && $days < 1) {
      throw new \InvalidArgumentException('延长天数无效。');
    }
    $now = $this->time->getRequestTime();
    $row = $this->load($uid);
    $base = $now;
    $starts = $now;
    if ($row && $row->status === 'active' && (int) $row->expires > $now) {
      $base = (int) $row->expires;
      $starts = (int) $row->starts;
    }
    $this->write($uid, $starts, $base + ($days * 86400), $actor, 'extend', $note);
  }

  public function cancel(int $uid, int $actor, string $note = ''): void {
    $now = $this->time->getRequestTime();
    $row = $this->load($uid);
    $starts = $row ? (int) $row->starts : 0;
    $expires = $row ? (int) $row->expires : 0;
    if ($row) {
      $this->database->update('xedc_vip')
        ->fields([
          'status' => 'cancelled',
          'changed' => $now,
          'granted_by' => $actor,
        ])
        ->condition('uid', $uid)
        ->execute();
    }
    $this->log($uid, 'cancel', $starts, $expires, $actor, $note);
    $this->invalidate($uid);
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user instanceof UserInterface && $user->hasRole(self::ROLE)) {
      $user->removeRole(self::ROLE);
      $user->save();
    }
  }

  /**
   * Marks every due membership expired. Page checks do not wait for this.
   */
  public function expireDue(): int {
    $now = $this->time->getRequestTime();
    $uids = $this->database->select('xedc_vip', 'v')
      ->fields('v', ['uid'])
      ->condition('status', 'active')
      ->condition('expires', $now, '<=')
      ->execute()
      ->fetchCol();
    $count = 0;
    foreach (array_map('intval', $uids) as $uid) {
      if ($this->expireOne($uid)) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * @return object[]
   *   Log rows, newest first.
   */
  public function history(int $uid, int $limit = 20): array {
    return $this->database->select('xedc_vip_log', 'l')
      ->fields('l')
      ->condition('uid', $uid)
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * @return object|null
   *   The membership row.
   */
  public function load(int $uid): ?object {
    $row = $this->database->select('xedc_vip', 'v')
      ->fields('v')
      ->condition('uid', $uid)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  public function tag(int $uid): string {
    return 'xedc_vip:' . $uid;
  }

  protected function write(int $uid, int $starts, int $expires, int $actor, string $action, string $note): void {
    if ($uid < 1) {
      throw new \InvalidArgumentException('请选择一个会员。');
    }
    if ($expires <= $starts) {
      throw new \InvalidArgumentException('到期时间必须晚于开始时间。');
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      throw new \InvalidArgumentException('会员不存在。');
    }
    $now = $this->time->getRequestTime();
    $fields = [
      'status' => 'active',
      'starts' => $starts,
      'expires' => $expires,
      'changed' => $now,
      'granted_by' => $actor,
    ];
    if ($this->load($uid)) {
      $this->database->update('xedc_vip')->fields($fields)->condition('uid', $uid)->execute();
    }
    else {
      $fields['uid'] = $uid;
      $fields['created'] = $now;
      $this->database->insert('xedc_vip')->fields($fields)->execute();
    }
    $this->log($uid, $action, $starts, $expires, $actor, $note);
    if (!$user->hasRole(self::ROLE) && $this->entityTypeManager->getStorage('user_role')->load(self::ROLE)) {
      $user->addRole(self::ROLE);
      $user->save();
    }
    $this->invalidate($uid);
  }

  protected function expireOne(int $uid): bool {
    $now = $this->time->getRequestTime();
    $row = $this->load($uid);
    if (!$row || $row->status !== 'active' || (int) $row->expires > $now) {
      return FALSE;
    }
    $updated = (int) $this->database->update('xedc_vip')
      ->fields(['status' => 'expired', 'changed' => $now])
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute();
    if ($updated < 1) {
      return FALSE;
    }
    $this->log($uid, 'expire', (int) $row->starts, (int) $row->expires, 0, '');
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user instanceof UserInterface && $user->hasRole(self::ROLE)) {
      $user->removeRole(self::ROLE);
      $user->save();
    }
    $this->invalidate($uid);
    return TRUE;
  }

  protected function syncRole(AccountInterface $account, bool $active): void {
    $uid = (int) $account->id();
    if ($uid < 1 || !$this->entityTypeManager->getStorage('user_role')->load(self::ROLE)) {
      return;
    }
    $has = in_array(self::ROLE, $account->getRoles(), TRUE);
    if ($has === $active) {
      return;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }
    if ($active) {
      $user->addRole(self::ROLE);
    }
    else {
      $user->removeRole(self::ROLE);
    }
    $user->save();
    $this->invalidate($uid);
  }

  protected function log(int $uid, string $action, int $starts, int $expires, int $actor, string $note): void {
    $this->database->insert('xedc_vip_log')->fields([
      'uid' => $uid,
      'action' => $action,
      'starts' => $starts,
      'expires' => $expires,
      'note' => mb_substr($note, 0, 255),
      'actor' => $actor,
      'created' => $this->time->getRequestTime(),
    ])->execute();
  }

  public function invalidate(int $uid): void {
    Cache::invalidateTags([$this->tag($uid), 'user:' . $uid]);
  }

}
