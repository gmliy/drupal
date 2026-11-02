<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\xedc_core\Ops\Storage\ProtectedDownloader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission-checked presigned download. It does not publish private files.
 */
class ProtectedDownloadController extends ControllerBase {

  public function __construct(
    protected ProtectedDownloader $downloader,
    protected AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.storage_download'),
      $container->get('current_user'),
    );
  }

  public function download(string $fid): Response {
    $result = $this->downloader->authorize((int) $fid, $this->account);
    if ($result['status'] === 302 && $result['url'] !== '') {
      $response = new RedirectResponse($result['url']);
      $response->headers->set('Cache-Control', 'no-store, private');
      return $response;
    }
    $response = new Response($result['message'], $result['status']);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
    return $response;
  }

}
