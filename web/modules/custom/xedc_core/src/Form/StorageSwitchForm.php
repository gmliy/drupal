<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\xedc_core\Ops\Storage\BootGuard;
use Drupal\xedc_core\Ops\Storage\RuntimeState;
use Drupal\xedc_core\Ops\Storage\StorageSizeFormatter;
use Drupal\xedc_core\Ops\Storage\StorageSwitchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a real public-storage switch after a fresh consistency check.
 *
 * The manager property stays protected. Form cache serialization runs from
 * FormBase and cannot see a private property, which previously left it
 * uninitialized on submit.
 */
class StorageSwitchForm extends FormBase {

  public function __construct(
    protected StorageSwitchManager $switcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.storage_switch'));
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
    $current = (string) $state['public_mode'];
    $report = $form_state->get('report');
    $report = is_array($report) ? $report : NULL;
    $form['#attributes']['class'][] = 'xedc-ops-switch';
    $form['#attached']['library'][] = 'xedc_core/storage_switch';
    $form['steps'] = [
      '#markup' => '<ol class="xedc-ops-switch-steps"><li>选择目标存储</li><li>检查文件一致性</li><li>查看检查结果</li><li>确认并执行切换</li><li>查看切换结果</li></ol><p class="xedc-ops-note">检查只读取文件和对象清单，不会复制文件，也不会改变当前运行模式。只有「确认并执行切换」会复制目标侧缺失的公开原件，并在复制校验通过后更新运行状态。</p>',
    ];
    $form['current'] = [
      '#type' => 'item',
      '#title' => '当前运行模式',
      '#markup' => '<strong>' . $this->label($current) . '</strong>（' . $current . ' / ' . ($state['public_provider'] !== '' ? (string) $state['public_provider'] : '未设置') . '，版本 ' . (int) $state['version'] . '，' . (string) $state['status'] . '）',
    ];
    $form['target'] = [
      '#type' => 'radios',
      '#title' => '本次选择的目标模式',
      '#options' => [
        'local' => '本地存储',
        'silly' => 'Cloudflare R2',
      ],
      '#default_value' => $current === 'local' ? 'silly' : 'local',
      '#required' => TRUE,
    ];
    if ($report !== NULL) {
      $checked = (string) ($report['target_label'] ?? '');
      $done = !empty($report['allowed']) ? '已通过' : '未通过';
      $form['checked'] = [
        '#type' => 'item',
        '#title' => '是否已经完成检查',
        '#markup' => $done . '。这次检查的目标是 ' . $checked . '。',
      ];
    }
    else {
      $form['checked'] = [
        '#type' => 'item',
        '#title' => '是否已经完成检查',
        '#markup' => '还没有检查。',
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['inspect'] = [
      '#type' => 'submit',
      '#name' => 'inspect',
      '#value' => '检查文件一致性',
      '#submit' => ['::inspect'],
      '#button_type' => 'primary',
    ];
    if ($report !== NULL) {
      $form['actions']['recheck'] = [
        '#type' => 'submit',
        '#name' => 'recheck',
        '#value' => '重新检查',
        '#submit' => ['::inspect'],
      ];
      $form['actions']['cancel'] = [
        '#type' => 'submit',
        '#name' => 'cancel',
        '#value' => '取消操作',
        '#submit' => ['::cancel'],
        '#limit_validation_errors' => [],
      ];
      $form['summary'] = [
        '#markup' => '<p class="xedc-ops-note">公开前缀 <code>' . htmlspecialchars((string) ($report['prefix'] ?? '')) . '/</code>，当前前缀对象 ' . (int) ($report['active_objects'] ?? 0) . ' 个。旧前缀 <code>' . htmlspecialchars((string) ($report['legacy_prefix'] ?? '')) . '/</code> 对象 ' . (int) ($report['legacy_objects'] ?? 0) . ' 个，只用于识别和必要时下载，不会被移动或删除。需要复制 ' . (int) ($report['copy_count'] ?? 0) . ' 个。进程用户 ' . htmlspecialchars((string) ($report['process_user'] ?? '')) . '。' . htmlspecialchars((string) ($report['language_note'] ?? '')) . '</p><p class="xedc-ops-note">' . htmlspecialchars((string) ($report['private_note'] ?? '')) . '</p>',
      ];
      $rows = [];
      foreach ((array) ($report['rows'] ?? []) as $row) {
        if (!is_array($row)) {
          continue;
        }
        $rows[] = [
          ['data' => (string) ($row['item'] ?? '')],
          ['data' => (string) ($row['result'] ?? ''), 'class' => ['is-' . (string) ($row['tone'] ?? 'ok')]],
        ];
      }
      $form['results'] = [
        '#type' => 'table',
        '#header' => ['检查项目', '结果'],
        '#rows' => $rows,
        '#attributes' => ['class' => ['xedc-ops-table']],
        '#empty' => '没有检查结果。',
      ];
      $problems = [];
      foreach ((array) ($report['problems'] ?? []) as $problem) {
        if (!is_array($problem)) {
          continue;
        }
        $problems[] = [
          (string) ($problem['uri'] ?? ''),
          (string) ($problem['reason'] ?? ''),
        ];
      }
      $pending = [];
      foreach ((array) ($report['pending'] ?? []) as $item) {
        if (!is_array($item)) {
          continue;
        }
        $bytes = $item['bytes'] ?? NULL;
        $label = (string) (($item['bytes_label'] ?? '') !== '' ? $item['bytes_label'] : StorageSizeFormatter::formatBytes($bytes));
        $pending[] = [
          (string) ($item['action'] ?? '') === 'upload' ? '上传到当前前缀' : '下载到本地',
          (string) ($item['key'] ?? ''),
          (string) ($item['source_key'] ?? ''),
          [
            'data' => [
              '#markup' => '<span title="' . htmlspecialchars(StorageSizeFormatter::exactLabel($bytes), ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</span>',
            ],
          ],
        ];
      }
      if ($pending !== []) {
        $form['pending'] = [
          '#type' => 'table',
          '#caption' => '执行切换时会复制这些公开原件。检查本身不会复制。',
          '#header' => ['动作', '目标对象键', '来源对象键', '大小'],
          '#rows' => array_slice($pending, 0, 40),
          '#attributes' => ['class' => ['xedc-ops-table']],
        ];
      }
      if ($problems !== []) {
        $form['problems'] = [
          '#type' => 'table',
          '#caption' => '不能静默跳过的文件（最多 40 条，共 ' . (int) ($report['problem_count'] ?? count($problems)) . ' 条）',
          '#header' => ['文件 URI', '原因'],
          '#rows' => $problems,
          '#attributes' => ['class' => ['xedc-ops-table']],
        ];
      }
      $selected = (string) $form_state->getValue('target');
      $matches = $selected !== '' && $selected === (string) ($report['target'] ?? '');
      if (!empty($report['allowed']) && $matches && (string) $form_state->get('token') !== '') {
        $form['confirm'] = [
          '#type' => 'textfield',
          '#title' => '确认文字',
          '#description' => '输入“' . RuntimeState::CONFIRMATION . '”。执行时会把目标侧缺失的公开原件复制过去，再更新运行状态。不删除源文件，不删除 R2 对象，不迁移图片样式和私有文件。SHA-256 只计算这次实际复制的文件。令牌 15 分钟内有效，且只对本次目标生效。',
        ];
        $form['actions']['execute'] = [
          '#type' => 'submit',
          '#name' => 'execute',
          '#value' => '确认并执行切换',
          '#submit' => ['::executeSwitch'],
          '#attributes' => ['class' => ['button--danger']],
          '#button_type' => 'danger',
        ];
      }
      elseif (empty($report['allowed'])) {
        $form['blocked'] = [
          '#markup' => '<p class="xedc-ops-note">检查未通过，禁止切换。' . htmlspecialchars((string) ($report['block_reason'] ?? '')) . '</p>',
        ];
      }
      elseif (!$matches) {
        $form['blocked'] = [
          '#markup' => '<p class="xedc-ops-note">目标模式已经改变，请重新检查。当前不能执行切换。</p>',
        ];
      }
    }
    if (!empty($state['writes_paused'])) {
      $form['actions']['resume'] = [
        '#type' => 'submit',
        '#name' => 'resume',
        '#value' => '恢复公开写入',
        '#submit' => ['::resume'],
        '#limit_validation_errors' => [],
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
    $target = (string) $form_state->getValue('target');
    if (!in_array($target, ['local', 'silly'], TRUE)) {
      $this->messenger()->addError('请选择本地存储或 Cloudflare R2。没有检查。');
      return;
    }
    try {
      $report = $this->switcher->inspect($target);
    }
    catch (\Throwable $error) {
      $this->switcher->clearCheck();
      $form_state->set('report', NULL);
      $form_state->set('token', '');
      $this->messenger()->addError('检查失败：' . BootGuard::safeError($error));
      $form_state->setRebuild(TRUE);
      return;
    }
    $form_state->set('report', $report);
    $form_state->set('token', (string) ($report['token'] ?? ''));
    $form_state->setRebuild(TRUE);
    if (!empty($report['allowed'])) {
      $this->messenger()->addStatus('检查完成，可以执行切换。还没有复制文件，也没有切换。');
      return;
    }
    $this->messenger()->addWarning('检查完成，但当前禁止切换。' . (string) ($report['block_reason'] ?? ''));
  }

  public function cancel(array &$form, FormStateInterface $form_state): void {
    if (!$this->allowed()) {
      $this->messenger()->addError('没有执行存储切换的权限。');
      return;
    }
    $this->switcher->clearCheck();
    $form_state->set('report', NULL);
    $form_state->set('token', '');
    $form_state->setRebuild(TRUE);
    $this->messenger()->addStatus('已取消。没有复制文件，也没有切换。');
  }

  public function executeSwitch(array &$form, FormStateInterface $form_state): void {
    if (!$this->allowed()) {
      $this->messenger()->addError('没有执行存储切换的权限。');
      return;
    }
    $report = $form_state->get('report');
    if (!is_array($report) || empty($report['allowed'])) {
      $this->messenger()->addError('还没有通过检查，没有切换。');
      $form_state->setRebuild(TRUE);
      return;
    }
    if ((string) $form_state->getValue('target') !== (string) ($report['target'] ?? '')) {
      $this->messenger()->addError('检查结果与目标模式不一致，请重新检查。没有切换。');
      $form_state->setRebuild(TRUE);
      return;
    }
    $result = $this->switcher->execute(
      (string) $form_state->getValue('target'),
      trim((string) $form_state->getValue('confirm')),
      (string) $form_state->get('token'),
      (int) $this->currentUser()->id(),
    );
    if (!empty($result['ok'])) {
      $form_state->set('report', NULL);
      $form_state->set('token', '');
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
      'local' => '本地存储',
      'cloudflare_r2' => 'Cloudflare R2',
      default => '跟随环境变量',
    };
  }

}
