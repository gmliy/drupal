<?php

declare(strict_types=1);

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
  exit(0);
}
foreach (file($file) ?: [] as $line) {
  if (preg_match('/^\s*(\*|\/\*|#|\/\/)/', $line) === 1) {
    continue;
  }
  if (preg_match('/hash_salt[^\n]*[\'\"]([^\'\"]+)[\'\"]/', $line, $m) === 1) {
    echo $m[1];
    exit(0);
  }
}
