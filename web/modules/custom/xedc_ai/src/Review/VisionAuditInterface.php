<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

/**
 * Reserved extension for a future visual reviewer.
 *
 * The shipped implementation stays disabled. It must not call a vision API,
 * decode video frames, or change the text review flow.
 */
interface VisionAuditInterface {

  /**
   * Whether a visual reviewer is allowed to run.
   */
  public function enabled(): bool;

  /**
   * @return array{enabled: bool, messages: list<string>}
   *   Image findings. Disabled implementations ignore the path.
   */
  public function inspectImage(string $path): array;

  /**
   * @return array{enabled: bool, messages: list<string>}
   *   Video findings. Disabled implementations ignore the path.
   */
  public function inspectVideo(string $path): array;

}
