<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_notify\Service\OfficialCampaignManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cancels a draft or in-flight official campaign.
 */
final class OfficialCancelForm extends ConfirmFormBase {

  protected int $cid = 0;

  public function __construct(
    protected OfficialCampaignManager $campaigns,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_notify.official'));
  }

  public function getFormId(): string {
    return 'xedc_notify_official_cancel';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $cid = NULL): array {
    $this->cid = (int) $cid;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    return $this->t('取消尚未完成的官方通知发送？');
  }

  public function getCancelUrl() {
    return Url::fromRoute('xedc_notify.admin_official_view', ['cid' => $this->cid]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->campaigns->cancel($this->cid);
      $this->messenger()->addStatus($this->t('已取消。'));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('xedc_notify.admin_official_view', ['cid' => $this->cid]);
  }

}
