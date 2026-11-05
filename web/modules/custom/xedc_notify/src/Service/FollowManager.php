<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Member-to-member follow relationships.
 */
final class FollowManager {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected MemberIdentity $identity,
  ) {}

  public function isFollowing(int $followerUid, int $followingUid): bool {
    if ($followerUid < 1 || $followingUid < 1 || $followerUid === $followingUid) {
      return FALSE;
    }
    return (bool) $this->database->select('xedc_follow', 'f')
      ->condition('follower_uid', $followerUid)
      ->condition('following_uid', $followingUid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * @return int
   *   New follow ID, or 0 when the relationship already existed.
   */
  public function follow(int $followerUid, int $followingUid): int {
    if ($followerUid < 1 || $followingUid < 1) {
      throw new \InvalidArgumentException('关注对象无效。');
    }
    if ($followerUid === $followingUid) {
      throw new \InvalidArgumentException('不能关注自己。');
    }
    if (!$this->identity->load($followerUid)) {
      throw new \InvalidArgumentException('当前账号不可用。');
    }
    if (!$this->identity->load($followingUid)) {
      throw new \InvalidArgumentException('对方会员不存在。');
    }
    try {
      return (int) $this->database->insert('xedc_follow')
        ->fields([
          'follower_uid' => $followerUid,
          'following_uid' => $followingUid,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      return 0;
    }
  }

  public function unfollow(int $followerUid, int $followingUid): bool {
    $deleted = (int) $this->database->delete('xedc_follow')
      ->condition('follower_uid', $followerUid)
      ->condition('following_uid', $followingUid)
      ->execute();
    return $deleted > 0;
  }

  public function followerCount(int $uid): int {
    return (int) $this->database->select('xedc_follow', 'f')
      ->condition('following_uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function followingCount(int $uid): int {
    return (int) $this->database->select('xedc_follow', 'f')
      ->condition('follower_uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
