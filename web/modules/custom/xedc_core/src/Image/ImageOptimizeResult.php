<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Image;

/**
 * One optimizer decision. The source file is never deleted by the optimizer.
 */
final class ImageOptimizeResult {

  public function __construct(
    public readonly string $status,
    public readonly string $path,
    public readonly string $mime,
    public readonly string $extension,
    public readonly int $width,
    public readonly int $height,
    public readonly int $bytes,
    public readonly string $sha256,
    public readonly string $sourceMime,
    public readonly string $sourceExtension,
    public readonly int $sourceBytes,
    public readonly string $sourceSha256,
    public readonly int $sourceWidth,
    public readonly int $sourceHeight,
    public readonly bool $keptOriginal,
    public readonly bool $animated,
    public readonly bool $alpha,
    public readonly int $elapsedMs,
    public readonly int $memoryBytes,
    public readonly string $error = '',
  ) {}

  public function savedBytes(): int {
    return max(0, $this->sourceBytes - $this->bytes);
  }

}
