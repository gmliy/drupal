<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\ImportException;
use Drupal\xedc_core\ImportProcessor;
use Drupal\xedc_core\Publisher;
use Drupal\xedc_core\TempCleaner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms one task action. The route access check still runs first.
 */
class TaskActionForm extends ConfirmFormBase {

  protected int $taskId = 0;

  protected string $op = '';

  public function __construct(
    protected ImportProcessor $processor,
    protected Publisher $publisher,
    protected TempCleaner $cleaner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.processor'),
      $container->get('xedc_core.publisher'),
      $container->get('xedc_core.cleaner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_core_task_action';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $task = 0): array {
    $this->taskId = $task;
    $this->op = match ((string) $this->getRouteMatch()->getRouteName()) {
      'xedc_core.import_publish' => 'publish',
      'xedc_core.import_retry' => 'retry',
      'xedc_core.import_cancel' => 'cancel',
      'xedc_core.import_cleanup' => 'cleanup',
      'xedc_core.import_reroll' => 'reroll',
      'xedc_core.import_rollback' => 'rollback',
      default => '',
    };
    $form_state->set('task_id', $task);
    $form_state->set('op', $this->op);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Stringable|string {
    return match ($this->op) {
      'publish' => $this->t('发布这组图片？'),
      'retry' => $this->t('重试这个导入任务？'),
      'cancel' => $this->t('取消这个导入任务？'),
      'cleanup' => $this->t('清理这个任务的临时文件？正式图片会保留。'),
      'reroll' => $this->t('重新随机选择封面？'),
      'rollback' => $this->t('回滚到替换前的图片？'),
      default => $this->t('确认操作？'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $task = (int) ($this->taskId ?: 0);
    return Url::fromRoute('xedc_core.import_detail', ['task' => $task]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Stringable|string {
    return $this->t('确认');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $taskId = (int) $form_state->get('task_id');
    $op = (string) $form_state->get('op');
    try {
      match ($op) {
        'publish' => $this->publish($taskId),
        'retry' => $this->processor->retry($taskId),
        'cancel' => $this->processor->cancel($taskId),
        'cleanup' => $this->cleanup($taskId),
        'reroll' => $this->publisher->rerollCover($taskId, $this->currentUser()),
        'rollback' => $this->publisher->rollback($taskId, $this->currentUser()),
        default => throw new ImportException('未知操作。'),
      };
      if ($op !== 'publish') {
        $this->messenger()->addStatus($this->t('操作已完成。'));
      }
    }
    catch (ImportException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('操作失败。'));
      \Drupal::logger('xedc_core')->error('@message', ['@message' => $e->getMessage()]);
    }
    $form_state->setRedirect('xedc_core.import_detail', ['task' => $taskId]);
  }

  /**
   * Publishes and reports the per-item result.
   */
  private function publish(int $taskId): void {
    $result = $this->publisher->publish($taskId, $this->currentUser());
    if ($result['ok']) {
      $this->messenger()->addStatus($result['message']);
      return;
    }
    $this->messenger()->addError($result['message']);
  }

  /**
   * Loads the task and deletes only its private temporary files.
   */
  private function cleanup(int $taskId): void {
    $task = \Drupal::service('xedc_core.tasks')->load($taskId);
    if (!$task) {
      throw new ImportException('任务不存在。');
    }
    $this->cleaner->manual($task);
  }

}
