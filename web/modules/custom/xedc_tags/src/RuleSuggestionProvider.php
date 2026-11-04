<?php

namespace Drupal\xedc_tags;

/**
 * Suggests existing tags from title, text and co-occurrence.
 *
 * Scores are rule-match levels, not model confidence.
 */
final class RuleSuggestionProvider implements SuggestionProviderInterface {

  public function suggest(array $input, array $terms, array $related): array {
    $current = array_map('intval', $input['current'] ?? []);
    $title = TagText::normalize(strip_tags((string) ($input['title'] ?? '')));
    $description = TagText::normalize(strip_tags((string) ($input['description'] ?? '')));
    $body = TagText::normalize(mb_substr(strip_tags((string) ($input['body'] ?? '')), 0, 8000));
    $bundle = (string) ($input['bundle'] ?? '');
    $found = [];
    foreach ($terms as $tid => $term) {
      $tid = (int) $tid;
      if (in_array($tid, $current, TRUE) || !(int) $term['status']) {
        continue;
      }
      $name = trim((string) $term['name']);
      if (mb_strlen($name) < 2) {
        continue;
      }
      $needle = TagText::normalize($name);
      if ($needle === '' || mb_strlen($needle) < 2) {
        continue;
      }
      $reasons = [];
      $score = 0;
      if ($title !== '' && mb_strpos($title, $needle) !== FALSE) {
        $reasons[] = '标题包含这个标签';
        $score = 92;
      }
      if ($description !== '' && mb_strpos($description, $needle) !== FALSE) {
        $reasons[] = '描述包含这个标签';
        $score = max($score, 74);
      }
      if ($body !== '' && mb_strpos($body, $needle) !== FALSE) {
        $reasons[] = '正文包含这个标签';
        $score = max($score, 58);
      }
      if ($score > 0 && $bundle !== '' && (int) ($term[$bundle] ?? 0) > 0) {
        $reasons[] = '这个标签已经用于同类型内容';
        $score = min(100, $score + 5);
      }
      if ($score > 0) {
        $found[$tid] = [
          'tid' => $tid,
          'name' => $name,
          'score' => $score,
          'reasons' => $reasons,
        ];
      }
    }
    foreach ($related as $item) {
      $tid = (int) $item['tid'];
      if (in_array($tid, $current, TRUE) || !isset($terms[$tid]) || $item['shared'] < 1 || $item['jaccard'] < 0.05) {
        continue;
      }
      $reason = '与当前标签“' . $item['from'] . '”在已发布内容中共同出现 ' . $item['shared'] . ' 次，Jaccard ' . number_format($item['jaccard'], 2);
      $boost = (int) round($item['jaccard'] * 80);
      if (isset($found[$tid])) {
        $found[$tid]['reasons'][] = $reason;
        $found[$tid]['score'] = min(100, $found[$tid]['score'] + min(20, $boost));
        continue;
      }
      if ($boost < 15) {
        continue;
      }
      $found[$tid] = [
        'tid' => $tid,
        'name' => (string) $terms[$tid]['name'],
        'score' => $boost,
        'reasons' => [$reason],
      ];
    }
    usort($found, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));
    return array_slice(array_values($found), 0, 12);
  }

}
