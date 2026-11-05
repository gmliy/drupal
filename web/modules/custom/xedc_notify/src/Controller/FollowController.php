<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\xedc_notify\Service\FollowManager;
use Drupal\xedc_notify\Service\SocialNotifier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Follow / unfollow from public profiles.
 */
final class FollowController extends ControllerBase {

  public function __construct(
    protected FollowManager $follow,
    protected SocialNotifier $social,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_notify.follow'),
      $container->get('xedc_notify.social'),
    );
  }

  public function toggle(UserInterface $user): RedirectResponse {
    $actor = (int) $this->currentUser()->id();
    $target = (int) $user->id();
    $actorAccount = $this->entityTypeManager()->getStorage('user')->load($actor);
    if (
      $actor < 1
      || $target < 1
      || $actor === $target
      || !$user->isActive()
      || !$actorAccount instanceof UserInterface
      || !$actorAccount->isActive()
    ) {
      throw new AccessDeniedHttpException();
    }
    if ($this->follow->isFollowing($actor, $target)) {
      $this->follow->unfollow($actor, $target);
      $this->messenger()->addStatus($this->t('已取消关注。'));
    }
    else {
      $fid = $this->follow->follow($actor, $target);
      if ($fid > 0) {
        try {
          $this->social->onFollow($actor, $target, $fid);
        }
        catch (\Throwable $e) {
          // Follow relationship is already stored; notification failure must not roll it back.
          \Drupal::logger('xedc_notify')->error('Follow notify failed: @m', ['@m' => $e->getMessage()]);
        }
      }
      $this->messenger()->addStatus($this->t('已关注。'));
    }
    $destination = $this->getRedirectDestination()->get();
    if (is_string($destination) && $destination !== '') {
      try {
        return new LocalRedirectResponse($destination);
      }
      catch (\InvalidArgumentException) {
        // External destination values are rejected.
      }
    }
    $slugPath = \Drupal::service('xedc_notify.identity')->publicProfileUrl($target);
    if (str_starts_with($slugPath, '/r/')) {
      return new LocalRedirectResponse($slugPath);
    }
    return $this->redirect('<front>');
  }

}
