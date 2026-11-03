<?php

declare(strict_types=1);

namespace Drupal\xedc_core\EventSubscriber;

use Drupal\xedc_core\Engagement\GuestIdentity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches a newly issued guest cookie. It never logs the secret.
 */
final class GuestCookieSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected GuestIdentity $guests,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $this->guests->apply($event->getResponse());
  }

}
