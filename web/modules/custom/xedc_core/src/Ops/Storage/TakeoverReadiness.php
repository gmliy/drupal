<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;

/**
 * Read-only view of the live storage mode and the switches that follow it.
 *
 * This class never changes settings, file URIs, or objects. It does not turn
 * a healthy adapter into a completed takeover.
 */
final class TakeoverReadiness {

  public function __construct(
    protected Connection $database,
    protected StorageConfig $config,
    protected StorageUriResolver $uris,
    protected StorageJournal $journal,
  ) {}

  /**
   * @return array<string, mixed>
   *   Public report. It contains no credentials.
   */
  public function report(): array {
    $census = $this->census();
    $formats = $this->formats();
    $styles = $this->styles();
    $active = $this->config->activeId();
    $profile = $active === '' ? NULL : $this->config->profile($active);
    $assessment = $this->switches();
    $switches = $assessment['flags'];
    $missingStyles = array_values(array_diff(['thumbnail', 'photo_gallery_650'], $styles));
    $blockers = [];
    if ($active === '') {
      $blockers[] = '没有活动供应商。';
    }
    elseif ($active === 'r2') {
      $blockers[] = '活动配置仍是旧 ID r2，应使用 silly。';
    }
    if (!$formats['jpeg'] || !$formats['png'] || !$formats['webp']) {
      $blockers[] = 'GD 缺少 JPEG、PNG 或 WebP。';
    }
    if (!$formats['avif']) {
      $blockers[] = 'GD 不能写 AVIF。转换必须报告失败，不能当成成功。';
    }
    if ($missingStyles !== []) {
      $blockers[] = '缺少图片样式：' . implode('、', $missingStyles) . '。';
    }
    $cdn = $this->latestCdn();
    $exposed = is_array($cdn) ? ($cdn['private_exposed'] ?? NULL) : NULL;
    if ($exposed === TRUE || $exposed === 1 || $exposed === '1') {
      $blockers[] = 'CDN 域名可以匿名读取私有前缀。需要在 Cloudflare 边缘拒绝该路径，不能靠 Drupal 开关。';
    }
    elseif ($exposed !== FALSE && $exposed !== 0) {
      $blockers[] = 'CDN 私有前缀还没有被匿名访问测试证明已拒绝。自定义域名目前面向整个 Bucket。';
    }
    $observed = RuntimeStatus::observe();
    $bucket = RuntimeStatus::bucketProbe();
    return [
      'active_profile' => $active,
      'bucket' => is_array($profile) ? (string) ($profile['bucket'] ?? '') : '',
      'region' => is_array($profile) ? (string) ($profile['region'] ?? '') : '',
      'endpoint_host' => $this->host((string) (is_array($profile) ? ($profile['endpoint'] ?? '') : '')),
      'cdn_host' => $this->host($this->uris->configuredPublicBase(is_array($profile) ? $profile : [])),
      'public_folder' => (string) ($this->uris->folders(is_array($profile) ? $profile : NULL)['public'] ?? ''),
      'private_folder' => (string) ($this->uris->folders(is_array($profile) ? $profile : NULL)['private'] ?? ''),
      'switches' => $switches,
      'assessment' => $assessment,
      'formats' => $formats,
      'styles' => $styles,
      'census' => $census,
      'blockers' => $blockers,
      'public_ready' => FALSE,
      'private_ready' => FALSE,
      'note' => '本页只读取状态。适配器可连接、开关与当前模式一致、允许执行接管、接管已经执行，是四件不同的事。公开和私有接管都不会从这里打开。配置被接受不等于上传已经进入 R2。',
      'observed' => $observed,
      'bucket_probe' => $bucket,
    ];
  }

  /**
   * @return array<string, mixed>
   *   Switch rows for the current process. No Bucket listing.
   */
  public function switches(): array {
    return self::assess($this->liveInput());
  }

