<?php

namespace Drupal\xedc_member\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\xedc_member\Service\MemberAliasManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends /member and the core account URL to the member's own slug.
 */
class MemberAliasSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a MemberAliasSubscriber.
   */
  public function __construct(
    protected MemberAliasManager $aliasManager,
    protected AccountProxyInterface $currentUser,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
      KernelEvents::EXCEPTION => ['onException', 60],
    ];
  }

  /**
   * Redirects /member and the owner's user page to that member's slug.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->currentUser->isAuthenticated()) {
      return;
    }
    $request = $event->getRequest();
    if (!$request->isMethodCacheable()) {
      return;
    }
    $uid = (int) $this->currentUser->id();
    $path = rawurldecode($request->getPathInfo());
    $slug = $this->aliasManager->getSlug($uid);
    if ($slug !== '' && mb_strtolower(rtrim($path, '/')) === mb_strtolower('/' . $slug . '/settings')) {
      $this->redirectIfDifferent($event, $request, $path, '/' . $slug . '/security');
      return;
    }
    if (preg_match('#^/member(?:/(.*))?$#', $path, $matches)) {
      $suffix = $matches[1] ?? '';
      if ($suffix === 'settings') {
        $suffix = 'security';
      }
      $target = $this->aliasManager->toPublicPath($uid, $suffix === '' ? '/member' : '/member/' . $suffix);
      $this->redirectIfDifferent($event, $request, $path, $target);
      return;
    }

    $route = $this->routeMatch->getRouteName();
    if ($route === 'user.page') {
      $this->redirectIfDifferent($event, $request, $path, $this->aliasManager->memberBase($uid));
      return;
    }
    if ($route === 'entity.user.canonical') {
      $account = $this->routeMatch->getParameter('user');
      $viewed = $account instanceof UserInterface ? (int) $account->id() : (int) $account;
      if ($viewed > 0 && $viewed === $uid) {
        $this->redirectIfDifferent($event, $request, $path, $this->aliasManager->memberBase($uid));
      }
    }
  }

  /**
   * Sends anonymous visitors who hit the member center to the login page.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if (!$exception instanceof AccessDeniedHttpException || $this->currentUser->isAuthenticated()) {
      return;
    }
    $route = $this->routeMatch->getRouteName();
    if (!is_string($route) || !str_starts_with($route, 'xedc_member.') || $route === 'xedc_member.alias_denied' || $route === 'xedc_member.public_profile') {
      return;
    }
    $destination = $event->getRequest()->getRequestUri();
    $url = Url::fromRoute('user.login', [], [
      'query' => ['destination' => $destination],
    ])->toString();
    $event->setResponse($this->uncachedRedirect($url));
  }

  /**
   * Redirects when the browser URL is not already the public member path.
   */
  protected function redirectIfDifferent(RequestEvent $event, Request $request, string $path, string $target): void {
    $target_path = rawurldecode((string) (parse_url($target, PHP_URL_PATH) ?: $target));
    if (rtrim($target_path, '/') === rtrim($path, '/')) {
      return;
    }
    $query = $request->getQueryString();
    if ($query) {
      $target .= (str_contains($target, '?') ? '&' : '?') . $query;
    }
    $event->setResponse($this->uncachedRedirect($target));
  }

  /**
   * Builds a short-lived redirect.
   */
  protected function uncachedRedirect(string $url): RedirectResponse {
    $response = new RedirectResponse($url, 302);
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    return $response;
  }

}
