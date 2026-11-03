<?php

declare(strict_types=1);

namespace Drupal\xedc_core\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Lets the core image style controller deliver local public derivatives.
 *
 * When public files are on object storage, s3fs locks the public scheme out
 * of the core derivative route. Derivatives for this site are generated on
 * the local disk, so that lockdown must not apply to public styles.
 */
final class ImageStyleRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after s3fs adds the lockdown routes.
    return [RoutingEvents::ALTER => ['onAlterRoutes', -200]];
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $name => $route) {
      if (!str_ends_with($name, '_s3fs_lockdown_public')) {
        continue;
      }
      if (!str_contains($route->getPath(), '/styles/')) {
        continue;
      }
      $collection->remove($name);
    }
  }

}
