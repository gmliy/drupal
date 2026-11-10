#!/usr/bin/env bash
# 备份 Drupal 站点。输出 tar.zst，不加密。
set -Eeuo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib/common.sh
source "$ROOT/lib/common.sh"
# shellcheck source=lib/r2.sh
source "$ROOT/lib/r2.sh"
# shellcheck source=lib/system.sh
source "$ROOT/lib/system.sh"
# shellcheck source=lib/rclone.sh
source "$ROOT/lib/rclone.sh"
# shellcheck source=lib/db.sh
source "$ROOT/lib/db.sh"
# shellcheck source=lib/disk.sh
source "$ROOT/lib/disk.sh"

TARGET=both
KEEP_LOCAL=7
KEEP_R2=30
DO_LIST=0
DO_SETUP=0
SITE=""
INCLUDE_STYLES=0
DO_CEX=1
XEDC_VERBOSE=0
CC_MOD=0
CC_THEME=0
CC_FILES=0
CC_BYTES=0
CC_NAMES=""
CC_THEME_NAMES=""
export XEDC_VERBOSE
BACKUP_STEP="启动"
BACKUP_TOTAL=8
BACKUP_OK=0
BACKUP_STARTED=$(date +%s)

usage() {
  cat <<'EOF'
用法: backup.sh [--target local|r2|both] [--keep-local N] [--keep-r2 N] [--setup-r2] [--list] [--verbose] [--include-styles] [--no-cex]
EOF
}

xedc_backup_stage() {
  BACKUP_STEP=$1
  local n=${2:-0} t0
  t0=$(date +%s)
  shift 2 || true
  "$@"
  local elapsed=$(( $(date +%s) - t0 ))
  if [[ "$n" -gt 0 ]]; then
    xedc_say "[${n}/${BACKUP_TOTAL}] ${BACKUP_STEP} 完成 ${elapsed} 秒"
  fi
}

xedc_backup_on_err() {
  local rc=$1 line=$2
  trap - ERR
  BACKUP_FAIL_REASON="步骤 ${BACKUP_STEP}，行 ${line}，退出码 ${rc}"
  xedc_say "失败: ${BACKUP_FAIL_REASON}"
  xedc_log_detail "失败: ${BACKUP_FAIL_REASON} 命令: ${BASH_COMMAND:-}"
  exit "$rc"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --target) TARGET=${2:-}; shift 2 ;;
    --keep-local) KEEP_LOCAL=${2:-}; shift 2 ;;
    --keep-r2) KEEP_R2=${2:-}; shift 2 ;;
    --setup-r2) DO_SETUP=1; shift ;;
    --list) DO_LIST=1; shift ;;
    --verbose) XEDC_VERBOSE=1; export XEDC_VERBOSE; shift ;;
    --include-styles) INCLUDE_STYLES=1; shift ;;
    --no-cex) DO_CEX=0; shift ;;
    -h|--help) usage; exit 0 ;;
    *) xedc_die "未知参数: $1" ;;
  esac
done

case "$TARGET" in
  local|r2|both) ;;
  *) xedc_die "--target 只能是 local、r2 或 both" ;;
esac

xedc_require_root
xedc_init_runtime_dirs
mkdir -p "$(dirname "$XEDC_BACKUP_LOG")" /var/lock
export XEDC_LOG_FILE=$XEDC_BACKUP_LOG
exec 9>/var/lock/xedc-backup.lock
if ! flock -n 9; then
  xedc_die "已有备份在运行"
fi

