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

xedc_init
server_bin=${XEDC_RCLONE_SERVER:-/tmp/rclone-v1.71.2-linux-amd64/rclone}
[[ -x $server_bin ]] || xedc_die "缺少带 serve s3 的 rclone：${server_bin}"
name=xedc-r2-s3-test
work=$(mktemp -d /var/lib/xedc-recovery/r2test.XXXXXX)
chmod 700 "$work"
mkdir -p "$work/buckets/xedc-backup-test"
"$server_bin" serve s3 "$work/buckets" --addr 127.0.0.1:19000 --auth-key xedctest,xedctestsecret >"$work/server.log" 2>&1 &
server_pid=$!

cleanup() {
  kill "$server_pid" >/dev/null 2>&1 || true
  wait "$server_pid" 2>/dev/null || true
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

archive=$work/xedc-migration-20260102T000000Z.tar.zst
printf 'migration-bytes\n' | zstd -q -o "$archive"
(
  cd "$work"
  sha256sum "$(basename "$archive")" >"$(basename "$archive").sha256"
)
printf '%s\n' '{"schema":2,"note":"test"}' >"$work/xedc-migration-20260102T000000Z.manifest.public.json"
xedc_r2_upload_package "$archive"
dest=$work/downloaded
mkdir -p "$dest"
got=$(xedc_r2_download 20260102T000000Z "$dest")
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
