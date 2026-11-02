<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Entity\DownloadResource;

/**
 * Re-queues a failed or waiting ZIP job. Does not mark it complete.
 */
final class ZipRetryForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'xedc_zip_retry';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    if (\Drupal::database()->schema()->tableExists('xedc_zip_job')) {
      $rows = \Drupal::database()->select('xedc_zip_job', 'z')
        ->fields('z', ['resource_id', 'status', 'message'])
        ->condition('status', ['failed', 'queued'], 'IN')
        ->orderBy('changed', 'DESC')
        ->range(0, 50)
        ->execute();
      foreach ($rows as $row) {
        $options[(int) $row->resource_id] = '#' . (int) $row->resource_id . ' ' . (string) $row->status . ' ' . (string) $row->message;
      }
    }
    if ($options === []) {
      $form['empty'] = ['#markup' => '<p>没有等待重试的 ZIP 任务。</p>'];
      return $form;
    }
    $form['resource_id'] = [
      '#type' => 'select',
      '#title' => '重新排队',
      '#options' => $options,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => '重新排队',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer xedc downloads') && !$this->currentUser()->hasPermission('administer xedc vip')) {
      $this->messenger()->addError('没有权限重试 ZIP 任务。');
      return;
    }
    $id = (int) $form_state->getValue('resource_id');
    $resource = \Drupal::entityTypeManager()->getStorage('xedc_download')->load($id);
    if (!$resource instanceof DownloadResource || $resource->get('source_type')->value !== 'zip') {
      $this->messenger()->addError('找不到可重试的 ZIP 资源。');
      return;
    }
    $builder = \Drupal::service('xedc_core.zip_builder');
    $builder->note($resource, 'queued', '管理员重新排队。');
    $builder->enqueue($resource);
    $this->messenger()->addStatus('已重新排队。任务完成前不会显示为可下载。');
  }

}
