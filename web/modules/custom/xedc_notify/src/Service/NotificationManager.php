<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Url;

/**
 * Creates and manages per-member in-app notifications.
 */
final class NotificationManager {

  public const CATEGORY_SOCIAL = 'social';
  public const CATEGORY_VIP = 'vip';
  public const CATEGORY_OFFICIAL = 'official';

  public const PER_PAGE = 20;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected MemberIdentity $identity,
  ) {}

  /**
   * Creates a notification. Returns 0 when skipped or deduped.
   *
   * @param array{
   *   uid: int,
   *   category: string,
   *   type: string,
   *   title: string,
   *   body?: string,
   *   actor_uid?: int,
   *   route_name?: string,
   *   route_params?: array<string, mixed>,
   *   entity_type?: string,
   *   entity_id?: int,
   *   dedupe_key: string,
   *   important?: bool,
   *   pinned?: bool,
   *   campaign_id?: int
   * } $data
   */
  public function create(array $data): int {
    $uid = (int) ($data['uid'] ?? 0);
    if ($uid < 1 || !$this->identity->load($uid)) {
      return 0;
    }
    $dedupe = mb_substr(trim((string) ($data['dedupe_key'] ?? '')), 0, 191);
    if ($dedupe === '') {
      throw new \InvalidArgumentException('通知去重键不能为空。');
    }
    $now = $this->time->getRequestTime();
    $fields = [
      'uid' => $uid,
      'category' => mb_substr((string) ($data['category'] ?? self::CATEGORY_SOCIAL), 0, 16),
      'type' => mb_substr((string) ($data['type'] ?? ''), 0, 32),
      'actor_uid' => (int) ($data['actor_uid'] ?? 0),
      'title' => mb_substr(strip_tags((string) ($data['title'] ?? '')), 0, 255),
      'body' => isset($data['body']) ? strip_tags((string) $data['body']) : '',
      'route_name' => mb_substr((string) ($data['route_name'] ?? ''), 0, 128),
      'route_params' => !empty($data['route_params']) ? json_encode($data['route_params'], JSON_UNESCAPED_UNICODE) : '',
      'entity_type' => mb_substr((string) ($data['entity_type'] ?? ''), 0, 32),
      'entity_id' => (int) ($data['entity_id'] ?? 0),
      'dedupe_key' => $dedupe,
      'important' => !empty($data['important']) ? 1 : 0,
      'pinned' => !empty($data['pinned']) ? 1 : 0,
      'is_read' => 0,
      'deleted' => 0,
      'campaign_id' => (int) ($data['campaign_id'] ?? 0),
      'created' => $now,
      'read_at' => 0,
    ];
    if ($fields['title'] === '') {
      throw new \InvalidArgumentException('通知标题不能为空。');
    }
    try {
      return (int) $this->database->insert('xedc_notification')->fields($fields)->execute();
    }
    catch (IntegrityConstraintViolationException) {
      return 0;
    }
  }

  public function unreadCount(int $uid, ?string $category = NULL): int {
    if ($uid < 1) {
      return 0;
    }
    $query = $this->database->select('xedc_notification', 'n')
      ->condition('uid', $uid)
      ->condition('deleted', 0)
      ->condition('is_read', 0);
    if ($category) {
      $query->condition('category', $category);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * @return list<object>
   */
  public function listForUser(int $uid, ?string $category = NULL, int $limit = self::PER_PAGE): array {
    $query = $this->database->select('xedc_notification', 'n')
      ->fields('n')
      ->condition('uid', $uid)
      ->condition('deleted', 0);
    if ($category && in_array($category, [self::CATEGORY_SOCIAL, self::CATEGORY_VIP, self::CATEGORY_OFFICIAL], TRUE)) {
      $query->condition('category', $category);
    }
    $query->orderBy('pinned', 'DESC');
    $query->orderBy('created', 'DESC');
    $query = $query->extend(PagerSelectExtender::class);
    $query->limit($limit);
    $rows = $query->execute()->fetchAll();
    foreach ($rows as $row) {
      $row->url = $this->buildUrl($row);
      $row->actor_name = $row->actor_uid ? $this->identity->displayNameByUid((int) $row->actor_uid) : '';
      $row->actor_avatar = $row->actor_uid ? $this->identity->avatarUrl((int) $row->actor_uid) : '';
    }
    return $rows;
  }

  public function markRead(int $uid, int $id): bool {
    $now = $this->time->getRequestTime();
    $updated = (int) $this->database->update('xedc_notification')
      ->fields(['is_read' => 1, 'read_at' => $now])
      ->condition('id', $id)
      ->condition('uid', $uid)
      ->condition('deleted', 0)
      ->condition('is_read', 0)
      ->execute();
    return $updated > 0;
  }

  public function markAllRead(int $uid, ?string $category = NULL): int {
    $now = $this->time->getRequestTime();
    $query = $this->database->update('xedc_notification')
      ->fields(['is_read' => 1, 'read_at' => $now])
      ->condition('uid', $uid)
      ->condition('deleted', 0)
      ->condition('is_read', 0);
    if ($category) {
      $query->condition('category', $category);
    }
    return (int) $query->execute();
  }

  public function softDelete(int $uid, int $id): bool {
    $updated = (int) $this->database->update('xedc_notification')
      ->fields(['deleted' => 1])
      ->condition('id', $id)
      ->condition('uid', $uid)
      ->condition('deleted', 0)
      ->execute();
    return $updated > 0;
  }

  public function loadForUser(int $uid, int $id): ?object {
    $row = $this->database->select('xedc_notification', 'n')
      ->fields('n')
      ->condition('id', $id)
      ->condition('uid', $uid)
      ->condition('deleted', 0)
      ->execute()
      ->fetchObject();
    if ($row) {
      $row->url = $this->buildUrl($row);
    }
    return $row ?: NULL;
  }

  public function campaignReadCount(int $campaignId): int {
    if ($campaignId < 1) {
      return 0;
    }
    return (int) $this->database->select('xedc_notification', 'n')
      ->condition('campaign_id', $campaignId)
      ->condition('is_read', 1)
      ->condition('deleted', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function campaignUnreadCount(int $campaignId): int {
    if ($campaignId < 1) {
      return 0;
    }
    return (int) $this->database->select('xedc_notification', 'n')
      ->condition('campaign_id', $campaignId)
      ->condition('is_read', 0)
      ->condition('deleted', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  protected function buildUrl(object $row): string {
    $route = (string) $row->route_name;
    if ($route === '') {
      return '';
    }
    $params = [];
    if (!empty($row->route_params)) {
      $decoded = json_decode((string) $row->route_params, TRUE);
      if (is_array($decoded)) {
        $params = $decoded;
      }
    }
    try {
      return Url::fromRoute($route, $params)->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
