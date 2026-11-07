#!/usr/bin/env bash
# shellcheck shell=bash
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi
if [[ -z "${ROOT:-}" ]]; then
  ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
fi

db_sql_filter() {
  printf '%s\n' "$ROOT/lib/php/sql_filter.php"
}

db_prefix() {
  local settings=$1
  [[ -f "$settings" ]] || return 0
  php "$ROOT/lib/php/settings_db.php" --prefix "$settings"
}

db_ephemeral_tables() {
  local cnf=$1 db=$2 settings=$3 prefix
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || xedc_die "数据库名非法"
  prefix=$(db_prefix "$settings")
  xedc_mysql --defaults-extra-file="$cnf" -N -B -e "SHOW TABLES FROM \`${db}\`" \
    | php "$(db_sql_filter)" --classify --prefix "$prefix"
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

db_imported_file() {
  printf '%s\n' "${XEDC_STATE_DIR}/database-imported"
}

db_mark_imported() {
  local db=$1
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\n' "$db" >"$(db_imported_file)"
  chmod 600 "$(db_imported_file)"
}

db_import_sql() {
  local bin=$1 db=$2 prefix=$3 map err rc
  map=$(mktemp)
  err=$(mktemp)
  rc=0
  php "$(db_sql_filter)" --filter --prefix "$prefix" --map "$map" \
    | "$bin" --protocol=socket -u root --default-character-set=utf8mb4 "$db" >/dev/null 2>"$err" || rc=$?
  if [[ "$rc" != 0 ]]; then
    php "$(db_sql_filter)" --explain-error --map "$map" --err "$err" >&2 || true
    rm -f "$map" "$err"
    return "$rc"
  fi
  rm -f "$map" "$err"
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
