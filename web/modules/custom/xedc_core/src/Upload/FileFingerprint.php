<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Upload;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Exact SHA-256 lookup for files that are already stored.
 *
 * The caller keeps integer byte counts. This service never deletes a file
 * that another record still references.
 */
final class FileFingerprint {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Looks up an original image by the caller's SHA-256.
   *
   * @return array{reusable: bool, reason: string, fid: int, file: \Drupal\file\FileInterface|null, bytes: int, stored_sha256: string}
   *   A healthy public file, or a reason the caller must upload.
   */
  public function findImage(string $sha, int $bytes): array {
    $empty = [
      'reusable' => FALSE,
      'reason' => 'not_found',
      'fid' => 0,
      'file' => NULL,
      'bytes' => 0,
      'stored_sha256' => '',
    ];
    $sha = strtolower(trim($sha));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
      $empty['reason'] = 'invalid';
      return $empty;
    }
    if ($this->database->schema()->tableExists('xedc_image_file')) {
      $query = $this->database->select('xedc_image_file', 'h')
        ->fields('h', ['fid', 'bytes', 'original_bytes', 'stored_sha256'])
        ->condition('source_sha256', $sha)
        ->orderBy('fid');
      if ($bytes > 0 && $this->database->schema()->fieldExists('xedc_image_file', 'original_bytes')) {
        $group = $query->orConditionGroup()
          ->condition('original_bytes', 0)
          ->condition('original_bytes', $bytes);
        $query->condition($group);
      }
      foreach ($query->execute()->fetchAll() as $row) {
        $stored = strtolower((string) $row->stored_sha256);
        if (!preg_match('/^[a-f0-9]{64}$/', $stored)) {
          continue;
        }
        $healthy = $this->healthyFile((int) $row->fid, $stored, (int) $row->bytes);
        if ($healthy) {
          return [
            'reusable' => TRUE,
            'reason' => '',
            'fid' => (int) $healthy->id(),
            'file' => $healthy,
            'bytes' => (int) $row->bytes,
            'stored_sha256' => strtolower((string) $row->stored_sha256),
          ];
        }
      }
    }
    if ($this->database->schema()->tableExists('xedc_quick_hash')) {
      $mid = (int) $this->database->select('xedc_quick_hash', 'q')
        ->fields('q', ['mid'])
        ->condition('hash', $sha)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      $file = $this->fileFromMedia($mid);
      if ($file && $this->objectReady($file, '')) {
        return [
          'reusable' => TRUE,
          'reason' => '',
          'fid' => (int) $file->id(),
          'file' => $file,
          'bytes' => (int) $file->getSize(),
          'stored_sha256' => '',
        ];
      }
      if ($mid > 0) {
        $empty['reason'] = 'missing_object';
      }
    }
    return $empty;
  }

  /**
   * Reserves the right to write one stored object.
   *
   * @return array{reuse?: array{file: \Drupal\file\FileInterface, media: null}, claimed?: bool, busy?: bool}
   *   Either an existing healthy file or permission to create one.
   */
  public function claimStored(string $sha, int $bytes): array {
    $sha = strtolower(trim($sha));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
      return [];
    }
    $existing = $this->findStored($sha);
    if ($existing) {
      return ['reuse' => $existing];
    }
    if (!$this->database->schema()->tableExists('xedc_blob_claim')) {
      return ['claimed' => TRUE];
    }
    $name = 'xedc_blob:' . $sha;
    if (!$this->lock->acquire($name, 30.0)) {
      $this->lock->wait($name, 15);
      $existing = $this->findStored($sha);
      if ($existing) {
        return ['reuse' => $existing];
      }
      if (!$this->lock->acquire($name, 30.0)) {
        return ['busy' => TRUE];
      }
    }
    try {
      $existing = $this->findStored($sha);
      if ($existing) {
        return ['reuse' => $existing];
      }
      $now = $this->time->getRequestTime() ?: time();
      try {
        $this->database->insert('xedc_blob_claim')->fields([
          'sha256' => $sha,
          'bytes' => max(0, $bytes),
          'fid' => 0,
          'uri' => '',
          'kind' => 'stored',
          'status' => 'pending',
          'created' => $now,
          'changed' => $now,
        ])->execute();
        return ['claimed' => TRUE];
      }
      catch (IntegrityConstraintViolationException) {
        $row = $this->database->select('xedc_blob_claim', 'c')
          ->fields('c')
          ->condition('sha256', $sha)
          ->execute()
          ->fetchObject();
        if ($row && (int) $row->fid > 0) {
          $healthy = $this->healthyFile((int) $row->fid, $sha, (int) $row->bytes);
          if ($healthy) {
            return ['reuse' => ['file' => $healthy, 'media' => NULL]];
          }
        }
        if ($row && (string) $row->status === 'pending' && (int) $row->fid === 0 && (int) $row->changed > $now - 600) {
          $this->lock->release($name);
          $deadline = microtime(TRUE) + 25;
          do {
            $ready = $this->findStored($sha);
            if ($ready) {
              return ['reuse' => $ready];
            }
            usleep(200000);
            $row = $this->database->select('xedc_blob_claim', 'c')
              ->fields('c')
              ->condition('sha256', $sha)
              ->execute()
              ->fetchObject();
          } while ($row && (string) $row->status === 'pending' && (int) $row->fid === 0 && microtime(TRUE) < $deadline);
          $ready = $this->findStored($sha);
          if ($ready) {
            return ['reuse' => $ready];
          }
          if ($row && (string) $row->status === 'pending' && (int) $row->fid === 0) {
            return ['busy' => TRUE];
          }
        }
        $this->database->update('xedc_blob_claim')->fields([
          'status' => 'pending',
          'fid' => 0,
          'uri' => '',
          'bytes' => max(0, $bytes),
          'changed' => $now,
        ])->condition('sha256', $sha)->execute();
        return ['claimed' => TRUE];
      }
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Records the file entity that won the claim.
   */
  public function commitStored(string $sha, int $fid, string $uri, int $bytes): void {
    $sha = strtolower(trim($sha));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha) || $fid < 1 || !$this->database->schema()->tableExists('xedc_blob_claim')) {
      return;
    }
    $this->database->update('xedc_blob_claim')->fields([
      'fid' => $fid,
      'uri' => mb_substr($uri, 0, 255),
      'bytes' => max(0, $bytes),
      'status' => 'ready',
      'changed' => $this->time->getRequestTime() ?: time(),
    ])->condition('sha256', $sha)->execute();
  }

  /**
   * Drops a claim that never received a file entity.
   */
  public function abandonStored(string $sha): void {
    $sha = strtolower(trim($sha));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha) || !$this->database->schema()->tableExists('xedc_blob_claim')) {
      return;
    }
    $this->database->delete('xedc_blob_claim')
      ->condition('sha256', $sha)
      ->condition('fid', 0)
      ->execute();
  }

  /**
   * @return array{file: \Drupal\file\FileInterface, media: null}|null
   *   Healthy stored object.
   */
  private function findStored(string $sha): ?array {
    if (!$this->database->schema()->tableExists('xedc_image_file')) {
      return NULL;
    }
    $rows = $this->database->select('xedc_image_file', 'h')
      ->fields('h', ['fid', 'bytes', 'stored_sha256'])
      ->condition('stored_sha256', $sha)
      ->orderBy('fid')
      ->execute();
    foreach ($rows as $row) {
      $file = $this->healthyFile((int) $row->fid, $sha, (int) $row->bytes);
      if ($file) {
        return ['file' => $file, 'media' => NULL];
      }
    }
    if ($this->database->schema()->tableExists('xedc_blob_claim')) {
      $fid = (int) $this->database->select('xedc_blob_claim', 'c')
        ->fields('c', ['fid'])
        ->condition('sha256', $sha)
        ->condition('status', 'ready')
        ->execute()
        ->fetchField();
      $file = $fid > 0 ? $this->healthyFile($fid, $sha, 0) : NULL;
      if ($file) {
        return ['file' => $file, 'media' => NULL];
      }
    }
    return NULL;
  }

  /**
   * Public file whose bytes still match the recorded hash.
   */
  private function healthyFile(int $fid, string $storedHash, int $recordedBytes): ?FileInterface {
    if ($fid < 1) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      $file = File::load($fid);
    }
    if (!$file instanceof FileInterface || !$this->objectReady($file, $storedHash, $recordedBytes)) {
      return NULL;
    }
    return $file;
  }

  /**
   * File entity from a quick-publish media id.
   */
  private function fileFromMedia(int $mid): ?FileInterface {
    if ($mid < 1) {
      return NULL;
    }
    $media = $this->entityTypeManager->getStorage('media')->load($mid);
    if (!$media || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return NULL;
    }
    $file = $media->get('field_media_image')->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * The object exists, is readable, and matches the recorded size and hash.
   */
  private function objectReady(FileInterface $file, string $storedHash, int $recordedBytes = 0): bool {
    $uri = (string) $file->getFileUri();
    if ($uri === '' || str_contains($uri, "\0") || str_contains($uri, '..') || str_starts_with($uri, 'private://')) {
      return FALSE;
    }
    $scheme = strstr($uri, '://', TRUE);
    if (!is_string($scheme) || !in_array($scheme, ['public', 's3'], TRUE)) {
      return FALSE;
    }
    clearstatcache(TRUE, $uri);
    if (!is_file($uri)) {
      $this->logger->warning('去重跳过文件 @fid，存储对象不存在：@uri', [
        '@fid' => $file->id(),
        '@uri' => $uri,
      ]);
      return FALSE;
    }
    $size = filesize($uri);
    if ($size === FALSE || $size < 1) {
      return FALSE;
    }
    if ($recordedBytes > 0 && (int) $size !== $recordedBytes) {
      $this->logger->warning('去重跳过文件 @fid，对象大小 @size 与记录 @recorded 不一致。', [
        '@fid' => $file->id(),
        '@size' => (string) $size,
        '@recorded' => (string) $recordedBytes,
      ]);
      return FALSE;
    }
    if ($storedHash === '') {
      return TRUE;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
      return FALSE;
    }
    $hash = @hash_file('sha256', $uri);
    if (!is_string($hash) || !hash_equals($storedHash, $hash)) {
      $this->logger->warning('去重跳过文件 @fid，对象 SHA-256 与记录不一致。', ['@fid' => $file->id()]);
      return FALSE;
    }
    return TRUE;
  }

}
