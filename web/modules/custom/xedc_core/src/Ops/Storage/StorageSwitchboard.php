<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Site\Settings;
use Drupal\xedc_core\ArchiveManager;
use Drupal\xedc_core\ImagePipeline;

/**
 * Reports takeover switches and refuses to turn them on from the admin UI.
 */
final class StorageSwitchboard {

  public function __construct(
    protected StorageConfig $config,
    protected StorageJournal $journal,
    protected ImagePipeline $images,
    protected ArchiveManager $archives,
    protected ConnectionTester $tester,
  ) {}

  /**
   * @return list<array<string, mixed>>
   *   One row per switch.
   */
  public function status(): array {
    $rows = [];
    foreach (['runtime', 'public_takeover', 'private_takeover', 'cdn_rewrite'] as $name) {
      $check = $this->preflight($name);
      $rows[] = [
        'id' => $name,
        'label' => $this->label($name),
        'enabled' => $this->enabled($name),
        'ready' => $check['ready'],
        'blockers' => $check['blockers'],
        'warnings' => $check['warnings'],
      ];
    }
    return $rows;
  }

  /**
   * @return array{ready: bool, blockers: list<string>, warnings: list<string>}
   *   Preconditions. This method never changes a switch.
   */
  public function preflight(string $name): array {
    $blockers = [];
    $warnings = [];
    $runtime = (bool) Settings::get('xedc_storage.runtime_applied', FALSE);
    $tested = $this->connectionPassed();
    if ($name === 'runtime') {
      if ($this->config->activeId() === '') {
        $blockers[] = '还没有选择活动供应商。';
      }
      if (!$tested) {
        $blockers[] = '活动供应商还没有一次通过的连接测试。';
      }
      $warnings[] = '运行时开关只接受服务器环境变量 XEDC_STORAGE_RUNTIME=1。';
    }
    elseif ($name === 'public_takeover') {
      if (!$runtime) {
        $blockers[] = '运行时连接还没有启用。';
      }
      if (!$tested) {
        $blockers[] = '连接测试还没有通过。';
      }
      if (!$this->images->remoteReady()) {
        $blockers[] = '图片处理还不能把远程原图放到本地临时文件。';
      }
      if (!$this->archives->remoteReady()) {
        $blockers[] = '压缩包解压还不能固定使用本地临时目录。';
      }
      $warnings[] = '公开接管会让新的 public:// 文件进入 R2。已有本地 URI 不会自动改写。';
    }
    elseif ($name === 'private_takeover') {
      if (!$runtime) {
        $blockers[] = '运行时连接还没有启用。';
      }
      if (!$tested) {
        $blockers[] = '连接测试还没有通过。';
      }
      if (!$this->archives->remoteReady()) {
        $blockers[] = '压缩包仍可能依赖私有目录的本地路径。';
      }
      $warnings[] = '私有接管前需要确认会员下载和受保护签名地址。';
    }
    elseif ($name === 'cdn_rewrite') {
      $profile = $this->config->profile($this->config->activeId());
      $base = trim((string) ($profile['public_base_url'] ?? ''));
      if ($base === '' || BootGuard::publicUrlError($base) !== NULL) {
        $blockers[] = '公开 CDN 地址还不是合法的 HTTPS 地址。';
      }
      $cdnHost = $base === '' ? '' : (string) (parse_url($base, PHP_URL_HOST) ?: '');
      $apiHost = $profile === NULL ? '' : (string) (parse_url((string) $profile['endpoint'], PHP_URL_HOST) ?: '');
      if ($cdnHost !== '' && $cdnHost === $apiHost) {
        $blockers[] = 'CDN 主机和 API Endpoint 主机相同。';
      }
      $warnings[] = 'CDN 重写只作用于已经在对象存储中的公开文件，不会替换现有本地 URL。';
    }
    else {
      $blockers[] = '未知开关。';
    }
    return [
      'ready' => $blockers === [],
      'blockers' => $blockers,
      'warnings' => $warnings,
    ];
  }

  /**
   * Records a refused enable request. The environment is not changed.
   */
  public function refuseEnable(string $name): string {
    $check = $this->preflight($name);
    $message = $check['ready']
      ? '条件已经满足，但后台不能打开生产开关。请在服务器环境变量中分别设置，并同时保留回退方法。'
      : '已拒绝打开 ' . $this->label($name) . '：' . implode(' ', $check['blockers']);
    $this->journal->add('error', $message);
    return $message;
  }

  private function enabled(string $name): bool {
    return match ($name) {
      'runtime' => (bool) Settings::get('xedc_storage.runtime_applied', FALSE),
      'public_takeover' => (bool) Settings::get('s3fs.use_s3_for_public', FALSE),
      'private_takeover' => (bool) Settings::get('s3fs.use_s3_for_private', FALSE),
      'cdn_rewrite' => (bool) Settings::get('xedc_storage.cdn_enabled', FALSE),
      default => FALSE,
    };
  }

  private function label(string $name): string {
    return match ($name) {
      'runtime' => '运行时连接',
      'public_takeover' => '公开文件接管',
      'private_takeover' => '私有文件接管',
      'cdn_rewrite' => 'CDN 重写',
      default => $name,
    };
  }

  private function connectionPassed(): bool {
    $id = $this->config->activeId();
    if ($id === '') {
      return FALSE;
    }
    $tests = $this->tester->stored();
    return (string) ($tests[$id]['status'] ?? '') === 'passed';
  }

}
