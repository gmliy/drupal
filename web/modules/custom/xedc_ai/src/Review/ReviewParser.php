<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

/**
 * Validates model JSON before any result is stored as a completed review.
 */
final class ReviewParser {

  /**
   * @return array{ok: bool, error: string, result: array<string, mixed>}
   *   Parsed review. ok is false when the payload cannot be trusted.
   */
  public static function review(string $text): array {
    $data = self::decode($text);
    if ($data === NULL) {
      return self::fail(self::refusal($text) ? '模型拒绝回答。' : '模型没有返回有效 JSON。');
    }
    $status = (string) ($data['status'] ?? '');
    $statuses = [
      'pass' => 'pass',
      'attention' => 'attention',
      'fail' => 'fail',
      'review_required' => 'review_required',
    ];
    if (!isset($statuses[$status])) {
      return self::fail('审核状态无效。');
    }
    $risk = (string) ($data['risk_level'] ?? '');
    if (!in_array($risk, ['normal', 'low', 'medium', 'high'], TRUE)) {
      return self::fail('风险等级无效。');
    }
    $score = self::score($data['score'] ?? NULL);
    if ($score === NULL) {
      return self::fail('评分无效。');
    }
    $seo = array_key_exists('seo_score', $data) ? self::score($data['seo_score']) : $score;
    if ($seo === NULL) {
      return self::fail('评分无效。');
    }
    $summary = trim((string) ($data['summary'] ?? ''));
    if ($summary === '') {
      return self::fail('审核摘要为空。');
    }
    if (!is_array($data['issues'] ?? NULL) || !is_array($data['suggestions'] ?? NULL)) {
      return self::fail('问题或建议不是列表。');
    }
    return [
      'ok' => TRUE,
      'error' => '',
      'result' => [
        'status' => $statuses[$status],
        'risk_level' => $risk,
        'score' => $score,
        'seo_score' => $seo,
        'issues' => self::lines($data['issues']),
        'suggestions' => self::lines($data['suggestions']),
        'summary' => self::clip($summary, 800),
        'issue_items' => self::issueItems($data['issues']),
        'suggestion_items' => self::suggestionItems($data['suggestions']),
      ],
    ];
  }

  /**
   * @return array{ok: bool, error: string, result: array<string, mixed>}
   *   Parsed SEO suggestion.
   */
  /**
   * @param array<string, mixed> $context
   *   Extracted node text. The score is calculated from this, not from the model.
   */
  public static function seo(string $text, array $context = []): array {
    return \Drupal\xedc_ai\Seo\SeoResult::parse($text, $context);
  }

