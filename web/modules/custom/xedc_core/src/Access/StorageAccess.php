<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Denies anonymous access to protected object downloads.
 */
final class StorageAccess {

  public function download(AccountInterface $account): AccessResultInterface {
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden('匿名用户不能访问受保护文件。')->cachePerUser();
    }
    return AccessResult::allowed()->cachePerUser();
  }

}
