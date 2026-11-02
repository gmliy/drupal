<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

/**
 * Builds an SVG QR code from the vendored encoder.
 *
 * The encoder is LGPL-3.0. This class draws the matrix itself so the library
 * does not send its own HTTP headers.
 */
final class QrImage {

  public function svg(string $text): string {
    if ($text === '' || strlen($text) > 1024) {
      throw new \InvalidArgumentException('二维码内容无效。');
    }
    require_once dirname(__DIR__, 2) . '/assets/phpqrcode.php';
    $rows = \QRcode::text($text, FALSE, \QR_ECLEVEL_M, 3, 2);
    if (!is_array($rows) || $rows === []) {
      throw new \RuntimeException('无法生成二维码。');
    }
    $size = count($rows);
    $scale = 4;
    $margin = 4;
    $dim = ($size + ($margin * 2)) * $scale;
    $rects = '';
    foreach ($rows as $y => $row) {
      if (!is_string($row)) {
        throw new \RuntimeException('无法生成二维码。');
      }
      $length = strlen($row);
      for ($x = 0; $x < $length; $x++) {
        if ($row[$x] === '1') {
          $rects .= '<rect x="' . (($x + $margin) * $scale) . '" y="' . (($y + $margin) * $scale) . '" width="' . $scale . '" height="' . $scale . '"/>';
        }
      }
    }
    if ($rects === '' || $size < 21) {
      throw new \RuntimeException('无法生成二维码。');
    }
    return '<?xml version="1.0" encoding="UTF-8"?>'
      . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" viewBox="0 0 ' . $dim . ' ' . $dim . '">'
      . '<rect width="100%" height="100%" fill="#ffffff"/>'
      . '<g fill="#18181b">' . $rects . '</g></svg>';
  }

}
