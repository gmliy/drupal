<?php

declare(strict_types=1);

function xedc_strip_rules(): array
{
  $file = getenv('XEDC_EXCLUDE_FILE');
  if (!is_string($file) || $file === '') {
    $file = dirname(__DIR__, 2) . '/exclude.list';
  }
  if (!is_file($file)) {
    return [];
  }
  $rules = [];
  foreach (file($file) ?: [] as $line) {
    $line = preg_replace('/\s+#.*$/', '', $line) ?? $line;
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }
    $rules[] = $line;
  }
  return $rules;
}

function xedc_strip_match(string $value): bool
{
  if ($value === '') {
    return false;
  }
  foreach (xedc_strip_rules() as $rule) {
    if ($rule !== '' && str_contains($value, $rule)) {
      return true;
    }
  }
  return false;
}

function xedc_keep_packages(): array
{
  $raw = getenv('XEDC_KEEP_PACKAGES');
  if (!is_string($raw) || $raw === '') {
    return [];
  }
  return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function xedc_strip_package(string $name): bool
{
  if (in_array($name, xedc_keep_packages(), true)) {
    return false;
  }
  return xedc_strip_match($name);
}

function xedc_strip_config_name(string $name): bool
{
  foreach (xedc_strip_rules() as $rule) {
    if ($name === $rule || str_contains($name, $rule)) {
      return true;
    }
  }
  return false;
}

function xedc_strip_line(string $line): bool
{
  $trim = trim($line);
  if ($trim === '' || str_starts_with($trim, '#')) {
    return false;
  }
  foreach (xedc_strip_rules() as $rule) {
    $quoted = preg_quote($rule, '/');
    if (preg_match('/(^|[\s\'"\[:._\/-])' . $quoted . '($|[\s\'"\]:._\/-])/', $trim) === 1) {
      return true;
    }
  }
  return false;
}

function xedc_strip_yaml(string $text): array
{
  $lines = preg_split("/\n/", $text) ?: [];
  $out = [];
  $skip = null;
  $removed = 0;
  foreach ($lines as $line) {
    if ($skip !== null) {
      if (trim($line) === '') {
        continue;
      }
      $indent = strlen($line) - strlen(ltrim($line));
      if ($indent > $skip) {
        $removed++;
        continue;
      }
      $skip = null;
    }
    if (xedc_strip_line($line) && preg_match('/^\s*.+:\s*(.*)$/', $line) === 1 && !str_starts_with(trim($line), '-')) {
      $removed++;
      $skip = strlen($line) - strlen(ltrim($line));
      continue;
    }
    if (xedc_strip_line($line)) {
      $removed++;
      continue;
    }
    $out[] = $line;
  }
  $joined = implode("\n", $out);
  if ($text !== '' && str_ends_with($text, "\n")) {
    $joined .= "\n";
  }
  return [$joined, $removed];
}

function xedc_rrmdir(string $dir): void
{
  if (!is_dir($dir)) {
    return;
  }
  $items = scandir($dir) ?: [];
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') {
      continue;
    }
    $path = $dir . '/' . $item;
    if (is_dir($path)) {
      xedc_rrmdir($path);
    } else {
      unlink($path);
    }
  }
  rmdir($dir);
}

