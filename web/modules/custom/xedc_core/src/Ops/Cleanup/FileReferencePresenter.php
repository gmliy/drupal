<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Turns stored reference rows into counts and access-checked labels.
 */
class FileReferencePresenter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @param list<array<string, mixed>> $refs
   *   Stored rows for one file.
   *
   * @return array<string, int>
   *   Counts. Locations and entities are different numbers.
   */
  public function summarize(array $refs): array {
    $entities = [];
    $counts = [
      'locations' => count($refs),
      'entities' => 0,
      'node' => 0,
      'media' => 0,
      'user' => 0,
      'paragraph' => 0,
      'embedded' => 0,
      'custom' => 0,
      'unknown' => 0,
      'migratable' => 0,
      'blocked' => 0,
    ];
    foreach ($refs as $ref) {
      $type = (string) ($ref['entity_type'] ?? '');
      $id = (int) ($ref['entity_id'] ?? 0);
      if ($type !== '' && $id > 0) {
        $entities[$type . ':' . $id] = TRUE;
      }
      $source = (string) ($ref['source'] ?? '');
      if ($source === 'embedded') {
        $counts['embedded']++;
      }
      elseif ($source === 'custom') {
        $counts['custom']++;
      }
      elseif (isset($counts[$type])) {
        $counts[$type]++;
      }
      if ((string) ($ref['confidence'] ?? '') === 'unknown' || $source === 'embedded' || $source === 'custom') {
        $counts['unknown']++;
      }
      if ((int) ($ref['migratable'] ?? 0) === 1 && $source === 'field') {
        $counts['migratable']++;
      }
      else {
        $counts['blocked']++;
      }
    }
    $counts['entities'] = count($entities);
    return $counts;
  }

  /**
   * @param list<array<string, mixed>> $refs
   *   Stored rows.
   *
   * @return array{rows: list<array<string, string>>, total: int}
   *   One page of access-checked reference cards.
   */
  public function page(array $refs, AccountInterface $account, string $kind, int $offset, int $limit): array {
    $filtered = array_values(array_filter($refs, fn(array $ref): bool => $this->matches($ref, $kind)));
    $slice = array_slice($filtered, max(0, $offset), max(1, $limit));
    $rows = [];
    foreach ($slice as $ref) {
      $rows[] = $this->card($ref, $account);
    }
    return ['rows' => $rows, 'total' => count($filtered)];
  }

  /**
   * @param list<array<string, mixed>> $members
   *   Duplicate members.
   * @param array<int, array<string, int>> $summaries
   *   Summaries keyed by fid.
   *
   * @return array{fid: int, reason: string}
   *   A non-binding suggestion.
   */
  public function recommend(array $members, array $summaries): array {
    $candidates = [];
    foreach ($members as $member) {
      $fid = (int) ($member['fid'] ?? 0);
      $summary = $summaries[$fid] ?? [];
      if ($fid <= 0 || (int) ($member['has_entity'] ?? 0) !== 1 || (string) ($member['path'] ?? '') === '') {
        continue;
      }
      if (($summary['unknown'] ?? 0) > 0 || ($summary['custom'] ?? 0) > 0 || ($summary['embedded'] ?? 0) > 0) {
        continue;
      }
      $candidates[] = $member;
    }
    if ($candidates === []) {
      return ['fid' => 0, 'reason' => '存在未知、正文或自定义表引用，系统不推荐自动选择保留文件。'];
    }
    usort($candidates, static function (array $a, array $b): int {
      $time = ((int) $a['mtime']) <=> ((int) $b['mtime']);
      return $time !== 0 ? $time : ((int) $a['fid']) <=> ((int) $b['fid']);
    });
    $chosen = $candidates[0];
    return [
      'fid' => (int) $chosen['fid'],
      'reason' => '文件实体有效、没有未知引用，并且修改时间较早。这只供参考，不是按引用数量决定。',
    ];
  }

  /**
   * @param array<int, list<array<string, mixed>>> $refsByFid
   *   References keyed by file id.
   *
   * @return list<string>
   *   Entity keys used by more than one file in the group.
   */
  public function shared(array $refsByFid): array {
    $seen = [];
    $shared = [];
    foreach ($refsByFid as $refs) {
      $local = [];
      foreach ($refs as $ref) {
        $type = (string) ($ref['entity_type'] ?? '');
        $id = (int) ($ref['entity_id'] ?? 0);
        if ($type === '' || $id <= 0 || (string) ($ref['source'] ?? '') === 'custom') {
          continue;
        }
        $local[$type . ':' . $id] = TRUE;
      }
      foreach (array_keys($local) as $key) {
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        if ($seen[$key] === 2) {
          $shared[] = $key;
        }
      }
    }
    sort($shared);
    return $shared;
  }

  /**
   * @param array<string, mixed> $ref
   *   Stored row.
   *
   * @return array<string, string>
   *   Display card. Hidden entities do not expose titles.
   */
  public function card(array $ref, AccountInterface $account): array {
    $type = (string) ($ref['entity_type'] ?? '');
    $id = (int) ($ref['entity_id'] ?? 0);
    $source = (string) ($ref['source'] ?? '');
    $card = [
      'source' => $this->sourceLabel($source),
      'kind' => $this->kindOf($ref),
      'entity' => $type . ':' . $id,
      'title' => $source === 'custom' ? ('表 ' . $type . ' 记录 ' . $id) : '',
      'bundle' => (string) ($ref['bundle'] ?? ''),
      'revision' => (string) ($ref['revision_id'] ?? ''),
      'langcode' => (string) ($ref['langcode'] ?? ''),
      'field' => (string) ($ref['field_name'] ?? ''),
      'status' => $this->stateLabel($ref),
      'migratable' => (int) ($ref['migratable'] ?? 0) === 1 ? '可迁移' : '不能自动迁移',
      'reason' => (string) ($ref['reason'] ?? ''),
      'view' => '',
      'edit' => '',
      'parent' => '',
    ];
    if ($source === 'custom' || $type === '' || $id <= 0) {
      $card['title'] = $card['title'] !== '' ? $card['title'] : '自定义表记录';
      return $card;
    }
    try {
      $storage = $this->entityTypeManager->getStorage($type);
      $definition = $this->entityTypeManager->getDefinition($type);
      $revisionId = (int) ($ref['revision_id'] ?? 0);
      $entity = $definition->isRevisionable() && $revisionId > 0 ? $storage->loadRevision($revisionId) : $storage->load($id);
    }
    catch (\Throwable) {
      $card['title'] = '无法读取';
      $card['status'] = '引用分析不完整';
      return $card;
    }
    if (!$entity instanceof EntityInterface) {
      $card['title'] = '实体已不存在';
      return $card;
    }
    if (!$entity->access('view', $account)) {
      $card['title'] = '无权查看';
      $card['entity'] = $type;
      $card['bundle'] = '';
      return $card;
    }
    $card['title'] = (string) $entity->label();
    $card['view'] = $this->link($entity, 'canonical', $account);
    $card['edit'] = $this->link($entity, 'edit-form', $account);
    if (method_exists($entity, 'getParentEntity')) {
      $parent = $entity->getParentEntity();
      if ($parent instanceof EntityInterface && $parent->access('view', $account)) {
        $card['parent'] = (string) $parent->label();
        if ($card['edit'] === '') {
          $card['edit'] = $this->link($parent, 'edit-form', $account);
        }
      }
    }
    return $card;
  }

  /**
   * @param array<string, mixed> $ref
   *   Stored row.
   */
  private function matches(array $ref, string $kind): bool {
    if ($kind === '' || $kind === 'all') {
      return TRUE;
    }
    $source = (string) ($ref['source'] ?? '');
    return match ($kind) {
      'embedded' => $source === 'embedded',
      'custom' => $source === 'custom',
      'unknown' => (string) ($ref['confidence'] ?? '') === 'unknown' || $source === 'embedded' || $source === 'custom',
      'revision' => (int) ($ref['revision_id'] ?? 0) > 0,
      'translation' => (string) ($ref['langcode'] ?? '') !== '',
      default => (string) ($ref['entity_type'] ?? '') === $kind && $source === 'field',
    };
  }

  /**
   * @param array<string, mixed> $ref
   *   Stored row.
   */
  private function kindOf(array $ref): string {
    $source = (string) ($ref['source'] ?? '');
    if ($source === 'embedded' || $source === 'custom') {
      return $source;
    }
    return (string) ($ref['entity_type'] ?? '');
  }

  /**
   * @param array<string, mixed> $ref
   *   Stored row.
   */
  private function stateLabel(array $ref): string {
    $source = (string) ($ref['source'] ?? '');
    if ($source === 'embedded') {
      return '正文路径引用';
    }
    if ($source === 'custom') {
      return '自定义表引用';
    }
    if ((string) ($ref['confidence'] ?? '') === 'unknown') {
      return '未知引用';
    }
    if ((int) ($ref['migratable'] ?? 0) === 1) {
      return '已确认引用';
    }
    return '已识别但未验证';
  }

  private function sourceLabel(string $source): string {
    return match ($source) {
      'field' => '实体字段',
      'embedded' => '正文路径',
      'custom' => '自定义表',
      'file_usage' => 'file_usage',
      default => $source === '' ? '未知' : $source,
    };
  }

  private function link(EntityInterface $entity, string $rel, AccountInterface $account): string {
    try {
      if (!$entity->hasLinkTemplate($rel)) {
        return '';
      }
      $url = $entity->toUrl($rel);
      if (!$url->access($account)) {
        return '';
      }
      return $url->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
