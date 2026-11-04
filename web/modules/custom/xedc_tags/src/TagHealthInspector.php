<?php

namespace Drupal\xedc_tags;

/**
 * Rule-based health report. Suggestions only; never deletes or merges.
 */
final class TagHealthInspector {

  public function __construct(
    private TagCatalog $catalog,
    private TagDuplicateDetector $duplicates,
    private TagAudit $audit,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function report(): array {
    $terms = $this->catalog->terms();
    $counts = array_map(static fn(array $term): int => (int) $term['published_any'], $terms);
    $threshold = $this->dynamicThreshold($counts);
    $issues = [];
    foreach ($terms as $tid => $term) {
      foreach ($this->inspectTerm($term, $threshold) as $issue) {
        $issues[] = $issue + ['tid' => (int) $tid, 'name' => (string) $term['name']];
      }
    }
    $groups = $this->duplicates->groups();
    $exact = 0;
    $similar = 0;
    foreach ($groups as $group) {
      if (!empty($group['recommend_merge'])) {
        $exact++;
      }
      else {
        $similar++;
      }
    }
    $bySeverity = ['error' => 0, 'warning' => 0, 'info' => 0];
    foreach ($issues as $issue) {
      $bySeverity[$issue['severity']] = ($bySeverity[$issue['severity']] ?? 0) + 1;
    }
    return [
      'generated' => time(),
      'threshold' => $threshold,
      'totals' => [
        'terms' => count($terms),
        'issues' => count($issues),
        'exact_duplicate_groups' => $exact,
        'similar_groups' => $similar,
        'unused' => count(array_filter($terms, static fn(array $t): bool => (int) $t['all'] === 0)),
        'low_quality' => count(array_filter($issues, static fn(array $i): bool => $i['code'] === 'low_quality')),
        'naming' => count(array_filter($issues, static fn(array $i): bool => $i['code'] === 'naming')),
        'orphan' => count(array_filter($issues, static fn(array $i): bool => $i['code'] === 'orphan')),
      ],
      'severity' => $bySeverity,
      'issues' => $issues,
      'trend' => $this->trendBuckets($terms, $threshold),
      'duplicate_groups' => count($groups),
    ];
  }

  /**
   * @param array<string, mixed> $term
   * @param array{high: int, low: int} $threshold
   * @return array<int, array{code: string, severity: string, message: string, action: string}>
   */
  public function inspectTerm(array $term, array $threshold): array {
    $issues = [];
    $name = (string) $term['default_name'];
    $normalized = TagText::normalize($name);

    if ($this->isLowQuality($name, $normalized)) {
      $issues[] = [
        'code' => 'low_quality',
        'severity' => 'warning',
        'message' => '低质量名称（无意义、纯数字/标点、过长或重复词）。',
        'action' => '人工改名或合并到有意义的标签，系统不会自动删除。',
      ];
    }
    foreach ($this->namingProblems($name) as $message) {
      $issues[] = [
        'code' => 'naming',
        'severity' => 'warning',
        'message' => $message,
        'action' => '按命名规范手工修正。',
      ];
    }
    if ((int) $term['all'] === 0) {
      $issues[] = [
        'code' => 'orphan',
        'severity' => 'info',
        'message' => '从未被内容引用。',
        'action' => '可保留待用，或在确认后手工删除。',
      ];
    }
    elseif ((int) $term['published_any'] === 0) {
      $issues[] = [
        'code' => 'orphan',
        'severity' => 'info',
        'message' => '只有未发布内容在使用。',
        'action' => '检查草稿是否仍需要该标签。',
      ];
    }
    if ((int) $term['published_any'] > 0 && (int) $term['published_any'] <= $threshold['low']) {
      $issues[] = [
        'code' => 'low_frequency',
        'severity' => 'info',
        'message' => '低频标签（已发布内容 ≤ ' . $threshold['low'] . '）。',
        'action' => '低频不等于低质量；结合 SEO 与内容价值判断。',
      ];
    }
    if ((int) $term['published_any'] >= $threshold['high'] && $threshold['high'] > 0) {
      $issues[] = [
        'code' => 'high_frequency',
        'severity' => 'info',
        'message' => '高频标签（已发布内容 ≥ ' . $threshold['high'] . '）。',
        'action' => '可关注是否过宽，必要时拆分相关标签。',
      ];
    }
    if (!(int) $term['status']) {
      $issues[] = [
        'code' => 'unpublished',
        'severity' => 'error',
        'message' => '术语本身未发布。',
        'action' => '确认是否应发布或合并。',
      ];
    }
    return $issues;
  }

  /**
   * Health label for list pages.
   *
   * @param array<string, mixed> $term
   */
  public function status(array $term): string {
    $threshold = $this->dynamicThreshold(array_map(static fn(array $t): int => (int) $t['published_any'], $this->catalog->terms()));
    $issues = $this->inspectTerm($term, $threshold);
    foreach ($issues as $issue) {
      if ($issue['severity'] === 'error') {
        return 'error';
      }
    }
    foreach ($issues as $issue) {
      if ($issue['severity'] === 'warning' || in_array($issue['code'], ['low_quality', 'naming'], TRUE)) {
        return 'warning';
      }
    }
    foreach ($this->duplicates->groups() as $group) {
      foreach ($group['terms'] as $member) {
        if ((int) $member['tid'] === (int) $term['tid']) {
          return !empty($group['recommend_merge']) ? 'warning' : 'warning';
        }
      }
    }
    return 'ok';
  }

  /**
   * @param int[] $counts
   * @return array{high: int, low: int}
   */
  public function dynamicThreshold(array $counts): array {
    $positive = array_values(array_filter($counts, static fn(int $n): bool => $n > 0));
    if (!$positive) {
      return ['high' => 0, 'low' => 0];
    }
    sort($positive);
    $n = count($positive);
    $low = $positive[(int) max(0, floor(($n - 1) * 0.25))];
    $high = $positive[(int) min($n - 1, floor(($n - 1) * 0.75))];
    if ($high <= $low) {
      $high = max($low + 1, (int) ceil(array_sum($positive) / $n));
    }
    return ['high' => $high, 'low' => max(1, $low)];
  }

  private function isLowQuality(string $name, string $normalized): bool {
    if ($normalized === '') {
      return TRUE;
    }
    if (preg_match('/^[\d\s]+$/u', $normalized)) {
      return TRUE;
    }
    if (preg_match('/^[\p{P}\p{S}\s]+$/u', $name)) {
      return TRUE;
    }
    if (mb_strlen($normalized) > 40) {
      return TRUE;
    }
    if (preg_match('/^(.)\1{3,}$/u', $normalized)) {
      return TRUE;
    }
    $chars = preg_split('//u', preg_replace('/\s+/u', '', $normalized) ?? $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($chars) >= 4 && count(array_unique($chars)) === 1) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @return string[]
   */
  private function namingProblems(string $name): array {
    $messages = [];
    if ($name !== trim($name)) {
      $messages[] = '首尾存在空格。';
    }
    if (preg_match('/\s{2,}/u', $name)) {
      $messages[] = '存在连续空格。';
    }
    if (preg_match('/[\x{FF00}-\x{FFEF}]/u', $name)) {
      $messages[] = '含全角字符，建议统一半角标点或规范用字。';
    }
    if (preg_match('/[A-Z]/u', $name) && preg_match('/[a-z]/u', $name) && !preg_match('/\p{Han}/u', $name)) {
      $messages[] = '英文大小写混用，建议统一规范。';
    }
    if (TagText::looksTraditional($name) && TagText::looksSimplified($name)) {
      $messages[] = '疑似简繁体混用。';
    }
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    if (count($parts) >= 2 && count(array_unique($parts)) < count($parts)) {
      $messages[] = '名称中有重复词语。';
    }
    return $messages;
  }

  /**
   * @param array<int, array<string, mixed>> $terms
   * @param array{high: int, low: int} $threshold
   * @return array<string, int>
   */
  private function trendBuckets(array $terms, array $threshold): array {
    $high = $mid = $low = $unused = $stale = 0;
    $now = time();
    foreach ($terms as $term) {
      $pub = (int) $term['published_any'];
      if ($pub === 0) {
        $unused++;
      }
      elseif ($pub >= $threshold['high']) {
        $high++;
      }
      elseif ($pub <= $threshold['low']) {
        $low++;
      }
      else {
        $mid++;
      }
      if ($pub > 0 && (int) $term['latest'] > 0 && ($now - (int) $term['latest']) > 86400 * 180) {
        $stale++;
      }
    }
    return [
      'high' => $high,
      'mid' => $mid,
      'low' => $low,
      'unused' => $unused,
      'stale' => $stale,
    ];
  }

}
