<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
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
   * JSON progress for the admin page.
   */
  public function progress(int $task): JsonResponse {
    $row = $this->tasks->load($task);
    if (!$row) {
      throw new NotFoundHttpException();
    }
    return new JsonResponse([
      'id' => (int) $row->id,
      'status' => (string) $row->status,
      'label' => ImportStatus::label((string) $row->status),
      'progress' => (int) $row->progress,
      'total' => (int) $row->total,
      'processed' => (int) $row->processed,
      'success' => (int) $row->success_count,
      'failed' => (int) $row->failed_count,
      'skipped' => (int) $row->skipped_count,
      'message' => (string) $row->message,
      'error' => (string) $row->error,
      'done' => ImportStatus::isTerminal((string) $row->status),
    ]);
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
      (string) $this->t('原图总大小：@size', ['@size' => $this->formatBytes((int) $row->bytes)]),
      (string) $this->t('方式：@mode', ['@mode' => $row->mode]),
    ];
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
  private function formatBytes(int $bytes): string {
    if ($bytes < 1) {
      return (string) $this->t('0 字节');
    }
    return (string) ByteSizeMarkup::create($bytes);
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
