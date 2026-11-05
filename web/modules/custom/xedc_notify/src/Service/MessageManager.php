<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Session\AccountInterface;

/**
 * Private message threads and text messages between two members.
 */
final class MessageManager {

  public const MAX_BODY_DEFAULT = 2000;

  public const RATE_LIMIT_DEFAULT = 20;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected MemberIdentity $identity,
  ) {}

  /**
   * @return list<object>
   *   Thread rows for the inbox, newest last message first.
   */
  public function listThreads(int $uid, int $limit = 50, int $offset = 0): array {
    if ($uid < 1) {
      return [];
    }
    $query = $this->database->select('xedc_pm_thread_state', 's');
    $query->fields('s', ['tid', 'unread_count', 'hidden', 'last_read_mid']);
    $query->join('xedc_pm_thread', 't', 't.tid = s.tid');
    $query->fields('t', ['uid_low', 'uid_high', 'last_mid', 'last_created', 'created', 'changed']);
    $query->condition('s.uid', $uid);
    $query->condition('s.hidden', 0);
    $query->condition('t.last_mid', 0, '>');
    $query->orderBy('t.last_created', 'DESC');
    $query->range($offset, $limit);
    $rows = $query->execute()->fetchAll();
    foreach ($rows as $row) {
      $other = ((int) $row->uid_low === $uid) ? (int) $row->uid_high : (int) $row->uid_low;
      $row->other_uid = $other;
      $row->other_name = $this->identity->displayNameByUid($other);
      $row->other_avatar = $this->identity->avatarUrl($other);
      $row->other_initial = $this->identity->initial($row->other_name);
      $row->preview = $this->preview((int) $row->last_mid);
    }
    return $rows;
  }

  public function unreadTotal(int $uid): int {
    if ($uid < 1) {
      return 0;
    }
    $query = $this->database->select('xedc_pm_thread_state', 's');
    $query->addExpression('COALESCE(SUM(s.unread_count), 0)', 'total');
    $query->condition('uid', $uid);
    $query->condition('hidden', 0);
    return (int) $query->execute()->fetchField();
  }

  /**
   * Ensures a thread exists for the current user and peer.
   */
  public function getOrCreateThread(int $uidA, int $uidB): int {
    if ($uidA < 1 || $uidB < 1 || $uidA === $uidB) {
      throw new \InvalidArgumentException('私信对象无效。');
    }
    if (!$this->identity->load($uidB)) {
      throw new \InvalidArgumentException('对方会员不存在。');
    }
    [$low, $high] = $this->pair($uidA, $uidB);
    $existing = $this->database->select('xedc_pm_thread', 't')
      ->fields('t', ['tid'])
      ->condition('uid_low', $low)
      ->condition('uid_high', $high)
      ->execute()
      ->fetchField();
    if ($existing) {
      $tid = (int) $existing;
      $this->ensureState($tid, $uidA);
      $this->ensureState($tid, $uidB);
      return $tid;
    }
    $now = $this->time->getRequestTime();
    try {
      $tid = (int) $this->database->insert('xedc_pm_thread')
        ->fields([
          'uid_low' => $low,
          'uid_high' => $high,
          'last_mid' => 0,
          'last_created' => 0,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      $tid = (int) $this->database->select('xedc_pm_thread', 't')
        ->fields('t', ['tid'])
        ->condition('uid_low', $low)
        ->condition('uid_high', $high)
        ->execute()
        ->fetchField();
    }
    $this->ensureState($tid, $uidA);
    $this->ensureState($tid, $uidB);
    return $tid;
  }

  public function assertParticipant(int $tid, int $uid): object {
    $thread = $this->loadThread($tid);
    if (!$thread) {
      throw new \InvalidArgumentException('会话不存在。');
    }
    if ((int) $thread->uid_low !== $uid && (int) $thread->uid_high !== $uid) {
      throw new \AccessException('无权访问此私信会话。');
    }
    return $thread;
  }

  public function loadThread(int $tid): ?object {
    $row = $this->database->select('xedc_pm_thread', 't')
      ->fields('t')
      ->condition('tid', $tid)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Sends a plain-text message. Sender must be the current account.
   *
   * @return array{mid: int, tid: int}
   */
  public function send(AccountInterface $sender, int $recipientUid, string $body): array {
    $senderUid = (int) $sender->id();
    if ($senderUid < 1 || !$sender->isAuthenticated()) {
      throw new \InvalidArgumentException('请先登录。');
    }
    // Blocked / missing accounts cannot send, even with a leftover session.
    if (!$this->identity->load($senderUid)) {
      throw new \InvalidArgumentException('当前账号不可用。');
    }
    if ($recipientUid < 1 || $recipientUid === $senderUid) {
      throw new \InvalidArgumentException('私信对象无效。');
    }
    if (!$this->identity->load($recipientUid)) {
      throw new \InvalidArgumentException('对方会员不存在。');
    }
    $body = $this->sanitizeBody($body);
    $this->assertRate($senderUid);
    $tid = $this->getOrCreateThread($senderUid, $recipientUid);
    $now = $this->time->getRequestTime();
    $transaction = $this->database->startTransaction();
    try {
      $mid = (int) $this->database->insert('xedc_pm_message')
        ->fields([
          'tid' => $tid,
          'sender_uid' => $senderUid,
          'recipient_uid' => $recipientUid,
          'body' => $body,
          'created' => $now,
          'is_read' => 0,
          'read_at' => 0,
        ])
        ->execute();
      // Keep the newest message pointer under concurrent sends.
      $this->database->update('xedc_pm_thread')
        ->fields([
          'last_mid' => $mid,
          'last_created' => $now,
          'changed' => $now,
        ])
        ->condition('tid', $tid)
        ->condition('last_mid', $mid, '<')
        ->execute();
      $this->ensureState($tid, $senderUid);
      $this->ensureState($tid, $recipientUid);
      // Recipient sees the thread again if they had hidden it.
      $this->database->update('xedc_pm_thread_state')
        ->fields([
          'hidden' => 0,
          'changed' => $now,
        ])
        ->expression('unread_count', 'unread_count + 1')
        ->condition('tid', $tid)
        ->condition('uid', $recipientUid)
        ->execute();
      // Sender keeps the thread visible after sending.
      $this->database->update('xedc_pm_thread_state')
        ->fields([
          'hidden' => 0,
          'changed' => $now,
        ])
        ->condition('tid', $tid)
        ->condition('uid', $senderUid)
        ->execute();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return ['mid' => $mid, 'tid' => $tid];
  }

  /**
   * @return list<object>
   */
  public function listMessages(int $tid, int $uid, int $limit = 100, int $beforeMid = 0): array {
    $this->assertParticipant($tid, $uid);
    $state = $this->loadState($tid, $uid);
    $query = $this->database->select('xedc_pm_message', 'm')
      ->fields('m')
      ->condition('tid', $tid)
      ->orderBy('mid', 'ASC')
      ->range(0, $limit);
    if ($beforeMid > 0) {
      $query->condition('mid', $beforeMid, '<');
    }
    // After a one-sided hide, older messages stay hidden for that user.
    // Cut-off uses message id (not timestamp) to avoid same-second races.
    if ($state && (int) $state->hidden === 0 && (int) ($state->hide_after_mid ?? 0) > 0) {
      $query->condition('mid', (int) $state->hide_after_mid, '>');
    }
    return $query->execute()->fetchAll();
  }

  public function markThreadRead(int $tid, int $uid): void {
    $thread = $this->assertParticipant($tid, $uid);
    $now = $this->time->getRequestTime();
    $this->database->update('xedc_pm_message')
      ->fields(['is_read' => 1, 'read_at' => $now])
      ->condition('tid', $tid)
      ->condition('recipient_uid', $uid)
      ->condition('is_read', 0)
      ->execute();
    $this->database->merge('xedc_pm_thread_state')
      ->keys(['tid' => $tid, 'uid' => $uid])
      ->fields([
        'unread_count' => 0,
        'last_read_mid' => (int) $thread->last_mid,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Soft-hides the thread for one participant only.
   */
  public function hideThread(int $tid, int $uid): void {
    $thread = $this->assertParticipant($tid, $uid);
    $now = $this->time->getRequestTime();
    $this->database->merge('xedc_pm_thread_state')
      ->keys(['tid' => $tid, 'uid' => $uid])
      ->fields([
        'hidden' => 1,
        'hidden_at' => $now,
        'hide_after_mid' => (int) $thread->last_mid,
        'unread_count' => 0,
        'changed' => $now,
      ])
      ->execute();
  }

  public function otherUid(object $thread, int $uid): int {
    return ((int) $thread->uid_low === $uid) ? (int) $thread->uid_high : (int) $thread->uid_low;
  }

  protected function sanitizeBody(string $body): string {
    $body = trim(str_replace("\0", '', $body));
    $body = strip_tags($body);
    $body = preg_replace("/\r\n?/", "\n", $body) ?? $body;
    if ($body === '') {
      throw new \InvalidArgumentException('消息不能为空。');
    }
    $max = (int) $this->configFactory->get('xedc_notify.settings')->get('message_max_length');
    if ($max < 1) {
      $max = self::MAX_BODY_DEFAULT;
    }
    if (mb_strlen($body) > $max) {
      throw new \InvalidArgumentException('消息过长，最多 ' . $max . ' 个字符。');
    }
    return $body;
  }

  protected function assertRate(int $uid): void {
    $limit = (int) $this->configFactory->get('xedc_notify.settings')->get('message_rate_limit');
    if ($limit < 1) {
      $limit = self::RATE_LIMIT_DEFAULT;
    }
    $since = $this->time->getRequestTime() - 60;
    $count = (int) $this->database->select('xedc_pm_message', 'm')
      ->condition('sender_uid', $uid)
      ->condition('created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($count >= $limit) {
      throw new \InvalidArgumentException('发送过于频繁，请稍后再试。');
    }
  }

  /**
   * @return array{0: int, 1: int}
   */
  protected function pair(int $a, int $b): array {
    return $a < $b ? [$a, $b] : [$b, $a];
  }

  protected function ensureState(int $tid, int $uid): void {
    $exists = $this->database->select('xedc_pm_thread_state', 's')
      ->condition('tid', $tid)
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($exists) {
      return;
    }
    try {
      $this->database->insert('xedc_pm_thread_state')
        ->fields([
          'tid' => $tid,
          'uid' => $uid,
          'hidden' => 0,
          'hidden_at' => 0,
          'hide_after_mid' => 0,
          'unread_count' => 0,
          'last_read_mid' => 0,
          'changed' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Concurrent ensureState already inserted the row.
    }
  }

  protected function loadState(int $tid, int $uid): ?object {
    $row = $this->database->select('xedc_pm_thread_state', 's')
      ->fields('s')
      ->condition('tid', $tid)
      ->condition('uid', $uid)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  protected function preview(int $mid): string {
    if ($mid < 1) {
      return '';
    }
    $body = (string) $this->database->select('xedc_pm_message', 'm')
      ->fields('m', ['body'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
    return mb_strlen($body) > 80 ? mb_substr($body, 0, 80) . '…' : $body;
  }

}
