<?php

declare(strict_types=1);

/**
 * 从 settings.php 提取数据库连接并写入 MySQL 选项文件。不打印密码。
 */
function xedc_settings_source(string $path): string
{
  $src = file_get_contents($path);
  if ($src === false) {
    throw new RuntimeException('无法读取 settings.php');
  }
  $lines = preg_split("/\r\n|\n|\r/", $src) ?: [];
  $kept = [];
  foreach ($lines as $line) {
    if (preg_match('/^\s*(\*|\/\*|#)/', $line)) {
      continue;
    }
    $kept[] = $line;
  }
  return implode("\n", $kept);
}

function xedc_match_last(string $src, string $pattern): string
{
  if (!preg_match_all($pattern, $src, $matches)) {
    return '';
  }
  return (string) end($matches[1]);
}

function xedc_db_prefix(string $settings): string
{
  $src = xedc_settings_source($settings);
  $pos = strpos($src, "['default']['default']");
  if ($pos === false) {
    $pos = strpos($src, '["default"]["default"]');
  }
  $slice = $pos === false ? $src : substr($src, $pos, 6000);
  if (preg_match('/[\'"]prefix[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $slice, $match) === 1) {
    $prefix = $match[1];
  } elseif (
    preg_match('/[\'"]prefix[\'"]\s*=>\s*(?:array\s*\(|\[)(.{0,800}?)(?:\)|\])/s', $slice, $match) === 1
    && preg_match('/[\'"]default[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $match[1], $nested) === 1
  ) {
    $prefix = $nested[1];
  } else {
    return '';
  }
  if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
    throw new RuntimeException('settings.php 的表前缀含有非法字符');
  }
  return $prefix;
}

function xedc_db_credentials(string $settings): array
{
  $src = xedc_settings_source($settings);
  $db = xedc_match_last($src, "/['\"]database['\"]\\s*=>\\s*['\"]([^'\"]*)['\"]/");
  $user = xedc_match_last($src, "/['\"]username['\"]\\s*=>\\s*['\"]([^'\"]*)['\"]/");
  $pass = xedc_match_last($src, "/['\"]password['\"]\\s*=>\\s*['\"]([^'\"]*)['\"]/");
  $host = xedc_match_last($src, "/['\"]host['\"]\\s*=>\\s*['\"]([^'\"]*)['\"]/");
  $port = xedc_match_last($src, "/['\"]port['\"]\\s*=>\\s*['\"]([^'\"]*)['\"]/");
  if ($db === '' || $user === '') {
    throw new RuntimeException('settings.php 里没有可用的数据库配置');
  }
  return [
    'database' => $db,
    'username' => $user,
    'password' => $pass,
    'host' => $host !== '' ? $host : 'localhost',
    'port' => $port !== '' ? $port : '3306',
  ];
}

function xedc_write_client_cnf(string $path, array $cred, bool $asRoot = false): void
{
  $lines = ["[client]"];
  if ($asRoot) {
    $lines[] = 'user=root';
  } else {
    $lines[] = 'user=' . $cred['username'];
    $lines[] = 'password=' . $cred['password'];
    $lines[] = 'host=' . $cred['host'];
    $lines[] = 'port=' . $cred['port'];
  }
  $lines[] = '';
  if (file_put_contents($path, implode("\n", $lines)) === false) {
    throw new RuntimeException('无法写入数据库选项文件');
  }
  chmod($path, 0600);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
  $write = '';
  $settings = '';
  $root = false;
  $prefixOnly = false;
  for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--write-cnf') {
      $write = (string) ($argv[++$i] ?? '');
    } elseif ($argv[$i] === '--prefix') {
      $prefixOnly = true;
      $settings = (string) ($argv[++$i] ?? '');
    } elseif ($argv[$i] === '--root') {
      $root = true;
    } else {
      $settings = (string) $argv[$i];
    }
  }
  if ($prefixOnly) {
    if ($settings === '') {
      fwrite(STDERR, "用法: settings_db.php --prefix settings.php\n");
      exit(2);
    }
    try {
      echo xedc_db_prefix($settings), "\n";
    } catch (Throwable $e) {
      fwrite(STDERR, $e->getMessage() . "\n");
      exit(1);
    }
    exit(0);
  }
  if ($settings === '' || $write === '') {
    fwrite(STDERR, "用法: settings_db.php --write-cnf 文件 [--root] settings.php\n");
    exit(2);
  }
  try {
    $cred = xedc_db_credentials($settings);
    xedc_write_client_cnf($write, $cred, $root);
    $pub = $cred;
    unset($pub['password']);
    echo json_encode($pub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
  }
}
