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

xedc_rclone_conf() {
  xedc_load_r2_env
  if ! xedc_r2_ready; then
    xedc_die "缺少 R2 凭据。可执行 backup.sh --setup-r2，或导出 R2_ACCOUNT_ID、R2_ACCESS_KEY_ID、R2_SECRET_ACCESS_KEY、R2_BUCKET"
  fi
  local conf provider
  conf=$(mktemp)
  chmod 600 "$conf"
  if [[ "${R2_ENDPOINT}" == *r2.cloudflarestorage.com* ]]; then
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
acl = private
force_path_style = true
no_check_bucket = true
EOF
  if [[ "$provider" == "Cloudflare" ]]; then
    # rclone 1.60 上传成功后会按返回的 versionId 再 HEAD。R2 对这个请求返回 501。
    printf '%s\n' 'no_head = true' >>"$conf"
  fi
  printf '%s\n' "$conf"
}

xedc_r2_remote() {
  local key=$1
  printf 'r2:%s/%s%s\n' "$R2_BUCKET" "$R2_PREFIX" "$key"
}

xedc_r2() {
  local conf=$1
  shift
  local -a args=(--config "$conf" --s3-chunk-size 64M --s3-upload-cutoff 64M --retries 3)
  if [[ "${R2_ENDPOINT:-}" == *r2.cloudflarestorage.com* ]]; then
    args+=(--s3-no-head)
  fi
  rclone "${args[@]}" "$@"
}

xedc_r2_put_verified() {
  local conf=$1 src=$2 key=$3
  local dest local_sum remote_sum i
  dest=$(xedc_r2_remote "$key")
  local_sum=$(sha256sum "$src" | awk '{print $1}')
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
