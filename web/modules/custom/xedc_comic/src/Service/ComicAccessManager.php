<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Engagement\MembershipManager;

/**
 * Chapter and page access, including VIP checks.
 */
final class ComicAccessManager {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entities,
    private readonly MembershipManager $membership,
  ) {}

  public function canViewComic(NodeInterface $comic): bool {
    if ($comic->bundle() !== 'comic') {
      return FALSE;
    }
    if (!$comic->isPublished()) {
      return $this->canAdmin();
    }
    if (!$this->currentUser->hasPermission('view published comics')) {
      return $this->canAdmin();
    }
    return TRUE;
  }

  public function canViewChapter(NodeInterface $chapter): bool {
    if ($chapter->bundle() !== 'comic_chapter') {
      return FALSE;
    }
    $parent = $this->parentComic($chapter);
    if (!$parent || !$this->canViewComic($parent)) {
      return FALSE;
    }
    $status = $chapter->hasField('field_comic_chapter_status')
      ? (string) $chapter->get('field_comic_chapter_status')->value
      : 'published';
    if ($status !== 'published' && !$this->canAdmin()) {
      return FALSE;
    }
    if (!$chapter->isPublished() && !$this->canAdmin()) {
      return FALSE;
    }
    if ($this->chapterIsVip($chapter) && !$this->chapterIsFree($chapter)) {
      return $this->membership->isActive($this->currentUser) || $this->canAdmin();
    }
    return TRUE;
  }

  public function chapterIsVip(NodeInterface $chapter): bool {
    return $chapter->hasField('field_comic_vip') && (bool) $chapter->get('field_comic_vip')->value;
  }

  public function chapterIsFree(NodeInterface $chapter): bool {
    return $chapter->hasField('field_comic_free') && (bool) $chapter->get('field_comic_free')->value;
  }

  public function parentComic(NodeInterface $chapter): ?NodeInterface {
    if (!$chapter->hasField('field_comic_parent') || $chapter->get('field_comic_parent')->isEmpty()) {
      return NULL;
    }
    $parent = $chapter->get('field_comic_parent')->entity;
    return $parent instanceof NodeInterface ? $parent : NULL;
  }

  public function canAdmin(): bool {
    return $this->currentUser->hasPermission('administer comics')
      || $this->currentUser->hasPermission('manage comic chapters');
  }

  public function canEditComic(NodeInterface $comic): bool {
    if (!$this->currentUser->hasPermission('edit any comics')) {
      if (!$this->currentUser->hasPermission('edit own comics')) {
        return FALSE;
      }
      return (int) $comic->getOwnerId() === (int) $this->currentUser->id();
    }
    return TRUE;
  }

}