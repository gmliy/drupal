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
XEDC_WORKDIR=${XEDC_WORKDIR:-/var/lib/xedc-recovery/work}
XEDC_RUN_DIR=${XEDC_RUN_DIR:-/run/xedc}
mkdir -p "$XEDC_WORKDIR" "$XEDC_RUN_DIR"
chmod 700 "$XEDC_WORKDIR" "$XEDC_RUN_DIR"
export TMPDIR="$XEDC_WORKDIR"
if [[ ! -d /run/systemd/system ]]; then
  printf '%s\n' '#!/bin/sh' 'exit 101' >/usr/sbin/policy-rc.d
  chmod 755 /usr/sbin/policy-rc.d
fi
apt-get update -y
apt-get install -y --no-install-recommends git curl ca-certificates tmux tar gzip zstd unzip

install_rclone() {
  local arch tmp zip bin
  case "$(uname -m)" in
    x86_64|amd64) arch=linux-amd64 ;;
    aarch64|arm64) arch=linux-arm64 ;;
    *) arch="" ;;
  esac
  tmp=$(mktemp -d -p "$XEDC_WORKDIR")
  if [[ -n "$arch" ]] && curl -fsSL --max-time 90 -o "$tmp/rclone.zip" "https://downloads.rclone.org/rclone-current-${arch}.zip" && unzip -qo "$tmp/rclone.zip" -d "$tmp"; then
    bin=$(find "$tmp" -type f -name rclone | head -n 1 || true)
    if [[ -n "$bin" ]]; then
      install -m 755 "$bin" /usr/local/bin/rclone
      install -m 755 "$bin" /usr/bin/rclone
      hash -r || true
      rm -rf "$tmp"
      if rclone version >/dev/null 2>&1; then
        return 0
      fi
    fi
  fi
  rm -rf "$tmp"
  echo "警告: 官方 rclone 没有装上，改用系统自带版本" >&2
  apt-get install -y --no-install-recommends rclone
}
install_rclone

DEST=${XEDC_INSTALL_DEST:-/opt/xedc-recovery}
API=${XEDC_GITHUB_API:-https://api.github.com}
REPO=${XEDC_GITHUB_REPO:-gmliy/drupal}
REF=${XEDC_GIT_REF:-main}

fetch_github() {
  local tmp extract top
  tmp=$(mktemp -p "$XEDC_WORKDIR")
  curl -fsSL \
    -H "Authorization: Bearer ${GH_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    -o "$tmp" \
    "${API}/repos/${REPO}/tarball/${REF}"
  extract=$(mktemp -d -p "$XEDC_WORKDIR")
  tar -xzf "$tmp" -C "$extract"
  top=$(find "$extract" -mindepth 1 -maxdepth 1 -type d | head -n 1)
  if [[ ! -d "$top/xedc-recovery" ]]; then
    echo "仓库压缩包里没有 xedc-recovery" >&2
    exit 1
  fi
  rm -rf "$DEST"
  mkdir -p "$DEST"
  cp -a "$top/xedc-recovery/." "$DEST/"
  printf '%s\n' github >"$DEST/.installed-from"
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
  local provider=Minio extra="" ver=""
  ver=$(rclone version 2>/dev/null | awk 'NR==1 {print $2}' || true)
  ver=${ver#v}
  if [[ "$R2_ENDPOINT" == *r2.cloudflarestorage.com* ]]; then
    provider=Cloudflare
    extra=$'region = auto'
    export AWS_REQUEST_CHECKSUM_CALCULATION=when_required
    export AWS_RESPONSE_CHECKSUM_VALIDATION=when_required
    export RCLONE_S3_DISABLE_CHECKSUM=true
    export RCLONE_S3_NO_HEAD=true
    export RCLONE_S3_USE_DATA_INTEGRITY_PROTECTIONS=false
    case "$ver" in
      ""|1.[0-4]*|1.5[0-2]*) ;;
      1.5[3-9]*|1.6[0-2]*) extra+=$'\ndisable_checksum = true' ;;
      1.6[3-9]*|1.7[01]*) extra+=$'\ndisable_checksum = true\nno_head = true' ;;
      *) extra+=$'\ndisable_checksum = true\nno_head = true\nuse_data_integrity_protections = false' ;;
    esac
  fi
  local conf pack
  conf=$(mktemp -p "$XEDC_RUN_DIR")
  chmod 600 "$conf"
  cat >"$conf" <<EOF
[r2]
type = s3
provider = ${provider}
access_key_id = ${R2_ACCESS_KEY_ID}
secret_access_key = ${R2_SECRET_ACCESS_KEY}
endpoint = ${R2_ENDPOINT}
force_path_style = true
no_check_bucket = true
${extra}
EOF
  pack="$DEST.pack"
  mkdir -p "$(dirname "$pack")"
  rm -rf "$pack"
  mkdir -p "$pack"
  local -a r2args=(--config "$conf" --retries 1 --low-level-retries 1)
  if [[ "$provider" == "Cloudflare" ]]; then
    r2args+=(--s3-region auto)
    case "$ver" in
      ""|1.[0-4]*|1.5[0-2]*) ;;
      1.5[3-9]*|1.6[0-2]*) r2args+=(--s3-disable-checksum) ;;
      1.6[3-9]*|1.7[01]*) r2args+=(--s3-disable-checksum --s3-no-head) ;;
      *) r2args+=(--s3-disable-checksum --s3-no-head --s3-use-data-integrity-protections=false) ;;
    esac
  fi
  rclone "${r2args[@]}" copyto "r2:${R2_BUCKET}/${R2_PREFIX}bootstrap/xedc-recovery.tar.gz" "$pack/xedc-recovery.tar.gz"
  rm -rf "$DEST"
  mkdir -p "$DEST"
  tar -xzf "$pack/xedc-recovery.tar.gz" -C "$DEST"
  printf '%s\n' r2 >"$DEST/.installed-from"
  rm -rf "$pack" "$conf"
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
if [[ -f "$DEST/VERSION" ]]; then
  ver=$(sed -n 's/^version=//p' "$DEST/VERSION" | head -n 1)
  built=$(sed -n 's/^built=//p' "$DEST/VERSION" | head -n 1)
  echo "工具版本: ${ver:-未知}"
  echo "构建时间: ${built:-未知}"
fi
if [[ "${XEDC_INSTALL_ONLY:-0}" == 1 ]]; then
  echo "工具已下载到 ${DEST}"
  exit 0
fi
exec bash "$DEST/restore.sh" "$@"
