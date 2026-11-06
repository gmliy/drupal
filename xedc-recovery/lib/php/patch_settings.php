<?php

declare(strict_types=1);

function xedc_patch_settings(string $file, array $opt): void
{
  $src = file_get_contents($file);
  if ($src === false) {
    throw new RuntimeException('无法读取 settings.php');
  }
  $db = var_export([
    'database' => $opt['database'],
    'username' => $opt['username'],
    'password' => $opt['password'],
    'prefix' => '',
    'host' => $opt['host'],
    'port' => $opt['port'],
    'isolation_level' => 'READ COMMITTED',
    'driver' => 'mysql',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  ], true);
  $assignment = "\$databases['default']['default'] = {$db};";
  $pattern = '/\$databases\s*\[\s*[\'"]default[\'"]\s*\]\s*\[\s*[\'"]default[\'"]\s*\]\s*=\s*(?:array\s*\([\s\S]*?\)|\[[\s\S]*?\])\s*;/';
  if (preg_match($pattern, $src) === 1) {
    $src = preg_replace($pattern, $assignment, $src, 1) ?? $src;
  } else {
    $src .= "\n{$assignment}\n";
  }

  $hosts = ["'^" . str_replace('\\', '\\\\', preg_quote($opt['domain'], '/')) . "$'"];
  $hosts[] = "'^www\\\\." . str_replace('\\', '\\\\', preg_quote($opt['domain'], '/')) . "$'";
  $hostBlock = "\$settings['trusted_host_patterns'] = [\n  " . implode(",\n  ", $hosts) . ",\n];";
  $hostPattern = '/\$settings\s*\[\s*[\'"]trusted_host_patterns[\'"]\s*\]\s*=\s*(?:array\s*)?\(.*?\)\s*;|\$settings\s*\[\s*[\'"]trusted_host_patterns[\'"]\s*\]\s*=\s*\[.*?\];/s';
  if (preg_match($hostPattern, $src) === 1) {
    $src = preg_replace($hostPattern, $hostBlock, $src, 1) ?? $src;
  } else {
    $src .= "\n{$hostBlock}\n";
  }

  $scheme = ($opt['https'] ?? 'on') === 'off' ? 'http' : 'https';
  $base = $scheme . '://' . $opt['domain'];
  $baseLine = "\$base_url = " . var_export($base, true) . ";";
  if (preg_match('/^\$base_url\s*=.*$/m', $src) === 1) {
    $src = preg_replace('/^\$base_url\s*=.*$/m', $baseLine, $src, 1) ?? $src;
  } else {
    $src .= "\n{$baseLine}\n";
  }

  $cookie = '.' . $opt['domain'];
  $cookieLine = "\$settings['cookie_domain'] = " . var_export($cookie, true) . ";";
  if (preg_match('/\$settings\s*\[\s*[\'"]cookie_domain[\'"]\s*\]\s*=.*$/m', $src) === 1) {
    $src = preg_replace('/\$settings\s*\[\s*[\'"]cookie_domain[\'"]\s*\]\s*=.*$/m', $cookieLine, $src, 1) ?? $src;
  } else {
    $src .= "\n{$cookieLine}\n";
  }

  if (!str_contains($src, "['reverse_proxy']")) {
    $src .= <<<'PHP'

$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['127.0.0.1', '::1'];

PHP;
  }
  $hasSalt = false;
  foreach (preg_split("/\r\n|\n|\r/", $src) ?: [] as $line) {
    if (preg_match('/^\s*(\*|\/\*|#|\/\/)/', $line) === 1) {
      continue;
    }
    if (str_contains($line, "['hash_salt']") || str_contains($line, '["hash_salt"]')) {
      $hasSalt = true;
      break;
    }
  }
  if (!$hasSalt) {
    $salt = ($opt['hash_salt'] ?? '') !== '' ? (string) $opt['hash_salt'] : bin2hex(random_bytes(32));
    $src .= "\n\$settings['hash_salt'] = " . var_export($salt, true) . ";\n";
  }
  if (file_put_contents($file, $src) === false) {
    throw new RuntimeException('无法写回 settings.php');
  }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
  $opt = [
    'file' => '',
    'database' => '',
    'username' => '',
    'password' => '',
    'host' => 'localhost',
    'port' => '3306',
    'domain' => '',
    'https' => 'on',
    'hash_salt' => '',
  ];
  for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (!str_starts_with($arg, '--')) {
      continue;
    }
    $key = substr($arg, 2);
    $value = (string) ($argv[++$i] ?? '');
    if ($key === 'file') {
      $opt['file'] = $value;
    } else {
      $opt[$key] = $value;
    }
  }
  if ($opt['file'] === '' || $opt['domain'] === '' || $opt['database'] === '' || $opt['username'] === '') {
    fwrite(STDERR, "参数不足\n");
    exit(2);
  }
  try {
    xedc_patch_settings($opt['file'], $opt);
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
  }
}
