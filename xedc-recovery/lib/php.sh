#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

php_codename() {
  # shellcheck disable=SC1091
  . /etc/os-release
  printf '%s\n' "${VERSION_CODENAME:-}"
}

php_os_id() {
  # shellcheck disable=SC1091
  . /etc/os-release
  printf '%s\n' "$ID"
}

php_add_repo() {
  local id codename list
  id=$(php_os_id)
  codename=$(php_codename)
  xedc_apt_install ca-certificates curl gnupg apt-transport-https
  if [[ "$id" == "ubuntu" ]]; then
    xedc_apt_install software-properties-common || true
    if ! add-apt-repository -y ppa:ondrej/php || ! apt-get update -y; then
      add-apt-repository -y -r ppa:ondrej/php >/dev/null 2>&1 || true
      php_drop_extra_repo
      xedc_warn "Ondřej PHP 源不可用"
      return 1
    fi
    rm -f /var/lib/xedc-recovery/apt-updated
    return 0
  fi
  list=/etc/apt/sources.list.d/sury-php.list
  curl -fsSL --max-time 30 -o /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
  echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${codename} main" >"$list"
  rm -f /var/lib/xedc-recovery/apt-updated
  if ! apt-get update -y; then
    php_drop_extra_repo
    xedc_apt_update || true
    xedc_warn "Sury PHP 源不可用"
    return 1
  fi
  touch /var/lib/xedc-recovery/apt-updated
  return 0
}

