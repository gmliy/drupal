<?php

namespace Drupal\xedc_audit\Drush\Commands;

use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\audit\AuditAnalyzerPluginManager;
use Drupal\audit\Drush\Commands\AuditCommands;
use Drupal\audit\Service\AuditRunner;
use Drupal\audit\Service\AuditScoreStorage;
use Drupal\xedc_audit\AuditReportExporter;
use Drupal\xedc_audit\SiteAuditReportExporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds CSV output, and Site Audit data for a full run, to audit:run.
 */
final class AuditFormatCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Values accepted by audit:run --fail-on.
   */
  private const FAIL_ON_VALUES = ['never', 'error', 'warning', 'any'];

  public function __construct(
    #[Autowire(service: 'audit.runner')]
    protected AuditRunner $runner,
    #[Autowire(service: 'plugin.manager.audit_analyzer')]
    protected AuditAnalyzerPluginManager $pluginManager,
    #[Autowire(service: 'audit.score_storage')]
    protected AuditScoreStorage $scoreStorage,
    #[Autowire(service: 'xedc_audit.exporter')]
    protected AuditReportExporter $exporter,
    #[Autowire(service: 'xedc_audit.site_exporter')]
    protected SiteAuditReportExporter $siteExporter,
  ) {
    parent::__construct();
  }

  /**
   * Prints CSV, and includes Site Audit when the argument is "all".
   *
   * Filtered runs, table output, and a single analyzer keep the original
   * Audit command. Scores are stored in State, matching that command.
   * The report itself is not written to disk.
   */
  #[CLI\Hook(type: HookManager::REPLACE_COMMAND_HOOK, target: 'audit:run')]
  public function replaceAuditRun(string $analyzerId, array $options = []): int {
    $format = strtolower((string) ($options['format'] ?? 'json'));
    $filter = trim((string) ($options['filter'] ?? ''));
    $failOn = (string) ($options['fail-on'] ?? 'never');
    $isAll = strtolower(trim($analyzerId)) === 'all';

    if ($filter !== '' || !in_array($failOn, self::FAIL_ON_VALUES, TRUE) || !in_array($format, ['json', 'csv'], TRUE)) {
      return $this->delegate($analyzerId, $options);
    }
    if ($format === 'json' && !$isAll) {
      return $this->delegate($analyzerId, $options);
    }
    if ($format === 'csv' && !$isAll) {
      $report = $this->exporter->run($analyzerId);
      $this->output()->write($this->exporter->toCsv($report));
      return $this->exitCode($report, $failOn, $analyzerId);
    }

    $this->logger()?->notice(dt('Running all enabled Audit analyzers and Site Audit checklists.'));
    $audit = $this->exporter->run('all');
    $site = $this->siteExporter->runEnabled();
    if ($format === 'csv') {
      $this->output()->write($this->exporter->toCombinedCsv($audit, $site));
    }
    else {
      $this->output()->writeln($this->exporter->toCombinedJson($audit, $site));
    }
    $code = $this->exitCode($audit, $failOn, $analyzerId);
    if ($code !== self::EXIT_SUCCESS) {
      return $code;
    }
    if (($site['failed'] ?? []) !== []) {
      $this->logger()?->error(dt('Site Audit checklists failed: @ids', [
        '@ids' => implode(', ', $site['failed']),
      ]));
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Hands the invocation back to the Audit module command.
   *
   * @param array<string, mixed> $options
   *   The original command options.
   */
  protected function delegate(string $analyzerId, array $options): int {
    $original = new AuditCommands($this->runner, $this->pluginManager, $this->scoreStorage);
    $original->setInput($this->input());
    $original->setOutput($this->output());
    $logger = $this->logger();
    if ($logger) {
      $original->setLogger($logger);
    }
    return $original->runAnalyzer($analyzerId, $options);
  }

  /**
   * Maps an Audit report to the same exit codes as audit:run.
   *
   * @param array<string, mixed> $report
   *   The combined Audit report.
   */
  protected function exitCode(array $report, string $failOn, string $analyzerId): int {
    if (($report['count'] ?? 0) === 0) {
      $this->logger()?->error(dt('No audit results were produced for @id.', ['@id' => $analyzerId]));
      return self::EXIT_FAILURE;
    }
    if (($report['failed'] ?? []) !== []) {
      return self::EXIT_FAILURE;
    }
    $totals = is_array($report['totals'] ?? NULL) ? $report['totals'] : [];
    $errors = (int) ($totals['errors'] ?? 0);
    $warnings = (int) ($totals['warnings'] ?? 0);
    $issues = (int) ($totals['total_issues'] ?? 0);
    $reached = match ($failOn) {
      'error' => $errors > 0,
      'warning' => ($errors + $warnings) > 0,
      'any' => $issues > 0,
      default => FALSE,
    };
    return $reached ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

}
