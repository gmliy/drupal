<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds tag suggestions and applies only the ones an editor confirms.
 */
final class TagRecommender {

  public function __construct(
    private SuggestionProviderInterface $provider,
    private TagRelations $relations,
    private TagCatalog $catalog,
    private TagAudit $audit,
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @param int[] $current
   * @param int[] $excluded
   * @return array<int, array{tid: int, name: string, score: int, reasons: string[]}>
   */
  public function suggest(string $title, string $body, string $description, array $current, string $bundle, array $excluded = []): array {
    $related = [];
    $terms = $this->catalog->terms();
    foreach ($current as $tid) {
      $tid = (int) $tid;
      $from = (string) ($terms[$tid]['name'] ?? '');
      foreach ($this->relations->forTerm($tid, 12, TRUE) as $item) {
        $item['from'] = $from;
        $other = (int) $item['tid'];
        if (!isset($related[$other]) || $item['jaccard'] > $related[$other]['jaccard']) {
          $related[$other] = $item;
        }
      }
    }
    $suggestions = $this->provider->suggest([
      'title' => $title,
      'body' => $body,
      'description' => $description,
      'current' => $current,
      'bundle' => $bundle,
    ], $terms, array_values($related));
    return array_values(array_filter($suggestions, static fn(array $item): bool => !in_array($item['tid'], $excluded, TRUE)));
  }

  /**
   * @return array{title: string, body: string, description: string, current: int[], bundle: string}
   */
  public function fromNode(NodeInterface $node): array {
    $node = \Drupal::service('entity.repository')->getTranslationFromContext($node);
    $description = '';
    foreach (['field_summary', 'field_description'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $description = trim(strip_tags((string) $node->get($field)->value));
        if ($description !== '') {
          break;
        }
      }
    }
    $body = '';
    foreach (['field_body', 'body'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $body = trim(strip_tags((string) $node->get($field)->value));
        if ($body !== '') {
          break;
        }
      }
    }
    $current = [];
    if ($node->hasField('field_tags')) {
      $current = array_map('intval', array_column($node->get('field_tags')->getValue(), 'target_id'));
    }
    return [
      'title' => (string) $node->label(),
      'body' => $body,
      'description' => $description,
      'current' => $current,
      'bundle' => $node->bundle(),
    ];
  }

  /**
   * @param int[] $tids
   */
  public function apply(NodeInterface $node, array $tids, int $uid): int {
    if (!$node->hasField('field_tags')) {
      return 0;
    }
    $allowed = $this->catalog->terms();
    $current = array_map('intval', array_column($node->getUntranslated()->get('field_tags')->getValue(), 'target_id'));
    $added = 0;
    foreach ($tids as $tid) {
      $tid = (int) $tid;
      if ($tid <= 0 || !isset($allowed[$tid]) || in_array($tid, $current, TRUE)) {
        continue;
      }
      $current[] = $tid;
      $added++;
    }
    if (!$added) {
      return 0;
    }
    $node->getUntranslated()->set('field_tags', array_map(static fn(int $id): array => ['target_id' => $id], $current));
    if (method_exists($node, 'setRevisionUserId')) {
      $node->setRevisionUserId($uid);
      $node->setRevisionLogMessage('人工确认后添加推荐标签。原有标签未删除。');
    }
    $node->save();
    $this->audit->log('recommend_apply', '为内容 ' . $node->id() . ' 添加标签', ['tids' => $tids], 0, $uid);
    \Drupal::service('cache_tags.invalidator')->invalidateTags($this->catalog->cacheTags());
    return $added;
  }

  /**
   * @return int[]
   */
  public function ignored(int $nid): array {
    $out = [];
    foreach ($this->audit->decisions('recommend') as $decision) {
      $subject = (string) $decision->subject;
      if (str_starts_with($subject, $nid . ':')) {
        $out[] = (int) substr($subject, strlen((string) $nid) + 1);
      }
    }
    return $out;
  }

}
