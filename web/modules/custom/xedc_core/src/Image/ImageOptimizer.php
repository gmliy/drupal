<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Image;

/**
 * Chooses one smaller, decodable image without shrinking the pixel grid.
 *
 * GD is the only encoder. The source path is left in place. AVIF is produced
 * only when the caller enables it; WebP is the usual smaller alternative.
 */
final class ImageOptimizer {

  /**
   * Results from this request, keyed by output hash and by source hash.
   *
   * @var array<string, ImageOptimizeResult>
   */
  private array $claims = [];

  private bool $oriented = FALSE;

  /**
   * @param array<string, mixed> $settings
   *   Runtime settings from the repository.
   */
  public function optimize(string $path, array $settings): ImageOptimizeResult {
    $started = hrtime(TRUE);
    $memory = memory_get_usage(TRUE);
    $this->oriented = FALSE;
    $bytes = is_file($path) ? filesize($path) : FALSE;
    $sourceSha = is_file($path) ? hash_file('sha256', $path) : FALSE;
    if ($bytes === FALSE || $bytes < 1 || !is_string($sourceSha)) {
      return $this->reject($path, '文件是空的或无法读取。', $started, $memory);
    }
    $free = @disk_free_space(sys_get_temp_dir());
    if (is_float($free) && $free < max(67108864, ((int) $bytes) * 3)) {
      return $this->reject($path, '临时目录空间不足。', $started, $memory, (int) $bytes, $sourceSha);
    }
    $maxBytes = (int) ($settings['max_bytes'] ?? 20971520);
    if ($bytes > $maxBytes) {
      return $this->reject($path, '图片超过安全大小。', $started, $memory, (int) $bytes, $sourceSha);
    }
    if ($this->isSvg($path)) {
      return $this->svg($path, (int) $bytes, $sourceSha, $started, $memory);
    }
    $info = @getimagesize($path);
    $type = (int) ($info[2] ?? 0);
    $map = $this->typeMap();
    if (!$info || !isset($map[$type])) {
      return $this->reject($path, '不是受支持的图片，或文件已损坏。', $started, $memory, (int) $bytes, $sourceSha);
    }
    if (!$this->mimeAgrees($path, $map[$type]['mime'])) {
      return $this->reject($path, '文件内容与识别出的类型不一致。', $started, $memory, (int) $bytes, $sourceSha);
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    $pixels = $width * $height;
    $maxPixels = (int) ($settings['max_pixels'] ?? 12000000);
    if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $pixels > $maxPixels) {
      return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, FALSE, FALSE, '超过安全像素，已保留原图。');
    }
    if ($type === IMAGETYPE_GIF && $this->animatedGif($path)) {
      return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, TRUE, FALSE, '保留 GIF 动画。');
    }
    if ($width * $height * 5 > 80 * 1024 * 1024) {
      return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, FALSE, FALSE, '解码内存超过限制，已保留原图。');
    }
    $opened = $this->open($path, $type);
    if ($opened === NULL) {
      return $this->reject($path, '图片无法解码。', $started, $memory, (int) $bytes, $sourceSha);
    }
    $image = $opened['image'];
    $gifTransparent = $opened['transparent'];
    if ($type === IMAGETYPE_JPEG) {
      $image = $this->orient($path, $image);
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $alpha = $gifTransparent || $this->hasAlpha($image, $path, $type);
    $points = $alpha ? $this->alphaPoints($image) : [];
    $sameFormat = empty($settings['auto_format']);
    $deadline = $started + max(5, (int) ($settings['timeout'] ?? 20)) * 1000000000;
    $dir = sys_get_temp_dir() . '/xedc-opt-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700) && !is_dir($dir)) {
      imagedestroy($image);
      return $this->reject($path, '无法创建临时目录。', $started, $memory, (int) $bytes, $sourceSha);
    }
    $candidates = [];
    try {
      if ($type === IMAGETYPE_JPEG && !$alpha && hrtime(TRUE) < $deadline) {
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
      if (($type === IMAGETYPE_PNG || $alpha || $type === IMAGETYPE_GIF) && (!$sameFormat || $type === IMAGETYPE_PNG) && hrtime(TRUE) < $deadline) {
        $png = $this->save($image, $dir . '/out.png', 'png', 9);
        if ($png) {
          $candidates[] = $png;
        }
      }
      if ($sameFormat && $type === IMAGETYPE_GIF && hrtime(TRUE) < $deadline) {
        $gif = $this->save($image, $dir . '/out.gif', 'gif', 0);
        if ($gif) {
          $candidates[] = $gif;
        }
      }
      $valid = [];
      foreach ($candidates as $candidate) {
        if (!$this->validCandidate($candidate, $width, $height, $alpha, $points)) {
          @unlink($candidate['path']);
          continue;
        }
        $valid[] = $candidate;
      }
      $best = $this->choose($valid, (int) $bytes, $map[$type]['extension'], !empty($settings['strip_metadata']));
      foreach ($valid as $candidate) {
        if ($best === NULL || $candidate['path'] !== $best['path']) {
          @unlink($candidate['path']);
        }
      }
      imagedestroy($image);
      $image = NULL;
      if ($best === NULL) {
        $this->removeDir($dir);
        $note = $this->oriented ? '' : '';
        return $this->keep($path, $map[$type], $width, $height, (int) $bytes, $sourceSha, $started, $memory, FALSE, $alpha, $note);
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
        $this->oriented && $best['bytes'] >= (int) $bytes ? '已纠正方向。' : '',
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
    if (preg_match('/^[a-f0-9]{64}$/', $result->sourceSha256)) {
      $this->claims['src:' . $result->sourceSha256] = $result;
    }
  }

  public function claimFor(string $sha256): ?ImageOptimizeResult {
    return $this->claims[$sha256] ?? $this->claims['src:' . $sha256] ?? NULL;
  }

  /**
   * @param list<array{path: string, mime: string, extension: string, bytes: int}> $valid
   * @return array{path: string, mime: string, extension: string, bytes: int}|null
   */
  private function choose(array $valid, int $sourceBytes, string $sourceExtension, bool $strip): ?array {
    $best = NULL;
    foreach ($valid as $candidate) {
      if ($candidate['bytes'] >= $sourceBytes) {
        continue;
      }
      if ($best === NULL || $candidate['bytes'] < $best['bytes']) {
        $best = $candidate;
      }
    }
    if ($best !== NULL) {
      return $best;
    }
    if ($this->oriented) {
      foreach ($valid as $candidate) {
        if ($candidate['extension'] === 'jpg') {
          return $candidate;
        }
      }
    }
    if ($strip) {
      foreach ($valid as $candidate) {
        if ($candidate['extension'] === $sourceExtension && $candidate['bytes'] <= $sourceBytes) {
          return $candidate;
        }
      }
    }
    return NULL;
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

  private function mimeAgrees(string $path, string $expected): bool {
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string) $finfo->file($path));
    $aliases = [
      'image/jpg' => 'image/jpeg',
      'image/pjpeg' => 'image/jpeg',
      'image/x-png' => 'image/png',
      'image/x-citrix-jpeg' => 'image/jpeg',
      'image/x-citrix-png' => 'image/png',
    ];
    $mime = $aliases[$mime] ?? $mime;
    if ($mime === '' || $mime === 'application/octet-stream' || $mime === 'binary/octet-stream') {
      return TRUE;
    }
    return $mime === $expected;
  }

  /**
   * @return array{image: \GdImage, transparent: bool}|null
   */
  private function open(string $path, int $type): ?array {
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
    $transparent = $type === IMAGETYPE_GIF && imagecolortransparent($image) >= 0;
    if (function_exists('imagepalettetotruecolor') && !imageistruecolor($image)) {
      imagepalettetotruecolor($image);
    }
    imagealphablending($image, FALSE);
    imagesavealpha($image, TRUE);
    return ['image' => $image, 'transparent' => $transparent];
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
      imagealphablending($rotated, FALSE);
      imagesavealpha($rotated, TRUE);
      $this->oriented = TRUE;
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
      'avif' => function_exists('imageavif') ? @imageavif($image, $path, max(68, min(75, $quality))) : FALSE,
      'png' => @imagepng($image, $path, max(0, min(9, $quality))),
      'gif' => @imagegif($image, $path),
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
      'gif' => 'image/gif',
      default => 'image/png',
    };
    $extension = match ($format) {
      'jpeg' => 'jpg',
      'webp' => 'webp',
      'avif' => 'avif',
      'gif' => 'gif',
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
    $expected = match ($candidate['extension']) {
      'jpg' => IMAGETYPE_JPEG,
      'png' => IMAGETYPE_PNG,
      'gif' => IMAGETYPE_GIF,
      'webp' => IMAGETYPE_WEBP,
      'avif' => defined('IMAGETYPE_AVIF') ? IMAGETYPE_AVIF : -1,
      default => -1,
    };
    if ((int) ($info[2] ?? 0) !== $expected) {
      return FALSE;
    }
    $proof = $this->open($candidate['path'], (int) $info[2]);
    if ($proof === NULL) {
      return FALSE;
    }
    $ok = !$alpha || $points === [] || $this->alphaSurvived($proof['image'], $points);
    imagedestroy($proof['image']);
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
      return TRUE;
    }
    $signature = fread($handle, 8);
    if ($signature !== "\x89PNG\r\n\x1a\n") {
      fclose($handle);
      return TRUE;
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
      if ($size < 0 || fseek($handle, $size + 4, SEEK_CUR) !== 0) {
        break;
      }
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
    $step = max(1, (int) floor(max($width, $height) / 48));
    for ($y = 0; $y < $height && count($points) < 8; $y += $step) {
      for ($x = 0; $x < $width && count($points) < 8; $x += $step) {
        $color = $this->colorAt($image, $x, $y);
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
      $color = $this->colorAt($image, $point[0], $point[1]);
      if (($color['alpha'] ?? 0) >= 16) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array{red?: int, green?: int, blue?: int, alpha: int}
   */
  private function colorAt(\GdImage $image, int $x, int $y): array {
    $index = imagecolorat($image, $x, $y);
    if (imageistruecolor($image)) {
      return ['alpha' => ($index >> 24) & 0x7F];
    }
    $color = imagecolorsforindex($image, $index);
    return is_array($color) ? $color : ['alpha' => 0];
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
    $head = fread($handle, 512);
    fclose($handle);
    if (!is_string($head)) {
      return FALSE;
    }
    $start = ltrim($head);
    return str_starts_with($start, '<svg') || str_starts_with($start, '<?xml') && str_contains($head, '<svg');
  }

  private function svg(string $path, int $bytes, string $sha, int $started, int $memory): ImageOptimizeResult {
    $body = file_get_contents($path, FALSE, NULL, 0, 200000);
    $text = is_string($body) ? strtolower($body) : '';
    if ($text === '' || str_contains($text, '<script') || str_contains($text, 'javascript:') || str_contains($text, 'onload=') || str_contains($text, '<foreignobject') || str_contains($text, '<!entity') || str_contains($text, 'xlink:href="data:') || str_contains($text, "xlink:href='data:")) {
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
