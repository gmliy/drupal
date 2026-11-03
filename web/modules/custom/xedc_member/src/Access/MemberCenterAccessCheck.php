<?php

namespace Drupal\xedc_member\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\xedc_member\Service\MemberAliasManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Allows a vanity member URL only for the member who owns that slug.
 *
 * The route match itself is identical for every visitor. This check reads the
 * browser path, so /12shh/favorites and /dcxe/favorites do not share an allow
 * decision even though both resolve to the same internal route.
 */
class MemberCenterAccessCheck implements AccessInterface {

  /**
   * Constructs a MemberCenterAccessCheck.
   */
  public function __construct(
    protected MemberAliasManager $aliasManager,
  ) {}

  /**
   * Checks the original path against the current account.
   */
  public function access(AccountInterface $account, Request $request): AccessResult {
    $owner = $this->aliasManager->ownerForVanityPath($request->getPathInfo());
    if ($owner === NULL) {
      // Canonical /member paths are not vanity URLs. Neutral would erase the
      // login check, because AccessResult::andIf() is allowed only when every
      // check is allowed.
      return AccessResult::allowed()
        ->addCacheContexts(['user', 'url.path'])
        ->addCacheTags(['xedc_member:aliases']);
    }
    $allowed = (int) $account->id() === $owner;
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden();
    return $result
      ->addCacheContexts(['user', 'url.path'])
      ->addCacheTags(['xedc_member:aliases', 'user:' . $owner]);
  }

}
