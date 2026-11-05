<?php

declare(strict_types=1);

namespace Drupal\xedc_tags\Menu;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Menu\MenuActiveTrail;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Keeps「标签统计」active for all /admin/content/tags* routes.
 */
final class TagMenuActiveTrail extends MenuActiveTrail {

  public function __construct(
    MenuLinkManagerInterface $menu_link_manager,
    RouteMatchInterface $route_match,
    CacheBackendInterface $cache,
    LockBackendInterface $lock,
    ?PathMatcherInterface $pathMatcher = NULL,
  ) {
    parent::__construct($menu_link_manager, $route_match, $cache, $lock, $pathMatcher);
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveLink($menu_name = NULL): ?MenuLinkInterface {
    $link = parent::getActiveLink($menu_name);
    if ($link) {
      return $link;
    }

    $route = $this->routeMatch->getRouteName();
    if (!is_string($route) || !str_starts_with($route, 'xedc_tags.admin')) {
      return NULL;
    }

    // Gin sidebar uses the admin menu; leave other menus alone.
    if ($menu_name !== NULL && $menu_name !== 'admin') {
      return NULL;
    }

    if (!$this->menuLinkManager->hasDefinition('xedc_tags.admin')) {
      return NULL;
    }

    return $this->menuLinkManager->createInstance('xedc_tags.admin');
  }

}
