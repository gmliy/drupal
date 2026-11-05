<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\UserInterface;
use Drupal\xedc_member\Service\MemberAliasManager;

/**
 * Resolves safe display names, avatars and public profile URLs.
 */
final class MemberIdentity {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MemberAliasManager $aliasManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public function load(int $uid): ?UserInterface {
    if ($uid < 1) {
      return NULL;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account instanceof UserInterface && $account->isActive() ? $account : NULL;
  }

  public function displayName(UserInterface $account): string {
    if ($account->hasField('field_xedc_nickname') && !$account->get('field_xedc_nickname')->isEmpty()) {
      $nickname = trim((string) $account->get('field_xedc_nickname')->value);
      if ($nickname !== '') {
        return $nickname;
      }
    }
    $name = trim((string) $account->getDisplayName());
    return $name !== '' ? $name : '会员';
  }

  public function displayNameByUid(int $uid): string {
    $account = $this->load($uid);
    return $account ? $this->displayName($account) : '会员';
  }

  public function avatarUrl(int $uid): string {
    $account = $this->load($uid);
    if (!$account || !$account->hasField('user_picture') || $account->get('user_picture')->isEmpty()) {
      return '';
    }
    $file = $account->get('user_picture')->entity;
    if (!$file instanceof FileInterface) {
      return '';
    }
    $uri = $file->getFileUri();
    $style = ImageStyle::load('thumbnail') ?: ImageStyle::load('max_325x325');
    if ($style) {
      return $style->buildUrl($uri);
    }
    return $this->fileUrlGenerator->generateString($uri);
  }

  public function publicProfileUrl(int $uid): string {
    $path = $this->aliasManager->publicBase($uid);
    return $path !== '' ? $path : '/user/' . $uid;
  }

  public function initial(string $name): string {
    $name = trim($name);
    return $name !== '' ? mb_substr($name, 0, 1) : '?';
  }

}
