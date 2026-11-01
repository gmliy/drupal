<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\xedc_core\Form\FileScanForm;
use Drupal\xedc_core\Ops\DiskOverview;
use Drupal\xedc_core\Ops\FileScanner;
use Drupal\xedc_core\Ops\MediaCensus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * File statistics page. Scanning is queued and read-only.
 */
class FileStatsController extends ControllerBase {

  public function __construct(
    protected DiskOverview $disk,
    protected FileScanner $scanner,
    protected MediaCensus $census,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.disk'),
      $container->get('xedc_core.file_scanner'),
      $container->get('xedc_core.media_census'),
    );
  }

  /**
   * File statistics page.
   */
  public function page(Request $request): array {
    $refresh = $request->query->get('refresh') === '1';
    return [
      '#theme' => 'xedc_core_files',
      '#disk' => $this->disk->summary($refresh),
      '#job' => $this->scanner->jobSummary(),
      '#report' => $this->scanner->report(),
      '#census' => $this->census->summary($refresh),
      '#form' => $this->formBuilder()->getForm(FileScanForm::class),
      '#attached' => [
        'library' => ['xedc_core/ops'],
        'drupalSettings' => [
          'xedcFiles' => [
            'statusUrl' => Url::fromRoute('xedc_core.files_status')->toString(),
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Scan progress for the current page. Does not include file paths.
   */
  public function status(): JsonResponse {
    $job = $this->scanner->jobSummary();
    unset($job['roots']);
    $response = new JsonResponse($job);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
