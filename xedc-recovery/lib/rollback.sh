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

xedc_rollback_verify() {
  local dir=$1
  [[ -d $dir ]] || return 1
  [[ -f $dir/nginx/site.conf || -f $dir/database/before.sql.zst || -f $dir/settings/settings.local.php ]]
}

xedc_rollback_apply() {
  local dir=$1 root=$2
  [[ -d $dir ]] || xedc_die "找不到回滚目录。"
  if [[ -f $dir/database/before.sql.zst && -f $dir/database/client.cnf ]]; then
    local database
    database=$(jq -r '.database' "$dir/database/meta.json")
    zstdcat "$dir/database/before.sql.zst" | mariadb --defaults-extra-file="$dir/database/client.cnf" "$database"
  fi
  if [[ -f $dir/nginx/site.conf ]]; then
    cp -a "$dir/nginx/site.conf" /etc/nginx/conf.d/xedc-drupal.conf
    nginx -t
    xedc_ensure_nginx
  fi
  if [[ -f $dir/settings/settings.local.php ]]; then
    cp -a "$dir/settings/settings.local.php" "$root/web/sites/default/settings.local.php"
  fi
  if [[ -d $dir/services ]]; then
    cp -a "$dir/services/." /etc/systemd/system/ 2>/dev/null || true
    systemctl daemon-reload || true
  fi
  printf '%s\n' PASS >"$dir/ROLLBACK_STATUS"
  xedc_log INFO "回滚文件已写回。请再执行验收确认原配置可用。"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
