#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"
fail=0
while IFS= read -r file; do
  if ! bash -n "$file"; then
    echo "bash -n 失败: $file"
    fail=1
  fi
done < <(find "$ROOT" -name '*.sh' -type f)
if command -v shellcheck >/dev/null 2>&1; then
  while IFS= read -r file; do
    if ! shellcheck -x -S error "$file"; then
      echo "shellcheck 失败: $file"
      fail=1
    fi
  done < <(find "$ROOT" -name '*.sh' -type f)
else
  echo "未安装 shellcheck"
  fail=1
fi
php -r '
require "'"$ROOT"'/lib/php/domain_replace.php";
$raw = "a:2:{s:4:\"link\";s:26:\"https://test1.local/models\";s:5:\"plain\";s:15:\"see test1.local\";}";
[$out, $changed] = xedc_transform($raw, "test1.local", "test2.local");
if (!$changed) { fwrite(STDERR, "serialized not changed\n"); exit(1); }
$val = unserialize($out, ["allowed_classes" => false]);
if (($val["link"] ?? "") !== "https://test2.local/models") { fwrite(STDERR, "bad unserialize\n"); exit(1); }
[$plain, $ok] = xedc_transform("see test1.local", "test1.local", "test2.local");
if ($plain !== "see test2.local" || !$ok) { fwrite(STDERR, "plain replace failed\n"); exit(1); }
echo "domain unit ok\n";
'
# shellcheck source=../lib/r2.sh
source "$ROOT/lib/r2.sh"
got=$(xedc_choose_stamp latest drupal-backup-20200101T000000 drupal-backup-20260101T000000)
[[ "$got" == "drupal-backup-20260101T000000" ]]
got=$(xedc_choose_stamp latest-1 drupal-backup-20200101T000000 drupal-backup-20260101T000000)
[[ "$got" == "drupal-backup-20200101T000000" ]]
got=$(xedc_choose_stamp 20260101T000000 drupal-backup-20200101T000000 drupal-backup-20260101T000000)
[[ "$got" == "drupal-backup-20260101T000000" ]]
tmp=$(mktemp)
php "$ROOT/lib/php/render.php" "$ROOT/templates/gzip.conf" "$tmp" <<'JSON'
{}
JSON
grep -q 'gzip_comp_level' "$tmp"
if grep -q 'gzip on' "$tmp"; then
  echo "gzip.conf 不能再写 gzip on，会和系统 nginx.conf 冲突" >&2
  exit 1
fi
rm -f "$tmp"
settings=$(mktemp)
cat >"$settings" <<'PHP'
<?php
$databases['default']['default'] = [
  'database' => 'old',
  'username' => 'old',
  'password' => 'old-pass',
  'host' => 'localhost',
  'port' => '3306',
];
$settings['hash_salt'] = 'keep-this-salt';
PHP
php "$ROOT/lib/php/patch_settings.php" --file "$settings" --database xedc --username xedc --password abc --host localhost --port 3306 --domain test2.local --https on
php "$ROOT/lib/php/patch_settings.php" --file "$settings" --database xedc --username xedc --password abc --host localhost --port 3306 --domain test2.local --https on
count=$(php -r 'echo substr_count(file_get_contents($argv[1]), "databases");' "$settings")
[[ "$count" == "1" ]]
grep -q 'keep-this-salt' "$settings"
grep -q 'test2.local' "$settings"
rm -f "$settings"
echo "unit ok"
exit "$fail"
