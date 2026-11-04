#!/usr/bin/env bash
# 用小快照验证迁移包的生成、校验、损坏检测和私钥排除。不读取生产库。
set -Eeuo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=../../lib/common.sh
source "$ROOT/lib/common.sh"
# shellcheck source=../../lib/package.sh
source "$ROOT/lib/package.sh"

xedc_init
fail=0
work=$(mktemp -d /var/lib/xedc-recovery/pkgtest.XXXXXX)
chmod 700 "$work"
snap="$work/20260101T000000Z"
out="$work/out"
mkdir -p "$snap/docker-volumes" "$out" "$work/proj/web/themes/custom/xedc" "$work/svc/matomo" "$work/svc/netdata" "$work/sys/nginx" "$work/sys/php"
printf '%s\n' '{"name":"fixture"}' >"$work/proj/composer.json"
printf '%s\n' '{}' >"$work/proj/composer.lock"
printf '%s\n' 'theme' >"$work/proj/web/themes/custom/xedc/theme.txt"
printf '%s\n' 'services: {}' >"$work/svc/matomo/compose.yaml"
printf '%s\n' 'bind to = localhost' >"$work/svc/netdata/netdata.conf"
printf '%s\n' 'server {}' >"$work/sys/nginx/site.conf"
printf '%s\n' 'memory_limit=128M' >"$work/sys/php/php.ini"
printf '%s\n' 'volume-note' >"$snap/docker-volumes/WARNING.txt"
tar -C "$work/proj" -cf - . | zstd -q -o "$snap/project-source.tar.zst"
tar -C "$work/svc" -cf - . | zstd -q -o "$snap/services-config.tar.zst"
tar -C "$work/sys" -cf - . | zstd -q -o "$snap/system-config.tar.zst"
printf '%s\n' 'not-a-database' | zstd -q -o "$snap/database.sql.zst"
printf '' | zstd -q -o "$snap/files-public.tar.zst"
printf '' | zstd -q -o "$snap/files-private.tar.zst"
printf '%s\n' 'encrypted-placeholder' >"$snap/secrets.enc"
cp "$work/proj/composer.lock" "$snap/composer.lock"
jq -n '{
  schema: 2,
  drupal: {version: "11.4.11"},
  database: {engine: "MariaDB", version: "11", charset: "utf8mb4", collation: "utf8mb4_unicode_ci", sql_mode: "STRICT_TRANS_TABLES", tables: 1, root_password: "not-stored"},
  source: {php: "8.5.12"},
  object_storage: {endpoint: "https://secret.example", bucket: "secret-bucket", in_use: false},
  media: {ffmpeg_version: "test"}
}' >"$snap/manifest.json"
printf '%s\n' PASS >"$snap/STATUS"
printf '%s\n' 'report' >"$snap/BACKUP_REPORT.md"
(
  cd "$snap"
  find . -type f ! -name checksums.sha256 ! -name BACKUP_REPORT.md ! -name STATUS -print0 \
    | sort -z | xargs -0 sha256sum > checksums.sha256
)

export XEDC_HOME=$ROOT
export XEDC_BACKUP_ROOT=$out
xedc_migration_package "$snap" "$out" >"$work/path.txt"
archive=$(cat "$work/path.txt")
if [[ -f $archive && -f ${archive}.sha256 ]]; then
  echo PASS package-created
else
  echo FAIL package-created
  fail=1
fi
(
  cd "$(dirname "$archive")"
  sha256sum -c "$(basename "$archive").sha256" >/dev/null
)
echo PASS sha256
python3 - "$out"/xedc-migration-20260101T000000Z.manifest.public.json <<'PY'
import json, sys
data = json.load(open(sys.argv[1], encoding="utf-8"))
blob = json.dumps(data)
if "secret.example" in blob or "secret-bucket" in blob:
    raise SystemExit("public manifest leaked storage location")
print("PASS public-manifest")
PY
bad="$work/bad.tar.zst"
cp "$archive" "$bad"
printf 'x' >>"$bad"
if sha256sum -c "${archive}.sha256" >/dev/null 2>&1; then
  :
fi
sum=$(awk '{print $1}' "${archive}.sha256")
printf '%s  %s\n' "$sum" "$(basename "$bad")" >"$work/bad.sha256"
if XEDC_BACKUP_ROOT=$work xedc_migration_verify_archive "$bad" "$work/bad.sha256" >/dev/null 2>&1; then
  echo FAIL corrupt-detected
  fail=1
else
  echo PASS corrupt-detected
fi
cp -a "$snap" "$work/20260101T000001Z"
mkdir -p "$work/out2"
printf '%s\n' 'AGE-SECRET-KEY-1SHOULDNOTSHIP' >"$work/20260101T000001Z/age.key"
(
  cd "$work/20260101T000001Z"
  find . -type f ! -name checksums.sha256 ! -name BACKUP_REPORT.md ! -name STATUS -print0 \
    | sort -z | xargs -0 sha256sum > checksums.sha256
)
if (xedc_migration_package "$work/20260101T000001Z" "$work/out2" >/dev/null 2>&1); then
  echo FAIL age-key-excluded
  fail=1
else
  echo PASS age-key-excluded
fi
rm -rf "$work"
if [[ $fail -ne 0 ]]; then
  exit 1
fi
echo PASS migration-package
