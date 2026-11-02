<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Storage\MigrationExecutor;
use Drupal\xedc_core\Ops\Storage\MigrationPlanner;
use Drupal\xedc_core\Ops\Storage\StorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a read-only migration precheck.
 */
class StorageMigrationForm extends FormBase {

  public function __construct(
    protected StorageConfig $storageConfig,
    protected MigrationPlanner $planner,
    protected MigrationExecutor $executor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.storage_config'),
      $container->get('xedc_core.storage_migration'),
      $container->get('xedc_core.storage_migration_executor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_storage_migration';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $targets = [];
    foreach ($this->storageConfig->profiles() as $profile) {
      $targets[$profile['id']] = $profile['label'] . ' (' . $profile['id'] . ')';
    }
    $form['source'] = [
      '#type' => 'select',
      '#title' => '来源',
      '#options' => [
        'local' => 'Drupal 本地公开和私有文件',
        'public' => '仅 public://',
        'private' => '仅 private://',
        's3' => '已经使用 s3:// 的文件',
      ],
      '#default_value' => 'local',
    ];
    $form['target'] = [
      '#type' => 'select',
      '#title' => '目标供应商配置',
      '#options' => $targets,
      '#empty_option' => '选择目标',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => 'precheck',
      '#value' => '执行预检查',
    ];
    $form['actions']['inventory'] = [
      '#type' => 'submit',
      '#name' => 'inventory',
      '#value' => '生成完整清单',
      '#attributes' => ['class' => ['xedc-ops-button']],
    ];
    foreach ($this->planner->recent(10) as $task) {
      if (!in_array($task['status'], ['prechecked', 'blocked'], TRUE)) {
        continue;
      }
      $id = (string) $task['id'];
      $form['cancel'][$id] = [
        '#type' => 'submit',
        '#name' => 'cancel__' . $id,
        '#value' => '取消 ' . $id,
        '#attributes' => ['class' => ['xedc-ops-button']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = (string) ($form_state->getTriggeringElement()['#name'] ?? 'precheck');
    if (str_starts_with($name, 'cancel__')) {
      $error = $this->planner->cancel(substr($name, 8));
      if ($error) {
        $this->messenger()->addError($error);
        return;
      }
      $this->messenger()->addStatus('迁移任务已取消，没有改动文件。');
      return;
    }
    $task = $this->planner->precheck(
      (string) $form_state->getValue('source'),
      (string) $form_state->getValue('target'),
    );
    if (($task['status'] ?? '') !== 'prechecked') {
      $this->messenger()->addError((string) $task['message']);
      return;
    }
    if ($name !== 'inventory') {
      $this->messenger()->addStatus((string) $task['message']);
      return;
    }
    $listed = $this->executor->inventory((string) $task['id']);
    if (($listed['status'] ?? '') !== 'inventoried') {
      $this->messenger()->addError((string) ($listed['message'] ?? '清单没有生成。没有复制文件。'));
      return;
    }
    $probed = $this->executor->probeTargets((string) $task['id']);
    $remote = is_array($probed['remote'] ?? NULL) ? $probed['remote'] : [];
    $this->messenger()->addStatus((string) ($probed['message'] ?? '清单已生成。'));
    $this->messenger()->addStatus('目标不存在 ' . (int) ($remote['absent'] ?? 0) . '，哈希一致 ' . (int) ($remote['match'] ?? 0) . '，不一致 ' . (int) ($remote['differ'] ?? 0) . '。没有开始复制。');
  }

}
