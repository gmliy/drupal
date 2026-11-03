<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\xedc_core\Video\TranscodeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Playback-copy overview for the existing file reports section.
 */
final class VideoTranscodeController extends ControllerBase {

  public function __construct(
    private readonly TranscodeManager $transcodes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.video_transcode'));
  }

  public function page(Request $request): array {
    $status = substr((string) $request->query->get('status', ''), 0, 16);
    $codec = substr((string) $request->query->get('codec', ''), 0, 32);
    $keys = trim(substr((string) $request->query->get('keys', ''), 0, 64));
    $summary = $this->transcodes->summary();
    $limit = 20;
    \Drupal::service('pager.manager')->createPager($this->count($status, $codec, $keys), $limit);
    $page = \Drupal::service('pager.manager')->findPage();
    $list = $this->transcodes->listing($status, $codec, $keys, $limit, $page * $limit);
    $rows = [];
    foreach ($list['rows'] as $row) {
      $titles = [];
      foreach ($row['nodes'] as $node) {
        $titles[] = $node['title'] . ' (' . $node['nid'] . ')';
      }
      $fid = (int) $row['source_fid'];
      $rows[] = [
        implode(', ', $titles) ?: $this->t('未关联'),
        $fid,
        basename((string) $row['source_uri']),
        (string) $row['video_codec'],
        ((int) $row['width']) . '×' . ((int) $row['height']),
        $this->size((int) $row['source_size']),
        (string) $row['status'],
        (int) $row['output_size'] > 0 ? $this->size((int) $row['output_size']) : '',
        (string) $row['status'] === 'completed' && (int) $row['elapsed'] < 1 ? $this->t('不到 1 秒') : ((int) $row['elapsed'] > 0 ? $this->t('@seconds 秒', ['@seconds' => (int) $row['elapsed']]) : ''),
        (string) $row['message'],
        [
          'data' => [
            '#type' => 'operations',
            '#links' => $this->links($fid, (string) $row['status']),
          ],
        ],
      ];
    }
    return [
      'summary' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('视频 @count', ['@count' => $summary['videos']]),
          $this->t('已检测 @count', ['@count' => $summary['detected']]),
          $this->t('直接兼容 @count', ['@count' => $summary['compatible']]),
          $this->t('需要转码 @count', ['@count' => $summary['transcode']]),
          $this->t('等待 @count', ['@count' => $summary['pending']]),
          $this->t('处理中 @count', ['@count' => $summary['processing']]),
          $this->t('成功 @count', ['@count' => $summary['completed']]),
          $this->t('失败 @count', ['@count' => $summary['failed']]),
          $this->t('播放副本 @count', ['@count' => $summary['copies']]),
        ],
      ],
      'filters' => [
        '#type' => 'link',
        '#title' => $this->t('清理临时文件'),
        '#url' => Url::fromRoute('xedc_core.video_transcode_action', ['fid' => 0, 'op' => 'cleanup']),
      ],
      'detect' => \Drupal::formBuilder()->getForm('Drupal\xedc_core\Form\VideoTranscodeEnqueueForm'),
      'form' => \Drupal::formBuilder()->getForm('Drupal\xedc_core\Form\VideoTranscodeFilterForm', $status, $codec, $keys),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('视频'),
          $this->t('文件'),
          $this->t('原文件名'),
          $this->t('编码'),
          $this->t('分辨率'),
          $this->t('大小'),
          $this->t('状态'),
          $this->t('副本大小'),
          $this->t('耗时'),
          $this->t('说明'),
          $this->t('操作'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('还没有检测记录。'),
      ],
      'pager' => ['#type' => 'pager'],
    ];
  }

  private function count(string $status, string $codec, string $keys): int {
    return $this->transcodes->listing($status, $codec, $keys, 1, 0)['total'];
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function links(int $fid, string $status): array {
    $links = [
      'detect' => [
        'title' => $this->t('检测'),
        'url' => Url::fromRoute('xedc_core.video_transcode_action', ['fid' => $fid, 'op' => 'detect']),
      ],
    ];
    if ($status === 'failed' || $status === 'cancelled') {
      $links['retry'] = [
        'title' => $this->t('重新转码'),
        'url' => Url::fromRoute('xedc_core.video_transcode_action', ['fid' => $fid, 'op' => 'retry']),
      ];
    }
    if ($status === 'pending') {
      $links['cancel'] = [
        'title' => $this->t('取消'),
        'url' => Url::fromRoute('xedc_core.video_transcode_action', ['fid' => $fid, 'op' => 'cancel']),
      ];
    }
    $links['references'] = [
      'title' => $this->t('引用'),
      'url' => Url::fromRoute('xedc_core.files_reference', ['fid' => $fid]),
    ];
    $file = $this->entityTypeManager()->getStorage('file')->load($fid);
    if ($file instanceof \Drupal\file\FileInterface) {
      $links['source'] = [
        'title' => $this->t('原文件'),
        'url' => \Drupal::service('file_url_generator')->generate($file->getFileUri()),
      ];
    }
    $copy = DRUPAL_ROOT . '/sites/default/files/xedc-playback/' . $fid . '.mp4';
    if (is_file($copy)) {
      $links['copy'] = [
        'title' => $this->t('播放副本'),
        'url' => Url::fromUserInput('/sites/default/files/xedc-playback/' . $fid . '.mp4'),
      ];
    }
    return $links;
  }

  private function size(int $bytes): string {
    if ($bytes < 1) {
      return '0';
    }
    return (string) ByteSizeMarkup::create($bytes);
  }

}
