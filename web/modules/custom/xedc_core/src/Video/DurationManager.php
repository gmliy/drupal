<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Queues and runs ffprobe without blocking the upload request.
 */
final class DurationManager {

  private const QUEUE = 'xedc_video_duration';

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly FileSystemInterface $fileSystem,
    private readonly StreamWrapperManagerInterface $streams,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
    private readonly LoggerChannelInterface $logger,
    private readonly TranscodeManager $transcodes,
  ) {}

  public function onNodeSave(NodeInterface $node): void {
    if ($node->bundle() !== 'video' || !$node->id()) {
      return;
    }
    $fid = $this->fileIdFromNode($node);
    if ($fid < 1) {
      return;
    }
    $previous = 0;
    if ($node->original instanceof NodeInterface) {
      $previous = $this->fileIdFromNode($node->original);
    }
    $seconds = $node->hasField('field_duration') ? (int) $node->get('field_duration')->value : 0;
    $replaced = $previous > 0 && $previous !== $fid;
    if ($replaced || $seconds < 1) {
      $this->enqueue((int) $node->id(), $fid, $replaced);
    }
  }

  public function onMediaUpdate(MediaInterface $media): void {
    if ($media->bundle() !== 'video' || !$media->original instanceof MediaInterface) {
      return;
    }
    $fid = $this->fileIdFromMedia($media);
    $previous = $this->fileIdFromMedia($media->original);
    if ($fid < 1 || $fid === $previous) {
      return;
    }
    $nids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'video')
      ->condition('field_video_file', $media->id())
      ->execute();
    foreach ($nids as $nid) {
      $this->enqueue((int) $nid, $fid, TRUE);
    }
  }

  public function onFileDelete(FileInterface $file): void {
    $pending = $this->pending();
    unset($pending[(int) $file->id()]);
    $this->state->set('xedc_video.pending', $pending);
  }

  public function onNodeDelete(NodeInterface $node): void {
    if ($node->bundle() !== 'video') {
      return;
    }
    $failures = $this->state->get('xedc_video.failures', []);
    unset($failures[(int) $node->id()]);
    $this->state->set('xedc_video.failures', $failures);
  }

  public function enqueue(int $nid, int $fid, bool $force): void {
    if ($nid < 1 || $fid < 1) {
      return;
    }
    $pending = $this->pending();
    if (isset($pending[$fid]) && (int) ($pending[$fid]['nid'] ?? 0) === $nid) {
      return;
    }
    $pending[$fid] = ['nid' => $nid, 'at' => \Drupal::time()->getRequestTime()];
    $this->state->set('xedc_video.pending', $pending);
    $this->queueFactory->get(self::QUEUE, TRUE)->createItem([
      'nid' => $nid,
      'fid' => $fid,
      'force' => $force,
    ]);
  }

  /**
   * @param array<string, mixed> $data
   */
  public function process(array $data): void {
    $nid = (int) ($data['nid'] ?? 0);
    $fid = (int) ($data['fid'] ?? 0);
    $force = !empty($data['force']);
    $pending = $this->pending();
    unset($pending[$fid]);
    $this->state->set('xedc_video.pending', $pending);
    $node = $this->entities->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'video') {
      return;
    }
    $source = $this->fileFromNode($node);
    if ($source instanceof FileInterface) {
      $this->transcodes->consider($source);
    }
    $current = $node->hasField('field_duration') ? (int) $node->get('field_duration')->value : 0;
    if (!$force && $current > 0) {
      return;
    }
    $result = $this->probeNode($node);
    if (!empty($result['temporary'])) {
      $attempts = (int) $this->state->get('xedc_video.attempt.' . $fid, 0) + 1;
      $this->state->set('xedc_video.attempt.' . $fid, $attempts);
      if ($attempts < 3) {
        $this->enqueue($nid, $fid, $force);
        return;
      }
    }
    if ($fid > 0) {
      $this->state->delete('xedc_video.attempt.' . $fid);
    }
    if (empty($result['ok'])) {
      $this->rememberFailure($nid, (string) ($result['reason'] ?? 'unknown'));
      return;
    }
    $this->writeDuration($node, (int) $result['seconds']);
    $this->forgetFailure($nid);
  }

  /**
   * @return array{total: int, with_duration: int, missing: int, file_missing: int, unreadable: int}
   */
  public function statistics(): array {
    $rows = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->condition('type', 'video')
      ->condition('default_langcode', 1)
      ->execute()
      ->fetchCol();
    $stats = [
      'total' => count($rows),
      'with_duration' => 0,
      'missing' => 0,
      'file_missing' => 0,
      'unreadable' => 0,
    ];
    if (!$rows) {
      return $stats;
    }
    $storage = $this->entities->getStorage('node');
    foreach ($rows as $nid) {
      $node = $storage->load($nid);
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $seconds = $node->hasField('field_duration') ? (int) $node->get('field_duration')->value : 0;
      if ($seconds > 0) {
        $stats['with_duration']++;
      }
      else {
        $stats['missing']++;
      }
      $located = $this->locate($node);
      if ($located['state'] === 'missing') {
        $stats['file_missing']++;
      }
      elseif ($located['state'] === 'remote' || $located['state'] === 'unreadable') {
        $stats['unreadable']++;
      }
      $storage->resetCache([(int) $nid]);
    }
    return $stats;
  }

  /**
   * @return array{processed: int, updated: int, failed: int, cursor: int, done: bool, rows: array<int, array<string, mixed>>}
   */
  public function backfill(int $limit, bool $retry): array {
    $limit = max(1, min(20, $limit));
    if ($retry) {
      $this->state->set('xedc_video.backfill_cursor', 0);
    }
    $cursor = (int) $this->state->get('xedc_video.backfill_cursor', 0);
    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('node__field_duration', 'd', 'd.entity_id = n.nid AND d.deleted = 0 AND d.field_duration_value > 0');
    $query->fields('n', ['nid']);
    $query->condition('n.type', 'video');
    $query->condition('n.default_langcode', 1);
    $query->condition('n.nid', $cursor, '>');
    $query->isNull('d.entity_id');
    $query->orderBy('n.nid');
    $query->distinct();
    $query->range(0, $limit);
    $nids = $query->execute()->fetchCol();
    $report = [
      'processed' => 0,
      'updated' => 0,
      'failed' => 0,
      'cursor' => $cursor,
      'done' => $nids === [],
      'rows' => [],
    ];
    foreach ($nids as $nid) {
      $nid = (int) $nid;
      $node = $this->entities->getStorage('node')->load($nid);
      $report['processed']++;
      $report['cursor'] = $nid;
      $this->state->set('xedc_video.backfill_cursor', $nid);
      if (!$node instanceof NodeInterface) {
        $report['failed']++;
        $report['rows'][] = ['nid' => $nid, 'ok' => FALSE, 'reason' => 'node missing'];
        continue;
      }
      $existing = $node->hasField('field_duration') ? (int) $node->get('field_duration')->value : 0;
      if ($existing > 0) {
        $report['rows'][] = ['nid' => $nid, 'ok' => TRUE, 'reason' => 'skipped', 'seconds' => $existing];
        continue;
      }
      $before = $this->snapshot($node);
      $result = $this->probeNode($node);
      if (!empty($result['ok'])) {
        $this->writeDuration($node, (int) $result['seconds']);
        $this->forgetFailure($nid);
        $after = $this->snapshot($this->entities->getStorage('node')->loadUnchanged($nid));
        $report['updated']++;
        $report['rows'][] = [
          'nid' => $nid,
          'ok' => TRUE,
          'seconds' => (int) $result['seconds'],
          'unchanged' => $before === $after,
        ];
      }
      else {
        $reason = (string) ($result['reason'] ?? 'unknown');
        $this->rememberFailure($nid, $reason);
        $report['failed']++;
        $report['rows'][] = ['nid' => $nid, 'ok' => FALSE, 'reason' => $reason];
      }
    }
    return $report;
  }

  /**
   * Re-reads one video and writes field_duration only when probing succeeds.
   *
   * @return array<string, mixed>
   */
  public function refresh(int $nid): array {
    $node = $this->entities->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'video') {
      return ['ok' => FALSE, 'reason' => 'not a video'];
    }
    $before = $this->snapshot($node);
    $result = $this->probeNode($node);
    if (empty($result['ok'])) {
      $this->rememberFailure($nid, (string) ($result['reason'] ?? 'unknown'));
      return $result + ['unchanged' => TRUE];
    }
    $this->writeDuration($node, (int) $result['seconds']);
    $this->forgetFailure($nid);
    $after = $this->snapshot($this->entities->getStorage('node')->loadUnchanged($nid));
    $result['unchanged'] = $before === $after;
    return $result;
  }

  /**
   * @return array<string, mixed>
   */
  public function failures(): array {
    $failures = $this->state->get('xedc_video.failures', []);
    return is_array($failures) ? $failures : [];
  }

  /**
   * @return array<string, mixed>
   */
  private function probeNode(NodeInterface $node): array {
    $located = $this->locate($node);
    if ($located['state'] !== 'local') {
      return ['ok' => FALSE, 'reason' => (string) $located['reason'], 'temporary' => FALSE];
    }
    return $this->probePath((string) $located['path']);
  }

  /**
   * @return array{state: string, path: string, reason: string}
   */
  private function locate(NodeInterface $node): array {
    $file = $this->fileFromNode($node);
    if ($file instanceof FileInterface) {
      $uri = (string) $file->getFileUri();
      $scheme = (string) $this->streams->getScheme($uri);
      if (!in_array($scheme, ['public', 'private'], TRUE)) {
        return ['state' => 'remote', 'path' => '', 'reason' => 'remote file was not downloaded'];
      }
      $path = $this->fileSystem->realpath($uri);
      $root = $this->fileSystem->realpath($scheme . '://');
      if (!is_string($path) || !is_file($path) || !is_string($root) || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return ['state' => 'missing', 'path' => '', 'reason' => 'local file is missing'];
      }
      return ['state' => 'local', 'path' => $path, 'reason' => ''];
    }
    if ($node->hasField('field_video_url') && !$node->get('field_video_url')->isEmpty()) {
      return ['state' => 'remote', 'path' => '', 'reason' => 'remote address was not downloaded'];
    }
    return ['state' => 'missing', 'path' => '', 'reason' => 'video file is missing'];
  }

  /**
   * @return array<string, mixed>
   */
  private function probePath(string $path): array {
    $binary = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : 'ffprobe';
    $process = new Process([
      $binary,
      '-v', 'error',
      '-show_entries', 'format=duration',
      '-of', 'default=noprint_wrappers=1:nokey=1',
      '-probesize', '5000000',
      '-analyzeduration', '10000000',
      $path,
    ]);
    $process->setTimeout(20);
    try {
      $process->run();
    }
    catch (ProcessTimedOutException $e) {
      $this->logger->error('视频时长检测超时：@path', ['@path' => $path]);
      return ['ok' => FALSE, 'reason' => 'ffprobe timed out', 'temporary' => TRUE];
    }
    catch (\Throwable $e) {
      $this->logger->error('视频时长检测失败：@message', ['@message' => $e->getMessage()]);
      return ['ok' => FALSE, 'reason' => 'ffprobe failed', 'temporary' => TRUE];
    }
    if (!$process->isSuccessful()) {
      $detail = trim($process->getErrorOutput());
      if (strlen($detail) > 240) {
        $detail = substr($detail, 0, 240);
      }
      $this->logger->error('ffprobe 退出异常：@error', ['@error' => $detail !== '' ? $detail : 'exit ' . $process->getExitCode()]);
      return ['ok' => FALSE, 'reason' => 'ffprobe could not read the file', 'temporary' => FALSE];
    }
    $seconds = (int) round((float) trim($process->getOutput()));
    if ($seconds < 1) {
      return ['ok' => FALSE, 'reason' => 'duration was empty', 'temporary' => FALSE];
    }
    return ['ok' => TRUE, 'seconds' => $seconds, 'temporary' => FALSE];
  }

  private function writeDuration(NodeInterface $node, int $seconds): void {
    $nid = (int) $node->id();
    $vid = (int) $node->getRevisionId();
    $langcode = $node->language()->getId();
    foreach (['node__field_duration' => FALSE, 'node_revision__field_duration' => TRUE] as $table => $revision) {
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }
      $select = $this->database->select($table, 'd')
        ->fields('d', ['entity_id'])
        ->condition('entity_id', $nid);
      if ($revision) {
        $select->condition('revision_id', $vid);
      }
      $exists = $select->range(0, 1)->execute()->fetchField();
      if ($exists) {
        $update = $this->database->update($table)
          ->fields(['field_duration_value' => $seconds])
          ->condition('entity_id', $nid);
        if ($revision) {
          $update->condition('revision_id', $vid);
        }
        $update->execute();
        continue;
      }
      $this->database->insert($table)->fields([
        'bundle' => 'video',
        'deleted' => 0,
        'entity_id' => $nid,
        'revision_id' => $vid,
        'langcode' => $langcode,
        'delta' => 0,
        'field_duration_value' => $seconds,
      ])->execute();
    }
    $this->entities->getStorage('node')->resetCache([$nid]);
    Cache::invalidateTags(['node:' . $nid, 'node_list:video']);
  }

  /**
   * @return array{title: string, status: int, created: int, changed: int}
   */
  private function snapshot(NodeInterface $node): array {
    $moderation = '';
    if ($node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      $moderation = (string) $node->get('moderation_state')->value;
    }
    return [
      'title' => $node->label(),
      'status' => (int) $node->isPublished(),
      'created' => $node->getCreatedTime(),
      'changed' => $node->getChangedTime(),
      'moderation' => $moderation,
    ];
  }

  private function fileFromNode(NodeInterface $node): ?FileInterface {
    if (!$node->hasField('field_video_file') || $node->get('field_video_file')->isEmpty()) {
      return NULL;
    }
    $media = $node->get('field_video_file')->entity;
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $fid = $this->fileIdFromMedia($media);
    if ($fid < 1) {
      return NULL;
    }
    $file = $this->entities->getStorage('file')->load($fid);
    return $file instanceof FileInterface ? $file : NULL;
  }

  private function fileIdFromNode(NodeInterface $node): int {
    $file = $this->fileFromNode($node);
    return $file instanceof FileInterface ? (int) $file->id() : 0;
  }

  private function fileIdFromMedia(MediaInterface $media): int {
    if (!$media->hasField('field_media_video_file') || $media->get('field_media_video_file')->isEmpty()) {
      return 0;
    }
    return (int) $media->get('field_media_video_file')->target_id;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function pending(): array {
    $pending = $this->state->get('xedc_video.pending', []);
    return is_array($pending) ? $pending : [];
  }

  private function rememberFailure(int $nid, string $reason): void {
    $failures = $this->failures();
    $failures[$nid] = [
      'reason' => $reason,
      'at' => \Drupal::time()->getRequestTime(),
    ];
    $this->state->set('xedc_video.failures', $failures);
    $this->logger->warning('视频 @nid 时长未写入：@reason', [
      '@nid' => $nid,
      '@reason' => $reason,
    ]);
  }

  private function forgetFailure(int $nid): void {
    $failures = $this->failures();
    unset($failures[$nid]);
    $this->state->set('xedc_video.failures', $failures);
  }

}
