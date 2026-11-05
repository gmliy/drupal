<?php

declare(strict_types=1);

namespace Drupal\xedc_tags;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Points menu.active_trail at TagMenuActiveTrail for tag admin routes.
 */
final class XedcTagsServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('menu.active_trail')) {
      // Custom class has no generated ProxyClass; disable lazy to avoid rebuild noise.
      $container->getDefinition('menu.active_trail')
        ->setClass('Drupal\xedc_tags\Menu\TagMenuActiveTrail')
        ->setLazy(FALSE);
    }
  }

}
