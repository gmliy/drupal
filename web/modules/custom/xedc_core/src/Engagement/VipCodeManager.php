<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\user\UserInterface;

/**
 * One-time VIP redeem codes. Plaintext is never persisted.
 */
final class VipCodeManager {

  public const STATUS_UNUSED = 'unused';
  public const STATUS_REDEEMED = 'redeemed';
  public const STATUS_REVOKED = 'revoked';
  public const STATUS_EXPIRED = 'expired';

  public const MAX_BATCH_SIZE = 200;
  public const MIN_DAYS = 1;
  public const MAX_DAYS = 3650;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MembershipManager $membership,
    protected FloodInterface $flood,
  ) {}

  /**
   * @return array{batch_id: int, batch_uuid: string, codes: list<string>}
   */
  public function generateBatch(int $quantity, int $vipDays, int $expiresAt, string $note, int $actorUid): array {
    if ($quantity < 1 || $quantity > self::MAX_BATCH_SIZE) {
      throw new \InvalidArgumentException('生成数量须在 1–' . self::MAX_BATCH_SIZE . ' 之间。');
    }
    if ($vipDays < self::MIN_DAYS || $vipDays > self::MAX_DAYS) {
      throw new \InvalidArgumentException('VIP 天数无效。');
    }
    if ($expiresAt < 0) {
      throw new \InvalidArgumentException('兑换截止日期无效。');
    }
    $now = $this->time->getRequestTime();
    if ($expiresAt > 0 && $expiresAt <= $now) {
      throw new \InvalidArgumentException('兑换截止日期必须晚于当前时间。');
    }
    $note = mb_substr(trim($note), 0, 255);
    $uuid = Crypt::randomBytesBase64(16);
    $transaction = $this->database->startTransaction();
    try {
      $batchId = (int) $this->database->insert('xedc_vip_code_batch')
        ->fields([
          'batch_uuid' => $uuid,
          'vip_days' => $vipDays,
          'quantity' => $quantity,
          'expires_at' => $expiresAt,
          'note' => $note,
          'created_uid' => $actorUid,
          'created' => $now,
          'status' => 'active',
        ])
        ->execute();
      $plain = [];
      for ($i = 0; $i < $quantity; $i++) {
        $code = $this->createUniqueCode($batchId, $vipDays, $expiresAt, $now);
        $plain[] = $code;
      }
      return [
        'batch_id' => $batchId,
        'batch_uuid' => $uuid,
        'codes' => $plain,
      ];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Redeems a code for the given member. Concurrent callers: only one wins.
   *
   * @return array{vip_days: int, expires: int, action: string}
   */
  public function redeem(string $rawCode, UserInterface $account): array {
    $uid = (int) $account->id();
    if ($uid < 1 || !$account->isActive()) {
      throw new \InvalidArgumentException('请先登录有效会员账号。');
    }
    $floodKey = 'uid:' . $uid;
    if (!$this->flood->isAllowed('xedc_vip_redeem_uid', 12, 3600, $floodKey)) {
      throw new \InvalidArgumentException('兑换尝试过于频繁，请稍后再试。');
    }
    $ip = \Drupal::requestStack()->getCurrentRequest()?->getClientIp() ?? '';
    if ($ip !== '' && !$this->flood->isAllowed('xedc_vip_redeem_ip', 40, 3600, $ip)) {
      throw new \InvalidArgumentException('兑换尝试过于频繁，请稍后再试。');
    }
    $this->flood->register('xedc_vip_redeem_uid', 3600, $floodKey);
    if ($ip !== '') {
      $this->flood->register('xedc_vip_redeem_ip', 3600, $ip);
    }

    $normalized = $this->normalize($rawCode);
    if (!$this->isValidFormat($normalized)) {
      throw new \InvalidArgumentException('卡密格式不正确。');
    }
    $hash = $this->hash($normalized);
    $now = $this->time->getRequestTime();

    $transaction = $this->database->startTransaction();
    try {
      $row = $this->database->query(
        'SELECT * FROM {xedc_vip_code} WHERE code_hash = :h FOR UPDATE',
        [':h' => $hash]
      )->fetchObject();
      if (!$row) {
        throw new \InvalidArgumentException('卡密无效或不存在。');
      }
      $status = $this->effectiveStatus($row, $now);
      if ($status === self::STATUS_REDEEMED) {
        throw new \InvalidArgumentException('该卡密已被兑换。');
      }
      if ($status === self::STATUS_REVOKED) {
        throw new \InvalidArgumentException('该卡密已作废。');
      }
      if ($status === self::STATUS_EXPIRED) {
        throw new \InvalidArgumentException('该卡密已过期。');
      }
      if ($status !== self::STATUS_UNUSED) {
        throw new \InvalidArgumentException('该卡密不可兑换。');
      }

      $days = (int) $row->vip_days;
      $wasActive = $this->membership->isActive($account);
      if ($wasActive) {
        $this->membership->extend($uid, $days, $uid, 'vip_code:' . (int) $row->code_id);
        $action = 'extend';
      }
      else {
        $starts = $now;
        $expires = $starts + ($days * 86400);
        $this->membership->grant($uid, $starts, $expires, $uid, 'vip_code:' . (int) $row->code_id);
        $action = 'grant';
      }

      $claimed = (int) $this->database->update('xedc_vip_code')
        ->fields([
          'status' => self::STATUS_REDEEMED,
          'redeemed_uid' => $uid,
          'redeemed' => $now,
        ])
        ->condition('code_id', (int) $row->code_id)
        ->condition('status', self::STATUS_UNUSED)
        ->execute();
      if ($claimed !== 1) {
        throw new \RuntimeException('卡密已被其他请求领取，请重试。');
      }

      $vip = $this->membership->load($uid);
      return [
        'vip_days' => $days,
        'expires' => $vip ? (int) $vip->expires : 0,
        'action' => $action,
      ];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  public function revoke(int $codeId, int $actorUid): bool {
    $now = $this->time->getRequestTime();
    $updated = (int) $this->database->update('xedc_vip_code')
      ->fields(['status' => self::STATUS_REVOKED])
      ->condition('code_id', $codeId)
      ->condition('status', self::STATUS_UNUSED)
      ->execute();
    if ($updated === 1) {
      \Drupal::logger('xedc_core')->notice('VIP 卡密已作废 code_id=@id by=@u', [
        '@id' => $codeId,
        '@u' => $actorUid,
      ]);
    }
    return $updated === 1;
  }

  public function loadBatch(int $batchId): ?object {
    $row = $this->database->select('xedc_vip_code_batch', 'b')
      ->fields('b')
      ->condition('batch_id', $batchId)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  public function loadCode(int $codeId): ?object {
    $row = $this->database->select('xedc_vip_code', 'c')
      ->fields('c')
      ->condition('code_id', $codeId)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * @return list<object>
   */
  public function listBatches(int $limit = 50): array {
    return $this->database->select('xedc_vip_code_batch', 'b')
      ->fields('b')
      ->orderBy('batch_id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * @return array{unused: int, redeemed: int, revoked: int, expired: int}
   */
  public function batchCounts(int $batchId): array {
    $now = $this->time->getRequestTime();
    $counts = [
      'unused' => 0,
      'redeemed' => 0,
      'revoked' => 0,
      'expired' => 0,
    ];
    $rows = $this->database->select('xedc_vip_code', 'c')
      ->fields('c', ['code_id', 'status', 'expires_at'])
      ->condition('batch_id', $batchId)
      ->execute()
      ->fetchAll();
    foreach ($rows as $row) {
      $counts[$this->effectiveStatus($row, $now)]++;
    }
    return $counts;
  }

  /**
   * @return list<object>
   */
  public function listCodes(?int $batchId = NULL, ?string $status = NULL, string $prefix = '', int $limit = 100): array {
    $q = $this->database->select('xedc_vip_code', 'c')->fields('c');
    if ($batchId) {
      $q->condition('batch_id', $batchId);
    }
    if ($prefix !== '') {
      $q->condition('code_prefix', mb_substr(strtoupper($prefix), 0, 8) . '%', 'LIKE');
    }
    $q->orderBy('code_id', 'DESC')->range(0, $limit);
    $rows = $q->execute()->fetchAll();
    $now = $this->time->getRequestTime();
    if ($status) {
      $rows = array_values(array_filter($rows, fn($r) => $this->effectiveStatus($r, $now) === $status));
    }
    return $rows;
  }

  /**
   * @return list<object>
   */
  public function listRedemptionsForUser(int $uid, int $limit = 20): array {
    return $this->database->select('xedc_vip_code', 'c')
      ->fields('c')
      ->condition('redeemed_uid', $uid)
      ->condition('status', self::STATUS_REDEEMED)
      ->orderBy('redeemed', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  public function maskCode(object $row): string {
    $prefix = (string) ($row->code_prefix ?? '');
    if ($prefix === '') {
      return '****-****-****-****';
    }
    return $prefix . '****-****-****';
  }

  public function statusLabel(string $status): string {
    return match ($status) {
      self::STATUS_UNUSED => '未兑换',
      self::STATUS_REDEEMED => '已兑换',
      self::STATUS_REVOKED => '已作废',
      self::STATUS_EXPIRED => '已过期',
      default => '未知',
    };
  }

  public function effectiveStatus(object $row, ?int $now = NULL): string {
    $now ??= $this->time->getRequestTime();
    $status = (string) $row->status;
    if ($status === self::STATUS_UNUSED && (int) $row->expires_at > 0 && (int) $row->expires_at <= $now) {
      return self::STATUS_EXPIRED;
    }
    return $status;
  }

  public function normalize(string $raw): string {
    $raw = strtoupper(trim($raw));
    $raw = preg_replace('/[^A-Z0-9]/', '', $raw) ?? '';
    if (strlen($raw) === 16) {
      return implode('-', str_split($raw, 4));
    }
    return $raw;
  }

  public function isValidFormat(string $normalized): bool {
    return (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $normalized);
  }

  public function hash(string $normalized): string {
    return hash_hmac('sha256', $normalized, Settings::getHashSalt() . '|xedc_vip_code');
  }

  protected function createUniqueCode(int $batchId, int $vipDays, int $expiresAt, int $now): string {
    for ($attempt = 0; $attempt < 12; $attempt++) {
      $code = $this->randomCode();
      $hash = $this->hash($code);
      try {
        $this->database->insert('xedc_vip_code')
          ->fields([
            'batch_id' => $batchId,
            'code_hash' => $hash,
            'code_prefix' => substr(str_replace('-', '', $code), 0, 4),
            'status' => self::STATUS_UNUSED,
            'vip_days' => $vipDays,
            'expires_at' => $expiresAt,
            'redeemed_uid' => 0,
            'redeemed' => 0,
            'created' => $now,
          ])
          ->execute();
        return $code;
      }
      catch (IntegrityConstraintViolationException) {
        // Hash collision — retry with a new code.
      }
    }
    throw new \RuntimeException('无法生成唯一卡密，请重试。');
  }

  protected function randomCode(): string {
    // Crockford-like alphabet without ambiguous I/L/O/0/1.
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $len = strlen($alphabet);
    $parts = [];
    for ($g = 0; $g < 4; $g++) {
      $chunk = '';
      for ($i = 0; $i < 4; $i++) {
        $chunk .= $alphabet[random_int(0, $len - 1)];
      }
      $parts[] = $chunk;
    }
    return implode('-', $parts);
  }

}
