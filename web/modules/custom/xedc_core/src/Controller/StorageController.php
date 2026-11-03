<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\xedc_core\Form\StorageCdnForm;
use Drupal\xedc_core\Form\StorageCredentialForm;
use Drupal\xedc_core\Form\StorageMigrationForm;
use Drupal\xedc_core\Form\StoragePolicyForm;
use Drupal\xedc_core\Form\StoragePricingForm;
use Drupal\xedc_core\Form\StorageProfileForm;
use Drupal\xedc_core\Form\StorageProviderOpsForm;
use Drupal\xedc_core\Form\StorageSecurityForm;
use Drupal\xedc_core\Ops\OpsFormat;
use Drupal\xedc_core\Ops\Storage\CapabilityProbe;
use Drupal\xedc_core\Ops\Storage\StorageSizeFormatter;
use Drupal\xedc_core\Ops\Storage\CdnStatus;
use Drupal\xedc_core\Ops\Storage\CostEstimator;
use Drupal\xedc_core\Ops\Storage\MigrationPlanner;
use Drupal\xedc_core\Ops\Storage\ObjectMonitor;
use Drupal\xedc_core\Ops\Storage\Provider\StorageProviderRegistry;
use Drupal\xedc_core\Ops\Storage\ProviderCatalog;
use Drupal\xedc_core\Ops\Storage\RuntimeConsistency;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Drupal\xedc_core\Ops\Storage\TakeoverReadiness;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Object storage center pages.
 */
class StorageController extends ControllerBase {

