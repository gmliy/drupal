<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Filter;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\Filter\FilterHtmlImageSecure;

/**
 * Core image restriction plus the one trusted public CDN host.
 *
 * filter_html_image_secure stays enabled. A local image still has to resolve
 * on disk. Every other URL still goes to hook_filter_secure_image_alter(),
 * which replaces it with the core placeholder. This class does not optimize
 * or rewrite the stored file.
 */
final class TrustedHtmlImageSecure extends FilterHtmlImageSecure {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $base_path = base_path();
    $base_path_length = mb_strlen($base_path);
    $local_dir = $this->root . '/';

    $html_dom = Html::load($text);
    $images = $html_dom->getElementsByTagName('img');
    foreach ($images as $image) {
      $original = $image->getAttribute('src');
      $image->setAttribute('src', $this->fileUrlGenerator->transformRelative($original));
      $src = $image->getAttribute('src');
      if (TrustedCdnImageUrl::isTrusted($original)) {
        $image->setAttribute('src', $original);
        continue;
      }
      if (mb_substr($src, 0, $base_path_length) === $base_path) {
        $local_image_path = $local_dir . mb_substr($src, $base_path_length);
        $local_image_path = rawurldecode($local_image_path);
        if (@getimagesize($local_image_path)) {
          continue;
        }
      }
      $this->moduleHandler->alter('filter_secure_image', $image);
    }
    return new FilterProcessResult(Html::serialize($html_dom));
  }

}
