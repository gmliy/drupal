<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Symfony\Component\Yaml\Yaml;

/**
 * Collects interface strings from a module directory.
 *
 * This reads source files only. It does not load nodes, media, or other
 * site content.
 */
final class InterfaceStringExtractor {

  /**
   * @return list<array{source: string, context: string, file: string}>
   */
  public function extractDirectory(string $root): array {
    $root = rtrim($root, '/');
    if (!is_dir($root)) {
      return [];
    }
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->getSize() > 1048576) {
        continue;
      }
      $pathname = $file->getPathname();
      if ($this->skipPath($root, $pathname)) {
        continue;
      }
      $extension = strtolower($file->getExtension());
      $contents = file_get_contents($pathname);
      if ($contents === FALSE || $contents === '') {
        continue;
      }
      $relative = ltrim(substr($pathname, strlen($root)), '/');
      if (in_array($extension, ['php', 'module', 'install', 'inc', 'theme'], TRUE)) {
        $this->extractPhp($contents, $relative, $found);
      }
      elseif ($extension === 'js' && !str_ends_with($file->getFilename(), '.min.js')) {
        $this->extractJavaScript($contents, $relative, $found);
      }
      elseif ($extension === 'twig') {
        $this->extractTwig($contents, $relative, $found);
      }
      elseif ($extension === 'yml' || $extension === 'yaml') {
        $this->extractYaml($file->getFilename(), $contents, $relative, $found);
      }
    }
    return $this->unique($found);
  }

  /**
   * @return array{machine: string, label: string, project: string, package: string}
   */
  public function readMeta(string $root, string $machine): array {
    $meta = [
      'machine' => $machine,
      'label' => '',
      'project' => $machine,
      'package' => '',
    ];
    $info_file = $root . '/' . $machine . '.info.yml';
    if (is_file($info_file)) {
      try {
        $info = Yaml::parseFile($info_file);
      }
      catch (\Throwable) {
        $info = NULL;
      }
      if (is_array($info)) {
        $meta['label'] = trim((string) ($info['name'] ?? ''));
        $project = trim((string) ($info['project'] ?? $info['interface translation project'] ?? ''));
        if ($project !== '') {
          $meta['project'] = $project;
        }
      }
    }
    $composer_file = $root . '/composer.json';
    if (is_file($composer_file)) {
      try {
        $composer = Yaml::parseFile($composer_file);
      }
      catch (\Throwable) {
        $composer = NULL;
      }
      if (!is_array($composer)) {
        $decoded = json_decode((string) file_get_contents($composer_file), TRUE);
        $composer = is_array($decoded) ? $decoded : NULL;
      }
      if (is_array($composer)) {
        $meta['package'] = trim((string) ($composer['name'] ?? ''));
      }
    }
    return $meta;
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $found
   */
  private function extractPhp(string $contents, string $file, array &$found): void {
    $patterns = [
      '/(?<![\w$])t\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
      '/TranslatableMarkup\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
      '/@Translation\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
    ];
    foreach ($patterns as $pattern) {
      if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
      }
      foreach ($matches[2] as $index => $match) {
        $source = stripcslashes($match[0]);
        $offset = $matches[0][$index][1];
        $slice = substr($contents, $offset, 500);
        $context = $this->contextFromCall($slice);
        $this->push($found, $source, $context, $file);
      }
    }
    $plural = '/(?<![\w$])(?:formatPlural|format_plural)\(\s*[^,]+,\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3/s';
    if (preg_match_all($plural, $contents, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[2] as $index => $singular) {
        $source = stripcslashes($singular[0]) . UiLocale::PLURAL . stripcslashes($matches[4][$index][0]);
        $offset = $matches[0][$index][1];
        $this->push($found, $source, $this->contextFromCall(substr($contents, $offset, 600)), $file);
      }
    }
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $found
   */
  private function extractJavaScript(string $contents, string $file, array &$found): void {
    if (!preg_match_all('/Drupal\.t\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $contents, $matches)) {
      return;
    }
    foreach ($matches[2] as $raw) {
      $this->push($found, stripcslashes($raw), '', $file);
    }
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $found
   */
  private function extractTwig(string $contents, string $file, array &$found): void {
    if (preg_match_all('/\{\{\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*\|\s*t\b/s', $contents, $matches)) {
      foreach ($matches[2] as $raw) {
        $this->push($found, stripcslashes($raw), '', $file);
      }
    }
    if (preg_match_all('/\{%\s*trans\s*%\}(.*?)\{%\s*endtrans\s*%\}/s', $contents, $matches)) {
      foreach ($matches[1] as $raw) {
        $text = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        $this->push($found, $text, '', $file);
      }
    }
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $found
   */
  private function extractYaml(string $filename, string $contents, string $file, array &$found): void {
    try {
      $data = Yaml::parse($contents);
    }
    catch (\Throwable) {
      return;
    }
    if (!is_array($data)) {
      return;
    }
    if (str_ends_with($filename, '.info.yml')) {
      $this->push($found, (string) ($data['description'] ?? ''), '', $file);
      return;
    }
    $keys = ['title', 'description'];
    if (str_ends_with($filename, '.routing.yml')) {
      $keys = ['_title'];
    }
    elseif (str_ends_with($filename, '.schema.yml')) {
      $keys = ['label', 'description'];
    }
    elseif (!preg_match('/\.(permissions|links\.[a-z]+|action)\.yml$/', $filename)) {
      return;
    }
    $this->walkYaml($data, $keys, $file, $found);
  }

  /**
   * @param array<mixed> $data
   * @param list<string> $keys
   * @param list<array{source: string, context: string, file: string}> $found
   */
  private function walkYaml(array $data, array $keys, string $file, array &$found): void {
    foreach ($data as $key => $value) {
      if (is_string($value) && in_array((string) $key, $keys, TRUE)) {
        $this->push($found, $value, '', $file);
      }
      elseif (is_array($value)) {
        $this->walkYaml($value, $keys, $file, $found);
      }
    }
  }

  private function contextFromCall(string $slice): string {
    if (!preg_match('/[\'"]context[\'"]\s*=>\s*([\'"])((?:\\\\.|(?!\1).)*)\1/', $slice, $match)) {
      return '';
    }
    $context = stripcslashes($match[2]);
    return strlen($context) <= 255 ? $context : '';
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $found
   */
  private function push(array &$found, string $source, string $context, string $file): void {
    $source = trim($source);
    if (!$this->acceptSource($source)) {
      return;
    }
    $found[] = [
      'source' => $source,
      'context' => $context,
      'file' => $file,
    ];
  }

  public function acceptSource(string $source): bool {
    if ($source === '' || strlen($source) > 2000) {
      return FALSE;
    }
    if (preg_match('/\p{Han}/u', $source)) {
      return FALSE;
    }
    if (!preg_match('/\p{L}/u', $source)) {
      return FALSE;
    }
    if (preg_match('/^(?:\$|\\\\|function\s|class\s|return\s)/', $source)) {
      return FALSE;
    }
    if (preg_match('#^(https?:)?//#', $source) || str_starts_with($source, 'internal:/')) {
      return FALSE;
    }
    // Routes, config keys, machine names and package names are not interface copy.
    if (!str_contains($source, ' ') && preg_match('/^[a-z0-9_.\:\/|-]+$/', $source)) {
      return FALSE;
    }
    if (preg_match('/^(?:TRUE|FALSE|NULL)$/', $source)) {
      return FALSE;
    }
    if (preg_match('/^[A-Z0-9]{1,4}$/', $source)) {
      return FALSE;
    }
    return TRUE;
  }

  private function skipPath(string $root, string $pathname): bool {
    $relative = str_replace('\\', '/', substr($pathname, strlen($root)));
    if (preg_match('#/(tests|node_modules|vendor|css|js/dist)/#', $relative . '/')) {
      return TRUE;
    }
    $dir = dirname($pathname);
    while ($dir !== $root && str_starts_with($dir, $root)) {
      $infos = glob($dir . '/*.info.yml') ?: [];
      if ($infos !== []) {
        return TRUE;
      }
      $parent = dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    return FALSE;
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $found
   * @return list<array{source: string, context: string, file: string}>
   */
  private function unique(array $found): array {
    $out = [];
    foreach ($found as $item) {
      $key = $item['context'] . "\n" . $item['source'];
      $out[$key] = $item;
    }
    return array_values($out);
  }

}
