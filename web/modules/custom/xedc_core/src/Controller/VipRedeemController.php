<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\xedc_core\Engagement\VipCodeManager;
use Drupal\xedc_core\Form\VipCodeRedeemForm;
use Drupal\xedc_member\Controller\MemberController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member center VIP code redeem page.
 */
final class VipRedeemController extends ControllerBase {

  public function __construct(
    protected VipCodeManager $codes,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.vip_codes'),
      $container->get('date.formatter'),
    );
  }

  public function page(): array {
    $uid = (int) $this->currentUser()->id();
    $history = [];
    foreach ($this->codes->listRedemptionsForUser($uid, 20) as $row) {
      $history[] = [
        'mask' => $this->codes->maskCode($row),
        'days' => (int) $row->vip_days,
        'redeemed' => $this->dateFormatter->format((int) $row->redeemed, 'custom', 'Y-m-d H:i'),
        'batch' => (int) $row->batch_id,
      ];
    }
    $content = [
      '#theme' => 'xedc_vip_redeem',
      '#form' => $this->formBuilder()->getForm(VipCodeRedeemForm::class),
      '#history' => $history,
      '#attached' => [
        'library' => ['xedc_core/vip_redeem'],
      ],
    ];
    if ($this->moduleHandler()->moduleExists('xedc_member')) {
      /** @var \Drupal\xedc_member\Controller\MemberController $member */
      $member = \Drupal::classResolver()->getInstanceFromDefinition(MemberController::class);
      return $member->renderShell('redeem', $content);
    }
    return $content;
  }

}
