<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;

/**
 * Finds file references in fields, revisions, translations, usage and text.
 *
 * A file is confirmed unused only when every supported source was read and
 * none of them point at the file. Anything unread stays unknown.
 */
class FileReferenceAnalyzer {

  /**
   * @var list<array<string, mixed>>|null
   */
  private ?array $sources = NULL;

  private bool $incomplete = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $fieldManager,
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected CleanupRepository $repository,
    protected FilePathGuard $guard,
  ) {}

  /**
   * @param array<string, mixed> $batch
   *   Mutable batch.
   */
  public function referenceStep(array &$batch, float $deadline): bool {
    $batchId = (int) $batch['id'];
    $state = $batch['state'];
    if (empty($state['refs_cleared'])) {
      $this->repository->clearRefs($batchId);
      $state['refs_cleared'] = 1;
      $state['ref_index'] = 0;
      $state['ref_offset'] = 0;
      $this->recordUsageAndCustom($batchId, array_keys($this->repository->workByFid($batchId)));
      $batch['state'] = $state;
    }
    $sources = $this->fileSources();
    $fids = array_keys($this->repository->workByFid($batchId));
    $index = (int) ($state['ref_index'] ?? 0);
    while ($index < count($sources) && microtime(TRUE) < $deadline) {
      $source = $sources[$index];
      $offset = (int) ($state['ref_offset'] ?? 0);
      $rows = $this->fetchTargets($source, $fids, $offset, 200);
      foreach ($rows as $row) {
        $this->repository->insertRef($this->fieldRef($batchId, $source, $row));
      }
      if (count($rows) === 200) {
        $state['ref_offset'] = $offset + 200;
        $batch['state'] = $state;
        $batch['message'] = '正在读取字段引用。';
        return TRUE;
      }
      $index++;
      $state['ref_index'] = $index;
      $state['ref_offset'] = 0;
    }
    $batch['state'] = $state;
    if ($index < count($sources)) {
      return TRUE;
    }
    $state['reference_incomplete'] = $this->incomplete;
    $batch['state'] = $state;
    return FALSE;
  }

  /**
   * @param array<string, mixed> $batch
   *   Mutable batch.
   */
  public function textStep(array &$batch, float $deadline): bool {
    $batchId = (int) $batch['id'];
    $state = $batch['state'];
    $state['text_index'] = (int) ($state['text_index'] ?? 0);
    $state['text_offset'] = (int) ($state['text_offset'] ?? 0);
    $needles = $this->needleMap($batchId);
    $sources = $this->textSources();
    $index = (int) $state['text_index'];
    while ($index < count($sources) && microtime(TRUE) < $deadline) {
      $source = $sources[$index];
      $offset = (int) $state['text_offset'];
      $rows = $this->fetchText($source, $offset, 100);
      if ($rows === NULL) {
        $this->incomplete = TRUE;
        $index++;
        $state['text_index'] = $index;
        $state['text_offset'] = 0;
        continue;
      }
      foreach ($rows as $row) {
        $value = (string) ($row['value'] ?? '');
        if ($value === '') {
          continue;
        }
        foreach ($needles as $needle => $fid) {
          if (str_contains($value, $needle)) {
            $this->repository->insertRef([
              'batch_id' => $batchId,
              'fid' => $fid,
              'entity_type' => $source['entity_type'],
              'entity_id' => (int) ($row['entity_id'] ?? 0),
              'revision_id' => (int) ($row['revision_id'] ?? 0),
              'langcode' => (string) ($row['langcode'] ?? ''),
              'field_name' => $source['field'],
              'delta' => (int) ($row['delta'] ?? 0),
              'bundle' => (string) ($row['bundle'] ?? ''),
              'source' => 'embedded',
              'confidence' => 'unknown',
              'migratable' => 0,
              'reason' => '正文或链接里出现了文件路径，不能自动改写。',
            ]);
          }
        }
      }
      if (count($rows) === 100) {
        $state['text_offset'] = $offset + 100;
        if ($state['text_offset'] > 5000) {
          $this->incomplete = TRUE;
          $index++;
          $state['text_index'] = $index;
          $state['text_offset'] = 0;
          continue;
        }
        $batch['state'] = $state;
        $batch['message'] = '正在检查正文中的文件路径。';
        return TRUE;
      }
      $index++;
      $state['text_index'] = $index;
      $state['text_offset'] = 0;
    }
    $state['text_incomplete'] = $this->incomplete || !empty($state['reference_incomplete']);
    $batch['state'] = $state;
    return $index < count($sources);
  }

  /**
   * Live check for one file. Does not trust a previous batch.
   *
   * @return array<string, mixed>
   *   Status, reason and references.
   */
  public function recheck(int $fid): array {
    $file = NULL;
    try {
      $loaded = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($loaded instanceof FileInterface) {
        $file = $loaded;
      }
    }
    catch (\Throwable) {
      return $this->unknownResult('读取文件实体失败。');
    }
    if (!$file) {
      return [
        'complete' => TRUE,
        'status' => 'missing_entity',
        'reason' => '没有对应的文件实体。',
        'refs' => [],
        'kind' => 'outside',
      ];
    }
    $uri = $file->getFileUri();
    $kind = $this->guard->kindForUri($uri);
    $real = $this->fileSystem->realpath($uri);
    if (is_string($real) && $real !== '') {
      $pathKind = $this->guard->containedKind($real);
      if ($pathKind === 'outside') {
        $kind = 'outside';
      }
      elseif ($kind === 'content') {
        $kind = $pathKind;
      }
    }
    if ($kind === 'quarantine') {
      try {
        $refs = $this->liveRefs($fid, $uri);
      }
      catch (\Throwable $exception) {
        return $this->unknownResult('隔离文件的引用复查失败：' . $exception->getMessage());
      }
      $classified = $this->classifyRefs($refs, '隔离文件没有新的引用。');
      if (($classified['status'] ?? '') === 'confirmed_unused' && !$this->incomplete) {
        $classified['status'] = 'quarantine';
        $classified['kind'] = 'quarantine';
        return $classified;
      }
      $classified['kind'] = 'quarantine';
      return $classified;
    }
    if (!$this->deletableKind($kind)) {
      return [
        'complete' => TRUE,
        'status' => $this->kindCategory($kind),
        'reason' => $this->kindReason($kind),
        'refs' => [],
        'kind' => $kind,
        'uri' => $uri,
      ];
    }
    $exists = is_string($real) && is_file($real);
    if (!$exists) {
      return [
        'complete' => TRUE,
        'status' => 'missing_physical',
        'reason' => '数据库有记录，物理文件不存在。',
        'refs' => [],
        'kind' => $kind,
        'uri' => $uri,
      ];
    }
    if ((int) $file->get('status')->value !== FileInterface::STATUS_PERMANENT) {
      return [
        'complete' => TRUE,
        'status' => 'temporary',
        'reason' => '这是临时文件，不纳入未引用清理。',
        'refs' => [],
        'kind' => $kind,
        'uri' => $uri,
      ];
    }
    try {
      $refs = $this->liveRefs($fid, $uri);
    }
    catch (\Throwable $exception) {
      return $this->unknownResult('引用复查失败：' . $exception->getMessage());
    }
    if ($this->incomplete) {
      return $this->unknownResult('有字段未能读取，引用关系未知。');
    }
    return $this->classifyRefs($refs, '物理文件存在，引用复查已完成。');
  }

  /**
   * @param list<array<string, mixed>> $refs
   *   References.
   *
   * @return list<array<string, mixed>>
   *   Rows that block a merge.
   */
  public function blockers(array $refs): array {
    $blocked = [];
    foreach ($refs as $ref) {
      if ((string) ($ref['confidence'] ?? '') === 'unknown' || (int) ($ref['migratable'] ?? 0) !== 1) {
        $blocked[] = $ref;
      }
    }
    return $blocked;
  }

  /**
   * @param list<int> $fids
   *   Files in this batch.
   */
  private function recordUsageAndCustom(int $batchId, array $fids): void {
    if ($fids === []) {
      return;
    }
    if ($this->database->schema()->tableExists('file_usage')) {
      $rows = $this->database->select('file_usage', 'u')
        ->fields('u')
        ->condition('fid', $fids, 'IN')
        ->execute();
      foreach ($rows as $row) {
        $module = (string) $row->module;
        $known = $module === 'file';
        $this->repository->insertRef([
          'batch_id' => $batchId,
          'fid' => (int) $row->fid,
          'entity_type' => (string) $row->type,
          'entity_id' => (int) $row->id,
          'revision_id' => 0,
          'langcode' => '',
          'field_name' => $module,
          'delta' => 0,
          'bundle' => '',
          'source' => 'file_usage',
          'confidence' => $known ? 'confirmed' : 'unknown',
          'migratable' => $known ? 1 : 0,
          'reason' => $known ? 'file 模块的使用记录会在字段迁移后同步。' : '文件使用记录来自模块 ' . $module . '，不能自动迁移。',
        ]);
      }
    }
    else {
      $this->incomplete = TRUE;
    }
    foreach ([
      'xedc_image_file' => 'fid',
      'xedc_import_item' => 'fid',
      'xedc_import_task' => 'archive_fid',
    ] as $table => $column) {
      if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, $column)) {
        continue;
      }
      $ids = $this->database->select($table, 't')
        ->fields('t', [$column])
        ->condition($column, $fids, 'IN')
        ->execute()
        ->fetchCol();
      foreach ($ids as $id) {
        $this->repository->insertRef([
          'batch_id' => $batchId,
          'fid' => (int) $id,
          'entity_type' => $table,
          'entity_id' => 0,
          'revision_id' => 0,
          'langcode' => '',
          'field_name' => $column,
          'delta' => 0,
          'bundle' => '',
          'source' => 'custom',
          'confidence' => 'confirmed',
          'migratable' => 0,
          'reason' => '自定义表 ' . $table . ' 记录了此文件，不能自动改写。',
        ]);
      }
    }
  }

  /**
   * @return list<array<string, mixed>>
   *   Fresh references.
   */
  private function liveRefs(int $fid, string $uri): array {
    $this->incomplete = FALSE;
    $refs = [];
    if ($this->database->schema()->tableExists('file_usage')) {
      $rows = $this->database->select('file_usage', 'u')->fields('u')->condition('fid', $fid)->execute();
      foreach ($rows as $row) {
        $module = (string) $row->module;
        $known = $module === 'file';
        $refs[] = [
          'fid' => $fid,
          'entity_type' => (string) $row->type,
          'entity_id' => (int) $row->id,
          'revision_id' => 0,
          'langcode' => '',
          'field_name' => $module,
          'delta' => 0,
          'bundle' => '',
          'source' => 'file_usage',
          'confidence' => $known ? 'confirmed' : 'unknown',
          'migratable' => $known ? 1 : 0,
          'reason' => $known ? 'file 模块的使用记录会在字段迁移后同步。' : '文件使用记录来自模块 ' . $module . '，不能自动迁移。',
        ];
      }
    }
    else {
      $this->incomplete = TRUE;
    }
    foreach ([
      'xedc_image_file' => 'fid',
      'xedc_import_item' => 'fid',
      'xedc_import_task' => 'archive_fid',
    ] as $table => $column) {
      if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, $column)) {
        continue;
      }
      $count = (int) $this->database->select($table, 't')
        ->condition($column, $fid)
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($count > 0) {
        $refs[] = [
          'fid' => $fid,
          'entity_type' => $table,
          'entity_id' => 0,
          'revision_id' => 0,
          'langcode' => '',
          'field_name' => $column,
          'delta' => 0,
          'bundle' => '',
          'source' => 'custom',
          'confidence' => 'confirmed',
          'migratable' => 0,
          'reason' => '自定义表 ' . $table . ' 记录了此文件，不能自动改写。',
        ];
      }
    }
    foreach ($this->fileSources() as $source) {
      foreach ($this->fetchTargets($source, [$fid], 0, 500) as $row) {
        $refs[] = $this->fieldRef(0, $source, $row);
      }
    }
    foreach ($this->textSources() as $source) {
      $rows = $this->fetchTextMatches($source, $this->needles($uri));
      if ($rows === NULL) {
        $this->incomplete = TRUE;
        continue;
      }
      foreach ($rows as $row) {
        $refs[] = [
          'fid' => $fid,
          'entity_type' => $source['entity_type'],
          'entity_id' => (int) ($row['entity_id'] ?? 0),
          'revision_id' => (int) ($row['revision_id'] ?? 0),
          'langcode' => (string) ($row['langcode'] ?? ''),
          'field_name' => $source['field'],
          'delta' => (int) ($row['delta'] ?? 0),
          'bundle' => (string) ($row['bundle'] ?? ''),
          'source' => 'embedded',
          'confidence' => 'unknown',
          'migratable' => 0,
          'reason' => '正文或链接里出现了文件路径，不能自动改写。',
        ];
      }
    }
    return $refs;
  }

  /**
   * @param list<array<string, mixed>> $refs
   *   References.
   *
   * @return array<string, mixed>
   *   Classification.
   */
  private function classifyRefs(array $refs, string $cleanReason): array {
    foreach ($refs as $ref) {
      if ((string) $ref['confidence'] === 'unknown') {
        return [
          'complete' => TRUE,
          'status' => 'unknown',
          'reason' => (string) ($ref['reason'] ?: '存在不能确认的引用。'),
          'refs' => $refs,
          'kind' => 'content',
        ];
      }
    }
    $uses = array_filter($refs, static fn(array $ref): bool => $ref['source'] !== 'file_usage' || (int) $ref['migratable'] !== 1);
    if ($uses !== []) {
      return [
        'complete' => TRUE,
        'status' => 'referenced',
        'reason' => '文件仍被字段、模块或使用记录引用。',
        'refs' => $refs,
        'kind' => 'content',
      ];
    }
    if ($this->hasUsageWithoutField($refs)) {
      return [
        'complete' => TRUE,
        'status' => 'unknown',
        'reason' => 'file_usage 有记录，但没有找到对应字段，不能当成未引用。',
        'refs' => $refs,
        'kind' => 'content',
      ];
    }
    return [
      'complete' => TRUE,
      'status' => 'confirmed_unused',
      'reason' => $cleanReason,
      'refs' => $refs,
      'kind' => 'content',
    ];
  }

  /**
   * @param list<array<string, mixed>> $refs
   *   References.
   */
  private function hasUsageWithoutField(array $refs): bool {
    $usage = FALSE;
    $field = FALSE;
    foreach ($refs as $ref) {
      if ($ref['source'] === 'file_usage') {
        $usage = TRUE;
      }
      if ($ref['source'] === 'field') {
        $field = TRUE;
      }
    }
    return $usage && !$field;
  }

  /**
   * @param array<string, mixed> $source
   *   Field source.
   * @param array<string, mixed> $row
   *   Database row.
   *
   * @return array<string, mixed>
   *   Reference row.
   */
  private function fieldRef(int $batchId, array $source, array $row): array {
    $type = (string) $source['type'];
    $supported = $type === 'file' || $type === 'image' || ($type === 'entity_reference' && $source['target'] === 'file');
    return [
      'batch_id' => $batchId,
      'fid' => (int) ($row['target_id'] ?? 0),
      'entity_type' => $source['entity_type'],
      'entity_id' => (int) ($row['entity_id'] ?? 0),
      'revision_id' => (int) ($row['revision_id'] ?? 0),
      'langcode' => (string) ($row['langcode'] ?? ''),
      'field_name' => $source['field'],
      'delta' => (int) ($row['delta'] ?? 0),
      'bundle' => (string) ($row['bundle'] ?? ''),
      'source' => 'field',
      'confidence' => 'confirmed',
      'migratable' => $supported ? 1 : 0,
      'reason' => $supported ? '可通过实体字段 API 迁移。' : '字段类型不支持安全迁移。',
    ];
  }

  /**
   * @return list<array<string, mixed>>
   *   File field sources.
   */
  private function fileSources(): array {
    return array_values(array_filter($this->sources(), static fn(array $source): bool => $source['kind'] === 'file'));
  }

  /**
   * @return list<array<string, mixed>>
   *   Text and link sources.
   */
  private function textSources(): array {
    return array_values(array_filter($this->sources(), static fn(array $source): bool => $source['kind'] === 'text'));
  }

  /**
   * @return list<array<string, mixed>>
   *   Readable field tables.
   */
  private function sources(): array {
    if ($this->sources !== NULL) {
      return $this->sources;
    }
    $sources = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || $entityTypeId === 'file') {
        continue;
      }
      try {
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $definitions = $this->fieldManager->getFieldStorageDefinitions($entityTypeId);
      }
      catch (\Throwable) {
        $this->incomplete = TRUE;
        continue;
      }
      $fileFields = FALSE;
      foreach ($definitions as $fieldStorage) {
        $type = $fieldStorage->getType();
        $target = (string) ($fieldStorage->getSetting('target_type') ?? '');
        if ($type === 'file' || $type === 'image' || ($type === 'entity_reference' && $target === 'file')) {
          $fileFields = TRUE;
        }
      }
      if (!$storage instanceof SqlContentEntityStorage) {
        if ($fileFields) {
          $this->incomplete = TRUE;
        }
        continue;
      }
      $mapping = $storage->getTableMapping();
      foreach ($definitions as $fieldName => $fieldStorage) {
        $type = $fieldStorage->getType();
        $target = (string) ($fieldStorage->getSetting('target_type') ?? '');
        $isFile = $type === 'file' || $type === 'image' || ($type === 'entity_reference' && $target === 'file');
        $isText = in_array($type, ['text', 'text_long', 'text_with_summary', 'string_long', 'link'], TRUE);
        if (!$isFile && !$isText) {
          continue;
        }
        try {
          $column = $mapping->getFieldColumnName($fieldStorage, $fieldStorage->getMainPropertyName());
          $tables = $mapping->getAllFieldTableNames($fieldName);
        }
        catch (\Throwable) {
          $this->incomplete = TRUE;
          continue;
        }
        foreach ($tables as $table) {
          if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, $column)) {
            $this->incomplete = TRUE;
            continue;
          }
          $id = $this->firstColumn($table, array_values(array_unique(array_filter([
            $definition->getKey('id'),
            'entity_id',
            'nid',
            'mid',
            'uid',
            'fid',
            'tid',
            'id',
            'cid',
            'rid',
          ]))));
          if ($id === NULL) {
            $this->incomplete = TRUE;
            continue;
          }
          $sources[] = [
            'entity_type' => $entityTypeId,
            'field' => $fieldName,
            'type' => $type,
            'target' => $target,
            'table' => $table,
            'column' => $column,
            'kind' => $isFile ? 'file' : 'text',
            'id' => $id,
            'revision' => $this->firstColumn($table, ['revision_id', 'vid']),
            'langcode' => $this->database->schema()->fieldExists($table, 'langcode') ? 'langcode' : NULL,
            'delta' => $this->database->schema()->fieldExists($table, 'delta') ? 'delta' : NULL,
            'bundle' => $this->database->schema()->fieldExists($table, 'bundle') ? 'bundle' : NULL,
          ];
          if ($type === 'text_with_summary') {
            $summary = $fieldName . '_summary';
            if ($this->database->schema()->fieldExists($table, $summary)) {
              $copy = $sources[array_key_last($sources)];
              $copy['column'] = $summary;
              $sources[] = $copy;
            }
          }
        }
      }
    }
    $this->sources = $sources;
    return $sources;
  }

  /**
   * @param array<string, mixed> $source
   *   Field source.
   * @param list<int> $fids
   *   Candidate ids.
   *
   * @return list<array<string, mixed>>
   *   Matching rows.
   */
  private function fetchTargets(array $source, array $fids, int $offset, int $limit): array {
    if ($fids === []) {
      return [];
    }
    $query = $this->database->select($source['table'], 't');
    $query->addField('t', $source['id'], 'entity_id');
    $query->addField('t', $source['column'], 'target_id');
    if ($source['revision']) {
      $query->addField('t', $source['revision'], 'revision_id');
    }
    if ($source['langcode']) {
      $query->addField('t', $source['langcode'], 'langcode');
    }
    if ($source['delta']) {
      $query->addField('t', $source['delta'], 'delta');
    }
    if ($source['bundle']) {
      $query->addField('t', $source['bundle'], 'bundle');
    }
    $query->condition($source['column'], $fids, 'IN');
    if ($this->database->schema()->fieldExists($source['table'], 'deleted')) {
      $query->condition('deleted', 0);
    }
    $query->range($offset, $limit);
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = (array) $row;
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $source
   *   Text source.
   *
   * @return list<array<string, mixed>>|null
   *   Rows, or NULL when the table cannot be read.
   */
  private function fetchText(array $source, int $offset, int $limit): ?array {
    try {
      $query = $this->database->select($source['table'], 't');
      $query->addField('t', $source['id'], 'entity_id');
      $query->addField('t', $source['column'], 'value');
      if ($source['revision']) {
        $query->addField('t', $source['revision'], 'revision_id');
      }
      if ($source['langcode']) {
        $query->addField('t', $source['langcode'], 'langcode');
      }
      if ($source['delta']) {
        $query->addField('t', $source['delta'], 'delta');
      }
      if ($source['bundle']) {
        $query->addField('t', $source['bundle'], 'bundle');
      }
      $group = $query->orConditionGroup()
        ->condition($source['column'], '%' . $this->database->escapeLike('/sites/default/files/') . '%', 'LIKE')
        ->condition($source['column'], '%' . $this->database->escapeLike('public://') . '%', 'LIKE')
        ->condition($source['column'], '%' . $this->database->escapeLike('/system/files/') . '%', 'LIKE')
        ->condition($source['column'], '%' . $this->database->escapeLike('private://') . '%', 'LIKE');
      $query->condition($group);
      if ($this->database->schema()->fieldExists($source['table'], 'deleted')) {
        $query->condition('deleted', 0);
      }
      $query->range($offset, $limit);
      $rows = [];
      foreach ($query->execute() as $row) {
        $rows[] = (array) $row;
      }
      return $rows;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @param array<string, mixed> $source
   *   Text source.
   * @param list<string> $needles
   *   Path fragments.
   *
   * @return list<array<string, mixed>>|null
   *   Matches.
   */
  private function fetchTextMatches(array $source, array $needles): ?array {
    if ($needles === []) {
      return [];
    }
    try {
      $query = $this->database->select($source['table'], 't');
      $query->addField('t', $source['id'], 'entity_id');
      if ($source['revision']) {
        $query->addField('t', $source['revision'], 'revision_id');
      }
      if ($source['langcode']) {
        $query->addField('t', $source['langcode'], 'langcode');
      }
      if ($source['delta']) {
        $query->addField('t', $source['delta'], 'delta');
      }
      if ($source['bundle']) {
        $query->addField('t', $source['bundle'], 'bundle');
      }
      $group = $query->orConditionGroup();
      foreach ($needles as $needle) {
        $group->condition($source['column'], '%' . $this->database->escapeLike($needle) . '%', 'LIKE');
      }
      $query->condition($group);
      if ($this->database->schema()->fieldExists($source['table'], 'deleted')) {
        $query->condition('deleted', 0);
      }
      $query->range(0, 50);
      $rows = [];
      foreach ($query->execute() as $row) {
        $rows[] = (array) $row;
      }
      return $rows;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @return array<string, int>
   *   Needle to fid.
   */
  private function needleMap(int $batchId): array {
    $map = [];
    foreach ($this->repository->workByFid($batchId) as $fid => $row) {
      foreach ($this->needles((string) $row['uri']) as $needle) {
        $map[$needle] = (int) $fid;
      }
    }
    return $map;
  }

  /**
   * @return list<string>
   *   Path fragments long enough to search.
   */
  private function needles(string $uri): array {
    $out = [];
    if (str_starts_with($uri, 'public://')) {
      $relative = substr($uri, 9);
      if ($relative !== '') {
        $out[] = 'public://' . $relative;
        $out[] = '/sites/default/files/' . $relative;
      }
    }
    elseif (str_starts_with($uri, 'private://')) {
      $relative = substr($uri, 10);
      if ($relative !== '') {
        $out[] = 'private://' . $relative;
        $out[] = '/system/files/' . $relative;
      }
    }
    return array_values(array_filter($out, static fn(string $needle): bool => strlen($needle) >= 12));
  }

  /**
   * @param list<string> $candidates
   *   Column names.
   */
  private function firstColumn(string $table, array $candidates): ?string {
    foreach ($candidates as $candidate) {
      if ($this->database->schema()->fieldExists($table, $candidate)) {
        return $candidate;
      }
    }
    return NULL;
  }

  private function deletableKind(string $kind): bool {
    return $kind === 'content';
  }

  private function kindCategory(string $kind): string {
    return match ($kind) {
      'aggregate_css', 'aggregate_js', 'derivative' => 'cache',
      'temporary' => 'temporary',
      'missing_physical' => 'missing_physical',
      default => 'protected',
    };
  }

  private function kindReason(string $kind): string {
    return match ($kind) {
      'aggregate_css' => '这是 CSS 聚合缓存，不能当作用户文件清理。',
      'aggregate_js' => '这是 JS 聚合缓存，不能当作用户文件清理。',
      'derivative' => '这是图片样式衍生物，不能当作未引用原文件。',
      'temporary' => '临时文件不纳入此清理流程。',
      'import' => '导入任务文件受保护。',
      'quarantine' => '隔离目录里的文件不能再次当作未引用文件。',
      'protected' => '系统文件受保护。',
      'outside' => '文件不在允许的公开或私有目录内。',
      default => '此文件受保护。',
    };
  }

  /**
   * @return array<string, mixed>
   *   Unknown result.
   */
  private function unknownResult(string $reason): array {
    return [
      'complete' => FALSE,
      'status' => 'unknown',
      'reason' => $reason,
      'refs' => [],
      'kind' => 'content',
    ];
  }

}
