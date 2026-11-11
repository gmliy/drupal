<?php

namespace Drupal\xedc_status\EventSubscriber;

use Drupal\Core\DrupalKernel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders HTML 5xx pages and keeps error responses from being stored.
 *
 * Registered from sites/default/services.yml so a later config import does
 * not uninstall it. The response never includes the exception message.
 */
final class StatusExceptionSubscriber implements EventSubscriberInterface {

  /**
   * Status codes that use this treatment.
   */
  private const CODES = [403, 404, 429, 500, 502, 503];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After the HTML 4xx subscriber (-128) and before the final one (-256).
      KernelEvents::EXCEPTION => ['onException', -240],
      // After FinishResponseSubscriber (0), before BigPipe.
      KernelEvents::RESPONSE => ['onResponse', -50],
    ];
  }

  /**
   * Replaces an uncaught HTML 5xx exception with the static status page.
   */
  public function onException(ExceptionEvent $event): void {
    if ($event->hasResponse() || !$event->isMainRequest()) {
      return;
    }
    try {
      $this->renderServerError($event);
    }
    catch (\Throwable) {
      // The final exception subscriber still has a chance to respond.
    }
  }

  /**
   * Stops browsers and shared caches from storing an error as a normal page.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    $status = $response->getStatusCode();
    if (!in_array($status, self::CODES, TRUE) || $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
      return;
    }
    $type = (string) $response->headers->get('Content-Type', '');
    if ($type !== '' && !str_contains($type, 'text/html')) {
      return;
    }
    if ($type === '' && !str_contains(substr((string) $response->getContent(), 0, 160), '<')) {
      return;
    }
    $this->markUncacheable($response);
  }

  /**
   * Builds the 5xx response from the inlined HTML file.
   */
  private function renderServerError(ExceptionEvent $event): void {
    $request = $event->getRequest();
    $format = $request->getRequestFormat('html');
    if ($format !== 'html') {
      return;
    }
    $throwable = $event->getThrowable();
    $status = 500;
    if ($throwable instanceof HttpExceptionInterface) {
      $status = $throwable->getStatusCode();
      if ($status < 500) {
        return;
      }
    }
    $page = in_array($status, [500, 502, 503], TRUE) ? $status : 500;
    $response = new Response($this->html($page), $status);
    $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
    $this->markUncacheable($response);
    $event->setResponse($response);
  }

  /**
   * Reads the static document, or a short page with no server detail.
   */
  private function html(int $status): string {
    $root = class_exists(DrupalKernel::class) ? \Drupal::root() : '';
    $path = $root . '/themes/custom/xedc/status/' . $status . '.html';
    if ($root !== '' && is_file($path)) {
      $html = file_get_contents($path);
      if (is_string($html) && $html !== '') {
        return $html;
      }
    }
    $copy = [
      502 => ['服务暂时不可用', '当前服务器连接出现问题，请稍后再试。'],
      503 => ['网站维护中', '我们正在进行网站维护和优化，请稍后再来。'],
    ];
    $item = $copy[$status] ?? ['服务器内部错误', '服务器遇到意外问题，请稍后重试。'];
    return '<!DOCTYPE html><html lang="zh-Hans"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . $item[0] . '</title><p>' . $item[1] . '</p></html>';
  }

  /**
   * Marks an error response as private and not for indexing.
   */
  private function markUncacheable(Response $response): void {
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
  }

}
