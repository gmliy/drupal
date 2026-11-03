<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\xedc_core\Ops\Storage\RuntimeState;
use Drupal\xedc_core\Ops\Storage\StorageSwitchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a real public-storage switch after a fresh consistency check.
 */
class StorageSwitchForm extends FormBase {

  public function __construct(
    private StorageSwitchManager $switcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(new StorageSwitchManager(
      $container->get('xedc_core.storage_config'),
      $container->get('xedc_core.storage_credentials'),
      $container->get('xedc_core.storage_reader'),
      $container->get('xedc_core.storage_writer'),
      $container->get('xedc_core.storage_journal'),
      $container->get('database'),
      $container->get('state'),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_storage_switch';
  }

  public function access(AccountInterface $account, $return_as_object = FALSE): AccessResultInterface|bool {
    $result = AccessResult::allowedIf(
      $account->hasPermission('administer xedc object storage')
      || $account->hasPermission('switch xedc storage mode')
    )->cachePerPermissions();
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $state = RuntimeState::read();
    $form['current'] = [
      '#markup' => '<p>当前实际模式是 <strong>' . $this->label((string) $state['public_mode']) . '</strong>，版本 ' . (int) $state['version'] . '。</p>',
    ];
    $form['target'] = [
      '#type' => 'radios',
      '#title' => '目标供应商',
      '#options' => [
        'local' => '本地文件系统',
        'silly' => 'Cloudflare R2（silly）',
      ],
      '#default_value' => (string) $state['public_mode'] === 'local' ? 'silly' : 'local',
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['inspect'] = [
      '#type' => 'submit',
      '#name' => 'inspect',
      '#value' => '检查文件一致性',
      '#submit' => ['::inspect'],
    ];
    $report = $form_state->get('report');
    if (is_array($report)) {
      $lines = [];
      foreach (array_slice((array) ($report['missing_on_local'] ?? []), 0, 20) as $item) {
        if (is_array($item) && isset($item['key'])) {
          $lines[] = 'R2 有、本地没有：' . $item['key'];
        }
      }
      foreach (array_slice((array) ($report['missing_on_remote'] ?? []), 0, 20) as $item) {
        if (is_array($item) && isset($item['key'])) {
          $lines[] = '本地有、R2 没有：' . $item['key'];
        }
      }
      $form['report'] = [
        '#markup' => '<p>需要的公开文件 ' . (int) $report['required'] . ' 个。两边都有 ' . (int) $report['both'] . ' 个，仅本地 ' . (int) $report['local_only'] . ' 个，仅 R2 ' . (int) $report['remote_only'] . ' 个，两边都没有 ' . (int) $report['required_missing_both'] . ' 个，大小不一致 ' . (int) $report['size_mismatch'] . ' 个。检查按文件大小比较。SHA-256 在复制时对实际复制的文件计算，不在这次只读检查里下载全部对象。</p>'
          . ($lines === [] ? '' : '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $lines)) . '</li></ul>'),
      ];
    }
    if (is_array($report) && (int) ($report['required_missing_both'] ?? 1) === 0) {
      $form['confirm'] = [
        '#type' => 'textfield',
        '#title' => '确认文字',
        '#description' => '输入“' . RuntimeState::CONFIRMATION . '”。这次操作会把目标侧缺失的公开原件复制过去，然后更新所有进程读取的运行状态。不删除源文件，不删除 R2 对象，不迁移图片样式和私有文件。',
      ];
      $form['actions']['execute'] = [
        '#type' => 'submit',
        '#name' => 'execute',
        '#value' => '确认并切换',
        '#submit' => ['::executeSwitch'],
        '#attributes' => ['class' => ['button--danger']],
      ];
    }
    if (!empty($state['writes_paused'])) {
      $form['actions']['resume'] = [
        '#type' => 'submit',
        '#name' => 'resume',
        '#value' => '恢复公开写入',
        '#submit' => ['::resume'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  public function inspect(array &$form, FormStateInterface $form_state): void {
    if (!$this->allowed()) {
      $this->messenger()->addError('没有执行存储切换的权限。');
      return;
    }
    $report = $this->switcher->inspect();
    $form_state->set('report', $report);
    $form_state->set('token', (string) ($report['token'] ?? ''));
    $form_state->setRebuild(TRUE);
    $this->messenger()->addStatus('检查完成。还没有复制文件，也没有切换。');
  }

  public function executeSwitch(array &$form, FormStateInterface $form_state): void {
    if (!$this->allowed()) {
      $this->messenger()->addError('没有执行存储切换的权限。');
      return;
    }
    $result = $this->switcher->execute(
      (string) $form_state->getValue('target'),
      trim((string) $form_state->getValue('confirm')),
      (string) $form_state->get('token'),
      (int) $this->currentUser()->id(),
    );
    if (!empty($result['ok'])) {
      $this->messenger()->addStatus((string) $result['message']);
      return;
    }
    $this->messenger()->addError((string) $result['message']);
    $form_state->setRebuild(TRUE);
  }

  public function resume(array &$form, FormStateInterface $form_state): void {
    if (!$this->allowed()) {
      $this->messenger()->addError('没有执行存储切换的权限。');
      return;
    }
    $path = \Drupal\Core\Site\Settings::get('file_private_path');
    $ok = RuntimeState::resumeWrites(is_string($path) ? $path : NULL);
    if ($ok) {
      $this->messenger()->addStatus('公开写入已恢复。');
      return;
    }
    $this->messenger()->addError('切换锁还在，没有恢复写入。');
  }

  private function allowed(): bool {
    return $this->currentUser()->hasPermission('administer xedc object storage')
      || $this->currentUser()->hasPermission('switch xedc storage mode');
  }

  private function label(string $mode): string {
    return match ($mode) {
      'local' => '本地文件系统',
      'cloudflare_r2' => 'Cloudflare R2',
      default => '跟随环境变量',
    };
  }

}
