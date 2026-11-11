<?php

namespace Drupal\xedc_status\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Skips the page cache while maintenance mode is on.
 *
 * Cached pages would otherwise keep returning HTTP 200. The request then
 * reaches Drupal, which still allows the login routes through.
 */
final class MaintenanceRequestPolicy implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    try {
      if (\Drupal::hasContainer() && \Drupal::state()->get('system.maintenance_mode')) {
        return static::DENY;
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return NULL;
  }

}
