<?php

namespace Drupal\xedc_home\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the security headers that are not already set by the web server.
 *
 * X-Content-Type-Options and X-Frame-Options are already present. Framing
 * stays SAMEORIGIN so the same-site login dialog iframe keeps working.
 */
class SecurityHeaderSubscriber implements EventSubscriberInterface {

  /**
   * Sets Referrer-Policy, HSTS, and CSP when the response does not have them.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $headers = $event->getResponse()->headers;
    if (!$headers->has('Referrer-Policy')) {
      $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
    if ($event->getRequest()->isSecure() && !$headers->has('Strict-Transport-Security')) {
      $headers->set('Strict-Transport-Security', 'max-age=15552000');
    }
    if (!$headers->has('Content-Security-Policy')) {
      $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
    }
  }

  /**
   * Policy for the resources this site actually loads.
   *
   * Fonts are the system stack. CSS and JS are same-origin aggregates, plus
   * Matomo at x.gitxx.xyz and the Cloudflare Web Analytics beacon injected at
   * the edge. Inline scripts and styles stay allowed because Matomo, the
   * video embed button, Drupal behaviors, and CKEditor 5 rely on them.
   */
  private function contentSecurityPolicy(): string {
    $directives = [
      "default-src 'self'",
      "base-uri 'self'",
      "object-src 'none'",
      "frame-ancestors 'self'",
      "form-action 'self'",
      "script-src 'self' 'unsafe-inline' https://x.gitxx.xyz https://static.cloudflareinsights.com",
      "style-src 'self' 'unsafe-inline'",
      "img-src 'self' data: blob: https://gitxx.xyz https://www.gitxx.xyz https://x.gitxx.xyz https://cdn.gitxx.xyz",
      "font-src 'self' data:",
      "connect-src 'self' https://x.gitxx.xyz https://cloudflareinsights.com https://static.cloudflareinsights.com",
      "media-src 'self' blob: https://cdn.gitxx.xyz",
      "worker-src 'self' blob:",
      "frame-src 'self' https://gitxx.xyz https://www.gitxx.xyz https://www.youtube.com https://player.bilibili.com https://platform.twitter.com https://www.instagram.com https://google.com https://www.google.com",
    ];
    return implode('; ', $directives);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -20]];
  }

}
