#!/usr/bin/env bash
# shellcheck shell=bash

XEDC_ROLLBACK_ROOT=${XEDC_ROLLBACK_ROOT:-/var/lib/xedc-recovery/rollback}

xedc_rollback_begin() {
  local stamp
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  XEDC_ROLLBACK_DIR=$XEDC_ROLLBACK_ROOT/$stamp
  mkdir -p "$XEDC_ROLLBACK_DIR"/{nginx,php,settings,database,services}
  chmod 700 "$XEDC_ROLLBACK_DIR"
  printf '%s\n' "$XEDC_ROLLBACK_DIR" >"$XEDC_STATE_ROOT/rollback-latest"
  chmod 600 "$XEDC_STATE_ROOT/rollback-latest"
}

xedc_rollback_save_file() {
  local src=$1 dest_name=$2
  if [[ -e $src ]]; then
    mkdir -p "$XEDC_ROLLBACK_DIR/files"
    cp -a "$src" "$XEDC_ROLLBACK_DIR/files/$dest_name"
  fi
}

xedc_rollback_capture_tree() {
  local root=$1
  [[ -d ${XEDC_ROLLBACK_DIR:-} && -d $root ]] || return 0
  local list=$XEDC_ROLLBACK_DIR/expected.sha256
  local rel file found
  : >"$list"
  for rel in composer.json web/index.php web/sites/default/settings.php web/sites/default/settings.local.php; do
    file=$root/$rel
    if [[ -f $file ]]; then
      sha256sum "$file" | awk -v rel="$rel" '{print $1, rel}' >>"$list"
    fi
  done
  if [[ ! -s $list ]]; then
    found=$(find "$root" -type f -printf '%P\n' | head -n 1)
    if [[ -n $found && -f $root/$found ]]; then
      sha256sum "$root/$found" | awk -v rel="$found" '{print $1, rel}' >>"$list"
    else
      printf '%s\n' empty >"$XEDC_ROLLBACK_DIR/site-empty"
    fi
  fi
  if [[ ${XEDC_ROLLBACK_CAPTURE_NGINX:-0} == 1 && -f /etc/nginx/conf.d/xedc-drupal.conf ]]; then
    cp -a /etc/nginx/conf.d/xedc-drupal.conf "$XEDC_ROLLBACK_DIR/nginx/site.conf"
    sha256sum /etc/nginx/conf.d/xedc-drupal.conf | awk '{print $1}' >"$XEDC_ROLLBACK_DIR/nginx/site.sha256"
  fi
}

xedc_rollback_capture_database() {
  local database=$1
  [[ -d ${XEDC_ROLLBACK_DIR:-} ]] || return 0
  [[ $database =~ ^[A-Za-z0-9_]+$ ]] || return 0
  local exists dump_bin
  exists=$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${database}'" 2>/dev/null || printf '%s' 0)
  [[ $exists == 1 ]] || return 0
  dump_bin=mysqldump
  if command -v mariadb-dump >/dev/null 2>&1; then
    dump_bin=mariadb-dump
  fi
  "$dump_bin" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$database" \
    | zstd -T2 -q -f -o "$XEDC_ROLLBACK_DIR/database/before.sql.zst"
  local dump_status=${PIPESTATUS[0]} zstd_status=${PIPESTATUS[1]}
  if [[ $dump_status -ne 0 || $zstd_status -ne 0 || ! -s $XEDC_ROLLBACK_DIR/database/before.sql.zst ]]; then
    xedc_die "覆盖数据库前的回滚快照失败。"
  fi
  jq -n --arg database "$database" '{database:$database}' >"$XEDC_ROLLBACK_DIR/database/meta.json"
  printf '%s\n' '[client]' 'user=root' 'socket=/run/mysqld/mysqld.sock' >"$XEDC_ROLLBACK_DIR/database/client.cnf"
  chmod 600 "$XEDC_ROLLBACK_DIR/database/client.cnf" "$XEDC_ROLLBACK_DIR/database/before.sql.zst"
  mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${database}'" \
    >"$XEDC_ROLLBACK_DIR/database/table-count"
}

xedc_rollback_capture_php_fpm() {
  local ver=${1:-}
  [[ -d ${XEDC_ROLLBACK_DIR:-} ]] || return 0
  if [[ -z $ver ]]; then
    ver=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
  fi
  local pool="/etc/php/${ver}/fpm/pool.d/www.conf"
  [[ -n $ver && -f $pool ]] || return 0
  mkdir -p "$XEDC_ROLLBACK_DIR/php"
  cp -a "$pool" "$XEDC_ROLLBACK_DIR/php/www.conf"
  printf '%s\n' "$ver" >"$XEDC_ROLLBACK_DIR/php/version"
  sha256sum "$pool" | awk '{print $1}' >"$XEDC_ROLLBACK_DIR/php/www.sha256"
}

