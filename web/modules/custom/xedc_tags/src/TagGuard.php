<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Finds references that must block a delete or be migrated during a merge.
 */
final class TagGuard {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private Connection $database,
    private EntityFieldManagerInterface $fields,
  ) {}

  /**
   * Content references that a merge can rewrite.
   *
   * @return array<int, array{entity_type: string, field: string, ids: int[]}>
   */
  public function contentReferences(int $tid): array {
    $out = [];
    foreach ($this->referenceFields() as $field) {
      if (!$field['migratable']) {
        continue;
      }
      $ids = $this->ids($field, $tid);
      if ($ids) {
        $out[] = [
          'entity_type' => $field['entity_type'],
          'field' => $field['field'],
          'ids' => $ids,
        ];
      }
    }
    return $out;
  }

  /**
   * References that are not rewritten automatically.
   *
   * @return array<int, array{type: string, label: string}>
   */
  public function blockers(int $tid): array {
    $blockers = [];
    foreach ($this->referenceFields() as $field) {
      if ($field['migratable']) {
        continue;
      }
      $ids = $this->ids($field, $tid);
      if ($ids) {
        $blockers[] = [
          'type' => $field['entity_type'] . '.' . $field['field'],
          'label' => $field['entity_type'] . ' 的 ' . $field['field'] . ' 仍引用这个标签（' . count($ids) . '）',
        ];
      }
    }
    foreach ($this->menuLinks($tid) as $id) {
      $blockers[] = [
        'type' => 'menu:' . $id,
        'label' => '菜单链接 #' . $id . ' 指向这个标签',
      ];
    }
    foreach ($this->redirects($tid) as $id) {
      $blockers[] = [
        'type' => 'redirect:' . $id,
        'label' => '跳转 #' . $id . ' 指向这个标签',
      ];
    }
    return $blockers;
  }

  public function contentCount(int $tid): int {
    $count = 0;
    foreach ($this->contentReferences($tid) as $group) {
      $count += count($group['ids']);
    }
    return $count;
  }

  /**
   * @return array<int, array{entity_type: string, field: string, table: string, column: string, migratable: bool}>
   */
  private function referenceFields(): array {
    $out = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entityType => $definition) {
      if (!$definition->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      try {
        $storages = $this->fields->getFieldStorageDefinitions($entityType);
      }
      catch (\Throwable) {
        continue;
      }
      foreach ($storages as $fieldName => $storage) {
        if (!$storage instanceof FieldStorageDefinitionInterface || $storage->getType() !== 'entity_reference') {
          continue;
        }
        if (($storage->getSettings()['target_type'] ?? '') !== 'taxonomy_term') {
          continue;
        }
        $table = $entityType . '__' . $fieldName;
        $column = $fieldName . '_target_id';
        if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, $column)) {
          continue;
        }
        $migratable = !($entityType === 'taxonomy_term' && $fieldName === 'parent');
        $out[] = [
          'entity_type' => $entityType,
          'field' => $fieldName,
          'table' => $table,
          'column' => $column,
          'migratable' => $migratable,
        ];
      }
    }
    return $out;
  }

  /**
   * @param array{entity_type: string, field: string, table: string, column: string, migratable: bool} $field
   * @return int[]
   */
  private function ids(array $field, int $tid): array {
    $query = $this->database->select($field['table'], 't');
    $query->condition($field['column'], $tid);
    if ($this->database->schema()->fieldExists($field['table'], 'deleted')) {
      $query->condition('deleted', 0);
    }
    $query->addField('t', 'entity_id');
    $query->distinct();
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * @return int[]
   */
  private function menuLinks(int $tid): array {
    if (!$this->database->schema()->tableExists('menu_link_content_data')) {
      return [];
    }
    $uris = [
      'entity:taxonomy_term/' . $tid,
      'internal:/taxonomy/term/' . $tid,
    ];
    $query = $this->database->select('menu_link_content_data', 'm');
    $query->condition('link__uri', $uris, 'IN');
    $query->addField('m', 'id');
    $query->distinct();
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * @return int[]
   */
  private function redirects(int $tid): array {
    if (!$this->database->schema()->tableExists('redirect')) {
      return [];
    }
    $uris = [
      'entity:taxonomy_term/' . $tid,
      'internal:/taxonomy/term/' . $tid,
    ];
    $query = $this->database->select('redirect', 'r');
    $query->condition('redirect_redirect__uri', $uris, 'IN');
    $query->addField('r', 'rid');
    return array_map('intval', $query->execute()->fetchCol());
  }

}
