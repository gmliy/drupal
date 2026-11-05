<?php

declare(strict_types=1);

namespace Drupal\xedc_notify;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\xedc_notify\Service\MessageManager;
use Drupal\xedc_notify\Service\NotificationManager;

/**
 * Header badge placeholders for messages and notifications.
 */
final class NotifyLazyBuilders implements TrustedCallbackInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected MessageManager $messages,
    protected NotificationManager $notifications,
  ) {}

  public static function trustedCallbacks(): array {
    return ['headerBadges'];
  }

  /**
   * @return array
   *   Render array for header icons.
   */
  public function headerBadges(): array {
    if (!$this->currentUser->isAuthenticated()) {
      return ['#markup' => ''];
    }
    $uid = (int) $this->currentUser->id();
    $msg = $this->currentUser->hasPermission('access own xedc messages')
      ? $this->messages->unreadTotal($uid) : 0;
    $ntf = $this->currentUser->hasPermission('access own xedc notifications')
      ? $this->notifications->unreadCount($uid) : 0;
    return [
      '#theme' => 'xedc_notify_header_badges',
      '#messages_url' => Url::fromRoute('xedc_notify.messages')->toString(),
      '#notifications_url' => Url::fromRoute('xedc_notify.notifications')->toString(),
      '#messages_count' => $msg,
      '#notifications_count' => $ntf,
      '#messages_label' => $msg > 99 ? '99+' : (string) $msg,
      '#notifications_label' => $ntf > 99 ? '99+' : (string) $ntf,
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
