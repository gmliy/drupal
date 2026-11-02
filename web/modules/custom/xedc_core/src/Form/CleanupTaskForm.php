<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\Ops\Cleanup\FileCleanupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Second confirmation for one bound cleanup task.
 */
class CleanupTaskForm extends FormBase {

  public function __construct(
    protected FileCleanupManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.cleanup_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    $task = (int) (\Drupal::routeMatch()->getParameter('task') ?? 0);
    $op = (string) (\Drupal::routeMatch()->getRouteObject()?->getDefault('op') ?? 'confirm');
    return 'xedc_cleanup_' . $op . '_' . $task;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $op = (string) (\Drupal::routeMatch()->getRouteObject()?->getDefault('op') ?? 'confirm');
    $form['#attributes']['class'][] = 'xedc-ops-form';
    $form['op'] = ['#type' => 'value', '#value' => $op];
    $form['task'] = ['#type' => 'value', '#value' => (int) \Drupal::routeMatch()->getParameter('task')];
    if ($op === 'confirm') {
      $form['ack'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('我已核对上面的文件、引用和风险，确认执行这一项任务'),
        '#required' => TRUE,
      ];
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('确认执行'),
        '#attributes' => ['class' => ['xedc-ops-danger']],
      ];
    }
    elseif ($op === 'cancel') {
      $form['submit'] = ['#type' => 'submit', '#value' => $this->t('取消任务')];
    }
    elseif ($op === 'purge_plan') {
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('生成永久清理方案'),
        '#attributes' => ['class' => ['xedc-ops-danger']],
      ];
    }
    elseif ($op === 'restore_plan') {
      $form['submit'] = ['#type' => 'submit', '#value' => $this->t('生成恢复方案')];
    }
    else {
      $form['submit'] = ['#type' => 'submit', '#value' => $this->t('继续处理剩余文件')];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $task = (int) $form_state->getValue('task');
    $op = (string) $form_state->getValue('op');
    $uid = (int) $this->currentUser()->id();
    if ($op === 'purge_plan' || $op === 'restore_plan') {
      $permission = $op === 'purge_plan' ? 'confirm xedc file purge' : 'restore xedc quarantined files';
      if (!$this->currentUser()->hasPermission($permission)) {
        throw new AccessDeniedHttpException();
      }
      $result = $this->manager->createFollowUp($task, $op === 'purge_plan' ? 'purge' : 'restore', $uid);
      foreach ($result['errors'] as $error) {
        $this->messenger()->addError($error);
      }
      if ($result['ok']) {
        $this->messenger()->addStatus($this->t('已生成新的待审核方案，尚未执行。'));
        $form_state->setRedirectUrl(Url::fromRoute('xedc_core.files_task', ['task' => $result['task_id']]));
      }
      return;
    }
    if ($op === 'cancel') {
      $this->messenger()->addStatus($this->manager->cancel($task, $uid));
    }
    elseif ($op === 'continue') {
      $result = $this->manager->resume($task, $uid);
      $this->messenger()->addStatus($result['message']);
      if ($result['ok']) {
        $this->manager->step($task);
      }
    }
    else {
      if (!(int) $form_state->getValue('ack')) {
        $this->messenger()->addError($this->t('需要勾选确认。'));
        return;
      }
      $result = $this->manager->confirm($task, $uid);
      if ($result['ok']) {
        $this->manager->step($task);
        $this->messenger()->addStatus($result['message']);
      }
      else {
        $this->messenger()->addError($result['message']);
      }
    }
    $form_state->setRedirectUrl(Url::fromRoute('xedc_core.files_task', ['task' => $task]));
  }

}
