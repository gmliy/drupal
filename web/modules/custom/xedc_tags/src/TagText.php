<?php

namespace Drupal\xedc_tags;

/**
 * Explainable string comparisons for tag names.
 *
 * This is not a semantic model. Similar names are reported for review and
 * are not treated as safe merge candidates.
 */
final class TagText {

  /**
   * Trim, compatibility-normalize, collapse spaces and case-fold.
   */
  public static function normalize(string $value): string {
    $value = trim($value);
    if (class_exists(\Normalizer::class)) {
      $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
      if (is_string($normalized) && $normalized !== '') {
        $value = $normalized;
      }
    }
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtolower($value, 'UTF-8');
  }

  /**
   * Compare two labels.
   *
   * @return array{reasons: string[], recommend_merge: bool, risk: string}|null
   *   Null when the names are not a review candidate.
   */
  public static function compare(string $left, string $right): ?array {
    if ($left === '' || $right === '') {
      return NULL;
    }
    $normalizedLeft = self::normalize($left);
    $normalizedRight = self::normalize($right);
    if ($normalizedLeft === '' || $normalizedRight === '') {
      return NULL;
    }
    if ($normalizedLeft === $normalizedRight) {
      return [
        'reasons' => self::exactReasons($left, $right),
        'recommend_merge' => TRUE,
        'risk' => '规范化后的名称相同。合并前仍要确认这不是有意保留的不同写法。',
      ];
    }

    $foldedLeft = self::foldSeparators($normalizedLeft);
    $foldedRight = self::foldSeparators($normalizedRight);
    if ($foldedLeft !== '' && $foldedLeft === $foldedRight) {
      return [
        'reasons' => ['分隔符或空格不同'],
        'recommend_merge' => FALSE,
        'risk' => '去掉分隔符后相同，但仍可能是有意区分的名称。',
      ];
    }

    $bagLeft = self::charBag($normalizedLeft);
    $bagRight = self::charBag($normalizedRight);
    if (mb_strlen($normalizedLeft) >= 3 && $bagLeft === $bagRight) {
      return [
        'reasons' => ['词序不同'],
        'recommend_merge' => FALSE,
        'risk' => '只有字序不同，不代表重复。例如“美女写真”和“写真美女”不能直接合并。',
      ];
    }

    $short = mb_strlen($normalizedLeft) <= mb_strlen($normalizedRight) ? $normalizedLeft : $normalizedRight;
    $long = $short === $normalizedLeft ? $normalizedRight : $normalizedLeft;
    $extra = mb_strlen($long) - mb_strlen($short);
    if (mb_strlen($short) >= 2 && $extra >= 1 && $extra <= 4 && mb_strpos($long, $short) !== FALSE) {
      return [
        'reasons' => ['名称存在包含关系'],
        'recommend_merge' => FALSE,
        'risk' => '一个名称包含另一个名称。例如“美女”和“美女热舞”含义可能不同，不能直接合并。',
      ];
    }

    $distance = self::distance($normalizedLeft, $normalizedRight);
    $max = max(mb_strlen($normalizedLeft), mb_strlen($normalizedRight));
    if ($distance !== NULL && $max > 0 && $distance <= 2 && (1 - $distance / $max) >= 0.85) {
      return [
        'reasons' => ['字符高度相似'],
        'recommend_merge' => FALSE,
        'risk' => '只是字符接近，系统没有做语义判断，不能据此合并。',
      ];
    }
    return NULL;
  }

  /**
   * @return string[]
   */
  private static function exactReasons(string $left, string $right): array {
    if ($left === $right) {
      return ['完全相同名称'];
    }
    $reasons = [];
    if (trim($left) !== $left || trim($right) !== $right) {
      $reasons[] = '首尾空格';
    }
    $collapsedLeft = preg_replace('/\s+/u', ' ', trim($left)) ?? trim($left);
    $collapsedRight = preg_replace('/\s+/u', ' ', trim($right)) ?? trim($right);
    if ($collapsedLeft !== trim($left) || $collapsedRight !== trim($right) || preg_match('/\s{2,}/u', $left . $right)) {
      $reasons[] = '多余空格';
    }
    if (mb_strtolower($collapsedLeft, 'UTF-8') !== $collapsedLeft || mb_strtolower($collapsedRight, 'UTF-8') !== $collapsedRight) {
      $reasons[] = '大小写不同';
    }
    if (self::widthChanged($collapsedLeft) || self::widthChanged($collapsedRight)) {
      $reasons[] = '全角半角不同';
    }
    $foldedLeft = class_exists(\Normalizer::class) ? \Normalizer::normalize($collapsedLeft, \Normalizer::FORM_KC) : $collapsedLeft;
    $foldedRight = class_exists(\Normalizer::class) ? \Normalizer::normalize($collapsedRight, \Normalizer::FORM_KC) : $collapsedRight;
    if (($foldedLeft !== $collapsedLeft && !self::widthChanged($collapsedLeft)) || ($foldedRight !== $collapsedRight && !self::widthChanged($collapsedRight))) {
      $reasons[] = 'Unicode 规范化不同';
    }
    return $reasons ?: ['规范化后相同'];
  }

  private static function widthChanged(string $value): bool {
    if (!class_exists(\Normalizer::class)) {
      return FALSE;
    }
    $folded = \Normalizer::normalize($value, \Normalizer::FORM_KC);
    return is_string($folded) && $folded !== $value && preg_match('/[\x{FF00}-\x{FFEF}]/u', $value) === 1;
  }

  private static function foldSeparators(string $value): string {
    return preg_replace('/[\s\-_·.。．]+/u', '', $value) ?? $value;
  }

  private static function charBag(string $value): string {
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    sort($chars, SORT_STRING);
    return implode('', $chars);
  }

  /**
   * Heuristic: string contains common traditional-only characters.
   */
  public static function looksTraditional(string $value): bool {
    return preg_match('/[繁體國東車門開關風龍學業們後來自這過還時會長經]/u', $value) === 1;
  }

  /**
   * Heuristic: string contains common simplified-only characters.
   */
  public static function looksSimplified(string $value): bool {
    return preg_match('/[国东车门开关风龙学业们这过还时长经]/u', $value) === 1;
  }

  private static function distance(string $left, string $right): ?int {
    $a = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $b = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n = count($a);
    $m = count($b);
    if ($n > 32 || $m > 32) {
      return NULL;
    }
    $row = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
      $next = [$i];
      for ($j = 1; $j <= $m; $j++) {
        $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
        $next[$j] = min($next[$j - 1] + 1, $row[$j] + 1, $row[$j - 1] + $cost);
      }
      $row = $next;
    }
    return $row[$m];
  }

}
