<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_tags\TagLabels;
use Drupal\xedc_tags\TagTaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Enqueue and monitor tag analysis tasks.
 */
final class TaskForm extends AdminFormBase {

  public function __construct(
    private TagTaskManager $tasks,
    private TagLabels $labels,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.tasks'),
      $container->get('xedc_tags.labels'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_tasks';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('分析任务'),
      'description' => $this->t('通过队列执行扫描任务，避免阻塞页面请求。'),
    ]);
    $form['intro'] = $this->callout((string) $this->t('大量分析通过队列执行。同一类型任务不能并发。可在 cron 或 drush queue:run xedc_tags_scan 处理。'));

    $form['enqueue_panel'] = $this->panel([
      'type' => [
        '#type' => 'select',
        '#title' => $this->t('任务类型'),
        '#options' => TagTaskManager::TYPES,
        '#required' => TRUE,
      ],
      'confirm' => [
        '#type' => 'checkbox',
        '#title' => $this->t('确认创建任务'),
      ],
      'enqueue' => [
        '#type' => 'submit',
        '#value' => $this->t('加入队列'),
        '#submit' => ['::enqueue'],
        '#access' => $this->currentUser()->hasPermission('run xedc tag analysis'),
        '#attributes' => ['class' => ['button--primary']],
      ],
    ], ['xt-form-narrow']);

    $header = [
      $this->t('任务 ID'),
      $this->t('类型'),
      $this->t('状态'),
      $this->t('进度'),
      $this->t('成功/失败'),
      $this->t('说明'),
      $this->t('开始时间'),
      $this->t('完成时间'),
      $this->t('操作'),
    ];
    $table = [
      '#type' => 'table',
      '#attributes' => ['class' => ['xt-table']],
      '#caption' => $this->t('最近任务'),
      '#header' => $header,
      '#empty' => $this->t('还没有任务。'),
    ];
    foreach ($this->tasks->list(40) as $row) {
      $id = (int) $row->id;
      $table[$id]['id'] = ['#plain_text' => (string) $id];
      $table[$id]['type'] = ['#plain_text' => (string) $this->labels->taskType((string) $row->type)];
      $table[$id]['status'] = ['#markup' => $this->badge((string) $this->labels->status((string) $row->status), $this->labels->statusTone((string) $row->status))];
      $table[$id]['progress'] = ['#markup' => '<span class="xt-num">' . $row->processed . '/' . $row->total . '</span>'];
      $table[$id]['counts'] = ['#markup' => '<span class="xt-num">' . $row->success . '/' . $row->failed . '</span>'];
      $table[$id]['message'] = ['#plain_text' => (string) ($row->message !== '' ? $row->message : '—')];
      $table[$id]['started'] = ['#plain_text' => $this->date((int) $row->started)];
      $table[$id]['finished'] = ['#plain_text' => $this->date((int) $row->finished)];
      $ops = ['#type' => 'container', '#attributes' => ['class' => ['xt-ops']]];
      $hasOps = FALSE;
      if (in_array($row->status, ['pending', 'running'], TRUE)) {
        $ops['cancel'] = [
          '#type' => 'submit',
          '#value' => $this->t('取消'),
          '#name' => 'cancel_' . $id,
          '#task_id' => $id,
          '#submit' => ['::cancel'],
          '#limit_validation_errors' => [],
        ];
        $hasOps = TRUE;
      }
      if ($row->status === 'running') {
        $ops['pause'] = [
          '#type' => 'submit',
          '#value' => $this->t('暂停'),
          '#name' => 'pause_' . $id,
          '#task_id' => $id,
          '#submit' => ['::pause'],
          '#limit_validation_errors' => [],
        ];
        $hasOps = TRUE;
      }
      if ($row->status === 'paused') {
        $ops['resume'] = [
          '#type' => 'submit',
          '#value' => $this->t('继续'),
          '#name' => 'resume_' . $id,
          '#task_id' => $id,
          '#submit' => ['::resume'],
          '#limit_validation_errors' => [],
        ];
        $hasOps = TRUE;
      }
      if (in_array($row->status, ['failed', 'partial', 'cancelled'], TRUE)) {
        $ops['retry'] = [
          '#type' => 'submit',
          '#value' => $this->t('重试同类'),
          '#name' => 'retry_' . $id,
          '#task_type' => (string) $row->type,
          '#submit' => ['::retry'],
          '#limit_validation_errors' => [],
        ];
        $hasOps = TRUE;
      }
      $table[$id]['ops'] = $hasOps ? $ops : ['#markup' => '—'];
    }
    $form['tasks'] = $this->tableCard($table);
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#submit'][0] ?? '') === '::enqueue' && !$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('请先确认。'));
    }
  }

  public function enqueue(array &$form, FormStateInterface $form_state): void {
    $result = $this->tasks->enqueue((string) $form_state->getValue('type'), [], (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function cancel(array &$form, FormStateInterface $form_state): void {
    $result = $this->tasks->cancel((int) ($form_state->getTriggeringElement()['#task_id'] ?? 0), (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function pause(array &$form, FormStateInterface $form_state): void {
    $result = $this->tasks->pause((int) ($form_state->getTriggeringElement()['#task_id'] ?? 0), (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function resume(array &$form, FormStateInterface $form_state): void {
    $result = $this->tasks->resume((int) ($form_state->getTriggeringElement()['#task_id'] ?? 0), (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function retry(array &$form, FormStateInterface $form_state): void {
    $type = (string) ($form_state->getTriggeringElement()['#task_type'] ?? '');
    $result = $this->tasks->enqueue($type, [], (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
