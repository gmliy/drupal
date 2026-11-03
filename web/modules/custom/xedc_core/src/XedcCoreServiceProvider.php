<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream;

/**
 * Installs the public stream wrapper that keeps image styles local.
 *
 * This runs only while the service container is compiled. Changing an
 * environment variable does not change an already compiled container.
 */
class XedcCoreServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('stream_wrapper.public')) {
      return;
    }
    // The class stays stable. Local or R2 is chosen per operation from the
    // shared runtime file, so a later container compile cannot fall back to
    // PublicStream just because the process lacks STORAGE_* variables.
    $container->getDefinition('stream_wrapper.public')->setClass(XedcPublicS3fsStream::class);
  }

  /**
   * Wrapper class a container compile should install for this request.
   */
  public static function publicWrapperClass(bool $takeoverRequested): string {
    return XedcPublicS3fsStream::class;
  }

}