xedc_setup_r2() {
  xedc_load_r2_env
  if [[ -t 0 ]]; then
    local input
    read -r -p "R2 Account ID [${R2_ACCOUNT_ID:-}]: " input
    [[ -n "$input" ]] && R2_ACCOUNT_ID=$input
    read -r -p "Access Key ID [${R2_ACCESS_KEY_ID:-}]: " input
    [[ -n "$input" ]] && R2_ACCESS_KEY_ID=$input
    read -r -s -p "Secret Access Key: " input
    echo
    [[ -n "$input" ]] && R2_SECRET_ACCESS_KEY=$input
    read -r -p "Bucket [${R2_BUCKET:-}]: " input
    [[ -n "$input" ]] && R2_BUCKET=$input
    read -r -p "Prefix [${R2_PREFIX:-xedc/}]: " input
    [[ -n "$input" ]] && R2_PREFIX=$input
    read -r -p "Endpoint（可留空）: " input
    [[ -n "$input" ]] && R2_ENDPOINT=$input
  fi
  if [[ -z "${R2_ENDPOINT:-}" && -n "${R2_ACCOUNT_ID:-}" ]]; then
    R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
  fi
  if [[ -n "${R2_PREFIX:-}" && "${R2_PREFIX}" != */ ]]; then
    R2_PREFIX="${R2_PREFIX}/"
  fi
  R2_PREFIX=${R2_PREFIX:-xedc/}
  if ! xedc_r2_ready; then
    xedc_die "R2 凭据不完整"
  fi
  mkdir -p "$(dirname "$XEDC_R2_ENV")"
  (
    umask 077
    cat >"$XEDC_R2_ENV" <<EOF
R2_ACCOUNT_ID=${R2_ACCOUNT_ID}
R2_ACCESS_KEY_ID=${R2_ACCESS_KEY_ID}
R2_SECRET_ACCESS_KEY=${R2_SECRET_ACCESS_KEY}
R2_BUCKET=${R2_BUCKET}
R2_PREFIX=${R2_PREFIX}
R2_ENDPOINT=${R2_ENDPOINT}
${TELEGRAM_BOT_TOKEN:+TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}}
${TELEGRAM_CHAT_ID:+TELEGRAM_CHAT_ID=${TELEGRAM_CHAT_ID}}
EOF
    chmod 600 "$XEDC_R2_ENV"
  )
  local conf
  conf=$(xedc_rclone_conf)
  if ! xedc_r2 "$conf" lsf "$(xedc_r2_remote "")" --max-depth 1 >/dev/null; then
    xedc_r2 "$conf" mkdir "$(xedc_r2_remote "")" || true
    xedc_r2 "$conf" lsf "$(xedc_r2_remote "")" --max-depth 1 >/dev/null || xedc_die "R2 连通失败"
  fi
  rm -f "$conf"
  xedc_r2_public_warning
  xedc_log "R2 连通正常"
  local cron_answer=no telegram_answer=no
  if [[ -t 0 ]]; then
    read -r -p "写入每天 07:00 和 19:00 的备份 cron？[y/N] " cron_answer
    read -r -p "备份失败时发 Telegram？[y/N] " telegram_answer
  elif [[ "${XEDC_SETUP_CRON:-}" == "yes" ]]; then
    cron_answer=yes
  fi
  if [[ "$cron_answer" == "y" || "$cron_answer" == "Y" || "$cron_answer" == "yes" ]]; then
    local line current
    line="0 7,19 * * * ${ROOT}/backup.sh --target both >> ${XEDC_BACKUP_LOG} 2>&1"
    current=$(crontab -u root -l 2>/dev/null || true)
    if [[ "$current" != *"${ROOT}/backup.sh"* ]]; then
      printf '%s\n%s\n' "$current" "$line" | sed '/^$/d' | crontab -u root -
    fi
    xedc_log "已写入 cron，日志 ${XEDC_BACKUP_LOG}"
  fi
  if [[ "$telegram_answer" == "y" || "$telegram_answer" == "Y" || "$telegram_answer" == "yes" ]]; then
    local token chat
    read -r -p "Telegram Bot Token: " token
    read -r -p "Telegram Chat ID: " chat
    {
      echo "TELEGRAM_BOT_TOKEN=${token}"
      echo "TELEGRAM_CHAT_ID=${chat}"
    } >>"$XEDC_R2_ENV"
    chmod 600 "$XEDC_R2_ENV"
  fi
}

