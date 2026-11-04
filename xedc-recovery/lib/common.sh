#!/usr/bin/env bash
# shellcheck shell=bash
# 公共日志、锁、脱敏、资源检查。被入口脚本 source，不要直接执行业务。

if [[ -z ${XEDC_LIB:-} ]]; then
  XEDC_LIB=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
fi
XEDC_HOME=$(cd "${XEDC_LIB}/.." && pwd)
export XEDC_HOME

xedc_init() {
  set -Eeuo pipefail
  umask 022
  XEDC_SITE_ROOT=${XEDC_SITE_ROOT:-/var/www/drupal}
  XEDC_BACKUP_ROOT=${XEDC_BACKUP_ROOT:-/var/backups/xedc}
  XEDC_STATE_ROOT=${XEDC_STATE_ROOT:-/var/lib/xedc-recovery}
  XEDC_LOG_DIR=${XEDC_LOG_DIR:-/var/log/xedc-recovery}
  XEDC_DRY_RUN=${XEDC_DRY_RUN:-0}
  XEDC_WARN_COUNT=${XEDC_WARN_COUNT:-0}
  XEDC_SECRET_LIST=${XEDC_SECRET_LIST:-}
  if [[ -z ${XEDC_SECRET_LIST} || ! -f ${XEDC_SECRET_LIST} ]]; then
    XEDC_SECRET_LIST=$(mktemp)
    chmod 600 "$XEDC_SECRET_LIST"
  fi
  declare -ga XEDC_TEMPS=()
  if [[ $(id -u) -eq 0 ]]; then
    mkdir -p "$XEDC_LOG_DIR" "$XEDC_STATE_ROOT" "$XEDC_BACKUP_ROOT"
    chmod 700 "$XEDC_LOG_DIR" "$XEDC_STATE_ROOT" "$XEDC_BACKUP_ROOT"
  fi
  XEDC_LOG=${XEDC_LOG:-$XEDC_LOG_DIR/recovery.log}
}

xedc_note_secret() {
  local value=${1-}
  [[ -n $value && ${#value} -ge 6 ]] || return 0
  printf '%s\n' "$value" >>"$XEDC_SECRET_LIST"
}

xedc_redact() {
  local text=${1-}
  XEDC_REDACT_TEXT=$text XEDC_REDACT_FILE=$XEDC_SECRET_LIST python3 - <<'PY'
import os, re
text = os.environ.get("XEDC_REDACT_TEXT", "")
path = os.environ.get("XEDC_REDACT_FILE", "")
secrets = []
if path and os.path.isfile(path):
    with open(path, encoding="utf-8", errors="replace") as handle:
        secrets = [line.strip("\n") for line in handle if len(line.strip("\n")) >= 6]
secrets.sort(key=len, reverse=True)
for secret in secrets:
    text = text.replace(secret, "***")
text = re.sub(r"-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]+?-----END [A-Z ]*PRIVATE KEY-----", "***", text)
text = re.sub(r"AGE-SECRET-KEY-1[A-Z0-9]+", "***", text)
text = re.sub(r"(?i)(password|passwd|secret|token|api[_-]?key|hash_salt)(\s*[:=]\s*)(\"[^\"]*\"|'[^']*'|\S+)", r"\1\2***", text)
print(text, end="")
PY
}

xedc_log() {
  local level=$1
  shift
  local clean stamp line
  clean=$(xedc_redact "$*")
  stamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  line="$stamp [$level] $clean"
  printf '%s\n' "$line" >&2
  if [[ -n ${XEDC_LOG:-} && $XEDC_LOG != /dev/stderr ]]; then
    mkdir -p "$(dirname "$XEDC_LOG")"
    printf '%s\n' "$line" >>"$XEDC_LOG"
    chmod 600 "$XEDC_LOG" 2>/dev/null || true
  fi
}

xedc_warn() {
  XEDC_WARN_COUNT=$((${XEDC_WARN_COUNT:-0} + 1))
  xedc_log WARN "$*"
}

xedc_die() {
  xedc_log FAIL "$*"
  exit 1
}

xedc_on_err() {
  local rc=$1 line=$2
  xedc_log FAIL "命令失败，退出码 ${rc}，行 ${line}"
  exit "$rc"
}

xedc_require_root() {
  [[ $(id -u) -eq 0 ]] || xedc_die "请用 root 运行。"
}

xedc_have() {
  command -v "$1" >/dev/null 2>&1
}

xedc_mktemp() {
  local file
  file=$(mktemp)
  chmod 600 "$file"
  XEDC_TEMPS+=("$file")
  printf '%s\n' "$file"
}

xedc_cleanup_temps() {
  local file
  if declare -p XEDC_TEMPS >/dev/null 2>&1; then
    for file in "${XEDC_TEMPS[@]}"; do
      [[ -n $file ]] && rm -f "$file"
    done
  fi
  if [[ -n ${XEDC_SECRET_WORKDIR:-} && -d $XEDC_SECRET_WORKDIR ]]; then
    rm -rf "$XEDC_SECRET_WORKDIR"
  fi
}

xedc_lock() {
  local file=$1
  mkdir -p "$(dirname "$file")"
  exec 8>"$file"
  if ! flock -n 8; then
    xedc_die "已有任务在运行：${file}"
  fi
  chmod 600 "$file" || true
}

xedc_run() {
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    xedc_log INFO "dry-run: $*"
    return 0
  fi
  "$@"
}

xedc_nice() {
  if xedc_have ionice && ionice -c2 -n7 true >/dev/null 2>&1; then
    ionice -c2 -n7 nice -n 10 "$@"
  elif xedc_have nice; then
    nice -n 10 "$@"
  else
    "$@"
  fi
}

xedc_mem_available_kb() {
  awk '/MemAvailable:/ {print $2}' /proc/meminfo
}

xedc_disk_avail_kb() {
  local path=$1
  df -Pk "$path" | awk 'NR==2 {print $4}'
}

xedc_check_resources() {
  local path=$1 need_kb=${2:-8388608}
  local avail disk
  avail=$(xedc_mem_available_kb)
  disk=$(xedc_disk_avail_kb "$path")
  xedc_log INFO "可用内存 ${avail}KB，磁盘剩余 ${disk}KB（${path}）"
  if [[ $disk -lt $need_kb ]]; then
    xedc_die "磁盘剩余不足，需要至少 $((need_kb / 1024))MB。不会删除生产数据或已有备份。"
  fi
  if [[ $avail -lt 800000 ]]; then
    xedc_warn "可用内存低于约 800MB。恢复将尝试临时 swap，备份会降低优先级继续。"
  fi
}

xedc_machine_hash() {
  local id=""
  if [[ -r /etc/machine-id ]]; then
    id=$(tr -d '[:space:]' </etc/machine-id)
  else
    id=$(hostname)
  fi
  printf '%s' "$id" | sha256sum | awk '{print $1}'
}

xedc_assert_restore_target() {
  local source_hash=${1-}
  local local_hash
  local_hash=$(xedc_machine_hash)
  if [[ -n $source_hash && $source_hash == "$local_hash" && ${XEDC_RECOVERY_TEST:-0} != 1 ]]; then
    xedc_die "备份来自这台机器。为保护生产站点，恢复已停止。"
  fi
  if [[ -f /.dockerenv ]]; then
    return 0
  fi
  if [[ ${XEDC_RECOVERY_TEST:-0} == 1 ]]; then
    return 0
  fi
}

xedc_is_aws() {
  if [[ -r /sys/class/dmi/id/sys_vendor ]] && grep -qi 'amazon' /sys/class/dmi/id/sys_vendor; then
    return 0
  fi
  return 1
}

xedc_docs_dir() {
  if [[ -d ${XEDC_HOME}/docs && -w ${XEDC_HOME}/docs ]]; then
    printf '%s\n' "${XEDC_HOME}/docs"
    return 0
  fi
  mkdir -p "${XEDC_STATE_ROOT}/docs"
  printf '%s\n' "${XEDC_STATE_ROOT}/docs"
}

xedc_os_info() {
  # shellcheck disable=SC1091
  . /etc/os-release
  XEDC_OS_ID=${ID:-unknown}
  XEDC_OS_VERSION=${VERSION_ID:-unknown}
  XEDC_OS_CODENAME=${VERSION_CODENAME:-unknown}
  XEDC_OS_ARCH=$(dpkg --print-architecture 2>/dev/null || uname -m)
  XEDC_OS_PRETTY=${PRETTY_NAME:-$XEDC_OS_ID}
  export XEDC_OS_ID XEDC_OS_VERSION XEDC_OS_CODENAME XEDC_OS_ARCH XEDC_OS_PRETTY
}

xedc_os_supported() {
  xedc_os_info
  case "${XEDC_OS_ID}:${XEDC_OS_VERSION}" in
    ubuntu:20.04|ubuntu:22.04|ubuntu:24.04|ubuntu:26.04|ubuntu:26.10) return 0 ;;
    debian:11|debian:12|debian:13) return 0 ;;
    *)
      xedc_die "不支持的系统 ${XEDC_OS_ID} ${XEDC_OS_VERSION}。支持 Ubuntu 20.04/22.04/24.04/26.04/26.10 和 Debian 11/12/13。"
      ;;
  esac
}

