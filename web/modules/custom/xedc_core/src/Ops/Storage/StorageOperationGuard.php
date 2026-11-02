<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;

/**
 * Refuses unsafe overwrite, remote cleanup and source deletion.
 *
 * Local cleanup never receives permission to delete a remote object.
 * An object delete also requires the file entity and a reference check.
 */
final class StorageOperationGuard {

  public function __construct(
    protected Connection $database,
    protected StorageUriResolver $uris,
  ) {}

  public function referenced(int $fid): bool {
    if ($fid < 1 || !$this->database->schema()->tableExists('file_usage')) {
      return FALSE;
    }
    $count = (int) $this->database->select('file_usage', 'u')
      ->condition('fid', $fid)
      ->countQuery()
      ->execute()
      ->fetchField();
    return $count > 0;
  }

  public function sourceDeleteBlocked(): ?string {
    if (!(bool) Settings::get('xedc_storage.allow_source_delete', FALSE)) {
      return '删除本地副本需要单独确认，当前开关是关闭的。';
    }
    return NULL;
  }

  public function remoteUri(string $uri): bool {
    return $this->uris->isRemote($uri);
  }

  public function location(string $uri): string {
    return $this->uris->location($uri);
  }

  /**
   * Why a local cleanup task must not delete this URI. NULL means no extra block.
   */
  public function localCleanupBlockReason(string $uri): ?string {
    $location = $this->uris->location($uri);
    if ($location === 'remote' || $this->uris->scheme($uri) === 's3') {
      return '远程文件不能由本地清理任务删除。';
    }
    if ($location === 'temporary') {
      return '临时文件不能由内容清理任务删除。';
    }
    $twin = $this->remoteTwinFid($uri);
    if ($twin !== NULL) {
      return '存在对应的远程文件实体 ' . $twin . '，本地文件和远程对象都保留。';
    }
    return NULL;
  }

  /**
   * Why this object must not be deleted. NULL means the file entity allows it.
   */
  public function objectDeleteBlockReason(string $key, int $fid): ?string {
    if ($this->uris->keyError($key) !== NULL) {
      return '对象键无效。';
    }
    if ($fid < 1) {
      return '删除远程对象前必须指定文件实体。';
    }
    if (!$this->database->schema()->tableExists('file_managed')) {
      return '无法确认文件实体，已拒绝删除。';
    }
    $uri = $this->database->select('file_managed', 'f')
      ->fields('f', ['uri'])
      ->condition('fid', $fid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if (!is_string($uri) || $uri === '') {
      return '文件实体不存在，已拒绝删除。';
    }
    if (!$this->uris->isRemote($uri)) {
      return '文件实体仍指向本地文件，不能删除对应的远程对象。';
    }
    $expected = $this->uris->objectKey($uri);
    if (!is_string($expected) || !hash_equals($expected, $key)) {
      return '对象键与文件实体不一致，已拒绝删除。';
    }
    $same = (int) $this->database->select('file_managed', 'f')
      ->condition('uri', $uri)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($same !== 1) {
      return '多个文件实体使用该 URI，已拒绝删除。';
    }
    if ($this->referenced($fid)) {
      return '文件仍被引用，已拒绝删除。';
    }
    return NULL;
  }

  /**
   * File id of an s3:// object that uses the same target as a local URI.
   */
  private function remoteTwinFid(string $uri): ?int {
    $scheme = $this->uris->scheme($uri);
    if (!in_array($scheme, ['public', 'private'], TRUE) || $this->uris->isRemote($uri)) {
      return NULL;
    }
    if (!$this->database->schema()->tableExists('file_managed')) {
      return NULL;
    }
    $target = $this->uris->target($uri);
    if ($target === '') {
      return NULL;
    }
    $candidates = ['s3://' . $target];
    $key = $this->uris->objectKey($uri);
    if ($key !== NULL) {
      $candidates[] = 's3://' . $key;
    }
    foreach (array_unique($candidates) as $candidate) {
      $fid = $this->database->select('file_managed', 'f')
        ->fields('f', ['fid'])
        ->condition('uri', $candidate)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if (is_numeric($fid) && (int) $fid > 0) {
        return (int) $fid;
      }
    }
    return NULL;
  }

}
