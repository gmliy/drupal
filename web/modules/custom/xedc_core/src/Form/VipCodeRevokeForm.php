<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\Engagement\VipCodeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm revoke of an unused VIP code.
 */
final class VipCodeRevokeForm extends ConfirmFormBase {

  protected int $codeId = 0;

  public function __construct(
    protected VipCodeManager $codes,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.vip_codes'));
  }

  public function getFormId(): string {
    return 'xedc_vip_code_revoke';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $code_id = NULL): array {
    $this->codeId = (int) $code_id;
    $row = $this->codes->loadCode($this->codeId);
    if (!$row || $this->codes->effectiveStatus($row) !== VipCodeManager::STATUS_UNUSED) {
      $this->messenger()->addError($this->t('只能作废未兑换的卡密。'));
      $form_state->setRedirect('xedc_core.vip_codes');
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    $row = $this->codes->loadCode($this->codeId);
    return $this->t('确定作废卡密 @mask 吗？作废后不可再兑换。', [
      '@mask' => $row ? $this->codes->maskCode($row) : (string) $this->codeId,
    ]);
  }

  public function getCancelUrl() {
    return Url::fromRoute('xedc_core.vip_codes');
  }

  public function getConfirmText() {
    return $this->t('确认作废');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->codes->revoke($this->codeId, (int) $this->currentUser()->id())) {
      $this->messenger()->addStatus($this->t('卡密已作废。'));
    }
    else {
      $this->messenger()->addError($this->t('作废失败，卡密可能已兑换或已作废。'));
    }
    $form_state->setRedirect('xedc_core.vip_codes');
  }

}
