<?php

declare(strict_types=1);

if ($argc < 3) {
  fwrite(STDERR, "用法: manifest_diff.php 旧清单 新清单\n");
  exit(2);
}

$old = json_decode((string) file_get_contents($argv[1]), true);
$new = json_decode((string) file_get_contents($argv[2]), true);
if (!is_array($old) || !is_array($new)) {
  fwrite(STDERR, "清单无法解析\n");
  exit(1);
}

$names = static function (array $doc, string $key): array {
  $items = $doc[$key] ?? [];
  if (!is_array($items)) {
    return [];
  }
  $out = [];
  foreach ($items as $item) {
    if (is_string($item)) {
      $out[] = $item;
    } elseif (is_array($item) && isset($item['name'])) {
      $out[] = (string) $item['name'];
    } elseif (is_array($item) && isset($item['summary'])) {
      $out[] = (string) ($item['schedule'] ?? '') . ' ' . $item['summary'];
    }
  }
  return array_values(array_unique($out));
};

$sections = [
  'services' => '服务',
  'timers' => '定时器',
  'packages' => '软件包',
  'cron' => '定时任务',
];
$found = false;
foreach ($sections as $key => $label) {
  $added = array_values(array_diff($names($new, $key), $names($old, $key)));
  if ($added !== []) {
    $found = true;
    echo "发现新增{$label}: " . implode(', ', $added) . "\n";
  }
}
if (!$found) {
  echo "与上次清单相比没有新增服务、软件或定时任务\n";
}
