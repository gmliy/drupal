<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

/**
 * Local text checks. These rules do not call a model.
 */
final class TextRules {

  /**
   * @param array<string, mixed> $fields
   *   Extracted text.
   * @param list<int> $duplicateTitleIds
   *   Other node ids with the same title.
   * @param list<int> $duplicateTextIds
   *   Other node ids with the same body or summary.
   *
   * @return list<array{level: string, message: string}>
   *   Local findings.
   */
  public static function assess(array $fields, array $duplicateTitleIds = [], array $duplicateTextIds = [], array $limits = []): array {
    $issues = [];
    $bundle = (string) ($fields['bundle'] ?? '');
    $title = trim((string) ($fields['title'] ?? ''));
    $summary = trim((string) ($fields['summary'] ?? ''));
    $body = trim((string) ($fields['body'] ?? ''));
    $tags = array_values(array_filter(array_map('strval', (array) ($fields['tags'] ?? []))));
    $minTitle = (int) ($limits['min_title'] ?? 4);
    $maxTitle = (int) ($limits['max_title'] ?? 80);
    $minBody = (int) ($limits['min_article_body'] ?? 40);
    $maxTags = (int) ($limits['max_tags'] ?? 12);
    $titleLength = mb_strlen($title);
    if ($title === '') {
      $issues[] = self::issue('high', '标题为空。');
    }
    elseif ($titleLength < $minTitle) {
      $issues[] = self::issue('medium', '标题过短。');
    }
    elseif ($maxTitle > 0 && $titleLength > $maxTitle) {
      $issues[] = self::issue('low', '标题超过 80 个字。');
    }
    if ($title !== '' && preg_match('/^[\p{P}\p{S}\d\s]+$/u', $title)) {
      $issues[] = self::issue('medium', '标题没有实际文字。');
    }
    if (preg_match('/(.)\1{7,}/u', $title . $summary . $body)) {
      $issues[] = self::issue('medium', '文字里有异常的重复字符。');
    }
    $parts = preg_split('/[,，|、]/u', $title) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if (count($parts) >= 8) {
      $issues[] = self::issue('high', '标题像是关键词堆砌。');
    }
    if ($tags !== []) {
      $seen = [];
      foreach ($tags as $tag) {
        $key = mb_strtolower($tag);
        $seen[$key] = ($seen[$key] ?? 0) + 1;
      }
      if (max($seen) > 1) {
        $issues[] = self::issue('medium', '标签有重复。');
      }
      if ($maxTags > 0 && count($tags) > $maxTags) {
        $issues[] = self::issue('medium', '标签数量超过 12 个。');
      }
    }
    elseif (in_array($bundle, ['photo', 'video', 'article'], TRUE)) {
      $issues[] = self::issue('low', '没有标签。');
    }
    if ($bundle === 'article' && mb_strlen($body) < $minBody) {
      $issues[] = self::issue('medium', '文章正文过短或不完整。');
    }
    if ($bundle === 'article' && $body === '') {
      $issues[] = self::issue('high', '正文为空。');
    }
    $descriptionMissing = $summary === '' && !($bundle === 'video' && $body !== '');
    if (in_array($bundle, ['photo', 'video', 'article'], TRUE) && $descriptionMissing) {
      $issues[] = self::issue('medium', '描述为空。');
    }
    if (in_array($bundle, ['photo', 'video'], TRUE) && $summary === '' && $body === '') {
      $issues[] = self::issue('medium', '缺少简介。');
    }
    if (preg_match('/加微信|免费领取|点击购买|QQ群|代开发票/u', $title . $summary . $body)) {
      $issues[] = self::issue('high', '文字里有明显广告话术。');
    }
    if ($summary !== '' && $title !== '' && !self::sharesGram($title, $summary) && mb_strlen($summary) > 12) {
      $issues[] = self::issue('low', '简介和标题几乎没有相同用词，需要人工确认是否匹配。');
    }
    if ($duplicateTitleIds !== []) {
      $issues[] = self::issue('medium', '发现相同标题的其他内容：' . implode(', ', $duplicateTitleIds) . '。');
    }
    if ($duplicateTextIds !== []) {
      $issues[] = self::issue('high', '发现完全相同的正文或简介：' . implode(', ', $duplicateTextIds) . '。');
    }
    return $issues;
  }

  public static function sharesGram(string $left, string $right): bool {
    $left = self::grams($left);
    $right = self::grams($right);
    if ($left === [] || $right === []) {
      return FALSE;
    }
    return array_intersect_key($left, $right) !== [];
  }

  /**
   * @return array<string, int>
   *   Two-character grams.
   */
  private static function grams(string $text): array {
    $text = preg_replace('/\s+/u', '', mb_strtolower($text)) ?? '';
    $grams = [];
    $length = mb_strlen($text);
    for ($i = 0; $i < $length - 1; $i++) {
      $grams[mb_substr($text, $i, 2)] = 1;
    }
    return $grams;
  }

  /**
   * @return array{level: string, message: string}
   *   One finding.
   */
  private static function issue(string $level, string $message): array {
    return ['level' => $level, 'message' => $message];
  }

}
