<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Comic module settings.
 */
final class ComicSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'xedc_comic_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['xedc_comic.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('xedc_comic.settings');
    $form['import'] = [
      '#type' => 'details',
      '#title' => $this->t('ZIP 导入'),
      '#open' => TRUE,
    ];
    foreach ([
      'max_archive_bytes' => $this->t('压缩包最大字节'),
      'max_extracted_bytes' => $this->t('解压后总大小上限'),
      'max_file_bytes' => $this->t('单图最大字节'),
      'max_files' => $this->t('最多图片数'),
      'batch_size' => $this->t('队列批大小'),
    ] as $key => $label) {
      $form['import'][$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $config->get('import.' . $key),
        '#min' => 1,
      ];
    }
    $form['pdf'] = [
      '#type' => 'details',
      '#title' => $this->t('PDF 导入'),
      '#open' => FALSE,
    ];
    foreach ([
      'max_bytes' => $this->t('PDF 最大字节'),
      'max_pages' => $this->t('最大页数'),
      'max_page_pixels' => $this->t('单页最大像素'),
      'max_concurrent' => $this->t('最大并发任务'),
      'convert_batch' => $this->t('每批转换页数'),
      'page_timeout' => $this->t('单页转换超时（秒）'),
      'keep_source_default' => $this->t('默认保留原始 PDF（1/0）'),
    ] as $key => $label) {
      $form['pdf']['pdf_' . $key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $config->get('pdf.' . $key),
        '#min' => 1,
      ];
    }
    $form['paths'] = [
      '#type' => 'details',
      '#title' => $this->t('存储路径'),
      '#open' => TRUE,
    ];
    $form['paths']['file_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('public:// 下目录前缀'),
      '#default_value' => $config->get('paths.file_prefix'),
      '#description' => $this->t('对象路径 public/{prefix}/{comic_id}/{chapter_id}/。Storage Hub 公开前缀策略不变。'),
    ];
    $built = parent::buildForm($form, $form_state);
    $built['#attributes']['class'][] = 'xedc-comic-settings-form';
    return $built;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('xedc_comic.settings')
      ->set('import.max_archive_bytes', (int) $form_state->getValue('max_archive_bytes'))
      ->set('import.max_extracted_bytes', (int) $form_state->getValue('max_extracted_bytes'))
      ->set('import.max_file_bytes', (int) $form_state->getValue('max_file_bytes'))
      ->set('import.max_files', (int) $form_state->getValue('max_files'))
      ->set('import.batch_size', (int) $form_state->getValue('batch_size'))
      ->set('pdf.max_bytes', (int) $form_state->getValue('pdf_max_bytes'))
      ->set('pdf.max_pages', (int) $form_state->getValue('pdf_max_pages'))
      ->set('pdf.max_page_pixels', (int) $form_state->getValue('pdf_max_page_pixels'))
      ->set('pdf.max_concurrent', (int) $form_state->getValue('pdf_max_concurrent'))
      ->set('pdf.convert_batch', (int) $form_state->getValue('pdf_convert_batch'))
      ->set('pdf.page_timeout', (int) $form_state->getValue('pdf_page_timeout'))
      ->set('pdf.keep_source_default', (int) $form_state->getValue('pdf_keep_source_default'))
      ->set('paths.file_prefix', trim((string) $form_state->getValue('file_prefix')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}