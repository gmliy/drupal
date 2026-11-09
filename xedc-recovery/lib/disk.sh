#!/usr/bin/env bash
# shellcheck shell=bash
# 磁盘空间选择与恢复解压错误摘要。
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi

xedc_path_avail_kb() {
  local path=${1:-/}
  df -Pk "$path" 2>/dev/null | awk 'NR==2 {print $4}'
}

xedc_path_inodes_avail() {
  local path=${1:-/}
  df -Pi "$path" 2>/dev/null | awk 'NR==2 {print $4}'
}

# 在候选目录中选可用空间最大的可写目录（排除内存盘）。
xedc_pick_work_root() {
  local hint=${1:-}
  local -a cands=()
  [[ -n "$hint" ]] && cands+=("$hint")
  [[ -d "${XEDC_WORKDIR:-}" ]] && cands+=("$XEDC_WORKDIR")
  cands+=(/var/lib/xedc-recovery/work "$XEDC_BACKUP_DIR" "$XEDC_STATE_DIR" /var/tmp)
  local best="" best_kb=0 d kb
  declare -A seen=()
  local -a uniq=()
  for d in "${cands[@]}"; do
    [[ -n "$d" ]] || continue
    mkdir -p "$d" 2>/dev/null || continue
    [[ -n "${seen[$d]:-}" ]] && continue
    seen[$d]=1
    xedc_is_volatile_fs "$d" && continue
    uniq+=("$d")
  done
  for d in "${uniq[@]}"; do
    kb=$(xedc_path_avail_kb "$d")
    [[ "$kb" =~ ^[0-9]+$ ]] || continue
    if (( kb > best_kb )); then
      best_kb=$kb
      best=$d
    fi
  done
  if [[ -z "$best" && -n "$hint" ]]; then
    best=$(xedc_resolve_workdir "$hint")
  fi
  [[ -n "$best" ]] || xedc_die "找不到可写的恢复工作目录"
  printf '%s\n' "$best"
}

disk_manifest_backup_field() {
  local manifest=$1 field=$2
  [[ -f "$manifest" ]] || return 1
  php -r '
    $j = json_decode((string) file_get_contents($argv[1]), true);
    if (!is_array($j)) { exit(1); }
    $b = $j["backup"] ?? [];
    $f = $argv[2];
    echo (int) ($b[$f] ?? 0);
  ' "$manifest" "$field" 2>/dev/null || echo 0
}

# 估算恢复所需磁盘：包 + 解压体量 + DB 导入缓冲 + 20%
xedc_restore_need_kb() {
  local archive=$1 manifest=$2
  local arch_kb unpacked_kb db_kb need
  if [[ -f "$archive" ]]; then
    arch_kb=$(( $(stat -c%s "$archive" 2>/dev/null || echo 0) / 1024 ))
  else
    arch_kb=$(disk_manifest_backup_field "$manifest" archive_bytes)
    arch_kb=$(( arch_kb / 1024 ))
  fi
  unpacked_kb=$(disk_manifest_backup_field "$manifest" unpacked_bytes)
  unpacked_kb=$(( unpacked_kb / 1024 ))
  db_kb=$(disk_manifest_backup_field "$manifest" database_zst_bytes)
  db_kb=$(( db_kb * 3 / 1024 ))
  if (( unpacked_kb < arch_kb )); then
    unpacked_kb=$((arch_kb * 3))
  fi
  need=$(( arch_kb + unpacked_kb + db_kb ))
  need=$(( need + need / 5 + 524288 ))
  echo "$need"
}

