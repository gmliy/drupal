#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=../../lib/ssl.sh
source "$ROOT/lib/ssl.sh"
# shellcheck source=../../lib/common.sh
source "$ROOT/lib/common.sh"
# shellcheck source=../../lib/environment.sh
source "$ROOT/lib/environment.sh"
# shellcheck source=../../lib/rollback.sh
source "$ROOT/lib/rollback.sh"

fail=0
check() {
  local name=$1 got=$2 want=$3
  if [[ $got == "$want" ]]; then
    printf 'PASS %s\n' "$name"
  else
    printf 'FAIL %s got=%s want=%s\n' "$name" "$got" "$want"
    fail=1
  fi
}

check http "$(xedc_ssl_decide yes no no)" http
check dns "$(xedc_ssl_decide no yes no)" dns
check manual "$(xedc_ssl_decide no no yes)" manual
check wait "$(xedc_ssl_decide no no no)" selfsigned-wait

export XEDC_LIB=$ROOT/lib
list=$(xedc_ssl_provider_list)
printf '%s\n' "$list" | grep -q 'CF_Token' || { echo FAIL cloudflare; fail=1; }
printf '%s\n' "$list" | grep -q 'Ali_Key' || { echo FAIL aliyun; fail=1; }
printf '%s\n' "$list" | grep -q 'hetzner -> hetznercloud' || { echo FAIL hetzner; fail=1; }
echo PASS providers

tmp=$(mktemp -d)
printf '%s\n' 'track_errors = On' 'memory_limit = 128M' >"$tmp/src.ini"
xedc_filter_php_ini "$tmp/src.ini" "$tmp/out.ini"
if grep -q track_errors "$tmp/out.ini"; then echo FAIL ini; fail=1; else echo PASS ini; fi
grep -q memory_limit "$tmp/out.ini"

manifest=$tmp/manifest.json
live=$tmp/live.json
cat >"$manifest" <<'JSON'
{"schema":2,"source":{"php":"8.5.12"},"files":{"public_files":2},"database":{"tables":3},"media":{"ffmpeg_version":"6"},"object_storage":{"prefixes":[{"count":1}]}}
JSON
cat >"$live" <<'JSON'
{"php":"8.5.12","public_files":2,"tables":3,"ffmpeg":"6","object_count":1,"drupal_version":"8.5.12"}
JSON
# shellcheck source=../../lib/compare.sh
source "$ROOT/lib/compare.sh"
# shellcheck source=../../lib/files.sh
source "$ROOT/lib/files.sh"
# shellcheck source=../../lib/database.sh
source "$ROOT/lib/database.sh"
# shellcheck source=../../lib/backup.sh
source "$ROOT/lib/backup.sh"
if xedc_compare_write "$manifest" "$live" "$tmp/parity.md"; then echo PASS compare; else echo FAIL compare; fail=1; fi
grep -q '异常差异: 0' "$tmp/parity.md"

cat >"$live" <<'JSON'
{"php":"8.4.1","public_files":1,"tables":3,"ffmpeg":"6","object_count":1,"drupal_version":"8.5.12"}
JSON
if xedc_compare_write "$manifest" "$live" "$tmp/parity.md"; then echo FAIL compare_should_fail; fail=1; else echo PASS compare_detects; fi
grep -q '异常差异' "$tmp/parity.md"

cat >"$manifest" <<'JSON'
{"schema":2,"source":{"php":"8.5.12"},"files":{"public_files":2},"database":{"tables":3},"media":{"ffmpeg_version":"6"},"object_storage":{"in_use":false,"prefixes":[]}}
JSON
cat >"$live" <<'JSON'
{"php":"8.5.12","public_files":2,"tables":3,"ffmpeg":"6"}
JSON
if xedc_compare_write "$manifest" "$live" "$tmp/parity.md"; then echo PASS compare_no_objects; else echo FAIL compare_no_objects; fail=1; fi

printf '%s\n' 'version=10.11.1' 'charset=utf8mb4' 'collation=utf8mb4_unicode_ci' 'sql_mode=STRICT' 'engine=InnoDB:4' 'tables=228' 'routines=2' 'triggers=1' >"$tmp/db.txt"
check dbver "$(xedc_database_fact "$tmp/db.txt" version)" 10.11.1
check dbtables "$(xedc_database_fact "$tmp/db.txt" tables)" 228
check dbengine "$(xedc_database_engines "$tmp/db.txt")" "InnoDB:4"

