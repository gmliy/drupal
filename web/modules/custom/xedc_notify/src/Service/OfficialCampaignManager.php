<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Engagement\MembershipManager;

/**
 * Admin-managed official notification campaigns with queued delivery.
 */
final class OfficialCampaignManager {

  public const AUDIENCES = ['all', 'member', 'vip', 'uids'];

  public const CATEGORIES = [
    'announcement' => '网站公告',
    'maintenance' => '网站维护通知',
    'security' => '账户安全提醒',
    'moderation' => '违规处理通知',
    'account_status' => '账号状态变化',
  ];

  public const STATUSES = [
    'draft',
    'queued',
    'sending',
    'sent',
    'partial',
    'failed',
    'cancelled',
  ];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected QueueFactory $queueFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MembershipManager $membership,
    protected NotificationManager $notifications,
  ) {}

  /**
   * @param array{
   *   title: string,
   *   body: string,
   *   category: string,
   *   audience: string,
   *   audience_uids?: int[],
   *   important?: bool,
   *   pinned?: bool,
   *   created_by: int
   * } $data
   */
  public function createDraft(array $data): int {
    $title = mb_substr(strip_tags(trim((string) ($data['title'] ?? ''))), 0, 255);
    $body = strip_tags(trim((string) ($data['body'] ?? '')));
    $category = (string) ($data['category'] ?? 'announcement');
    $audience = (string) ($data['audience'] ?? 'all');
    if ($title === '' || $body === '') {
      throw new \InvalidArgumentException('标题和正文不能为空。');
    }
    if (!isset(self::CATEGORIES[$category])) {
      throw new \InvalidArgumentException('通知分类无效。');
    }
    if (!in_array($audience, self::AUDIENCES, TRUE)) {
      throw new \InvalidArgumentException('接收对象无效。');
    }
    $uids = array_values(array_unique(array_filter(array_map('intval', $data['audience_uids'] ?? []))));
    if ($audience === 'uids' && !$uids) {
      throw new \InvalidArgumentException('请指定至少一个会员。');
    }
    $now = $this->time->getRequestTime();
    return (int) $this->database->insert('xedc_official_campaign')
      ->fields([
        'title' => $title,
        'body' => $body,
        'category' => $category,
        'audience' => $audience,
        'audience_uids' => $uids ? implode(',', $uids) : '',
        'important' => !empty($data['important']) ? 1 : 0,
        'pinned' => !empty($data['pinned']) ? 1 : 0,
        'status' => 'draft',
        'created_by' => (int) ($data['created_by'] ?? 0),
        'created' => $now,
        'queued_at' => 0,
        'sent_at' => 0,
        'planned_count' => 0,
        'success_count' => 0,
        'fail_count' => 0,
        'last_error' => '',
        'cursor_uid' => 0,
      ])
      ->execute();
  }

  public function load(int $cid): ?object {
    $row = $this->database->select('xedc_official_campaign', 'c')
      ->fields('c')
      ->condition('cid', $cid)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * @return list<object>
   */
  public function listCampaigns(int $limit = 50): array {
    return $this->database->select('xedc_official_campaign', 'c')
      ->fields('c')
      ->orderBy('cid', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Server-side recipient count for preview.
   */
  public function countRecipients(object $campaign): int {
    $audience = (string) $campaign->audience;
    if ($audience === 'all') {
      return (int) $this->database->select('users_field_data', 'u')
        ->condition('u.uid', 0, '>')
        ->condition('u.status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    if ($audience === 'uids') {
      $all = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $campaign->audience_uids)))));
      $count = 0;
      foreach ($all as $uid) {
        if ($this->identityActive($uid)) {
          $count++;
        }
      }
      return $count;
    }
    // member / vip: walk in pages without loading everything at once.
    $count = 0;
    $cursor = 0;
    while (TRUE) {
      $batch = $this->resolveRecipientUids($campaign, $cursor, 200);
      if (!$batch) {
        break;
      }
      $count += count($batch);
      $cursor = max($batch);
    }
    return $count;
  }

  /**
   * Queues a draft/failed/cancelled campaign for batch send.
   */
  public function queueSend(int $cid): void {
    $campaign = $this->load($cid);
    if (!$campaign) {
      throw new \InvalidArgumentException('通知不存在。');
    }
    if (!in_array($campaign->status, ['draft', 'failed', 'partial', 'cancelled'], TRUE)) {
      throw new \InvalidArgumentException('当前状态不能发送。');
    }
    $planned = $this->countRecipients($campaign);
    $now = $this->time->getRequestTime();
    $this->database->update('xedc_official_campaign')
      ->fields([
        'status' => 'queued',
        'queued_at' => $now,
        'planned_count' => $planned,
        'success_count' => 0,
        'fail_count' => 0,
        'last_error' => '',
        'cursor_uid' => 0,
        'sent_at' => 0,
      ])
      ->condition('cid', $cid)
      ->execute();
    $this->queueFactory->get('xedc_official_notify')->createItem([
      'cid' => $cid,
    ]);
  }

  public function cancel(int $cid): void {
    $campaign = $this->load($cid);
    if (!$campaign) {
      throw new \InvalidArgumentException('通知不存在。');
    }
    if (!in_array($campaign->status, ['draft', 'queued', 'sending'], TRUE)) {
      throw new \InvalidArgumentException('当前状态不能取消。');
    }
    $this->database->update('xedc_official_campaign')
      ->fields(['status' => 'cancelled'])
      ->condition('cid', $cid)
      ->condition('status', ['draft', 'queued', 'sending'], 'IN')
      ->execute();
  }

  /**
   * Processes one batch. Called by the queue worker.
   *
   * @return string
   *   done|more|cancelled
   */
  public function processBatch(int $cid): string {
    $campaign = $this->load($cid);
    if (!$campaign) {
      return 'done';
    }
    if ($campaign->status === 'cancelled') {
      return 'cancelled';
    }
    if (!in_array($campaign->status, ['queued', 'sending'], TRUE)) {
      return 'done';
    }
    $batch = (int) $this->configFactory->get('xedc_notify.settings')->get('official_batch_size');
    if ($batch < 1) {
      $batch = 50;
    }
    // Do not revive a cancelled campaign if cancel won the race.
    $claimed = (int) $this->database->update('xedc_official_campaign')
      ->fields(['status' => 'sending'])
      ->condition('cid', $cid)
      ->condition('status', ['queued', 'sending'], 'IN')
      ->execute();
    if ($claimed < 1) {
      $fresh = $this->load($cid);
      return ($fresh && $fresh->status === 'cancelled') ? 'cancelled' : 'done';
    }

    $uids = $this->resolveRecipientUids($campaign, (int) $campaign->cursor_uid, $batch);
    if (!$uids) {
      $final = ((int) $campaign->fail_count > 0 && (int) $campaign->success_count > 0)
        ? 'partial'
        : (((int) $campaign->fail_count > 0) ? 'failed' : 'sent');
      $this->database->update('xedc_official_campaign')
        ->fields([
          'status' => $final,
          'sent_at' => $this->time->getRequestTime(),
        ])
        ->condition('cid', $cid)
        ->condition('status', ['queued', 'sending'], 'IN')
        ->execute();
      return 'done';
    }

    $success = 0;
    $fail = 0;
    $skipped = 0;
    $lastUid = (int) $campaign->cursor_uid;
    $lastError = '';
    $audience = (string) $campaign->audience;
    foreach ($uids as $uid) {
      $lastUid = $uid;
      // Stop early if an admin cancelled during this batch.
      $live = $this->load($cid);
      if ($live && $live->status === 'cancelled') {
        break;
      }
      try {
        if (!$this->stillEligibleRecipient($uid, $audience)) {
          $skipped++;
          continue;
        }
        $id = $this->notifications->create([
          'uid' => $uid,
          'category' => NotificationManager::CATEGORY_OFFICIAL,
          'type' => (string) $campaign->category,
          'title' => (string) $campaign->title,
          'body' => (string) $campaign->body,
          'route_name' => 'xedc_notify.notifications_category',
          'route_params' => ['category' => 'official'],
          'dedupe_key' => 'official:' . $cid . ':' . $uid,
          'important' => (bool) $campaign->important || in_array($campaign->category, ['security', 'moderation', 'account_status'], TRUE),
          'pinned' => (bool) $campaign->pinned,
          'campaign_id' => $cid,
        ]);
        // Deduped rows still count as success for idempotent retries.
        unset($id);
        $success++;
      }
      catch (\Throwable $e) {
        $fail++;
        $lastError = mb_substr($e->getMessage(), 0, 255);
      }
    }

    $this->database->update('xedc_official_campaign')
      ->fields([
        'cursor_uid' => $lastUid,
        'last_error' => $lastError,
      ])
      ->expression('success_count', 'success_count + :s', [':s' => $success])
      ->expression('fail_count', 'fail_count + :f', [':f' => $fail])
      ->condition('cid', $cid)
      ->condition('status', ['queued', 'sending'], 'IN')
      ->execute();

    // Re-check cancel between batches.
    $fresh = $this->load($cid);
    if ($fresh && $fresh->status === 'cancelled') {
      return 'cancelled';
    }
    // If the whole batch was skipped (e.g. users became inactive), continue.
    unset($skipped);
    return 'more';
  }

  /**
   * Re-validates recipient eligibility at send time.
   */
  protected function stillEligibleRecipient(int $uid, string $audience): bool {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface || !$account->isActive()) {
      return FALSE;
    }
    if ($audience === 'vip') {
      return $this->membership->isActive($account);
    }
    if ($audience === 'member') {
      return !$this->membership->isActive($account);
    }
    return TRUE;
  }

  /**
   * Creates a single system official notification for one user (security etc.).
   */
  public function notifyUser(int $uid, string $category, string $title, string $body, string $dedupeKey, string $routeName = 'xedc_member.security', array $routeParams = []): int {
    if (!isset(self::CATEGORIES[$category])) {
      $category = 'announcement';
    }
    $important = in_array($category, ['security', 'moderation', 'account_status'], TRUE);
    return $this->notifications->create([
      'uid' => $uid,
      'category' => NotificationManager::CATEGORY_OFFICIAL,
      'type' => $category,
      'title' => $title,
      'body' => $body,
      'route_name' => $routeName,
      'route_params' => $routeParams,
      'dedupe_key' => $dedupeKey,
      'important' => $important,
    ]);
  }

  /**
   * @return list<int>
   */
  protected function resolveRecipientUids(object $campaign, int $afterUid, int $limit): array {
    $audience = (string) $campaign->audience;
    if ($audience === 'uids') {
      $all = array_values(array_filter(array_map('intval', explode(',', (string) $campaign->audience_uids))));
      sort($all);
      $out = [];
      foreach ($all as $uid) {
        if ($uid <= $afterUid) {
          continue;
        }
        if ($this->identityActive($uid)) {
          $out[] = $uid;
        }
        if (count($out) >= $limit) {
          break;
        }
      }
      return $out;
    }

    $query = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->condition('u.uid', 0, '>')
      ->condition('u.status', 1)
      ->condition('u.uid', $afterUid, '>')
      ->orderBy('u.uid', 'ASC')
      ->range(0, $limit > 100000 ? 100000 : $limit);

    // For VIP/member we may need more rows to filter; scan in pages.
    if ($audience === 'all') {
      return array_map('intval', $query->execute()->fetchCol());
    }

    $matched = [];
    $cursor = $afterUid;
    while (count($matched) < $limit) {
      $batch = $this->database->select('users_field_data', 'u')
        ->fields('u', ['uid'])
        ->condition('u.uid', 0, '>')
        ->condition('u.status', 1)
        ->condition('u.uid', $cursor, '>')
        ->orderBy('u.uid', 'ASC')
        ->range(0, 200)
        ->execute()
        ->fetchCol();
      if (!$batch) {
        break;
      }
      foreach (array_map('intval', $batch) as $uid) {
        $cursor = $uid;
        $account = $this->entityTypeManager->getStorage('user')->load($uid);
        if (!$account instanceof UserInterface) {
          continue;
        }
        $isVip = $this->membership->isActive($account);
        if ($audience === 'vip' && $isVip) {
          $matched[] = $uid;
        }
        elseif ($audience === 'member' && !$isVip) {
          $matched[] = $uid;
        }
        if (count($matched) >= $limit) {
          break;
        }
      }
    }
    return $matched;
  }

  protected function identityActive(int $uid): bool {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account instanceof UserInterface && $account->isActive();
  }

}
