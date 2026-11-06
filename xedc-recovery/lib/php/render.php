<?php

declare(strict_types=1);

if ($argc < 3) {
  fwrite(STDERR, "用法: render.php 模板 输出 < vars.json\n");
  exit(2);
}

$raw = stream_get_contents(STDIN);
$vars = json_decode($raw === false ? '' : $raw, true);
if (!is_array($vars)) {
  fwrite(STDERR, "模板变量不是 JSON\n");
  exit(1);
}

$tpl = file_get_contents($argv[1]);
if ($tpl === false) {
  fwrite(STDERR, "无法读取模板\n");
  exit(1);
}

$out = preg_replace_callback('/\{\{([A-Za-z0-9_]+)\}\}/', static function (array $m) use ($vars): string {
  $value = $vars[$m[1]] ?? '';
  return is_scalar($value) ? (string) $value : '';
}, $tpl);

$dir = dirname($argv[2]);
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
  fwrite(STDERR, "无法创建目录\n");
  exit(1);
}
if (file_put_contents($argv[2], $out) === false) {
  fwrite(STDERR, "无法写入输出\n");
  exit(1);
}
