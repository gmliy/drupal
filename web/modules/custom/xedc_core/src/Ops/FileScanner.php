<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Batched, read-only scan of a fixed set of directories.
 */
class FileScanner {

  private const ROOTS = [
    'drupal' => '/var/www/drupal',
    'files' => '/var/www/drupal/web/sites/default/files',
    'styles' => '/var/www/drupal/web/sites/default/files/styles',
    'private' => '/var/www/drupal/private',
    'log' => '/var/log',
    'docker' => '/var/lib/docker',
    'mysql' => '/var/lib/mysql',
  ];

  private const WALK = ['drupal', 'log', 'docker', 'mysql'];

  private const CONTENT = ['files', 'private'];

  private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

  private const VIDEO = ['mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi', 'mpeg', 'mpg', 'wmv', 'flv'];

  private const PDF = ['pdf'];

  private const ARCHIVE = ['zip', '7z', 'rar', 'gz', 'tar', 'tgz', 'bz2'];

  private ?int $webUid = NULL;

  private ?int $webGid = NULL;

  public function __construct(
    KeyValueFactoryInterface $keyValueFactory,
    protected QueueFactory $queueFactory,
    protected \Drupal\Core\Lock\LockBackendInterface $lock,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {
    $this->store = $keyValueFactory->get('xedc_core.file_scan');
    $this->resolveWebUser();
  }

  /**
   * Key-value store for the scan job. Assigned in the constructor.
   */
  private KeyValueStoreInterface $store;

  /**
   * Starts a new scan when one is not already running.
   */
  public function start(): string {
    if (!$this->lock->acquire('xedc_core_file_scan', 30.0)) {
      return 'busy';
    }
    try {
      $job = $this->loadJob();
      if ($job['status'] === 'running') {
        return 'running';
      }
      if ($this->webUid === NULL) {
        return 'error';
      }
      $job = $this->freshJob();
      $job['status'] = 'running';
      $job['phase'] = 'walk';
      $job['started'] = $this->time->getCurrentTime();
      $job['updated'] = $job['started'];
      $job['message'] = '正在统计目录。';
      $this->prepareQueue($job);
      $this->loadManaged($job);
      $this->saveJob($job);
      $this->ensureQueued();
      return 'started';
    }
    finally {
      $this->lock->release('xedc_core_file_scan');
    }
  }

  public function pause(): string {
    if (!$this->lock->acquire('xedc_core_file_scan', 30.0)) {
      return 'busy';
    }
    try {
      $job = $this->loadJob();
      if ($job['status'] !== 'running') {
        return $job['status'];
      }
      $job['status'] = 'paused';
      $job['message'] = '扫描已暂停，已统计的结果还在。';
      $job['updated'] = $this->time->getCurrentTime();
      $this->saveJob($job);
      return 'paused';
    }
    finally {
      $this->lock->release('xedc_core_file_scan');
    }
  }

  public function resume(): string {
    if (!$this->lock->acquire('xedc_core_file_scan', 30.0)) {
      return 'busy';
    }
    try {
      $job = $this->loadJob();
      if (!in_array($job['status'], ['paused', 'error'], TRUE) || $job['phase'] === 'idle') {
        return $job['status'];
      }
      $job['status'] = 'running';
      $job['message'] = '继续扫描。';
      $job['updated'] = $this->time->getCurrentTime();
      $this->saveJob($job);
      $this->ensureQueued();
      return 'resumed';
    }
    finally {
      $this->lock->release('xedc_core_file_scan');
    }
  }

  /**
   * Processes one batch. Returns more, done, paused, idle, busy, or error.
   */
  public function step(): string {
    if (!$this->lock->acquire('xedc_core_file_scan', 30.0)) {
      return 'busy';
    }
    try {
      $job = $this->loadJob();
      if ($job['status'] === 'paused') {
        return 'paused';
      }
      if ($job['status'] !== 'running') {
        return $job['status'] === 'done' ? 'done' : 'idle';
      }
      $started = microtime(TRUE);
      $budget = (int) $job['limits']['batch'];
      $deadline = $started + ((int) $job['limits']['seconds']);
      if ($job['phase'] === 'walk') {
        $this->walk($job, $budget, $deadline);
      }
      elseif ($job['phase'] === 'hash') {
        $this->hash($job, $deadline);
      }
      if ($job['phase'] === 'done' && $job['status'] === 'running') {
        $this->complete($job);
      }
      $job['elapsed_ms'] += (int) round((microtime(TRUE) - $started) * 1000);
      $job['updated'] = $this->time->getCurrentTime();
      $this->applyWallClock($job);
      $this->saveJob($job);
      return $job['status'] === 'running' ? 'more' : (string) $job['status'];
    }
    catch (\Throwable $exception) {
      $this->logger->error('File scan stopped: @message', ['@message' => $exception->getMessage()]);
      $job = $this->loadJob();
      $job['status'] = 'error';
      $job['message'] = '扫描中断：' . $exception->getMessage();
      $job['updated'] = $this->time->getCurrentTime();
      $this->saveJob($job);
      return 'error';
    }
    finally {
      $this->lock->release('xedc_core_file_scan');
    }
  }

  /**
   * @return array<string, mixed>
   *   Progress safe to show and to poll.
   */
  public function jobSummary(): array {
    $job = $this->loadJob();
    $roots = [];
    foreach (self::ROOTS as $id => $path) {
      $stored = $job['roots'][$id] ?? NULL;
      if (is_array($stored)) {
        $roots[] = $this->presentRoot($id, $path, $stored);
        continue;
      }
      $roots[] = $this->presentRoot($id, $path, $this->inspectRoot($path));
    }
    return [
      'status' => $job['status'],
      'status_label' => $this->statusLabel($job['status']),
      'phase' => $job['phase'],
      'phase_label' => $this->phaseLabel($job['phase']),
      'message' => (string) $job['message'],
      'processed' => (int) $job['processed'],
      'dirs' => (int) $job['dirs'],
      'failures' => (int) $job['failures'],
      'denied' => (int) $job['denied'],
      'hash_done' => (int) $job['hash_done'],
      'hash_total' => (int) $job['hash_total'],
      'started' => $this->formatTime((int) $job['started']),
      'updated' => $this->formatTime((int) $job['updated']),
      'finished' => $this->formatTime((int) $job['finished']),
      'elapsed' => OpsFormat::elapsedMs((int) $job['elapsed_ms']),
      'roots' => $roots,
    ];
  }

  /**
   * @return array<string, mixed>|null
   *   Last finished report.
   */
  public function report(): ?array {
    $report = $this->store->get('report');
    return is_array($report) ? $report : NULL;
  }

  /**
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function walk(array &$job, int $budget, float $deadline): void {
    while ($budget > 0 && microtime(TRUE) < $deadline) {
      if (empty($job['current_dir'])) {
        $next = array_pop($job['queue']);
        if (!is_string($next)) {
          $this->finishWalk($job);
          return;
        }
        if (isset($job['visited'][$next]) || !$this->insideWalk($next, $job)) {
          continue;
        }
        $job['visited'][$next] = 1;
        if (!$this->canList($next)) {
          $job['denied']++;
          $job['failures']++;
          $this->markDenied($job, $next);
          continue;
        }
        $names = @scandir($next);
        if ($names === FALSE) {
          $job['denied']++;
          $job['failures']++;
          continue;
        }
        $stat = @lstat($next);
        $job['current_dir'] = $next;
        $job['current_names'] = array_values(array_diff($names, ['.', '..']));
        $job['dirs']++;
        if (is_array($stat)) {
          $this->addDirectoryInode($job, $next);
        }
      }
      $dir = (string) $job['current_dir'];
      while ($budget > 0 && microtime(TRUE) < $deadline && !empty($job['current_names'])) {
        $name = array_pop($job['current_names']);
        $budget--;
        if (!is_string($name) || $name === '' || str_contains($name, '/') || str_contains($name, "\0")) {
          $job['failures']++;
          continue;
        }
        $full = $dir . '/' . $name;
        if (!$this->insideWalk($full, $job)) {
          $job['failures']++;
          continue;
        }
        $stat = @lstat($full);
        if (!is_array($stat)) {
          $job['failures']++;
          continue;
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0120000) {
          $job['links']++;
          continue;
        }
        if ($type === 0040000) {
          $job['dir_children'][$dir][] = $full;
          $job['queue'][] = $full;
          continue;
        }
        if ($type === 0100000) {
          $this->accountFile($job, $full, $stat);
        }
      }
      if (empty($job['current_names'])) {
        $job['current_dir'] = '';
        $job['current_names'] = [];
      }
    }
    $job['message'] = '正在统计目录，已处理 ' . (int) $job['processed'] . ' 个文件。';
  }

  /**
   * @param array<string, mixed> $job
   *   Mutable job.
   * @param array<string, int> $stat
   *   lstat result.
   */
  private function accountFile(array &$job, string $path, array $stat): void {
    $inode = ((int) ($stat['dev'] ?? 0)) . ':' . ((int) ($stat['ino'] ?? 0));
    if (isset($job['inodes'][$inode])) {
      $job['links']++;
      return;
    }
    $job['inodes'][$inode] = 1;
    $size = (int) $stat['size'];
    $mtime = (int) $stat['mtime'];
    $ctime = (int) $stat['ctime'];
    $job['processed']++;
    $dir = dirname($path);
    $job['dir_direct'][$dir] = ($job['dir_direct'][$dir] ?? 0) + $size;
    foreach ($job['roots'] as $id => &$root) {
      if (empty($root['countable'])) {
        continue;
      }
      $rootPath = (string) $root['path'];
      if ($path === $rootPath || str_starts_with($path, $rootPath . '/')) {
        $root['bytes'] = (int) $root['bytes'] + $size;
        $root['files'] = (int) $root['files'] + 1;
        if ($root['status'] === 'pending') {
          $root['status'] = 'partial';
        }
      }
    }
    unset($root);
    $this->considerTopFile($job, $path, $size, $mtime);
    if ($size >= (int) $job['limits']['large']) {
      $this->pushCapped($job['large'], ['path' => $path, 'bytes' => $size, 'mtime' => $mtime], 'bytes', 100);
    }
    if ($size >= (int) $job['limits']['huge']) {
      $this->pushCapped($job['huge'], ['path' => $path, 'bytes' => $size, 'mtime' => $mtime], 'bytes', 100);
    }
    if (!$this->isContentFile($path, $job)) {
      if ($this->isStyleFile($path, $job)) {
        $job['styles_files']++;
        if ($this->extension($path) === 'webp') {
          $job['styles_webp']++;
        }
      }
      return;
    }
    $extension = $this->extension($path);
    $bucket = $job['ext'][$extension] ?? ['count' => 0, 'bytes' => 0];
    $bucket['count']++;
    $bucket['bytes'] += $size;
    $job['ext'][$extension] = $bucket;
    $this->pushCapped($job['recent_mtime'], ['path' => $path, 'bytes' => $size, 'time' => $mtime], 'time', 20);
    $this->pushCapped($job['recent_ctime'], ['path' => $path, 'bytes' => $size, 'time' => $ctime], 'time', 20);
    if (!empty($job['managed_ok']) && !isset($job['managed'][$path]) && !$this->ignoredContentName($path)) {
      $job['unmanaged_count']++;
      if (count($job['unmanaged']) < 80) {
        $job['unmanaged'][] = ['path' => $path, 'bytes' => $size, 'mtime' => $mtime];
      }
    }
    if (isset($job['size_groups'][$size])) {
      $job['size_groups'][$size][] = $path;
      return;
    }
    if (isset($job['size_first'][$size])) {
      $job['size_groups'][$size] = [$job['size_first'][$size], $path];
      unset($job['size_first'][$size]);
      return;
    }
    $job['size_first'][$size] = $path;
  }

  /**
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function finishWalk(array &$job): void {
    $memo = [];
    foreach ($job['roots'] as $id => &$root) {
      if (!in_array($root['status'], ['pending', 'partial'], TRUE)) {
        continue;
      }
      $path = (string) $root['path'];
      if (isset($job['dir_direct'][$path]) || isset($job['dir_children'][$path])) {
        $root['bytes'] = $this->rollup($path, $job, $memo);
      }
      $root['status'] = !empty($root['denied_children']) ? 'limited' : 'ok';
    }
    unset($root);
    $totals = [];
    foreach (array_keys($job['dir_direct']) as $dir) {
      $totals[$dir] = $this->rollup((string) $dir, $job, $memo);
    }
    arsort($totals);
    $top = [];
    foreach ($totals as $dir => $bytes) {
      $top[] = ['path' => $dir, 'bytes' => $bytes];
      if (count($top) >= (int) $job['limits']['top_dirs']) {
        break;
      }
    }
    $job['top_dirs'] = $top;
    $queue = [];
    $ready = is_array($job['duplicates']) ? $job['duplicates'] : [];
    foreach ($job['size_groups'] as $size => $paths) {
      if (!is_array($paths) || count($paths) < 2) {
        continue;
      }
      $size = (int) $size;
      if ($size === 0) {
        $this->recordEmptyGroup($paths, $ready, $job);
        continue;
      }
      foreach ($paths as $path) {
        if (is_string($path)) {
          $queue[] = ['path' => $path, 'bytes' => $size];
        }
      }
    }
    $job['duplicates'] = $ready;
    $job['hash_queue'] = $queue;
    $job['hash_total'] = count($queue);
    $job['hash_done'] = 0;
    unset($job['size_groups'], $job['size_first'], $job['dir_direct'], $job['dir_children'], $job['visited'], $job['current_dir'], $job['current_names'], $job['inodes']);
    if ($queue === []) {
      $job['phase'] = 'done';
      $job['message'] = '目录统计完成。没有需要计算哈希的同大小文件。';
      return;
    }
    $job['phase'] = 'hash';
    $job['message'] = '正在为 ' . count($queue) . ' 个同大小文件计算 SHA-256。';
  }

  /**
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function hash(array &$job, float $deadline): void {
    if (!isset($job['hash_skipped']) || !is_array($job['hash_skipped'])) {
      $job['hash_skipped'] = [];
    }
    $hashed = 0;
    while (!empty($job['hash_queue']) && microtime(TRUE) < $deadline && $hashed < 8) {
      $item = array_pop($job['hash_queue']);
      if (!is_array($item) || empty($item['path'])) {
        continue;
      }
      $path = (string) $item['path'];
      $bytes = (int) $item['bytes'];
      $hashed++;
      $job['hash_done']++;
      if ($bytes > 33554432) {
        $job['hash_skipped'][] = ['path' => $path, 'bytes' => $bytes];
        continue;
      }
      if (!$this->canRead($path)) {
        $job['failures']++;
        continue;
      }
      $hash = @hash_file('sha256', $path);
      if (!is_string($hash) || strlen($hash) !== 64) {
        $job['failures']++;
        continue;
      }
      $job['hashes'][$hash][] = ['path' => $path, 'bytes' => $bytes];
    }
    if (!empty($job['hash_queue'])) {
      $job['message'] = '正在计算 SHA-256，已完成 ' . (int) $job['hash_done'] . ' / ' . (int) $job['hash_total'] . '。';
      return;
    }
    foreach ($job['hashes'] as $hash => $items) {
      if (!is_array($items) || count($items) < 2) {
        continue;
      }
      $paths = [];
      foreach ($items as $item) {
        if (isset($item['path'])) {
          $paths[] = (string) $item['path'];
        }
      }
      $job['duplicates'][] = [
        'hash' => (string) $hash,
        'bytes' => (int) ($items[0]['bytes'] ?? 0),
        'count' => count($paths),
        'paths' => array_slice($paths, 0, 20),
      ];
    }
    unset($job['hashes'], $job['hash_queue']);
    $job['phase'] = 'done';
    $job['message'] = '扫描完成。';
  }

  /**
   * @param list<string> $paths
   *   Empty files of the same size.
   * @param list<array<string, mixed>> $ready
   *   Duplicate groups already confirmed.
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function recordEmptyGroup(array $paths, array &$ready, array &$job): void {
    $hash = NULL;
    foreach ($paths as $path) {
      if (is_string($path) && $this->canRead($path)) {
        $hash = @hash_file('sha256', $path);
        break;
      }
    }
    if (!is_string($hash) || strlen($hash) !== 64) {
      $job['failures']++;
      return;
    }
    $clean = array_values(array_filter($paths, 'is_string'));
    $ready[] = [
      'hash' => $hash,
      'bytes' => 0,
      'count' => count($clean),
      'paths' => array_slice($clean, 0, 20),
    ];
  }

  /**
   * @param array<string, mixed> $job
   *   Completed job.
   */
  private function complete(array &$job): void {
    $job['status'] = 'done';
    $job['finished'] = $this->time->getCurrentTime();
    $this->applyWallClock($job);
    $job['message'] = '扫描完成。';
    $report = [
      'finished' => $job['finished'],
      'finished_label' => $this->formatTime((int) $job['finished']),
      'elapsed' => OpsFormat::elapsedMs((int) $job['elapsed_ms']),
      'processed' => (int) $job['processed'],
      'dirs' => (int) $job['dirs'],
      'failures' => (int) $job['failures'],
      'denied' => (int) $job['denied'],
      'links' => (int) $job['links'],
      'managed_ok' => !empty($job['managed_ok']),
      'roots' => [],
      'top_files' => $this->decorateFiles($job['top_files']),
      'top_dirs' => $this->decorateFiles($job['top_dirs']),
      'large' => $this->decorateFiles($job['large']),
      'huge' => $this->decorateFiles($job['huge']),
      'recent_mtime' => $this->decorateTimes($job['recent_mtime']),
      'recent_ctime' => $this->decorateTimes($job['recent_ctime']),
      'extensions' => $this->extensionTable($job['ext']),
      'groups' => $this->extensionGroups($job['ext']),
      'styles_files' => (int) $job['styles_files'],
      'styles_webp' => (int) $job['styles_webp'],
      'unmanaged' => $this->decorateFiles($job['unmanaged']),
      'unmanaged_count' => (int) $job['unmanaged_count'],
      'hash_skipped' => $this->decorateFiles($job['hash_skipped'] ?? []),
      'duplicates' => $this->decorateDuplicates($job['duplicates']),
    ];
    foreach ($job['roots'] as $id => $root) {
      $report['roots'][] = $this->presentRoot((string) $id, (string) $root['path'], $root);
    }
    $this->store->set('report', $report);
    unset($job['managed'], $job['hashes'], $job['hash_queue'], $job['size_groups'], $job['size_first']);
  }

  /**
   * @return array<string, mixed>
   *   A new idle job.
   */
  private function freshJob(): array {
    $config = $this->configFactory->get('xedc_core.settings');
    $batch = (int) $config->get('file_scan_batch');
    $seconds = (int) $config->get('file_scan_seconds');
    $large = (int) $config->get('file_large_bytes');
    $huge = (int) $config->get('file_huge_bytes');
    $topFiles = (int) $config->get('file_top_files');
    $topDirs = (int) $config->get('file_top_dirs');
    $roots = [];
    foreach (self::ROOTS as $id => $path) {
      $roots[$id] = $this->inspectRoot($path);
      $roots[$id]['path'] = $path;
    }
    $content = [];
    $generated = [];
    foreach (self::CONTENT as $id) {
      if (($roots[$id]['status'] ?? '') === 'denied') {
        continue;
      }
      if (is_dir($path = self::ROOTS[$id])) {
        $real = realpath($path);
        if (is_string($real)) {
          $content[] = $real;
        }
      }
    }
    $files = realpath(self::ROOTS['files']);
    if (is_string($files)) {
      foreach (['styles', 'css', 'js', 'php'] as $directory) {
        $generated[] = $files . '/' . $directory;
      }
    }
    $walk = [];
    foreach (self::WALK as $id) {
      if (($roots[$id]['status'] ?? '') === 'pending' && is_string($roots[$id]['real'] ?? NULL)) {
        $walk[] = $roots[$id]['real'];
      }
    }
    return [
      'v' => 1,
      'status' => 'idle',
      'phase' => 'walk',
      'queue' => $walk,
      'current_dir' => '',
      'current_names' => [],
      'visited' => [],
      'processed' => 0,
      'dirs' => 0,
      'failures' => 0,
      'denied' => 0,
      'links' => 0,
      'started' => 0,
      'updated' => 0,
      'finished' => 0,
      'elapsed_ms' => 0,
      'message' => '',
      'roots' => $roots,
      'walk_roots' => $walk,
      'content_roots' => $content,
      'generated' => $generated,
      'top_files' => [],
      'top_dirs' => [],
      'large' => [],
      'huge' => [],
      'recent_mtime' => [],
      'recent_ctime' => [],
      'ext' => [],
      'styles_files' => 0,
      'styles_webp' => 0,
      'unmanaged' => [],
      'unmanaged_count' => 0,
      'managed' => [],
      'managed_ok' => FALSE,
      'size_first' => [],
      'size_groups' => [],
      'hash_queue' => [],
      'hash_done' => 0,
      'hash_total' => 0,
      'hashes' => [],
      'hash_skipped' => [],
      'duplicates' => [],
      'dir_direct' => [],
      'dir_children' => [],
      'inodes' => [],
      'limits' => [
        'batch' => $batch >= 50 && $batch <= 500 ? $batch : 250,
        'seconds' => $seconds >= 3 && $seconds <= 8 ? $seconds : 6,
        'large' => $large > 0 ? $large : 104857600,
        'huge' => $huge > 0 ? $huge : 524288000,
        'top_files' => $topFiles >= 10 && $topFiles <= 100 ? $topFiles : 100,
        'top_dirs' => $topDirs >= 10 && $topDirs <= 50 ? $topDirs : 50,
      ],
    ];
  }

  /**
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function prepareQueue(array &$job): void {
    foreach ($job['roots'] as &$root) {
      if ($root['status'] === 'pending') {
        $root['countable'] = TRUE;
      }
    }
    unset($root);
  }

  /**
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function loadManaged(array &$job): void {
    try {
      $uris = $this->database->query('SELECT uri FROM {file_managed}')->fetchCol();
    }
    catch (\Throwable $exception) {
      $job['managed_ok'] = FALSE;
      $job['message'] = '文件实体列表读取失败，完成后不会做孤立文件比对。';
      $this->logger->error('Managed file list failed: @message', ['@message' => $exception->getMessage()]);
      return;
    }
    $set = [];
    foreach ($uris as $uri) {
      if (!is_string($uri)) {
        continue;
      }
      $real = $this->fileSystem->realpath($uri);
      if (is_string($real) && $real !== '') {
        $set[$real] = 1;
      }
    }
    $job['managed'] = $set;
    $job['managed_ok'] = TRUE;
  }

  /**
   * @return array<string, mixed>
   *   Stored job or a blank idle job.
   */
  private function loadJob(): array {
    $job = $this->store->get('job');
    if (!is_array($job) || (int) ($job['v'] ?? 0) !== 1) {
      $blank = $this->freshJob();
      $blank['status'] = 'idle';
      $blank['phase'] = 'idle';
      $blank['queue'] = [];
      foreach ($blank['roots'] as &$root) {
        if (($root['status'] ?? '') === 'pending') {
          $root['status'] = 'idle';
          $root['bytes'] = NULL;
        }
      }
      unset($root);
      return $blank;
    }
    return $job;
  }

  /**
   * @param array<string, mixed> $job
   *   Job to persist.
   */
  private function saveJob(array $job): void {
    $this->store->set('job', $job);
  }

  private function ensureQueued(): void {
    $queue = $this->queueFactory->get('xedc_file_scan');
    if ($queue->numberOfItems() < 1) {
      $queue->createItem(['v' => 1]);
    }
  }

  /**
   * @return array<string, mixed>
   *   Root state before any walk.
   */
  private function inspectRoot(string $path): array {
    $base = [
      'path' => $path,
      'real' => NULL,
      'status' => 'pending',
      'bytes' => 0,
      'files' => 0,
      'dirs' => 0,
      'countable' => FALSE,
    ];
    if (!file_exists($path)) {
      $base['status'] = 'missing';
      $base['bytes'] = NULL;
      return $base;
    }
    if (!$this->canList($path)) {
      $base['status'] = 'denied';
      $base['bytes'] = NULL;
      return $base;
    }
    $real = realpath($path);
    $base['real'] = is_string($real) ? $real : $path;
    $base['path'] = $base['real'];
    return $base;
  }

  /**
   * @param array<string, mixed> $root
   *   Stored root.
   *
   * @return array<string, string>
   *   Display row.
   */
  private function presentRoot(string $id, string $fallback, array $root): array {
    $status = (string) ($root['status'] ?? 'idle');
    $bytes = $root['bytes'] ?? NULL;
    $labels = [
      'idle' => '未扫描',
      'pending' => '等待扫描',
      'partial' => '扫描中',
      'ok' => '已完成',
      'limited' => '部分无权限',
      'denied' => '权限不足',
      'missing' => '目录不存在',
    ];
    return [
      'id' => $id,
      'path' => (string) ($root['path'] ?? $fallback),
      'status' => $status,
      'status_label' => $labels[$status] ?? $status,
      'bytes' => is_numeric($bytes) && in_array($status, ['partial', 'ok', 'limited'], TRUE) ? OpsFormat::bytes((float) $bytes) : '暂无数据',
      'files' => is_numeric($root['files'] ?? NULL) && in_array($status, ['partial', 'ok', 'limited'], TRUE) ? (string) (int) $root['files'] : '暂无数据',
      'dirs' => is_numeric($root['dirs'] ?? NULL) && in_array($status, ['partial', 'ok', 'limited'], TRUE) ? (string) (int) $root['dirs'] : '暂无数据',
    ];
  }

  /**
   * @param array<string, mixed> $job
   *   Job.
   */
  /**
   * Uses the clock, not the frozen request time, so a long CLI scan records its real duration.
   *
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function applyWallClock(array &$job): void {
    $end = (int) ($job['finished'] ?? 0);
    if ($end <= 0) {
      $end = (int) ($job['updated'] ?? 0);
    }
    $start = (int) ($job['started'] ?? 0);
    if ($start <= 0 || $end < $start) {
      return;
    }
    $wall = ($end - $start) * 1000;
    if ($wall > (int) $job['elapsed_ms']) {
      $job['elapsed_ms'] = $wall;
    }
  }

  /**
   * @param array<string, mixed> $job
   *   Mutable job.
   */
  private function markDenied(array &$job, string $path): void {
    foreach ($job['roots'] as &$root) {
      if (empty($root['countable'])) {
        continue;
      }
      $rootPath = (string) ($root['path'] ?? '');
      if ($rootPath !== '' && ($path === $rootPath || str_starts_with($path, $rootPath . '/'))) {
        $root['denied_children'] = (int) ($root['denied_children'] ?? 0) + 1;
      }
    }
    unset($root);
  }

  /**
   * @param array<string, mixed> $job
   *   Job.
   */
  private function addDirectoryInode(array &$job, string $path): void {
    if (!isset($job['dir_direct'][$path])) {
      $job['dir_direct'][$path] = 0;
    }
    foreach ($job['roots'] as &$root) {
      if (empty($root['countable'])) {
        continue;
      }
      $rootPath = (string) $root['path'];
      if ($path !== $rootPath && str_starts_with($path, $rootPath . '/')) {
        $root['dirs'] = (int) $root['dirs'] + 1;
        if ($root['status'] === 'pending') {
          $root['status'] = 'partial';
        }
      }
    }
    unset($root);
  }

  /**
   * @param array<string, mixed> $job
   *   Job.
   * @param array<string, int> $memo
   *   Rollup cache.
   */
  private function rollup(string $dir, array &$job, array &$memo): int {
    if (isset($memo[$dir])) {
      return $memo[$dir];
    }
    $sum = (int) ($job['dir_direct'][$dir] ?? 0);
    foreach ($job['dir_children'][$dir] ?? [] as $child) {
      if (is_string($child)) {
        $sum += $this->rollup($child, $job, $memo);
      }
    }
    $memo[$dir] = $sum;
    return $sum;
  }

  /**
   * @param array<string, mixed> $job
   *   Job.
   */
  private function considerTopFile(array &$job, string $path, int $size, int $mtime): void {
    $limit = (int) $job['limits']['top_files'];
    $last = array_key_last($job['top_files']);
    if (count($job['top_files']) >= $limit && $last !== NULL && $size <= (int) $job['top_files'][$last]['bytes']) {
      return;
    }
    $job['top_files'][] = ['path' => $path, 'bytes' => $size, 'mtime' => $mtime];
    usort($job['top_files'], static fn(array $a, array $b): int => ($b['bytes'] <=> $a['bytes']));
    if (count($job['top_files']) > $limit) {
      $job['top_files'] = array_slice($job['top_files'], 0, $limit);
    }
  }

  /**
   * @param list<array<string, int|string>> $list
   *   Capped list.
   * @param array<string, int|string> $item
   *   New row.
   */
  private function pushCapped(array &$list, array $item, string $key, int $limit): void {
    $last = array_key_last($list);
    $candidate = $item[$key] ?? 0;
    if (count($list) >= $limit && $last !== NULL && $candidate <= ($list[$last][$key] ?? 0)) {
      return;
    }
    $list[] = $item;
    usort($list, static fn(array $a, array $b): int => (($b[$key] ?? 0) <=> ($a[$key] ?? 0)));
    if (count($list) > $limit) {
      $list = array_slice($list, 0, $limit);
    }
  }

  /**
   * @param array<string, mixed> $job
   *   Job.
   */
  private function isContentFile(string $path, array $job): bool {
    if ($this->isStyleFile($path, $job) || $this->underPrefixes($path, $job['generated'] ?? [])) {
      return FALSE;
    }
    return $this->underPrefixes($path, $job['content_roots'] ?? []);
  }

  /**
   * @param array<string, mixed> $job
   *   Job.
   */
  private function isStyleFile(string $path, array $job): bool {
    foreach ($job['generated'] ?? [] as $prefix) {
      if (is_string($prefix) && str_ends_with($prefix, '/styles') && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param list<string> $prefixes
   *   Absolute prefixes.
   */
  private function underPrefixes(string $path, array $prefixes): bool {
    foreach ($prefixes as $prefix) {
      if (is_string($prefix) && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $job
   *   Job.
   */
  private function insideWalk(string $path, array $job): bool {
    return $this->underPrefixes($path, $job['walk_roots'] ?? []);
  }

  private function ignoredContentName(string $path): bool {
    $name = basename($path);
    return in_array($name, ['.htaccess', 'web.config', '.htaccess.old'], TRUE);
  }

  private function extension(string $path): string {
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === '' || strlen($extension) > 12 || !preg_match('/^[a-z0-9]+$/', $extension)) {
      return '无扩展名';
    }
    return $extension;
  }

  /**
   * @param array<string, array{count: int, bytes: int}> $ext
   *   Extension totals.
   *
   * @return list<array<string, string>>
   *   Rows for the type table.
   */
  private function extensionTable(array $ext): array {
    $rows = [];
    foreach ($ext as $name => $info) {
      $count = (int) ($info['count'] ?? 0);
      $bytes = (int) ($info['bytes'] ?? 0);
      $rows[] = [
        'ext' => (string) $name,
        'count' => (string) $count,
        'bytes' => OpsFormat::bytes($bytes),
        'average' => $count > 0 ? OpsFormat::bytes((int) round($bytes / $count)) : '暂无数据',
        'raw_bytes' => $bytes,
      ];
    }
    usort($rows, static fn(array $a, array $b): int => ($b['raw_bytes'] <=> $a['raw_bytes']));
    $rows = array_slice($rows, 0, 30);
    foreach ($rows as &$row) {
      unset($row['raw_bytes']);
    }
    return $rows;
  }

  /**
   * @param array<string, array{count: int, bytes: int}> $ext
   *   Extension totals.
   *
   * @return list<array<string, string>>
   *   Grouped image, video, pdf, archive and other totals.
   */
  private function extensionGroups(array $ext): array {
    $groups = [
      'image' => ['label' => '图片', 'count' => 0, 'bytes' => 0],
      'video' => ['label' => '视频', 'count' => 0, 'bytes' => 0],
      'pdf' => ['label' => 'PDF', 'count' => 0, 'bytes' => 0],
      'archive' => ['label' => '压缩包', 'count' => 0, 'bytes' => 0],
      'other' => ['label' => '其他', 'count' => 0, 'bytes' => 0],
    ];
    foreach ($ext as $name => $info) {
      $key = 'other';
      if (in_array($name, self::IMAGE, TRUE)) {
        $key = 'image';
      }
      elseif (in_array($name, self::VIDEO, TRUE)) {
        $key = 'video';
      }
      elseif (in_array($name, self::PDF, TRUE)) {
        $key = 'pdf';
      }
      elseif (in_array($name, self::ARCHIVE, TRUE)) {
        $key = 'archive';
      }
      $groups[$key]['count'] += (int) ($info['count'] ?? 0);
      $groups[$key]['bytes'] += (int) ($info['bytes'] ?? 0);
    }
    $rows = [];
    foreach ($groups as $group) {
      $count = $group['count'];
      $rows[] = [
        'label' => $group['label'],
        'count' => (string) $count,
        'bytes' => OpsFormat::bytes($group['bytes']),
        'average' => $count > 0 ? OpsFormat::bytes((int) round($group['bytes'] / $count)) : '暂无数据',
      ];
    }
    return $rows;
  }

  /**
   * @param mixed $rows
   *   File rows.
   *
   * @return list<array<string, string>>
   *   Display rows.
   */
  private function decorateFiles(mixed $rows): array {
    if (!is_array($rows)) {
      return [];
    }
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row) || empty($row['path'])) {
        continue;
      }
      $out[] = [
        'path' => (string) $row['path'],
        'bytes' => OpsFormat::bytes((int) ($row['bytes'] ?? 0)),
        'time' => $this->formatTime((int) ($row['mtime'] ?? $row['time'] ?? 0)),
      ];
    }
    return $out;
  }

  /**
   * @param mixed $rows
   *   Time rows.
   *
   * @return list<array<string, string>>
   *   Display rows.
   */
  private function decorateTimes(mixed $rows): array {
    return $this->decorateFiles($rows);
  }

  /**
   * @param mixed $groups
   *   Duplicate groups.
   *
   * @return list<array<string, mixed>>
   *   Display groups.
   */
  private function decorateDuplicates(mixed $groups): array {
    if (!is_array($groups)) {
      return [];
    }
    $out = [];
    foreach ($groups as $group) {
      if (!is_array($group) || empty($group['hash']) || empty($group['paths'])) {
        continue;
      }
      $paths = [];
      foreach ($group['paths'] as $path) {
        if (is_string($path)) {
          $paths[] = $path;
        }
      }
      if (count($paths) < 2 && (int) ($group['count'] ?? 0) < 2) {
        continue;
      }
      $out[] = [
        'hash' => (string) $group['hash'],
        'bytes' => OpsFormat::bytes((int) ($group['bytes'] ?? 0)),
        'count' => (string) (int) ($group['count'] ?? count($paths)),
        'paths' => $paths,
      ];
    }
    return $out;
  }

  private function canList(string $path): bool {
    $stat = @lstat($path);
    if (!is_array($stat) || $this->webUid === NULL || $this->webGid === NULL) {
      return FALSE;
    }
    if (($stat['mode'] & 0170000) === 0120000) {
      return FALSE;
    }
    return $this->allows($stat, 5);
  }

  private function canRead(string $path): bool {
    $stat = @lstat($path);
    return is_array($stat) && $this->webUid !== NULL && $this->allows($stat, 4);
  }

  /**
   * @param array<string, int> $stat
   *   lstat result.
   */
  private function allows(array $stat, int $need): bool {
    $mode = $stat['mode'] & 0777;
    if ((int) $stat['uid'] === $this->webUid) {
      $bits = ($mode & 0700) >> 6;
    }
    elseif ((int) $stat['gid'] === $this->webGid) {
      $bits = ($mode & 0070) >> 3;
    }
    else {
      $bits = $mode & 0007;
    }
    return ($bits & $need) === $need;
  }

  private function resolveWebUser(): void {
    if (!function_exists('posix_getpwnam')) {
      return;
    }
    $info = posix_getpwnam('www-data');
    if (!is_array($info)) {
      return;
    }
    $this->webUid = (int) $info['uid'];
    $this->webGid = (int) $info['gid'];
  }

  private function formatTime(int $timestamp): string {
    if ($timestamp <= 0) {
      return '暂无';
    }
    return \Drupal::service('date.formatter')->format($timestamp, 'custom', 'Y-m-d H:i:s');
  }

  private function statusLabel(string $status): string {
    return match ($status) {
      'running' => '扫描中',
      'paused' => '已暂停',
      'done' => '已完成',
      'error' => '失败',
      default => '尚未扫描',
    };
  }

  private function phaseLabel(string $phase): string {
    return match ($phase) {
      'walk' => '目录统计',
      'hash' => 'SHA-256',
      'done' => '完成',
      default => '空闲',
    };
  }

}
