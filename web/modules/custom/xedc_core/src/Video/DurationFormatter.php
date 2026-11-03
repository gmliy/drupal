<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

/**
 * Formats field_duration seconds for every video surface.
 */
final class DurationFormatter {

  /**
   * Returns an empty string when the duration is missing or zero.
   */
  public function format(int $seconds): string {
    if ($seconds < 1) {
      return '';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remain = $seconds % 60;
    if ($hours > 0) {
      return sprintf('%02d:%02d:%02d', $hours, $minutes, $remain);
    }
    return sprintf('%02d:%02d', $minutes, $remain);
  }

}