php_drop_extra_repo() {
  local f
  shopt -s nullglob
  for f in /etc/apt/sources.list.d/*ondrej* /etc/apt/sources.list.d/*sury* /etc/apt/sources.list.d/php.list; do
    rm -f "$f"
  done
  shopt -u nullglob
  rm -f /var/lib/xedc-recovery/apt-updated
}

php_pkg_exists() {
  local name=$1 out
  out=$(apt-cache show "$name" 2>/dev/null || true)
  [[ "$out" == *"Package: ${name}"* ]]
}

php_pick_version() {
  local v
  for v in 8.5 8.4 8.3 8.2; do
    if php_pkg_exists "php${v}-fpm"; then
      printf '%s\n' "$v"
      return 0
    fi
  done
  return 1
}

php_install() {
  local current=""
  if xedc_have php; then
    current=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
  fi
  if ! php_add_repo; then
    xedc_warn "将使用系统源里的最高 PHP 版本"
    rm -f /var/lib/xedc-recovery/apt-updated
    xedc_apt_update
  fi
  local ver=""
  ver=$(php_pick_version || true)
  if [[ -z "$ver" ]]; then
    xedc_die "软件源里没有 PHP 8.2 及以上，无法恢复"
  fi
  if [[ "$ver" != "8.5" ]]; then
    xedc_warn "未能安装 PHP 8.5，已改用 PHP ${ver}"
  fi
  if [[ -n "$current" && "$current" != "$ver" ]]; then
    xedc_warn "PHP ${current} 与目标 ${ver} 不符，开始安装 ${ver}"
  elif [[ "$current" == "$ver" ]]; then
    xedc_log "PHP ${ver} 已安装，补齐扩展"
  fi
  if ! xedc_apt_install \
    "php${ver}-cli" "php${ver}-fpm" "php${ver}-mysql" "php${ver}-gd" \
    "php${ver}-intl" "php${ver}-mbstring" "php${ver}-xml" "php${ver}-curl" \
    "php${ver}-zip" "php${ver}-bcmath" "php${ver}-readline" \
    "php${ver}-redis"; then
    xedc_die "安装 PHP ${ver} 失败。Opcache 从 8.5 起随 php${ver}-common 提供，不再单独装 php${ver}-opcache"
  fi
  if php_pkg_exists "php${ver}-opcache"; then
    xedc_apt_install "php${ver}-opcache" || xedc_warn "php${ver}-opcache 未装上，继续使用内置 Opcache"
  fi
  local opt
  for opt in apcu igbinary imagick; do
    if ! xedc_apt_install "php${ver}-${opt}"; then
      xedc_warn "可选扩展 php${ver}-${opt} 未安装"
    fi
  done
  if [[ -x "/usr/bin/php${ver}" ]]; then
    update-alternatives --set php "/usr/bin/php${ver}" >/dev/null 2>&1 || ln -sfn "/usr/bin/php${ver}" /usr/bin/php
  fi
  if ! xedc_have php; then
    xedc_die "PHP ${ver} 包装上了，但是没有 php 命令"
  fi
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\n' "$ver" >"$XEDC_STATE_DIR/php-version"
  xedc_log "PHP $(php -v | head -n 1)"
}

php_version_file() {
  if [[ -f "$XEDC_STATE_DIR/php-version" ]]; then
    cat "$XEDC_STATE_DIR/php-version"
  elif xedc_have php; then
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'
  else
    echo 8.5
  fi
}

php_configure() {
  local ver
  ver=$(php_version_file)
  xedc_load_tune
  local manifest=${XEDC_MANIFEST:-}
  local upload=${UPLOAD_MAX:-200M} post=${POST_MAX:-220M} exec_time=${MAX_EXEC:-300}
  local input_time=${MAX_INPUT:-300} input_vars=${MAX_INPUT_VARS:-10000} uploads=${MAX_FILE_UPLOADS:-100}
  local tz=${PHP_TZ:-UTC}
  if [[ -n "$manifest" && -f "$manifest" ]]; then
    local got
    got=$(xedc_manifest_get "$manifest" php.ini.upload_max_filesize || true)
    [[ -n "$got" && "$got" != "-1" ]] && upload=$got
    got=$(xedc_manifest_get "$manifest" php.ini.post_max_size || true)
    [[ -n "$got" && "$got" != "-1" ]] && post=$got
    got=$(xedc_manifest_get "$manifest" php.ini.max_execution_time || true)
    [[ -n "$got" && "$got" != "0" ]] && exec_time=$got
    got=$(xedc_manifest_get "$manifest" php.ini.max_input_time || true)
    [[ -n "$got" && "$got" != "-1" ]] && input_time=$got
    got=$(xedc_manifest_get "$manifest" php.ini.max_input_vars || true)
    [[ -n "$got" ]] && input_vars=$got
    got=$(xedc_manifest_get "$manifest" php.ini.max_file_uploads || true)
    [[ -n "$got" ]] && uploads=$got
    got=$(xedc_manifest_get "$manifest" php.ini.date.timezone || true)
    [[ -n "$got" ]] && tz=$got
  fi
  local sock="/run/php/php${ver}-fpm.sock"
  printf '%s\n' "$sock" >"$XEDC_STATE_DIR/php-sock"
  php "$XEDC_ROOT/lib/php/render.php" "$XEDC_ROOT/templates/php-xedc.ini.tpl" "/etc/php/${ver}/fpm/conf.d/zz-xedc.ini" <<JSON
{"memory_limit":"${MEMORY_LIMIT}","upload_max_filesize":"${upload}","post_max_size":"${post}","max_execution_time":"${exec_time}","max_input_time":"${input_time}","max_input_vars":"${input_vars}","max_file_uploads":"${uploads}","timezone":"${tz}","opcache_memory":"${OPCACHE_MEMORY}"}
JSON
  php "$XEDC_ROOT/lib/php/render.php" "$XEDC_ROOT/templates/php-xedc.ini.tpl" "/etc/php/${ver}/cli/conf.d/zz-xedc.ini" <<JSON
{"memory_limit":"${MEMORY_LIMIT}","upload_max_filesize":"${upload}","post_max_size":"${post}","max_execution_time":"${exec_time}","max_input_time":"${input_time}","max_input_vars":"${input_vars}","max_file_uploads":"${uploads}","timezone":"${tz}","opcache_memory":"${OPCACHE_MEMORY}"}
JSON
  php "$XEDC_ROOT/lib/php/render.php" "$XEDC_ROOT/templates/php-fpm-pool.conf.tpl" "/etc/php/${ver}/fpm/pool.d/www.conf" <<JSON
{"php_sock":"${sock}","pm_max_children":"${PM_MAX_CHILDREN}","pm_start":"${PM_START}","pm_spare":"${PM_SPARE}","memory_limit":"${MEMORY_LIMIT}"}
JSON
  local mysql_sock=""
  mysql_sock=$(php_mysql_socket || true)
  if [[ -n "$mysql_sock" ]]; then
    printf 'pdo_mysql.default_socket = %s\nmysqli.default_socket = %s\n' "$mysql_sock" "$mysql_sock" >>"/etc/php/${ver}/fpm/conf.d/zz-xedc.ini"
    printf 'pdo_mysql.default_socket = %s\nmysqli.default_socket = %s\n' "$mysql_sock" "$mysql_sock" >>"/etc/php/${ver}/cli/conf.d/zz-xedc.ini"
  fi
}

php_mysql_socket() {
  local sock=""
  if xedc_have mariadb; then
    sock=$(mariadb --protocol=socket -u root -N -e "SHOW VARIABLES LIKE 'socket'" 2>/dev/null | awk '{print $2}' || true)
  elif xedc_have mysql; then
    sock=$(mysql --protocol=socket -u root -N -e "SHOW VARIABLES LIKE 'socket'" 2>/dev/null | awk '{print $2}' || true)
  fi
  if [[ -n "$sock" && -S "$sock" ]]; then
    printf '%s\n' "$sock"
    return 0
  fi
  local candidate
  for candidate in /run/mysqld/mysqld.sock /var/run/mysqld/mysqld.sock /run/mysqld/mariadb.sock /tmp/mysql.sock; do
    if [[ -S "$candidate" ]]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  return 1
}

php_enable() {
  local ver
  ver=$(php_version_file)
  mkdir -p /run/php
  xedc_svc "php${ver}-fpm"
}

php_verify() {
  local ver
  ver=$(php_version_file)
  php -v >/dev/null
  local ext
  for ext in gd intl mbstring xml curl zip pdo_mysql redis; do
    php -m | grep -qi "^${ext}$" || xedc_die "PHP 扩展 ${ext} 未启用"
  done
  local sock
  sock=$(cat "$XEDC_STATE_DIR/php-sock")
  [[ -S "$sock" ]] || xedc_die "PHP-FPM 套接字不存在: ${sock}"
  xedc_log "PHP-FPM 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) php_install ;;
    configure) php_configure ;;
    enable) php_enable ;;
    verify) php_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
