<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

/**
 * Visual review is off. These methods do not read files or call an API.
 */
final class DisabledVisionAudit implements VisionAuditInterface {

  public function enabled(): bool {
    return FALSE;
  }

  public function inspectImage(string $path): array {
    return ['enabled' => FALSE, 'messages' => []];
  }

  public function inspectVideo(string $path): array {
    return ['enabled' => FALSE, 'messages' => []];
  }

}
