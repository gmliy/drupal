<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Psr\Log\LoggerInterface;

/**
 * Reads Drupal file and media records without scanning the whole disk.
 */
class MediaCensus {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $fieldManager,
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, mixed>
   *   Entity statistics and read-only anomaly lists.
   */
  public function summary(bool $refresh = FALSE): array {
    $cid = 'xedc_core:file_census';
    if ($refresh) {
      $this->cache->delete($cid);
    }
    else {
      $cached = $this->cache->get($cid);
      if ($cached && is_array($cached->data)) {
        return $cached->data;
      }
    }
    try {
      $summary = $this->build();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Media census failed: @message', ['@message' => $exception->getMessage()]);
      $summary = [
        'ok' => FALSE,
        'error' => '媒体统计失败：' . $exception->getMessage(),
      ];
    }
    $this->cache->set($cid, $summary, $this->time->getRequestTime() + 180);
    return $summary;
  }

  /**
   * @return array<string, mixed>
   *   Census payload.
   */
  private function build(): array {
    $rows = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid', 'uri', 'filemime', 'filesize', 'filename', 'status', 'changed'])
      ->execute()
      ->fetchAllAssoc('fid');
    $references = $this->references();
    $config = $this->configFactory->get('xedc_core.settings');
    $largeBytes = (int) ($config->get('file_large_bytes') ?: 104857600);
    $staleDays = (int) ($config->get('file_stale_days') ?: 180);
    $staleBefore = $this->time->getRequestTime() - ($staleDays * 86400);

    $images = $videos = $webp = $other = 0;
    $imageDb = $imageDisk = $videoDb = $videoDisk = $webpDb = $otherDb = 0;
    $physical = $missing = $temporary = $permanent = 0;
    $seenPaths = [];
    $missingRows = [];
    $largeRows = [];
    $staleRows = [];
    $staleCount = 0;
    $imageFiles = [];

    foreach ($rows as $fid => $row) {
      $fid = (int) $fid;
      $mime = (string) $row->filemime;
      $uri = (string) $row->uri;
      $dbSize = (int) $row->filesize;
      $isPermanent = (int) $row->status === FileInterface::STATUS_PERMANENT;
      if ($isPermanent) {
        $permanent++;
      }
      else {
        $temporary++;
      }
      $real = $this->fileSystem->realpath($uri);
      $exists = is_string($real) && is_file($real);
      $diskSize = $exists ? (int) (@filesize($real) ?: 0) : NULL;
      if ($exists) {
        $physical++;
        if (!isset($seenPaths[$real])) {
          $seenPaths[$real] = $fid;
        }
      }
      elseif ($isPermanent && count($missingRows) < 40) {
        $missingRows[] = [
          'fid' => (string) $fid,
          'uri' => $uri,
          'bytes' => OpsFormat::bytes($dbSize),
        ];
      }
      if ($isPermanent && !$exists) {
        $missing++;
      }
      if ($isPermanent && $dbSize >= $largeBytes) {
        $largeRows[] = [
          'fid' => (string) $fid,
          'uri' => $uri,
          'bytes' => OpsFormat::bytes($dbSize),
        ];
      }
      if ($isPermanent && (int) $row->changed > 0 && (int) $row->changed < $staleBefore) {
        $staleCount++;
        if (count($staleRows) < 30) {
          $staleRows[] = [
            'fid' => (string) $fid,
            'uri' => $uri,
            'changed' => \Drupal::service('date.formatter')->format((int) $row->changed, 'custom', 'Y-m-d'),
            'bytes' => OpsFormat::bytes($dbSize),
          ];
        }
      }
      $kind = $this->kind($mime, $uri);
      if ($kind === 'image') {
        $images++;
        $imageDb += $dbSize;
        if ($diskSize !== NULL) {
          $imageDisk += $diskSize;
        }
        if ($exists) {
          $imageFiles[] = ['fid' => $fid, 'uri' => $uri];
        }
        if ($this->isWebp($mime, $uri)) {
          $webp++;
          $webpDb += $dbSize;
        }
      }
      elseif ($kind === 'video') {
        $videos++;
        $videoDb += $dbSize;
        if ($diskSize !== NULL) {
          $videoDisk += $diskSize;
        }
      }
      else {
        $other++;
        $otherDb += $dbSize;
      }
    }

    $mediaFids = $references['media'];
    $contentFids = $references['content'];
    $usageFids = $references['usage'];
    $unreferenced = [];
    $unreferencedCount = 0;
    foreach ($rows as $fid => $row) {
      $fid = (int) $fid;
      if ((int) $row->status !== FileInterface::STATUS_PERMANENT) {
        continue;
      }
      if (isset($mediaFids[$fid]) || isset($contentFids[$fid]) || isset($usageFids[$fid])) {
        continue;
      }
      $unreferencedCount++;
      if (count($unreferenced) < 40) {
        $unreferenced[] = [
          'fid' => (string) $fid,
          'uri' => (string) $row->uri,
          'bytes' => OpsFormat::bytes((int) $row->filesize),
        ];
      }
    }

    $styles = $this->styles($imageFiles);
    $duplicatePaths = count($rows) - count($seenPaths) - $missing;

    return [
      'ok' => TRUE,
      'error' => '',
      'entities' => count($rows),
      'permanent' => $permanent,
      'temporary' => $temporary,
      'physical' => $physical,
      'distinct_paths' => count($seenPaths),
      'shared_paths' => max(0, $duplicatePaths),
      'images' => $images,
      'image_db' => OpsFormat::bytes($imageDb),
      'image_disk' => OpsFormat::bytes($imageDisk),
      'videos' => $videos,
      'video_db' => OpsFormat::bytes($videoDb),
      'video_disk' => OpsFormat::bytes($videoDisk),
      'webp' => $webp,
      'webp_db' => OpsFormat::bytes($webpDb),
      'other' => $other,
      'other_db' => OpsFormat::bytes($otherDb),
      'media_referenced' => count($mediaFids),
      'content_referenced' => count($contentFids),
      'usage_recorded' => count($usageFids),
      'missing_count' => $missing,
      'missing' => $missingRows,
      'unreferenced_count' => $unreferencedCount,
      'unreferenced' => $unreferenced,
      'large' => $largeRows,
      'stale_count' => $staleCount,
      'stale_days' => $staleDays,
      'stale' => $staleRows,
      'styles' => $styles,
      'watchdog' => $this->watchdog(),
      'fields' => $references['fields'],
    ];
  }

