#!/usr/bin/env bash
# 隔离的上传、列表、下载和失败诊断。不读取生产库，不使用生产 R2 配置。
set -Eeuo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=../../lib/common.sh
source "$ROOT/lib/common.sh"
# shellcheck source=../../lib/package.sh
source "$ROOT/lib/package.sh"

fail=0
pass() { printf 'PASS %s\n' "$1"; }
bad() { printf 'FAIL %s\n' "$1"; fail=1; }
server_bin=${XEDC_RCLONE_SERVER:-/tmp/rclone-v1.71.2-linux-amd64/rclone}
[[ -x $server_bin ]] || { echo "缺少 rclone serve：$server_bin"; exit 1; }

work=$(mktemp -d /var/lib/xedc-recovery/r2transfer.XXXXXX)
chmod 700 "$work"
export XEDC_HOME=$ROOT
export XEDC_BACKUP_ROOT=$work/backups
export XEDC_STATE_ROOT=$work/state
export XEDC_LOG_DIR=$work/log
export XEDC_LOG=$work/log/recovery.log
mkdir -p "$XEDC_BACKUP_ROOT" "$XEDC_STATE_ROOT" "$XEDC_LOG_DIR" "$work/buckets/bucket-a" "$work/buckets/empty-bucket"
chmod 700 "$XEDC_BACKUP_ROOT" "$XEDC_STATE_ROOT" "$XEDC_LOG_DIR"
xedc_init

port=19121
while ss -ltn | awk '{print $4}' | grep -q ":${port}$"; do
  port=$((port + 1))
done
"$server_bin" serve s3 "$work/buckets" --addr "127.0.0.1:${port}" --auth-key xedctest,xedctestsecret >"$work/server.log" 2>&1 &
server_pid=$!
cleanup() {
  kill "$server_pid" >/dev/null 2>&1 || true
  wait "$server_pid" 2>/dev/null || true
  xedc_cleanup_temps || true
  rm -rf "$work"
}
trap cleanup EXIT
for _ in $(seq 1 50); do
  grep -q 's3 server' "$work/server.log" 2>/dev/null && break
  sleep 0.1
done
grep -q 's3 server' "$work/server.log"

write_env() {
  local dest=$1 bucket=$2 secret=$3 endpoint=$4
  cat >"$dest" <<EOF
R2_BUCKET=${bucket}
R2_ACCESS_KEY_ID=xedctest
R2_SECRET_ACCESS_KEY=${secret}
R2_ENDPOINT=${endpoint}
R2_PROVIDER=Other
EOF
  chmod 600 "$dest"
}

build_package() {
  local stamp=$1
  local snap=$work/snaps/$stamp
  local proj=$work/proj-$stamp
  mkdir -p "$snap/docker-volumes" "$proj/web/themes/custom/xedc" "$work/svc/matomo" "$work/svc/netdata" "$work/sys/nginx"
  printf '%s\n' '{"name":"fixture"}' >"$proj/composer.json"
  printf '%s\n' '{}' >"$proj/composer.lock"
  printf '%s\n' theme >"$proj/web/themes/custom/xedc/theme.txt"
  printf '%s\n' 'services: {}' >"$work/svc/matomo/compose.yaml"
  printf '%s\n' 'bind to = localhost' >"$work/svc/netdata/netdata.conf"
  printf '%s\n' 'server {}' >"$work/sys/nginx/site.conf"
  printf '%s\n' note >"$snap/docker-volumes/WARNING.txt"
  tar -C "$proj" -cf - . | zstd -q -o "$snap/project-source.tar.zst"
  tar -C "$work/svc" -cf - . | zstd -q -o "$snap/services-config.tar.zst"
  tar -C "$work/sys" -cf - . | zstd -q -o "$snap/system-config.tar.zst"
  printf '%s\n' "$stamp" | zstd -q -o "$snap/database.sql.zst"
  printf '' | zstd -q -o "$snap/files-public.tar.zst"
  printf '' | zstd -q -o "$snap/files-private.tar.zst"
  printf '%s\n' encrypted-placeholder >"$snap/secrets.enc"
  cp "$proj/composer.lock" "$snap/composer.lock"
  jq -n --arg created "$stamp" '{schema:2, created:"2026-01-03T00:00:00Z", drupal:{version:"11.4.11"}, database:{engine:"MariaDB", version:"11", charset:"utf8mb4", collation:"utf8mb4_unicode_ci", sql_mode:"STRICT", tables:1}, source:{php:"8.5.12"}, media:{ffmpeg_version:"test"}, note:$created}' >"$snap/manifest.json"
  printf '%s\n' PASS >"$snap/STATUS"
  printf '%s\n' report >"$snap/BACKUP_REPORT.md"
  (
    cd "$snap"
    find . -type f ! -name checksums.sha256 ! -name BACKUP_REPORT.md ! -name STATUS -print0 | sort -z | xargs -0 sha256sum >checksums.sha256
  )
  xedc_migration_package "$snap" "$XEDC_BACKUP_ROOT" >/dev/null
  printf '%s\n' "$XEDC_BACKUP_ROOT/xedc-migration-${stamp}.tar.zst"
}

