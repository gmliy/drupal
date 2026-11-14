<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Admin access for comic pages. Public reads go through the reader API.
 */
class ComicPageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'manage comic pages')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'administer comics'));
    }
    return AccessResult::allowedIfHasPermission($account, 'manage comic pages')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer comics'));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'manage comic pages')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer comics'));
  }

}