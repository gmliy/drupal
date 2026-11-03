<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Records one playback-copy job per source file and runs FFmpeg off the request.
 */
final class TranscodeManager {

  private const QUEUE = 'xedc_video_transcode';

  private const LOCK = 'xedc_video_transcode';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly FileUsageInterface $usage,
    private readonly FileSystemInterface $fileSystem,
    private readonly LockBackendInterface $lock,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
    private readonly PlaybackTranscoder $playback,
    private readonly MediaProbe $probe,
  ) {}

  /**
   * Probe once and enqueue only when a copy is actually required.
   */
  public function consider(FileInterface $file): void {
    $fid = (int) $file->id();
    if ($fid < 1 || !$this->tableReady()) {
      return;
    }
    $scheme = (string) \Drupal::service('stream_wrapper_manager')->getScheme((string) $file->getFileUri());
    if ($scheme === 'private') {
      $this->save($fid, $file, [
        'status' => 'failed',
        'verdict' => 'blocked',
        'message' => 'private files are not copied into the public directory',
        'attempts' => 3,
      ]);
      return;
    }
    $path = $this->playback->readableSource($file);
    if ($path === '') {
      $this->save($fid, $file, [
        'status' => 'failed',
        'verdict' => 'blocked',
        'message' => 'remote or unreadable files are not downloaded for transcoding',
        'attempts' => 3,
      ]);
      return;
    }
    clearstatcache(TRUE, $path);
    $stamp = (int) (@filemtime($path) ?: 0);
    $existing = $this->row($fid);
    $sameSource = $existing && (int) $existing['source_stamp'] === $stamp;
    if ($sameSource && in_array($existing['status'], ['completed', 'skipped'], TRUE)) {
      if ($existing['status'] !== 'completed' || $this->copyReady($fid)) {
        return;
      }
    }
    $info = $this->probe->inspect($path);
    $fields = $this->fieldsFromProbe($file, $path, $info);
    $canAdopt = (!$existing || $sameSource) && $this->copyReady($fid) && ($info['verdict'] ?? '') === 'transcode';
    if ($canAdopt) {
      $output = $this->probe->inspect($this->playback->playbackFile($fid));
      if (($output['verdict'] ?? '') === 'compatible') {
        $fields['status'] = 'completed';
        $fields['output_fid'] = $this->registerCopy($fid);
        $fields['output_size'] = (int) filesize($this->playback->playbackFile($fid));
        $fields['message'] = 'existing playback copy kept';
        $fields['finished'] = \Drupal::time()->getRequestTime();
        $this->save($fid, $file, $fields);
        return;
      }
    }
    if (($info['verdict'] ?? '') === 'compatible' || ($info['verdict'] ?? '') === 'review' || ($info['verdict'] ?? '') === 'hls') {
      $fields['status'] = 'skipped';
      $fields['message'] = (string) ($info['message'] ?: 'playback copy not required');
      $this->save($fid, $file, $fields);
      return;
    }
    if (($info['verdict'] ?? '') !== 'transcode') {
      $fields['status'] = 'failed';
      $fields['attempts'] = 3;
      $fields['message'] = (string) ($info['message'] ?: 'file cannot be transcoded');
      $this->save($fid, $file, $fields);
      return;
    }
    $already = $existing && (string) $existing['status'] === 'pending';
    $fields['status'] = 'pending';
    $this->save($fid, $file, $fields);
    if (!$already) {
      $this->enqueue($fid);
    }
  }

  public function enqueue(int $fid): void {
    if ($fid < 1) {
      return;
    }
    $queued = $this->database->select(TranscodeSchema::TABLE, 't')
      ->fields('t', ['status'])
      ->condition('source_fid', $fid)
      ->execute()
      ->fetchField();
    if ($queued === 'processing') {
      return;
    }
    $this->queueFactory->get(self::QUEUE, TRUE)->createItem(['fid' => $fid]);
  }

  public function run(int $fid): void {
    if (!$this->lock->acquire(self::LOCK, 200)) {
      throw new SuspendQueueException('A playback transcode is already running.');
    }
    try {
      $this->execute($fid);
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * @return array{url: string, state: string}
   */
  public function choice(MediaInterface|FileInterface|null $entity): array {
    $file = $entity instanceof MediaInterface ? $this->fileFromMedia($entity) : $entity;
    if (!$file instanceof FileInterface) {
      return ['url' => '', 'state' => 'missing'];
    }
    $fid = (int) $file->id();
    $row = $this->row($fid);
    $status = (string) ($row['status'] ?? '');
    if ($status === 'completed' && $this->copyReady($fid)) {
      return ['url' => '/sites/default/files/xedc-playback/' . $fid . '.mp4', 'state' => 'copy'];
    }
    if ($status === 'pending' || $status === 'processing') {
      return ['url' => '', 'state' => 'transcoding'];
    }
    if ($status === 'failed') {
      return ['url' => '', 'state' => 'failed'];
    }
    if ($status === '' && $this->copyReady($fid)) {
      return ['url' => '/sites/default/files/xedc-playback/' . $fid . '.mp4', 'state' => 'copy'];
    }
    return ['url' => '', 'state' => 'original'];
  }

  /**
   * @return array<string, int>
   */
  public function summary(): array {
    $counts = [
      'videos' => 0,
      'detected' => 0,
      'compatible' => 0,
      'transcode' => 0,
      'pending' => 0,
      'processing' => 0,
      'completed' => 0,
      'failed' => 0,
      'copies' => 0,
    ];
    if (!$this->tableReady()) {
      return $counts;
    }
    $counts['videos'] = (int) $this->database->select('node_field_data', 'n')
      ->condition('type', 'video')
      ->condition('default_langcode', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
    $counts['detected'] = (int) $this->database->select(TranscodeSchema::TABLE, 't')->countQuery()->execute()->fetchField();
    foreach ($this->database->select(TranscodeSchema::TABLE, 't')->fields('t', ['status', 'verdict'])->execute() as $row) {
      $status = (string) $row->status;
      $verdict = (string) $row->verdict;
      if (isset($counts[$status])) {
        $counts[$status]++;
      }
      if ($verdict === 'compatible') {
        $counts['compatible']++;
      }
      if ($verdict === 'transcode') {
        $counts['transcode']++;
      }
      if ($status === 'completed') {
        $counts['copies']++;
      }
    }
    return $counts;
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   */
  public function listing(string $status, string $codec, string $keys, int $limit, int $offset): array {
    if (!$this->tableReady()) {
      return ['rows' => [], 'total' => 0];
    }
    $query = $this->database->select(TranscodeSchema::TABLE, 't')->fields('t');
    if ($status !== '') {
      $query->condition('status', $status);
    }
    if ($codec !== '') {
      $query->condition('video_codec', $codec);
    }
    if ($keys !== '') {
      $fids = $this->matchingFiles($keys);
      if (!$fids) {
        return ['rows' => [], 'total' => 0];
      }
      $query->condition('source_fid', $fids, 'IN');
    }
    $count = (int) (clone $query)->countQuery()->execute()->fetchField();
    $rows = [];
    foreach ($query->orderBy('changed', 'DESC')->range($offset, $limit)->execute() as $row) {
      $item = (array) $row;
      $item['nodes'] = $this->references((int) $row->source_fid);
      $rows[] = $item;
    }
    return ['rows' => $rows, 'total' => $count];
  }

  /**
   * @return list<array{nid: int, title: string}>
   */
  public function references(int $fid): array {
    if (!$this->database->schema()->tableExists('media__field_media_video_file') || !$this->database->schema()->tableExists('node__field_video_file')) {
      return [];
    }
    $nodes = [];
    $result = $this->database->query(
      'SELECT DISTINCT n.nid, n.title FROM {node__field_video_file} vf
       INNER JOIN {media__field_media_video_file} mf ON mf.entity_id = vf.field_video_file_target_id AND mf.deleted = 0
       INNER JOIN {node_field_data} n ON n.nid = vf.entity_id AND n.default_langcode = 1
       WHERE vf.deleted = 0 AND mf.field_media_video_file_target_id = :fid',
      [':fid' => $fid]
    );
    foreach ($result as $row) {
      $nodes[] = ['nid' => (int) $row->nid, 'title' => (string) $row->title];
    }
    return $nodes;
  }

  public function cancel(int $fid): void {
    $row = $this->row($fid);
    if (!$row || !in_array($row['status'], ['pending', 'failed'], TRUE)) {
      return;
    }
    $this->database->update(TranscodeSchema::TABLE)
      ->fields(['status' => 'cancelled', 'changed' => \Drupal::time()->getRequestTime()])
      ->condition('source_fid', $fid)
      ->execute();
    Cache::invalidateTags(['xedc_video_transcode:' . $fid]);
  }

  public function retry(int $fid): void {
    $row = $this->row($fid);
    if (!$row || !in_array((string) $row['verdict'], ['transcode', 'unreadable'], TRUE)) {
      return;
    }
    $this->database->update(TranscodeSchema::TABLE)
      ->fields([
        'status' => 'pending',
        'attempts' => 0,
        'message' => '',
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('source_fid', $fid)
      ->execute();
    Cache::invalidateTags(['xedc_video_transcode:' . $fid]);
    $this->enqueue($fid);
  }

  public function cleanupTemps(): int {
    $directory = $this->fileSystem->getTempDirectory();
    $removed = 0;
    foreach (glob($directory . '/xedc-transcode-*.part.mp4') ?: [] as $path) {
      if (is_file($path) && @unlink($path)) {
        $removed++;
      }
    }
    return $removed;
  }

  private function execute(int $fid): void {
    $file = $this->entities->getStorage('file')->load($fid);
    $row = $this->row($fid);
    if (!$file instanceof FileInterface || !$row || in_array($row['status'], ['cancelled', 'completed', 'skipped'], TRUE)) {
      return;
    }
    if ((int) $row['attempts'] >= 3) {
      $this->mark($fid, 'failed', 'retry limit reached');
      return;
    }
    $free = @disk_free_space(DRUPAL_ROOT);
    if ($free !== FALSE && $free < 512 * 1024 * 1024) {
      $this->mark($fid, 'failed', 'not enough free disk space');
      return;
    }
    $source = $this->playback->readableSource($file);
    if ($source === '') {
      $this->mark($fid, 'failed', 'source file is not readable locally');
      return;
    }
    $now = \Drupal::time()->getRequestTime();
    $this->database->update(TranscodeSchema::TABLE)
      ->fields(['status' => 'processing', 'started' => $now, 'changed' => $now])
      ->expression('attempts', 'attempts + 1')
      ->condition('source_fid', $fid)
      ->execute();
    $temp = $this->fileSystem->getTempDirectory() . '/xedc-transcode-' . $fid . '.part.mp4';
    $audio = (string) $row['audio_codec'] !== '';
    $ok = $this->playback->writeCopy($source, $temp, $audio, (int) $row['width'], (int) $row['height']);
    $output = $ok ? $this->probe->inspect($temp) : ['verdict' => 'unreadable', 'message' => 'ffmpeg did not produce a file'];
    if (!$ok || ($output['verdict'] ?? '') !== 'compatible' || (int) ($output['duration'] ?? 0) < 1 || !is_file($temp) || filesize($temp) < 1) {
      if (is_file($temp)) {
        @unlink($temp);
      }
      $attempts = (int) $this->row($fid)['attempts'];
      $message = (string) ($output['message'] ?: 'output failed validation');
      if ($attempts < 3) {
        $this->mark($fid, 'pending', $message);
        $this->enqueue($fid);
      }
      else {
        $this->mark($fid, 'failed', $message);
      }
      return;
    }
    $final = $this->playback->playbackFile($fid);
    $directory = dirname($final);
    if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      @unlink($temp);
      $this->mark($fid, 'failed', 'playback directory is not writable');
      return;
    }
    if (!@rename($temp, $final)) {
      @unlink($temp);
      $this->mark($fid, 'failed', 'could not publish the playback copy');
      return;
    }
    @chmod($final, 0644);
    $finished = \Drupal::time()->getRequestTime();
    $this->database->update(TranscodeSchema::TABLE)
      ->fields([
        'status' => 'completed',
        'output_fid' => $this->registerCopy($fid),
        'output_size' => (int) filesize($final),
        'message' => 'playback copy verified',
        'finished' => $finished,
        'elapsed' => max(0, $finished - $now),
        'changed' => $finished,
      ])
      ->condition('source_fid', $fid)
      ->execute();
    Cache::invalidateTags(['xedc_video_transcode:' . $fid]);
  }

  private function registerCopy(int $sourceFid): int {
    $uri = 'public://xedc-playback/' . $sourceFid . '.mp4';
    $existing = $this->entities->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = $existing ? reset($existing) : NULL;
    if (!$file instanceof FileInterface) {
      $file = $this->entities->getStorage('file')->create([
        'uri' => $uri,
        'status' => FileInterface::STATUS_PERMANENT,
        'uid' => 1,
      ]);
      $file->save();
    }
    $listed = $this->usage->listUsage($file);
    $known = $listed['xedc_core']['video_playback'][$sourceFid] ?? 0;
    if ((int) $known < 1) {
      $this->usage->add($file, 'xedc_core', 'video_playback', $sourceFid);
    }
    return (int) $file->id();
  }

  /**
   * @param array<string, int|string> $info
   * @return array<string, int|string>
   */
  private function fieldsFromProbe(FileInterface $file, string $path, array $info): array {
    return [
      'verdict' => (string) $info['verdict'],
      'source_uri' => (string) $file->getFileUri(),
      'source_size' => (int) (@filesize($path) ?: $file->getSize()),
      'source_stamp' => (int) (@filemtime($path) ?: 0),
      'video_codec' => (string) $info['video_codec'],
      'audio_codec' => (string) $info['audio_codec'],
      'container' => substr((string) $info['container'], 0, 64),
      'profile' => substr((string) $info['profile'], 0, 64),
      'pix_fmt' => (string) $info['pix_fmt'],
      'width' => (int) $info['width'],
      'height' => (int) $info['height'],
      'message' => substr((string) $info['message'], 0, 255),
      'changed' => \Drupal::time()->getRequestTime(),
    ];
  }

  /**
   * @param array<string, int|string> $fields
   */
  private function save(int $fid, FileInterface $file, array $fields): void {
    $fields += [
      'source_uri' => (string) $file->getFileUri(),
      'changed' => \Drupal::time()->getRequestTime(),
    ];
    if ($this->row($fid)) {
      $this->database->update(TranscodeSchema::TABLE)->fields($fields)->condition('source_fid', $fid)->execute();
    }
    else {
      $fields['source_fid'] = $fid;
      $this->database->insert(TranscodeSchema::TABLE)->fields($fields)->execute();
    }
    Cache::invalidateTags(['xedc_video_transcode:' . $fid]);
  }

  private function mark(int $fid, string $status, string $message): void {
    $this->database->update(TranscodeSchema::TABLE)
      ->fields([
        'status' => $status,
        'message' => substr($message, 0, 255),
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('source_fid', $fid)
      ->execute();
    Cache::invalidateTags(['xedc_video_transcode:' . $fid]);
    $this->logger->warning('播放副本任务 @fid：@message', ['@fid' => $fid, '@message' => $message]);
  }

  /**
   * @return array<string, mixed>|null
   */
  private function row(int $fid): ?array {
    if (!$this->tableReady()) {
      return NULL;
    }
    $row = $this->database->select(TranscodeSchema::TABLE, 't')->fields('t')->condition('source_fid', $fid)->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  private function copyReady(int $fid): bool {
    $path = $this->playback->playbackFile($fid);
    return $path !== '' && is_file($path) && filesize($path) > 0;
  }

  private function tableReady(): bool {
    return $this->database->schema()->tableExists(TranscodeSchema::TABLE);
  }

  private function fileFromMedia(MediaInterface $media): ?FileInterface {
    $fid = (int) $media->getSource()->getSourceFieldValue($media);
    $file = $fid > 0 ? $this->entities->getStorage('file')->load($fid) : NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * @return list<int>
   */
  private function matchingFiles(string $keys): array {
    $like = '%' . $this->database->escapeLike($keys) . '%';
    $fids = $this->database->select(TranscodeSchema::TABLE, 't')
      ->fields('t', ['source_fid'])
      ->condition('source_uri', $like, 'LIKE')
      ->execute()
      ->fetchCol();
    if (ctype_digit($keys)) {
      $fids[] = (int) $keys;
    }
    $nodeIds = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->condition('type', 'video')
      ->condition('default_langcode', 1)
      ->condition('title', $like, 'LIKE')
      ->range(0, 50)
      ->execute()
      ->fetchCol();
    foreach ($nodeIds as $nid) {
      foreach ($this->referencesForNode((int) $nid) as $fid) {
        $fids[] = $fid;
      }
    }
    return array_values(array_unique(array_map('intval', $fids)));
  }

  /**
   * @return list<int>
   */
  private function referencesForNode(int $nid): array {
    if (!$this->database->schema()->tableExists('node__field_video_file')) {
      return [];
    }
    return array_map('intval', $this->database->query(
      'SELECT mf.field_media_video_file_target_id FROM {node__field_video_file} vf
       INNER JOIN {media__field_media_video_file} mf ON mf.entity_id = vf.field_video_file_target_id AND mf.deleted = 0
       WHERE vf.deleted = 0 AND vf.entity_id = :nid',
      [':nid' => $nid]
    )->fetchCol());
  }

}
