<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Seo;

/**
 * Local ALT checks and rule-based suggestions. No model and no file bytes.
 */
final class AltInspector {

  /**
   * @param list<array<string, mixed>> $images
   *   One row per image reference. File bytes are ignored.
   *
   * @return array<string, mixed>
   *   Counts, rows and a local score contribution. tokens is always 0.
   */
  public static function inspect(array $images, string $title, string $bundle): array {
    $title = trim($title);
    $seenFiles = [];
    $unique = [];
    foreach ($images as $image) {
      if (!is_array($image)) {
        continue;
      }
      $fid = (int) ($image['fid'] ?? 0);
      $mid = (int) ($image['mid'] ?? 0);
      $identity = $fid > 0 ? 'f' . $fid : 'm' . $mid . ':' . count($unique);
      if (isset($seenFiles[$identity])) {
        continue;
      }
      $seenFiles[$identity] = TRUE;
      $unique[] = $image;
    }
    $rows = [];
    $index = 0;
    $usedText = [];
    $valid = 0;
    $missing = 0;
    $abnormal = 0;
    $duplicate = 0;
    $decorative = 0;
    $candidates = 0;
    foreach ($unique as $image) {
      $index++;
      $raw = self::rawAlt($image);
      $text = self::normalize($raw);
      $filename = trim((string) ($image['filename'] ?? ''));
      $isDecorative = self::decorative($image, $text);
      $status = 'valid';
      $keep = TRUE;
      if ($isDecorative) {
        $status = 'decorative';
        $decorative++;
      }
      elseif ($raw === NULL || $text === '') {
        $status = 'missing';
        $keep = FALSE;
        $missing++;
      }
      else {
        $problem = self::problem($text);
        if ($problem !== '') {
          $status = $problem;
          $keep = FALSE;
          $abnormal++;
        }
        elseif (isset($usedText[$text])) {
          $status = 'duplicate';
          $keep = FALSE;
          $duplicate++;
        }
        else {
          $valid++;
          $usedText[$text] = TRUE;
        }
      }
      $candidate = '';
      if (!$keep && !$isDecorative && $status !== 'duplicate') {
        $candidate = self::candidate($title, $bundle, $filename, $index);
        if ($candidate !== '' && $candidate === $text) {
          $candidate = '';
          if ($status === 'missing') {
            $missing--;
          }
          else {
            $abnormal--;
          }
          $status = 'valid';
          $keep = TRUE;
          $valid++;
        }
      }
      if ($candidate !== '') {
        $candidates++;
      }
      $rows[] = [
        'mid' => (int) ($image['mid'] ?? 0),
        'fid' => (int) ($image['fid'] ?? 0),
        'field' => (string) ($image['field'] ?? ''),
        'label' => (string) ($image['label'] ?? ''),
        'filename' => $filename,
        'alt' => $raw === NULL ? '' : $text,
        'status' => $status,
        'status_label' => self::label($status),
        'candidate' => $candidate,
        'keep' => $keep,
        'shared' => (int) ($image['shared'] ?? 0),
        'reason' => self::reason($status, $candidate, (int) ($image['shared'] ?? 0)),
      ];
    }
    $scored = $valid + $missing + $abnormal + $duplicate;
    return [
      'tokens' => 0,
      'local' => TRUE,
      'total' => count($rows),
      'valid' => $valid,
      'missing' => $missing,
      'abnormal' => $abnormal,
      'duplicate' => $duplicate,
      'decorative' => $decorative,
      'candidates' => $candidates,
      'scored' => $scored,
      'rows' => $rows,
    ];
  }

  /**
   * @param list<array<string, mixed>> $images
   *   Image rows from extraction.
   *
   * @return array{points: int, status: string, reason: string}
   *   ALT score using the existing 10-point weight.
   */
  public static function points(array $images, string $title, string $bundle): array {
    $report = self::inspect($images, $title, $bundle);
    if ($report['total'] < 1) {
      return ['points' => 0, 'status' => 'na', 'reason' => '这个内容没有图片，不计入分母。'];
    }
    if ($report['scored'] < 1) {
      return ['points' => 0, 'status' => 'na', 'reason' => '只有装饰性图片，不计入分母。'];
    }
    $points = (int) round(10 * $report['valid'] / $report['scored']);
    $reason = $report['valid'] . '/' . $report['scored'] . ' 张应填写的图片有有效 ALT。';
    if ($report['valid'] === 0) {
      $reason = '应填写的图片 ALT 全部缺失或不可用。';
    }
    elseif ($report['valid'] === $report['scored']) {
      $reason = '应填写的图片 ALT 都有效。';
    }
    return ['points' => $points, 'status' => 'checked', 'reason' => $reason];
  }

