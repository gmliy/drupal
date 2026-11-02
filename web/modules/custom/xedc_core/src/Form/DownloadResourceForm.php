<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Edit form for a download resource.
 */
final class DownloadResourceForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    if ($this->entity->isNew() && $this->entity->get('parent')->isEmpty()) {
      $parent = \Drupal::request()->query->get('parent');
      if (is_scalar($parent) && (int) $parent > 0) {
        $this->entity->set('parent', (int) $parent);
      }
    }
    $form = parent::form($form, $form_state);
    $form['notice'] = [
      '#type' => 'item',
      '#markup' => '<p>一个资源只能使用一种来源。VIP 专属资源必须上传到私有目录，不能填写外部永久链接。图片原图和整组 ZIP 使用内容里的原始文件，不会把 WebP 缩略图当作原图。如果详情页本身已经展示原图，访客仍可能从页面保存图片；需要不可直链的文件时，请使用上传文件。</p>',
      '#weight' => -10,
    ];
    if (isset($form['file'])) {
      $form['file']['#states'] = [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'upload'],
        ],
      ];
    }
    if (isset($form['external_url'])) {
      $form['external_url']['#states'] = [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'external'],
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    if ($entity) {
      try {
        $entity->assertReady();
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName('source_type', $e->getMessage());
      }
    }
    $node = $entity?->get('parent')->entity;
    if ($node instanceof NodeInterface && !$node->access('view')) {
      $form_state->setErrorByName('parent', $this->t('不能把资源挂到无权查看的内容上。'));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($status === SAVED_NEW ? $this->t('已添加下载资源。') : $this->t('已保存下载资源。'));
    $form_state->setRedirect('entity.xedc_download.collection');
    return $status;
  }

}
