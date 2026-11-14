<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_comic\Service\ComicImportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm cancellation of an import job.
 */
final class ComicImportCancelForm extends ConfirmFormBase {

  private int $jobId = 0;

  public function __construct(private readonly ComicImportManager $imports) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_comic.import'));
  }

  public function getFormId(): string {
    return 'xedc_comic_import_cancel';
  }

  public function getQuestion() {
    return $this->t('确定取消导入任务 @id？', ['@id' => $this->jobId]);
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('xedc_comic.import_status', [], ['query' => ['job' => $this->jobId]]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $job = NULL): array {
    $this->jobId = (int) $job;
    $built = parent::buildForm($form, $form_state);
    return \Drupal::service('xedc_comic.admin_shell')->page(
      $built,
      'xedc_comic.import_status',
      (string) $this->t('取消导入'),
      (string) $this->t('确认是否停止队列中的任务。'),
    );
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->imports->cancelJob($this->jobId)) {
      $this->messenger()->addStatus($this->t('任务已取消。'));
    }
    else {
      $this->messenger()->addWarning($this->t('任务无法取消（可能已完成或已取消）。'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}