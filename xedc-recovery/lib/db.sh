#!/usr/bin/env bash
# shellcheck shell=bash
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi
if [[ -z "${ROOT:-}" ]]; then
  ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
fi

# 测试可缩短：进度间隔与卡住判定（秒）
XEDC_IMPORT_PROGRESS_SECS="${XEDC_IMPORT_PROGRESS_SECS:-10}"
XEDC_IMPORT_STALL_SECS="${XEDC_IMPORT_STALL_SECS:-300}"

db_sql_filter() {
  printf '%s\n' "$ROOT/lib/php/sql_filter.php"
}

db_sql_skip_bin() {
  if [[ -n "${XEDC_SQL_SKIP_BIN:-}" ]]; then
    printf '%s\n' "$XEDC_SQL_SKIP_BIN"
    return 0
  fi
  # 仓库只读挂载（Docker 测试）时编到状态目录
  if [[ -w "$(dirname "$ROOT/lib/sql_skip_cache")" ]]; then
    printf '%s\n' "$ROOT/lib/sql_skip_cache"
  else
    mkdir -p "${XEDC_STATE_DIR:-/var/lib/xedc-recovery}"
    printf '%s\n' "${XEDC_STATE_DIR:-/var/lib/xedc-recovery}/sql_skip_cache"
  fi
}

db_prefix() {
  local settings=$1
  [[ -f "$settings" ]] || return 0
  php "$ROOT/lib/php/settings_db.php" --prefix "$settings"
}

db_prefix_from_manifest() {
  local manifest=$1
  [[ -f "$manifest" ]] || return 0
  php -r '$j=json_decode((string) file_get_contents($argv[1]), true); echo (string) ($j["database"]["prefix"] ?? "");' "$manifest" 2>/dev/null || true
}

db_escape_ere() {
  # 前缀仅含 [A-Za-z0-9_]，ERE 元字符无需转义
  printf '%s' "$1"
}

# 行首 INSERT/REPLACE 进缓存类表。等价于用户要求的 grep -a -v -E 模式。
# 前缀可选：`(prefix)?(cache|cache_*|...)`
db_cache_insert_ere() {
  local prefix=$1 esc tables
  esc=$(db_escape_ere "$prefix")
  tables='(cache|cache_[^\`]*|cachetags|sessions|watchdog|flood|semaphore)'
  if [[ -n "$esc" ]]; then
    printf '%s' "^(INSERT|REPLACE)( (IGNORE|LOW_PRIORITY|DELAYED))* INTO \`(${esc})?${tables}\`"
  else
    printf '%s' "^(INSERT|REPLACE)( (IGNORE|LOW_PRIORITY|DELAYED))* INTO \`${tables}\`"
  fi
}

db_ensure_sql_skip() {
  local src="$ROOT/lib/sql_skip_cache.c" bin
  bin=$(db_sql_skip_bin)
  if [[ -x "$bin" && ! "$src" -nt "$bin" ]]; then
    return 0
  fi
  local cc=""
  if xedc_have cc; then
    cc=cc
  elif xedc_have gcc; then
    cc=gcc
  else
    xedc_apt_install gcc libc6-dev >/dev/null 2>&1 || true
    if xedc_have cc; then
      cc=cc
    elif xedc_have gcc; then
      cc=gcc
    fi
  fi
  if [[ -z "$cc" ]]; then
    return 1
  fi
  mkdir -p "$(dirname "$bin")"
  "$cc" -O2 -std=c11 -o "$bin" "$src" || return 1
  chmod 755 "$bin"
}

db_ensure_pv() {
  if xedc_have pv; then
    return 0
  fi
  xedc_apt_install pv >/dev/null 2>&1 || true
  xedc_have pv
}

db_ephemeral_tables() {
  local cnf=$1 db=$2 settings=$3 prefix
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || xedc_die "数据库名非法"
  prefix=$(db_prefix "$settings")
  xedc_mysql --defaults-extra-file="$cnf" -N -B -e "SHOW TABLES FROM \`${db}\`" \
    | php "$(db_sql_filter)" --classify --prefix "$prefix"
}

