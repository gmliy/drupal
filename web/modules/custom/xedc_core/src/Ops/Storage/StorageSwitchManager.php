<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\PublicStream;

/**
 * Copies missing public originals and then updates the shared runtime file.
 *
 * Object keys use the configured public folder. The check reads local files
 * from the public disk directory, not through the public stream wrapper.
 * It does not delete sources, R2 objects, private files, or image styles.
 */
final class StorageSwitchManager {

  public const CHECK_TTL = 900;

  private const LEGACY_PUBLIC_FOLDER = 's3fs-public';

  public function __construct(
    private StorageConfig $config,
    private CredentialVault $credentials,
    private RemoteFileReader $reader,
    private RemoteFileWriter $writer,
    private StorageJournal $journal,
    private Connection $database,
    private StateInterface $state,
    private StorageUriResolver $uris,
  ) {}

  /**
   * @return array<string, mixed>
   *   Read-only consistency report for one target. This does not copy or switch.
   */
  public function inspect(string $target): array {
    $report = $this->collect($target);
    unset($report['copy_plan']);
    if (empty($report['allowed']) || !is_string($report['digest'] ?? NULL) || $report['digest'] === '') {
      $this->clearCheck();
      $report['token'] = '';
      $report['expires'] = 0;
      return $report;
    }
    $token = bin2hex(random_bytes(16));
    $expires = time() + self::CHECK_TTL;
    $this->state->set('xedc_storage.switch_check', [
      'token' => $token,
      'target' => $target,
      'digest' => $report['digest'],
      'expires' => $expires,
    ]);
    $report['token'] = $token;
    $report['expires'] = $expires;
    return $report;
  }

  public function clearCheck(): void {
    $this->state->delete('xedc_storage.switch_check');
  }

  /**
   * @return array<string, mixed>
   *   Switch result. Production mode changes only after the confirmation phrase,
   *   a fresh matching check, and a successful copy.
   */
  public function execute(string $target, string $phrase, string $token, int $uid): array {
    if ($phrase !== RuntimeState::CONFIRMATION) {
      return $this->fail('confirmation', '确认文字不正确，没有切换。');
    }
    if (!in_array($target, ['local', 'silly'], TRUE)) {
      return $this->fail('target', '只能在本地存储和 Cloudflare R2 之间切换。');
    }
    $saved = $this->state->get('xedc_storage.switch_check', []);
    $saved = is_array($saved) ? $saved : [];
    $gate = self::tokenAccepts($saved, $token, $target, '', time(), FALSE);
    if ($gate !== '') {
      return $this->fail('check', $gate);
    }
    $private = $this->privatePath();
    if (!RuntimeState::acquire($private)) {
      return $this->fail('lock', '已有切换正在执行。');
    }
    $before = RuntimeState::read($private);
    $task = 'sw_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(3));
    try {
      $fresh = $this->collect($target);
      $decision = self::tokenAccepts($saved, $token, $target, (string) ($fresh['digest'] ?? ''), time(), TRUE);
      if ($decision !== '') {
        $this->clearCheck();
        return $this->fail('check', $decision);
      }
      if (empty($fresh['allowed'])) {
        $this->clearCheck();
        return $this->fail('blocked', (string) ($fresh['block_reason'] ?: '检查没有通过，没有切换。'));
      }
      $targetMode = $target === 'local' ? 'local' : 'cloudflare_r2';
      if ((string) $before['public_mode'] === $targetMode && ($targetMode === 'local' || (string) $before['public_provider'] === 'silly')) {
        $this->clearCheck();
        return $this->fail('same', '目标就是当前运行模式，没有重复切换。');
      }
      $paused = $before;
      $paused['writes_paused'] = TRUE;
      $paused['switch_task'] = $task;
      $paused['status'] = 'switching';
      $paused['updated'] = time();
      if (!RuntimeState::write($private, $paused)) {
        return $this->fail('state', '没能暂停写入，没有切换。');
      }
      $copied = $this->copyPlan(is_array($fresh['copy_plan'] ?? NULL) ? $fresh['copy_plan'] : [], (string) $fresh['prefix']);
      if ($copied['failed'] !== []) {
        $this->restore($private, $before, $task, '复制失败，已恢复原运行状态。源文件未删除。');
        return $this->fail('copy', '有文件没有复制成功，已回滚运行状态。失败 ' . count($copied['failed']) . ' 个。源文件仍保留。', $task);
      }
      $verified = $this->verifyCopied($copied['copied']);
      if ($verified !== []) {
        $this->restore($private, $before, $task, '校验失败，已恢复原运行状态。源文件未删除。');
        return $this->fail('hash', 'SHA-256 不一致，已回滚运行状态。源文件未删除。', $task);
      }
      $next = $before;
      $next['version'] = (int) $before['version'] + 1;
      $next['public_provider'] = $target === 'local' ? 'local' : 'silly';
      $next['public_mode'] = $targetMode;
      $next['cdn_enabled'] = $targetMode === 'cloudflare_r2';
      $next['writes_paused'] = FALSE;
      $next['switch_task'] = '';
      $next['previous_provider'] = (string) $before['public_provider'];
      $next['previous_mode'] = (string) $before['public_mode'];
      $next['updated'] = time();
      $next['status'] = 'active';
      $next['summary'] = '公开存储已从 ' . (string) $before['public_mode'] . ' 切到 ' . $targetMode . '。源文件未删除。';
      if (!RuntimeState::write($private, $next)) {
        $this->restore($private, $before, $task, '新状态没有写入，已恢复。');
        return $this->fail('state', '新状态没有写入，已回滚。', $task);
      }
      $active = RuntimeState::usesObjectStorage();
      if (($targetMode === 'cloudflare_r2') !== $active) {
        $this->restore($private, $before, $task, '切换后读写目标与状态不一致，已恢复。');
        return $this->fail('runtime', '运行状态和实际读写不一致，已回滚。', $task);
      }
      $this->record($uid, $before, $next, $task, 'switched', count($copied['copied']), '');
      $this->clearCheck();
      return [
        'ok' => TRUE,
        'status' => 'switched',
        'task' => $task,
        'copied' => count($copied['copied']),
        'message' => $next['summary'],
      ];
    }
    finally {
      $current = RuntimeState::read($private);
      if (!empty($current['writes_paused'])) {
        $current['writes_paused'] = FALSE;
        $current['switch_task'] = '';
        $current['updated'] = time();
        RuntimeState::write($private, $current);
      }
      RuntimeState::release();
    }
  }

