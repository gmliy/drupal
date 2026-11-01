<?php

namespace Drupal\xedc_audit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\site_audit\Plugin\SiteAuditChecklistManager;
use Psr\Log\LoggerInterface;

/**
 * Runs enabled Site Audit checklists and keeps the report in memory.
 *
 * This follows the same checklist selection as the Site Audit page and does
 * not create report entities or write configuration.
 */
class SiteAuditReportExporter {

  /**
   * JSON flags. Invalid UTF-8 is substituted so the download stays valid.
   */
  private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

  /**
   * Columns of the Site Audit CSV.
   */
  private const CSV_HEADER = [
    'checklist_id',
    'checklist_label',
    'percent',
    'check_id',
    'check_label',
    'description',
    'score',
    'score_label',
    'result',
    'action',
  ];

  protected LoggerInterface $logger;

  public function __construct(
    protected SiteAuditChecklistManager $checklistManager,
    protected ConfigFactoryInterface $configFactory,
    protected RendererInterface $renderer,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('xedc_audit');
  }

  /**
   * Runs every checklist enabled on the Site Audit report page.
   *
   * @return array<string, mixed>
   *   Report with timestamp, count, failed, and reports.
   */
  public function runEnabled(): array {
    @set_time_limit(600);

    $definitions = $this->checklistManager->getDefinitions();
    $reports = [];
    $failed = [];
    foreach ($this->enabledIds($definitions) as $id) {
      try {
        $checklist = $this->checklistManager->createInstance($id);
        $checks = [];
        foreach ($checklist->getCheckObjects() as $checkId => $check) {
          $checks[(string) $checkId] = [
            'label' => $this->plain($check->getLabel()),
            'description' => $this->plain($check->getDescription()),
            'score' => $check->getScore(),
            'score_label' => $this->plain($check->getScoreLabel()),
            'result' => $this->plain($check->getResult()),
            'action' => $this->plain($check->renderAction()),
          ];
        }
        $reports[$id] = [
          'label' => $this->plain($checklist->getLabel()),
          'percent' => $checklist->getPercent(),
          'checks' => $checks,
        ];
      }
      catch (\Throwable $e) {
        $this->logger->error('Site Audit checklist @id failed: @message', [
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]);
        $failed[] = $id;
      }
    }

    return [
      'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
      'source' => 'site_audit',
      'count' => count($reports),
      'failed' => $failed,
      'reports' => $reports,
    ];
  }

  /**
   * Encodes a Site Audit report as pretty JSON.
   */
  public function toJson(array $report): string {
    $json = json_encode($report, self::JSON_FLAGS);
    return $json === FALSE ? '{}' : $json;
  }

