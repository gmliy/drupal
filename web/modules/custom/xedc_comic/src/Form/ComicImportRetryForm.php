<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_comic\Service\ComicImportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retry failed items on an import job.
 */
final class ComicImportRetryForm extends ConfirmFormBase {

  private int $jobId = 0;

  public function __construct(private readonly ComicImportManager $imports) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_comic.import'));
  }

  public function getFormId(): string {
    return 'xedc_comic_import_retry';
  }

  public function getQuestion() {
    return $this->t('重试任务 @id 中所有失败项？', ['@id' => $this->jobId]);
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('xedc_comic.import_status', [], ['query' => ['job' => $this->jobId]]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $job = NULL): array {
    $this->jobId = (int) $job;
    $failed = $this->imports->failedItems($this->jobId);
    $form['details'] = [
      '#markup' => $this->t('失败项：@n', ['@n' => count($failed)]),
    ];
    $built = parent::buildForm($form, $form_state);
    return $built;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->imports->retryFailed($this->jobId)) {
      $this->messenger()->addStatus($this->t('已排队重试失败项。'));
    }
    else {
      $this->messenger()->addWarning($this->t('没有可重试的失败项，或任务仍在进行。'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}