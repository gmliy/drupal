#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi

xedc_choose_stamp() {
  local spec=$1
  shift
  local -a stamps=("$@")
  local n=${#stamps[@]}
  if (( n == 0 )); then
    return 1
  fi
  case "$spec" in
    latest) printf '%s\n' "${stamps[$((n - 1))]}" ;;
    latest-1)
      if (( n < 2 )); then
        return 1
      fi
      printf '%s\n' "${stamps[$((n - 2))]}"
      ;;
    *)
      local s
      for s in "${stamps[@]}"; do
        if [[ "$s" == "$spec" || "$s" == "drupal-backup-${spec}" || "$s" == "drupal-backup-${spec}.tar.zst" ]]; then
          local base=$s
          base=${base%.tar.zst}
          printf '%s\n' "$base"
          return 0
        fi
      done
      return 1
      ;;
  esac
}

xedc_version_ge() {
  local have=$1 need=$2
  local IFS=. i av bv
  local -a a b
  have=${have#v}
  need=${need#v}
  have=${have%%-*}
  need=${need%%-*}
  read -r -a a <<<"$have"
  read -r -a b <<<"$need"
  for i in 0 1 2; do
    av=${a[$i]:-0}
    bv=${b[$i]:-0}
    av=${av%%[^0-9]*}
    bv=${bv%%[^0-9]*}
    av=${av:-0}
    bv=${bv:-0}
    if (( av > bv )); then
      return 0
    fi
    if (( av < bv )); then
      return 1
    fi
  done
  return 0
}

xedc_rclone_version() {
  local line=""
  line=$(rclone version 2>/dev/null | awk 'NR==1 {print $2}' || true)
  line=${line#v}
  printf '%s\n' "$line"
}

xedc_rclone_ge() {
  local have
  have=$(xedc_rclone_version)
  [[ -n "$have" ]] || return 1
  xedc_version_ge "$have" "$1"
}

xedc_r2_cloudflare() {
  [[ "${R2_ENDPOINT:-}" == *r2.cloudflarestorage.com* ]]
}

xedc_rclone_conf() {
  xedc_load_r2_env
  if ! xedc_r2_ready; then
    xedc_die "缺少 R2 凭据。可执行 backup.sh --setup-r2，或导出 R2_ACCOUNT_ID、R2_ACCESS_KEY_ID、R2_SECRET_ACCESS_KEY、R2_BUCKET"
  fi
  local conf provider
  conf=$(xedc_mktemp)
  chmod 600 "$conf"
  if xedc_r2_cloudflare; then
    provider=Cloudflare
  else
    provider=Minio
  fi
  cat >"$conf" <<EOF
[r2]
type = s3
provider = ${provider}
access_key_id = ${R2_ACCESS_KEY_ID}
secret_access_key = ${R2_SECRET_ACCESS_KEY}
endpoint = ${R2_ENDPOINT}
force_path_style = true
no_check_bucket = true
EOF
  if [[ "$provider" == "Cloudflare" ]]; then
    # 1.60 上传成功后会按 versionId 再 HEAD，R2 返回 501，重试才成功。
    # 新版 rclone 的 AWS SDK 还会默认带上 CRC32，R2 同样回 501。
    # 不发送 ACL。完整性只靠上传后的 sha256。
    {
      printf '%s\n' 'region = auto'
      if xedc_rclone_ge 1.53; then
        printf '%s\n' 'disable_checksum = true'
      fi
      if xedc_rclone_ge 1.63; then
        printf '%s\n' 'no_head = true'
      fi
      if xedc_rclone_ge 1.72; then
        printf '%s\n' 'use_data_integrity_protections = false'
      fi
    } >>"$conf"
  fi
  printf '%s\n' "$conf"
}

xedc_r2_remote() {
  local key=$1
  printf 'r2:%s/%s%s\n' "$R2_BUCKET" "$R2_PREFIX" "$key"
}

xedc_r2_redact() {
  local line=$1
  if [[ -n "${R2_SECRET_ACCESS_KEY:-}" && "$line" == *"$R2_SECRET_ACCESS_KEY"* ]]; then
    printf '%s\n' '[已隐藏含凭据的 rclone 输出]'
    return 0
  fi
  if [[ -n "${R2_ACCESS_KEY_ID:-}" && "$line" == *"$R2_ACCESS_KEY_ID"* ]]; then
    printf '%s\n' '[已隐藏含凭据的 rclone 输出]'
    return 0
  fi
  printf '%s\n' "$line"
}

xedc_r2() {
  local conf=$1
  shift
  local -a args=(--config "$conf" --s3-chunk-size 64M --s3-upload-cutoff 512M --retries 1 --low-level-retries 1)
  if [[ "${XEDC_VERBOSE:-0}" != 1 && ! -t 1 ]]; then
    args+=(--stats-one-line --stats 5s)
  fi
  if xedc_r2_cloudflare; then
    export AWS_REQUEST_CHECKSUM_CALCULATION=when_required
    export AWS_RESPONSE_CHECKSUM_VALIDATION=when_required
    export RCLONE_S3_DISABLE_CHECKSUM=true
    export RCLONE_S3_NO_HEAD=true
    export RCLONE_S3_USE_DATA_INTEGRITY_PROTECTIONS=false
    args+=(--s3-region auto)
    if xedc_rclone_ge 1.53; then
      args+=(--s3-disable-checksum)
    fi
    if xedc_rclone_ge 1.63; then
      args+=(--s3-no-head)
    fi
    if xedc_rclone_ge 1.72; then
      args+=(--s3-use-data-integrity-protections=false)
    fi
  fi
  local err rc=0 line safe
  err=$(xedc_mktemp)
  chmod 600 "$err"
  rclone "${args[@]}" "$@" 2>"$err" || rc=$?
  if [[ -s "$err" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
      safe=$(xedc_r2_redact "$line")
      xedc_log "rclone: ${safe}"
    done <"$err"
  fi
  if grep -E 'ERROR|NotImplemented|Attempt [0-9]+/[0-9]+ failed' "$err" >/dev/null 2>&1; then
    rm -f "$err"
    return 1
  fi
  rm -f "$err"
  return "$rc"
}

xedc_r2_put_verified() {
  local conf=$1 src=$2 key=$3
  local dest local_sum remote_sum i
  dest=$(xedc_r2_remote "$key")
  local_sum=$(sha256sum "$src" | awk '{print $1}')
  xedc_log "R2 上传 ${key}"
  for i in 1 2 3; do
    if xedc_r2 "$conf" copyto "$src" "$dest"; then
      remote_sum=$(xedc_r2 "$conf" cat "$dest" | sha256sum | awk '{print $1}')
      if [[ "$local_sum" == "$remote_sum" ]]; then
        return 0
      fi
      xedc_warn "远端校验不一致，准备第 ${i} 次重试"
    else
      xedc_warn "上传失败，准备第 ${i} 次重试"
    fi
  done
  return 1
}

xedc_r2_public_warning() {
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "${R2_ENDPOINT%/}/${R2_BUCKET}/" || true)
  if [[ "$code" == "200" ]]; then
    xedc_warn "存储桶返回 200，可能允许公开访问。请把桶改为私有"
  fi
}

xedc_r2_stamps() {
  local conf=$1
  local line base
  { xedc_r2 "$conf" lsf "$(xedc_r2_remote "")" --max-depth 1 2>/dev/null || true; } | while IFS= read -r line; do
    case "$line" in
      drupal-backup-*.complete)
        base=${line%.complete}
        printf '%s\n' "$base"
        ;;
    esac
  done | sort
}

xedc_r2_all_archives() {
  local conf=$1
  local line
  { xedc_r2 "$conf" lsf "$(xedc_r2_remote "")" --max-depth 1 2>/dev/null || true; } | while IFS= read -r line; do
    case "$line" in
      drupal-backup-*.tar.zst) printf '%s\n' "${line%.tar.zst}" ;;
    esac
  done | sort -u
}

xedc_backup_stamp_time() {
  local stamp=$1
  local raw=${stamp#drupal-backup-}
  if [[ "$raw" =~ ^([0-9]{8})T([0-9]{6})$ ]]; then
    date -u -d "${BASH_REMATCH[1]} ${BASH_REMATCH[2]:0:2}:${BASH_REMATCH[2]:2:2}:${BASH_REMATCH[2]:4:2}" '+%F %T UTC' 2>/dev/null || printf '%s\n' "$raw"
  else
    printf '%s\n' "$raw"
  fi
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "r2 函数库，由 backup.sh 和 restore.sh 调用" >&2
  exit 0
fi
