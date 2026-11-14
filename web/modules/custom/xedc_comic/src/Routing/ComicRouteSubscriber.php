<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Sets node upcasting for comic routes.
 */
final class ComicRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['xedc_comic.detail', 'xedc_comic.reader', 'xedc_comic.api_pages'] as $name) {
      if ($route = $collection->get($name)) {
        $route->setOption('parameters', [
          'comic' => ['type' => 'entity:node', 'bundle' => ['comic']],
          'chapter' => ['type' => 'entity:node', 'bundle' => ['comic_chapter']],
        ]);
      }
    }
  }

}