xedc_json_escape() {
  python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' <<<"$1"
}

xedc_state_init() {
  local file=$1
  mkdir -p "$(dirname "$file")"
  if [[ ! -f $file ]]; then
    printf '%s\n' '{"done":{}}' >"$file"
    chmod 600 "$file"
  fi
}

xedc_step_done() {
  local file=$1 step=$2
  jq -e --arg step "$step" '.done[$step] == true' "$file" >/dev/null 2>&1
}

xedc_step_mark() {
  local file=$1 step=$2
  local tmp
  tmp=$(xedc_mktemp)
  jq --arg step "$step" '.done[$step] = true' "$file" >"$tmp"
  mv "$tmp" "$file"
  chmod 600 "$file"
}

xedc_maybe_swap() {
  local avail
  avail=$(xedc_mem_available_kb)
  if [[ $avail -ge 1200000 ]]; then
    return 0
  fi
  if swapon --show | awk 'NR>1 {print $1}' | grep -qx /swapfile.xedc-recovery; then
    return 0
  fi
  local disk
  disk=$(xedc_disk_avail_kb /)
  if [[ $disk -lt 3145728 ]]; then
    xedc_warn "内存不足且磁盘不足以创建临时 swap。"
    return 0
  fi
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    xedc_log INFO "dry-run: 将创建 1GB 临时 swap"
    return 0
  fi
  xedc_log INFO "可用内存 ${avail}KB，创建临时 swap。"
  if ! fallocate -l 1G /swapfile.xedc-recovery 2>/dev/null; then
    dd if=/dev/zero of=/swapfile.xedc-recovery bs=1M count=1024 status=none
  fi
  chmod 600 /swapfile.xedc-recovery
  mkswap /swapfile.xedc-recovery >/dev/null
  swapon /swapfile.xedc-recovery
  XEDC_SWAP_ADDED=1
}

xedc_swap_cleanup() {
  if [[ ${XEDC_SWAP_ADDED:-0} == 1 ]]; then
    swapoff /swapfile.xedc-recovery || true
    rm -f /swapfile.xedc-recovery
    XEDC_SWAP_ADDED=0
  fi
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 或 restore.sh 运行。" >&2
  exit 1
fi
