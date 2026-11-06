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

php_version_id_major() {
  # shellcheck disable=SC1091
  . /etc/os-release
  printf '%s\n' "${VERSION_ID%%.*}"
}

php_minor_of() {
  local full=$1
  if [[ "$full" =~ ^([0-9]+)\.([0-9]+) ]]; then
    printf '%s.%s\n' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"
    return 0
  fi
  return 1
}

php_target_version() {
  local got="" manifest=${XEDC_MANIFEST:-}
  if [[ -n "${XEDC_PHP_VERSION:-}" ]]; then
    printf '%s\n' "$XEDC_PHP_VERSION"
    return 0
  fi
  if [[ -n "$manifest" && -f "$manifest" ]] && xedc_have php; then
    got=$(xedc_manifest_get "$manifest" php.minor || true)
    if [[ ! "$got" =~ ^[0-9]+\.[0-9]+$ ]]; then
      got=$(xedc_manifest_get "$manifest" php.version || true)
      got=$(php_minor_of "$got" || true)
    fi
  fi
  if [[ "$got" =~ ^[0-9]+\.[0-9]+$ ]]; then
    printf '%s\n' "$got"
    return 0
  fi
  printf '%s\n' "8.5"
}

php_candidate() {
  local ver=$1 line
  line=$(apt-cache policy "php${ver}-fpm" 2>/dev/null | awk '/Candidate:/ {print $2; exit}' || true)
  [[ "$line" == "${ver}."* ]]
}

php_add_ubuntu_ppa() {
  xedc_apt_install ca-certificates curl gnupg apt-transport-https software-properties-common
  if ! add-apt-repository -y ppa:ondrej/php || ! apt-get update -y; then
    add-apt-repository -y -r ppa:ondrej/php >/dev/null 2>&1 || true
    php_drop_extra_repo
    return 1
  fi
  rm -f /var/lib/xedc-recovery/apt-updated
  return 0
}

php_add_sury() {
  local codename list
  codename=$(php_codename)
  xedc_apt_install ca-certificates curl gnupg apt-transport-https
  list=/etc/apt/sources.list.d/sury-php.list
  curl -fsSL --max-time 30 -o /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
  echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${codename} main" >"$list"
  rm -f /var/lib/xedc-recovery/apt-updated
  if ! apt-get update -y; then
    php_drop_extra_repo
    return 1
  fi
  touch /var/lib/xedc-recovery/apt-updated
  return 0
}