priv=$tmp/private
mkdir -p "$priv/backups" "$priv/xedc-storage-keys" "$priv/xedc-ai-keys"
printf '%s\n' secret >"$priv/backups/settings.php.before-object-storage"
printf '%s\n' key >"$priv/xedc-storage-keys/access_key"
printf '%s\n' aikey >"$priv/xedc-ai-keys/deepseek.key"
printf '%s\n' ok >"$priv/note.txt"
xedc_archive_private "$priv" "$tmp/private.tar.zst"
names=$(zstdcat "$tmp/private.tar.zst" | tar -tf -)
printf '%s\n' "$names" | grep -q 'note.txt' || { echo FAIL private_keep; fail=1; }
if printf '%s\n' "$names" | grep -q 'settings.php'; then echo FAIL private_settings; fail=1; else echo PASS private_settings; fi
if printf '%s\n' "$names" | grep -q 'access_key'; then echo FAIL private_keys; fail=1; else echo PASS private_keys; fi
if printf '%s\n' "$names" | grep -q 'deepseek.key'; then echo FAIL private_ai; fail=1; else echo PASS private_ai; fi
read -r counted _ < <(xedc_count_private_archive "$priv")
check private_count "$counted" 1

check ssl_method "$(SSL_METHOD=manual xedc_ssl_choose_method example.test)" manual
if ( XEDC_DNS_PROVIDER=ali xedc_dns_auto_a example.test 203.0.113.10 0 >/dev/null 2>"$tmp/dns.err" ); then
  echo FAIL dns_auto
  fail=1
else
  if grep -q '只支持 Cloudflare' "$tmp/dns.err"; then echo PASS dns_auto; else echo FAIL dns_auto_msg; fail=1; fi
fi

mkdir -p "$tmp/snap"
printf '%s\n' PASS >"$tmp/snap/STATUS"
XEDC_OFFSITE_DEST=$tmp/offsite xedc_backup_offsite "$tmp/snap"
if [[ -f $tmp/offsite/snap/STATUS ]]; then echo PASS offsite; else echo FAIL offsite; fail=1; fi

retain=$tmp/retain
mkdir -p "$retain/a" "$retain/b" "$retain/c"
printf '%s\n' PASS >"$retain/a/STATUS"
printf '%s\n' PASS >"$retain/b/STATUS"
printf '%s\n' FAIL >"$retain/c/STATUS"
xedc_backup_retain "$retain" 1
single=$tmp/retain-one
mkdir -p "$single/only"
printf '%s\n' PASS >"$single/only/STATUS"
xedc_backup_retain "$single" 1
if [[ -d $retain/b && ! -d $retain/a && -d $retain/c && -d $single/only ]]; then
  echo PASS retain
else
  echo FAIL retain
  fail=1
fi

rb=$tmp/rollback-case
mkdir -p "$rb/site/web" "$tmp/state" "$tmp/rbroot"
printf '%s\n' 'version-one' >"$rb/site/web/index.php"
export XEDC_SECRET_LIST=$tmp/secrets
export XEDC_STATE_ROOT=$tmp/state
export XEDC_ROLLBACK_ROOT=$tmp/rbroot
: >"$XEDC_SECRET_LIST"
xedc_rollback_begin
xedc_rollback_capture_tree "$rb/site"
mv "$rb/site" "$XEDC_ROLLBACK_DIR/site-before"
mkdir -p "$rb/site/web"
printf '%s\n' 'version-two' >"$rb/site/web/index.php"
if xedc_rollback_apply "$XEDC_ROLLBACK_DIR" "$rb/site" && [[ $(cat "$rb/site/web/index.php") == version-one && $(cat "$XEDC_ROLLBACK_DIR/ROLLBACK_STATUS") == PASS ]]; then
  echo PASS rollback_files
else
  echo FAIL rollback_files
  fail=1
fi
empty=$tmp/empty-rollback
export XEDC_ROLLBACK_ROOT=$tmp/rbempty
mkdir -p "$XEDC_ROLLBACK_ROOT"
xedc_rollback_begin
if xedc_rollback_apply "$XEDC_ROLLBACK_DIR" "$empty/missing"; then
  echo FAIL rollback_empty
  fail=1
else
  if [[ $(cat "$XEDC_ROLLBACK_DIR/ROLLBACK_STATUS") == FAIL ]]; then echo PASS rollback_empty; else echo FAIL rollback_status; fail=1; fi
fi

rm -rf "$tmp"
exit $fail