expect_fail() {
  local name=$1
  shift
  local err=$work/err-$name
  if "$@" >"$work/out-$name" 2>"$err"; then
    bad "$name"
  else
    pass "$name"
  fi
}

write_env "$work/r2.env" bucket-a xedctestsecret "http://127.0.0.1:${port}"
export XEDC_R2_ENV=$work/r2.env
pkg=$(build_package 20260103T000000Z)
other=$(build_package 20260203T000000Z)
keeper=$work/keep.txt
printf 'keep\n' >"$keeper"

expect_fail missing-file "$ROOT/upload.sh" --file "$work/no-such.tar.zst"
cp -a "$pkg" "$work/bad-name.tar.zst"
expect_fail bad-name "$ROOT/upload.sh" --file "$work/bad-name.tar.zst"
mkdir -p "$work/notfile"
expect_fail directory "$ROOT/upload.sh" --file "$work/notfile"
ln -s "$pkg" "$work/xedc-migration-20260103T000000Z.tar.zst.link"
expect_fail symlink "$ROOT/upload.sh" --file "$work/xedc-migration-20260103T000000Z.tar.zst.link"

solo=$work/solo
mkdir -p "$solo"
cp -a "$pkg" "$solo/$(basename "$pkg")"
expect_fail missing-sha "$ROOT/upload.sh" --file "$solo/$(basename "$pkg")"
cp -a "${pkg}.sha256" "$solo/"
expect_fail missing-manifest "$ROOT/upload.sh" --file "$solo/$(basename "$pkg")"

badsha=$work/badsha
mkdir -p "$badsha"
cp -a "$pkg" "${pkg}.sha256" "${pkg%.tar.zst}.manifest.public.json" "$badsha/"
python3 - "$badsha/$(basename "$pkg").sha256" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8").read().split()
text[0] = "0" * 64
open(path, "w", encoding="utf-8").write(text[0] + "  " + text[1] + "\n")
PY
expect_fail bad-sha "$ROOT/upload.sh" --file "$badsha/$(basename "$pkg")"

corrupt=$work/corrupt
mkdir -p "$corrupt"
cp -a "$pkg" "$corrupt/$(basename "$pkg")"
printf 'x' >>"$corrupt/$(basename "$pkg")"
(
  cd "$corrupt"
  sha256sum "$(basename "$pkg")" >"$(basename "$pkg").sha256"
)
cp -a "${pkg%.tar.zst}.manifest.public.json" "$corrupt/"
expect_fail corrupt-zstd "$ROOT/upload.sh" --file "$corrupt/$(basename "$pkg")"

mismatch=$work/mismatch
mkdir -p "$mismatch"
cp -a "$pkg" "${pkg}.sha256" "$mismatch/"
python3 - "$mismatch/$(basename "$pkg" .tar.zst).manifest.public.json" <<'PY'
import json, sys
json.dump({"schema": 1}, open(sys.argv[1], "w", encoding="utf-8"))
PY
expect_fail manifest-mismatch "$ROOT/upload.sh" --file "$mismatch/$(basename "$pkg")"

expect_fail missing-config env XEDC_R2_ENV=$work/no-r2.env "$ROOT/upload.sh" --file "$pkg"
badperm=$work/bad-perm.env
write_env "$badperm" bucket-a xedctestsecret "http://127.0.0.1:${port}"
chmod 644 "$badperm"
expect_fail bad-permission env XEDC_R2_ENV=$badperm "$ROOT/upload.sh" --file "$pkg"
if ! grep -q '权限不正确' "$work/err-bad-permission"; then
  bad bad-permission-message
else
  pass bad-permission-message
fi

