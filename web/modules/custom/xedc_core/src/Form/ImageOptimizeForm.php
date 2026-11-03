<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\xedc_core\Image\ImageOptimizeRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings, counters and task actions for image optimization.
 */
final class ImageOptimizeForm extends FormBase {

  public function __construct(
    protected ImageOptimizeRepository $repository,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.image_optimize_repository'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_image_optimize';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->repository->settings();
    $summary = $this->repository->summary();
    $saved = (int) ($summary['saved'] ?? 0);
    $success = (int) ($summary['success'] ?? 0);
    $form['#attached']['library'][] = 'xedc_core/image_optimize';
    $form['summary'] = [
      '#theme' => 'item_list',
      '#wrapper_attributes' => ['class' => ['xedc-opt-summary']],
      '#attributes' => ['class' => ['xedc-opt-summary']],
      '#items' => [
        $this->t('成功 @count', ['@count' => $success]),
        $this->t('累计节省 @bytes', ['@bytes' => \format_size($saved)]),
        $this->t('平均节省 @ratio%', ['@ratio' => $this->ratio($summary)]),
        $this->t('失败 @count', ['@count' => (int) ($summary['failed'] ?? 0)]),
        $this->t('排队 @count', ['@count' => (int) ($summary['queued'] ?? 0)]),
        $this->t('处理中 @count', ['@count' => (int) ($summary['running'] ?? 0)]),
        $this->t('最近处理 @time', ['@time' => empty($summary['latest']) ? '-' : \Drupal::service('date.formatter')->format((int) $summary['latest'], 'short')]),
        $this->t('R2 成功 @count', ['@count' => (int) ($summary['r2_ok'] ?? 0)]),
        $this->t('R2 失败 @count', ['@count' => (int) ($summary['r2_failed'] ?? 0)]),
      ],
    ];
    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('优化设置'),
      '#open' => TRUE,
    ];
    $form['settings']['jpeg_quality'] = $this->number($this->t('JPEG 质量'), (int) $settings['jpeg_quality'], 82, 88);
    $form['settings']['webp_quality'] = $this->number($this->t('WebP 质量'), (int) $settings['webp_quality'], 80, 84);
    $form['settings']['avif_quality'] = $this->number($this->t('AVIF 质量'), (int) $settings['avif_quality'], 68, 75);
    $form['settings']['allow_webp'] = ['#type' => 'checkbox', '#title' => $this->t('允许输出 WebP'), '#default_value' => !empty($settings['allow_webp'])];
    $form['settings']['allow_avif'] = ['#type' => 'checkbox', '#title' => $this->t('允许输出 AVIF'), '#default_value' => !empty($settings['allow_avif'])];
    $form['settings']['auto_format'] = ['#type' => 'checkbox', '#title' => $this->t('允许按体积自动选择格式'), '#default_value' => !empty($settings['auto_format'])];
    $form['settings']['strip_metadata'] = ['#type' => 'checkbox', '#title' => $this->t('清理 EXIF 隐私元数据'), '#default_value' => !empty($settings['strip_metadata'])];
    $form['settings']['sync_bytes'] = $this->number($this->t('同步处理上限（字节）'), (int) $settings['sync_bytes'], 102400, 10485760);
    $form['settings']['max_bytes'] = $this->number($this->t('单文件安全上限（字节）'), (int) $settings['max_bytes'], 1048576, 52428800);
    $form['settings']['max_pixels'] = $this->number($this->t('最大解码像素'), (int) $settings['max_pixels'], 1000000, 24000000);
    $form['settings']['timeout'] = $this->number($this->t('图片处理超时（秒）'), (int) $settings['timeout'], 5, 60);
    $form['settings']['retries'] = $this->number($this->t('失败重试次数'), (int) $settings['retries'], 0, 3);
    $form['settings']['concurrency'] = $this->number($this->t('队列并发上限'), (int) $settings['concurrency'], 1, 2);
    $form['settings']['save'] = ['#type' => 'submit', '#name' => 'save', '#value' => $this->t('保存设置'), '#submit' => ['::saveSettings']];

