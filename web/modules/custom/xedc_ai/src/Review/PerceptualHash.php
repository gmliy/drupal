<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

/**
 * Local 64-bit DCT perceptual hash. It never sends the image anywhere.
 */
final class PerceptualHash {

  public static function fromFile(string $path): ?string {
    if (!function_exists('imagecreatefromstring')) {
      return NULL;
    }
    $info = @getimagesize($path);
    if ($info === FALSE) {
      return NULL;
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width * $height > 40000000) {
      return NULL;
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 20 * 1024 * 1024) {
      return NULL;
    }
    $source = @imagecreatefromstring($bytes);
    if ($source === FALSE) {
      return NULL;
    }
    $size = 32;
    $image = imagecreatetruecolor($size, $size);
    imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
    imagedestroy($source);
    $gray = [];
    for ($y = 0; $y < $size; $y++) {
      for ($x = 0; $x < $size; $x++) {
        $rgb = imagecolorat($image, $x, $y);
        $red = ($rgb >> 16) & 255;
        $green = ($rgb >> 8) & 255;
        $blue = $rgb & 255;
        $gray[$y][$x] = ($red * 299 + $green * 587 + $blue * 114) / 1000;
      }
    }
    imagedestroy($image);
    return self::bits(self::lowDct($gray, $size));
  }

  public static function distance(string $left, string $right): int {
    if (strlen($left) !== 16 || strlen($right) !== 16) {
      return 64;
    }
    $a = hex2bin($left);
    $b = hex2bin($right);
    if ($a === FALSE || $b === FALSE) {
      return 64;
    }
    $distance = 0;
    $length = strlen($a);
    for ($i = 0; $i < $length; $i++) {
      $distance += substr_count(decbin(ord($a[$i]) ^ ord($b[$i])), '1');
    }
    return $distance;
  }

  /**
   * @param array<int, array<int, float>> $gray
   *   32 by 32 grayscale pixels.
   *
   * @return array<int, array<int, float>>
   *   Lowest 8 by 8 DCT coefficients.
   */
  private static function lowDct(array $gray, int $size): array {
    $factor = M_PI / (2 * $size);
    $cos = [];
    for ($u = 0; $u < 8; $u++) {
      for ($x = 0; $x < $size; $x++) {
        $cos[$u][$x] = cos((2 * $x + 1) * $u * $factor);
      }
    }
    $out = [];
    for ($v = 0; $v < 8; $v++) {
      for ($u = 0; $u < 8; $u++) {
        $sum = 0.0;
        for ($y = 0; $y < $size; $y++) {
          for ($x = 0; $x < $size; $x++) {
            $sum += $gray[$y][$x] * $cos[$u][$x] * $cos[$v][$y];
          }
        }
        $cu = $u === 0 ? 1 / sqrt(2) : 1;
        $cv = $v === 0 ? 1 / sqrt(2) : 1;
        $out[$v][$u] = 0.25 * $cu * $cv * $sum;
      }
    }
    return $out;
  }

  /**
   * @param array<int, array<int, float>> $coefficients
   *   DCT block.
   */
  private static function bits(array $coefficients): string {
    $values = [];
    for ($y = 0; $y < 8; $y++) {
      for ($x = 0; $x < 8; $x++) {
        $values[] = $coefficients[$y][$x];
      }
    }
    $sorted = $values;
    sort($sorted);
    $median = $sorted[32];
    $bits = '';
    foreach ($values as $value) {
      $bits .= $value > $median ? '1' : '0';
    }
    $hex = '';
    foreach (str_split($bits, 4) as $nibble) {
      $hex .= dechex(bindec($nibble));
    }
    return $hex;
  }

}
