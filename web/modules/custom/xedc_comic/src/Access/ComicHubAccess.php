<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Comic hub visibility for admin overview and menu-related routes.
 */
final class ComicHubAccess implements AccessInterface {

  /**
   * Permissions that grant access to the comic admin overview.
   *
   * @var list<string>
   */
  private const HUB_PERMS = [
    'administer comics',
    'create comics',
    'edit own comics',
    'edit any comics',
    'manage comic chapters',
    'manage comic pages',
    'import comic archives',
    'view comic statistics',
  ];

  public function access(AccountInterface $account): AccessResult {
    foreach (self::HUB_PERMS as $permission) {
      if ($account->hasPermission($permission)) {
        return AccessResult::allowed()->cachePerPermissions();
      }
    }
    return AccessResult::forbidden()->cachePerPermissions();
  }

}