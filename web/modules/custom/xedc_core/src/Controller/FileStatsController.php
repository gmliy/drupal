<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\xedc_core\Form\AnalysisScanForm;
use Drupal\xedc_core\Form\FileScanForm;
use Drupal\xedc_core\Ops\Cleanup\FileAnalysisRunner;
use Drupal\xedc_core\Ops\DiskOverview;
use Drupal\xedc_core\Ops\FileScanner;
use Drupal\xedc_core\Ops\MediaCensus;
use Drupal\xedc_core\Ops\OpsFormat;
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
    protected FileAnalysisRunner $analysis,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.disk'),
      $container->get('xedc_core.file_scanner'),
      $container->get('xedc_core.media_census'),
      $container->get('xedc_core.file_analysis'),
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
      '#analysis' => $this->analysisCard(),
      '#form' => $this->formBuilder()->getForm(FileScanForm::class),
      '#analysis_form' => $this->formBuilder()->getForm(AnalysisScanForm::class),
      '#gallery' => $this->galleryCard(),
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
   * @return array<string, mixed>
   *   Overview from the latest finished analysis only.
   */
  private function galleryCard(): array {
    if (!\Drupal::hasService('xedc_core.gallery_metrics')) {
      return [];
    }
    $site = \Drupal::service('xedc_core.gallery_metrics')->site();
    $original = (int) $site['original_bytes'];
    $optimized = (int) ($site['optimized_known'] ?? 0);
    $saved = $original > $optimized ? $original - $optimized : 0;
    return [
      'files' => (int) $site['files'],
      'known' => (int) $site['known'],
      'unknown' => (int) $site['unknown'],
      'original' => $original > 0 ? OpsFormat::bytes($original) : '原图大小未知',
      'optimized' => OpsFormat::bytes($optimized),
      'saved' => $original > 0 ? OpsFormat::bytes($saved) : '原图大小未知',
      'failed' => (int) $site['failed'],
      'archive_tasks' => (int) $site['archive_tasks'],
      'image_tasks' => (int) $site['image_tasks'],
      'zip_tasks' => (int) $site['zip_tasks'],
      'seven_tasks' => (int) $site['seven_tasks'],
    ];
  }

  /**
   * @return array<string, mixed>
   *   Overview from the latest finished analysis only.
   */
  private function analysisCard(): array {
    $summary = $this->analysis->summary();
    $done = is_array($summary['summary'] ?? NULL) ? $summary['summary'] : [];
    $findings = is_array($done['findings'] ?? NULL) ? $done['findings'] : [];
    $unused = is_array($findings['confirmed_unused'] ?? NULL) ? $findings['confirmed_unused'] : NULL;
    $account = $this->currentUser();
    return [
      'status' => (string) ($summary['status'] ?? 'idle'),
      'message' => (string) ($summary['message'] ?? ''),
      'processed' => (int) ($summary['processed'] ?? 0),
      'total' => (int) ($summary['total'] ?? 0),
      'failures' => (int) ($summary['failures'] ?? 0),
      'finished_batch' => (int) ($summary['finished_batch'] ?? 0),
      'has_batch' => (int) ($summary['finished_batch'] ?? 0) > 0,
      'managed_files' => array_key_exists('managed_files', $done) ? (string) $done['managed_files'] : '',
      'managed_bytes' => array_key_exists('managed_bytes', $done) ? OpsFormat::bytes((int) $done['managed_bytes']) : '',
      'duplicate_groups' => array_key_exists('duplicate_groups', $done) ? (string) $done['duplicate_groups'] : '',
      'reclaimable' => array_key_exists('reclaimable_bytes', $done) ? OpsFormat::bytes((int) $done['reclaimable_bytes']) : '',
      'unused_count' => $unused ? (string) $unused['count'] : ((int) ($summary['finished_batch'] ?? 0) > 0 ? '0' : ''),
      'unused_bytes' => $unused ? OpsFormat::bytes((int) $unused['bytes']) : ((int) ($summary['finished_batch'] ?? 0) > 0 ? OpsFormat::bytes(0) : ''),
      'unknown_count' => isset($findings['unknown']['count']) ? (string) $findings['unknown']['count'] : ((int) ($summary['finished_batch'] ?? 0) > 0 ? '0' : ''),
      'links' => [
        'duplicates' => $account->hasPermission('view xedc duplicate files'),
        'unused' => $account->hasPermission('view xedc unused files'),
        'tasks' => $account->hasPermission('review xedc cleanup plans'),
        'logs' => $account->hasPermission('view xedc cleanup logs'),
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