  public function __construct(
    protected StorageConfig $storageConfig,
    protected ObjectMonitor $monitor,
    protected CostEstimator $costs,
    protected CapabilityProbe $probe,
    protected MigrationPlanner $planner,
    protected TakeoverReadiness $readinessReport,
    protected CdnStatus $cdnStatus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.storage_config'),
      $container->get('xedc_core.storage_monitor'),
      $container->get('xedc_core.storage_cost'),
      $container->get('xedc_core.storage_probe'),
      $container->get('xedc_core.storage_migration'),
      $container->get('xedc_core.storage_readiness'),
      $container->get('xedc_core.storage_cdn_status'),
    );
  }

  public function overview(): array {
    $this->storageConfig->ensure();
    $tasks = $this->taskRows(1);
    $registry = new StorageProviderRegistry(
      $this->storageConfig,
      \Drupal::service('xedc_core.storage_credentials'),
      \Drupal::service('xedc_core.storage_reader'),
    );
    $providerCards = [];
    foreach ($registry->operational() as $provider) {
      $providerCards[] = $registry->card($provider, FALSE);
    }
    return $this->page('overview', '概览', [
      'versions' => $this->monitor->versions(),
      'runtime' => $this->runtimeCard(),
      'census' => $this->monitor->census(FALSE),
      'errors' => $this->monitor->errors(),
      'task' => $tasks[0] ?? NULL,
      'active' => $this->activeLabel(),
      'provider_cards' => $providerCards,
    ]);
  }

  public function readiness(): array {
    $this->storageConfig->ensure();
    $report = $this->readinessReport->report();
    $report['bytes_label'] = OpsFormat::bytes((int) ($report['census']['bytes'] ?? 0));
    $report['bytes_exact'] = StorageSizeFormatter::exactLabel($report['census']['bytes'] ?? NULL);
    $report['public_bytes_label'] = OpsFormat::bytes((int) ($report['census']['public_bytes'] ?? 0));
    $report['public_bytes_exact'] = StorageSizeFormatter::exactLabel($report['census']['public_bytes'] ?? NULL);
    $report['private_bytes_label'] = OpsFormat::bytes((int) ($report['census']['private_bytes'] ?? 0));
    $report['private_bytes_exact'] = StorageSizeFormatter::exactLabel($report['census']['private_bytes'] ?? NULL);
    $report['providers'] = $this->providerHealth();
    return $this->page('readiness', '启用检查', $report);
  }

  public function providers(): array {
    $this->storageConfig->ensure();
    $catalog = [];
    foreach (ProviderCatalog::all() as $id => $item) {
      $item['id'] = $id;
      $item['add_url'] = Url::fromRoute('xedc_core.storage_profile_add', [], ['query' => ['provider' => $id]])->toString();
      $catalog[] = $item;
    }
    return $this->page('providers', '供应商', [
      'catalog' => $catalog,
      'profiles' => $this->monitor->profileCards(),
      'runtime' => $this->runtimeCard(),
      'probes' => $this->probeCards(),
    ], $this->formBuilder()->getForm(StorageProviderOpsForm::class));
  }

  public function profileForm(string $mode, ?string $profile = NULL): array {
    $this->storageConfig->ensure();
    $profileId = (string) ($profile ?? '');
    $credential = NULL;
    if ($mode === 'edit' && $profileId !== '' && $this->storageConfig->profile($profileId) !== NULL) {
      $credential = $this->formBuilder()->getForm(StorageCredentialForm::class);
    }
    return $this->page('profile', $mode === 'edit' ? '编辑供应商' : '新增供应商', [
      'runtime' => $this->runtimeCard(),
    ], $this->formBuilder()->getForm(StorageProfileForm::class), $credential);
  }

  public function policy(): array {
    $this->storageConfig->ensure();
    $observed = \Drupal\xedc_core\Ops\Storage\RuntimeStatus::observe();
    return $this->page('policy', '存储策略', [
      'policy' => $this->storageConfig->data()['policy'],
      'effective' => [
        ['label' => '图片原图和视频', 'value' => \Drupal\xedc_core\Ops\Storage\RuntimeState::usesObjectStorage() ? '当前运行状态写入 Cloudflare R2' : '当前运行状态写入本地文件系统'],
        ['label' => '图片样式', 'value' => '本地服务器。样式路径不进入 CDN，也不写入 R2。'],
        ['label' => 'Private 和 Temporary', 'value' => '本地服务器。Private takeover 保持关闭。'],
        ['label' => '按文件类型分到多个供应商', 'value' => '未启用。历史文件 URI 仍是 public:// 或 private://，不能按类型改写。'],
      ],
    ], $this->formBuilder()->getForm(StoragePolicyForm::class));
  }

  public function runtime(): array {
    $this->storageConfig->ensure();
    $account = $this->currentUser();
    $form = NULL;
    if ($account->hasPermission('administer xedc object storage') || $account->hasPermission('switch xedc storage mode')) {
      $form = $this->formBuilder()->getForm(\Drupal\xedc_core\Form\StorageSwitchForm::class);
    }
    return $this->page('runtime', '运行模式', [
      'runtime' => $this->runtimeCard(),
      'consistency' => RuntimeConsistency::report(),
      'active' => $this->activeLabel(),
      'state' => \Drupal\xedc_core\Ops\Storage\RuntimeState::read(),
      'assessment' => $this->readinessReport->switches(),
      'providers' => $this->providerHealth(),
    ], $form);
  }

  public function accessRuntime(\Drupal\Core\Session\AccountInterface $account): \Drupal\Core\Access\AccessResultInterface {
    $allowed = $account->hasPermission('administer xedc object storage')
      || $account->hasPermission('view xedc storage status')
      || $account->hasPermission('switch xedc storage mode');
    return \Drupal\Core\Access\AccessResult::allowedIf($allowed)->cachePerPermissions();
  }

  public function health(): array {
    $this->storageConfig->ensure();
    $registry = new StorageProviderRegistry(
      $this->storageConfig,
      \Drupal::service('xedc_core.storage_credentials'),
      \Drupal::service('xedc_core.storage_reader'),
    );
    return $this->page('health', '存储健康检查', [
      'providers' => $this->providerHealth(),
      'reserved' => $registry->reserved(),
      'checked' => \Drupal::service('date.formatter')->format(\Drupal::time()->getRequestTime(), 'short'),
    ]);
  }

  public function cdn(): array {
    $this->storageConfig->ensure();
    return $this->page('cdn', 'CDN', [
      'cdn' => $this->storageConfig->data()['cdn'],
      'runtime' => $this->runtimeCard(),
      'status' => $this->cdnStatus->report(),
    ], $this->formBuilder()->getForm(StorageCdnForm::class));
  }

  public function migration(): array {
    $this->storageConfig->ensure();
    return $this->page('migration', '迁移', [
      'tasks' => $this->taskRows(10),
    ], $this->formBuilder()->getForm(StorageMigrationForm::class));
  }

  public function monitor(Request $request): array {
    $this->storageConfig->ensure();
    $refresh = $request->query->get('refresh') === '1';
    $account = $this->currentUser();
    $pricing = NULL;
    if ($account->hasPermission('administer xedc object storage') || $account->hasPermission('manage xedc storage policy')) {
      $pricing = $this->formBuilder()->getForm(StoragePricingForm::class);
    }
    return $this->page('monitor', '监控', [
      'runtime' => $this->runtimeCard(),
      'census' => $this->monitor->census($refresh),
      'counters' => $this->monitor->counters(),
      'cost' => $this->costs->estimate(),
      'errors' => $this->monitor->errors(),
    ], $pricing);
  }

  public function security(): array {
    $this->storageConfig->ensure();
    $census = $this->monitor->census(FALSE);
    $runtime = $this->runtimeCard();
    return $this->page('security', '安全', [
      'runtime' => $runtime,
      'quarantine_days' => $census['quarantine_days'],
      'checks' => [
        ['label' => '运行时密钥', 'value' => $runtime['secret_key'], 'tone' => $runtime['secret_key'] === '已设置' ? 'ok' : 'muted'],
        ['label' => 'public:// 接管', 'value' => $runtime['public_takeover'] ? '已开启' : '关闭', 'tone' => $runtime['public_takeover'] ? 'bad' : 'ok'],
        ['label' => 'private:// 接管', 'value' => $runtime['private_takeover'] ? '已开启' : '关闭', 'tone' => $runtime['private_takeover'] ? 'bad' : 'ok'],
        ['label' => '上传 ACL', 'value' => $runtime['upload_as_private'] ? '私有上传' : '未强制私有', 'tone' => $runtime['applied'] && !$runtime['upload_as_private'] ? 'warn' : 'ok'],
        ['label' => '文件隔离保留', 'value' => $census['quarantine_days'] . ' 天', 'tone' => 'ok'],
        ['label' => '自动删除', 'value' => '对象存储中心没有自动删除业务文件', 'tone' => 'ok'],
        ['label' => 's3:// 文件', 'value' => (string) $census['s3_files'], 'tone' => 'muted'],
      ],
    ], $this->formBuilder()->getForm(StorageSecurityForm::class));
  }

  /**
   * @param array<string, mixed> $data
   *   Page data.
   * @param array<string, mixed>|null $form
   *   Embedded form.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function page(string $page, string $title, array $data, ?array $form = NULL, ?array $credential = NULL): array {
    return [
      '#theme' => 'xedc_core_storage',
      '#page' => $page,
      '#title_text' => $title,
      '#data' => $data,
      '#form' => $form,
      '#credential' => $credential,
      '#attached' => ['library' => ['xedc_core/ops']],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   *   Runtime card.
   */
  private function runtimeCard(): array {
    $runtime = $this->monitor->runtime();
    $observed = \Drupal\xedc_core\Ops\Storage\RuntimeStatus::observe();
    $runtime['observed'] = $observed;
    if ($runtime['boot'] !== 'ok') {
      $runtime['summary'] = '运行时文件没有加载';
      $runtime['tone'] = 'bad';
    }
    elseif ($observed['mismatch']) {
      $runtime['summary'] = '已请求接管，但当前容器的 Stream Wrapper 仍不是对象存储';
      $runtime['tone'] = 'bad';
    }
    elseif ($runtime['error'] !== '') {
      $runtime['summary'] = $runtime['error'];
      $runtime['tone'] = 'warn';
    }
    elseif ($observed['stream_wrapper_active'] === 'r2' && empty($observed['private_takeover_requested'])) {
      $runtime['summary'] = '公开包装器是对象存储，私有包装器仍是本地。本请求没有执行写入。';
      $runtime['tone'] = 'ok';
    }
    elseif ($observed['stream_wrapper_active'] === 'r2') {
      $runtime['summary'] = '公开和私有包装器都显示为对象存储。私有接管不应开启。';
      $runtime['tone'] = 'bad';
    }
    elseif ($observed['settings_accepted']) {
      $runtime['summary'] = '配置已接受，当前容器仍使用本地 Stream Wrapper';
      $runtime['tone'] = 'warn';
    }
    else {
      $runtime['summary'] = '运行时未启用，文件仍在本地';
      $runtime['tone'] = 'muted';
    }
    $runtime['active_label'] = $this->activeLabel();
    return $runtime;
  }

  private function activeLabel(): string {
    $id = $this->storageConfig->activeId();
    if ($id === '') {
      return '未选择';
    }
    $profile = $this->storageConfig->profile($id);
    return $profile ? (string) $profile['label'] : $id;
  }

  /**
   * @return list<array<string, mixed>>
   *   Capability groups for saved profiles.
   */
  private function probeCards(): array {
    $stored = $this->probe->stored();
    $cards = [];
    foreach ($this->monitor->profileCards() as $profile) {
      $probe = is_array($stored[$profile['id']] ?? NULL) ? $stored[$profile['id']] : [];
      $rows = [];
      foreach (CapabilityProbe::definitions() as $id => $label) {
        $row = is_array($probe['rows'][$id] ?? NULL) ? $probe['rows'][$id] : ['status' => 'unverified', 'detail' => '尚未检测'];
        $rows[] = [
          'label' => $label,
          'status' => (string) ($row['status'] ?? 'unverified'),
          'detail' => (string) ($row['detail'] ?? ''),
          'tone' => $this->tone((string) ($row['status'] ?? 'unverified')),
        ];
      }
      $cards[] = [
        'id' => $profile['id'],
        'label' => $profile['label'],
        'status' => (string) ($probe['status'] ?? 'unverified'),
        'message' => (string) ($probe['message'] ?? '尚未检测'),
        'time' => $this->monitor->format((int) ($probe['checked'] ?? 0)),
        'rows' => $rows,
      ];
    }
    return $cards;
  }

  /**
   * @return list<array<string, mixed>>
   *   Migration tasks for display.
   */
  private function taskRows(int $limit): array {
    $rows = [];
    foreach ($this->planner->recent($limit) as $task) {
      if (!is_array($task)) {
        continue;
      }
      $counts = is_array($task['counts'] ?? NULL) ? $task['counts'] : ['files' => 0, 'bytes' => 0];
      $rows[] = [
        'id' => (string) ($task['id'] ?? ''),
        'status' => (string) ($task['status'] ?? ''),
        'tone' => $this->tone((string) ($task['status'] ?? '')),
        'source' => (string) ($task['source'] ?? ''),
        'target' => (string) ($task['target'] ?? ''),
        'files' => (string) ($counts['files'] ?? 0),
        'bytes' => OpsFormat::bytes((int) ($counts['bytes'] ?? 0)),
        'copied' => (string) ($task['copied'] ?? 0),
        'failed' => (string) ($task['failed'] ?? 0),
        'missing' => (string) ($task['missing'] ?? 0),
        'duplicate_groups' => (string) ($task['duplicate_groups'] ?? 0),
        'remote_absent' => (string) (is_array($task['remote'] ?? NULL) ? ($task['remote']['absent'] ?? 0) : 0),
        'remote_match' => (string) (is_array($task['remote'] ?? NULL) ? ($task['remote']['match'] ?? 0) : 0),
        'remote_differ' => (string) (is_array($task['remote'] ?? NULL) ? ($task['remote']['differ'] ?? 0) : 0),
        'kinds' => is_array($task['kinds'] ?? NULL) ? $task['kinds'] : [],
        'message' => (string) ($task['message'] ?? ''),
        'created' => $this->monitor->format((int) ($task['created'] ?? 0)),
        'finished' => $this->monitor->format((int) ($task['finished'] ?? 0)),
        'verified' => !empty($task['verified']) ? '已校验' : '未校验',
        'source_retained' => !empty($task['source_retained']) ? '保留' : '未知',
        'uri_rewritten' => !empty($task['uri_rewritten']) ? '已改写' : '未改写',
        'warnings' => is_array($task['warnings'] ?? NULL) ? $task['warnings'] : [],
        'sample' => $this->sampleSizes(is_array($task['sample'] ?? NULL) ? $task['sample'] : []),
      ];
    }
    return $rows;
  }

  /**
   * @return list<array<string, mixed>>
   *   Read-only adapter cards. One HeadBucket and one sample head, no listing.
   */
  private function providerHealth(): array {
    $registry = new StorageProviderRegistry(
      $this->storageConfig,
      \Drupal::service('xedc_core.storage_credentials'),
      \Drupal::service('xedc_core.storage_reader'),
    );
    $cards = [];
    foreach ($registry->operational() as $provider) {
      $cards[] = $registry->card($provider, TRUE);
    }
    return $cards;
  }

  /**
   * @param list<mixed> $sample
   *   Stored sample rows. The stored byte count is left unchanged.
   *
   * @return list<array<string, mixed>>
   *   Display copy.
   */
  private function sampleSizes(array $sample): array {
    $rows = [];
    foreach ($sample as $row) {
      if (!is_array($row)) {
        continue;
      }
      $bytes = $row['bytes'] ?? NULL;
      $row['bytes_exact'] = is_numeric($bytes) ? StorageSizeFormatter::exactLabel($bytes) : '';
      $row['bytes'] = StorageSizeFormatter::formatBytes(is_numeric($bytes) ? (int) $bytes : NULL);
      $rows[] = $row;
    }
    return $rows;
  }

  private function tone(string $status): string {
    return match ($status) {
      'passed', 'supported', 'prechecked', 'inventoried', 'probed', 'ok' => 'ok',
      'partial', 'pending_credentials', 'incomplete', 'unverified', 'blocked', 'warn' => 'warn',
      'failed', 'unsupported', 'bad' => 'bad',
      default => 'muted',
    };
  }

}
