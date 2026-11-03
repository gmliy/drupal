<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonymous like identity. The cookie holds the secret; storage only gets a hash.
 */
final class GuestIdentity {

  public const COOKIE = 'xedc_guest';

  public const TTL = 15552000;

  private ?string $issued = NULL;

  public function __construct(
    protected RequestStack $requestStack,
    protected TimeInterface $time,
  ) {}

  /**
   * Hash for a cookie this browser already has. Empty when there is no guest yet.
   */
  public function existingHash(): string {
    $secret = $this->readSecret();
    return $secret === '' ? '' : hash('sha256', $secret);
  }

  /**
   * Hash for a like. Issues a new secret only when this browser has none.
   */
  public function issueHash(): string {
    $existing = $this->existingHash();
    if ($existing !== '') {
      return $existing;
    }
    if ($this->issued === NULL) {
      $this->issued = bin2hex(random_bytes(32));
    }
    return hash('sha256', $this->issued);
  }

  public function apply(Response $response): void {
    if ($this->issued === NULL) {
      return;
    }
    $request = $this->requestStack->getCurrentRequest();
    $secure = $request instanceof Request && $request->isSecure();
    $response->headers->setCookie(Cookie::create(
      self::COOKIE,
      $this->issued,
      $this->time->getRequestTime() + self::TTL,
      '/',
      NULL,
      $secure,
      TRUE,
      FALSE,
      Cookie::SAMESITE_LAX
    ));
  }

  private function readSecret(): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request) {
      return '';
    }
    $secret = $request->cookies->get(self::COOKIE);
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/', $secret)) {
      return '';
    }
    return $secret;
  }

}