xedc_list_backups() {
  xedc_backup_perms
  echo "本地"
  local f base
  shopt -s nullglob
  for f in "$XEDC_BACKUP_DIR"/drupal-backup-*.tar.zst; do
    base=$(basename "$f" .tar.zst)
    if [[ -f "$XEDC_BACKUP_DIR/${base}.complete" ]]; then
      echo "$(xedc_backup_stamp_time "$base") $(du -h "$f" | awk '{print $1}') 完整 $f"
    else
      echo "$(xedc_backup_stamp_time "$base") 不完整 $f"
    fi
  done
  shopt -u nullglob
  xedc_load_r2_env
  if xedc_r2_ready && xedc_have rclone; then
    echo "R2"
    local conf
    conf=$(xedc_rclone_conf)
    local -a complete=()
    mapfile -t complete < <(xedc_r2_stamps "$conf")
    local -a all=()
    mapfile -t all < <(xedc_r2_all_archives "$conf")
    local item mark
    for item in "${all[@]}"; do
      [[ -z "$item" ]] && continue
      mark=不完整
      local c
      for c in "${complete[@]}"; do
        if [[ "$c" == "$item" ]]; then
          mark=完整
        fi
      done
      echo "$(xedc_backup_stamp_time "$item") ${mark} r2:${item}"
    done
    rm -f "$conf"
  fi
}

xedc_telegram() {
  xedc_load_r2_env
  if [[ -z "${TELEGRAM_BOT_TOKEN:-}" || -z "${TELEGRAM_CHAT_ID:-}" ]]; then
    return 0
  fi
  curl -fsS --max-time 15 -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" \
    --data-urlencode "text=$1" >/dev/null || true
}

MAINT=0
SITE=""
stage=""
maint_off() {
  if [[ "$MAINT" == 1 && -n "${SITE:-}" ]]; then
    local bin
    bin=$(xedc_drush || true)
    if [[ -n "$bin" ]]; then
      "$bin" -r "$SITE" maint:set 0 >/dev/null 2>&1 || true
    fi
    MAINT=0
  fi
}
xedc_backup_exit() {
  local rc=$?
  trap - EXIT ERR
  if [[ -n "${stage:-}" ]]; then
    rm -rf "$stage" 2>/dev/null || true
    xedc_log_detail "已清理暂存目录"
  fi
  maint_off
  if [[ "$DO_LIST" == 1 || "$DO_SETUP" == 1 ]]; then
    exit "$rc"
  fi
  if [[ "$BACKUP_OK" == 1 && "$rc" == 0 ]]; then
    exit 0
  fi
  if [[ "$rc" == 0 ]]; then
    rc=1
  fi
  xedc_say "备份失败: ${BACKUP_FAIL_REASON:-步骤 ${BACKUP_STEP}，退出码 ${rc}}"
  xedc_telegram "备份失败，日志 ${XEDC_BACKUP_LOG}"
  exit "$rc"
}
trap 'xedc_backup_on_err $? ${LINENO}' ERR
trap xedc_backup_exit EXIT

if [[ "$DO_SETUP" == 1 ]]; then
  xedc_install_rclone
  xedc_setup_r2
  if [[ "$DO_LIST" != 1 && "${XEDC_SETUP_ONLY:-1}" == 1 ]]; then
    exit 0
  fi
fi

if [[ "$DO_LIST" == 1 ]]; then
  xedc_list_backups
  exit 0
fi

if ! xedc_have zstd || ! xedc_have rsync || ! xedc_have php; then
  xedc_apt_install zstd rsync tar gzip
  if ! xedc_have php; then
    xedc_die "备份需要已经安装的 php 命令"
  fi
fi
if [[ "$TARGET" != local ]]; then
  xedc_install_rclone
fi

xedc_cleanup_stale_stages "$XEDC_BACKUP_DIR"
if xedc_is_volatile_fs "$XEDC_BACKUP_DIR"; then
  xedc_die "备份目录 ${XEDC_BACKUP_DIR} 位于内存盘，不能作暂存"
fi

SITE=$(xedc_site_root) || xedc_die "找不到 Drupal 站点"
export XEDC_SITE_ROOT=$SITE
xedc_log_detail "站点 ${SITE}"
rm -f "${XEDC_STATE_DIR}/backup-skipped.tsv"
xedc_log_test_cache_volumes
mkdir -p "$XEDC_STATE_DIR" "$XEDC_BACKUP_DIR"
chmod 700 "$XEDC_BACKUP_DIR"
BACKUP_STEP="探测清单"
local_manifest="$XEDC_STATE_DIR/manifest.json"
xedc_backup_stage "探测清单" 1 php "$ROOT/lib/php/discover.php" -o "$local_manifest"
xedc_log_exclude_list
if [[ -d "$SITE/config/sync" ]]; then
  while IFS= read -r mod; do
    [[ -n "$mod" ]] && xedc_warn "模块 ${mod} 恢复时将被卸载"
  done < <(php "$ROOT/lib/php/strip_excluded.php" --site "$SITE" --mode warn-enabled)