  /**
   * @param array<string, mixed> $input
   *   Facts already collected. This method does not read settings or files.
   *
   * @return array<string, mixed>
   *   Per-switch result. Execution stays closed.
   */
  public static function assess(array $input): array {
    $readable = !empty($input['settings_readable']);
    $file = (string) ($input['runtime_file'] ?? 'missing');
    $mode = (string) ($input['public_mode'] ?? '');
    if (!$readable) {
      $phase = 'unreadable';
    }
    elseif ($file === 'invalid') {
      $phase = 'invalid';
    }
    elseif ($file !== 'ok') {
      $phase = 'uninitialized';
    }
    elseif ($mode === 'cloudflare_r2') {
      $phase = 'production_r2';
    }
    elseif ($mode === 'local') {
      $phase = 'production_local';
    }
    else {
      $phase = 'uninitialized';
    }
    $rows = [
      self::runtimeRow($input, $phase),
      self::publicRow($input, $phase),
      self::privateRow($input, $phase),
      self::cdnRow($input, $phase),
    ];
    $failures = [];
    foreach ($rows as $row) {
      if (($row['result'] ?? '') !== '通过') {
        $failures[] = (string) $row['label'] . '：' . (string) $row['explanation'];
      }
    }
    $consistent = $failures === [];
    $observed = $phase === 'production_r2'
      && $consistent
      && !empty($input['public_wrapper_remote'])
      && empty($input['private_wrapper_remote']);
    return [
      'phase' => $phase,
      'production_mode' => $phase === 'production_r2' ? 'cloudflare_r2' : ($phase === 'production_local' ? 'local' : (string) ($input['public_mode'] ?? '')),
      'public_provider' => (string) ($input['public_provider'] ?? ''),
      'consistent' => $consistent,
      'takeover_check' => $consistent ? '通过' : '不通过',
      'execution_allowed' => FALSE,
      'executed_this_round' => FALSE,
      'observed_public_r2' => $observed,
      'rows' => $rows,
      'failures' => $failures,
      'execution_reason' => self::executionReason($phase, $consistent),
      'phase_note' => self::phaseNote($phase, $consistent),
      'flags' => [
        'runtime_applied' => $phase === 'production_r2',
        'public_takeover' => !empty($input['public_takeover']),
        'private_takeover' => !empty($input['private_takeover']),
        'cdn_rewrite' => !empty($input['cdn_rewrite']),
        'settings_runtime_applied' => !empty($input['settings_runtime_applied']),
      ],
      'summary' => [
        self::summaryRow('当前生产运行模式', self::modeText($phase, $mode), $phase === 'invalid' || $phase === 'unreadable' ? '不通过' : '通过'),
        self::summaryRow('接管检查', $consistent ? '通过' : '不通过', $consistent ? '通过' : '不通过'),
        self::summaryRow('接管可以执行', '不允许', '不允许'),
        self::summaryRow('接管已执行', '本轮未执行', '未执行'),
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   *   Live facts. Missing settings stay missing instead of becoming false.
   */
  private function liveInput(): array {
    try {
      $boot = (string) Settings::get('xedc_storage.boot', '');
    }
    catch (\Throwable) {
      return ['settings_readable' => FALSE, 'runtime_file' => 'missing'];
    }
    if ($boot !== 'ok') {
      return ['settings_readable' => FALSE, 'runtime_file' => 'missing'];
    }
    $file = $this->runtimeFileFacts();
    try {
      $observed = RuntimeStatus::observe();
    }
    catch (\Throwable) {
      $observed = [];
    }
    $state = is_array($file['state'] ?? NULL) ? $file['state'] : [];
    $cdn = $state['cdn_enabled'] ?? NULL;
    return [
      'settings_readable' => TRUE,
      'runtime_file' => (string) ($file['status'] ?? 'missing'),
      'public_mode' => (string) ($state['public_mode'] ?? ''),
      'public_provider' => (string) ($state['public_provider'] ?? ''),
      'settings_public_mode' => (string) Settings::get('xedc_storage.public_mode', ''),
      'settings_runtime_applied' => (bool) Settings::get('xedc_storage.runtime_applied', FALSE),
      'public_takeover' => (bool) Settings::get('s3fs.use_s3_for_public', FALSE),
      'xedc_public_takeover' => (bool) Settings::get('xedc_storage.public_takeover', FALSE),
      'private_takeover' => (bool) Settings::get('s3fs.use_s3_for_private', FALSE),
      'xedc_private_takeover' => (bool) Settings::get('xedc_storage.private_takeover', FALSE),
      'cdn_rewrite' => $this->uris->rewriteEnabled(),
      'runtime_cdn_enabled' => is_bool($cdn) ? $cdn : NULL,
      'public_wrapper_remote' => (($observed['stream_wrapper_active'] ?? '') === 'r2'),
      'private_wrapper_remote' => str_contains((string) ($observed['private_stream_wrapper_class'] ?? ''), 'S3'),
      'public_wrapper_class' => (string) ($observed['active_stream_wrapper_class'] ?? ''),
      'private_wrapper_class' => (string) ($observed['private_stream_wrapper_class'] ?? ''),
    ];
  }

  /**
   * @return array{status: string, state: array<string, mixed>}
   *   File presence without rewriting it.
   */
  private function runtimeFileFacts(): array {
    $private = Settings::get('file_private_path');
    $private = is_string($private) ? $private : '';
    $path = $private === '' ? '' : rtrim($private, '/') . '/xedc-storage-runtime/active.json';
    if ($path === '' || !is_file($path)) {
      return ['status' => 'missing', 'state' => RuntimeState::missing()];
    }
    if (!is_readable($path)) {
      return ['status' => 'invalid', 'state' => RuntimeState::missing()];
    }
    $state = RuntimeState::read($private);
    if ((string) ($state['checksum'] ?? '') === '') {
      return ['status' => 'invalid', 'state' => $state];
    }
    return ['status' => 'ok', 'state' => $state];
  }

  /**
   * @param array<string, mixed> $input
   *   Collected facts.
   *
   * @return array<string, string>
   *   One display row.
   */
  private static function runtimeRow(array $input, string $phase): array {
    $source = '运行状态文件 private/xedc-storage-runtime/active.json 的 public_mode。请求启动时由 BootGuard 写入 Settings xedc_storage.public_mode。Settings xedc_storage.runtime_applied 固定写成 false，不能单独当成运行时已关闭。';
    if ($phase === 'unreadable') {
      return self::row('运行时', '无法判断', 'Settings', '不通过', '配置读取失败。没有把运行时当成关闭，也没有改任何开关。');
    }
    if ($phase === 'invalid') {
      return self::row('运行时', '无法判断', $source, '不通过', '状态文件无效或不可读。不能用它判断运行模式，本检查也不会把文件改成其他模式。');
    }
    if ($phase === 'uninitialized') {
      if (!empty($input['settings_runtime_applied'])) {
        return self::row('运行时', '已启用', $source, '不通过', '状态文件不存在，但 Settings xedc_storage.runtime_applied 为打开。两个来源不一致。本检查不会关闭它。');
      }
      return self::row('运行时', '未启用', $source, '通过', '没有可用的运行状态文件。对象存储运行模式未启用。这只说明当前未启用，不表示可以执行接管。');
    }
    $settingsMode = (string) ($input['settings_public_mode'] ?? '');
    $problems = [];
    if ($settingsMode !== (string) ($input['public_mode'] ?? '')) {
      $problems[] = '状态文件的 public_mode 与 Settings xedc_storage.public_mode 不一致。';
    }
    if (!empty($input['settings_runtime_applied'])) {
      $problems[] = 'xedc_storage.runtime_applied 为 true。BootGuard 不会写出这个值，它和状态文件不是同一个开关。';
    }
    if ($phase === 'production_r2') {
      if (empty($input['public_wrapper_remote'])) {
        $problems[] = '运行模式是 Cloudflare R2，但当前进程的公开包装器没有按对象存储工作（' . self::className($input, 'public_wrapper_class') . '）。';
      }
      if ($problems !== []) {
        return self::row('运行时', '已启用', $source, '不通过', implode('', $problems) . '本检查不会修改运行模式。');
      }
      return self::row('运行时', '已启用', $source, '通过', '当前生产运行模式是 cloudflare_r2，公开包装器按这个模式工作。runtime_applied 为 false 是启动代码的固定写法，不表示运行时关闭。');
    }
    if (!empty($input['public_wrapper_remote'])) {
      $problems[] = '状态文件是本地，但公开包装器仍按对象存储工作。';
    }
    if ($problems !== []) {
      $state = (!empty($input['settings_runtime_applied']) || !empty($input['public_wrapper_remote'])) ? '已启用' : '未启用';
      return self::row('运行时', $state, $source, '不通过', implode('', $problems) . '本检查不会修改运行模式。');
    }
    return self::row('运行时', '未启用', $source, '通过', '运行模式是本地。对象存储运行时没有启用，和状态文件一致。');
  }

  /**
   * @param array<string, mixed> $input
   *   Collected facts.
   *
   * @return array<string, string>
   *   One display row.
   */
  private static function publicRow(array $input, string $phase): array {
    $source = 'BootGuard 写入 Settings s3fs.use_s3_for_public，并写入同一值到 xedc_storage.public_takeover。cloudflare_r2 运行模式会打开公开接管。环境变量 STORAGE_PUBLIC_TAKEOVER 只在同时具备 STORAGE_I_UNDERSTAND_TAKEOVER、且不是由运行状态文件驱动时才参与。';
    if ($phase === 'unreadable') {
      return self::row('公开接管', '无法判断', 'Settings', '不通过', '配置读取失败。没有把公开接管当成关闭。');
    }
    if ($phase === 'invalid') {
      return self::row('公开接管', '无法判断', $source, '不通过', '状态文件无效，不能判断公开接管是否符合当前模式。没有改开关。');
    }
    $enabled = !empty($input['public_takeover']);
    $mirror = !empty($input['xedc_public_takeover']);
    if ($enabled !== $mirror) {
      return self::row('公开接管', $enabled ? '已启用' : '未启用', $source, '不通过', 's3fs.use_s3_for_public 与 xedc_storage.public_takeover 不一致。Settings 与模块自己的接管标记不是同一个值。本检查不会改其中任何一个。');
    }
    if ($phase === 'production_r2') {
      if (!$enabled) {
        return self::row('公开接管', '未启用', $source, '不通过', '生产模式是 Cloudflare R2，公开接管却是关闭的。这和 BootGuard 在该模式下应写入的值不一致。本检查不会自动打开它。');
      }
      return self::row('公开接管', '已启用', $source, '通过', '公开原件已经按 Cloudflare R2 运行。这个开关打开是当前架构的正常状态，不是首次启用前的失败条件。');
    }
    if ($enabled) {
      return self::row('公开接管', '已启用', $source, '不通过', '当前运行模式不是 Cloudflare R2，公开接管却已打开。首次启用前它应当关闭。本检查不会关闭它。');
    }
    return self::row('公开接管', '未启用', $source, '通过', '公开接管关闭，和当前未使用 R2 运行模式的状态一致。这不表示可以在本页执行接管。');
  }

  /**
   * @param array<string, mixed> $input
   *   Collected facts.
   *
   * @return array<string, string>
   *   One display row.
   */
  private static function privateRow(array $input, string $phase): array {
    $source = 'BootGuard 固定把 s3fs.use_s3_for_private 和 xedc_storage.private_takeover 写成 false。私有文件继续由 Drupal 本地 PrivateStream 处理。';
    if ($phase === 'unreadable') {
      return self::row('私有接管', '无法判断', 'Settings', '不通过', '配置读取失败。没有把私有接管当成关闭。');
    }
    if ($phase === 'invalid') {
      return self::row('私有接管', '无法判断', $source, '不通过', '状态文件无效，不能判断私有接管。没有改开关。');
    }
    $enabled = !empty($input['private_takeover']);
    $mirror = !empty($input['xedc_private_takeover']);
    $remote = !empty($input['private_wrapper_remote']);
    if ($enabled !== $mirror) {
      return self::row('私有接管', $enabled ? '已启用' : '未启用', $source, '不通过', 's3fs.use_s3_for_private 与 xedc_storage.private_takeover 不一致。本检查不会改它们。');
    }
    if ($enabled || $remote) {
      $why = $enabled ? '私有接管设置已打开。' : '私有接管设置是关闭的，但当前私有包装器是对象存储（' . self::className($input, 'private_wrapper_class') . '）。';
      return self::row('私有接管', $enabled ? '已启用' : '未启用', $source, '不通过', $why . '当前架构要求私有文件留在本地。本检查不会关闭包装器，也不会继续执行接管。');
    }
    return self::row('私有接管', '未启用', $source, '通过', '私有接管关闭，私有包装器不是对象存储。会员和受保护文件仍走本地私有目录。');
  }

  /**
   * @param array<string, mixed> $input
   *   Collected facts.
   *
   * @return array<string, string>
   *   One display row.
   */
  private static function cdnRow(array $input, string $phase): array {
    $source = 'Settings xedc_storage.cdn_enabled，由 StorageUriResolver 读取。运行模式为 cloudflare_r2 时，BootGuard 用状态文件里的 cdn_enabled 覆盖环境变量 STORAGE_CDN_ENABLED。公开原件地址依赖它；图片样式仍留在本站。';
    if ($phase === 'unreadable') {
      return self::row('CDN Rewrite', '无法判断', 'Settings', '不通过', '配置读取失败。没有把 CDN 改写当成关闭。');
    }
    if ($phase === 'invalid') {
      return self::row('CDN Rewrite', '无法判断', $source, '不通过', '状态文件无效，不能判断 CDN 改写是否符合当前模式。没有改开关。');
    }
    $enabled = !empty($input['cdn_rewrite']);
    $fileCdn = $input['runtime_cdn_enabled'] ?? NULL;
    if ($phase === 'production_r2') {
      if (!is_bool($fileCdn)) {
        return self::row('CDN Rewrite', $enabled ? '已启用' : '未启用', $source, '不通过', '运行模式是 Cloudflare R2，但状态文件没有布尔型 cdn_enabled。Settings 与状态文件无法对照。本检查不会写入这个值。');
      }
      if ($fileCdn !== $enabled) {
        return self::row('CDN Rewrite', $enabled ? '已启用' : '未启用', $source, '不通过', '状态文件 cdn_enabled 与 Settings xedc_storage.cdn_enabled 不一致。本检查不会改其中任何一个。');
      }
      if (!$enabled) {
        return self::row('CDN Rewrite', '未启用', $source, '不通过', '生产模式是 Cloudflare R2，但 CDN 改写关闭。公开原件不会改写到 cdn.gitxx.xyz。本检查不会自动打开它。');
      }
      return self::row('CDN Rewrite', '已启用', $source, '通过', 'CDN 改写打开，和状态文件一致。当前公开原件访问需要它。这不是首次启用前必须关闭的条件，私有文件也不会因此生成公开 CDN 地址。');
    }
    if ($enabled) {
      return self::row('CDN Rewrite', '已启用', $source, '不通过', '当前不是 Cloudflare R2 运行模式，CDN 改写却已打开。本检查不会关闭它。');
    }
    return self::row('CDN Rewrite', '未启用', $source, '通过', 'CDN 改写关闭，和当前未使用 R2 运行模式的状态一致。');
  }

  private static function executionReason(string $phase, bool $consistent): string {
    if (!$consistent) {
      return '有开关和当前架构不一致，或配置无法读取。禁止执行接管。本页不会关闭开关，也不会改运行模式。';
    }
    if ($phase === 'production_r2') {
      return '公开存储已经由运行状态文件处于 Cloudflare R2。这不是等待执行的首次接管。本页没有执行入口。更换模式要走运行模式页的独立检查，本次没有执行。';
    }
    if ($phase === 'production_local') {
      return '开关与本地模式一致。本页仍然不执行接管。更换公开存储要走运行模式页的检查和确认文字。';
    }
    if ($phase === 'uninitialized') {
      return '状态文件缺失，开关处于关闭。这只满足旧的首次启用前观察条件。本页不执行接管，公开和私有接管都不标记为可执行。';
    }
    return '配置或状态文件不可用。禁止执行接管。';
  }

  private static function phaseNote(string $phase, bool $consistent): string {
    if ($phase === 'production_r2' && $consistent) {
      return '旧检查把运行时、公开接管、私有接管和 CDN 改写合成一条，并要求全部关闭。那个条件只适用于还没有运行状态文件的首次启用。当前文件已经是 cloudflare_r2：公开接管和 CDN 改写打开是正常状态，私有接管必须保持关闭。';
    }
    if ($phase === 'production_r2') {
      return '当前文件声明的模式是 cloudflare_r2，但下面有开关和这个模式不一致。不一致的项目逐项列出。不会为了显示通过而关闭生产开关。';
    }
    if ($phase === 'unreadable' || $phase === 'invalid') {
      return '配置或状态文件不可用时，不把未知开关显示成关闭，也不允许执行。';
    }
    return '当前还没有 Cloudflare R2 运行模式。此时运行时、公开接管、私有接管和 CDN 改写应当关闭。关闭只说明尚未启用，不表示本页可以执行接管。';
  }

  private static function modeText(string $phase, string $mode): string {
    return match ($phase) {
      'production_r2' => 'cloudflare_r2',
      'production_local' => 'local',
      'uninitialized' => '未初始化',
      'invalid' => '状态文件无效',
      'unreadable' => '配置不可读',
      default => $mode === '' ? '未知' : $mode,
    };
  }

  /**
   * @param array<string, mixed> $input
   *   Collected facts.
   */
  private static function className(array $input, string $key): string {
    $class = (string) ($input[$key] ?? '');
    if ($class === '') {
      return '未知';
    }
    $parts = explode('\\', $class);
    return (string) end($parts);
  }

  /**
   * @return array<string, string>
   *   Display row.
   */
  private static function row(string $label, string $state, string $source, string $result, string $explanation): array {
    return [
      'label' => $label,
      'state' => $state,
      'source' => $source,
      'result' => $result,
      'explanation' => $explanation,
      'tone' => $result === '通过' ? 'ok' : 'bad',
    ];
  }

  /**
   * @return array<string, string>
   *   Short verdict row.
   */
  private static function summaryRow(string $label, string $value, string $result): array {
    $tone = match ($result) {
      '通过' => 'ok',
      '不允许', '未执行' => 'muted',
      default => 'bad',
    };
    return [
      'label' => $label,
      'value' => $value,
      'result' => $result,
      'tone' => $tone,
    ];
  }

  /**
   * @return array<string, int>
   *   File entity counts. These are not Bucket object counts.
   */
  private function census(): array {
    $counts = [
      'files' => 0,
      'bytes' => 0,
      'public_files' => 0,
      'public_bytes' => 0,
      'private_files' => 0,
      'private_bytes' => 0,
      's3_files' => 0,
      's3_bytes' => 0,
      'temporary_files' => 0,
      'image_files' => 0,
      'video_files' => 0,
      'other_files' => 0,
    ];
    if (!$this->database->schema()->tableExists('file_managed')) {
      return $counts;
    }
    $rows = $this->database->query('SELECT uri, filemime, filesize FROM {file_managed}');
    foreach ($rows as $row) {
      $uri = (string) $row->uri;
      $bytes = max(0, (int) $row->filesize);
      $scheme = $this->uris->scheme($uri);
      $kind = $this->uris->mediaKind((string) $row->filemime, $uri);
      $counts['files']++;
      $counts['bytes'] += $bytes;
      if ($scheme === 'public') {
        $counts['public_files']++;
        $counts['public_bytes'] += $bytes;
      }
      elseif ($scheme === 'private') {
        $counts['private_files']++;
        $counts['private_bytes'] += $bytes;
      }
      elseif ($scheme === 's3') {
        $counts['s3_files']++;
        $counts['s3_bytes'] += $bytes;
      }
      elseif ($scheme === 'temporary') {
        $counts['temporary_files']++;
      }
      if ($kind === 'image') {
        $counts['image_files']++;
      }
      elseif ($kind === 'video') {
        $counts['video_files']++;
      }
      else {
        $counts['other_files']++;
      }
    }
    return $counts;
  }

  /**
   * @return array<string, bool>
   *   Encoder availability. Imagick is reported and is not required.
   */
  private function formats(): array {
    return [
      'jpeg' => function_exists('imagejpeg') && function_exists('imagecreatefromjpeg'),
      'png' => function_exists('imagepng') && function_exists('imagecreatefrompng'),
      'webp' => function_exists('imagewebp') && function_exists('imagecreatefromwebp'),
      'avif' => function_exists('imageavif') && function_exists('imagecreatefromavif'),
      'imagick' => extension_loaded('imagick'),
    ];
  }

  /**
   * @return array<string, mixed>|null
   *   Newest CDN probe that recorded a private-prefix result.
   */
  private function latestCdn(): ?array {
    $stored = $this->journal->all()['cdn'] ?? NULL;
    if (!is_array($stored)) {
      return NULL;
    }
    $latest = NULL;
    $time = -1;
    foreach ($stored as $row) {
      if (!is_array($row) || !array_key_exists('private_exposed', $row)) {
        continue;
      }
      $checked = (int) ($row['checked'] ?? 0);
      if ($latest === NULL || $checked >= $time) {
        $latest = $row;
        $time = $checked;
      }
    }
    return $latest;
  }

  /**
   * @return list<string>
   *   Image style config IDs.
   */
  private function styles(): array {
    if (!$this->database->schema()->tableExists('config')) {
      return [];
    }
    $names = $this->database->select('config', 'c')
      ->fields('c', ['name'])
      ->condition('name', 'image.style.%', 'LIKE')
      ->execute()
      ->fetchCol();
    $ids = [];
    foreach ($names as $name) {
      if (is_string($name) && str_starts_with($name, 'image.style.')) {
        $ids[] = substr($name, strlen('image.style.'));
      }
    }
    sort($ids);
    return $ids;
  }

  private function host(string $url): string {
    if ($url === '') {
      return '';
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? $host : '';
  }

}
