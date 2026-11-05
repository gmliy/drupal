<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Per-member social notification preference switches.
 */
final class PreferenceManager {

  public const KEYS = [
    'follow',
    'like_photo',
    'like_article',
    'like_video',
    'comment_photo',
    'comment_reply',
    'favorite',
  ];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, bool>
   */
  public function get(int $uid): array {
    $defaults = $this->defaults();
    if ($uid < 1) {
      return $defaults;
    }
    $row = $this->database->select('xedc_notification_pref', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return $defaults;
    }
    $out = [];
    foreach (self::KEYS as $key) {
      $out[$key] = (bool) ($row[$key] ?? $defaults[$key]);
    }
    return $out;
  }

  public function isEnabled(int $uid, string $key): bool {
    // Personal preference UI was removed. Social notifications follow system
    // defaults for everyone; historical rows in xedc_notification_pref are kept
    // but no longer consulted for delivery.
    if (!in_array($key, self::KEYS, TRUE)) {
      return TRUE;
    }
    return $this->defaults()[$key];
  }

  /**
   * @param array<string, bool|int> $values
   */
  public function save(int $uid, array $values): void {
    if ($uid < 1) {
      throw new \InvalidArgumentException('无效会员。');
    }
    $fields = ['uid' => $uid, 'changed' => $this->time->getRequestTime()];
    foreach (self::KEYS as $key) {
      $fields[$key] = !empty($values[$key]) ? 1 : 0;
    }
    $this->database->merge('xedc_notification_pref')
      ->keys(['uid' => $uid])
      ->fields($fields)
      ->execute();
  }

  /**
   * @return array<string, bool>
   */
  public function defaults(): array {
    $favoriteDefault = (bool) $this->configFactory->get('xedc_notify.settings')->get('favorite_notify_default');
    return [
      'follow' => TRUE,
      'like_photo' => TRUE,
      'like_article' => TRUE,
      'like_video' => TRUE,
      'comment_photo' => TRUE,
      'comment_reply' => TRUE,
      'favorite' => $favoriteDefault,
    ];
  }

  /**
   * Human labels for preference form.
   *
   * @return array<string, array{label: string, description: string}>
   */
  public function labels(): array {
    return [
      'follow' => [
        'label' => '新粉丝通知',
        'description' => '有会员关注你时通知。',
      ],
      'like_photo' => [
        'label' => '图片点赞通知',
        'description' => '有人赞了你的图片时通知。',
      ],
      'like_article' => [
        'label' => '文章点赞通知',
        'description' => '有人赞了你的文章时通知。',
      ],
      'like_video' => [
        'label' => '视频点赞通知',
        'description' => '有人赞了你的视频时通知。',
      ],
      'comment_photo' => [
        'label' => '图片评论通知',
        'description' => '有人评论了你的图片时通知。',
      ],
      'comment_reply' => [
        'label' => '评论回复通知',
        'description' => '有人回复了你的评论时通知。',
      ],
      'favorite' => [
        'label' => '收藏作品通知',
        'description' => '有人收藏了你的公开作品时通知。关闭后不影响收藏功能本身。',
      ],
    ];
  }

}
