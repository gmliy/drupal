<?php

namespace Drupal\xedc_tags;

/**
 * Rule-based duplicate and near-duplicate groups.
 *
 * Near-duplicate groups are review suggestions. They are not safe merges.
 * No semantic model is used.
 */
final class TagDuplicateDetector {

  public function __construct(
    private TagCatalog $catalog,
    private TagAudit $audit,
  ) {}

  /**
   * @return array<int, array<string, mixed>>
   */
  public function groups(bool $includeDismissed = FALSE): array {
    $terms = $this->catalog->terms();
    $dismissed = [];
    if (!$includeDismissed) {
      foreach ($this->audit->decisions('duplicate') as $decision) {
        $dismissed[(string) $decision->subject] = TRUE;
      }
    }
    $groups = [];
    $grouped = [];
    $byKey = [];
    foreach ($terms as $tid => $term) {
      $key = TagText::normalize((string) $term['default_name']);
      if ($key === '') {
        continue;
      }
      $byKey[$key][] = (int) $tid;
    }
    foreach ($byKey as $key => $tids) {
      $components = $this->components($tids, $dismissed);
      foreach ($components as $component) {
        if (count($component) < 2) {
          continue;
        }
        $sampleLeft = $terms[$component[0]]['default_name'];
        $sampleRight = $terms[$component[1]]['default_name'];
        $compared = TagText::compare($sampleLeft, $sampleRight);
        $groups[] = $this->group('normalized', $component, $terms, $compared['reasons'] ?? ['规范化后相同'], TRUE, $compared['risk'] ?? '');
        foreach ($component as $tid) {
          $grouped[$tid] = TRUE;
        }
      }
      unset($key);
    }

    $ids = array_keys($terms);
    $edges = [];
    $reasons = [];
    $count = count($ids);
    for ($i = 0; $i < $count; $i++) {
      for ($j = $i + 1; $j < $count; $j++) {
        $left = (int) $ids[$i];
        $right = (int) $ids[$j];
        if (isset($grouped[$left], $grouped[$right])) {
          continue;
        }
        if (isset($dismissed[$this->audit->pairSubject($left, $right)])) {
          continue;
        }
        $compared = $this->compareTerms($terms[$left], $terms[$right]);
        if (!$compared || $compared['recommend_merge']) {
          continue;
        }
        $edges[] = [$left, $right];
        $reasons[$left . ':' . $right] = $compared;
      }
    }
    foreach ($this->clusters($edges) as $component) {
      if (count($component) < 2) {
        continue;
      }
      $reason = ['名称相似，需人工确认'];
      $risk = '相似不等于重复。系统没有做语义识别，也不会自动合并。';
      foreach ($component as $left) {
        foreach ($component as $right) {
          if ($left < $right && isset($reasons[$left . ':' . $right])) {
            $reason = $reasons[$left . ':' . $right]['reasons'];
            $risk = $reasons[$left . ':' . $right]['risk'];
            break 2;
          }
        }
      }
      $groups[] = $this->group('similar', $component, $terms, $reason, FALSE, $risk);
    }
    return $groups;
  }

  /**
   * @param array<string, mixed> $left
   * @param array<string, mixed> $right
   * @return array{reasons: string[], recommend_merge: bool, risk: string}|null
   */
  private function compareTerms(array $left, array $right): ?array {
    $primary = TagText::compare((string) $left['default_name'], (string) $right['default_name']);
    if ($primary) {
      return $primary;
    }
    foreach ($left['names'] as $leftName) {
      foreach ($right['names'] as $rightName) {
        $compared = TagText::compare((string) $leftName, (string) $rightName);
        if ($compared && $compared['recommend_merge']) {
          return [
            'reasons' => ['不同语言或译文的名称相同'],
            'recommend_merge' => FALSE,
            'risk' => '译文名称相同可能只是翻译用词，不能据此合并两个术语。',
          ];
        }
      }
    }
    return NULL;
  }

  /**
   * @param int[] $tids
   * @param array<string, bool> $dismissed
   * @return array<int, int[]>
   */
  private function components(array $tids, array $dismissed): array {
    $edges = [];
    $count = count($tids);
    for ($i = 0; $i < $count; $i++) {
      for ($j = $i + 1; $j < $count; $j++) {
        if (!isset($dismissed[$this->audit->pairSubject($tids[$i], $tids[$j])])) {
          $edges[] = [(int) $tids[$i], (int) $tids[$j]];
        }
      }
    }
    return $this->clusters($edges);
  }

  /**
   * @param array<int, array{0: int, 1: int}> $edges
   * @return array<int, int[]>
   */
  private function clusters(array $edges): array {
    $parent = [];
    $find = function (int $id) use (&$parent, &$find): int {
      if (!isset($parent[$id])) {
        $parent[$id] = $id;
      }
      if ($parent[$id] !== $id) {
        $parent[$id] = $find($parent[$id]);
      }
      return $parent[$id];
    };
    foreach ($edges as [$left, $right]) {
      $rootLeft = $find($left);
      $rootRight = $find($right);
      $parent[$rootRight] = $rootLeft;
    }
    $groups = [];
    foreach (array_keys($parent) as $id) {
      $groups[$find($id)][] = $id;
    }
    $out = [];
    foreach ($groups as $ids) {
      sort($ids);
      $out[] = $ids;
    }
    return $out;
  }

  /**
   * @param int[] $tids
   * @param array<int, array<string, mixed>> $terms
   * @param string[] $reasons
   * @return array<string, mixed>
   */
  private function group(string $kind, array $tids, array $terms, array $reasons, bool $recommend, string $risk): array {
    $members = [];
    $keep = NULL;
    foreach ($tids as $tid) {
      $row = $terms[$tid];
      $members[] = $row;
      if ($recommend) {
        if ($keep === NULL || (int) $row['published_any'] > (int) $terms[$keep]['published_any'] || ((int) $row['published_any'] === (int) $terms[$keep]['published_any'] && $tid < $keep)) {
          $keep = $tid;
        }
      }
    }
    return [
      'id' => $kind . ':' . implode('-', $tids),
      'kind' => $kind,
      'reasons' => $reasons,
      'recommend_merge' => $recommend,
      'risk' => $risk,
      'keep_tid' => $keep,
      'terms' => $members,
    ];
  }

}