xedc_disk_precheck_restore() {
  local archive=$1 manifest=$2 work_root=$3
  local need_kb avail_kb iavail mount site_root site_kb site_i
  need_kb=$(xedc_restore_need_kb "$archive" "$manifest")
  avail_kb=$(xedc_path_avail_kb "$work_root")
  iavail=$(xedc_path_inodes_avail "$work_root")
  mount=$(xedc_path_mount "$work_root")
  xedc_log "磁盘预检: 工作目录 ${work_root}（${mount}），可用 ${avail_kb}KB / inode ${iavail:-?}，估算需要 ${need_kb}KB"
  if [[ ! "$avail_kb" =~ ^[0-9]+$ ]] || (( avail_kb < need_kb )); then
    df -h "$work_root" >&2 || true
    df -i "$work_root" >&2 || true
    xedc_die "磁盘空间不足：分区 ${mount} 可用 ${avail_kb:-?}KB，恢复估算需要 ${need_kb}KB（含下载、解压、导入与 20% 余量）。请清理磁盘或加 --workdir 指向更大分区"
  fi
  if [[ "$iavail" =~ ^[0-9]+$ ]] && (( iavail < 50000 )); then
    df -i "$work_root" >&2 || true
    xedc_die "inode 不足：分区 ${mount} 可用 inode ${iavail}，恢复可能创建大量文件。请清理或换 --workdir"
  fi
  site_root=${XEDC_SITE_ROOT:-/var/www/drupal}
  if [[ -d "$(dirname "$site_root")" ]]; then
    site_kb=$(xedc_path_avail_kb "$site_root")
    site_i=$(xedc_path_inodes_avail "$site_root")
    if [[ "$site_kb" =~ ^[0-9]+$ ]] && (( site_kb < need_kb / 2 )); then
      mount=$(xedc_path_mount "$site_root")
      xedc_die "站点目录 ${site_root} 所在分区 ${mount} 可用 ${site_kb}KB，不足以还原代码/文件（估算至少 ${need_kb}KB）。请清理或调整安装路径"
    fi
    if [[ "$site_i" =~ ^[0-9]+$ ]] && (( site_i < 50000 )); then
      mount=$(xedc_path_mount "$site_root")
      xedc_die "站点目录 ${site_root} 所在分区 ${mount} inode 仅剩 ${site_i}，不足以还原"
    fi
  fi
}

xedc_work_dir() {
  local root marker
  root=$(xedc_pick_work_root "${XEDC_WORKDIR:-}")
  export XEDC_WORKDIR="$root"
  export TMPDIR="$root"
  marker="$XEDC_STATE_DIR/work.path"
  if [[ -f "$marker" ]]; then
    local saved
    saved=$(cat "$marker")
    if [[ -d "$saved" ]]; then
      printf '%s\n' "$saved"
      return 0
    fi
  fi
  mkdir -p "$root"
  local dir
  dir=$(xedc_work_mktemp_d restore.XXXXXX)
  chmod 700 "$dir"
  printf '%s\n' "$dir" >"$marker"
  chmod 600 "$marker"
  printf '%s\n' "$dir"
}

