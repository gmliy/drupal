<?php

namespace Drupal\xedc_audit;

use Drupal\audit\AuditAnalyzerPluginManager;
use Drupal\audit\Service\AuditRunner;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs enabled audits and renders the combined report in memory.
 */
class AuditReportExporter {

  /**
   * JSON flags matching drush audit:run --format=json.
   */
  private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

  /**
   * Columns of the flat CSV report.
   */
  private const CSV_HEADER = [
    'audit_id',
    'audit_name',
    'status',
    'score',
    'grade',
    'errors',
    'warnings',
    'notices',
    'total_issues',
    'severity',
    'category',
    'code',
    'check',
    'file',
    'line',
    'module',
    'message',
    'recommendation',
    'data',
  ];

  protected LoggerInterface $logger;

  public function __construct(
    protected AuditRunner $runner,
    protected AuditAnalyzerPluginManager $pluginManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('xedc_audit');
  }

  /**
   * Runs every enabled analyzer.
   *
   * @return array<string, mixed>
   *   Combined report: timestamp, count, failed, totals, results.
   */
  public function runAll(bool $saveScore = TRUE): array {
    return $this->run('all', $saveScore);
  }

  /**
   * Runs one analyzer, a comma-separated list, or "all".
   *
   * Pass $saveScore FALSE for a read-only run. The browser download does
   * that, so stored scores, content, and configuration stay unchanged.
   * Drush keeps the default so `audit:run` still records scores in State,
   * which is what the Audit module already does. Nothing is written to
   * the filesystem.
   *
   * @return array<string, mixed>
   *   Combined report: timestamp, count, failed, totals, results.
   */
  public function run(string $analyzerId, bool $saveScore = TRUE): array {
    @set_time_limit(600);

    $definitions = $this->pluginManager->getDefinitions();
    $ids = $this->resolveIds($analyzerId, $definitions);
    $results = [];
    $failed = [];
    foreach ($ids as $id) {
      try {
        $result = $this->runner->runAnalyzer($id, $saveScore);
      }
      catch (\Throwable $e) {
        $this->logger->error('Audit analyzer @id failed: @message', [
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]);
        $result = NULL;
      }
      if ($result === NULL) {
        $failed[] = $id;
        continue;
      }
      $results[$id] = $this->stripInternalKeys($result);
    }

    $totals = [
      'errors' => 0,
      'warnings' => 0,
      'notices' => 0,
      'total_issues' => 0,
      'acknowledged' => 0,
    ];
    foreach ($results as $result) {
      $summary = $result['summary'] ?? [];
      foreach ($totals as $key => $value) {
        $totals[$key] += (int) ($summary[$key] ?? 0);
      }
    }

    return [
      'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
      'count' => count($results),
      'failed' => $failed,
      'totals' => $totals,
      'results' => $results,
    ];
  }

  /**
   * Encodes a combined report as pretty JSON.
   */
  public function toJson(array $report): string {
    $json = json_encode($report, self::JSON_FLAGS);
    return $json === FALSE ? '{}' : $json;
  }

  /**
   * JSON for every Audit analyzer plus the enabled Site Audit checklists.
   *
   * The Audit keys stay in their existing order. Site Audit is appended.
   */
  public function toCombinedJson(array $audit, array $site): string {
    $audit['site_audit'] = $site;
    return $this->toJson($audit);
  }

