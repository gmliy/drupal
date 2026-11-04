#!/usr/bin/env bash
# 用本机隔离的 MinIO 验证上传、回读和 SHA-256。不使用生产对象存储凭据。
set -Eeuo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=../../lib/common.sh
source "$ROOT/lib/common.sh"
# shellcheck source=../../lib/environment.sh
source "$ROOT/lib/environment.sh"
# shellcheck source=../../lib/r2.sh
source "$ROOT/lib/r2.sh"
# shellcheck source=../../lib/package.sh
source "$ROOT/lib/package.sh"

xedc_init
server_bin=${XEDC_RCLONE_SERVER:-/tmp/rclone-v1.71.2-linux-amd64/rclone}
[[ -x $server_bin ]] || xedc_die "缺少带 serve s3 的 rclone：${server_bin}"
name=xedc-r2-s3-test
work=$(mktemp -d /var/lib/xedc-recovery/r2test.XXXXXX)
chmod 700 "$work"
export XEDC_LOG_DIR=$work/log
export XEDC_STATE_ROOT=$work/state
mkdir -p "$work/buckets/xedc-backup-test" "$XEDC_LOG_DIR" "$XEDC_STATE_ROOT"
"$server_bin" serve s3 "$work/buckets" --addr 127.0.0.1:19000 --auth-key xedctest,xedctestsecret >"$work/server.log" 2>&1 &
server_pid=$!

cleanup() {
  kill "$server_pid" >/dev/null 2>&1 || true
  wait "$server_pid" 2>/dev/null || true
  xedc_cleanup_temps || true
  rm -rf "$work"
}
trap cleanup EXIT

for _ in $(seq 1 30); do
  if grep -q 's3 server' "$work/server.log" 2>/dev/null; then
    break
  fi
  sleep 0.2
done
if ! grep -q 's3 server' "$work/server.log"; then
  cat "$work/server.log" >&2
  exit 1
fi

envfile=$work/r2.env
cat >"$envfile" <<'EOF'
R2_BUCKET=xedc-backup-test
R2_ACCESS_KEY_ID=xedctest
R2_SECRET_ACCESS_KEY=xedctestsecret
R2_ENDPOINT=http://127.0.0.1:19000
R2_PROVIDER=Other
EOF
chmod 600 "$envfile"
export XEDC_R2_ENV=$envfile
xedc_r2_load
xedc_r2_install_rclone
cfg=$(xedc_r2_rclone_config)

snap=$work/20260102T000000Z
out=$work/out
mkdir -p "$snap/docker-volumes" "$out" "$work/proj/web/themes/custom/xedc" "$work/svc/matomo" "$work/svc/netdata" "$work/sys/nginx"
printf '%s\n' '{"name":"fixture"}' >"$work/proj/composer.json"
printf '%s\n' '{}' >"$work/proj/composer.lock"
printf '%s\n' theme >"$work/proj/web/themes/custom/xedc/theme.txt"
printf '%s\n' 'services: {}' >"$work/svc/matomo/compose.yaml"
printf '%s\n' 'bind to = localhost' >"$work/svc/netdata/netdata.conf"
printf '%s\n' 'server {}' >"$work/sys/nginx/site.conf"
printf '%s\n' note >"$snap/docker-volumes/WARNING.txt"
tar -C "$work/proj" -cf - . | zstd -q -o "$snap/project-source.tar.zst"
tar -C "$work/svc" -cf - . | zstd -q -o "$snap/services-config.tar.zst"
tar -C "$work/sys" -cf - . | zstd -q -o "$snap/system-config.tar.zst"
printf '%s\n' fixture | zstd -q -o "$snap/database.sql.zst"
printf '' | zstd -q -o "$snap/files-public.tar.zst"
printf '' | zstd -q -o "$snap/files-private.tar.zst"
printf '%s\n' encrypted-placeholder >"$snap/secrets.enc"
cp "$work/proj/composer.lock" "$snap/composer.lock"
jq -n '{schema:2, created:"2026-01-02T00:00:00Z", drupal:{version:"11.4.11"}, database:{engine:"MariaDB", version:"11", charset:"utf8mb4", collation:"utf8mb4_unicode_ci", sql_mode:"STRICT", tables:1}, source:{php:"8.5.12"}, media:{ffmpeg_version:"test"}}' >"$snap/manifest.json"
printf '%s\n' PASS >"$snap/STATUS"
printf '%s\n' report >"$snap/BACKUP_REPORT.md"
(
  cd "$snap"
  find . -type f ! -name checksums.sha256 ! -name BACKUP_REPORT.md ! -name STATUS -print0 | sort -z | xargs -0 sha256sum >checksums.sha256
)
export XEDC_HOME=$ROOT
export XEDC_BACKUP_ROOT=$out
xedc_migration_package "$snap" "$out" >/dev/null
archive=$out/xedc-migration-20260102T000000Z.tar.zst
xedc_r2_upload_package "$archive"
dest=$work/downloaded
mkdir -p "$dest"
got=$(xedc_r2_download 20260102T000000Z "$dest" | head -n 1)
cmp -s "$archive" "$got"
echo PASS r2-roundtrip

badenv=$work/bad.env
cat >"$badenv" <<'EOF'
R2_BUCKET=xedc-backup-test
R2_ACCESS_KEY_ID=xedctest
R2_SECRET_ACCESS_KEY=wrong-secret-value
R2_ENDPOINT=http://127.0.0.1:19000
R2_PROVIDER=Other
EOF
chmod 600 "$badenv"
export XEDC_R2_ENV=$badenv
if ( xedc_r2_download 20260102T000000Z "$work/bad-download" >/dev/null 2>"$work/bad.log" ); then
  echo FAIL bad-key
  exit 1
fi
if find "$work" -name '.r2partial.*' | grep -q .; then
  echo FAIL leftover-temp
  exit 1
fi
echo PASS bad-key-cleaned
export XEDC_R2_ENV=$envfile
if ( xedc_r2_download 19990101T000000Z "$work/missing" >/dev/null 2>>"$work/bad.log" ); then
  echo FAIL missing-object
  exit 1
fi
echo PASS missing-object
echo PASS r2-minio