  /**
   * @return array{ok: bool, error: string, result: array<string, mixed>}
   *   Translation suggestion. Nothing in the result is executed.
   */
  public static function translation(string $text): array {
    $data = self::decode($text);
    if ($data === NULL) {
      return self::fail(self::refusal($text) ? '模型拒绝回答。' : '模型没有返回有效 JSON。');
    }
    $summary = trim((string) ($data['summary'] ?? ''));
    $items = $data['items'] ?? NULL;
    if ($summary === '' || !is_array($items)) {
      return self::fail('翻译建议缺少摘要或列表。');
    }
    $lines = [];
    $stored = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $suggested = self::clip(trim((string) ($item['suggested'] ?? '')), 500);
      if ($suggested === '') {
        continue;
      }
      $field = self::clip(trim((string) ($item['field'] ?? '')), 64);
      $lines[] = trim($field . '：' . $suggested);
      $stored[] = [
        'field' => $field,
        'original' => self::clip(trim((string) ($item['original'] ?? '')), 500),
        'suggested' => $suggested,
        'langcode' => self::clip(trim((string) ($item['langcode'] ?? '')), 12),
      ];
      if (count($stored) >= 20) {
        break;
      }
    }
    if ($stored === []) {
      return self::fail('翻译建议没有可用译文。');
    }
    return [
      'ok' => TRUE,
      'error' => '',
      'result' => [
        'status' => 'pass',
        'risk_level' => 'low',
        'score' => 0,
        'seo_score' => 0,
        'issues' => [],
        'suggestions' => $lines,
        'summary' => self::clip($summary, 800),
        'suggestion_items' => $stored,
      ],
    ];
  }

  /**
   * @return array<string, mixed>|null
   *   Object payload, or NULL.
   */
  public static function decode(string $text): ?array {
    $text = trim($text);
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $matches)) {
      $text = $matches[1];
    }
    else {
      $start = strpos($text, '{');
      $end = strrpos($text, '}');
      if ($start === FALSE || $end === FALSE || $end <= $start) {
        return NULL;
      }
      $text = substr($text, $start, $end - $start + 1);
    }
    $data = json_decode($text, TRUE);
    return is_array($data) ? $data : NULL;
  }

  private static function score(mixed $value): ?int {
    if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
      if (!is_float($value)) {
        return NULL;
      }
    }
    $score = (int) $value;
    return ($score >= 0 && $score <= 100) ? $score : NULL;
  }

  /**
   * @return list<string>
   *   Display lines.
   */
  private static function lines(array $items): array {
    $lines = [];
    foreach ($items as $item) {
      if (is_string($item) || is_numeric($item)) {
        $line = trim((string) $item);
      }
      elseif (is_array($item)) {
        $line = trim((string) ($item['message'] ?? $item['text'] ?? $item['suggested'] ?? ''));
      }
      else {
        continue;
      }
      if ($line !== '') {
        $lines[] = self::clip($line, 500);
      }
      if (count($lines) >= 30) {
        break;
      }
    }
    return $lines;
  }

  /**
   * @return list<array{type: string, severity: string, field: string, message: string, suggestion: string}>
   *   Structured issues. Strings in the model payload are skipped.
   */
  private static function issueItems(array $items): array {
    $itemsOut = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $message = trim((string) ($item['message'] ?? ''));
      if ($message === '') {
        continue;
      }
      $severity = (string) ($item['severity'] ?? 'low');
      if (!in_array($severity, ['low', 'medium', 'high'], TRUE)) {
        $severity = 'low';
      }
      $itemsOut[] = [
        'type' => self::clip(trim((string) ($item['type'] ?? 'content')), 32),
        'severity' => $severity,
        'field' => self::clip(trim((string) ($item['field'] ?? '')), 64),
        'message' => self::clip($message, 500),
        'suggestion' => self::clip(trim((string) ($item['suggestion'] ?? '')), 500),
      ];
      if (count($itemsOut) >= 30) {
        break;
      }
    }
    return $itemsOut;
  }

  /**
   * @return list<array{field: string, original: string, suggested: string, reason: string}>
   *   Structured suggestions.
   */
  private static function suggestionItems(array $items): array {
    $itemsOut = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $suggested = trim((string) ($item['suggested'] ?? ''));
      if ($suggested === '') {
        continue;
      }
      $itemsOut[] = [
        'field' => self::clip(trim((string) ($item['field'] ?? '')), 64),
        'original' => self::clip(trim((string) ($item['original'] ?? '')), 500),
        'suggested' => self::clip($suggested, 500),
        'reason' => self::clip(trim((string) ($item['reason'] ?? '')), 500),
      ];
      if (count($itemsOut) >= 30) {
        break;
      }
    }
    return $itemsOut;
  }

  private static function clip(string $value, int $limit): string {
    return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
  }

  private static function refusal(string $text): bool {
    return (bool) preg_match("/(can['’]t assist|cannot assist|i cannot|我不能|无法回答|拒绝回答)/iu", $text);
  }

  /**
   * @return array{ok: bool, error: string, result: array<string, mixed>}
   *   Failure payload.
   */
  private static function fail(string $error): array {
    return ['ok' => FALSE, 'error' => $error, 'result' => []];
  }

}
