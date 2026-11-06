<?php

declare(strict_types=1);

if ($argc < 3) {
  fwrite(STDERR, "用法: manifest_get.php 文件 点路径\n");
  exit(2);
}

$data = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($data)) {
  exit(1);
}

$cursor = $data;
foreach (explode('.', $argv[2]) as $part) {
  if ($part === '') {
    continue;
  }
  if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
    exit(0);
  }
  $cursor = $cursor[$part];
}

if (is_array($cursor)) {
  echo json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif (is_bool($cursor)) {
  echo $cursor ? '1' : '0';
} elseif ($cursor === null) {
  echo '';
} else {
  echo (string) $cursor;
}
echo "\n";
