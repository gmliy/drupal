<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\xedc_core\Ops\Storage\StorageSizeFormatter;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\ImportStatus;
use Drupal\xedc_core\TaskRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Task detail, preview and progress.
 */
class ImportController extends ControllerBase {

  public function __construct(
    protected TaskRepository $tasks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.tasks'));
  }

  /**
   * Detail page for one task.
   */
  public function detail(int $task): array {
    $row = $this->tasks->load($task);
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $node = $this->entityTypeManager()->getStorage('node')->load($row->nid);
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import']],
      '#attached' => ['library' => ['xedc_core/admin']],
      '#cache' => ['max-age' => 0],
    ];
    $build['back'] = Link::fromTextAndUrl($this->t('返回列表'), Url::fromRoute('xedc_core.import_list'))->toRenderable();
    $build['summary'] = [
      '#theme' => 'item_list',
      '#items' => $this->summary($row, $node instanceof NodeInterface ? $node : NULL),
    ];
    if (!ImportStatus::isTerminal((string) $row->status)) {
      $build['progress'] = $this->progressElement($row);
    }
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import__actions']],
    ];
    foreach ($this->actionLinks($row, $node instanceof NodeInterface ? $node : NULL) as $link) {
      $build['actions'][] = $link;
    }
    $build['preview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import-grid']],
    ];
    $shown = 0;
    foreach ($this->tasks->items((int) $row->id) as $item) {
      if ($shown >= 180) {
        break;
      }
      $build['preview'][] = $this->preview($item);
      $shown++;
    }
    $logs = [];
    foreach ($this->tasks->logs((int) $row->id) as $log) {
      $logs[] = date('Y-m-d H:i:s', (int) $log->created) . ' [' . $log->level . '] ' . $log->message;
    }
    $build['logs'] = [
      '#type' => 'details',
      '#title' => $this->t('处理日志'),
      '#open' => TRUE,
      'list' => [
        '#theme' => 'item_list',
        '#items' => $logs ?: [$this->t('暂无日志')],
      ],
    ];
    if ($row->error) {
      $build['error'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        'text' => ['#plain_text' => (string) $row->error],
      ];
    }
    return $build;
  }

  /**
   * Lightweight task list sync for the upload center table.
   */
  public function sync(): JsonResponse {
    $account = $this->currentUser();
    $uid = $account->hasPermission('administer xedc image imports') ? NULL : (int) $account->id();
    $rows = $this->tasks->list('all', $uid, 40, 0, '', 'new');
    $tasks = [];
    foreach ($rows as $row) {
      $done = ImportStatus::isTerminal((string) $row->status);
      $live = trim((string) ($row->phase ?? ''));
      $phase = ImportStatus::phase((string) $row->status, $live !== '' ? $live : (string) $row->stage);
      $total = (int) $row->total;
      $processed = (int) $row->processed;
      $percent = $done ? 100 : ($total > 0 ? (int) floor($processed / $total * 100) : (int) $row->progress);
      $tasks[] = [
        'id' => (int) $row->id,
        'status' => (string) $row->status,
        'label' => $phase['label'],
        'progress' => $percent,
        'success' => (int) $row->success_count,
        'failed' => (int) $row->failed_count,
        'skipped' => (int) $row->skipped_count,
        'detail' => $this->t('成功 @ok · 失败 @bad', [
          '@ok' => (int) $row->success_count,
          '@bad' => (int) $row->failed_count,
        ])->__toString(),
        'done' => $done,
      ];
    }
    return new JsonResponse([
      'tasks' => $tasks,
      'updated' => time(),
    ]);
  }

  /**
   * JSON progress for the admin page.
   */
  public function progress(int $task): JsonResponse {
    $row = $this->tasks->load($task);
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $items = [];
    foreach ($this->tasks->items($task) as $item) {
      $items[] = [
        'id' => (int) $item->id,
        'name' => (string) ($item->original_name ?? ''),
        'status' => (string) $item->status,
        'label' => $this->itemLabel((string) $item->status, (int) ($item->reused ?? 0)),
        'bytes' => isset($item->original_bytes) ? (int) $item->original_bytes : 0,
        'error' => (string) ($item->error ?? ''),
      ];
    }
    $metrics = $this->progressMetrics($row);
    $done = ImportStatus::isTerminal((string) $row->status);
    $live = trim((string) ($row->phase ?? ''));
    $phase = ImportStatus::phase((string) $row->status, $live !== '' ? $live : (string) $row->stage);
    $reusedItems = 0;
    foreach ($items as $item) {
      if (($item['label'] ?? '') === '文件已存在，已复用') {
        $reusedItems++;
      }
    }
    if ($done && (string) $row->source === 'images' && $reusedItems > 0 && $reusedItems === (int) $row->success_count && (int) $row->failed_count === 0) {
      $phase = ['id' => 'duplicate', 'label' => '文件已存在，已复用'];
    }
    $total = (int) $row->total;
    $processed = (int) $row->processed;
    $percent = $done ? 100 : ($total > 0 ? (int) floor($processed / $total * 100) : (int) $row->progress);
    return new JsonResponse([
      'id' => (int) $row->id,
      'status' => (string) $row->status,
      'stage' => (string) $row->stage,
      'phase' => $phase['id'],
      'label' => $phase['label'],
      'progress' => $percent,
      'percent' => $percent,
      'total' => $total,
      'processed' => $processed,
      'success' => (int) $row->success_count,
      'failed' => (int) $row->failed_count,
      'skipped' => (int) $row->skipped_count,
      'current' => $metrics['current'],
      'archive_bytes' => (int) ($row->archive_bytes ?? 0),
      'archive_label' => $this->formatBytes((int) ($row->archive_bytes ?? 0)),
      'done_bytes' => $metrics['done_bytes'],
      'done_label' => $this->formatBytes($metrics['done_bytes']),
      'eta_seconds' => $metrics['eta_seconds'],
      'updated' => (int) $row->changed,
      'message' => (string) $row->message,
      'error' => (string) $row->error,
      'done' => $done,
      'detail' => $this->progressDetail($row, $phase['label'], $percent, $metrics),
      'items' => $items,
    ]);
  }

  /**
   * Real counters from item rows. ETA stays null until five measured images exist.
   *
   * @return array{done_bytes: int, eta_seconds: int|null, current: int}
   *   Measured progress.
   */
  private function progressMetrics(object $row): array {
    $current = ImportStatus::isTerminal((string) $row->status) ? 0 : (in_array((string) $row->status, ImportStatus::processing(), TRUE) ? 1 : 0);
    $doneBytes = 0;
    $eta = NULL;
    $database = \Drupal::database();
    $schema = $database->schema();
    if (!$schema->fieldExists('xedc_import_item', 'elapsed_ms')) {
      return ['done_bytes' => $doneBytes, 'eta_seconds' => $eta, 'current' => $current];
    }
    $stats = $database->query("SELECT COALESCE(SUM(CASE WHEN status IN ('success', 'failed') THEN GREATEST(original_bytes, bytes) ELSE 0 END), 0) AS done_bytes, COALESCE(SUM(CASE WHEN elapsed_ms > 0 THEN elapsed_ms ELSE 0 END), 0) AS elapsed_ms, COALESCE(SUM(CASE WHEN elapsed_ms > 0 THEN 1 ELSE 0 END), 0) AS timed FROM {xedc_import_item} WHERE task_id = :id", [
      ':id' => (int) $row->id,
    ])->fetchAssoc();
    $doneBytes = (int) ($stats['done_bytes'] ?? 0);
    $timed = (int) ($stats['timed'] ?? 0);
    $pending = max(0, (int) $row->total - (int) $row->processed);
    if ($timed >= 5 && $pending > 0) {
      $eta = (int) round(((int) $stats['elapsed_ms'] / $timed) * $pending / 1000);
    }
    return ['done_bytes' => $doneBytes, 'eta_seconds' => $eta, 'current' => $current];
  }

  /**
   * One line built only from stored task data.
   *
   * @param array{id: string, label: string} $phase
   *   Display phase.
   * @param array{done_bytes: int, eta_seconds: int|null, current: int} $metrics
   *   Measured counters.
   */
  private function progressDetail(object $row, string $label, int $percent, array $metrics): string {
    $parts = [
      $label,
      '压缩包 ' . $this->formatBytes((int) ($row->archive_bytes ?? 0)),
      '图片 ' . (int) $row->processed . '/' . (int) $row->total,
      '成功 ' . (int) $row->success_count,
      '失败 ' . (int) $row->failed_count,
      '正在处理 ' . $metrics['current'],
      $percent . '%',
      '已处理 ' . $this->formatBytes($metrics['done_bytes']),
    ];
    if ($metrics['eta_seconds'] !== NULL) {
      $parts[] = '预计剩余 ' . $this->etaLabel((int) $metrics['eta_seconds']);
    }
    $changed = (int) $row->changed;
    if ($changed > 0) {
      $parts[] = '更新于 ' . date('H:i:s', $changed);
    }
    return implode(' · ', $parts);
  }

  /**
   * Short remaining-time text. Callers hide this when there is no sample.
   */
  private function etaLabel(int $seconds): string {
    if ($seconds < 60) {
      return $seconds . ' 秒';
    }
    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;
    return $rest > 0 ? $minutes . ' 分 ' . $rest . ' 秒' : $minutes . ' 分';
  }

  /**
   * @return array<int, string>
   *   Summary lines.
   */
  private function summary(object $row, ?NodeInterface $node): array {
    $items = [
      (string) $this->t('任务 #@id', ['@id' => $row->id]),
      (string) $this->t('状态：@status', ['@status' => ImportStatus::label((string) $row->status)]),
      (string) $this->t('压缩包：@name（@format）', [
        '@name' => $row->archive_name ?: '-',
        '@format' => $row->archive_format ?: '-',
      ]),
      (string) $this->t('图片 @total 张，成功 @ok，失败 @bad，跳过 @skip', [
        '@total' => $row->total,
        '@ok' => $row->success_count,
        '@bad' => $row->failed_count,
        '@skip' => $row->skipped_count,
      ]),
      (string) $this->t('原图总大小：@size', ['@size' => $this->originalLabel($row)]),
      (string) $this->t('复用文件：@count 个', ['@count' => $this->reusedCount($row)]),
      (string) $this->t('方式：@mode', ['@mode' => $row->mode]),
    ];
    $archiveSha = strtolower((string) ($row->archive_sha256 ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $archiveSha)) {
      $items[] = (string) $this->t('压缩包 SHA-256：@hash', ['@hash' => $archiveSha]);
    }
    $session = $this->sessionForTask((int) $row->id);
    if ($session) {
      $items[] = (string) $this->t('上传会话：@status，已确认 @done / @total', [
        '@status' => ImportStatus::label((string) $session->status),
        '@done' => $this->formatBytes((int) $session->received_bytes),
        '@total' => $this->formatBytes((int) $session->total_bytes),
      ]);
    }
    if (!$node) {
      $items[] = (string) $this->t('关联内容已不存在。');
      return $items;
    }
    $items[] = (string) $this->t('标题：@title', ['@title' => $node->label()]);
    $items[] = (string) $this->t('分类：@value', ['@value' => $this->labels($node, 'field_categories')]);
    $items[] = (string) $this->t('人物名称：@value', ['@value' => $this->labels($node, 'field_model')]);
    $items[] = (string) $this->t('标签：@value', ['@value' => $this->labels($node, 'field_tags')]);
    $items[] = (string) $this->t('内容图片 @count 张', ['@count' => $node->get('field_images')->count()]);
    return $items;
  }

  /**
   * Formats a byte total. Zero stays zero under Chinese plural rules.
   */
  private function reusedCount(object $row): int {
    $count = 0;
    foreach ($this->tasks->items((int) $row->id) as $item) {
      if ((int) ($item->reused ?? 0) === 1 || (string) $item->status === 'duplicate') {
        $count++;
      }
    }
    return $count;
  }

  /**
   * The upload session that created this task, when one still exists.
   */
  private function sessionForTask(int $taskId): ?object {
    $schema = \Drupal::database()->schema();
    if ($taskId < 1 || !$schema->tableExists('xedc_upload_session')) {
      return NULL;
    }
    $row = \Drupal::database()->select('xedc_upload_session', 's')
      ->fields('s', ['status', 'received_bytes', 'total_bytes'])
      ->condition('task_id', $taskId)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  private function originalLabel(object $row): string {
    $known = 0;
    $bytes = 0;
    foreach ($this->tasks->items((int) $row->id) as $item) {
      $size = isset($item->original_bytes) ? (int) $item->original_bytes : 0;
      if ($size > 0) {
        $known++;
        $bytes += $size;
      }
    }
    return $known > 0 ? $this->formatBytes($bytes) : (string) $this->t('原图大小未知');
  }

  /**
   * Formats a byte total. Zero stays zero under Chinese plural rules.
   */
  private function formatBytes(int $bytes): string {
    return StorageSizeFormatter::formatBytes(max(0, $bytes));
  }

  /**
   * User-facing item state. The stored status value stays unchanged.
   */
  private function itemLabel(string $status, int $reused): string {
    if ($reused === 1 || $status === 'duplicate') {
      return '文件已存在，已复用';
    }
    return match ($status) {
      'pending' => '等待上传',
      'success' => '成功',
      'failed' => '失败',
      'skipped' => '已跳过',
      default => '未知状态',
    };
  }

  /**
   * Comma-separated term labels.
   */
  private function labels(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '-';
    }
    $names = [];
    foreach ($node->get($field)->referencedEntities() as $term) {
      $names[] = $term->label();
    }
    return $names ? implode('、', $names) : '-';
  }

  /**
   * Action links that still go through route access checks.
   *
   * @return array<int, array<string, mixed>>
   *   Render arrays.
   */
  private function actionLinks(object $row, ?NodeInterface $node): array {
    $id = (int) $row->id;
    $links = [];
    $add = function (string $route, string $title) use (&$links, $id): void {
      $links[] = Link::fromTextAndUrl($title, Url::fromRoute($route, ['task' => $id]))->toRenderable();
    };
    if ((string) $row->status === ImportStatus::REVIEW) {
      $add('xedc_core.import_publish', (string) $this->t('发布'));
    }
    if (in_array((string) $row->status, [ImportStatus::FAILED, ImportStatus::PARTIAL, ImportStatus::CANCELLED], TRUE)) {
      $add('xedc_core.import_retry', (string) $this->t('重试'));
    }
    if (!in_array((string) $row->status, [ImportStatus::PUBLISHED, ImportStatus::CANCELLED], TRUE)) {
      $add('xedc_core.import_cancel', (string) $this->t('取消'));
    }
    $add('xedc_core.import_cleanup', (string) $this->t('清理临时文件'));
    $add('xedc_core.import_reroll', (string) $this->t('重新随机封面'));
    if ((string) $row->mode === 'replace' && (int) $row->replaced) {
      $add('xedc_core.import_rollback', (string) $this->t('回滚替换'));
    }
    if ($node) {
      $links[] = Link::fromTextAndUrl($this->t('编辑内容'), $node->toUrl('edit-form'))->toRenderable();
      if ($node->isPublished()) {
        $links[] = Link::fromTextAndUrl($this->t('查看已发布内容'), $node->toUrl())->toRenderable();
      }
    }
    return $links;
  }

  /**
   * Progress bar that polls the JSON route.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function progressElement(object $row): array {
    $url = Url::fromRoute('xedc_core.import_progress', ['task' => (int) $row->id])->toString();
    $label = ImportStatus::label((string) $row->status) . ' ' . (int) $row->progress . '%';
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['xedc-import__live'],
        'data-xedc-progress-url' => $url,
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $label,
        '#attributes' => ['data-xedc-progress-label' => '1'],
      ],
      'detail' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => '',
        '#attributes' => ['data-xedc-progress-detail' => '1', 'class' => ['xedc-import__detail']],
      ],
      'bar' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-import__bar']],
        'fill' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '',
          '#attributes' => [
            'data-xedc-progress-fill' => '1',
            'style' => 'width:' . (int) $row->progress . '%',
          ],
        ],
      ],
    ];
  }

  /**
   * One preview cell.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function preview(object $item): array {
    $src = '';
    $alt = (string) $item->alt;
    if ((int) $item->mid) {
      $media = $this->entityTypeManager()->getStorage('media')->load($item->mid);
      if ($media instanceof MediaInterface && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
        $alt = (string) $media->get('field_media_image')->alt;
        $file = $media->get('field_media_image')->entity;
        if ($file instanceof FileInterface) {
          $style = ImageStyle::load('photo_gallery_650');
          if ($style && (string) $item->webp !== 'animated' && (string) $item->webp !== 'fallback') {
            $src = $style->buildUrl($file->getFileUri());
          }
          else {
            $src = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
          }
        }
      }
    }
    $caption = (string) $item->original_name;
    if ((string) $item->status === 'failed') {
      $caption .= ' — ' . (string) $item->error;
    }
    elseif ((int) ($item->reused ?? 0) === 1 || (string) $item->status === 'duplicate') {
      $caption .= ' — 文件已存在，已复用';
    }
    $figure = [
      '#type' => 'container',
      'caption' => ['#plain_text' => $caption . ($alt !== '' ? ' / ' . $alt : '')],
    ];
    if ($src !== '') {
      $figure['image'] = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => $src,
          'alt' => $alt,
          'loading' => 'lazy',
        ],
      ];
    }
    return $figure;
  }

}
