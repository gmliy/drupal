<?php

namespace Drupal\xedc_audit\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds download buttons to the audit overview pages.
 */
class AuditOverviewSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run before the main-content subscriber turns the render array into a
    // response. Equal priority would lose that race.
    return [KernelEvents::VIEW => ['onView', 30]];
  }

  /**
   * Inserts JSON and CSV download links into both audit overviews.
   */
  public function onView(ViewEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route = $event->getRequest()->attributes->get('_route');
    $result = $event->getControllerResult();
    if (!is_array($result)) {
      return;
    }

    if ($route === 'audit.reports') {
      $this->addAuditDownloads($result);
      $event->setControllerResult($result);
      return;
    }
    if ($route === 'site_audit.report') {
      $this->addSiteAuditDownloads($result);
      $event->setControllerResult($result);
    }
  }

  /**
   * Adds the buttons to the existing Audit overview actions.
   *
   * @param array<string, mixed> $result
   *   The overview render array.
   */
  protected function addAuditDownloads(array &$result): void {
    if (!isset($result['actions']) || !is_array($result['actions'])) {
      $result['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['audit-list-actions']],
        '#weight' => -10,
      ];
    }
    $result['actions']['download_json'] = $this->downloadLink('xedc_audit.download', 'json');
    $result['actions']['download_csv'] = $this->downloadLink('xedc_audit.download', 'csv');
  }

  /**
   * Adds the buttons above the Site Audit report.
   *
   * @param array<string, mixed> $result
   *   The report render array.
   */
  protected function addSiteAuditDownloads(array &$result): void {
    $result['xedc_audit_downloads'] = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => [
        'class' => ['xedc-audit-downloads'],
        'style' => 'display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;margin:0 0 1rem;',
      ],
      'download_json' => $this->downloadLink('xedc_audit.site_download', 'json'),
      'download_csv' => $this->downloadLink('xedc_audit.site_download', 'csv'),
    ];
  }

  /**
   * Builds one CSRF-protected download link.
   *
   * @return array<string, mixed>
   *   The link render array.
   */
  protected function downloadLink(string $route, string $format): array {
    $title = $format === 'csv'
      ? $this->t('下载全部报告（CSV）')
      : $this->t('下载全部报告（JSON）');
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute($route, ['format' => $format]),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];
  }

}
