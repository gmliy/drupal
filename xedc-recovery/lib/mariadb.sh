#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

mariadb_install() {
  if xedc_have mariadbd || xedc_have mysqld; then
    xedc_log "MariaDB 已安装，检查版本"
  fi
  xedc_apt_install mariadb-server mariadb-client
  local got want
  got=$(mariadbd --version 2>/dev/null || mysqld --version 2>/dev/null || true)
  want=""
  if [[ -n "${XEDC_MANIFEST:-}" && -f ${XEDC_MANIFEST} ]]; then
    want=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); foreach(($j["packages"]??[]) as $p){ if(($p["name"]??"")==="mariadb-server"){ echo $p["version"]; break; } }' "$XEDC_MANIFEST" || true)
  fi
  if [[ -n "$want" && "$got" != *"${want%%+*}"* && "$got" != *"$want"* ]]; then
    xedc_warn "MariaDB 当前为 ${got}，备份时为 ${want}。已保留可用版本"
  fi
  xedc_log "MariaDB ${got}"
}

mariadb_configure() {
  xedc_load_tune
  mkdir -p /etc/mysql/mariadb.conf.d
  php "$XEDC_ROOT/lib/php/render.php" "$XEDC_ROOT/templates/99-xedc.cnf.tpl" /etc/mysql/mariadb.conf.d/99-xedc.cnf <<JSON
{"innodb_buffer_pool":"${INNODB_BUFFER}","max_connections":"${MAX_CONNECTIONS}","performance_schema":"${PERFORMANCE_SCHEMA}"}
JSON
}

mariadb_prepare_run() {
  mkdir -p /run/mysqld
  if id mysql >/dev/null 2>&1; then
    chown mysql:mysql /run/mysqld || true
  fi
}

mariadb_enable() {
  mariadb_prepare_run
  if xedc_systemd; then
    xedc_svc mariadb || xedc_svc mysql || xedc_die "MariaDB 服务没有启动"
    return 0
  fi
  service mariadb stop >/dev/null 2>&1 || service mysql stop >/dev/null 2>&1 || true
  mariadb_prepare_run
  if service mariadb start || service mysql start; then
    return 0
  fi
  xedc_warn "MariaDB 错误日志："
  tail -n 40 /var/log/mysql/error.log >&2 || true
  xedc_die "MariaDB 没有启动"
}

mariadb_root() {
  if mysql --protocol=socket -u root -e 'SELECT 1' >/dev/null 2>&1; then
    echo mysql
    return 0
  fi
  if mariadb --protocol=socket -u root -e 'SELECT 1' >/dev/null 2>&1; then
    echo mariadb
    return 0
  fi
  return 1
}

mariadb_verify() {
  local bin
  bin=$(mariadb_root)
  "$bin" --protocol=socket -u root -e 'SELECT 1' >/dev/null
  xedc_log "MariaDB 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) mariadb_install ;;
    configure) mariadb_configure ;;
    enable) mariadb_enable ;;
    verify) mariadb_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
