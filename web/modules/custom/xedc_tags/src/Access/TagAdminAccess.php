<?php

namespace Drupal\xedc_tags\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Limits tag admin routes to the tags vocabulary.
 */
final class TagAdminAccess {

  public function term(AccountInterface $account, TermInterface $taxonomy_term): AccessResult {
    if ($taxonomy_term->bundle() !== 'tags') {
      return AccessResult::forbidden()->addCacheableDependency($taxonomy_term);
    }
    return AccessResult::allowedIfHasPermission($account, 'view xedc tag center')
      ->addCacheableDependency($taxonomy_term);
  }

  public function delete(AccountInterface $account, TermInterface $taxonomy_term): AccessResult {
    if ($taxonomy_term->bundle() !== 'tags') {
      return AccessResult::forbidden()->addCacheableDependency($taxonomy_term);
    }
    return AccessResult::allowedIfHasPermission($account, 'delete xedc tags')
      ->addCacheableDependency($taxonomy_term);
  }

}