db_dump_has_multiline_cache_insert() {
  local file=$1 prefix=$2 pat
  pat=$(db_cache_insert_ere "$prefix")
  # 压缩包：解压后找命中行且不以 ; 结尾（忽略结尾空白）
  if [[ "$file" == *.zst ]]; then
    zstd -dc "$file" 2>/dev/null | LC_ALL=C grep -a -E "$pat" | LC_ALL=C grep -a -vE ';[[:space:]]*$' | head -n 1 | grep -q .
  else
    LC_ALL=C grep -a -E "$pat" "$file" | LC_ALL=C grep -a -vE ';[[:space:]]*$' | head -n 1 | grep -q .
  fi
}

xedc_mysql() {
  if xedc_have mysql; then
    mysql "$@"
  elif xedc_have mariadb; then
    mariadb "$@"
  else
    xedc_die "没有 mysql 或 mariadb 客户端"
  fi
}

db_dump_database() {
  local cnf=$1 db=$2 settings=$3 dump_bin=$4
  local list table
  local -a skip=() ignore=()
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || xedc_die "数据库名非法"
  list=$(db_ephemeral_tables "$cnf" "$db" "$settings")
  while IFS= read -r table; do
    [[ -n "$table" ]] || continue
    [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || xedc_die "表名非法: ${table}"
    skip+=("$table")
  done <<< "$list"
  if ((${#skip[@]} > 0)); then
    xedc_log "这些表只导出结构、不导出数据: ${skip[*]}"
  else
    xedc_log "没有需要跳过数据的缓存表"
  fi
  xedc_log "queue 表如果存在，仍导出数据"
  for table in "${skip[@]+"${skip[@]}"}"; do
    ignore+=(--ignore-table="${db}.${table}")
  done
  "$dump_bin" --defaults-extra-file="$cnf" --single-transaction --routines --triggers \
    "${ignore[@]+"${ignore[@]}"}" "$db"
  if ((${#skip[@]} > 0)); then
    "$dump_bin" --defaults-extra-file="$cnf" --single-transaction --no-data --skip-triggers \
      "$db" "${skip[@]}"
  fi
}

db_target_nonempty() {
  local bin=$1 db=$2 count
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || return 1
  count=$("$bin" --protocol=socket -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}'" 2>/dev/null || true)
  [[ "${count:-0}" =~ ^[0-9]+$ && "$count" -gt 0 ]]
}

db_schema_bytes() {
  local bin=$1 db=$2
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || { echo 0; return; }
  "$bin" --protocol=socket -u root -N -e \
    "SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema='${db}'" 2>/dev/null || echo 0
}

db_imported_file() {
  printf '%s\n' "${XEDC_STATE_DIR}/database-imported"
}

db_owned_file() {
  printf '%s\n' "${XEDC_STATE_DIR}/database-owned"
}

db_mark_owned() {
  local db=$1
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\n' "$db" >"$(db_owned_file)"
  chmod 600 "$(db_owned_file)"
}

db_is_owned() {
  local db=$1 marked
  [[ -f "$(db_owned_file)" ]] || return 1
  marked=$(cat "$(db_owned_file)")
  [[ "$marked" == "$db" ]]
}

db_archive_sha256() {
  local archive=$1
  if [[ -f "${archive}.sha256" ]]; then
    awk '{print $1; exit}' "${archive}.sha256"
    return 0
  fi
  sha256sum "$archive" | awk '{print $1}'
}

db_mark_imported() {
  local db=$1 sha=${2:-}
  mkdir -p "$XEDC_STATE_DIR"
  {
    printf 'db=%s\n' "$db"
    printf 'sha256=%s\n' "$sha"
  } >"$(db_imported_file)"
  chmod 600 "$(db_imported_file)"
}

db_imported_matches() {
  local db=$1 sha=$2 marked_db marked_sha
  [[ -f "$(db_imported_file)" ]] || return 1
  marked_db=$(awk -F= '/^db=/{print substr($0,4); exit}' "$(db_imported_file)")
  marked_sha=$(awk -F= '/^sha256=/{print substr($0,8); exit}' "$(db_imported_file)")
  # 兼容旧格式：整文件只有库名
  if [[ -z "$marked_sha" && -z "$marked_db" ]]; then
    marked_db=$(tr -d '\n' <"$(db_imported_file)")
  fi
  [[ "$marked_db" == "$db" && -n "$sha" && "$marked_sha" == "$sha" ]]
}

db_clear_import_marks() {
  rm -f "$(db_imported_file)"
}

# 写入导入流的会话加速（max_allowed_packet 只读，靠 GLOBAL + 客户端参数）
db_import_session_sql() {
  cat <<'EOF'
SET SESSION foreign_key_checks=0;
SET SESSION unique_checks=0;
SET SESSION autocommit=0;
EOF
}

# 导入前用 root 调 GLOBAL（失败可忽略）
db_import_prepare_global() {
  local bin=$1 mb innodb
  xedc_load_tune 2>/dev/null || true
  if [[ -z "${INNODB_BUFFER:-}" ]]; then
    xedc_write_tune 2>/dev/null || true
  fi
  mb=${MEMORY_MB:-$(xedc_mem_mb)}
  innodb=${INNODB_BUFFER:-256M}
  xedc_log "导入加速参数: 内存 ${mb}MB，InnoDB 缓冲池目标 ${innodb}，max_allowed_packet=256M"
  "$bin" --protocol=socket -u root -e "
SET GLOBAL max_allowed_packet=268435456;
SET GLOBAL foreign_key_checks=0;
SET GLOBAL unique_checks=0;
SET GLOBAL innodb_flush_log_at_trx_commit=2;
" >/dev/null 2>&1 || true
  # MariaDB 10.5+ 可动态调整缓冲池；失败忽略
  "$bin" --protocol=socket -u root -e "SET GLOBAL innodb_buffer_pool_size=$(xedc_size_to_bytes "$innodb");" >/dev/null 2>&1 || true
}

xedc_size_to_bytes() {
  local s=$1
  if [[ "$s" =~ ^([0-9]+)[Gg]$ ]]; then
    echo $(( BASH_REMATCH[1] * 1024 * 1024 * 1024 ))
  elif [[ "$s" =~ ^([0-9]+)[Mm]$ ]]; then
    echo $(( BASH_REMATCH[1] * 1024 * 1024 ))
  elif [[ "$s" =~ ^([0-9]+)[Kk]$ ]]; then
    echo $(( BASH_REMATCH[1] * 1024 ))
  else
    echo "$s"
  fi
}

db_import_finish_sql() {
  cat <<'EOF'
COMMIT;
SET SESSION foreign_key_checks=1;
SET SESSION unique_checks=1;
SET SESSION autocommit=1;
EOF
}

db_filter_stream() {
  local prefix=$1
  local pat bin
  pat=$(db_cache_insert_ere "$prefix")
  if db_ensure_sql_skip; then
    bin=$(db_sql_skip_bin)
    "$bin" --prefix "$prefix"
    return $?
  fi
  xedc_warn "未能编译 sql_skip_cache，回退到 LC_ALL=C grep -a"
  # grep 无匹配时退出码 1，管道里视为成功
  LC_ALL=C grep -a -v -E "$pat" || [[ $? -eq 1 ]]
}

# pv -n 写的是百分比；换算已读字节。无 pv 时读 /proc/<pid>/io。
db_read_progress_bytes() {
  local pv_file=$1 reader_pid=$2 total=$3
  local pct bytes=0
  if [[ -n "$reader_pid" && -r "/proc/${reader_pid}/io" ]]; then
    bytes=$(awk '/^read_bytes:/ {print $2; exit}' "/proc/${reader_pid}/io" 2>/dev/null || echo 0)
    if [[ "$bytes" =~ ^[0-9]+$ && "$bytes" -gt 0 ]]; then
      echo "$bytes"
      return 0
    fi
  fi
  if [[ -f "$pv_file" ]]; then
    pct=$(tail -n 1 "$pv_file" 2>/dev/null | tr -dc '0-9' || echo 0)
    if [[ "$pct" =~ ^[0-9]+$ && "$total" =~ ^[0-9]+$ && "$total" -gt 0 ]]; then
      (( pct > 100 )) && pct=100
      echo $((total * pct / 100))
      return 0
    fi
  fi
  echo 0
}

db_processlist_summary() {
  local bin=$1
  "$bin" --protocol=socket -u root -N -e \
    "SELECT ID,USER,DB,COMMAND,TIME,STATE,LEFT(INFO,80) FROM information_schema.processlist WHERE COMMAND!='Sleep' ORDER BY TIME DESC LIMIT 5" \
    2>/dev/null | tr '\t' ' ' || true
}

db_current_import_table() {
  local bin=$1
  local info
  info=$("$bin" --protocol=socket -u root -N -e \
    "SELECT INFO FROM information_schema.processlist WHERE INFO IS NOT NULL AND (INFO LIKE 'INSERT%' OR INFO LIKE 'REPLACE%' OR INFO LIKE 'CREATE%' OR INFO LIKE 'LOCK%') ORDER BY TIME DESC LIMIT 1" \
    2>/dev/null || true)
  if [[ -z "$info" ]]; then
    echo "未知"
    return 0
  fi
  if [[ "$info" =~ INTO[[:space:]]+\`([^\`]+)\` ]]; then
    echo "${BASH_REMATCH[1]}"
  elif [[ "$info" =~ TABLE[[:space:]]+\`([^\`]+)\` ]]; then
    echo "${BASH_REMATCH[1]}"
  else
    echo "${info:0:60}"
  fi
}

db_mariadb_state() {
  local bin=$1
  "$bin" --protocol=socket -u root -N -e \
    "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_running','Innodb_buffer_pool_reads','Innodb_data_reads')" \
    2>/dev/null | tr '\t' '=' | tr '\n' ' ' || true
}

# 监控导入：每 PROGRESS_SECS 打日志；STALL_SECS 无增长则提示。
db_watch_import() {
  local bin=$1 db=$2 total=$3 pv_file=$4 reader_pid=$5 mysql_pid=$6
  local last_bytes=0 last_size=0 stall=0
  local bytes size pct
  while kill -0 "$mysql_pid" 2>/dev/null; do
    sleep "$XEDC_IMPORT_PROGRESS_SECS"
    kill -0 "$mysql_pid" 2>/dev/null || break
    bytes=$(db_read_progress_bytes "$pv_file" "$reader_pid" "$total")
    size=$(db_schema_bytes "$bin" "$db")
    pct=0
    if [[ "$total" =~ ^[0-9]+$ && "$total" -gt 0 && "$bytes" =~ ^[0-9]+$ ]]; then
      pct=$((bytes * 100 / total))
      (( pct > 100 )) && pct=100
    fi
    xedc_log "导入进度: 已读 ${bytes}/${total} 字节 (${pct}%)，库 ${db} 当前 ${size} 字节"
    if [[ "$bytes" == "$last_bytes" && "$size" == "$last_size" ]]; then
      stall=$((stall + XEDC_IMPORT_PROGRESS_SECS))
    else
      stall=0
      last_bytes=$bytes
      last_size=$size
    fi
    if (( stall >= XEDC_IMPORT_STALL_SECS )); then
      xedc_warn "导入已 ${stall} 秒无增长。正在导入的表: $(db_current_import_table "$bin")"
      xedc_warn "MariaDB 状态: $(db_mariadb_state "$bin")"
      xedc_warn "PROCESSLIST: $(db_processlist_summary "$bin")"
      stall=0
    fi
  done
}

db_explain_mysql_error() {
  local err_file=$1
  local useful
  useful=$(grep -E '^ERROR' "$err_file" | head -n 1 || true)
  if [[ -z "$useful" ]]; then
    useful=$(tr '\n' ' ' <"$err_file" | head -c 160)
  fi
  useful=$(printf '%s' "$useful" | tr '\n' ' ')
  if ((${#useful} > 160)); then
    useful="${useful:0:160}..."
  fi
  # 尽量抽出表名
  local table="未知表"
  if [[ "$useful" =~ Table[[:space:]]+[\'\`]([^\'\`]+)[\'\`] ]]; then
    table="${BASH_REMATCH[1]}"
  elif [[ "$useful" =~ \`([A-Za-z0-9_]+)\` ]]; then
    table="${BASH_REMATCH[1]}"
  fi
  local line=""
  if [[ "$useful" =~ at\ line\ ([0-9]+) ]]; then
    line="${BASH_REMATCH[1]}"
  fi
  if [[ -n "$line" ]]; then
    printf '导入失败: 表 %s，第 %s 行: %s\n' "$table" "$line" "$useful"
  else
    printf '导入失败: 表 %s: %s\n' "$table" "$useful"
  fi
}

# 从已压缩的 site.sql.zst 导入。
# 参数: bin db prefix sql_zst
db_import_sql_zst() {
  local bin=$1 db=$2 prefix=$3 sql_zst=$4
  local err rc start end elapsed
  local total=0 pv_file="" have_pv=0
  local reader_pid="" mysql_pid="" watch_pid="" pipe_pid=""
  local pat saved_flush=""

  [[ -f "$sql_zst" ]] || return 1
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || return 1

  err=$(xedc_mktemp)
  pv_file=$(xedc_mktemp)
  rc=0
  start=$(date +%s)
  total=$(stat -c%s "$sql_zst" 2>/dev/null || echo 0)

  saved_flush=$("$bin" --protocol=socket -u root -N -e "SELECT @@GLOBAL.innodb_flush_log_at_trx_commit" 2>/dev/null || echo 1)
  db_import_prepare_global "$bin"

  if db_ensure_pv; then
    have_pv=1
  fi

  pat=$(db_cache_insert_ere "$prefix")
  xedc_log "过滤缓存表 INSERT（LC_ALL=C grep -a 语义）: ${pat}"
  if ! db_ensure_sql_skip; then
    if db_dump_has_multiline_cache_insert "$sql_zst" "$prefix"; then
      xedc_die "dump 含跨多行的缓存 INSERT，且未能编译 sql_skip_cache"
    fi
  fi

  # 管道: pv -n 压缩包 | zstd -dc | C/grep 过滤 | mysql
  (
    db_import_session_sql
    if [[ "$have_pv" == 1 ]]; then
      pv -n "$sql_zst" 2>"$pv_file" | zstd -dc | db_filter_stream "$prefix"
    else
      zstd -dc "$sql_zst" | db_filter_stream "$prefix"
    fi
    db_import_finish_sql
  ) | "$bin" --protocol=socket -u root --default-character-set=utf8mb4 --max_allowed_packet=256M "$db" \
    >/dev/null 2>"$err" &
  mysql_pid=$!
  pipe_pid=$mysql_pid

  sleep 0.3
  if [[ "$have_pv" == 1 ]]; then
    reader_pid=$(pgrep -P "$pipe_pid" -a 2>/dev/null | awk '/\bpv\b/{print $1; exit}')
    if [[ -z "$reader_pid" ]]; then
      reader_pid=$(pgrep -x pv 2>/dev/null | tail -n 1 || true)
    fi
  else
    reader_pid=$(pgrep -a zstd 2>/dev/null | awk '/zstd -dc/{print $1; exit}')
  fi

  db_watch_import "$bin" "$db" "$total" "$pv_file" "$reader_pid" "$mysql_pid" &
  watch_pid=$!

  if ! wait "$mysql_pid"; then
    rc=$?
  fi
  kill "$watch_pid" 2>/dev/null || true
  wait "$watch_pid" 2>/dev/null || true

  if [[ "$saved_flush" =~ ^[0-9]+$ ]]; then
    "$bin" --protocol=socket -u root -e "SET GLOBAL innodb_flush_log_at_trx_commit=${saved_flush}" >/dev/null 2>&1 || true
  fi
  "$bin" --protocol=socket -u root -e "SET GLOBAL foreign_key_checks=1; SET GLOBAL unique_checks=1;" >/dev/null 2>&1 || true

  end=$(date +%s)
  elapsed=$((end - start))
  if [[ "$rc" != 0 ]]; then
    db_explain_mysql_error "$err" >&2
    rm -f "$err" "$pv_file"
    return "$rc"
  fi
  xedc_log "数据库导入完成，耗时 ${elapsed} 秒"
  rm -f "$err" "$pv_file"
  return 0
}

# 兼容旧接口：从 stdin 过滤后导入（单元测试用，无进度）
db_import_sql() {
  local bin=$1 db=$2 prefix=$3
  local err rc
  err=$(xedc_mktemp)
  rc=0
  db_import_prepare_global "$bin"
  {
    db_import_session_sql
    db_filter_stream "$prefix"
    db_import_finish_sql
  } | "$bin" --protocol=socket -u root --default-character-set=utf8mb4 --max_allowed_packet=256M "$db" \
    >/dev/null 2>"$err" || rc=$?
  if [[ "$rc" != 0 ]]; then
    db_explain_mysql_error "$err" >&2
    rm -f "$err"
    return "$rc"
  fi
  rm -f "$err"
}

db_archive_has_sql_zst() {
  local archive=$1
  [[ -f "$archive" ]] || return 1
  zstd -dc "$archive" 2>/dev/null | tar -t 2>/dev/null | grep -qE '(^|\.?/?)db/site\.sql\.zst$'
}

db_import_sql_zst_from_archive() {
  local bin=$1 db=$2 prefix=$3 archive=$4 manifest=${5:-}
  local err pv_file rc start end elapsed total=0 have_pv=0
  local mysql_pid watch_pid reader_pid saved_flush pipe_pid

  [[ -f "$archive" ]] || return 1
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || return 1
  if [[ -f "$manifest" ]]; then
    total=$(php -r '
      $j=json_decode((string) file_get_contents($argv[1]),true);
      echo (int) (($j["backup"]["database_zst_bytes"] ?? 0));
    ' "$manifest" 2>/dev/null || echo 0)
  fi

  err=$(xedc_mktemp)
  pv_file=$(xedc_mktemp)
  rc=0
  start=$(date +%s)
  saved_flush=$("$bin" --protocol=socket -u root -N -e "SELECT @@GLOBAL.innodb_flush_log_at_trx_commit" 2>/dev/null || echo 1)
  db_import_prepare_global "$bin"
  if db_ensure_pv; then
    have_pv=1
  fi
  pat=$(db_cache_insert_ere "$prefix")
  xedc_log "流式导入数据库（不落盘 sql 包）: ${pat}"
  if ! db_ensure_sql_skip; then
    xedc_warn "sql_skip_cache 未编译，流式导入仅用行首 grep"
  fi
  (
    db_import_session_sql
    zstd -dc "$archive" | { tar -xO ./db/site.sql.zst 2>/dev/null || tar -xO db/site.sql.zst 2>/dev/null; } \
      | zstd -dc | db_filter_stream "$prefix"
    db_import_finish_sql
  ) | "$bin" --protocol=socket -u root --default-character-set=utf8mb4 --max_allowed_packet=256M "$db" \
    >/dev/null 2>"$err" &
  mysql_pid=$!
  pipe_pid=$mysql_pid
  sleep 0.3
  reader_pid=$(pgrep -a zstd 2>/dev/null | awk '/zstd -dc/{print $1; exit}')
  db_watch_import "$bin" "$db" "$total" "$pv_file" "$reader_pid" "$mysql_pid" &
  watch_pid=$!
  if ! wait "$mysql_pid"; then
    rc=$?
  fi
  kill "$watch_pid" 2>/dev/null || true
  wait "$watch_pid" 2>/dev/null || true
  if [[ "$saved_flush" =~ ^[0-9]+$ ]]; then
    "$bin" --protocol=socket -u root -e "SET GLOBAL innodb_flush_log_at_trx_commit=${saved_flush}" >/dev/null 2>&1 || true
  fi
  "$bin" --protocol=socket -u root -e "SET GLOBAL foreign_key_checks=1; SET GLOBAL unique_checks=1;" >/dev/null 2>&1 || true
  end=$(date +%s)
  elapsed=$((end - start))
  if [[ "$rc" != 0 ]]; then
    db_explain_mysql_error "$err" >&2
    rm -f "$err" "$pv_file"
    return "$rc"
  fi
  xedc_log "数据库流式导入完成，耗时 ${elapsed} 秒"
  rm -f "$err" "$pv_file"
  return 0
}

db_rebuild_cache() {
  local bin
  bin=$(xedc_drush || true)
  if [[ -z "$bin" ]]; then
    xedc_warn "没有 drush，跳过缓存重建"
    return 0
  fi
  xedc_log "重建 Drupal 缓存"
  "$bin" -r "$SITE" cache:rebuild || "$bin" -r "$SITE" cr
}
