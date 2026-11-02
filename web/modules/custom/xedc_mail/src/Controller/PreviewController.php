<?php

declare(strict_types=1);

namespace Drupal\xedc_mail\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\xedc_mail\MailWrapper;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns one rendered account mail for the preview iframe.
 */
final class PreviewController extends ControllerBase {

  public function __construct(
    private readonly MailWrapper $wrapper,
  ) {}

  public static function create($container): static {
    return new static($container->get('xedc_mail.wrapper'));
  }

  public function render(string $key): Response {
    if (!in_array($key, MailWrapper::keys(), TRUE)) {
      throw new NotFoundHttpException();
    }
    $html = $this->wrapper->renderHtml($key, $this->wrapper->examplePlain($key), $this->wrapper->exampleSubject($key));
    $response = new Response($html);
    $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    return $response;
  }

}
