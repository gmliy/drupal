<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Collects interface strings for one enabled module.
 */
final class StringCollector {

  public function __construct(
    private readonly InterfaceStringExtractor $extractor,
    private readonly ModuleExtensionList $modules,
    private readonly Connection $database,
  ) {}

  /**
   * @return array{machine: string, label: string, project: string, package: string, path: string}
   */
  public function meta(string $module): array {
    if (!$this->modules->exists($module)) {
      return [
        'machine' => $module,
        'label' => '',
        'project' => $module,
        'package' => '',
        'path' => '',
      ];
    }
    $path = $this->modules->getPath($module);
    $meta = $this->extractor->readMeta(DRUPAL_ROOT . '/' . $path, $module);
    $meta['path'] = $path;
    return $meta;
  }

  /**
   * @return list<array{source: string, context: string, file: string}>
   */
  public function collect(string $module): array {
    if (!$this->modules->exists($module)) {
      return [];
    }
    $path = $this->modules->getPath($module);
    $root = DRUPAL_ROOT . '/' . $path;
    $found = $this->extractor->extractDirectory($root);
    $seen = [];
    foreach ($found as $item) {
      $seen[$item['context'] . "\n" . $item['source']] = TRUE;
    }
    $query = $this->database->select('locales_source', 's');
    $query->fields('s', ['source', 'context']);
    $query->innerJoin('locales_location', 'l', 'l.sid = s.lid');
    $query->condition('l.name', $this->database->escapeLike($path) . '%', 'LIKE');
    $query->distinct();
    foreach ($query->execute() as $row) {
      $source = (string) $row->source;
      $context = (string) $row->context;
      if (!$this->extractor->acceptSource($source)) {
        continue;
      }
      $key = $context . "\n" . $source;
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $found[] = [
        'source' => $source,
        'context' => $context,
        'file' => $path,
      ];
    }
    return $found;
  }

}