  /**
   * Flattens a Site Audit report to CSV, one row per check.
   */
  public function toCsv(array $report): string {
    $handle = fopen('php://memory', 'r+');
    if ($handle === FALSE) {
      return '';
    }

    fputcsv($handle, self::CSV_HEADER, ',', '"', '\\', "\n");
    $reports = is_array($report['reports'] ?? NULL) ? $report['reports'] : [];
    foreach ($reports as $checklistId => $checklist) {
      if (!is_array($checklist)) {
        continue;
      }
      $checks = $checklist['checks'] ?? [];
      if (!is_array($checks) || $checks === []) {
        $this->writeRow($handle, (string) $checklistId, $checklist, '', []);
        continue;
      }
      foreach ($checks as $checkId => $check) {
        $this->writeRow($handle, (string) $checklistId, $checklist, (string) $checkId, is_array($check) ? $check : []);
      }
    }
    foreach ($report['failed'] ?? [] as $id) {
      $this->writeRow($handle, (string) $id, [
        'label' => (string) $id,
        'percent' => '',
      ], '', [
        'score_label' => 'failed',
      ]);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === FALSE ? '' : $csv;
  }

  /**
   * Selects checklists the same way the Site Audit page does.
   *
   * An empty selection, or a selection where every box is unchecked,
   * runs every available checklist. Otherwise only the checked ones run.
   *
   * @param array<string, mixed> $definitions
   *   Checklist plugin definitions.
   *
   * @return array<int, string>
   *   Checklist IDs.
   */
  protected function enabledIds(array $definitions): array {
    $saved = $this->configFactory->get('site_audit.settings')->get('reports');
    if (!is_array($saved) || $saved === [] || count(array_flip($saved)) <= 1) {
      return array_map('strval', array_keys($definitions));
    }

    $ids = [];
    foreach ($saved as $id) {
      if (!$id) {
        continue;
      }
      $id = (string) $id;
      if (isset($definitions[$id])) {
        $ids[] = $id;
      }
      else {
        $this->logger->error('Site Audit checklist @id is enabled but not available.', ['@id' => $id]);
      }
    }
    return $ids;
  }

  /**
   * Writes one Site Audit CSV row.
   *
   * @param resource $handle
   *   The memory stream.
   * @param array<string, mixed> $checklist
   *   Checklist summary.
   * @param array<string, mixed> $check
   *   One check.
   */
  protected function writeRow($handle, string $checklistId, array $checklist, string $checkId, array $check): void {
    fputcsv($handle, [
      $checklistId,
      $this->cell($checklist['label'] ?? $checklistId),
      $checklist['percent'] ?? '',
      $checkId,
      $this->cell($check['label'] ?? ''),
      $this->cell($check['description'] ?? ''),
      $check['score'] ?? '',
      $this->cell($check['score_label'] ?? ''),
      $this->cell($check['result'] ?? ''),
      $this->cell($check['action'] ?? ''),
    ], ',', '"', '\\', "\n");
  }

  /**
   * Converts check output to JSON-safe text or a table structure.
   */
  protected function plain(mixed $value): mixed {
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if ($value === NULL) {
      return '';
    }
    if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
      return $value;
    }
    if (!is_array($value)) {
      return '';
    }
    if (($value['#theme'] ?? NULL) === 'table') {
      $header = [];
      foreach ($value['#header'] ?? [] as $cell) {
        $header[] = $this->tableCell(is_array($cell) && array_key_exists('data', $cell) ? $cell['data'] : $cell);
      }
      $rows = [];
      foreach ($value['#rows'] ?? [] as $row) {
        if (!is_array($row)) {
          $rows[] = [$this->tableCell($row)];
          continue;
        }
        $line = [];
        foreach ($row as $cell) {
          $data = is_array($cell) && array_key_exists('data', $cell) ? $cell['data'] : $cell;
          $line[] = $this->tableCell($data);
        }
        $rows[] = $line;
      }
      return ['header' => $header, 'rows' => $rows];
    }
    if ($this->isRenderArray($value)) {
      try {
        $html = (string) $this->renderer->renderInIsolation($value);
      }
      catch (\Throwable $e) {
        return '';
      }
      $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      $collapsed = preg_replace('/\s+/u', ' ', $text);
      return $collapsed === NULL ? $text : $collapsed;
    }
    return '';
  }

  /**
   * Renders one table cell without CSV escaping.
   */
  protected function tableCell(mixed $value): string {
    $plain = $this->plain($value);
    if (is_array($plain)) {
      $encoded = json_encode($plain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      return $encoded === FALSE ? '' : $encoded;
    }
    if (is_bool($plain)) {
      return $plain ? 'true' : 'false';
    }
    return (string) $plain;
  }

  /**
   * Whether an array is a Drupal render array.
   *
   * @param array<mixed> $value
   *   Candidate value.
   */
  protected function isRenderArray(array $value): bool {
    foreach ($value as $key => $item) {
      if (is_string($key) && str_starts_with($key, '#')) {
        return TRUE;
      }
    }
    return FALSE;
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
      $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      $value = $encoded === FALSE ? '' : $encoded;
    }
    $string = (string) $value;
    if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t"], TRUE)) {
      return "'" . $string;
    }
    return $string;
  }

}
