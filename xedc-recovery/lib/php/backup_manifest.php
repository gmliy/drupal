<?php

declare(strict_types=1);

/**
 * 把备份体积统计写入 manifest.json。
 */
function xedc_patch_backup_manifest(string $file, array $backup): void
{
  $raw = file_get_contents($file);
  if ($raw === false) {
    throw new RuntimeException('无法读取 manifest');
  }
  $j = json_decode($raw, true);
  if (!is_array($j)) {
    throw new RuntimeException('manifest 不是合法 JSON');
  }
  $j['backup'] = $backup;
  $out = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($out === false) {
    throw new RuntimeException('无法编码 manifest');
  }
  if (file_put_contents($file, $out . "\n") === false) {
    throw new RuntimeException('无法写回 manifest');
  }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
  $file = '';
  $json = '';
  for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--file') {
      $file = (string) ($argv[++$i] ?? '');
    } elseif ($argv[$i] === '--json') {
      $json = (string) ($argv[++$i] ?? '');
    }
  }
  if ($file === '' || $json === '') {
    fwrite(STDERR, "用法: backup_manifest.php --file manifest.json --json '{...}'\n");
    exit(2);
  }
  $data = json_decode($json, true);
  if (!is_array($data)) {
    fwrite(STDERR, "无效 JSON\n");
    exit(1);
  }
  try {
    xedc_patch_backup_manifest($file, $data);
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
  }
}