xedc_work_resume_cleanup() {
  local marker="$XEDC_STATE_DIR/work.path"
  local incomplete="$XEDC_STATE_DIR/work.incomplete"
  if [[ "$RESUME" != 1 ]]; then
    return 0
  fi
  if [[ -f "$incomplete" ]]; then
    local dir
    dir=$(cat "$incomplete" 2>/dev/null || true)
    if [[ -n "$dir" && -d "$dir" ]]; then
      xedc_log "resume：删除上次未完成解压目录 ${dir}"
      rm -rf "$dir"
    fi
    rm -f "$incomplete"
  fi
  if [[ -f "$marker" ]]; then
    local saved
    saved=$(cat "$marker")
    if [[ -d "$saved" ]] && [[ ! -f "$(xedc_step_dir)/database.done" ]]; then
      if [[ ! -f "$saved/db/site.sql.zst" && ! -d "$saved/code" ]]; then
        xedc_log "resume：清空未完成工作目录 ${saved}"
        rm -rf "$saved"/*
      elif [[ -d "$saved/code" ]] && ! find "$saved/code" -mindepth 1 -print -quit 2>/dev/null | grep -q .; then
        rm -rf "$saved"/*
      fi
    fi
  fi
}

xedc_work_mark_incomplete() {
  local dir=$1
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\n' "$dir" >"$XEDC_STATE_DIR/work.incomplete"
  chmod 600 "$XEDC_STATE_DIR/work.incomplete"
}

xedc_work_mark_complete() {
  rm -f "$XEDC_STATE_DIR/work.incomplete"
}

xedc_dir_need_kb() {
  local kb=${1:-1048576}
  echo "$kb"
}

xedc_disk_check_dir() {
  local dir=$1 need_kb=${2:-524288}
  mkdir -p "$dir" 2>/dev/null || true
  local avail
  avail=$(xedc_path_avail_kb "$dir")
  if [[ ! "$avail" =~ ^[0-9]+$ ]] || (( avail < need_kb )); then
    xedc_disk_enospc_diag "$dir"
    local mount
    mount=$(xedc_path_mount "$dir")
    xedc_die "目标目录 ${dir}（${mount}）可用空间 ${avail:-?}KB，需要至少 ${need_kb}KB"
  fi
}

xedc_disk_enospc_diag() {
  local path=${1:-/}
  xedc_warn "磁盘空间不足诊断（df -h / df -i）："
  df -h 2>&1 | while IFS= read -r line; do xedc_warn "$line"; done
  df -i 2>&1 | while IFS= read -r line; do xedc_warn "$line"; done
  if [[ -d "$path" ]]; then
    xedc_warn "目录 ${path} 下占用最大的 10 项："
    du -x -h "$path" 2>/dev/null | sort -hr | head -n 10 | while IFS= read -r line; do xedc_warn "$line"; done || true
  fi
}

xedc_archive_member_to_stream() {
  local archive=$1 member=$2
  local m="./${member#./}"
  zstd -dc "$archive" 2>/dev/null | tar -xO "$m" 2>/dev/null || \
    zstd -dc "$archive" 2>/dev/null | tar -xO "${member#./}" 2>/dev/null
}

xedc_archive_member_to_file() {
  local archive=$1 member=$2 dest=$3
  mkdir -p "$(dirname "$dest")"
  if ! xedc_archive_member_to_stream "$archive" "$member" >"$dest"; then
    return 1
  fi
  [[ -s "$dest" ]]
}

# 从 zst 流解压；tar 错误只写前几行与计数。
xedc_archive_extract() {
  local archive=$1 dest=$2
  shift 2
  local -a members=("$@")
  local err total=0 shown=0 line
  err=$(xedc_mktemp)
  mkdir -p "$dest"
  xedc_disk_check_dir "$dest" "$(xedc_dir_need_kb 262144)"
  set +o pipefail
  set +e
  if ((${#members[@]} > 0)); then
    zstd -dc "$archive" 2>>"$err" | tar -C "$dest" -xf - "${members[@]}" 2>>"$err"
  else
    zstd -dc "$archive" 2>>"$err" | tar -C "$dest" -xf - 2>>"$err"
  fi
  local rc=$?
  set -e
  set -o pipefail
  if [[ "$rc" == 0 ]]; then
    rm -f "$err"
    return 0
  fi
  if grep -qiE 'No space left on device|ENOSPC' "$err"; then
    xedc_disk_enospc_diag "$dest"
    xedc_die "解压失败：磁盘已满。请清理后加 --resume"
  fi
  total=$(wc -l <"$err" | tr -d ' ')
  xedc_warn "tar 解压失败，共 ${total} 行错误（仅显示前 20 行）："
  while IFS= read -r line && (( shown < 20 )); do
    xedc_warn "$line"
    shown=$((shown + 1))
  done <"$err"
  rm -f "$err"
  return "$rc"
}

xedc_archive_extract_manifest() {
  local archive=$1 dest=$2
  xedc_archive_extract "$archive" "$dest" ./manifest.json manifest.json 2>/dev/null || \
    xedc_archive_extract "$archive" "$dest" ./manifest.json
}