fi
if [[ -f "$XEDC_BACKUP_DIR/last-manifest.json" ]]; then
  php "$ROOT/lib/php/manifest_diff.php" "$XEDC_BACKUP_DIR/last-manifest.json" "$local_manifest" >&2 || true
else
  xedc_log "这是第一次记录恢复清单"
fi

avail_kb=$(xedc_path_avail_kb "$XEDC_BACKUP_DIR")
if (( avail_kb < 1048576 )); then
  xedc_die "备份磁盘剩余不足 1GB"
fi

BACKUP_STEP="维护与配置导出"
bin=$(xedc_drush || true)
if [[ -n "$bin" ]]; then
  if "$bin" -r "$SITE" maint:set 1; then
    MAINT=1
  else
    xedc_warn "未能打开维护模式"
  fi
  cfg_out=$("$bin" -r "$SITE" config:status 2>&1) || cfg_rc=$?
  cfg_rc=${cfg_rc:-0}
  xedc_log_detail "drush config:status 退出码 ${cfg_rc}"
  while IFS= read -r cfg_line; do
    [[ -n "$cfg_line" ]] && xedc_log_detail "config:status: ${cfg_line}"
  done <<<"$cfg_out"
  cfg_drift=0
  if grep -q 'No differences' <<<"$cfg_out"; then
    cfg_drift=0
  elif grep -Eq 'Different|Only in sync|Only in DB|\.yml' <<<"$cfg_out" || [[ "$cfg_rc" != 0 ]]; then
    cfg_drift=1
  fi
  if [[ "$cfg_drift" == 1 ]]; then
    xedc_say "警告: 数据库与 config/sync 不一致"
    xedc_log "数据库与 config/sync 不一致"
    if [[ "$DO_CEX" == 1 ]]; then
      xedc_say "执行 drush cex"
      if ! "$bin" -r "$SITE" cex -y; then
        xedc_warn "drush cex 失败，继续备份已有配置"
      fi
    else
      xedc_say "已指定 --no-cex，不执行导出"
    fi
  else
    xedc_log "配置与数据库一致"
  fi
else
  xedc_warn "没有 drush，跳过维护模式和配置导出"
fi

stage=$(mktemp -d -p "$XEDC_BACKUP_DIR" stage.XXXXXX)
mkdir -p "$stage/code" "$stage/files/public" "$stage/files/private" "$stage/db" "$stage/system"
cp "$local_manifest" "$stage/manifest.json"
public="$SITE/web/sites/default/files"
private=$(xedc_manifest_get "$local_manifest" private_path || true)
if [[ -z "$private" ]]; then
  private="$SITE/private"
fi
prev="$XEDC_BACKUP_DIR/file-snapshot"
files_saved_bytes=0
regen_sub=js,css,php,ctools,tmp
if [[ "$INCLUDE_STYLES" != 1 ]]; then
  regen_sub="${regen_sub},styles"
fi
IFS=',' read -ra regen_parts <<<"$regen_sub"
for sub in "${regen_parts[@]}"; do
  if [[ -d "$public/$sub" ]]; then
    b=$(xedc_dir_size_bytes "$public/$sub")
    files_saved_bytes=$((files_saved_bytes + b))
    xedc_backup_skip_logged "files/${sub}" "$b" "可再生聚合/缓存"
  fi
done
unset IFS
mkdir -p "$public"
BACKUP_STEP="同步站点文件"
file_ex=(--exclude '/js/' --exclude '/css/' --exclude '/php/' --exclude '/ctools/' --exclude '/tmp/')
if [[ "$INCLUDE_STYLES" != 1 ]]; then
  file_ex+=(--exclude '/styles/')
fi
t0=$(date +%s)
if [[ -d "$prev/public" ]]; then
  rsync -a "${file_ex[@]}" --link-dest="$prev/public" "$public/" "$stage/files/public/"
  xedc_log_detail "文件使用增量暂存"
else
  rsync -a "${file_ex[@]}" "$public/" "$stage/files/public/"
