<?php

namespace Drupal\xedc_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\xedc_audit\AuditReportExporter;
use Drupal\xedc_audit\SiteAuditReportExporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a combined audit report without storing it on disk.
 */
class AuditDownloadController extends ControllerBase {

  public function __construct(
    protected AuditReportExporter $exporter,
    protected SiteAuditReportExporter $siteExporter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_audit.exporter'),
      $container->get('xedc_audit.site_exporter'),
    );
  }

  /**
   * Runs every enabled Audit analyzer and Site Audit checklist.
   *
   * Scores are not stored. The response is the only copy of the report.
   */
  public function download(string $format): Response {
    $audit = $this->exporter->runAll(FALSE);
    $site = $this->siteExporter->runEnabled();
    $stamp = (new \DateTimeImmutable())->format('Ymd-His');

    if ($format === 'csv') {
      return $this->attachment(
        $this->exporter->toCombinedCsv($audit, $site),
        'text/csv; charset=UTF-8',
        'audit-report-' . $stamp . '.csv',
      );
    }
    return $this->attachment(
      $this->exporter->toCombinedJson($audit, $site),
      'application/json; charset=UTF-8',
      'audit-report-' . $stamp . '.json',
    );
  }

  /**
   * Runs every Site Audit checklist enabled on the report page.
   *
   * No report entity is created and no configuration is written.
   */
  public function downloadSite(string $format): Response {
    $report = $this->siteExporter->runEnabled();
    $stamp = (new \DateTimeImmutable())->format('Ymd-His');

    if ($format === 'csv') {
      return $this->attachment(
        $this->siteExporter->toCsv($report),
        'text/csv; charset=UTF-8',
        'site-audit-report-' . $stamp . '.csv',
      );
    }
    return $this->attachment(
      $this->siteExporter->toJson($report),
      'application/json; charset=UTF-8',
      'site-audit-report-' . $stamp . '.json',
    );
  }

  /**
   * Builds a no-store download response.
   */
  protected function attachment(string $body, string $contentType, string $filename): Response {
    $response = new Response($body);
    $response->headers->set('Content-Type', $contentType);
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
