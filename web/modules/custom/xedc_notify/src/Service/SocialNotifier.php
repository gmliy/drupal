<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\comment\CommentInterface;
use Drupal\node\NodeInterface;

/**
 * Creates social notifications from real server-side interactions.
 */
final class SocialNotifier {

  public function __construct(
    protected NotificationManager $notifications,
    protected PreferenceManager $preferences,
    protected MemberIdentity $identity,
  ) {}

  public function onFollow(int $actorUid, int $targetUid, int $followId = 0): void {
    if ($actorUid < 1 || $targetUid < 1 || $actorUid === $targetUid || $followId < 1) {
      return;
    }
    if (!$this->preferences->isEnabled($targetUid, 'follow')) {
      return;
    }
    $name = $this->identity->displayNameByUid($actorUid);
    $route = $this->publicProfileRoute($actorUid);
    $this->notifications->create([
      'uid' => $targetUid,
      'category' => NotificationManager::CATEGORY_SOCIAL,
      'type' => 'follow',
      'actor_uid' => $actorUid,
      'title' => $name . ' 关注了你',
      'route_name' => $route['route_name'],
      'route_params' => $route['route_params'],
      'entity_type' => 'user',
      'entity_id' => $actorUid,
      'dedupe_key' => 'follow:' . $followId,
    ]);
  }

  public function onLike(int $actorUid, NodeInterface $node): void {
    if ($actorUid < 1 || !$node->id()) {
      return;
    }
    $owner = (int) $node->getOwnerId();
    if ($owner < 1 || $owner === $actorUid) {
      return;
    }
    $bundle = $node->bundle();
    $prefKey = match ($bundle) {
      'photo' => 'like_photo',
      'article' => 'like_article',
      'video' => 'like_video',
      default => '',
    };
    if ($prefKey === '' || !$this->preferences->isEnabled($owner, $prefKey)) {
      return;
    }
    $label = match ($bundle) {
      'photo' => '图片',
      'article' => '文章',
      'video' => '视频',
      default => '作品',
    };
    $name = $this->identity->displayNameByUid($actorUid);
    $this->notifications->create([
      'uid' => $owner,
      'category' => NotificationManager::CATEGORY_SOCIAL,
      'type' => 'like_' . $bundle,
      'actor_uid' => $actorUid,
      'title' => $name . ' 赞了你的' . $label,
      'route_name' => 'entity.node.canonical',
      'route_params' => ['node' => (int) $node->id()],
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'dedupe_key' => 'like:' . $bundle . ':' . (int) $node->id() . ':' . $actorUid,
    ]);
  }

  public function onFavorite(int $actorUid, NodeInterface $node): void {
    if ($actorUid < 1 || !$node->id()) {
      return;
    }
    $owner = (int) $node->getOwnerId();
    if ($owner < 1 || $owner === $actorUid) {
      return;
    }
    if (!$this->preferences->isEnabled($owner, 'favorite')) {
      return;
    }
    $name = $this->identity->displayNameByUid($actorUid);
    $this->notifications->create([
      'uid' => $owner,
      'category' => NotificationManager::CATEGORY_SOCIAL,
      'type' => 'favorite',
      'actor_uid' => $actorUid,
      'title' => $name . ' 收藏了你的公开作品',
      'route_name' => 'entity.node.canonical',
      'route_params' => ['node' => (int) $node->id()],
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'dedupe_key' => 'favorite:node:' . (int) $node->id() . ':' . $actorUid,
    ]);
  }

  public function onComment(CommentInterface $comment): void {
    if (!$comment->isPublished() || !(int) $comment->id()) {
      return;
    }
    $actorUid = (int) $comment->getOwnerId();
    if ($actorUid < 1) {
      return;
    }
    $entity = $comment->getCommentedEntity();
    if (!$entity instanceof NodeInterface || !(int) $entity->id()) {
      return;
    }
    $name = $this->identity->displayNameByUid($actorUid);
    $parent = NULL;
    try {
      $parent = $comment->getParentComment();
    }
    catch (\Throwable) {
      $parent = NULL;
    }
    if ($parent instanceof CommentInterface) {
      $parentOwner = (int) $parent->getOwnerId();
      if ($parentOwner > 0 && $parentOwner !== $actorUid && $this->preferences->isEnabled($parentOwner, 'comment_reply')) {
        $this->notifications->create([
          'uid' => $parentOwner,
          'category' => NotificationManager::CATEGORY_SOCIAL,
          'type' => 'comment_reply',
          'actor_uid' => $actorUid,
          'title' => $name . ' 回复了你的评论',
          'route_name' => 'entity.node.canonical',
          'route_params' => ['node' => (int) $entity->id()],
          'entity_type' => 'comment',
          'entity_id' => (int) $comment->id(),
          'dedupe_key' => 'comment_reply:' . (int) $comment->id(),
        ]);
      }
    }
    // Notify content owner for top-level photo comments.
    $owner = (int) $entity->getOwnerId();
    if ($owner < 1 || $owner === $actorUid) {
      return;
    }
    if ($entity->bundle() !== 'photo') {
      return;
    }
    if ($parent instanceof CommentInterface) {
      // Owner already covered if they are the parent author; still notify owner
      // on replies to others' comments under their photo when preferred.
      if ($parent->getOwnerId() == $owner) {
        return;
      }
    }
    if (!$this->preferences->isEnabled($owner, 'comment_photo')) {
      return;
    }
    $this->notifications->create([
      'uid' => $owner,
      'category' => NotificationManager::CATEGORY_SOCIAL,
      'type' => 'comment_photo',
      'actor_uid' => $actorUid,
      'title' => $name . ' 评论了你的图片',
      'route_name' => 'entity.node.canonical',
      'route_params' => ['node' => (int) $entity->id()],
      'entity_type' => 'comment',
      'entity_id' => (int) $comment->id(),
      'dedupe_key' => 'comment_photo:' . (int) $comment->id(),
    ]);
  }

  /**
   * @return array{route_name: string, route_params: array<string, string>}
   */
  protected function publicProfileRoute(int $uid): array {
    $path = $this->identity->publicProfileUrl($uid);
    if (preg_match('#^/r/([^/]+)$#', $path, $m)) {
      return [
        'route_name' => 'xedc_member.public_profile',
        'route_params' => ['slug' => $m[1]],
      ];
    }
    return ['route_name' => '', 'route_params' => []];
  }

}
