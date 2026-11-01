<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\ImportStatus;
use Drupal\xedc_core\Publisher;
use Drupal\xedc_core\TaskRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Task list, filters and bulk publish.
 */
class ImportListForm extends FormBase {

  public function __construct(
    protected TaskRepository $tasks,
    protected Publisher $publisher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.tasks'),
      $container->get('xedc_core.publisher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_core_import_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $filter = (string) $this->getRequest()->query->get('status', 'all');
    if (!isset(ImportStatus::filters()[$filter])) {
      $filter = 'all';
    }
    $account = $this->currentUser();
    $uid = $account->hasPermission('administer xedc image imports') ? NULL : (int) $account->id();
    $rows = $this->tasks->list($filter, $uid, 50);
    $canPublish = $account->hasPermission('publish xedc image imports') || $account->hasPermission('administer xedc image imports');

    $form['#attributes']['class'][] = 'xedc-import';
    $form['#attached']['library'][] = 'xedc_core/admin';
    $form['#cache']['max-age'] = 0;

    $links = [];
    foreach (ImportStatus::filters() as $id => $label) {
      $links[] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('xedc_core.import_list', [], ['query' => ['status' => $id]]),
        '#attributes' => ['class' => [$id === $filter ? 'is-active' : '']],
      ];
    }
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import__filters']],
      'links' => $links,
    ];

    $header = [
      'id' => $this->t('任务'),
      'title' => $this->t('内容标题'),
      'user' => $this->t('创建者'),
      'archive' => $this->t('压缩包'),
      'counts' => $this->t('图片'),
      'status' => $this->t('状态'),
      'progress' => $this->t('进度'),
      'created' => $this->t('创建时间'),
      'changed' => $this->t('更新时间'),
      'ops' => $this->t('操作'),
    ];
    $table = [];
    foreach ($rows as $row) {
      $id = (int) $row->id;
      $detail = Url::fromRoute('xedc_core.import_detail', ['task' => $id]);
      $table[$id] = [
        'id' => ['data' => ['#type' => 'link', '#title' => '#' . $id, '#url' => $detail]],
        'title' => ['data' => ['#plain_text' => (string) ($row->node_title ?: $this->t('内容已删除'))]],
        'user' => ['data' => ['#plain_text' => (string) ($row->user_name ?: $row->uid)]],
        'archive' => ['data' => ['#plain_text' => (string) $row->archive_name]],
        'counts' => ['data' => ['#plain_text' => (int) $row->total . ' / ' . (int) $row->success_count . ' / ' . (int) $row->failed_count]],
        'status' => ['data' => ['#markup' => '<span class="xedc-import-badge xedc-import-badge--' . htmlspecialchars((string) $row->status, ENT_QUOTES) . '">' . htmlspecialchars(ImportStatus::label((string) $row->status), ENT_QUOTES) . '</span>']],
        'progress' => [
          'data' => [
            '#markup' => '<div class="xedc-import__bar"><span style="width:' . (int) $row->progress . '%"></span></div>',
          ],
        ],
        'created' => ['data' => ['#plain_text' => date('Y-m-d H:i', (int) $row->created)]],
        'changed' => ['data' => ['#plain_text' => date('Y-m-d H:i', (int) $row->changed)]],
        'ops' => ['data' => ['#type' => 'link', '#title' => $this->t('查看'), '#url' => $detail]],
      ];
    }
    $form['tasks'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $table,
      '#empty' => $this->t('没有任务。'),
      '#prefix' => '<div class="xedc-import__table-wrap">',
      '#suffix' => '</div>',
    ];
    if ($canPublish) {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['publish'] = [
        '#type' => 'submit',
        '#value' => $this->t('发布所选'),
        '#button_type' => 'primary',
      ];
    }
    $form['note'] = [
      '#markup' => '<p>' . $this->t('图片数量列依次为总数、成功数、失败数。只有“待审核”的任务可以勾选发布。') . '</p>',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter(array_map('intval', (array) $form_state->getValue('tasks')));
    if (!$selected) {
      $this->messenger()->addWarning($this->t('请先勾选任务。'));
      return;
    }
    $ok = 0;
    $failed = 0;
    foreach ($selected as $taskId) {
      $result = $this->publisher->publish($taskId, $this->currentUser());
      if ($result['ok']) {
        $ok++;
        $this->messenger()->addStatus($this->t('任务 #@id：@message', [
          '@id' => $taskId,
          '@message' => $result['message'],
        ]));
      }
      else {
        $failed++;
        $this->messenger()->addError($this->t('任务 #@id：@message', [
          '@id' => $taskId,
          '@message' => $result['message'],
        ]));
      }
    }
    $this->messenger()->addStatus($this->t('批量发布结束。成功 @ok 条，失败 @failed 条。', [
      '@ok' => $ok,
      '@failed' => $failed,
    ]));
  }

}
