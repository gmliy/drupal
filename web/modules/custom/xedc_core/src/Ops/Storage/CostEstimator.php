<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Turns configured unit prices into an estimate. It is not a provider invoice.
 */
final class CostEstimator {

  public function __construct(
    protected StorageConfig $config,
    protected ObjectMonitor $monitor,
  ) {}

  /**
   * @return array<string, mixed>
   *   Estimate and the inputs that were actually known.
   */
  public function estimate(): array {
    $pricing = $this->config->data()['pricing'];
    $census = $this->monitor->census(FALSE);
    $counters = $this->monitor->counters();
    $currency = (string) ($pricing['currency'] ?? 'USD');
    $requests = (int) ($counters['upload_ok'] ?? 0) + (int) ($counters['upload_failed'] ?? 0) + (int) ($counters['read_ok'] ?? 0) + (int) ($counters['delete_ok'] ?? 0);
    $objectBytes = (int) ($census['s3_bytes_raw'] ?? 0);
    $storageGb = $objectBytes / 1073741824;
    $billableGb = max(0, $storageGb - (float) ($pricing['free_storage_gb'] ?? 0));
    $billableRequests = max(0, $requests - (int) ($pricing['free_requests'] ?? 0));
    $storageCost = $billableGb * (float) ($pricing['storage_price_per_gb_month'] ?? 0);
    $requestCost = ($billableRequests / 1000) * (float) ($pricing['request_price_per_thousand'] ?? 0);
    $other = (float) ($pricing['other_monthly'] ?? 0);
    return [
      'currency' => $currency,
      'storage_gb' => number_format($storageGb, 4, '.', ''),
      'storage_formatted' => \Drupal\xedc_core\Ops\Storage\StorageSizeFormatter::formatBytes($objectBytes),
      'storage_exact' => \Drupal\xedc_core\Ops\Storage\StorageSizeFormatter::exactLabel($objectBytes),
      'storage_cost' => $this->money($storageCost),
      'download' => '未采集',
      'download_cost' => '未采集',
      'requests' => (string) $requests,
      'request_cost' => $this->money($requestCost),
      'other' => $this->money($other),
      'total' => $this->money($storageCost + $requestCost + $other),
      'priced' => ((float) ($pricing['storage_price_per_gb_month'] ?? 0) + (float) ($pricing['request_price_per_thousand'] ?? 0) + $other) > 0,
      'disclaimer' => '这是按本页单价和 Drupal 文件实体算出的估算，不是供应商账单。下载流量没有采集。没有账单接口的供应商不会显示实时账单。',
      'pricing' => $pricing,
    ];
  }

  private function money(float $amount): string {
    return number_format($amount, 4, '.', '');
  }

}
