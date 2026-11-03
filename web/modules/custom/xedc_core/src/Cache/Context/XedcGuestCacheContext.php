<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\xedc_core\Engagement\GuestIdentity;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Varies anonymous like state without putting the guest secret in the cache id.
 */
final class XedcGuestCacheContext implements CacheContextInterface {

  public function __construct(
    protected RequestStack $requestStack,
    protected AccountProxyInterface $currentUser,
  ) {}

  public static function getLabel() {
    return t('XEDC guest');
  }

  public function getContext(): string {
    if ($this->currentUser->isAuthenticated()) {
      return 'member';
    }
    $request = $this->requestStack->getCurrentRequest();
    $secret = $request ? (string) $request->cookies->get(GuestIdentity::COOKIE, '') : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $secret)) {
      return 'none';
    }
    return substr(hash('sha256', $secret), 0, 12);
  }

  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