fi
if [[ -d "$private" ]]; then
  if [[ -d "$prev/private" ]]; then
    rsync -a --link-dest="$prev/private" "$private/" "$stage/files/private/"
  else
    rsync -a "$private/" "$stage/files/private/"
  fi
fi
xedc_say "[2/${BACKUP_TOTAL}] 同步站点文件 完成 $(( $(date +%s) - t0 )) 秒"

BACKUP_STEP="同步站点代码"
t0=$(date +%s)
rsync -a \
  --exclude '.git/' \
  --exclude 'vendor/' \
  --exclude 'node_modules/' \
  --exclude 'web/sites/default/files/' \
  --exclude 'web/sites/*/files/' \
  --exclude 'private/' \
  --exclude 'xedc-recovery/tests/logs/' \
  --exclude 'xedc-recovery/tests/work/' \
  --exclude '/docs/' \
  --exclude '/docs-archive/' \
  --exclude '_server/db.sql' \
  --exclude '*.tar.zst' \
  --exclude 'r2.env' \
  "$SITE/" "$stage/code/"
xedc_say "[3/${BACKUP_TOTAL}] 同步站点代码 完成 $(( $(date +%s) - t0 )) 秒"
saved_code=$(xedc_prune_tree_by_exclude "$stage/code")
php "$ROOT/lib/php/strip_excluded.php" --site "$stage/code" --mode composer >/dev/null
php "$ROOT/lib/php/strip_excluded.php" --site "$stage/code" --mode config >/dev/null
hits=()
mapfile -d '' hits < <(find "$stage/code" \( -iname '*matomo*' -o -iname '*piwik*' \) ! -path '*/themes/*' ! -path '*/modules/custom/*' -print0) || true
if ((${#hits[@]} > 0)); then
  rm -rf "${hits[@]}" 2>/dev/null || true
fi
BACKUP_STEP="核对自定义代码"
cc_summary=""
if ! cc_summary=$(php "$ROOT/lib/php/custom_code.php" inventory \
  --site "$SITE" \
  --stage "$stage/code" \
  --list "$stage/custom-code.list" \
  --manifest "$stage/manifest.json" \
  --include "$ROOT/include.list"); then
  BACKUP_FAIL_REASON="自定义代码没有完整进入备份"
  xedc_say "警告: 自定义代码清单与备份不一致"
  xedc_die "$BACKUP_FAIL_REASON"
fi
if [[ "$cc_summary" =~ ^SUMMARY\ modules=([0-9]+)\ themes=([0-9]+)\ files=([0-9]+)\ bytes=([0-9]+)\ names=([^ ]*)\ theme_names=(.*)$ ]]; then
  CC_MOD=${BASH_REMATCH[1]}
  CC_THEME=${BASH_REMATCH[2]}
  CC_FILES=${BASH_REMATCH[3]}
  CC_BYTES=${BASH_REMATCH[4]}
  CC_NAMES=${BASH_REMATCH[5]}
  CC_THEME_NAMES=${BASH_REMATCH[6]}
else
  BACKUP_FAIL_REASON="自定义代码摘要无法解析"
  xedc_die "$BACKUP_FAIL_REASON"
fi
xedc_log_detail "自定义代码主题: ${CC_THEME_NAMES:-无}"

cnf=$(xedc_mktemp)
chmod 600 "$cnf"
php "$ROOT/lib/php/settings_db.php" --write-cnf "$cnf" "$SITE/web/sites/default/settings.php" >/dev/null
db_name=$(xedc_manifest_get "$local_manifest" database.name)
if command -v mariadb-dump >/dev/null 2>&1; then
  dump_bin=mariadb-dump
elif command -v mysqldump >/dev/null 2>&1; then
  dump_bin=mysqldump
else
  xedc_die "没有 mariadb-dump 或 mysqldump"
fi
BACKUP_STEP="导出数据库"
db_dump_started=$(date +%s)
zflags=(-T0 -3 -o "$stage/db/site.sql.zst")
zq=$(xedc_zstd_quiet_flag)
[[ -n "$zq" ]] && zflags=("$zq" "${zflags[@]}")
db_dump_database "$cnf" "$db_name" "$SITE/web/sites/default/settings.php" "$dump_bin" \
  | php "$ROOT/lib/php/strip_excluded.php" --mode stream \
  | zstd "${zflags[@]}"
db_dump_elapsed=$(( $(date +%s) - db_dump_started ))
xedc_say "[4/${BACKUP_TOTAL}] 导出数据库 完成 ${db_dump_elapsed} 秒"
db_zst_size=$(stat -c%s "$stage/db/site.sql.zst" 2>/dev/null || echo 0)
# 新备份里缓存类表不应有数据行
db_prefix_now=$(db_prefix "$SITE/web/sites/default/settings.php" || true)
cache_pat=$(db_cache_insert_ere "$db_prefix_now")
cache_inserts=$(zstd -dc "$stage/db/site.sql.zst" | LC_ALL=C grep -a -cE "$cache_pat" || true)
if [[ "${cache_inserts:-0}" != 0 ]]; then
  xedc_warn "数据库 dump 仍含 ${cache_inserts} 条缓存类 INSERT，应只导结构"
else
  xedc_log_detail "数据库 dump 缓存类表数据行数为 0"
fi
prev_stats="$XEDC_BACKUP_DIR/last-db-stats.txt"
if [[ -f "$prev_stats" ]]; then
  prev_size=$(awk -F= '/^size=/{print $2; exit}' "$prev_stats")
  prev_time=$(awk -F= '/^seconds=/{print $2; exit}' "$prev_stats")
  xedc_log "数据库 dump ${db_zst_size} 字节 / ${db_dump_elapsed} 秒（上次 ${prev_size:-?} 字节 / ${prev_time:-?} 秒）"
else
  xedc_log "数据库 dump ${db_zst_size} 字节 / ${db_dump_elapsed} 秒"
fi
printf 'size=%s\nseconds=%s\n' "$db_zst_size" "$db_dump_elapsed" >"$prev_stats"
chmod 600 "$prev_stats"
rm -f "$cnf"
BACKUP_STEP="采集系统配置"
t0=$(date +%s)
system_capture "$stage/system"
xedc_say "[5/${BACKUP_TOTAL}] 采集系统配置 完成 $(( $(date +%s) - t0 )) 秒"

code_bytes=$(xedc_dir_size_bytes "$stage/code")
files_bytes=$(( $(xedc_dir_size_bytes "$stage/files/public") + $(xedc_dir_size_bytes "$stage/files/private") ))
db_bytes=$(stat -c%s "$stage/db/site.sql.zst" 2>/dev/null || echo 0)
sys_bytes=$(xedc_dir_size_bytes "$stage/system")
vol_bytes=0
if [[ -d "$stage/system/docker/volumes" ]]; then
  vol_bytes=$(du -sb "$stage/system/docker/volumes" 2>/dev/null | awk '{print $1}')
fi
unpacked_bytes=$((code_bytes + files_bytes + db_bytes + sys_bytes))
skip_saved=0
skip_count=0
if [[ -f "${XEDC_STATE_DIR}/backup-skipped.tsv" ]]; then
  skip_saved=$(awk -F'\t' '{s+=$2} END {print s+0}' "${XEDC_STATE_DIR}/backup-skipped.tsv")
  skip_count=$(wc -l <"${XEDC_STATE_DIR}/backup-skipped.tsv" | tr -d ' ')
fi
skip_saved=$((skip_saved + files_saved_bytes))
xedc_log_detail "备份暂存体积: 站点代码 ${code_bytes} 字节，文件 ${files_bytes}，数据库 ${db_bytes}，系统配置 ${sys_bytes}，Docker 卷 ${vol_bytes}"
xedc_log_detail "暂存目录前 15 大项:"
while IFS= read -r line; do
  [[ -n "$line" ]] && xedc_log_detail "  ${line}"
done < <(xedc_du_top_bytes "$stage" 15)
xedc_say "体积明细: 站点代码 $(xedc_human_bytes "$code_bytes") / 文件 $(xedc_human_bytes "$files_bytes") / 数据库 $(xedc_human_bytes "$db_bytes") / 系统配置 $(xedc_human_bytes "$sys_bytes") / Docker 卷 $(xedc_human_bytes "$vol_bytes")"
if (( skip_count > 0 || files_saved_bytes > 0 )); then
  xedc_say "已排除 ${skip_count} 项，约 $(xedc_human_bytes "$skip_saved")（可再生文件与清单项，详见日志）"
fi

stamp=$(date -u '+%Y%m%dT%H%M%S')
base="drupal-backup-${stamp}"
archive="$XEDC_BACKUP_DIR/${base}.tar.zst"
est_archive_bytes=$((unpacked_bytes / 4 + db_bytes))
need_stage_kb=$(( unpacked_bytes * 3 / 2 / 1024 + 524288 ))
if [[ "$avail_kb" =~ ^[0-9]+$ ]] && (( avail_kb < need_stage_kb )); then
  xedc_die "磁盘不足: 可用 ${avail_kb}KB，暂存估算需 ${need_stage_kb}KB（解压体量 1.5 倍）"
fi
BACKUP_STEP="打包压缩"
backup_manifest_json() {
  php -r 'echo json_encode([
    "archive_bytes" => (int)$argv[1],
    "unpacked_bytes" => (int)$argv[2],
    "database_zst_bytes" => (int)$argv[3],
    "categories" => [
      "code" => (int)$argv[4],
      "files" => (int)$argv[5],
      "database" => (int)$argv[3],
      "system" => (int)$argv[6],
      "docker_volumes" => (int)$argv[7],
    ],
  ], JSON_UNESCAPED_UNICODE);' \
    "$1" "$unpacked_bytes" "$db_bytes" "$code_bytes" "$files_bytes" "$sys_bytes" "$vol_bytes"
}
php "$ROOT/lib/php/backup_manifest.php" --file "$stage/manifest.json" --json "$(backup_manifest_json "$est_archive_bytes")"
pack_t0=$(date +%s)
zpack=( -T0 -3 -o "$archive" )
[[ -n "$zq" ]] && zpack=("$zq" "${zpack[@]}")
(
  umask 077
  tar -C "$stage" -cf - . | zstd "${zpack[@]}"
  (
    cd "$XEDC_BACKUP_DIR"
    sha256sum "${base}.tar.zst" >"${base}.tar.zst.sha256"
  )
)
archive_bytes=$(stat -c%s "$archive")
pack_elapsed=$(( $(date +%s) - pack_t0 ))
xedc_say "[6/${BACKUP_TOTAL}] 打包压缩 完成 ${pack_elapsed} 秒"
cp "$stage/manifest.json" "$XEDC_BACKUP_DIR/${base}.manifest.json"
php "$ROOT/lib/php/backup_manifest.php" --file "$XEDC_BACKUP_DIR/${base}.manifest.json" --json "$(backup_manifest_json "$archive_bytes")"
printf '%s\n' "$(awk '{print $1}' "$XEDC_BACKUP_DIR/${base}.tar.zst.sha256")" >"$XEDC_BACKUP_DIR/${base}.complete"
chmod 600 "$XEDC_BACKUP_DIR/${base}".*
xedc_backup_perms
mkdir -p "$prev/public" "$prev/private"
rsync -a --delete "$stage/files/public/" "$prev/public/" || xedc_warn "未能更新文件增量快照"
if [[ -d "$stage/files/private" ]]; then
  rsync -a --delete "$stage/files/private/" "$prev/private/" || true
fi
cp "$local_manifest" "$XEDC_BACKUP_DIR/last-manifest.json"
chmod 600 "$XEDC_BACKUP_DIR/last-manifest.json"
xedc_log_detail "本地备份 ${archive}"
BACKUP_STEP="保留策略与上传"

xedc_prune_local() {
  local -a files=()
  shopt -s nullglob
  files=("$XEDC_BACKUP_DIR"/drupal-backup-*.tar.zst)
  shopt -u nullglob
  local count=${#files[@]}
  if (( count <= KEEP_LOCAL )); then
    return 0
  fi
  local -a sorted
  mapfile -t sorted < <(printf '%s\n' "${files[@]}" | sort)
  local drop=$((count - KEEP_LOCAL))
  local i old base
  for ((i = 0; i < drop; i++)); do
    old=${sorted[$i]}
    base=$(basename "$old" .tar.zst)
    rm -f "$old" "$XEDC_BACKUP_DIR/${base}.tar.zst.sha256" "$XEDC_BACKUP_DIR/${base}.manifest.json" "$XEDC_BACKUP_DIR/${base}.complete"
    xedc_log "删除旧本地备份 ${base}"
  done
}
xedc_prune_local
xedc_say "[7/${BACKUP_TOTAL}] 本地保留策略 完成"

if [[ "$TARGET" != local ]]; then
  xedc_load_r2_env
  if ! xedc_r2_ready; then
    xedc_die "未配置 R2。先运行 backup.sh --setup-r2"
  fi
  conf=$(xedc_rclone_conf)
  xedc_r2_public_warning
  xedc_r2_put_verified "$conf" "$archive" "${base}.tar.zst" || xedc_die "R2 上传 ${base}.tar.zst 失败"
  xedc_r2_put_verified "$conf" "$XEDC_BACKUP_DIR/${base}.tar.zst.sha256" "${base}.tar.zst.sha256" \
    || xedc_die "R2 上传 ${base}.tar.zst.sha256 失败"
  xedc_r2_put_verified "$conf" "$XEDC_BACKUP_DIR/${base}.manifest.json" "${base}.manifest.json" \
    || xedc_die "R2 上传 ${base}.manifest.json 失败"
  xedc_r2_put_verified "$conf" "$XEDC_BACKUP_DIR/${base}.complete" "${base}.complete" \
    || xedc_die "R2 上传 ${base}.complete 失败"
  mkdir -p "$XEDC_BACKUP_DIR/bootstrap"
  chmod 700 "$XEDC_BACKUP_DIR/bootstrap"
  tool_tar="$XEDC_BACKUP_DIR/bootstrap/xedc-recovery.tar.gz"
  rm -f "$tool_tar"
  tar -C "$ROOT" \
    --exclude './tests/logs' \
    --exclude './tests/work' \
    --exclude '*.tar.zst' \
    --exclude './bootstrap/xedc-recovery.tar.gz' \
    -czf "$tool_tar" .
  chmod 600 "$tool_tar"
  xedc_r2_put_verified "$conf" "$ROOT/install.sh" "bootstrap/install.sh" \
    || xedc_die "R2 上传 bootstrap/install.sh 失败"
  xedc_r2_put_verified "$conf" "$tool_tar" "bootstrap/xedc-recovery.tar.gz" \
    || xedc_die "R2 上传 bootstrap/xedc-recovery.tar.gz 失败"
  rm -f "$tool_tar"
  mapfile -t complete < <(xedc_r2_stamps "$conf")
  count=${#complete[@]}
  if (( count > KEEP_R2 )); then
    drop=$((count - KEEP_R2))
    for ((i = 0; i < drop; i++)); do
      old=${complete[$i]}
      for suffix in .tar.zst .tar.zst.sha256 .manifest.json .complete; do
        xedc_r2 "$conf" deletefile "$(xedc_r2_remote "${old}${suffix}")" || true
      done
      xedc_log "删除旧 R2 备份 ${old}"
    done
  fi
  rm -f "$conf"
  xedc_say "[8/${BACKUP_TOTAL}] R2 上传 完成"
  xedc_log_detail "R2 上传完成 ${base}"
fi

sha=$(awk '{print $1}' "$XEDC_BACKUP_DIR/${base}.tar.zst.sha256")
total_elapsed=$(( $(date +%s) - BACKUP_STARTED ))
xedc_say "自定义代码摘要: 模块 ${CC_MOD:-0} 个、主题 ${CC_THEME:-0} 个、共 ${CC_FILES:-0} 个文件、总大小 $(xedc_human_bytes "${CC_BYTES:-0}")"
if [[ -n "${CC_NAMES:-}" ]]; then
  xedc_say "模块: ${CC_NAMES//,/ }"
else
  xedc_say "模块: 无"
fi
if [[ -n "${CC_THEME_NAMES:-}" ]]; then
  xedc_say "主题: ${CC_THEME_NAMES//,/ }"
else
  xedc_say "主题: 无"
fi
BACKUP_OK=1
xedc_say "备份完成: ${archive} $(xedc_human_bytes "$archive_bytes") sha256=${sha} 耗时 ${total_elapsed} 秒"
xedc_log_detail "备份完成 ${base}"
exit 0