  /**
   * @param array<string, mixed> $saved
   *   Stored check. It contains no credentials.
   */
  public static function tokenAccepts(array $saved, string $token, string $target, string $digest, int $now, bool $requireDigest): string {
    if ($token === '' || !isset($saved['token']) || !is_string($saved['token']) || $saved['token'] === '' || !hash_equals($saved['token'], $token)) {
      return '没有可用的检查令牌。请重新检查。没有切换。';
    }
    if ((string) ($saved['target'] ?? '') !== $target) {
      return '检查结果属于另一个目标。请重新检查。没有切换。';
    }
    if ((int) ($saved['expires'] ?? 0) < $now) {
      return '检查结果已过期。请重新检查。没有切换。';
    }
    if ($requireDigest && (!is_string($saved['digest'] ?? NULL) || $saved['digest'] === '' || !hash_equals((string) $saved['digest'], $digest))) {
      return '文件状态在检查之后发生了变化。请重新检查。没有切换。';
    }
    return '';
  }

  /**
   * Local disk path for a public relative path. Stream URIs are rejected.
   */
  public static function diskPath(string $publicRoot, string $relative): ?string {
    $relative = str_replace('\\', '/', $relative);
    if ($publicRoot === '' || !str_starts_with($publicRoot, '/') || str_contains($publicRoot, "\0")) {
      return NULL;
    }
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..') || str_contains($relative, '://') || str_starts_with($relative, '/')) {
      return NULL;
    }
    return rtrim($publicRoot, '/') . '/' . $relative;
  }

  /**
   * @return array{bytes: int|null, error: string}
   *   Size of one ordinary file, or the reason it cannot be used.
   */
  public static function readLocalFile(string $path): array {
    if (is_link($path)) {
      return ['bytes' => NULL, 'error' => '符号链接，已拒绝当作本地原件'];
    }
    if (!file_exists($path)) {
      return ['bytes' => NULL, 'error' => ''];
    }
    if (!is_file($path)) {
      return ['bytes' => NULL, 'error' => '路径存在但不是普通文件'];
    }
    if (!is_readable($path)) {
      return ['bytes' => NULL, 'error' => '文件存在但当前进程无法读取'];
    }
    $size = filesize($path);
    if ($size === FALSE) {
      return ['bytes' => NULL, 'error' => '无法读取文件大小'];
    }
    return ['bytes' => (int) $size, 'error' => ''];
  }

  public static function hashesMatch(?string $left, ?string $right): bool {
    return is_string($left) && is_string($right) && $left !== '' && hash_equals($left, $right);
  }

  public static function prepareLocalDestination(string $path): bool {
    if ($path === '' || str_contains($path, "\0")) {
      return FALSE;
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      return FALSE;
    }
    return is_dir($directory);
  }

  /**
   * @param array<string, mixed> $input
   *   Facts already collected. This method does not touch R2 or the runtime file.
   *
   * @return array<string, mixed>
   *   Checklist and copy plan.
   */
  public static function evaluate(array $input): array {
    $target = (string) ($input['target'] ?? '');
    $folder = trim((string) ($input['public_folder'] ?? ''), '/');
    $privateFolder = trim((string) ($input['private_folder'] ?? ''), '/');
    $currentMode = (string) ($input['current_mode'] ?? '');
    $currentProvider = (string) ($input['current_provider'] ?? '');
    $files = is_array($input['files'] ?? NULL) ? $input['files'] : [];
    $targetMode = $target === 'local' ? 'local' : ($target === 'silly' ? 'cloudflare_r2' : '');
    $problems = [];
    $plan = [];
    $required = 0;
    $missingTarget = 0;
    $missingBoth = 0;
    $sizeMismatch = 0;
    $unreadable = 0;
    $doublePrefix = 0;
    $both = 0;
    foreach ($files as $file) {
      if (!is_array($file)) {
        continue;
      }
      $required++;
      $uri = (string) ($file['uri'] ?? '');
      $relative = str_replace('\\', '/', (string) ($file['relative'] ?? ''));
      $localError = (string) ($file['local_error'] ?? '');
      $localBytes = isset($file['local_bytes']) && is_int($file['local_bytes']) ? $file['local_bytes'] : NULL;
      $activeBytes = isset($file['active_bytes']) && is_int($file['active_bytes']) ? $file['active_bytes'] : NULL;
      $legacyBytes = isset($file['legacy_bytes']) && is_int($file['legacy_bytes']) ? $file['legacy_bytes'] : NULL;
      $unsafe = $relative === '' || str_contains($relative, '..') || str_contains($relative, '://') || str_starts_with($relative, '/');
      $doubled = $folder !== '' && str_starts_with($relative, $folder . '/');
      $style = str_starts_with($relative, 'styles/');
      if ($unsafe || $doubled || $style || $folder === '') {
        $doublePrefix += $doubled ? 1 : 0;
        $problems[] = ['uri' => $uri, 'reason' => $doubled ? '对象键会产生 ' . $folder . '/' . $folder . '/ 双重前缀' : '相对路径不能映射到安全对象键'];
        continue;
      }
      if ($localError !== '') {
        $unreadable++;
        $problems[] = ['uri' => $uri, 'reason' => $localError];
      }
      if (empty($input['remote_known'])) {
        continue;
      }
      $onLocal = $localBytes !== NULL && $localError === '';
      $onActive = $activeBytes !== NULL;
      $onLegacy = $legacyBytes !== NULL;
      if (!$onLocal && !$onActive && !$onLegacy) {
        $missingBoth++;
        $missingTarget++;
        $problems[] = ['uri' => $uri, 'reason' => '本地磁盘、当前前缀和旧前缀都没有这个文件'];
        continue;
      }
      if ($onLocal && $onActive && $localBytes !== $activeBytes) {
        $sizeMismatch++;
        $problems[] = ['uri' => $uri, 'reason' => '本地 ' . StorageSizeFormatter::formatBytes($localBytes) . '（' . StorageSizeFormatter::exactLabel($localBytes) . '），当前前缀 ' . StorageSizeFormatter::formatBytes($activeBytes) . '（' . StorageSizeFormatter::exactLabel($activeBytes) . '）'];
      }
      if ($target === 'local' && !$onLocal) {
        $missingTarget++;
        if ($onActive) {
          $plan[] = ['action' => 'download', 'key' => $folder . '/' . $relative, 'source_key' => $folder . '/' . $relative, 'path' => (string) ($file['disk'] ?? ''), 'bytes' => $activeBytes];
        }
        elseif ($onLegacy) {
          $plan[] = ['action' => 'download', 'key' => $folder . '/' . $relative, 'source_key' => self::LEGACY_PUBLIC_FOLDER . '/' . $relative, 'path' => (string) ($file['disk'] ?? ''), 'bytes' => $legacyBytes];
        }
      }
      elseif ($target === 'silly' && !$onActive) {
        $missingTarget++;
        if ($onLocal) {
          $plan[] = ['action' => 'upload', 'key' => $folder . '/' . $relative, 'source_key' => '', 'path' => (string) ($file['disk'] ?? ''), 'bytes' => $localBytes];
        }
        else {
          $problems[] = ['uri' => $uri, 'reason' => '当前前缀没有对象，本地也没有可读副本。不会在 R2 前缀之间自动搬迁。'];
        }
      }
      elseif ($onLocal && $onActive && $localBytes === $activeBytes) {
        $both++;
      }
    }
    usort($problems, static fn (array $a, array $b): int => strcmp($a['uri'], $b['uri']));
    usort($plan, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));
    $remoteKnown = !empty($input['remote_known']);
    if (!$remoteKnown) {
      $plan = [];
      $missingTarget = 0;
      $missingBoth = 0;
      $sizeMismatch = 0;
    }
    $directoryOk = !empty($input['directory_exists']) && !empty($input['directory_readable']);
    $writableOk = $target === 'silly' ? !empty($input['directory_readable']) : (!empty($input['directory_writable']) && $directoryOk);
    $runtimeWritable = !empty($input['runtime_writable']);
    $permissionOk = $directoryOk && $writableOk && $runtimeWritable;
    $privateOk = !empty($input['private_wrapper_local']) && !empty($input['private_cdn_blocked']) && empty($input['private_takeover']);
    $styleOk = !empty($input['style_stays_local']);
    $architectureOk = !empty($input['architecture_ok']);
    $languageError = (string) ($input['language_error'] ?? '');
    $listError = (string) ($input['list_error'] ?? '');
    $same = $targetMode !== '' && $currentMode === $targetMode && ($targetMode === 'local' || $currentProvider === 'silly');
    $blocks = [];
    if (!in_array($target, ['local', 'silly'], TRUE)) {
      $blocks[] = '目标存储无效。';
    }
    if (!$architectureOk) {
      $blocks[] = (string) ($input['architecture_note'] ?? '当前存储架构和已确认的配置不一致。');
    }
    if ($listError !== '') {
      $blocks[] = $listError;
    }
    if (!$remoteKnown) {
      $blocks[] = '没有拿到当前前缀的对象清单，不能把对象当成不存在。';
    }
    if (!$directoryOk) {
      $blocks[] = '本地公开目录不可用。';
    }
    if ($target === 'local' && empty($input['directory_writable'])) {
      $blocks[] = '本地公开目录不能写。';
    }
    if (!$runtimeWritable) {
      $blocks[] = '运行状态目录不能写，切换后无法更新模式。';
    }
    if (!empty($input['locked'])) {
      $blocks[] = '切换锁正在被占用。';
    }
    if ($unreadable > 0) {
      $blocks[] = '有本地文件无法读取。';
    }
    if ($missingBoth > 0) {
      $blocks[] = '有文件在本地和 R2 都不存在。';
    }
    if ($sizeMismatch > 0) {
      $blocks[] = '有文件大小不一致。';
    }
    if ($doublePrefix > 0 || $folder === '' || $folder === $privateFolder) {
      $blocks[] = '对象前缀不安全。';
    }
    foreach ($problems as $problem) {
      if (str_contains($problem['reason'], '自动搬迁') || str_contains($problem['reason'], '双重前缀') || str_contains($problem['reason'], '安全对象键')) {
        $blocks[] = $problem['reason'];
        break;
      }
    }
    if (!$privateOk) {
      $blocks[] = '私有文件安全检查没有通过。';
    }
    if (!$styleOk) {
      $blocks[] = '图片样式没有保持在本地。';
    }
    if ($languageError !== '') {
      $blocks[] = '多语言引用没有核对成功：' . $languageError;
    }
    if ($same) {
      $blocks[] = '目标就是当前运行模式。';
    }
    $allowed = $blocks === [];
    $currentLabel = self::modeLabel($currentMode);
    $targetLabel = $target === 'local' ? '本地存储' : ($target === 'silly' ? 'Cloudflare R2' : '未选择');
    $directoryResult = $directoryOk ? '通过' : '失败';
    if (!$directoryOk) {
      $directoryResult .= '：' . (string) ($input['directory_note'] ?? '目录不存在或不可读');
    }
    $rows = [
      self::row('当前运行模式', $currentLabel . '（' . $currentMode . ' / ' . ($currentProvider !== '' ? $currentProvider : '未设置') . '）', 'ok'),
      self::row('目标运行模式', $targetLabel, $targetMode === '' ? 'bad' : 'ok'),
      self::row('本地目录检查', $directoryResult, $directoryOk ? 'ok' : 'bad'),
      self::row('文件数量检查', (string) $required, 'ok'),
      self::row('缺失文件', $remoteKnown ? (string) $missingTarget : '未执行', !$remoteKnown ? 'warn' : ($missingBoth > 0 ? 'bad' : 'ok')),
      self::row('文件大小检查', !$remoteKnown ? '未执行' : ($sizeMismatch === 0 ? '通过' : '失败：' . $sizeMismatch . ' 个'), !$remoteKnown ? 'warn' : ($sizeMismatch === 0 ? 'ok' : 'bad')),
      self::row('文件哈希检查', '未执行', 'warn'),
      self::row('文件读取检查', $unreadable === 0 ? '通过' : '失败：' . $unreadable . ' 个', $unreadable === 0 ? 'ok' : 'bad'),
      self::row('权限检查', $permissionOk ? '通过' : '失败', $permissionOk ? 'ok' : 'bad'),
      self::row('私有文件安全检查', $privateOk ? '通过' : '失败', $privateOk ? 'ok' : 'bad'),
      self::row('切换条件', $allowed ? '可以执行' : '禁止执行', $allowed ? 'ok' : 'bad'),
    ];
    $digestPayload = [
      'target' => $target,
      'folder' => $folder,
      'required' => $required,
      'missing_target' => $missingTarget,
      'missing_both' => $missingBoth,
      'size_mismatch' => $sizeMismatch,
      'unreadable' => $unreadable,
      'problems' => $problems,
      'plan' => $plan,
      'architecture' => !empty($input['architecture_ok']),
    ];
    $encoded = json_encode($digestPayload, JSON_UNESCAPED_SLASHES);
    $digest = is_string($encoded) ? hash('sha256', $encoded) : '';
    if ($digest === '') {
      $allowed = FALSE;
      $blocks[] = '检查摘要没有生成。';
    }
    return [
      'checked' => TRUE,
      'allowed' => $allowed,
      'block_reason' => $blocks[0] ?? '',
      'blocks' => $blocks,
      'target' => $target,
      'target_mode' => $targetMode,
      'target_label' => $targetLabel,
      'current_mode' => $currentMode,
      'current_provider' => $currentProvider,
      'current_label' => $currentLabel,
      'rows' => $rows,
      'problems' => array_slice($problems, 0, 40),
      'problem_count' => count($problems),
      'required' => $required,
      'both' => $both,
      'missing_target' => $missingTarget,
      'missing_both' => $missingBoth,
      'size_mismatch' => $sizeMismatch,
      'unreadable' => $unreadable,
      'copy_count' => count($plan),
      'copy_plan' => $plan,
      'pending' => array_map(static function (array $item): array {
        return [
          'action' => (string) ($item['action'] ?? ''),
          'key' => (string) ($item['key'] ?? ''),
          'source_key' => (string) ($item['source_key'] ?? ''),
          'bytes' => (int) ($item['bytes'] ?? 0),
          'bytes_label' => StorageSizeFormatter::formatBytes((int) ($item['bytes'] ?? 0)),
        ];
      }, $plan),
      'digest' => $digest,
      'hash' => 'not_run',
      'prefix' => $folder,
      'legacy_prefix' => (string) ($input['legacy_prefix'] ?? ''),
      'legacy_objects' => (int) ($input['legacy_objects'] ?? 0),
      'active_objects' => (int) ($input['active_objects'] ?? 0),
      'language_note' => (string) ($input['language_note'] ?? ''),
      'private_note' => (string) ($input['private_note'] ?? ''),
      'directory' => (string) ($input['directory'] ?? ''),
      'process_user' => (string) ($input['process_user'] ?? ''),
      'token' => '',
      'expires' => 0,
    ];
  }

  /**
   * @return array<string, mixed>
   *   Result for the dedicated test prefix only.
   */
  public function exerciseTestPrefix(): array {
    $folder = $this->publicFolderName();
    $name = 'switch-' . bin2hex(random_bytes(4)) . '.txt';
    $relative = BootGuard::TEST_PREFIX . 'switch/' . $name;
    $key = $folder . '/' . $relative;
    if (str_starts_with($relative, $folder . '/')) {
      return $this->fail('prefix', '测试键产生了双重前缀，没有写入。');
    }
    $body = 'xedc-switch-test-' . $name;
    $public = $this->publicRoot();
    $local = self::diskPath($public, $relative);
    if ($local === NULL || !self::prepareLocalDestination($local)) {
      return $this->fail('local', '测试目录没有创建。');
    }
    file_put_contents($local, $body);
    $connection = $this->connection();
    if ($connection === NULL) {
      @unlink($local);
      return $this->fail('credentials', 'R2 凭证不可用，没有写入正式前缀。');
    }
    $uploaded = $this->writer->putFile($connection, $local, $key, ['purpose' => 'test', 'mime' => 'text/plain']);
    $remoteHash = $this->reader->hash($connection, $key);
    $localHash = hash_file('sha256', $local);
    $temp = $this->reader->copyToTemp($connection, $key);
    $tempHash = is_file($temp) ? hash_file('sha256', $temp) : '';
    @unlink($temp);
    $deleted = $this->writer->delete($connection, $key, 'test');
    @unlink($local);
    $ok = ($uploaded['ok'] ?? FALSE) && is_string($remoteHash) && is_string($localHash) && hash_equals($localHash, $remoteHash) && $tempHash === $localHash && $deleted;
    return [
      'ok' => $ok,
      'status' => $ok ? 'verified' : 'failed',
      'message' => $ok ? '测试前缀完成了本地与 R2 的复制和 SHA-256 校验，并已删除测试对象。' : '测试前缀校验没有通过。正式对象没有删除。',
      'key' => $key,
    ];
  }

  /**
   * @return array<string, mixed>
   *   Collected facts and the evaluated checklist.
   */
  private function collect(string $target): array {
    $runtime = RuntimeState::read($this->privatePath());
    $folders = $this->uris->folders();
    $folder = trim((string) ($folders['public'] ?? ''), '/');
    $privateFolder = trim((string) ($folders['private'] ?? ''), '/');
    $legacy = $folder === self::LEGACY_PUBLIC_FOLDER ? '' : self::LEGACY_PUBLIC_FOLDER;
    $root = $this->publicRoot();
    $directoryExists = is_dir($root) && !is_link($root);
    $directoryReadable = $directoryExists && is_readable($root);
    $directoryWritable = $directoryExists && is_writable($root);
    $runtimeDirectory = $this->runtimeDirectory();
    $runtimeWritable = $runtimeDirectory !== NULL && is_dir($runtimeDirectory) && is_writable($runtimeDirectory);
    $listError = '';
    $remoteKnown = FALSE;
    $active = [];
    $legacyObjects = [];
    try {
      $active = $this->listPrefix($folder . '/');
      $legacyObjects = $legacy === '' ? [] : $this->listPrefix($legacy . '/');
      $remoteKnown = TRUE;
    }
    catch (\Throwable $error) {
      $listError = '列出 R2 对象失败：' . BootGuard::safeError($error);
      $active = [];
      $legacyObjects = [];
    }
    $rows = $this->database->query("SELECT fid, uri FROM {file_managed} WHERE uri LIKE :public AND uri NOT LIKE :styles AND uri NOT LIKE :styleFile", [
      ':public' => 'public://%',
      ':styles' => 'public://styles/%',
      ':styleFile' => 'public://styles',
    ])->fetchAll();
    $files = [];
    foreach ($rows as $row) {
        $uri = (string) $row->uri;
        $relative = substr($uri, strlen('public://'));
        $relative = str_replace('\\', '/', $relative);
        $disk = self::diskPath($root, $relative);
        $local = $disk === NULL ? ['bytes' => NULL, 'error' => '不能把 Drupal URI 当作磁盘路径'] : self::readLocalFile($disk);
        $activeKey = $folder !== '' ? $folder . '/' . ltrim($relative, '/') : '';
        $legacyKey = $legacy !== '' ? $legacy . '/' . ltrim($relative, '/') : '';
        $files[] = [
          'fid' => (int) $row->fid,
          'uri' => $uri,
          'relative' => $relative,
          'disk' => $disk ?? '',
          'local_bytes' => $local['bytes'],
          'local_error' => $local['error'],
          'active_bytes' => $activeKey !== '' && isset($active[$activeKey]) ? $active[$activeKey] : NULL,
          'legacy_bytes' => $legacyKey !== '' && isset($legacyObjects[$legacyKey]) ? $legacyObjects[$legacyKey] : NULL,
        ];
    }
    $language = $this->languageNote();
    $private = $this->privateNote();
    $profile = $this->config->profile('silly');
    $cdn = rtrim((string) ($runtime['cdn_base'] ?: 'https://cdn.gitxx.xyz'), '/');
    $architectureOk = is_array($profile)
      && (string) ($profile['provider'] ?? '') === 'cloudflare_r2'
      && (string) ($profile['id'] ?? 'silly') === 'silly'
      && (string) ($profile['bucket'] ?? '') === 'dclili'
      && (string) ($profile['region'] ?? '') === 'auto'
      && $folder === 'public'
      && $privateFolder === 'private'
      && $cdn === 'https://cdn.gitxx.xyz'
      && $this->endpointAccepted($profile);
    $architectureNote = $architectureOk
      ? 'Cloudflare R2，配置 silly，Bucket dclili，Region auto，前缀 public/ 与 private/，CDN https://cdn.gitxx.xyz。'
      : '存储架构与当前确认值不一致，已停止。没有修改 Bucket、Endpoint 或前缀。';
    $styleUrl = PublicUrl::localStyleUrl('public://styles/thumbnail/public/example.jpg');
    $publicUrl = PublicUrl::cdnUrl('public://example.jpg', $cdn, $folder !== '' ? $folder : 'public');
    $styleOk = is_string($styleUrl) && !str_contains($styleUrl, 'cdn.gitxx.xyz') && $publicUrl === $cdn . '/' . ($folder !== '' ? $folder : 'public') . '/example.jpg';
    $user = 'unknown';
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
      $info = posix_getpwuid(posix_geteuid());
      if (is_array($info) && isset($info['name'])) {
        $user = (string) $info['name'];
      }
    }
    $report = self::evaluate([
      'target' => $target,
      'current_mode' => (string) $runtime['public_mode'],
      'current_provider' => (string) $runtime['public_provider'],
      'public_folder' => $folder,
      'private_folder' => $privateFolder,
      'legacy_prefix' => $legacy,
      'legacy_objects' => count($legacyObjects),
      'active_objects' => count($active),
      'files' => $files,
      'remote_known' => $remoteKnown,
      'list_error' => $listError,
      'directory_exists' => $directoryExists,
      'directory_readable' => $directoryReadable,
      'directory_writable' => $directoryWritable,
      'directory' => $root,
      'directory_note' => $directoryExists ? $root : '目录不存在：' . $root,
      'runtime_writable' => $runtimeWritable,
      'locked' => RuntimeState::locked($this->privatePath()),
      'process_user' => $user,
      'private_wrapper_local' => $private['wrapper_local'],
      'private_cdn_blocked' => $private['cdn_blocked'],
      'private_takeover' => $private['takeover'],
      'private_note' => $private['note'],
      'style_stays_local' => $styleOk,
      'architecture_ok' => $architectureOk,
      'architecture_note' => $architectureNote,
      'language_note' => $language['note'],
      'language_error' => $language['error'],
    ]);
    $report['version'] = (int) $runtime['version'];
    $report['writes_paused'] = !empty($runtime['writes_paused']);
    return $report;
  }

  /**
   * @param list<array<string, mixed>> $plan
   *   Files selected by the fresh check.
   *
   * @return array{copied: list<array<string, mixed>>, failed: list<string>}
   *   Copy result. Source files and existing objects are not deleted.
   */
  private function copyPlan(array $plan, string $folder): array {
    $copied = [];
    $failed = [];
    $connection = NULL;
    if ($plan !== []) {
      $connection = $this->connection();
      if ($connection === NULL) {
        return ['copied' => [], 'failed' => ['credentials']];
      }
    }
    foreach ($plan as $item) {
      $action = (string) ($item['action'] ?? '');
      $key = (string) ($item['key'] ?? '');
      $sourceKey = (string) ($item['source_key'] ?? '');
      $path = (string) ($item['path'] ?? '');
      if (!$this->allowedWriteKey($key, $folder) || $path === '' || str_contains($path, '://')) {
        $failed[] = $key;
        continue;
      }
      if ($action === 'download') {
        if (!$this->allowedReadKey($sourceKey, $folder) || !$this->download($connection, $sourceKey, $path)) {
          $failed[] = $key;
          continue;
        }
      }
      elseif ($action === 'upload') {
        if (!is_file($path) || is_link($path)) {
          $failed[] = $key;
          continue;
        }
        $result = $this->writer->putFile($connection, $path, $key, ['purpose' => 'migration', 'mime' => $this->mime($path)]);
        if (empty($result['ok'])) {
          $failed[] = $key;
          continue;
        }
      }
      else {
        $failed[] = $key;
        continue;
      }
      $copied[] = ['key' => $action === 'download' ? $sourceKey : $key, 'path' => $path];
    }
    return ['copied' => $copied, 'failed' => $failed];
  }

  /**
   * @param list<array<string, mixed>> $items
   *   Files copied during this task.
   *
   * @return list<string>
   *   Keys whose SHA-256 did not match.
   */
  private function verifyCopied(array $items): array {
    $failed = [];
    $connection = $this->connection();
    foreach ($items as $item) {
      $path = (string) ($item['path'] ?? '');
      $key = (string) ($item['key'] ?? '');
      if ($path === '' || $key === '' || !is_file($path) || is_link($path)) {
        $failed[] = $key;
        continue;
      }
      $local = hash_file('sha256', $path);
      $remote = $connection === NULL ? NULL : $this->reader->hash($connection, $key);
      if (!self::hashesMatch(is_string($local) ? $local : NULL, is_string($remote) ? $remote : NULL)) {
        $failed[] = $key;
      }
    }
    return $failed;
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  private function download(array $connection, string $key, string $path): bool {
    if (!self::prepareLocalDestination($path)) {
      return FALSE;
    }
    if (is_link($path)) {
      return FALSE;
    }
    if (is_file($path) && filesize($path) !== 0) {
      return TRUE;
    }
    try {
      $temp = $this->reader->copyToTemp($connection, $key);
    }
    catch (\Throwable) {
      return FALSE;
    }
    if (!is_file($temp)) {
      return FALSE;
    }
    $moved = rename($temp, $path);
    if (!$moved) {
      @unlink($temp);
      return FALSE;
    }
    @chmod($path, 0644);
    return is_file($path);
  }

  /**
   * @param array<string, mixed> $before
   *   Previous document.
   */
  private function restore(?string $private, array $before, string $task, string $summary): void {
    $before['writes_paused'] = FALSE;
    $before['switch_task'] = '';
    $before['status'] = 'rolled_back';
    $before['summary'] = $summary;
    $before['updated'] = time();
    RuntimeState::write($private, $before);
    $this->record(0, $before, $before, $task, 'rolled_back', 0, $summary);
  }

  /**
   * @param array<string, mixed> $before
   *   Previous document.
   * @param array<string, mixed> $after
   *   New document.
   */
  private function record(int $uid, array $before, array $after, string $task, string $status, int $copied, string $error): void {
    RuntimeState::audit($this->privatePath(), [
      'uid' => $uid,
      'task' => $task,
      'from' => (string) ($before['public_mode'] ?? ''),
      'to' => (string) ($after['public_mode'] ?? ''),
      'copied' => $copied,
      'status' => $status,
      'error' => $error,
    ]);
    $this->journal->add($status === 'switched' ? 'info' : 'error', '存储切换 ' . $task . ' ' . $status . '，复制 ' . $copied . ' 个。' . $error);
  }

  /**
   * @return array<string, int>
   *   Object key to size. Image styles are omitted.
   */
  private function listPrefix(string $prefix): array {
    if ($prefix === '/' || $prefix === '' || str_contains($prefix, '..') || str_contains($prefix, '//')) {
      throw new \RuntimeException('对象前缀无效。');
    }
    $connection = $this->connection();
    if ($connection === NULL) {
      throw new \RuntimeException('R2 凭证不可用，没有列出对象。');
    }
    $secrets = [];
    foreach (['access_key', 'secret_key'] as $name) {
      if (isset($connection[$name]) && is_string($connection[$name]) && $connection[$name] !== '') {
        $secrets[] = $connection[$name];
      }
    }
    try {
      $client = S3ClientFactory::create($connection);
      $bucket = (string) $connection['bucket'];
      $token = NULL;
      $sizes = [];
      do {
        $args = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
        if ($token !== NULL) {
          $args['ContinuationToken'] = $token;
        }
        $page = $client->listObjectsV2($args);
        foreach ($page['Contents'] ?? [] as $object) {
          $key = (string) $object['Key'];
          if (str_contains($key, '/styles/')) {
            continue;
          }
          $sizes[$key] = (int) $object['Size'];
        }
        $token = !empty($page['IsTruncated']) ? (string) $page['NextContinuationToken'] : NULL;
      }
      while ($token !== NULL);
      return $sizes;
    }
    catch (\Throwable $error) {
      throw new \RuntimeException(BootGuard::safeError($error, $secrets));
    }
    finally {
      unset($connection, $secrets);
    }
  }

  /**
   * @return array<string, mixed>|null
   *   Connection, or NULL when it cannot be resolved.
   */
  private function connection(): ?array {
    $profile = $this->config->profile('silly');
    if ($profile === NULL) {
      return NULL;
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
      unset($resolved);
      return NULL;
    }
    $connection = $resolved['connection'];
    unset($resolved);
    return $connection;
  }

  /**
   * @return array{note: string, error: string}
   *   Language references sharing one file URI.
   */
  private function languageNote(): array {
    try {
      $languages = (int) $this->database->query('SELECT COUNT(DISTINCT langcode) FROM {node_field_data}')->fetchField();
      $shared = 0;
      foreach ([
        'node__field_images' => 'field_images_target_id',
        'node__field_cover' => 'field_cover_target_id',
        'node__field_cover_image' => 'field_cover_image_target_id',
        'node__field_video_file' => 'field_video_file_target_id',
        'node__field_video_cover' => 'field_video_cover_target_id',
      ] as $table => $column) {
        if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, $column)) {
          continue;
        }
        $shared += (int) $this->database->query("SELECT COUNT(*) FROM (SELECT {$column} AS fid FROM {{$table}} GROUP BY {$column} HAVING COUNT(DISTINCT langcode) > 1) shared_files")->fetchField();
      }
      return ['note' => $languages . ' 种内容语言，' . $shared . ' 个文件被多于一种语言引用。这些引用仍指向同一条文件 URI。', 'error' => ''];
    }
    catch (\Throwable $error) {
      return ['note' => '', 'error' => BootGuard::safeError($error)];
    }
  }

  /**
   * @return array{note: string, wrapper_local: bool, cdn_blocked: bool, takeover: bool}
   *   Private delivery facts. Missing private originals do not by themselves fail this check.
   */
  private function privateNote(): array {
    $class = '';
    if (\Drupal::hasService('stream_wrapper_manager')) {
      $resolved = \Drupal::service('stream_wrapper_manager')->getClass('private');
      $class = is_string($resolved) ? $resolved : '';
    }
    $wrapperLocal = str_contains($class, 'PrivateStream') && !str_contains($class, 'S3');
    $takeover = (bool) Settings::get('s3fs.use_s3_for_private', FALSE);
    $cdn = PublicUrl::cdnUrl('private://secret.txt', 'https://cdn.gitxx.xyz', 'public');
    $policy = CdnUrlPolicy::decide(TRUE, TRUE, TRUE, 'private/secret.txt', 'public', 'private', 'https://cdn.gitxx.xyz', 'https://example.r2.cloudflarestorage.com');
    $blocked = $cdn === NULL && $policy === NULL;
    $rows = $this->database->query("SELECT uri FROM {file_managed} WHERE uri LIKE :private", [
      ':private' => 'private://%',
    ])->fetchAll();
    $root = Settings::get('file_private_path');
    $root = is_string($root) ? rtrim($root, '/') : '';
    $missing = 0;
    $unreadable = 0;
    foreach ($rows as $row) {
      $relative = substr((string) $row->uri, strlen('private://'));
      $path = $root !== '' ? self::diskPath($root, $relative) : NULL;
      if ($path === NULL || !is_file($path) || is_link($path)) {
        $missing++;
        continue;
      }
      if (!is_readable($path)) {
        $unreadable++;
      }
    }
    $note = '私有记录 ' . count($rows) . ' 条，本地缺失 ' . $missing . ' 条，不可读 ' . $unreadable . ' 条。私有文件不参与公开存储切换，也不会生成 CDN 地址。包装器 ' . ($class !== '' ? $class : '未知') . '。';
    return [
      'note' => $note,
      'wrapper_local' => $wrapperLocal,
      'cdn_blocked' => $blocked,
      'takeover' => $takeover,
    ];
  }

  /**
   * @param array<string, mixed> $profile
   *   Non-secret profile.
   */
  private function endpointAccepted(array $profile): bool {
    $endpoint = trim((string) ($profile['endpoint'] ?? ''));
    $host = strtolower((string) (parse_url($endpoint, PHP_URL_HOST) ?: ''));
    $settingsEndpoint = trim((string) Settings::get('xedc_storage.endpoint', ''));
    $settingsHost = strtolower((string) (parse_url($settingsEndpoint, PHP_URL_HOST) ?: ''));
    if ($host === '' || !str_ends_with($host, '.r2.cloudflarestorage.com')) {
      return FALSE;
    }
    return $settingsHost === '' || $settingsHost === $host;
  }

  private function allowedWriteKey(string $key, string $folder): bool {
    if (!$this->allowedReadKey($key, $folder)) {
      return FALSE;
    }
    if (!str_starts_with($key, $folder . '/')) {
      return FALSE;
    }
    if (str_starts_with($key, $folder . '/' . $folder . '/')) {
      return FALSE;
    }
    return !str_contains($key, '/styles/');
  }

  private function allowedReadKey(string $key, string $folder): bool {
    if ($key === '' || str_contains($key, '..') || str_contains($key, '//') || str_starts_with($key, '/')) {
      return FALSE;
    }
    $legacy = $folder === self::LEGACY_PUBLIC_FOLDER ? 'public' : self::LEGACY_PUBLIC_FOLDER;
    return str_starts_with($key, $folder . '/') || str_starts_with($key, $legacy . '/');
  }

  private function publicFolderName(): string {
    $folder = trim((string) ($this->uris->folders()['public'] ?? ''), '/');
    return $folder !== '' ? $folder : 'public';
  }

  private function privatePath(): ?string {
    $path = Settings::get('file_private_path');
    return is_string($path) && $path !== '' ? $path : NULL;
  }

  private function runtimeDirectory(): ?string {
    $private = $this->privatePath();
    if ($private === NULL) {
      return NULL;
    }
    return rtrim($private, '/') . '/xedc-storage-runtime';
  }

  private function publicRoot(): string {
    $path = PublicStream::basePath();
    if (!str_starts_with($path, '/')) {
      $path = DRUPAL_ROOT . '/' . ltrim($path, '/');
    }
    return rtrim($path, '/');
  }

  private function mime(string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
      'jpg', 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'webp' => 'image/webp',
      'avif' => 'image/avif',
      'mp4' => 'video/mp4',
      'webm' => 'video/webm',
      default => 'application/octet-stream',
    };
  }

  /**
   * @return array{item: string, result: string, tone: string}
   *   One checklist row.
   */
  private static function row(string $item, string $result, string $tone): array {
    return ['item' => $item, 'result' => $result, 'tone' => $tone];
  }

  private static function modeLabel(string $mode): string {
    return match ($mode) {
      'local' => '本地存储',
      'cloudflare_r2' => 'Cloudflare R2',
      default => '跟随环境变量',
    };
  }

  /**
   * @return array<string, mixed>
   *   Failed result.
   */
  private function fail(string $status, string $message, string $task = ''): array {
    return ['ok' => FALSE, 'status' => $status, 'task' => $task, 'message' => $message];
  }

}
