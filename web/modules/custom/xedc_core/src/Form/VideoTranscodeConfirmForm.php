<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\xedc_core\Video\TranscodeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms a single playback-copy action.
 */
final class VideoTranscodeConfirmForm extends ConfirmFormBase {

  private int $fid = 0;

  private string $op = '';

  public function __construct(
    private readonly TranscodeManager $transcodes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.video_transcode'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_video_transcode_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('确认执行这个转码操作？');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('xedc_core.video_transcode');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $fid = 0, string $op = ''): array {
    $this->fid = $fid;
    $this->op = $op;
    $form['note'] = [
      '#markup' => '<p>' . $this->t('原视频不会被删除。操作：@op，文件 @fid。', [
        '@op' => $op,
        '@fid' => $fid,
      ]) . '</p>',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->op === 'cleanup') {
      $removed = $this->transcodes->cleanupTemps();
      $this->messenger()->addStatus($this->t('已清理 @count 个临时文件。', ['@count' => $removed]));
    }
    elseif ($this->op === 'cancel') {
      $this->transcodes->cancel($this->fid);
      $this->messenger()->addStatus($this->t('等待中的任务已取消。'));
    }
    elseif ($this->op === 'retry') {
      $this->transcodes->retry($this->fid);
      $this->messenger()->addStatus($this->t('已重新加入队列。'));
    }
    elseif ($this->op === 'detect') {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($this->fid);
      if ($file instanceof FileInterface) {
        $this->transcodes->consider($file);
        $this->messenger()->addStatus($this->t('检测已记录。'));
      }
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
