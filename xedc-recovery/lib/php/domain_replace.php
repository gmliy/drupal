<?php

declare(strict_types=1);

function xedc_replace_value(mixed $value, string $from, string $to): mixed
{
  if (is_string($value)) {
    return str_replace($from, $to, $value);
  }
  if (!is_array($value)) {
    return $value;
  }
  $out = [];
  foreach ($value as $key => $item) {
    $newKey = is_string($key) ? str_replace($from, $to, $key) : $key;
    $out[$newKey] = xedc_replace_value($item, $from, $to);
  }
  return $out;
}

function xedc_transform(string $raw, string $from, string $to): array
{
  if ($raw === '' || !str_contains($raw, $from)) {
    return [$raw, false];
  }
  $unserialized = @unserialize($raw, ['allowed_classes' => false]);
  $isSerialized = $unserialized !== false || $raw === 'b:0;';
  if (!$isSerialized && preg_match('/^[abidsO]:/', $raw) === 1) {
    $probe = @unserialize($raw, ['allowed_classes' => false]);
    $isSerialized = $probe !== false || $raw === 'b:0;';
    $unserialized = $probe;
  }
  if ($isSerialized) {
    $new = xedc_replace_value($unserialized, $from, $to);
    $encoded = serialize($new);
    return [$encoded, $encoded !== $raw];
  }
  $json = json_decode($raw, true);
  if (is_array($json) && json_last_error() === JSON_ERROR_NONE) {
    $new = xedc_replace_value($json, $from, $to);
    $encoded = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
      return [$raw, false];
    }
    return [$encoded, $encoded !== $raw];
  }
  return [str_replace($from, $to, $raw), true];
}

function xedc_pdo_from_cnf(string $cnf, string $database): PDO
{
  $user = '';
  $pass = '';
  $host = 'localhost';
  $port = '3306';
  foreach (file($cnf, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (!str_contains($line, '=')) {
      continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    if ($key === 'user') {
      $user = $value;
    } elseif ($key === 'password') {
      $pass = $value;
    } elseif ($key === 'host') {
      $host = $value;
    } elseif ($key === 'port') {
      $port = $value;
    }
  }
  $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
}

function xedc_replace_tree(string $root, string $from, string $to, bool $apply): int
{
  $count = 0;
  if (!is_dir($root)) {
    return 0;
  }
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($iterator as $file) {
    if (!$file->isFile()) {
      continue;
    }
    $path = $file->getPathname();
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['yml', 'yaml', 'xml', 'txt', 'html', 'json'], true)) {
      continue;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || !str_contains($raw, $from)) {
      continue;
    }
    $count++;
    if ($apply) {
      file_put_contents($path, str_replace($from, $to, $raw));
    }
  }
  return $count;
}

function xedc_replace_database(PDO $pdo, string $from, string $to, bool $apply): int
{
  $skip = '/^(cache.*|watchdog|sessions|semaphore|flood|queue|batch|router)$/';
  $types = "'varchar','char','text','tinytext','mediumtext','longtext','json','blob','tinyblob','mediumblob','longblob'";
  $columns = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ($types)"
  )->fetchAll(PDO::FETCH_ASSOC);
  $count = 0;
  foreach ($columns as $column) {
    $table = (string) $column['TABLE_NAME'];
    $field = (string) $column['COLUMN_NAME'];
    if (preg_match($skip, $table) === 1) {
      continue;
    }
    $quotedTable = '`' . str_replace('`', '``', $table) . '`';
    $quotedField = '`' . str_replace('`', '``', $field) . '`';
    $sql = "SELECT $quotedField AS v FROM $quotedTable WHERE $quotedField LIKE :needle";
    $select = $pdo->prepare($sql);
    $select->execute(['needle' => '%' . $from . '%']);
    $rows = $select->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === []) {
      continue;
    }
    foreach ($rows as $raw) {
      if (!is_string($raw)) {
        continue;
      }
      [, $changed] = xedc_transform($raw, $from, $to);
      if (!$changed) {
        continue;
      }
      $count++;
    }
    if (!$apply) {
      continue;
    }
    $update = $pdo->prepare("UPDATE $quotedTable SET $quotedField = :new WHERE $quotedField = :old");
    foreach ($rows as $raw) {
      if (!is_string($raw)) {
        continue;
      }
      [$new, $changed] = xedc_transform($raw, $from, $to);
      if ($changed) {
        $update->execute(['new' => $new, 'old' => $raw]);
      }
    }
  }
  return $count;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
  $from = '';
  $to = '';
  $cnf = '';
  $database = '';
  $paths = [];
  $apply = false;
  for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--from') {
      $from = (string) ($argv[++$i] ?? '');
    } elseif ($arg === '--to') {
      $to = (string) ($argv[++$i] ?? '');
    } elseif ($arg === '--cnf') {
      $cnf = (string) ($argv[++$i] ?? '');
    } elseif ($arg === '--database') {
      $database = (string) ($argv[++$i] ?? '');
    } elseif ($arg === '--path') {
      $paths[] = (string) ($argv[++$i] ?? '');
    } elseif ($arg === '--apply') {
      $apply = true;
    } elseif ($arg === '--dry-run') {
      $apply = false;
    }
  }
  if ($from === '' || $to === '') {
    fwrite(STDERR, "用法: domain_replace.php --from 旧域名 --to 新域名 [--cnf 文件 --database 库] [--path 目录] [--dry-run|--apply]\n");
    exit(2);
  }
  $files = 0;
  foreach ($paths as $path) {
    $files += xedc_replace_tree($path, $from, $to, $apply);
  }
  $rows = 0;
  if ($cnf !== '' && $database !== '') {
    $pdo = xedc_pdo_from_cnf($cnf, $database);
    $rows = xedc_replace_database($pdo, $from, $to, $apply);
  }
  $mode = $apply ? '已替换' : '将替换';
  echo "{$mode} 文件 {$files} 个，数据库字段值 {$rows} 处\n";
}
