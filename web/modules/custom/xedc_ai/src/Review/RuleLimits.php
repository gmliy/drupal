<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Local rule thresholds. Missing config keeps the built-in defaults.
 */
final class RuleLimits {

  /**
   * @return array<string, int>
   *   Default thresholds.
   */
  public static function defaults(): array {
    return [
      'min_title' => 4,
      'max_title' => 80,
      'min_article_body' => 40,
      'max_tags' => 12,
      'min_image_edge' => 200,
      'max_video_seconds' => 86400,
      'phash_distance' => 10,
      'max_hash_bytes' => 67108864,
    ];
  }

  /**
   * @return array<string, int>
   *   Stored thresholds, filled with defaults.
   */
  public static function fromConfig(ConfigFactoryInterface $configFactory): array {
    $config = $configFactory->get('xedc_ai.rules');
    $limits = self::defaults();
    foreach ($limits as $name => $default) {
      $value = $config->get($name);
      if (is_numeric($value)) {
        $limits[$name] = max(0, (int) $value);
      }
    }
    if ($limits['phash_distance'] < 1) {
      $limits['phash_distance'] = 10;
    }
    if ($limits['max_hash_bytes'] < 1048576) {
      $limits['max_hash_bytes'] = 67108864;
    }
    return $limits;
  }

}