touch "$work/rclone-called"
cat >"$work/rclone-spy" <<'EOF'
#!/bin/bash
echo called >>"$XEDC_SPY_FLAG"
exit 1
EOF
chmod 755 "$work/rclone-spy"
: >"$work/rclone-called"
if XEDC_RCLONE_BIN=$work/rclone-spy XEDC_SPY_FLAG=$work/rclone-called "$ROOT/upload.sh" --dry-run --file "$pkg" >"$work/dry.out" 2>"$work/dry.err"; then
  if [[ ! -s $work/rclone-called ]] && grep -q 'dry-run 未上传' "$work/dry.out"; then
    pass dry-run
  else
    bad dry-run
  fi
else
  bad dry-run
fi
if "$ROOT/download.sh" --source=r2 --list >"$work/list-empty.out" 2>"$work/list-empty.err"; then
  if grep -q 'R2 中没有找到迁移包。' "$work/list-empty.out" && grep -q '列表状态：PASS' "$work/list-empty.out"; then
    pass list-empty
  else
    bad list-empty
  fi
else
  bad list-empty
fi

"$ROOT/upload.sh" --file "$pkg" >"$work/up.out" 2>"$work/up.err"
grep -q '远端对象：xedc-backups/2026/01/' "$work/up.out"
grep -q 'result=PASS' "$XEDC_LOG_DIR/upload-xedc-migration-20260103T000000Z.log"
grep -q 'sha256=PASS' "$XEDC_LOG_DIR/upload-xedc-migration-20260103T000000Z.log"
grep -q '临时配置权限=600' "$XEDC_LOG_DIR/upload-xedc-migration-20260103T000000Z.log"
pass upload-ok
"$ROOT/upload.sh" --file "$pkg" >"$work/up2.out" 2>"$work/up2.err"
if grep -q '未覆盖' "$work/up2.err"; then
  pass no-overwrite-same
else
  bad no-overwrite-same
fi
"$ROOT/upload.sh" --file "$other" >/dev/null
if "$ROOT/download.sh" --source=r2 --list >"$work/list.out" 2>"$work/list.err"; then
  if grep -q 'xedc-migration-20260203T000000Z.tar.zst' "$work/list.out" && grep -q 'xedc-migration-20260103T000000Z.tar.zst' "$work/list.out" && grep -q '对象数量：2' "$work/list.out" && ! grep -q '\.sha256' "$work/list.out" && ! grep -q 'manifest' "$work/list.out"; then
    pass list-objects
  else
    bad list-objects
  fi
  first=$(awk '/^xedc-migration-/ {print $1; exit}' "$work/list.out")
  if [[ $first == xedc-migration-20260203T000000Z.tar.zst ]]; then
    pass list-order
  else
    bad list-order
  fi
  size=$(stat -c %s "$other")
  if grep -q "${size} 字节" "$work/list.out"; then
    pass list-size
  else
    bad list-size
  fi
else
  bad list-objects
fi

dl=$work/download
mkdir -p "$dl"
printf 'old\n' >"$dl/marker.txt"
"$ROOT/download.sh" --source=r2 --output "$dl" >"$work/dl-latest.out"
latest=$(head -n 1 "$work/dl-latest.out")
if [[ $(basename "$latest") == xedc-migration-20260203T000000Z.tar.zst ]] && [[ -f ${latest}.sha256 && -f ${latest%.tar.zst}.manifest.public.json ]]; then
  pass download-latest
else
  bad download-latest
fi
(
  cd "$dl"
  sha256sum -c xedc-migration-20260203T000000Z.tar.zst.sha256 >/dev/null
)
zstd -t -q "$dl/xedc-migration-20260203T000000Z.tar.zst"
pass download-checksum
cmp -s "$other" "$dl/xedc-migration-20260203T000000Z.tar.zst"
pass download-bytes
spec=$work/download-spec
mkdir -p "$spec"
"$ROOT/download.sh" --source=r2 --backup 20260103T000000Z --output "$spec" >/dev/null
cmp -s "$pkg" "$spec/xedc-migration-20260103T000000Z.tar.zst"
pass download-specific
if [[ $(cat "$dl/marker.txt") == old ]]; then
  pass kept-unrelated
else
  bad kept-unrelated
fi
expect_fail exists "$ROOT/download.sh" --source=r2 --backup 20260203T000000Z --output "$dl"
if cmp -s "$other" "$dl/xedc-migration-20260203T000000Z.tar.zst"; then
  pass exists-unchanged
else
  bad exists-unchanged
fi

write_env "$work/empty.env" empty-bucket xedctestsecret "http://127.0.0.1:${port}"
if XEDC_R2_ENV=$work/empty.env "$ROOT/download.sh" --source=r2 --list >"$work/empty-list.out" 2>"$work/empty-list.err"; then
  grep -q 'R2 中没有找到迁移包。' "$work/empty-list.out" && pass list-other-empty || bad list-other-empty
