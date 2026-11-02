<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale\Drush\Commands;

use Drupal\xedc_ui_locale\TranslationTaskRepository;
use Drupal\xedc_ui_locale\UiTranslationManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Simplified Chinese interface translation.
 */
final class UiTranslateCommands extends DrushCommands {

  public function __construct(
    private readonly UiTranslationManager $manager,
  ) {
    parent::__construct();
  }

  /**
   * Imports official zh-hans packs, then translates the remaining UI strings.
   */
  #[CLI\Command(name: 'xedc:ui-translate', aliases: ['xedc-ui-translate'])]
  #[CLI\Argument(name: 'modules', description: '逗号分隔的模块机器名。省略时处理队列中的模块。')]
  #[CLI\Option(name: 'all', description: '扫描全部已启用模块')]
  #[CLI\Option(name: 'official', description: '只导入官方简体中文语言包')]
  #[CLI\Option(name: 'missing', description: '只翻译官方语言包没有覆盖的字符串')]
  #[CLI\Option(name: 'retry', description: '重试失败的界面翻译')]
  #[CLI\Usage(name: 'drush xedc:ui-translate token', description: '扫描并翻译 token 模块的界面字符串')]
  #[CLI\Usage(name: 'drush xedc:ui-translate --all', description: '扫描全部已启用模块')]
  #[CLI\Usage(name: 'drush xedc:ui-translate token --official', description: '只导入 token 的官方简体中文语言包')]
  #[CLI\Usage(name: 'drush xedc:ui-translate token --missing', description: '只翻译 token 仍然缺失的界面字符串')]
  #[CLI\Usage(name: 'drush xedc:ui-translate --retry', description: '重试失败的界面翻译')]
  public function translate(?string $modules = NULL, array $options = [
    'all' => FALSE,
    'official' => FALSE,
    'missing' => FALSE,
    'retry' => FALSE,
  ]): void {
    $operation = 'full';
    if (!empty($options['retry'])) {
      $operation = 'retry';
    }
    elseif (!empty($options['official'])) {
      $operation = 'official';
    }
    elseif (!empty($options['missing'])) {
      $operation = 'missing';
    }
    $names = $this->moduleNames($modules, !empty($options['all']));
    if ($names === []) {
      $this->logger()->warning('没有待处理的模块。用法：drush xedc:ui-translate MODULE，或 drush xedc:ui-translate --all');
      return;
    }
    $invalid = $this->invalidModules($names);
    if ($invalid !== []) {
      throw new \InvalidArgumentException('这些模块未启用或不存在：' . implode(', ', $invalid));
    }
    $platform = $this->manager->platform();
    if ($platform['key_configured']) {
      $this->logger()->notice('界面翻译复用 {service}::{method}，提供商 {provider}。密钥读取自 {storage}，不会在这里显示。', [
        'service' => $platform['service'],
        'method' => $platform['method'],
        'provider' => $platform['provider_label'],
        'storage' => $platform['key_storage'],
      ]);
    }
    elseif ($operation !== 'official') {
      $this->logger()->warning('未配置 {provider} API Key，官方语言包仍会导入，AI 补翻会记为失败。', [
        'provider' => $platform['provider_label'],
      ]);
    }
    $result = $this->manager->run($names, $operation);
    foreach ($result['errors'] as $error) {
      $this->logger()->error('{error}', ['error' => $error]);
    }
    foreach ($result['modules'] as $module => $scan) {
      $this->logger()->notice('模块 {module}：状态 {status}，官方 {official} 条，AI {api} 条，剩余 {remaining} 条。{message}', [
        'module' => $module,
        'status' => $scan['status'] ?: 'completed',
        'official' => $scan['official'],
        'api' => $scan['api'],
        'remaining' => $scan['remaining'],
        'message' => $scan['message'] !== '' ? $scan['message'] : '',
      ]);
      foreach ($scan['samples'] as $sample) {
        $this->logger()->warning('  未翻译：{sample}', ['sample' => $sample]);
      }
    }
  }

  /**
   * Prints interface translation statistics.
   */
  #[CLI\Command(name: 'xedc:ui-locale-status', aliases: ['xedc-ui-locale-status'])]
  #[CLI\Usage(name: 'drush xedc:ui-locale-status', description: '查看界面翻译统计')]
  public function status(): void {
    $platform = $this->manager->platform();
    $stats = $this->manager->statistics();
    $labels = [
      'enabled' => '已启用模块',
      'scanned' => '已扫描模块',
      'official' => '官方翻译',
      'ai' => 'AI 翻译',
      'existing' => '已有翻译',
      'remaining' => '待翻译',
      'failed' => '翻译失败',
    ];
    foreach ($labels as $key => $label) {
      $this->output()->writeln($label . '：' . (string) ($stats[$key] ?? 0));
    }
    $this->output()->writeln('提供商：' . $platform['provider_label']);
    $this->output()->writeln('服务：' . $platform['service'] . '::' . $platform['method']);
    $this->output()->writeln('配置：' . $platform['config']);
    $this->output()->writeln('密钥来源：' . $platform['key_storage']);
    $this->output()->writeln('密钥已配置：' . ($platform['key_configured'] ? '是' : '否'));
  }

  /**
   * Lists interface strings that still have no Simplified Chinese translation.
   */
  #[CLI\Command(name: 'xedc:ui-locale-untranslated', aliases: ['xedc-ui-locale-untranslated'])]
  #[CLI\Option(name: 'module', description: '只查看一个模块')]
  #[CLI\Option(name: 'limit', description: '最多显示多少条')]
  #[CLI\Usage(name: 'drush xedc:ui-locale-untranslated --module=token --limit=20', description: '查看 token 尚未翻译的界面字符串')]
  public function untranslated(array $options = ['module' => '', 'limit' => 50]): void {
    $module = trim((string) $options['module']);
    if ($module !== '' && !in_array($module, $this->manager->enabledModules(), TRUE)) {
      throw new \InvalidArgumentException('模块未启用：' . $module);
    }
    $limit = max(1, (int) $options['limit']);
    $rows = \Drupal::service(TranslationTaskRepository::class)->untranslated($limit, $module);
    if ($rows === []) {
      $this->logger()->notice('没有未翻译的界面字符串记录。');
      return;
    }
    foreach ($rows as $row) {
      $this->output()->writeln(sprintf('%s [%s] %s', $row['module'], $row['status'], $row['source']));
      if (!empty($row['reason'])) {
        $this->output()->writeln('  ' . $row['reason']);
      }
    }
  }

  /**
   * @return list<string>
   */
  private function moduleNames(?string $modules, bool $all): array {
    if ($all) {
      return $this->manager->enabledModules();
    }
    if ($modules !== NULL && trim($modules) !== '') {
      return array_values(array_filter(array_map('trim', explode(',', $modules))));
    }
    return $this->manager->pendingModules();
  }

  /**
   * @param list<string> $names
   * @return list<string>
   */
  private function invalidModules(array $names): array {
    $enabled = array_flip($this->manager->enabledModules());
    $invalid = [];
    foreach ($names as $name) {
      if (!preg_match('/^[a-z][a-z0-9_]*$/', $name) || !isset($enabled[$name])) {
        $invalid[] = $name;
      }
    }
    return $invalid;
  }

}