function xedc_strip_composer(string $site): array
{
  $removed = [];
  $jsonPath = $site . '/composer.json';
  if (is_file($jsonPath)) {
    $json = json_decode((string) file_get_contents($jsonPath), true);
    if (is_array($json)) {
      foreach (['require', 'require-dev'] as $section) {
        if (!isset($json[$section]) || !is_array($json[$section])) {
          continue;
        }
        foreach (array_keys($json[$section]) as $package) {
          if (xedc_strip_package((string) $package)) {
            unset($json[$section][$package]);
            $removed[] = (string) $package;
          }
        }
      }
      if (isset($json['repositories']) && is_array($json['repositories'])) {
        $json['repositories'] = array_values(array_filter(
          $json['repositories'],
          static function ($repo): bool {
            $blob = is_array($repo) ? (string) json_encode($repo) : (string) $repo;
            return !xedc_strip_match($blob);
          }
        ));
      }
      file_put_contents($jsonPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
  }
  $lockPath = $site . '/composer.lock';
  if (is_file($lockPath)) {
    $lock = json_decode((string) file_get_contents($lockPath), true);
    if (is_array($lock)) {
      foreach (['packages', 'packages-dev'] as $section) {
        if (!isset($lock[$section]) || !is_array($lock[$section])) {
          continue;
        }
        $lock[$section] = array_values(array_filter(
          $lock[$section],
          static function ($package) use (&$removed): bool {
            $name = is_array($package) ? (string) ($package['name'] ?? '') : '';
            if ($name !== '' && xedc_strip_package($name)) {
              $removed[] = $name;
              return false;
            }
            return true;
          }
        ));
      }
      file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
  }
  foreach (glob($site . '/vendor/*/*') ?: [] as $path) {
    if (is_dir($path) && xedc_strip_package(basename(dirname($path)) . '/' . basename($path))) {
      xedc_rrmdir($path);
    }
  }
  foreach (glob($site . '/web/modules/contrib/*') ?: [] as $path) {
    if (is_dir($path) && xedc_strip_match(basename($path))) {
      xedc_rrmdir($path);
    }
  }
  return array_values(array_unique($removed));
}

function xedc_strip_config(string $site): array
{
  $dir = $site . '/config/sync';
  $removedFiles = [];
  $removedLines = 0;
  if (!is_dir($dir)) {
    return ['files' => [], 'lines' => 0];
  }
  foreach (glob($dir . '/*.yml') ?: [] as $file) {
    $name = basename($file, '.yml');
    $text = (string) file_get_contents($file);
    $drop = xedc_strip_config_name($name);
    if (!$drop && str_starts_with($name, 'block.block.') && xedc_strip_match($text)) {
      $drop = true;
    }
    if ($drop) {
      unlink($file);
      $removedFiles[] = basename($file);
      continue;
    }
    [$next, $count] = xedc_strip_yaml($text);
    if ($count > 0 && $next !== $text) {
      file_put_contents($file, $next);
      $removedLines += $count;
    }
  }
  return ['files' => $removedFiles, 'lines' => $removedLines];
}

function xedc_enabled_modules(string $site): array
{
  $file = $site . '/config/sync/core.extension.yml';
  if (!is_file($file)) {
    return [];
  }
  $mods = [];
  $in = false;
  foreach (file($file) ?: [] as $line) {
    if (preg_match('/^[A-Za-z0-9_]+:\s*$/', $line) === 1) {
      $in = str_starts_with($line, 'module:');
      continue;
    }
    if (!$in) {
      continue;
    }
    if (preg_match('/^\s{2,}([A-Za-z0-9_]+)\s*:/', $line, $m) === 1 && xedc_strip_match($m[1])) {
      $mods[] = $m[1];
    }
  }
  return $mods;
}

function xedc_composer_packages(string $site): array
{
  $file = $site . '/composer.json';
  if (!is_file($file)) {
    return [];
  }
  $json = json_decode((string) file_get_contents($file), true);
  if (!is_array($json)) {
    return [];
  }
  $found = [];
  foreach (['require', 'require-dev'] as $section) {
    foreach (array_keys($json[$section] ?? []) as $package) {
      if (xedc_strip_package((string) $package)) {
        $found[] = (string) $package;
      }
    }
  }
  return $found;
}

function xedc_dependents(string $site, string $module): array
{
  $root = $site . '/web/modules';
  if (!is_dir($root)) {
    return [];
  }
  $hits = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $item) {
    if (!$item->isFile() || !str_ends_with($item->getFilename(), '.info.yml')) {
      continue;
    }
    $path = $item->getPathname();
    if (xedc_strip_match($item->getFilename()) && !str_contains($path, '/custom/') && !str_contains($path, '/modules/custom/')) {
      continue;
    }
    $text = (string) file_get_contents($path);
    if (!preg_match('/^dependencies:\s*$/m', $text) && !preg_match('/^dependencies:\s*\[/m', $text)) {
      continue;
    }
    $quoted = preg_quote($module, '/');
    if (preg_match('/(^|[\s\'"\[:._\/-])' . $quoted . '($|[\s\'"\]:._\/-])/m', $text) === 1) {
      $hits[] = $path;
    }
  }
  return $hits;
}

function xedc_code_refs(string $site): array
{
  $refs = [];
  foreach ([$site . '/web/themes', $site . '/web/modules/custom'] as $root) {
    if (!is_dir($root)) {
      continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $item) {
      if (!$item->isFile()) {
        continue;
      }
      $path = $item->getPathname();
      if (str_contains($path, '/node_modules/') || str_contains($path, '/vendor/')) {
        continue;
      }
      $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if (!in_array($ext, ['php', 'module', 'inc', 'twig', 'js', 'yml', 'html', 'htm'], true)) {
        continue;
      }
      $lines = file($path) ?: [];
      foreach ($lines as $i => $line) {
        if (preg_match('/matomo\.js|piwik\.js|piwik\.php|matomo\.php/i', $line) === 1 || xedc_strip_match($line)) {
          if (preg_match('/matomo\.js|piwik\.js|https?:\/\/[^\s\'"]*(matomo|piwik)/i', $line) === 1 || preg_match('/\b(matomo|piwik)\b/i', $line) === 1) {
            $refs[] = ['file' => $path, 'line' => $i + 1, 'text' => trim($line)];
          }
        }
      }
    }
  }
  return $refs;
}

function xedc_read_cnf(string $path): array
{
  $out = ['user' => '', 'password' => '', 'host' => 'localhost', 'port' => '3306', 'database' => ''];
  foreach (file($path) ?: [] as $line) {
    if (preg_match('/^\s*user\s*=\s*(.*)$/', $line, $m) === 1) {
      $out['user'] = trim($m[1]);
    }
    if (preg_match('/^\s*password\s*=\s*(.*)$/', $line, $m) === 1) {
      $out['password'] = trim($m[1]);
    }
    if (preg_match('/^\s*host\s*=\s*(.*)$/', $line, $m) === 1) {
      $out['host'] = trim($m[1]);
    }
    if (preg_match('/^\s*port\s*=\s*(.*)$/', $line, $m) === 1) {
      $out['port'] = trim($m[1]);
    }
    if (preg_match('/^\s*database\s*=\s*(.*)$/', $line, $m) === 1) {
      $out['database'] = trim($m[1]);
    }
  }
  return $out;
}

function xedc_strip_tracking_text(string $text): string
{
  $next = preg_replace('#<script\b[^>]*(matomo|piwik)[^>]*>.*?</script>#is', '', $text) ?? $text;
  $next = preg_replace('#https?://[^\s\'"<>]*(matomo|piwik)[^\s\'"<>]*#i', '', $next) ?? $next;
  return $next;
}

function xedc_strip_service_name(string $name): bool
{
  foreach (xedc_strip_rules() as $rule) {
    if ($rule === '' || str_contains($rule, '*') || str_contains($rule, '?')) {
      continue;
    }
    if (in_array($rule, ['docs', 'docs-archive', '_server/db.sql'], true)) {
      continue;
    }
    if (str_contains($name, $rule)) {
      return true;
    }
  }
  return false;
}

function xedc_strip_extension_data(string $blob): ?string
{
  $data = @unserialize($blob, ['allowed_classes' => false]);
  if (!is_array($data)) {
    return null;
  }
  $removed = 0;
  foreach (['module', 'theme'] as $section) {
    if (!isset($data[$section]) || !is_array($data[$section])) {
      continue;
    }
    foreach (array_keys($data[$section]) as $name) {
      if (xedc_strip_service_name((string) $name)) {
        unset($data[$section][$name]);
        $removed++;
      }
    }
  }
  if ($removed === 0) {
    return null;
  }
  return serialize($data);
}

function xedc_strip_db(string $cnf, string $database): int
{
  if (!is_file($cnf) || !extension_loaded('pdo_mysql')) {
    return 0;
  }
  $auth = xedc_read_cnf($cnf);
  if ($auth['user'] === '') {
    return 0;
  }
  $dsn = 'mysql:host=' . $auth['host'] . ';port=' . ($auth['port'] !== '' ? $auth['port'] : '3306') . ';dbname=' . $database . ';charset=utf8mb4';
  try {
    $pdo = new PDO($dsn, $auth['user'], $auth['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  } catch (Throwable) {
    return 0;
  }
  $changed = 0;
  $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  foreach ($tables as $table) {
    $table = (string) $table;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
      continue;
    }
    if ($table === 'config') {
      $changed += $pdo->exec("DELETE FROM config WHERE name LIKE '%matomo%' OR name LIKE '%piwik%'") ?: 0;
      $extRows = $pdo->query("SELECT collection, data FROM config WHERE name = 'core.extension'");
      foreach ($extRows ?: [] as $extRow) {
        $next = xedc_strip_extension_data((string) ($extRow['data'] ?? ''));
        if ($next === null) {
          continue;
        }
        $upd = $pdo->prepare('UPDATE config SET data = ? WHERE collection = ? AND name = ?');
        $upd->execute([$next, (string) ($extRow['collection'] ?? ''), 'core.extension']);
        $changed++;
        fwrite(STDERR, "已从数据库 core.extension 去掉排除模块\n");
      }
      continue;
    }
    if ($table === 'key_value') {
      $changed += $pdo->exec("DELETE FROM key_value WHERE collection LIKE '%matomo%' OR collection LIKE '%piwik%' OR name LIKE '%matomo%' OR name LIKE '%piwik%'") ?: 0;
      continue;
    }
    $cols = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    $textCols = [];
    $pk = '';
    foreach ($cols as $col) {
      $field = (string) ($col['Field'] ?? '');
      $type = strtolower((string) ($col['Type'] ?? ''));
      if (($col['Key'] ?? '') === 'PRI' && $pk === '') {
        $pk = $field;
      }
      if (str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'blob')) {
        $textCols[] = $field;
      }
    }
    if ($pk === '' || $textCols === []) {
      continue;
    }
    foreach ($textCols as $col) {
      $sql = 'SELECT `' . $pk . '` AS pk, `' . $col . '` AS val FROM `' . $table . '` WHERE `' . $col . '` LIKE ' . $pdo->quote('%matomo%') . ' OR `' . $col . '` LIKE ' . $pdo->quote('%piwik%');
      foreach ($pdo->query($sql) ?: [] as $row) {
        $val = (string) ($row['val'] ?? '');
        $next = xedc_strip_tracking_text($val);
        if ($next === $val) {
          continue;
        }
        $upd = $pdo->prepare('UPDATE `' . $table . '` SET `' . $col . '` = ? WHERE `' . $pk . '` = ?');
        $upd->execute([$next, $row['pk']]);
        $changed++;
      }
    }
  }
  return $changed;
}

function xedc_write_report(string $path, array $report): void
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

$site = '';
$mode = 'all';
$cnf = '';
$database = '';
$reportPath = '';
foreach ($argv as $i => $arg) {
  if ($arg === '--site' && isset($argv[$i + 1])) {
    $site = $argv[$i + 1];
  }
  if ($arg === '--mode' && isset($argv[$i + 1])) {
    $mode = $argv[$i + 1];
  }
  if ($arg === '--cnf' && isset($argv[$i + 1])) {
    $cnf = $argv[$i + 1];
  }
  if ($arg === '--database' && isset($argv[$i + 1])) {
    $database = $argv[$i + 1];
  }
  if ($arg === '--report' && isset($argv[$i + 1])) {
    $reportPath = $argv[$i + 1];
  }
}
if ($mode === 'stream') {
  while (($line = fgets(STDIN)) !== false) {
    echo xedc_strip_tracking_text($line);
  }
  exit(0);
}
if ($mode === 'selftest') {
  $blob = serialize([
    'module' => ['system' => 0, 'matomo' => 0, 'matomo_tagmanager' => 0],
    'theme' => ['stark' => 0],
  ]);
  $next = xedc_strip_extension_data($blob);
  $data = is_string($next) ? unserialize($next, ['allowed_classes' => false]) : null;
  if (!is_array($data) || isset($data['module']['matomo']) || isset($data['module']['matomo_tagmanager']) || !isset($data['module']['system']) || !isset($data['theme']['stark'])) {
    fwrite(STDERR, "core.extension 清理失败\n");
    exit(1);
  }
  if (xedc_strip_extension_data(serialize(['module' => ['system' => 0]])) !== null) {
    fwrite(STDERR, "没有排除模块时不应改写 core.extension\n");
    exit(1);
  }
  echo "extension strip ok\n";
  exit(0);
}
if ($site === '' || !is_dir($site)) {
  fwrite(STDERR, "需要 --site 目录\n");
  exit(2);
}

if ($mode === 'list-modules') {
  foreach (xedc_enabled_modules($site) as $mod) {
    echo $mod, "\n";
  }
  exit(0);
}
if ($mode === 'list-packages') {
  foreach (xedc_composer_packages($site) as $pkg) {
    echo $pkg, "\n";
  }
  exit(0);
}
if ($mode === 'dependents') {
  $mod = '';
  foreach ($argv as $i => $arg) {
    if ($arg === '--module' && isset($argv[$i + 1])) {
      $mod = $argv[$i + 1];
    }
  }
  foreach (xedc_dependents($site, $mod) as $hit) {
    echo $hit, "\n";
  }
  exit(0);
}
if ($mode === 'warn-enabled') {
  foreach (xedc_enabled_modules($site) as $mod) {
    echo $mod, "\n";
  }
  exit(0);
}
if ($mode === 'assert-disabled') {
  $left = xedc_enabled_modules($site);
  if ($left !== []) {
    fwrite(STDERR, '仍启用: ' . implode(',', $left) . "\n");
    exit(1);
  }
  exit(0);
}

$report = [
  'modules' => xedc_enabled_modules($site),
  'packages' => [],
  'files' => [],
  'lines' => 0,
  'db_rows' => 0,
  'code_refs' => [],
];
if ($mode === 'composer' || $mode === 'all') {
  $report['packages'] = xedc_strip_composer($site);
}
if ($mode === 'config' || $mode === 'all') {
  $cfg = xedc_strip_config($site);
  $report['files'] = $cfg['files'];
  $report['lines'] = $cfg['lines'];
  $report['code_refs'] = xedc_code_refs($site);
}
if (($mode === 'database' || $mode === 'all') && $cnf !== '' && $database !== '') {
  $report['db_rows'] = xedc_strip_db($cnf, $database);
}
if ($reportPath !== '') {
  xedc_write_report($reportPath, $report);
}
fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
