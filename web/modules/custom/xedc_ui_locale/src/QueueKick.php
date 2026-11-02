<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Continues queued interface translation after the install request.
 */
final class QueueKick implements EventSubscriberInterface {

  public function __construct(
    private readonly UiTranslationManager $manager,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['onTerminate', 100]];
  }

  public function onTerminate(TerminateEvent $event): void {
    if (!$this->state->get('xedc_ui_locale.kick')) {
      return;
    }
    if (!$this->shouldRun($event)) {
      return;
    }
    $this->manager->processQueue(8);
    if ($this->manager->pendingModules() === []) {
      $this->state->delete('xedc_ui_locale.kick');
    }
  }

  private function shouldRun(TerminateEvent $event): bool {
    if (PHP_SAPI === 'cli') {
      return TRUE;
    }
    $path = $event->getRequest()->getPathInfo();
    return str_starts_with($path, '/admin') || str_starts_with($path, '/core/install');
  }

}
