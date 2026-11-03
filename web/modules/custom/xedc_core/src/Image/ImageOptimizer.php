<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Image;

/**
 * Chooses one smaller, decodable image without changing the pixel grid.
 *
 * GD is the only encoder. The source path is left in place.
 */
final class ImageOptimizer {

  /**
   * @var array<string, ImageOptimizeResult>
   */
  private array $claims = [];

  /**
   * @param array<string, mixed> $settings
   *   Runtime settings from the repository.
   */
  public function optimize(string $path, array $settings): ImageOptimizeResult {
    $started = hrtime(TRUE);
    $memory = memory_get_usage(TRUE);
    $bytes = is_file($path) ? filesize($path) : FALSE;
    $sourceSha = is_file($path) ? hash_file('sha256', $path) : FALSE;
    if ($bytes === FALSE || $bytes < 1 || !is_string($sourceSha)) {
      return $this->reject($path, '文件是空的或无法读取。', $started, $memory);
    }
    $maxBytes = (int) ($settings['max_bytes'] ?? 20971520);
    if ($bytes > $maxBytes) {
      return $this->reject($path, '图片超过安全大小。', $started, $memory, $bytes, $sourceSha);
    }
    if ($this->isSvg($path)) {
      return $this->svg($path, $bytes, $sourceSha, $started, $memory);
    }
    $info = @getimagesize($path);
    $type = (int) ($info[2] ?? 0);
    $map = $this->typeMap();
    if (!$info || !isset($map[$type])) {
      return $this->reject($path, '不是受支持的图片，或文件已损坏。', $started, $memory, (int) $bytes, $sourceSha);
    }
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($path);
    if ($mime !== $map[$type]['mime']) {
      return $this->reject($path, '文件内容与扩展名不一致。', $started, $memory, (int) $bytes, $sourceSha);
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    $pixels = $width * $height;
    $maxPixels = (int) ($settings['max_pixels'] ?? 12000000);
    if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $pixels > $maxPixels) {
      return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, FALSE, FALSE, '超过安全像素，已保留原图。');
    }
    if ($type === IMAGETYPE_GIF && $this->animatedGif($path)) {
      return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, TRUE, FALSE, '');
    }
    $decoded = $width * $height * 5;
    if ($decoded > 80 * 1024 * 1024) {
      return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, FALSE, FALSE, '解码内存超过限制，已保留原图。');
    }
    $image = $this->open($path, $type);
    if (!$image instanceof \GdImage) {
      return $this->reject($path, '图片无法解码。', $started, $memory, (int) $bytes, $sourceSha);
    }
    if ($type === IMAGETYPE_JPEG) {
      $image = $this->orient($path, $image);
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $alpha = $this->hasAlpha($image, $path, $type);
    $points = $alpha ? $this->alphaPoints($image) : [];
    $candidates = [];
    $sameFormat = empty($settings['auto_format']);
    $deadline = $started + max(5, (int) ($settings['timeout'] ?? 20)) * 1000000000;
    $dir = sys_get_temp_dir() . '/xedc-opt-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700) && !is_dir($dir)) {
      imagedestroy($image);
      return $this->reject($path, '无法创建临时目录。', $started, $memory, (int) $bytes, $sourceSha);
    }
    try {
      if (!$alpha && (!$sameFormat || $type === IMAGETYPE_JPEG) && hrtime(TRUE) < $deadline) {
        $jpeg = $this->save($image, $dir . '/out.jpg', 'jpeg', (int) ($settings['jpeg_quality'] ?? 84));
        if ($jpeg) {
          $candidates[] = $jpeg;
        }
      }
      if (!empty($settings['allow_webp']) && function_exists('imagewebp') && (!$sameFormat || $type === IMAGETYPE_WEBP) && hrtime(TRUE) < $deadline) {
        $webp = $this->save($image, $dir . '/out.webp', 'webp', (int) ($settings['webp_quality'] ?? 82));
        if ($webp) {
          $candidates[] = $webp;
        }
      }
      if (!empty($settings['allow_avif']) && function_exists('imageavif') && (!$sameFormat || $type === IMAGETYPE_AVIF) && hrtime(TRUE) < $deadline) {
        $avif = $this->save($image, $dir . '/out.avif', 'avif', (int) ($settings['avif_quality'] ?? 70));
        if ($avif) {
          $candidates[] = $avif;
        }
      }
      if (($type === IMAGETYPE_PNG || $alpha) && (!$sameFormat || $type === IMAGETYPE_PNG) && hrtime(TRUE) < $deadline) {
        $png = $this->save($image, $dir . '/out.png', 'png', 9);
        if ($png) {
          $candidates[] = $png;
        }
      }
      imagedestroy($image);
      $image = NULL;
      $best = NULL;
      foreach ($candidates as $candidate) {
        if (!$this->validCandidate($candidate, $width, $height, $alpha, $points)) {
          @unlink($candidate['path']);
          continue;
        }
        if ($candidate['bytes'] >= (int) $bytes) {
          @unlink($candidate['path']);
          continue;
        }
        if ($best === NULL || $candidate['bytes'] < $best['bytes']) {
          if ($best !== NULL) {
            @unlink($best['path']);
          }
          $best = $candidate;
        }
        else {
          @unlink($candidate['path']);
        }
      }
      if ($best === NULL) {
        $this->removeDir($dir);
        return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, FALSE, $alpha, '');
      }
      $sha = hash_file('sha256', $best['path']);
      $result = new ImageOptimizeResult(
        'optimized',
        $best['path'],
        $best['mime'],
        $best['extension'],
        $width,
        $height,
        $best['bytes'],
        is_string($sha) ? $sha : '',
        $map[$type]['mime'],
        $map[$type]['extension'],
        (int) $bytes,
        $sourceSha,
        (int) $info[0],
        (int) $info[1],
        FALSE,
        FALSE,
        $alpha,
        $this->elapsed($started),
        max(0, memory_get_peak_usage(TRUE) - $memory),
      );
      $this->claim($result);
      return $result;
    }
    finally {
      if ($image instanceof \GdImage) {
        imagedestroy($image);
      }
    }
  }

  public function claim(ImageOptimizeResult $result): void {
    if (preg_match('/^[a-f0-9]{64}$/', $result->sha256)) {
      $this->claims[$result->sha256] = $result;
    }
  }

  public function claimFor(string $sha256): ?ImageOptimizeResult {
    return $this->claims[$sha256] ?? NULL;
  }

  /**
   * @return array<int, array{mime: string, extension: string}>
   */
  private function typeMap(): array {
    $map = [
      IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'extension' => 'jpg'],
      IMAGETYPE_PNG => ['mime' => 'image/png', 'extension' => 'png'],
      IMAGETYPE_GIF => ['mime' => 'image/gif', 'extension' => 'gif'],
      IMAGETYPE_WEBP => ['mime' => 'image/webp', 'extension' => 'webp'],
    ];
    if (defined('IMAGETYPE_AVIF')) {
      $map[IMAGETYPE_AVIF] = ['mime' => 'image/avif', 'extension' => 'avif'];
    }
    return $map;
  }

  private function open(string $path, int $type): ?\GdImage {
    $image = match ($type) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      IMAGETYPE_GIF => @imagecreatefromgif($path),
      IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : FALSE,
      default => defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF && function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : FALSE,
    };
    if (!$image instanceof \GdImage) {
      return NULL;
    }
    if (function_exists('imagepalettetotruecolor') && !imageistruecolor($image)) {
      imagepalettetotruecolor($image);
    }
    imagealphablending($image, FALSE);
    imagesavealpha($image, TRUE);
    return $image;
  }

  private function orient(string $path, \GdImage $image): \GdImage {
    if (!function_exists('exif_read_data')) {
      return $image;
    }
    $exif = @exif_read_data($path);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    $angle = match ($orientation) {
      3 => 180,
      6 => -90,
      8 => 90,
      default => 0,
    };
    if ($angle === 0) {
      return $image;
    }
    $rotated = imagerotate($image, $angle, 0);
    if ($rotated instanceof \GdImage) {
      imagedestroy($image);
      imagesavealpha($rotated, TRUE);
      return $rotated;
    }
    return $image;
  }

  /**
   * @return array{path: string, mime: string, extension: string, bytes: int}|null
   */
  private function save(\GdImage $image, string $path, string $format, int $quality): ?array {
    $ok = match ($format) {
      'jpeg' => @imagejpeg($image, $path, max(82, min(88, $quality))),
      'webp' => @imagewebp($image, $path, max(80, min(84, $quality))),
      'avif' => @imageavif($image, $path, max(68, min(75, $quality))),
      'png' => @imagepng($image, $path, max(0, min(9, $quality))),
      default => FALSE,
    };
    $size = $ok && is_file($path) ? filesize($path) : FALSE;
    if ($size === FALSE || $size < 1) {
      @unlink($path);
      return NULL;
    }
    $mime = match ($format) {
      'jpeg' => 'image/jpeg',
      'webp' => 'image/webp',
      'avif' => 'image/avif',
      default => 'image/png',
    };
    $extension = match ($format) {
      'jpeg' => 'jpg',
      'webp' => 'webp',
      'avif' => 'avif',
      default => 'png',
    };
    return ['path' => $path, 'mime' => $mime, 'extension' => $extension, 'bytes' => (int) $size];
  }

  /**
   * @param array{path: string, mime: string, extension: string, bytes: int} $candidate
   * @param array<int, array{0: int, 1: int}> $points
   */
  private function validCandidate(array $candidate, int $width, int $height, bool $alpha, array $points): bool {
    $info = @getimagesize($candidate['path']);
    if (!$info || (int) $info[0] !== $width || (int) $info[1] !== $height) {
      return FALSE;
    }
    $type = (int) ($info[2] ?? 0);
    $proof = $this->open($candidate['path'], $type);
    if (!$proof instanceof \GdImage) {
      return FALSE;
    }
    $ok = !$alpha || !$points || $this->alphaSurvived($proof, $points);
    imagedestroy($proof);
    return $ok;
  }

  private function hasAlpha(\GdImage $image, string $path, int $type): bool {
    if ($type === IMAGETYPE_JPEG) {
      return FALSE;
    }
    if ($this->alphaPoints($image) !== []) {
      return TRUE;
    }
    if ($type !== IMAGETYPE_PNG) {
      return FALSE;
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
      return FALSE;
    }
    $signature = fread($handle, 8);
    if ($signature !== "\x89PNG\r\n\x1a\n") {
      fclose($handle);
      return FALSE;
    }
    while (!feof($handle)) {
      $head = fread($handle, 8);
      if (!is_string($head) || strlen($head) < 8) {
        break;
      }
      $length = unpack('N', substr($head, 0, 4));
      $size = (int) ($length[1] ?? 0);
      $kind = substr($head, 4, 4);
      if ($kind === 'IHDR') {
        $data = fread($handle, $size);
        fclose($handle);
        $color = ord(substr((string) $data, 9, 1));
        return $color === 4 || $color === 6;
      }
      if ($kind === 'tRNS') {
        fclose($handle);
        return TRUE;
      }
      fseek($handle, $size + 4, SEEK_CUR);
    }
    fclose($handle);
    return FALSE;
  }

  /**
   * @return array<int, array{0: int, 1: int}>
   */
  private function alphaPoints(\GdImage $image): array {
    $points = [];
    $width = imagesx($image);
    $height = imagesy($image);
    $step = max(1, (int) floor(max($width, $height) / 32));
    for ($y = 0; $y < $height && count($points) < 6; $y += $step) {
      for ($x = 0; $x < $width && count($points) < 6; $x += $step) {
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        if (($color['alpha'] ?? 0) >= 16) {
          $points[] = [$x, $y];
        }
      }
    }
    return $points;
  }

  /**
   * @param array<int, array{0: int, 1: int}> $points
   */
  private function alphaSurvived(\GdImage $image, array $points): bool {
    foreach ($points as $point) {
      if ($point[0] >= imagesx($image) || $point[1] >= imagesy($image)) {
        continue;
      }
      $color = imagecolorsforindex($image, imagecolorat($image, $point[0], $point[1]));
      if (($color['alpha'] ?? 0) >= 16) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function animatedGif(string $path): bool {
    $handle = fopen($path, 'rb');
    if (!$handle) {
      return FALSE;
    }
    $count = 0;
    $tail = '';
    while (!feof($handle)) {
      $chunk = fread($handle, 65536);
      if (!is_string($chunk) || $chunk === '') {
        break;
      }
      $count += substr_count($tail . $chunk, "\x21\xF9\x04");
      if ($count > 1) {
        fclose($handle);
        return TRUE;
      }
      $tail = substr($chunk, -3);
    }
    fclose($handle);
    return FALSE;
  }

  private function isSvg(string $path): bool {
    $handle = fopen($path, 'rb');
    if (!$handle) {
      return FALSE;
    }
    $head = fread($handle, 256);
    fclose($handle);
    if (!is_string($head)) {
      return FALSE;
    }
    return str_contains(ltrim($head), '<svg') || str_contains($head, '<svg');
  }

  private function svg(string $path, int $bytes, string $sha, int $started, int $memory): ImageOptimizeResult {
    $body = file_get_contents($path, FALSE, NULL, 0, 200000);
    $text = is_string($body) ? strtolower($body) : '';
    if ($text === '' || str_contains($text, '<script') || str_contains($text, 'javascript:') || str_contains($text, 'onload=') || str_contains($text, '<foreignobject') || str_contains($text, '<!entity')) {
      return $this->reject($path, 'SVG 含有不允许的内容。', $started, $memory, $bytes, $sha);
    }
    return $this->keep($path, ['mime' => 'image/svg+xml', 'extension' => 'svg'], 0, 0, $bytes, $sha, $started, $memory, FALSE, FALSE, '');
  }

  /**
   * @param array{mime: string, extension: string} $type
   */
  private function keep(string $path, array $type, int $width, int $height, int $bytes, string $sha, int $started, int $memory, bool $animated, bool $alpha, string $note): ImageOptimizeResult {
    $result = new ImageOptimizeResult('kept', $path, $type['mime'], $type['extension'], $width, $height, $bytes, $sha, $type['mime'], $type['extension'], $bytes, $sha, $width, $height, TRUE, $animated, $alpha, $this->elapsed($started), max(0, memory_get_peak_usage(TRUE) - $memory), $note);
    $this->claim($result);
    return $result;
  }

  private function reject(string $path, string $error, int $started, int $memory, int $bytes = 0, string $sha = ''): ImageOptimizeResult {
    return new ImageOptimizeResult('rejected', $path, '', '', 0, 0, 0, '', '', '', $bytes, $sha, 0, 0, TRUE, FALSE, FALSE, $this->elapsed($started), max(0, memory_get_peak_usage(TRUE) - $memory), $error);
  }

  private function elapsed(int $started): int {
    return (int) ((hrtime(TRUE) - $started) / 1000000);
  }

  private function removeDir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
      if (is_file($file)) {
        @unlink($file);
      }
    }
    @rmdir($dir);
  }

}