    $header = [
      'file' => $this->t('文件'),
      'source' => $this->t('原始'),
      'stored' => $this->t('结果'),
      'saved' => $this->t('节省'),
      'size' => $this->t('尺寸'),
      'time' => $this->t('耗时'),
      'r2' => $this->t('R2'),
      'status' => $this->t('状态'),
      'error' => $this->t('错误'),
    ];
    $options = [];
    foreach ($this->repository->list('all', 50) as $row) {
      $savedBytes = max(0, (int) $row['source_bytes'] - (int) $row['stored_bytes']);
      $options[$row['id']] = [
        'file' => $row['filename'] . ' #' . $row['fid'],
        'source' => $row['source_mime'] . ' ' . \format_size((int) $row['source_bytes']),
        'stored' => $row['stored_mime'] . ' ' . \format_size((int) $row['stored_bytes']),
        'saved' => \format_size($savedBytes),
        'size' => $row['width'] . '×' . $row['height'],
        'time' => $row['elapsed_ms'] . ' ms',
        'r2' => $row['r2_status'],
        'status' => $row['status'],
        'error' => $row['error'],
      ];
    }
    $form['tasks'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#empty' => $this->t('还没有优化记录。新上传的公开图片会出现在这里。'),
    ];
    $form['retry'] = ['#type' => 'submit', '#name' => 'retry', '#value' => $this->t('重试所选'), '#submit' => ['::retry']];
    $form['cancel'] = ['#type' => 'submit', '#name' => 'cancel', '#value' => $this->t('取消排队'), '#submit' => ['::cancel']];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Saves encoder settings without touching config/sync.
   */
  public function saveSettings(array &$form, FormStateInterface $form_state): void {
    $this->repository->saveSettings([
      'jpeg_quality' => (int) $form_state->getValue('jpeg_quality'),
      'webp_quality' => (int) $form_state->getValue('webp_quality'),
      'avif_quality' => (int) $form_state->getValue('avif_quality'),
      'allow_webp' => (bool) $form_state->getValue('allow_webp'),
      'allow_avif' => (bool) $form_state->getValue('allow_avif'),
      'auto_format' => (bool) $form_state->getValue('auto_format'),
      'strip_metadata' => (bool) $form_state->getValue('strip_metadata'),
      'sync_bytes' => (int) $form_state->getValue('sync_bytes'),
      'max_bytes' => (int) $form_state->getValue('max_bytes'),
      'max_pixels' => (int) $form_state->getValue('max_pixels'),
      'timeout' => (int) $form_state->getValue('timeout'),
      'retries' => (int) $form_state->getValue('retries'),
      'concurrency' => (int) $form_state->getValue('concurrency'),
    ]);
    $this->messenger()->addStatus($this->t('图片优化设置已保存。'));
  }

  /**
   * Queues selected failed tasks again.
   */
  public function retry(array &$form, FormStateInterface $form_state): void {
    foreach ($this->selected($form_state) as $id) {
      $row = $this->repository->load($id);
      if (!is_array($row) || !in_array($row['status'], ['failed', 'cancelled'], TRUE)) {
        continue;
      }
      $this->repository->record([
        'fid' => (int) $row['fid'],
        'filename' => (string) $row['filename'],
        'status' => 'queued',
        'error' => '',
        'attempts' => 0,
      ]);
      $this->queueFactory->get('xedc_image_optimize')->createItem(['id' => $id, 'fid' => (int) $row['fid']]);
    }
    $this->messenger()->addStatus($this->t('已重新排队。'));
  }

  /**
   * Cancels selected tasks that have not finished.
   */
  public function cancel(array &$form, FormStateInterface $form_state): void {
    foreach ($this->selected($form_state) as $id) {
      $row = $this->repository->load($id);
      if (!is_array($row) || $row['status'] !== 'queued') {
        continue;
      }
      $this->repository->record([
        'fid' => (int) $row['fid'],
        'filename' => (string) $row['filename'],
        'status' => 'cancelled',
      ]);
    }
    $this->messenger()->addStatus($this->t('已取消仍在排队的任务。'));
  }

  /**
   * @return list<int>
   */
  private function selected(FormStateInterface $form_state): array {
    $value = $form_state->getValue('tasks');
    if (!is_array($value)) {
      return [];
    }
    $ids = [];
    foreach ($value as $id => $picked) {
      if ($picked) {
        $ids[] = (int) $id;
      }
    }
    return $ids;
  }

  /**
   * @param array<string, int> $summary
   */
  private function ratio(array $summary): string {
    $source = (int) ($summary['source_sum'] ?? 0);
    if ($source < 1) {
      return '0';
    }
    return (string) round(((int) ($summary['saved'] ?? 0) / $source) * 100, 1);
  }

  /**
   * @return array<string, mixed>
   */
  private function number(string $title, int $default, int $min, int $max): array {
    return [
      '#type' => 'number',
      '#title' => $title,
      '#default_value' => $default,
      '#min' => $min,
      '#max' => $max,
      '#required' => TRUE,
    ];
  }

}