  /**
   * @param array<string, mixed> $image
   *   One image row.
   */
  private static function rawAlt(array $image): ?string {
    if (!array_key_exists('alt', $image) || $image['alt'] === NULL) {
      return NULL;
    }
    return (string) $image['alt'];
  }

  private static function normalize(string|null $alt): string {
    return trim((string) $alt);
  }

  /**
   * @param array<string, mixed> $image
   *   One image row.
   */
  private static function decorative(array $image, string $alt): bool {
    if (!empty($image['decorative'])) {
      return TRUE;
    }
    $stem = self::stem((string) ($image['filename'] ?? ''));
    return $stem !== '' && preg_match('/^(spacer|pixel|blank|decoration|divider|icon)([-_.].*)?$/i', $stem) === 1 && $alt === '';
  }

  private static function problem(string $text): string {
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
      return 'abnormal';
    }
    $lower = mb_strtolower($text);
    $placeholders = ['图片', '照片', '图像', 'image', 'photo', 'img', 'picture', 'untitled', 'null', 'undefined', 'alt', 'none', 'n/a', 'na', 'test'];
    if (in_array($lower, $placeholders, TRUE)) {
      return 'placeholder';
    }
    if (preg_match('/^(jpe?g|png|gif|webp|avif)$/i', $lower) === 1) {
      return 'extension';
    }
    if (preg_match('/^\d+$/', $text) === 1) {
      return 'numeric';
    }
    if (mb_strlen($text) < 2) {
      return 'short';
    }
    if (mb_strlen($text) > 125) {
      return 'long';
    }
    if (self::random($text)) {
      return 'random';
    }
    return '';
  }

  private static function random(string $text): bool {
    if (preg_match('/\p{Han}/u', $text) === 1) {
      return FALSE;
    }
    if (preg_match('/[aeiouy]/i', $text) === 1 && preg_match('/[a-z]{3,}/i', $text) === 1) {
      return FALSE;
    }
    return mb_strlen($text) >= 8;
  }

  private static function candidate(string $title, string $bundle, string $filename, int $index): string {
    $stem = self::stem($filename);
    $meaningful = self::meaningful($stem);
    if ($bundle === 'video') {
      return $title;
    }
    if ($meaningful && $title !== '') {
      return $title . ' - ' . $stem;
    }
    if ($meaningful) {
      return $stem;
    }
    if ($title !== '') {
      return $title . ' - 图片 ' . $index;
    }
    return '图片 ' . $index;
  }

  private static function stem(string $filename): string {
    $base = trim($filename);
    $base = (string) preg_replace('/\.[^.\/]+$/', '', $base);
    return trim($base);
  }

  private static function meaningful(string $stem): bool {
    if ($stem === '' || preg_match('/^\d+$/', $stem) === 1) {
      return FALSE;
    }
    if (preg_match('/^(img|dsc|image|photo|pic|wx|微信图片)[_\- ]?\d+([_\- ]\d+)*$/iu', $stem) === 1) {
      return FALSE;
    }
    if (preg_match('/^[a-f0-9]{8,}$/i', $stem) === 1) {
      return FALSE;
    }
    if (self::random($stem)) {
      return FALSE;
    }
    return preg_match('/\p{Han}/u', $stem) === 1 || preg_match('/[a-z]{3,}/i', $stem) === 1;
  }

  private static function label(string $status): string {
    return match ($status) {
      'valid' => '有效',
      'missing' => '缺失',
      'decorative' => '装饰性',
      'duplicate' => '重复',
      'placeholder' => '占位',
      'extension' => '只有扩展名',
      'numeric' => '只有数字',
      'short' => '过短',
      'long' => '过长',
      'random' => '无意义字符',
      'abnormal' => '异常字符',
      default => $status,
    };
  }

  private static function reason(string $status, string $candidate, int $shared): string {
    $sharedNote = $shared > 0 ? ' 这张图片还被其他 ' . $shared . ' 个内容引用，确认后会改共享媒体。' : '';
    if ($status === 'valid' || $status === 'decorative') {
      return '保留现有 ALT，不自动覆盖。' . $sharedNote;
    }
    if ($candidate === '') {
      return '没有生成新的 ALT。' . $sharedNote;
    }
    return '按标题和序号在本地生成，不是图片识别。' . $sharedNote;
  }

}