  /**
   * @return array{media: array<int, int>, content: array<int, int>, usage: array<int, int>, fields: list<string>}
   *   Fid sets discovered from field tables and file_usage.
   */
  private function references(): array {
    $mediaFids = [];
    $directContent = [];
    $mediaIds = [];
    $fields = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || $entityTypeId === 'file') {
        continue;
      }
      try {
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $storages = $this->fieldManager->getFieldStorageDefinitions($entityTypeId);
      }
      catch (\Throwable) {
        continue;
      }
      if (!$storage instanceof SqlContentEntityStorage) {
        continue;
      }
      $mapping = $storage->getTableMapping();
      foreach ($storages as $fieldName => $fieldStorage) {
        $type = $fieldStorage->getType();
        $target = (string) ($fieldStorage->getSetting('target_type') ?? '');
        $isFile = $type === 'file' || $type === 'image' || ($type === 'entity_reference' && $target === 'file');
        $isMedia = $type === 'entity_reference' && $target === 'media';
        if (!$isFile && !$isMedia) {
          continue;
        }
        try {
          $column = $mapping->getFieldColumnName($fieldStorage, $fieldStorage->getMainPropertyName());
          $tables = $mapping->getAllFieldTableNames($fieldName);
        }
        catch (\Throwable) {
          continue;
        }
        $fields[] = $entityTypeId . '.' . $fieldName;
        foreach ($tables as $table) {
          if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, $column)) {
            continue;
          }
          $ids = $this->database->select($table, 't')
            ->fields('t', [$column])
            ->isNotNull($column)
            ->distinct()
            ->execute()
            ->fetchCol();
          foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
              continue;
            }
            if ($isMedia) {
              if ($entityTypeId !== 'media') {
                $mediaIds[$id] = $id;
              }
            }
            elseif ($entityTypeId === 'media') {
              $mediaFids[$id] = $id;
            }
            else {
              $directContent[$id] = $id;
            }
          }
        }
      }
    }
    $content = $directContent;
    $mediaToFiles = $this->mediaFileMap();
    foreach ($mediaIds as $mid) {
      foreach ($mediaToFiles[$mid] ?? [] as $fid) {
        $content[$fid] = $fid;
      }
    }
    return [
      'media' => $mediaFids,
      'content' => $content,
      'usage' => $this->usageFids(),
      'fields' => $fields,
    ];
  }

  /**
   * @return array<int, list<int>>
   *   Media id to file ids, from the file fields already stored on media.
   */
  private function mediaFileMap(): array {
    $map = [];
    try {
      $storage = $this->entityTypeManager->getStorage('media');
      $definitions = $this->fieldManager->getFieldStorageDefinitions('media');
    }
    catch (\Throwable) {
      return [];
    }
    if (!$storage instanceof SqlContentEntityStorage) {
      return [];
    }
    $mapping = $storage->getTableMapping();
    foreach ($definitions as $fieldName => $fieldStorage) {
      $type = $fieldStorage->getType();
      $target = (string) ($fieldStorage->getSetting('target_type') ?? '');
      $isFile = $type === 'file' || $type === 'image' || ($type === 'entity_reference' && $target === 'file');
      if (!$isFile) {
        continue;
      }
      try {
        $column = $mapping->getFieldColumnName($fieldStorage, $fieldStorage->getMainPropertyName());
        $table = $mapping->getFieldTableName($fieldName);
      }
      catch (\Throwable) {
        continue;
      }
      $idColumn = $this->idColumn($table);
      if ($idColumn === NULL || !$this->database->schema()->fieldExists($table, $column)) {
        continue;
      }
      $result = $this->database->select($table, 't')
        ->fields('t', [$idColumn, $column])
        ->isNotNull($column)
        ->execute();
      foreach ($result as $row) {
        $mid = (int) $row->{$idColumn};
        $fid = (int) $row->{$column};
        if ($mid > 0 && $fid > 0) {
          $map[$mid][$fid] = $fid;
        }
      }
    }
    $normalized = [];
    foreach ($map as $mid => $fids) {
      $normalized[$mid] = array_values($fids);
    }
    return $normalized;
  }

  /**
   * @return array<int, int>
   *   Fids present in file_usage.
   */
  private function usageFids(): array {
    if (!$this->database->schema()->tableExists('file_usage')) {
      return [];
    }
    $ids = $this->database->select('file_usage', 'u')->fields('u', ['fid'])->distinct()->execute()->fetchCol();
    $set = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $set[$id] = $id;
      }
    }
    return $set;
  }

  private function idColumn(string $table): ?string {
    foreach (['entity_id', 'mid', 'fid'] as $column) {
      if ($this->database->schema()->fieldExists($table, $column)) {
        return $column;
      }
    }
    return NULL;
  }

  /**
   * @param list<array{fid: int, uri: string}> $images
   *   Existing image files.
   *
   * @return array<string, mixed>
   *   Derivative presence. Missing means not generated yet.
   */
  private function styles(array $images): array {
    $styles = ImageStyle::loadMultiple();
    $checked = 0;
    $missing = 0;
    $empty = 0;
    $samples = [];
    $capped = FALSE;
    foreach ($images as $image) {
      if ($checked >= 400) {
        $capped = TRUE;
        break;
      }
      $checked++;
      foreach ($styles as $style) {
        try {
          $derivative = $style->buildUri($image['uri']);
        }
        catch (\Throwable) {
          continue;
        }
        $path = $this->fileSystem->realpath($derivative);
        if (!is_string($path) || !is_file($path)) {
          $missing++;
          if (count($samples) < 30) {
            $samples[] = [
              'uri' => $image['uri'],
              'style' => $style->id(),
              'state' => '尚未生成',
            ];
          }
          continue;
        }
        $size = @filesize($path);
        if ($size === 0) {
          $empty++;
          if (count($samples) < 30) {
            $samples[] = [
              'uri' => $image['uri'],
              'style' => $style->id(),
              'state' => '文件为空',
            ];
          }
        }
      }
    }
    return [
      'styles' => count($styles),
      'checked' => $checked,
      'missing' => $missing,
      'empty' => $empty,
      'capped' => $capped,
      'samples' => $samples,
    ];
  }

  /**
   * @return array{count: int, rows: list<array<string, string>>}
   *   Recent image log rows, without unserialized variables.
   */
  private function watchdog(): array {
    if (!$this->database->schema()->tableExists('watchdog')) {
      return ['count' => 0, 'rows' => []];
    }
    $since = $this->time->getRequestTime() - (30 * 86400);
    $count = (int) $this->database->select('watchdog', 'w')
      ->condition('type', 'image')
      ->condition('severity', 3, '<=')
      ->condition('timestamp', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
    $result = $this->database->select('watchdog', 'w')
      ->fields('w', ['timestamp', 'message', 'severity'])
      ->condition('type', 'image')
      ->condition('severity', 3, '<=')
      ->condition('timestamp', $since, '>=')
      ->orderBy('wid', 'DESC')
      ->range(0, 5)
      ->execute();
    $rows = [];
    foreach ($result as $row) {
      $message = trim(preg_replace('/\s+/', ' ', strip_tags((string) $row->message)) ?? '');
      if (strlen($message) > 180) {
        $message = substr($message, 0, 180) . '…';
      }
      $rows[] = [
        'time' => \Drupal::service('date.formatter')->format((int) $row->timestamp, 'custom', 'Y-m-d H:i'),
        'message' => $message,
      ];
    }
    return ['count' => $count, 'rows' => $rows];
  }

  private function kind(string $mime, string $uri): string {
    if (str_starts_with($mime, 'image/')) {
      return 'image';
    }
    if (str_starts_with($mime, 'video/')) {
      return 'video';
    }
    return 'other';
  }

  private function isWebp(string $mime, string $uri): bool {
    if ($mime === 'image/webp') {
      return TRUE;
    }
    return str_ends_with(strtolower($uri), '.webp');
  }

}
