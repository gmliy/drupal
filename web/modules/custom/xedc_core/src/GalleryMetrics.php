<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Database\Connection;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Ops\OpsFormat;

/**
 * Original and optimized byte totals. Unknown originals are never guessed.
 */
final class GalleryMetrics {

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function forNode(NodeInterface $node): array {
    $rows = $this->files($node);
    $count = count($rows);
    $known = 0;
    $original = 0;
    $optimized = 0;
    $failed = 0;
    $pending = 0;
    $missingAlt = 0;
    foreach ($rows as $row) {
      $optimized += $row['optimized_bytes'];
      if ($row['original_known']) {
        $known++;
        $original += $row['original_bytes'];
      }
      if ($row['failed']) {
        $failed++;
      }
      if ($row['pending']) {
        $pending++;
      }
      if ($row['alt'] === '') {
        $missingAlt++;
      }
    }
    $saved = $known > 0 ? max(0, $original - $this->optimizedForKnown($rows)) : 0;
    $ratio = $known > 0 && $original > 0 ? round($saved / $original * 100, 1) : NULL;
    $status = '无图片';
    if ($count > 0 && $pending > 0) {
      $status = '处理中';
    }
    elseif ($count > 0 && $failed > 0) {
      $status = '有失败';
    }
    elseif ($count > 0) {
      $status = '已完成';
    }
    return [
      'count' => $count,
      'known' => $known,
      'unknown' => max(0, $count - $known),
      'original_bytes' => $known > 0 ? $original : NULL,
      'optimized_bytes' => $optimized,
      'saved_bytes' => $known > 0 ? $saved : NULL,
      'ratio' => $ratio,
      'original_label' => $known > 0 ? OpsFormat::bytes($original) : '原图大小未知',
      'optimized_label' => OpsFormat::bytes($optimized),
      'saved_label' => $known > 0 ? OpsFormat::bytes($saved) : '原图大小未知',
      'ratio_label' => $ratio === NULL ? '原图大小未知' : $ratio . '%',
      'partial_note' => $known > 0 && $known < $count ? '其中 ' . ($count - $known) . ' 张原图大小未知' : '',
      'status' => $status,
      'failed' => $failed,
      'pending' => $pending,
      'missing_alt' => $missingAlt,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function readiness(NodeInterface $node): array {
    $metrics = $this->forNode($node);
    $importBlocked = $node->id() && $this->database->schema()->tableExists('xedc_import_task')
      ? \Drupal::service('xedc_core.tasks')->blocksPublish((int) $node->id())
      : FALSE;
    $optimizeBlocked = \Drupal::hasService('xedc_core.image_optimize') && \Drupal::service('xedc_core.image_optimize')->blocksPublish($node);
    $reasons = [];
    if ($importBlocked) {
      $reasons[] = '导入任务尚未完成或存在失败图片';
    }
    if ($optimizeBlocked) {
      $reasons[] = '有图片还在优化或优化失败';
    }
    if ($metrics['missing_alt'] > 0) {
      $reasons[] = '有 ' . $metrics['missing_alt'] . ' 张图片缺少 ALT';
    }
    if ($metrics['failed'] > 0) {
      $reasons[] = '有 ' . $metrics['failed'] . ' 张图片处理失败';
    }
    return $metrics + [
      'publishable' => $reasons === [] && $metrics['pending'] === 0,
      'reasons' => $reasons,
    ];
  }

  /**
   * @return array<string, int|string>
   */
  /**
   * Node ids matching one review filter. Empty means no photo matches.
   *
   * @return int[]
   */
  public function nidsForState(string $state): array {
    $pending = array_merge(ImportStatus::processing(), [ImportStatus::QUEUED, ImportStatus::UPLOADING]);
    if ($state === 'pending') {
      return $this->taskNids($pending);
    }
    if ($state === 'failed') {
      return $this->taskNids([ImportStatus::FAILED, ImportStatus::PARTIAL]);
    }
    if ($state === 'missing_alt') {
      return $this->missingAltNids();
    }
    if ($state === 'complete') {
      $exclude = array_fill_keys(array_merge(
        $this->taskNids($pending),
        $this->taskNids([ImportStatus::FAILED, ImportStatus::PARTIAL]),
        $this->missingAltNids(),
      ), TRUE);
      $complete = [];
      foreach ($this->photoNidsWithImages() as $nid) {
        if (!isset($exclude[$nid])) {
          $complete[] = $nid;
        }
      }
      return $complete;
    }
    return [];
  }

  /**
   * @return array<string, int|string>
   */
  public function site(): array {
    $out = [
      'files' => 0,
      'known' => 0,
      'unknown' => 0,
      'original_bytes' => 0,
      'optimized_bytes' => 0,
      'optimized_known' => 0,
      'failed' => 0,
      'archive_tasks' => 0,
      'image_tasks' => 0,
      'zip_tasks' => 0,
      'seven_tasks' => 0,
    ];
    if ($this->database->schema()->tableExists('xedc_image_file') && $this->database->schema()->fieldExists('xedc_image_file', 'original_bytes')) {
      $row = $this->database->query('SELECT COUNT(*) AS c, SUM(CASE WHEN original_bytes > 0 THEN 1 ELSE 0 END) AS known, SUM(CASE WHEN original_bytes > 0 THEN original_bytes ELSE 0 END) AS original_bytes, SUM(CASE WHEN optimized_bytes > 0 THEN optimized_bytes ELSE bytes END) AS optimized_bytes, SUM(CASE WHEN original_bytes > 0 THEN CASE WHEN optimized_bytes > 0 THEN optimized_bytes ELSE bytes END ELSE 0 END) AS optimized_known, SUM(CASE WHEN optimization_status = :failed THEN 1 ELSE 0 END) AS failed FROM {xedc_image_file}', [
        ':failed' => 'failed',
      ])->fetchAssoc();
      if (is_array($row)) {
        $out['files'] = (int) $row['c'];
        $out['known'] = (int) $row['known'];
        $out['unknown'] = max(0, $out['files'] - $out['known']);
        $out['original_bytes'] = (int) $row['original_bytes'];
        $out['optimized_bytes'] = (int) $row['optimized_bytes'];
        $out['optimized_known'] = (int) $row['optimized_known'];
        $out['failed'] = (int) $row['failed'];
      }
    }
    if ($this->database->schema()->tableExists('xedc_quick_orig') && $this->database->schema()->tableExists('media__field_media_image')) {
      $quick = $this->database->query('SELECT COUNT(*) AS c, SUM(q.bytes) AS original_bytes, SUM(fm.filesize) AS optimized_known FROM {xedc_quick_orig} q INNER JOIN {media__field_media_image} m ON m.entity_id = q.mid INNER JOIN {file_managed} fm ON fm.fid = m.field_media_image_target_id LEFT JOIN {xedc_image_file} f ON f.fid = fm.fid WHERE q.bytes > 0 AND (f.original_bytes IS NULL OR f.original_bytes = 0)')->fetchAssoc();
      if (is_array($quick)) {
        $out['known'] += (int) $quick['c'];
        $out['original_bytes'] += (int) $quick['original_bytes'];
        $out['optimized_known'] += (int) $quick['optimized_known'];
      }
    }
    if ($this->database->schema()->tableExists('xedc_import_task')) {
      $out['archive_tasks'] = (int) $this->database->query("SELECT COUNT(*) FROM {xedc_import_task} WHERE source <> 'images'")->fetchField();
      $out['image_tasks'] = (int) $this->database->query("SELECT COUNT(*) FROM {xedc_import_task} WHERE source = 'images'")->fetchField();
      $out['zip_tasks'] = (int) $this->database->query("SELECT COUNT(*) FROM {xedc_import_task} WHERE archive_format = 'zip'")->fetchField();
      $out['seven_tasks'] = (int) $this->database->query("SELECT COUNT(*) FROM {xedc_import_task} WHERE archive_format = '7z'")->fetchField();
    }
    return $out;
  }

  /**
   * @param array<int, array{original_known: bool, original_bytes: int, optimized_bytes: int}> $rows
   */
  /**
   * @param string[] $statuses
   * @return int[]
   */
  private function taskNids(array $statuses): array {
    if (!$statuses || !$this->database->schema()->tableExists('xedc_import_task')) {
      return [];
    }
    $ids = $this->database->select('xedc_import_task', 't')
      ->fields('t', ['nid'])
      ->condition('status', $statuses, 'IN')
      ->condition('nid', 0, '>')
      ->execute()
      ->fetchCol();
    return array_values(array_unique(array_map('intval', $ids)));
  }

  /**
   * @return int[]
   */
  private function missingAltNids(): array {
    if (!$this->database->schema()->tableExists('node__field_images') || !$this->database->schema()->tableExists('media__field_media_image')) {
      return [];
    }
    $ids = $this->database->query("SELECT DISTINCT fi.entity_id FROM {node__field_images} fi INNER JOIN {node_field_data} n ON n.nid = fi.entity_id AND n.type = 'photo' INNER JOIN {media__field_media_image} m ON m.entity_id = fi.field_images_target_id WHERE m.field_media_image_alt IS NULL OR m.field_media_image_alt = ''")->fetchCol();
    return array_values(array_unique(array_map('intval', $ids)));
  }

  /**
   * @return int[]
   */
  private function photoNidsWithImages(): array {
    if (!$this->database->schema()->tableExists('node__field_images')) {
      return [];
    }
    $ids = $this->database->query("SELECT DISTINCT fi.entity_id FROM {node__field_images} fi INNER JOIN {node_field_data} n ON n.nid = fi.entity_id AND n.type = 'photo'")->fetchCol();
    return array_values(array_unique(array_map('intval', $ids)));
  }

  /**
   * @param array<int, array{original_known: bool, original_bytes: int, optimized_bytes: int}> $rows
   */
  private function optimizedForKnown(array $rows): int {
    $sum = 0;
    foreach ($rows as $row) {
      if ($row['original_known']) {
        $sum += $row['optimized_bytes'];
      }
    }
    return $sum;
  }

  /**
   * @return array<int, array{original_known: bool, original_bytes: int, optimized_bytes: int, failed: bool, pending: bool, alt: string}>
   */
  private function files(NodeInterface $node): array {
    if (!$node->hasField('field_images')) {
      return [];
    }
    $rows = [];
    $fids = [];
    $mids = [];
    foreach ($node->get('field_images')->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
        continue;
      }
      $file = $media->get('field_media_image')->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $fid = (int) $file->id();
      $mid = (int) $media->id();
      $fids[] = $fid;
      $mids[] = $mid;
      $rows[$fid] = [
        'mid' => $mid,
        'original_known' => FALSE,
        'original_bytes' => 0,
        'optimized_bytes' => (int) $file->getSize(),
        'failed' => FALSE,
        'pending' => FALSE,
        'alt' => trim((string) $media->get('field_media_image')->alt),
      ];
    }
    if (!$rows) {
      return [];
    }
    $this->applyImageFile($rows, $fids);
    $this->applyQuick($rows, $mids);
    $this->applyImport($rows, $mids);
    $this->applyOptimize($rows, $fids);
    return array_values($rows);
  }

  /**
   * @param array<int, array<string, mixed>> $rows
   * @param int[] $fids
   */
  private function applyImageFile(array &$rows, array $fids): void {
    if (!$this->database->schema()->tableExists('xedc_image_file') || !$this->database->schema()->fieldExists('xedc_image_file', 'original_bytes')) {
      return;
    }
    $result = $this->database->select('xedc_image_file', 'f')
      ->fields('f', ['fid', 'original_bytes', 'optimized_bytes', 'optimization_status'])
      ->condition('fid', $fids, 'IN')
      ->execute();
    foreach ($result as $row) {
      $fid = (int) $row->fid;
      if (!isset($rows[$fid])) {
        continue;
      }
      if ((int) $row->optimized_bytes > 0) {
        $rows[$fid]['optimized_bytes'] = (int) $row->optimized_bytes;
      }
      if ((int) $row->original_bytes > 0) {
        $rows[$fid]['original_known'] = TRUE;
        $rows[$fid]['original_bytes'] = (int) $row->original_bytes;
      }
      if ((string) $row->optimization_status === 'failed') {
        $rows[$fid]['failed'] = TRUE;
      }
      if (in_array((string) $row->optimization_status, ['queued', 'running'], TRUE)) {
        $rows[$fid]['pending'] = TRUE;
      }
    }
  }

  /**
   * @param array<int, array<string, mixed>> $rows
   * @param int[] $mids
   */
  private function applyQuick(array &$rows, array $mids): void {
    if (!$mids || !$this->database->schema()->tableExists('xedc_quick_orig')) {
      return;
    }
    $map = $this->database->query('SELECT mid, bytes FROM {xedc_quick_orig} WHERE mid IN (:mids[])', [
      ':mids[]' => $mids,
    ])->fetchAllKeyed();
    foreach ($rows as &$row) {
      $bytes = (int) ($map[$row['mid']] ?? 0);
      if ($bytes > 0 && !$row['original_known']) {
        $row['original_known'] = TRUE;
        $row['original_bytes'] = $bytes;
      }
    }
  }

  /**
   * @param array<int, array<string, mixed>> $rows
   * @param int[] $mids
   */
  private function applyImport(array &$rows, array $mids): void {
    if (!$mids || !$this->database->schema()->tableExists('xedc_import_item') || !$this->database->schema()->fieldExists('xedc_import_item', 'original_bytes')) {
      return;
    }
    $result = $this->database->select('xedc_import_item', 'i')
      ->fields('i', ['mid', 'original_bytes', 'status'])
      ->condition('mid', $mids, 'IN')
      ->execute();
    $byMid = [];
    foreach ($result as $item) {
      $byMid[(int) $item->mid] = $item;
    }
    foreach ($rows as &$row) {
      $item = $byMid[$row['mid']] ?? NULL;
      if (!$item) {
        continue;
      }
      if ((int) $item->original_bytes > 0 && !$row['original_known']) {
        $row['original_known'] = TRUE;
        $row['original_bytes'] = (int) $item->original_bytes;
      }
      if ((string) $item->status === 'failed') {
        $row['failed'] = TRUE;
      }
      if ((string) $item->status === 'pending') {
        $row['pending'] = TRUE;
      }
    }
  }

  /**
   * Uses a measured optimize audit only. Equal source and stored sizes are still measured.
   *
   * @param array<int, array<string, mixed>> $rows
   * @param int[] $fids
   */
  private function applyOptimize(array &$rows, array $fids): void {
    if (!$this->database->schema()->tableExists('xedc_image_optimize')) {
      return;
    }
    $result = $this->database->query('SELECT o.fid, o.source_bytes, o.stored_bytes, o.status FROM {xedc_image_optimize} o INNER JOIN (SELECT fid, MAX(id) AS id FROM {xedc_image_optimize} WHERE fid IN (:fids[]) GROUP BY fid) latest ON latest.id = o.id', [
      ':fids[]' => $fids,
    ]);
    foreach ($result as $row) {
      $fid = (int) $row->fid;
      if (!isset($rows[$fid]) || $rows[$fid]['original_known']) {
        continue;
      }
      if ((string) $row->status === 'success' && (int) $row->source_bytes > 0) {
        $rows[$fid]['original_known'] = TRUE;
        $rows[$fid]['original_bytes'] = (int) $row->source_bytes;
      }
      if ((string) $row->status === 'failed') {
        $rows[$fid]['failed'] = TRUE;
      }
      if (in_array((string) $row->status, ['queued', 'running'], TRUE)) {
        $rows[$fid]['pending'] = TRUE;
      }
    }
  }

}
