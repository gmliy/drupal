<?php

declare(strict_types=1);

namespace Drupal\xedc_notify;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\xedc_notify\Service\FollowManager;

/**
 * Lazy-built follow / message actions on public profiles.
 */
final class ProfileActionsBuilder implements TrustedCallbackInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected FollowManager $follow,
  ) {}

  public static function trustedCallbacks(): array {
    return ['actions'];
  }

  /**
   * @return array
   *   Render array.
   */
  public function actions(string $targetUid): array {
    $target = (int) $targetUid;
    $viewer = (int) $this->currentUser->id();
    if ($target < 1 || $viewer === $target) {
      return ['#markup' => ''];
    }

    // Anonymous visitors get a login destination, not a 404 / broken PM link.
    if (!$this->currentUser->isAuthenticated()) {
      $destination = \Drupal::request()->getRequestUri();
      $loginUrl = Url::fromRoute('user.login', [], [
        'query' => ['destination' => $destination],
      ])->toString();
      return [
        '#theme' => 'xedc_notify_profile_actions',
        '#follow_url' => '',
        '#following' => FALSE,
        '#message_url' => $loginUrl,
        '#message_guest' => TRUE,
        '#cache' => [
          'contexts' => ['user.roles:authenticated'],
          'max-age' => 0,
        ],
      ];
    }

    $following = $this->follow->isFollowing($viewer, $target);
    $followUrl = Url::fromRoute('xedc_notify.follow_toggle', ['user' => $target], [
      'query' => ['destination' => \Drupal::request()->getRequestUri()],
    ])->toString();
    $messageUrl = '';
    if ($this->currentUser->hasPermission('access send xedc messages')) {
      // Recipient is always the profile owner ($target), never the viewer.
      $messageUrl = Url::fromRoute('xedc_notify.message_start', ['user' => $target])->toString();
    }
    return [
      '#theme' => 'xedc_notify_profile_actions',
      '#follow_url' => $followUrl,
      '#following' => $following,
      '#message_url' => $messageUrl,
      '#message_guest' => FALSE,
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

}
