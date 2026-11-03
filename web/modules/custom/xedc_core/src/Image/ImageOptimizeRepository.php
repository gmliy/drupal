<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Image;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Settings and audit rows for image optimization. No secrets are stored.
 */
final class ImageOptimizeRepository {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function settings(): array {
    $stored = $this->configFactory->get('xedc_core.image_optimize')->getRawData();
    $defaults = [
      'jpeg_quality' => 84,
      'webp_quality' => 82,
      'avif_quality' => 70,
      'allow_webp' => TRUE,
      'allow_avif' => FALSE,
      'auto_format' => TRUE,
      'strip_metadata' => TRUE,
      'sync_bytes' => 3145728,
      'max_bytes' => 20971520,
      'max_pixels' => 12000000,
      'timeout' => 20,
      'retries' => 2,
      'concurrency' => 1,
    ];
    foreach ($defaults as $key => $value) {
      if (!array_key_exists($key, $stored)) {
        $stored[$key] = $value;
      }
    }
    return $stored;
  }

  /**
   * @param array<string, mixed> $values
   */
  public function saveSettings(array $values): void {
    $config = $this->configFactory->getEditable('xedc_core.image_optimize');
    foreach ($this->settings() as $key => $current) {
      if (array_key_exists($key, $values)) {
        $config->set($key, $values[$key]);
      }
    }
    $config->save();
  }

  /**
   * @param array<string, mixed> $fields
   */
  public function record(array $fields): int {
    $fields['changed'] = \Drupal::time()->getRequestTime();
    if (empty($fields['created'])) {
      $fields['created'] = $fields['changed'];
    }
    $fid = (int) ($fields['fid'] ?? 0);
    $existing = $fid > 0 ? $this->latestForFid($fid) : NULL;
    if ($existing && $existing['status'] !== 'success') {
      $this->database->update('xedc_image_optimize')
        ->fields($fields)
        ->condition('id', (int) $existing['id'])
        ->execute();
      return (int) $existing['id'];
    }
    return (int) $this->database->insert('xedc_image_optimize')->fields($fields)->execute();
  }

  /**
   * @return array<string, mixed>|null
   */
  public function latestForFid(int $fid): ?array {
    $row = $this->database->select('xedc_image_optimize', 'o')
      ->fields('o')
      ->condition('fid', $fid)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function load(int $id): ?array {
    $row = $this->database->select('xedc_image_optimize', 'o')
      ->fields('o')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  public function runningCount(): int {
    $cutoff = \Drupal::time()->getRequestTime() - 600;
    return (int) $this->database->select('xedc_image_optimize', 'o')
      ->condition('status', 'running')
      ->condition('changed', $cutoff, '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function update(int $id, array $fields): void {
    unset($fields['id'], $fields['created']);
    $allowed = [
      'fid', 'filename', 'source_mime', 'stored_mime', 'source_bytes', 'stored_bytes',
      'source_sha256', 'stored_sha256', 'width', 'height', 'elapsed_ms', 'memory_bytes',
      'status', 'r2_status', 'error', 'attempts', 'changed',
    ];
    $fields['changed'] = \Drupal::time()->getRequestTime();
    $fields = array_intersect_key($fields, array_flip($allowed));
    if ($fields === []) {
      return;
    }
    $this->database->update('xedc_image_optimize')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Latest successful dimensions for the given file ids.
   *
   * @param list<int> $fids
   *   File ids.
   *
   * @return array<int, array{width: int, height: int}>
   *   Dimensions keyed by file id.
   */
  public function successDimensions(array $fids): array {
    $fids = array_values(array_unique(array_filter(array_map('intval', $fids))));
    if ($fids === []) {
      return [];
    }
    $rows = $this->database->select('xedc_image_optimize', 'o')
      ->fields('o', ['fid', 'width', 'height'])
      ->condition('fid', $fids, 'IN')
      ->condition('status', 'success')
      ->orderBy('id', 'DESC')
      ->execute();
    $map = [];
    foreach ($rows as $row) {
      $fid = (int) $row->fid;
      if (!isset($map[$fid])) {
        $map[$fid] = ['width' => (int) $row->width, 'height' => (int) $row->height];
      }
    }
    return $map;
  }

  /**
   * Import task ids for files created by the archive importer.
   *
   * @param list<int> $fids
   *   File ids.
   *
   * @return array<int, string>
   *   Task id keyed by file id.
   */
  public function importTasks(array $fids): array {
    $fids = array_values(array_unique(array_filter(array_map('intval', $fids))));
    if ($fids === [] || !$this->database->schema()->tableExists('xedc_import_item')) {
      return [];
    }
    $rows = $this->database->select('xedc_import_item', 'i')
      ->fields('i', ['fid', 'task_id'])
      ->condition('fid', $fids, 'IN')
      ->execute();
    $map = [];
    foreach ($rows as $row) {
      $map[(int) $row->fid] = (string) $row->task_id;
    }
    return $map;
  }

  public function settled(int $fid, string $sha256): bool {
    $row = $this->latestForFid($fid);
    return is_array($row) && $row['status'] === 'success' && hash_equals((string) $row['stored_sha256'], $sha256);
  }

  /**
   * @return array<string, int>
   */
  public function summary(): array {
    $query = $this->database->select('xedc_image_optimize', 'o');
    $query->addExpression('COUNT(*)', 'total');
    $query->addExpression("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END)", 'success');
    $query->addExpression("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)", 'failed');
    $query->addExpression("SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END)", 'queued');
    $query->addExpression("SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END)", 'running');
    $query->addExpression("SUM(CASE WHEN r2_status = 'stored' THEN 1 ELSE 0 END)", 'r2_ok');
    $query->addExpression("SUM(CASE WHEN r2_status = 'failed' THEN 1 ELSE 0 END)", 'r2_failed');
    $query->addExpression('SUM(CASE WHEN source_bytes > stored_bytes THEN source_bytes - stored_bytes ELSE 0 END)', 'saved');
    $query->addExpression("SUM(CASE WHEN status = 'success' THEN source_bytes ELSE 0 END)", 'source_sum');
    $query->addExpression('MAX(changed)', 'latest');
    $row = $query->execute()->fetchAssoc();
    $out = [];
    foreach ((array) $row as $key => $value) {
      $out[$key] = (int) $value;
    }
    return $out;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function list(string $status, int $limit): array {
    $query = $this->database->select('xedc_image_optimize', 'o')
      ->fields('o')
      ->orderBy('id', 'DESC')
      ->range(0, $limit);
    if ($status !== '' && $status !== 'all') {
      $query->condition('status', $status);
    }
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

}