xedc_rollback_verify() {
  local dir=$1 root=$2
  local hash rel now checked=0
  if [[ -s $dir/expected.sha256 ]]; then
    while read -r hash rel; do
      [[ -n $hash && -n $rel && -f $root/$rel ]] || return 1
      now=$(sha256sum "$root/$rel" | awk '{print $1}')
      [[ $now == "$hash" ]] || return 1
      checked=1
    done <"$dir/expected.sha256"
  elif [[ -f $dir/site-empty ]]; then
    [[ -d $root ]] || return 1
    checked=1
  fi
  if [[ -f $dir/nginx/site.sha256 ]]; then
    [[ -f /etc/nginx/conf.d/xedc-drupal.conf ]] || return 1
    now=$(sha256sum /etc/nginx/conf.d/xedc-drupal.conf | awk '{print $1}')
    [[ $now == $(cat "$dir/nginx/site.sha256") ]] || return 1
    checked=1
  fi
  if [[ -f $dir/database/before.sql.zst ]]; then
    [[ -s $dir/database/before.sql.zst && -f $dir/database/table-count && -f $dir/database/meta.json ]] || return 1
    local database now_count
    database=$(jq -r '.database' "$dir/database/meta.json")
    now_count=$(mariadb --defaults-extra-file="$dir/database/client.cnf" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${database}'" 2>/dev/null || printf '%s' missing)
    [[ $now_count == $(cat "$dir/database/table-count") ]] || return 1
    checked=1
  fi
  if [[ -f $dir/php/www.sha256 && -f $dir/php/version ]]; then
    local pool
    pool="/etc/php/$(cat "$dir/php/version")/fpm/pool.d/www.conf"
    [[ -f $pool ]] || return 1
    now=$(sha256sum "$pool" | awk '{print $1}')
    [[ $now == $(cat "$dir/php/www.sha256") ]] || return 1
    checked=1
  fi
  [[ $checked == 1 ]]
}

xedc_rollback_apply() {
  local dir=$1 root=$2
  [[ -d $dir ]] || xedc_die "找不到回滚目录。"
  if [[ -d $dir/site-before ]]; then
    if [[ -e $root ]]; then
      mv "$root" "$dir/site-failed"
    fi
    mkdir -p "$(dirname "$root")"
    mv "$dir/site-before" "$root"
  fi
  if [[ -f $dir/database/before.sql.zst && -f $dir/database/client.cnf && -f $dir/database/meta.json ]]; then
    local database
    database=$(jq -r '.database' "$dir/database/meta.json")
    zstdcat "$dir/database/before.sql.zst" | mariadb --defaults-extra-file="$dir/database/client.cnf" "$database"
  fi
  if [[ -f $dir/nginx/site.conf ]]; then
    cp -a "$dir/nginx/site.conf" /etc/nginx/conf.d/xedc-drupal.conf
    nginx -t
    xedc_ensure_nginx
  fi
  if [[ -f $dir/php/www.conf && -f $dir/php/version ]]; then
    local ver pool
    ver=$(cat "$dir/php/version")
    pool="/etc/php/${ver}/fpm/pool.d/www.conf"
    mkdir -p "$(dirname "$pool")"
    cp -a "$dir/php/www.conf" "$pool"
    if [[ -f /run/php/php${ver}-fpm.pid ]]; then
      kill -USR2 "$(cat "/run/php/php${ver}-fpm.pid")" 2>/dev/null || xedc_ensure_php_fpm "$ver" || true
    else
      xedc_ensure_php_fpm "$ver" || true
    fi
  fi
  if [[ -f $dir/settings/settings.local.php ]]; then
    mkdir -p "$root/web/sites/default"
    cp -a "$dir/settings/settings.local.php" "$root/web/sites/default/settings.local.php"
  fi
  if [[ -d $dir/services && -n $(find "$dir/services" -mindepth 1 -print -quit) ]]; then
    cp -a "$dir/services/." /etc/systemd/system/ || true
    systemctl daemon-reload || true
  fi
  if xedc_rollback_verify "$dir" "$root"; then
    printf '%s\n' PASS >"$dir/ROLLBACK_STATUS"
    xedc_log INFO "回滚后的文件校验通过。"
    return 0
  fi
  printf '%s\n' FAIL >"$dir/ROLLBACK_STATUS"
  xedc_warn "回滚未通过校验，不能视为成功。"
  return 1
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
