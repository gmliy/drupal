<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Entry access for the operations overview.
 */
final class AiAccess {

  /**
   * Allows the overview when the account can use any AI operations page.
   */
  public function overview(AccountInterface $account): AccessResultInterface {
    $permissions = [
      'view xedc ai dashboard',
      'view xedc ai reviews',
      'view xedc ai usage',
      'administer xedc ai providers',
      'administer xedc ai',
      'execute xedc ai reviews',
      'administer xedc ai reviews',
      'administer xedc ai rules',
      'apply xedc ai changes',
      'view xedc ai logs',
      'moderate xedc ai submissions',
    ];
    foreach ($permissions as $permission) {
      if ($account->hasPermission($permission)) {
        return AccessResult::allowed()->cachePerPermissions();
      }
    }
    return AccessResult::forbidden()->cachePerPermissions();
  }

}