  /**
   * Flattens a combined report to CSV, one row per finding.
   */
  public function toCsv(array $report): string {
    $handle = fopen('php://memory', 'r+');
    if ($handle === FALSE) {
      return '';
    }

    fputcsv($handle, self::CSV_HEADER, ',', '"', '\\', "\n");
    foreach ($report['results'] ?? [] as $id => $result) {
      if (!is_array($result)) {
        continue;
      }
      $findings = $result['findings'] ?? [];
      if (!is_array($findings) || $findings === []) {
        $this->writeCsvRow($handle, $this->auditCells((string) $id, $result, []));
        continue;
      }
      foreach ($findings as $finding) {
        $this->writeCsvRow($handle, $this->auditCells((string) $id, $result, is_array($finding) ? $finding : []));
      }
    }
    foreach ($report['failed'] ?? [] as $id) {
      $this->writeCsvRow($handle, $this->auditCells((string) $id, [
        'audit_name' => (string) $id,
        'status' => 'failed',
        'summary' => [],
      ], []));
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === FALSE ? '' : $csv;
  }

  /**
   * Drops UI-only keys, matching the Drush JSON shape.
   *
   * @param array<string, mixed> $result
   *   One analyzer result.
   *
   * @return array<string, mixed>
   *   The result without internal keys.
   */
  protected function stripInternalKeys(array $result): array {
    $cleaned = array_filter(
      $result,
      static fn(string $key): bool => !str_starts_with($key, '_'),
      ARRAY_FILTER_USE_KEY,
    );
    unset($cleaned['results']);
    return $cleaned;
  }

  /**
   * Resolves "all", one ID, or a comma-separated list.
   *
   * Unknown IDs are omitted; the caller records an empty run as failure.
   *
   * @param array<string, mixed> $definitions
   *   Enabled analyzer definitions.
   *
   * @return array<int, string>
   *   Analyzer IDs to run.
   */
  protected function resolveIds(string $analyzerId, array $definitions): array {
    if (strtolower(trim($analyzerId)) === 'all') {
      uasort($definitions, static fn(array $a, array $b): int => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
      return array_map('strval', array_keys($definitions));
    }

    $ids = [];
    foreach (array_filter(array_map('trim', explode(',', $analyzerId))) as $id) {
      if (isset($definitions[$id])) {
        $ids[] = $id;
      }
      else {
        $this->logger->error('Audit analyzer @id was not found.', ['@id' => $id]);
      }
    }
    return $ids;
  }

  /**
   * CSV for Audit analyzers and Site Audit checks in one stream.
   *
   * Audit rows keep the original columns. Site Audit rows fill those
   * columns where they match and the extra columns at the end.
   */
  public function toCombinedCsv(array $audit, array $site): string {
    $handle = fopen('php://memory', 'r+');
    if ($handle === FALSE) {
      return '';
    }

    $header = array_merge(['source'], self::CSV_HEADER, [
      'checklist_id',
      'checklist_label',
      'percent',
      'check_id',
      'check_label',
      'score_label',
      'result',
      'action',
    ]);
    fputcsv($handle, $header, ',', '"', '\\', "\n");
    $padding = array_fill(0, 8, '');

    foreach ($audit['results'] ?? [] as $id => $result) {
      if (!is_array($result)) {
        continue;
      }
      $findings = $result['findings'] ?? [];
      if (!is_array($findings) || $findings === []) {
        $this->writeCsvRow($handle, array_merge(['audit'], $this->auditCells((string) $id, $result, []), $padding));
        continue;
      }
      foreach ($findings as $finding) {
        $cells = $this->auditCells((string) $id, $result, is_array($finding) ? $finding : []);
        $this->writeCsvRow($handle, array_merge(['audit'], $cells, $padding));
      }
    }
    foreach ($audit['failed'] ?? [] as $id) {
      $cells = $this->auditCells((string) $id, [
        'audit_name' => (string) $id,
        'status' => 'failed',
        'summary' => [],
      ], []);
      $this->writeCsvRow($handle, array_merge(['audit'], $cells, $padding));
    }

    $reports = is_array($site['reports'] ?? NULL) ? $site['reports'] : [];
    foreach ($reports as $checklistId => $checklist) {
      if (!is_array($checklist)) {
        continue;
      }
      $checks = $checklist['checks'] ?? [];
      if (!is_array($checks) || $checks === []) {
        $this->writeCsvRow($handle, $this->siteCells((string) $checklistId, $checklist, '', []));
        continue;
      }
      foreach ($checks as $checkId => $check) {
        $this->writeCsvRow($handle, $this->siteCells((string) $checklistId, $checklist, (string) $checkId, is_array($check) ? $check : []));
      }
    }
    foreach ($site['failed'] ?? [] as $id) {
      $this->writeCsvRow($handle, $this->siteCells((string) $id, [
        'label' => (string) $id,
        'percent' => '',
      ], '', [
        'score_label' => 'failed',
        'label' => '',
      ]));
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === FALSE ? '' : $csv;
  }

  /**
   * Writes one CSV row.
   *
   * @param resource $handle
   *   The memory stream.
   * @param array<int, string|int|float> $row
   *   The cell values.
   */
  protected function writeCsvRow($handle, array $row): void {
    fputcsv($handle, $row, ',', '"', '\\', "\n");
  }

  /**
   * Builds the original 19 Audit columns.
   *
   * @param array<string, mixed> $result
   *   The analyzer result.
   * @param array<string, mixed> $finding
   *   One finding, or an empty array when the analyzer has none.
   *
   * @return array<int, string|int|float>
   *   Cell values.
   */
  protected function auditCells(string $id, array $result, array $finding): array {
    $summary = is_array($result['summary'] ?? NULL) ? $result['summary'] : [];
    $score = is_array($result['score'] ?? NULL) ? $result['score'] : [];
    $data = $finding['data'] ?? [];
    if (!is_array($data)) {
      $data = ['value' => $data];
    }

    return [
      $id,
      $this->cell($result['audit_name'] ?? $id),
      $this->cell($result['status'] ?? ''),
      $score['total'] ?? '',
      $this->cell($score['grade'] ?? ''),
      (int) ($summary['errors'] ?? 0),
      (int) ($summary['warnings'] ?? 0),
      (int) ($summary['notices'] ?? 0),
      (int) ($summary['total_issues'] ?? 0),
      $this->cell($finding['severity'] ?? ''),
      $this->cell($finding['category'] ?? ''),
      $this->cell($finding['code'] ?? ''),
      $this->cell($finding['check'] ?? ''),
      $this->cell($finding['file'] ?? ''),
      $finding['line'] ?? '',
      $this->cell($finding['module'] ?? ''),
      $this->cell($finding['message'] ?? ''),
      $this->cell($finding['recommendation'] ?? ''),
      $data === [] ? '' : $this->cell(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
    ];
  }

  /**
   * Builds one Site Audit row on the combined header.
   *
   * @param array<string, mixed> $checklist
   *   One checklist from the Site Audit export.
   * @param array<string, mixed> $check
   *   One check, or a placeholder when the checklist has none.
   *
   * @return array<int, string|int|float>
   *   Cell values.
   */
  protected function siteCells(string $checklistId, array $checklist, string $checkId, array $check): array {
    $result = $check['result'] ?? '';
    $action = $check['action'] ?? '';
    $data = [
      'description' => $check['description'] ?? '',
      'result' => $result,
      'action' => $action,
    ];
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    return [
      'site_audit',
      '',
      '',
      $this->cell($check['score_label'] ?? ''),
      $check['score'] ?? '',
      '',
      '',
      '',
      '',
      '',
      $this->cell($check['score_label'] ?? ''),
      $checklistId,
      $checkId,
      $this->cell($check['label'] ?? ''),
      '',
      '',
      '',
      $this->plainText($result),
      $this->plainText($action),
      $encoded === FALSE ? '' : $this->cell($encoded),
      $checklistId,
      $this->cell($checklist['label'] ?? $checklistId),
      $checklist['percent'] ?? '',
      $checkId,
      $this->cell($check['label'] ?? ''),
      $this->cell($check['score_label'] ?? ''),
      $this->plainText($result),
      $this->plainText($action),
    ];
  }

  /**
   * Flattens a Site Audit value into one CSV cell.
   */
  protected function plainText(mixed $value): string {
    if (is_array($value)) {
      $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      return $this->cell($encoded === FALSE ? '' : $encoded);
    }
    return $this->cell($value);
  }

  /**
   * Renders a CSV cell and neutralizes spreadsheet formulas.
   */
  protected function cell(mixed $value): string {
    if ($value === NULL) {
      return '';
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_array($value) || is_object($value)) {
      $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      $value = $encoded === FALSE ? '' : $encoded;
    }
    $string = (string) $value;
    if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t"], TRUE)) {
      return "'" . $string;
    }
    return $string;
  }

}