else
  bad list-other-empty
fi
write_env "$work/auth.env" bucket-a wrong-secret-value "http://127.0.0.1:${port}"
expect_fail auth env XEDC_R2_ENV=$work/auth.env "$ROOT/download.sh" --source=r2 --list
if grep -q '鉴权失败' "$work/err-auth"; then
  pass auth-message
else
  bad auth-message
fi
write_env "$work/net.env" bucket-a xedctestsecret "http://127.0.0.1:9"
expect_fail network env XEDC_R2_ENV=$work/net.env "$ROOT/download.sh" --source=r2 --list
if grep -q '无法连接 R2' "$work/err-network" && ! grep -q '列表状态：PASS' "$work/out-network"; then
  pass network-message
else
  bad network-message
fi
write_env "$work/nobucket.env" missing-bucket-name xedctestsecret "http://127.0.0.1:${port}"
expect_fail no-bucket env XEDC_R2_ENV=$work/nobucket.env "$ROOT/download.sh" --source=r2 --list
if grep -q 'Bucket 不存在' "$work/err-no-bucket"; then
  pass no-bucket-message
else
  bad no-bucket-message
fi

cfg=$(mktemp)
chmod 600 "$cfg"
cat >"$cfg" <<EOF
[xedc]
type = s3
provider = Other
env_auth = false
access_key_id = xedctest
secret_access_key = xedctestsecret
endpoint = http://127.0.0.1:${port}
force_path_style = true
acl = private
no_check_bucket = true
EOF
RCLONE_CONFIG=$cfg rclone deletefile "xedc:bucket-a/xedc-backups/2026/01/xedc-migration-20260103T000000Z.manifest.public.json"
miss=$work/miss-manifest
mkdir -p "$miss"
printf 'stay\n' >"$miss/stay.txt"
expect_fail manifest-missing "$ROOT/download.sh" --source=r2 --backup 20260103T000000Z --output "$miss"
if [[ -f $miss/stay.txt && ! -e $miss/xedc-migration-20260103T000000Z.tar.zst ]]; then
  pass manifest-missing-cleanup
else
  bad manifest-missing-cleanup
fi
RCLONE_CONFIG=$cfg rclone copyto "${pkg%.tar.zst}.manifest.public.json" "xedc:bucket-a/xedc-backups/2026/01/xedc-migration-20260103T000000Z.manifest.public.json"

python3 - <<PY
open("$work/bad-remote.sha256","w",encoding="utf-8").write("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  xedc-migration-20260103T000000Z.tar.zst\n")
PY
RCLONE_CONFIG=$cfg rclone copyto "$work/bad-remote.sha256" "xedc:bucket-a/xedc-backups/2026/01/xedc-migration-20260103T000000Z.tar.zst.sha256"
sha_dl=$work/sha-bad
mkdir -p "$sha_dl"
expect_fail bad-remote-sha "$ROOT/download.sh" --source=r2 --backup 20260103T000000Z --output "$sha_dl"
if [[ ! -e $sha_dl/xedc-migration-20260103T000000Z.tar.zst ]]; then
  pass bad-remote-sha-cleanup
else
  bad bad-remote-sha-cleanup
fi
RCLONE_CONFIG=$cfg rclone copyto "${pkg}.sha256" "xedc:bucket-a/xedc-backups/2026/01/xedc-migration-20260103T000000Z.tar.zst.sha256"

printf 'not-zstd\n' >"$work/notzstd"
(
  cd "$work"
  sha256sum notzstd >notzstd.sha256
)
# The remote object name must stay the migration name. Upload raw bytes under that key and a matching sha.
python3 - <<PY
import hashlib, pathlib
data = b"not-a-zstd-archive\n"
path = pathlib.Path("$work/notzstd")
path.write_bytes(data)
digest = hashlib.sha256(data).hexdigest()
pathlib.Path("$work/notzstd.sha256").write_text(digest + "  xedc-migration-20260203T000000Z.tar.zst\n", encoding="utf-8")
PY
RCLONE_CONFIG=$cfg rclone copyto "$work/notzstd" "xedc:bucket-a/xedc-backups/2026/02/xedc-migration-20260203T000000Z.tar.zst"
RCLONE_CONFIG=$cfg rclone copyto "$work/notzstd.sha256" "xedc:bucket-a/xedc-backups/2026/02/xedc-migration-20260203T000000Z.tar.zst.sha256"
zstd_dl=$work/zstd-bad
mkdir -p "$zstd_dl"
expect_fail bad-remote-zstd "$ROOT/download.sh" --source=r2 --backup 20260203T000000Z --output "$zstd_dl"
if [[ ! -e $zstd_dl/xedc-migration-20260203T000000Z.tar.zst ]]; then
  pass bad-remote-zstd-cleanup
