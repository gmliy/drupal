#!/usr/bin/env bash
# 新服务器引导：安装最小依赖，取回工具，再执行 restore.sh。
# 不含任何密钥。GH_TOKEN 只在运行时传入。
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "需要 root 运行" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
if [[ ! -d /run/systemd/system ]]; then
  printf '%s\n' '#!/bin/sh' 'exit 101' >/usr/sbin/policy-rc.d
  chmod 755 /usr/sbin/policy-rc.d
fi
apt-get update -y
apt-get install -y --no-install-recommends git curl ca-certificates tmux rclone tar gzip zstd unzip php-cli

DEST=${XEDC_INSTALL_DEST:-/opt/xedc-recovery}
API=${XEDC_GITHUB_API:-https://api.github.com}
REPO=${XEDC_GITHUB_REPO:-gmliy/drupal}
REF=${XEDC_GIT_REF:-main}

fetch_github() {
  local tmp extract top
  tmp=$(mktemp)
  curl -fsSL \
    -H "Authorization: Bearer ${GH_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    -o "$tmp" \
    "${API}/repos/${REPO}/tarball/${REF}"
  extract=$(mktemp -d)
  tar -xzf "$tmp" -C "$extract"
  top=$(find "$extract" -mindepth 1 -maxdepth 1 -type d | head -n 1)
  if [[ ! -d "$top/xedc-recovery" ]]; then
    echo "仓库压缩包里没有 xedc-recovery" >&2
    exit 1
  fi
  rm -rf "$DEST"
  mkdir -p "$DEST"
  cp -a "$top/xedc-recovery/." "$DEST/"
  rm -rf "$extract" "$tmp"
}

fetch_r2() {
  : "${R2_ACCESS_KEY_ID:?缺少 R2_ACCESS_KEY_ID}"
  : "${R2_SECRET_ACCESS_KEY:?缺少 R2_SECRET_ACCESS_KEY}"
  : "${R2_BUCKET:?缺少 R2_BUCKET}"
  if [[ -z "${R2_ENDPOINT:-}" && -n "${R2_ACCOUNT_ID:-}" ]]; then
    R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
  fi
  : "${R2_ENDPOINT:?缺少 R2_ENDPOINT 或 R2_ACCOUNT_ID}"
  if [[ -n "${R2_PREFIX:-}" && "${R2_PREFIX}" != */ ]]; then
    R2_PREFIX="${R2_PREFIX}/"
  fi
  R2_PREFIX=${R2_PREFIX:-xedc/}
  local provider=Minio
  if [[ "$R2_ENDPOINT" == *r2.cloudflarestorage.com* ]]; then
    provider=Cloudflare
  fi
  local conf tmp
  conf=$(mktemp)
  chmod 600 "$conf"
  cat >"$conf" <<EOF
[r2]
type = s3
provider = ${provider}
access_key_id = ${R2_ACCESS_KEY_ID}
secret_access_key = ${R2_SECRET_ACCESS_KEY}
endpoint = ${R2_ENDPOINT}
acl = private
force_path_style = true
no_check_bucket = true
EOF
  tmp=$(mktemp)
  rclone --config "$conf" copyto "r2:${R2_BUCKET}/${R2_PREFIX}bootstrap/xedc-recovery.tar.zst" "$tmp"
  rm -rf "$DEST"
  mkdir -p "$DEST"
  zstd -dc "$tmp" | tar -C "$DEST" -xf -
  rm -f "$tmp" "$conf"
}

if [[ -n "${GH_TOKEN:-}" ]]; then
  fetch_github
elif [[ -n "${R2_ACCESS_KEY_ID:-}" && -n "${R2_SECRET_ACCESS_KEY:-}" && -n "${R2_BUCKET:-}" ]]; then
  fetch_r2
elif [[ -t 0 ]]; then
  read -r -s -p "GH_TOKEN（只读，不会回显）: " GH_TOKEN
  echo
  if [[ -z "$GH_TOKEN" ]]; then
    echo "没有 GH_TOKEN，也没有 R2 凭据" >&2
    exit 1
  fi
  export GH_TOKEN
  fetch_github
else
  echo "需要 GH_TOKEN，或 R2_ACCESS_KEY_ID / R2_SECRET_ACCESS_KEY / R2_BUCKET" >&2
  exit 1
fi

find "$DEST" -name '*.sh' -exec chmod 755 {} +
if [[ "${XEDC_INSTALL_ONLY:-0}" == 1 ]]; then
  echo "工具已下载到 ${DEST}"
  exit 0
fi
exec bash "$DEST/restore.sh" "$@"
