<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Download resources are administered. Public delivery uses its own route.
 */
final class DownloadResourceAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $allowed = AccessResult::allowedIfHasPermission($account, 'administer xedc downloads');
    return $allowed->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'administer xedc downloads')->cachePerPermissions();
  }

}