php_require_source() {
  local ver=$1 id major
  id=$(php_os_id)
  major=$(php_version_id_major)
  if [[ "$id" == "ubuntu" && "$major" -ge 26 ]]; then
    rm -f /var/lib/xedc-recovery/apt-updated
    xedc_apt_update || true
    if php_candidate "$ver"; then
      xedc_log "系统源已有 PHP ${ver}"
      return 0
    fi
    xedc_log "系统源没有 PHP ${ver}，尝试 Ondřej 源"
  fi
  if [[ "$id" == "ubuntu" ]]; then
    php_add_ubuntu_ppa || true
  elif [[ "$id" == "debian" ]]; then
    php_add_sury || true
  else
    xedc_die "这个系统不能安装 PHP ${ver}"
  fi
  rm -f /var/lib/xedc-recovery/apt-updated
  xedc_apt_update || true
  if ! php_candidate "$ver"; then
    apt-cache policy "php${ver}-fpm" >&2 || true
    xedc_die "软件源里没有 PHP ${ver}。apt-cache policy php${ver}-fpm 没有 ${ver}.x。已停止，不会改用更低版本"
  fi
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

php_install() {
  local ver current="" pkg
  ver=$(php_target_version)
  if [[ ! "$ver" =~ ^[0-9]+\.[0-9]+$ ]]; then
    xedc_die "PHP 版本 ${ver} 无法识别。使用 --php 8.5 这种形式"
  fi
  php_require_source "$ver"
  if xedc_have php; then
    current=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
  fi
  if [[ -n "$current" && "$current" != "$ver" ]]; then
    xedc_log "当前 php 命令是 ${current}，将改为 ${ver}"
  fi
  local -a required=(cli fpm mysql gd intl mbstring xml curl zip bcmath readline redis)
  for pkg in "${required[@]}"; do
    if ! php_pkg_exists "php${ver}-${pkg}"; then
      xedc_die "没有软件包 php${ver}-${pkg}。请换一个提供 PHP ${ver} 的软件源后再恢复"
    fi
  done
  xedc_apt_install "${required[@]/#/php${ver}-}"
  if php_pkg_exists "php${ver}-opcache"; then
    xedc_apt_install "php${ver}-opcache" || xedc_warn "php${ver}-opcache 未装上。Opcache 通常已经在 php${ver}-common 里"
  fi
  if php_pkg_exists "php${ver}-apcu"; then
    xedc_apt_install "php${ver}-apcu" || xedc_warn "php${ver}-apcu 未装上。没有 APCu 时缓存只用 Redis"
  else
    xedc_warn "没有 php${ver}-apcu。没有 APCu 时缓存只用 Redis"
  fi
  if php_pkg_exists "php${ver}-igbinary"; then
    xedc_apt_install "php${ver}-igbinary" || xedc_warn "php${ver}-igbinary 未装上。Redis 序列化会用 PHP 默认方式"
  else
    xedc_warn "没有 php${ver}-igbinary。Redis 序列化会用 PHP 默认方式"
  fi
  if php_pkg_exists "php${ver}-imagick"; then
    xedc_apt_install "php${ver}-imagick" || xedc_warn "php${ver}-imagick 未装上。图片处理改用 GD"
  else
    xedc_warn "没有 php${ver}-imagick。图片处理改用 GD，需要时再安装这个包"
  fi
  if [[ ! -x "/usr/bin/php${ver}" ]]; then
    xedc_die "安装后没有 /usr/bin/php${ver}"
  fi
  update-alternatives --install /usr/bin/php php "/usr/bin/php${ver}" 85 >/dev/null 2>&1 || true
  update-alternatives --set php "/usr/bin/php${ver}" >/dev/null 2>&1 || ln -sfn "/usr/bin/php${ver}" /usr/bin/php
  local init name unit
  if xedc_systemd; then
    while IFS= read -r unit; do
      [[ -z "$unit" ]] && continue
      name=${unit%.service}
      [[ "$name" == "php${ver}-fpm" ]] && continue
      systemctl disable --now "$name" >/dev/null 2>&1 || true
      xedc_log "已停用旧的 ${name}"
    done < <(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}')
  else
    shopt -s nullglob
    for init in /etc/init.d/php*-fpm; do
      name=$(basename "$init")
      if [[ "$name" != "php${ver}-fpm" ]]; then
        service "$name" stop >/dev/null 2>&1 || true
        xedc_log "已停用旧的 ${name}"
      fi
    done
    shopt -u nullglob
  fi
  local got_ver mods
  got_ver=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
  if [[ "$got_ver" != "$ver" ]]; then
    xedc_die "php 命令不是 ${ver}：$(php -v | awk 'NR==1{print}')"
  fi
  mods=$(php -m || true)
  if ! grep -F 'Zend OPcache' <<<"$mods" >/dev/null; then
    xedc_die "PHP ${ver} 没有启用 OPcache。它应随 php${ver}-common 提供；若没有，安装 php${ver}-opcache"
  fi
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\n' "$ver" >"$XEDC_STATE_DIR/php-version"
  printf '%s\n' "/run/php/php${ver}-fpm.sock" >"$XEDC_STATE_DIR/php-sock"
  xedc_log "PHP $(php -v | awk 'NR==1{print}')"
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
  xedc_apply_perms "/etc/php/${ver}/fpm/conf.d/zz-xedc.ini"
  xedc_apply_perms "/etc/php/${ver}/cli/conf.d/zz-xedc.ini"
  xedc_apply_perms "/etc/php/${ver}/fpm/pool.d/www.conf"
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
  if [[ -f "/etc/php/${ver}/fpm/pool.d/www.conf" ]]; then
    xedc_assert_readable www-data "/etc/php/${ver}/fpm/pool.d/www.conf"
  fi
  if [[ -f "/etc/php/${ver}/fpm/conf.d/zz-xedc.ini" ]]; then
    xedc_assert_readable www-data "/etc/php/${ver}/fpm/conf.d/zz-xedc.ini"
  fi
  xedc_svc "php${ver}-fpm"
}

php_align_paths() {
  local ver file tmp
  ver=$(php_version_file)
  [[ "$ver" =~ ^[0-9]+\.[0-9]+$ ]] || return 0
  shopt -s nullglob
  for file in /etc/nginx/conf.d/*.conf /etc/nginx/sites-enabled/* /etc/cron.d/* /etc/systemd/system/xedc-*; do
    [[ -f "$file" ]] || continue
    sed -i -E "s/php8\\.[0-9]+/php${ver}/g" "$file"
  done
  shopt -u nullglob
  tmp=$(mktemp)
  if crontab -l -u root >"$tmp" 2>/dev/null; then
    sed -i -E "s/php8\\.[0-9]+/php${ver}/g" "$tmp"
    crontab -u root "$tmp" || true
  fi
  : >"$tmp"
  if crontab -l -u www-data >"$tmp" 2>/dev/null; then
    sed -i -E "s/php8\\.[0-9]+/php${ver}/g" "$tmp"
    crontab -u www-data "$tmp" || true
  fi
  rm -f "$tmp"
}

php_verify() {
  local ver got ext mods sock
  ver=$(php_version_file)
  got=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
  [[ "$got" == "$ver" ]] || xedc_die "php 命令是 ${got}，需要 ${ver}"
  mods=$(php -m | tr '[:upper:]' '[:lower:]')
  for ext in gd intl mbstring xml curl zip pdo_mysql redis; do
    case $'\n'"$mods"$'\n' in
      *$'\n'"${ext}"$'\n'*) ;;
      *) xedc_die "PHP 扩展 ${ext} 未启用" ;;
    esac
  done
  sock=$(cat "$XEDC_STATE_DIR/php-sock")
  [[ -S "$sock" ]] || xedc_die "PHP-FPM 套接字不存在: ${sock}"
  [[ "$sock" == "/run/php/php${ver}-fpm.sock" ]] || xedc_die "PHP-FPM 套接字不是 ${ver}: ${sock}"
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