else
  bad bad-remote-zstd-cleanup
fi

cat >"$work/rclone-size" <<EOF
#!/bin/bash
real=/usr/bin/rclone
cmd=""
for arg in "\$@"; do
  case \$arg in
    ls|lsf|copyto) cmd=\$arg ;;
  esac
done
if [[ \$cmd == ls ]]; then
  echo "1 2026/01/xedc-migration-20260103T000000Z.tar.zst"
  exit 0
fi
exec "\$real" "\$@"
EOF
chmod 755 "$work/rclone-size"
size_dl=$work/size-bad
mkdir -p "$size_dl"
expect_fail size-mismatch env XEDC_RCLONE_BIN=$work/rclone-size "$ROOT/download.sh" --source=r2 --backup 20260103T000000Z --output "$size_dl"

third=$(build_package 20260104T000000Z)
cat >"$work/rclone-upload-size" <<'EOF'
#!/bin/bash
real=/usr/bin/rclone
cmd=""
for arg in "$@"; do
  case $arg in
    ls|lsf|copyto) cmd=$arg ;;
  esac
done
if [[ $cmd == copyto ]]; then
  echo yes >>"${XEDC_UPLOAD_SEEN}"
fi
if [[ $cmd == ls && -s ${XEDC_UPLOAD_SEEN:-} ]]; then
  key="${@: -1}"
  key="${key#xedc:}"
  echo "1 ${key#*/}"
  exit 0
fi
exec "$real" "$@"
EOF
chmod 755 "$work/rclone-upload-size"
: >"$work/upload-seen"
if XEDC_RCLONE_BIN=$work/rclone-upload-size XEDC_UPLOAD_SEEN=$work/upload-seen "$ROOT/upload.sh" --file "$third" >"$work/size-up.out" 2>"$work/size-up.err"; then
  bad remote-size-mismatch
else
  if grep -q 'remote-size' "$work/size-up.err"; then
    pass remote-size-mismatch
  else
    bad remote-size-mismatch
  fi
fi

fourth=$(build_package 20260105T000000Z)
cat >"$work/rclone-reread" <<'EOF'
#!/bin/bash
real=/usr/bin/rclone
args=("$@")
cmd=""
i=0
while [[ $i -lt ${#args[@]} ]]; do
  case ${args[$i]} in
    copyto) cmd=copyto; src=${args[$((i+1))]:-}; dest=${args[$((i+2))]:-} ;;
    ls|lsf) cmd=${args[$i]} ;;
  esac
  i=$((i+1))
done
if [[ $cmd == copyto && $src == xedc:* && $dest != xedc:* && $dest == *.tar.zst ]]; then
  printf 'corrupt\n' >"$dest"
  exit 0
fi
exec "$real" "$@"
EOF
chmod 755 "$work/rclone-reread"
if XEDC_RCLONE_BIN=$work/rclone-reread "$ROOT/upload.sh" --file "$fourth" >"$work/reread.out" 2>"$work/reread.err"; then
  bad remote-sha-mismatch
else
  if grep -q 'remote-sha256' "$work/reread.err"; then
    pass remote-sha-mismatch
  else
    bad remote-sha-mismatch
  fi
fi

mapfile -t leftovers < <(find "$XEDC_STATE_ROOT" "$work" \( -name '.rclone*' -o -name 'xedc-r2partial.*' -o -name 'xedc-r2dl.*' \) 2>/dev/null || true)
if [[ ${#leftovers[@]} -gt 0 ]]; then
  printf 'leftover %s\n' "${leftovers[@]}" >&2
  bad temp-cleaned
else
  pass temp-cleaned
fi
if grep -R -q 'xedctestsecret' "$XEDC_LOG_DIR" "$work/up.err" "$work/list.out" "$work/dry.out" 2>/dev/null; then
  bad secret-redacted
else
  pass secret-redacted
fi
if [[ -s $keeper && $(cat "$keeper") == keep ]]; then
  pass original-package-kept
else
  bad original-package-kept
fi

if [[ $fail -ne 0 ]]; then
  exit 1
fi
echo PASS r2-transfer
