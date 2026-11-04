#!/usr/bin/env bash
# 生成不含生产数据的小快照。不连接生产库，不导出生产 Docker 卷。
set -Eeuo pipefail

ROOT=/var/lib/xedc-recovery/fixture-site
BACKUP_ROOT=/var/lib/xedc-recovery/fixture-backups
AGE_DIR=/var/lib/xedc-recovery/fixture-age
STATE_ROOT=/var/lib/xedc-recovery/fixture-state
LOG_DIR=/var/log/xedc-recovery
SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)

rm -rf "$ROOT"
mkdir -p "$ROOT/web/sites/default/files" "$ROOT/web/themes/custom/xedc/js" \
  "$ROOT/web/modules/custom/fixture" "$ROOT/config/sync" "$ROOT/private" \
  "$BACKUP_ROOT" "$AGE_DIR" "$STATE_ROOT" "$LOG_DIR"
chmod 700 "$AGE_DIR" "$STATE_ROOT" "$LOG_DIR"

cat >"$ROOT/composer.json" <<'JSON'
{
  "name": "xedc/fixture",
  "require": {}
}
JSON
(
  cd "$ROOT"
  composer update --no-install --no-interaction --no-plugins --no-ansi
)

printf '%s\n' 'fixture-file' >"$ROOT/web/sites/default/files/hello.txt"
printf '%s\n' 'player' >"$ROOT/web/themes/custom/xedc/js/plyr.min.js"
printf '%s\n' 'note' >"$ROOT/private/note.txt"
printf '%s\n' '<?php' '// https://old.example.test/kept-for-scan' >"$ROOT/web/modules/custom/fixture/fixture.module"
cat >"$ROOT/web/index.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/admin') {
    header('Location: /user/login', true, 302);
    exit;
}
http_response_code(200);
echo "ok\n";
PHP
php "$SCRIPT_DIR/write-fixture.php" "$ROOT"

unset XEDC_DRY_RUN XEDC_SITE_ROOT XEDC_BACKUP_ROOT XEDC_AGE_DIR XEDC_AGE_PUB XEDC_AGE_KEY \
  XEDC_STATE_ROOT XEDC_LOG_DIR XEDC_SKIP_DOCKER XEDC_SQL_DUMP_FILE
export XEDC_SITE_ROOT=$ROOT
export XEDC_BACKUP_ROOT=$BACKUP_ROOT
export XEDC_AGE_DIR=$AGE_DIR
export XEDC_AGE_PUB=$BACKUP_ROOT/age.pub
export XEDC_STATE_ROOT=$STATE_ROOT
export XEDC_LOG_DIR=$LOG_DIR
export XEDC_SQL_DUMP_FILE=$ROOT/fixture.sql
export XEDC_SKIP_DOCKER=1
"$SCRIPT_DIR/../../backup.sh" --output "$BACKUP_ROOT"
unset XEDC_SQL_DUMP_FILE XEDC_SKIP_DOCKER XEDC_SITE_ROOT
rm -f "$ROOT/fixture.sql"
latest=$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | awk 'NR==1 {print $2}')
printf '%s\n' "$latest"
