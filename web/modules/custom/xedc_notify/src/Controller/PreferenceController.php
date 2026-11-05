<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Former notification settings page — redirects to the notification center.
 */
final class PreferenceController extends ControllerBase {

  /**
   * Personal preference UI is retired; keep the old path as a soft redirect.
   */
  public function page(): RedirectResponse {
    $this->messenger()->addStatus($this->t('个人通知设置已取消。社交通知按系统默认规则发送，可在通知中心查看。'));
    return $this->redirect('xedc_notify.notifications');
  }

}
