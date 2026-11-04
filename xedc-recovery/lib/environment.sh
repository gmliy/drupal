#!/usr/bin/env bash
# shellcheck shell=bash

xedc_export_apt_env() {
  export DEBIAN_FRONTEND=noninteractive
  export NEEDRESTART_MODE=a
  export APT_LISTCHANGES_FRONTEND=none
}

xedc_apt_has() {
  apt-cache show "$1" >/dev/null 2>&1
}

xedc_url_ok() {
  curl -fsI -o /dev/null --max-time 25 "$1"
}

xedc_add_sury_repo() {
  xedc_run apt-get install -y ca-certificates curl gnupg
  xedc_run curl -fsSL -o /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
  printf 'deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ %s main\n' "$XEDC_OS_CODENAME" \
    > /etc/apt/sources.list.d/sury-php.list
}

xedc_add_php_repo() {
  xedc_os_info
  xedc_export_apt_env
  local ondrej="https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${XEDC_OS_CODENAME}/Release"
  local sury="https://packages.sury.org/php/dists/${XEDC_OS_CODENAME}/Release"
  if [[ $XEDC_OS_ID == ubuntu ]] && xedc_url_ok "$ondrej"; then
    xedc_run apt-get install -y ca-certificates software-properties-common
    xedc_run add-apt-repository -y ppa:ondrej/php
  elif xedc_url_ok "$sury"; then
    xedc_log INFO "使用 packages.sury.org 的 PHP 源（${XEDC_OS_ID} ${XEDC_OS_CODENAME}）。"
    xedc_add_sury_repo
  else
    xedc_warn "Ondřej 与 Sury 都没有 ${XEDC_OS_ID} ${XEDC_OS_CODENAME} 的 Release 文件，改用系统仓库。"
    return 1
  fi
  xedc_run apt-get update
}

xedc_filter_php_ini() {
  local src=$1 dest=$2
  grep -Ev '^[[:space:]]*(track_errors|allow_call_time_pass_reference|magic_quotes_gpc|register_globals|safe_mode|always_populate_raw_post_data|zend.ze1_compatibility_mode)[[:space:]]*=' "$src" >"$dest" || true
}

xedc_php85_blocked() {
  local reason=$1
  mkdir -p "${XEDC_STATE_ROOT:-/var/lib/xedc-recovery}"
  printf 'BLOCKED %s\n系统: %s %s (%s)\n' "$reason" "${XEDC_OS_ID:-unknown}" "${XEDC_OS_VERSION:-unknown}" "${XEDC_OS_CODENAME:-unknown}" \
    >"${XEDC_STATE_ROOT:-/var/lib/xedc-recovery}/PHP_STATUS"
  chmod 600 "${XEDC_STATE_ROOT:-/var/lib/xedc-recovery}/PHP_STATUS" 2>/dev/null || true
  xedc_die "BLOCKED ${reason}。系统 ${XEDC_OS_ID:-unknown} ${XEDC_OS_VERSION:-unknown} (${XEDC_OS_CODENAME:-unknown})。不会安装 PHP 8.4 或其他版本，数据库和网站切换已停止。"
}

xedc_assert_php85() {
  local cli fpm ext missing=""
  cli=$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)
  [[ $cli == 8.5.* ]] || xedc_php85_blocked "PHP CLI 不是 8.5（${cli:-missing}）"
  fpm=$(php-fpm8.5 -v 2>/dev/null | awk 'NR==1 {print $2}')
  [[ $fpm == 8.5.* ]] || xedc_php85_blocked "php-fpm8.5 不是 8.5（${fpm:-missing}）"
  for ext in curl gd intl mbstring xml zip bcmath pdo_mysql apcu redis; do
    if ! php -m | grep -qi "^${ext}$"; then
      missing="${missing} ${ext}"
    fi
  done
  [[ -z $missing ]] || xedc_php85_blocked "PHP 8.5 缺少扩展:${missing}"
  if [[ ! -S /run/php/php8.5-fpm.sock ]]; then
    xedc_ensure_php_fpm 8.5 || xedc_php85_blocked "PHP 8.5 FPM 套接字不存在"
  fi
  [[ -S /run/php/php8.5-fpm.sock ]] || xedc_php85_blocked "Nginx 将要使用的 PHP 8.5 套接字不存在"
  printf 'PASS PHP CLI %s，PHP-FPM %s，套接字 /run/php/php8.5-fpm.sock\n' "$cli" "$fpm" \
    >"${XEDC_STATE_ROOT:-/var/lib/xedc-recovery}/PHP_STATUS"
  chmod 600 "${XEDC_STATE_ROOT:-/var/lib/xedc-recovery}/PHP_STATUS" 2>/dev/null || true
}

xedc_install_php() {
  local want=${1:-8.5}
  local current actual="8.5"
  xedc_os_info
  if [[ $want != 8.5 ]]; then
    xedc_php85_blocked "Drupal 恢复拒绝 PHP ${want}"
  fi
  current=$(php -r 'echo PHP_VERSION;' 2>/dev/null || printf '%s' none)
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    export XEDC_PHP_ACTUAL=8.5
    printf '当前版本 → 目标版本 → 实际安装版本: %s → 8.5 → dry-run\n' "$current"
    return 0
  fi
  xedc_export_apt_env
  xedc_add_php_repo || xedc_log INFO "PHP 第三方源不可用，继续检查系统仓库是否已有 PHP 8.5。"
  xedc_run apt-get update
  if ! xedc_apt_has php8.5-fpm || ! xedc_apt_has php8.5-cli; then
    xedc_php85_blocked "没有可用的 PHP 8.5 软件包"
  fi
  local pkg packages=(
    php8.5-fpm php8.5-cli php8.5-mysql php8.5-xml
    php8.5-gd php8.5-mbstring php8.5-curl php8.5-zip
    php8.5-intl php8.5-bcmath php8.5-apcu php8.5-redis
  )
  for pkg in "${packages[@]}"; do
    if xedc_apt_has "$pkg"; then
      xedc_run apt-get install -y "$pkg"
    else
      xedc_warn "软件包不存在，已跳过：${pkg}"
    fi
  done
  if xedc_apt_has "php${actual}-opcache"; then
    xedc_log INFO "安装独立 OPcache 包 php${actual}-opcache。"
    xedc_run apt-get install -y "php${actual}-opcache"
  else
    xedc_log INFO "PHP ${actual} 的 OPcache 已内置，不重复安装 php${actual}-opcache。"
  fi
  local installed
  installed=$(php8.5 -r 'echo PHP_VERSION;' 2>/dev/null || printf '%s' missing)
  printf '当前版本 → 目标版本 → 实际安装版本: %s → 8.5 → %s\n' "$current" "$installed"
  export XEDC_PHP_ACTUAL=8.5
  if [[ $installed != 8.5.* ]]; then
    xedc_php85_blocked "PHP 8.5 安装后仍不可用（${installed}）"
  fi
  if [[ -x /usr/bin/php8.5 ]]; then
    update-alternatives --install /usr/bin/php php /usr/bin/php8.5 85 >/dev/null 2>&1 || true
    update-alternatives --set php /usr/bin/php8.5 >/dev/null 2>&1 || true
    ln -sfn /usr/bin/php8.5 /usr/local/bin/php
  fi
  xedc_assert_php85
}

xedc_have_systemd() {
  [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1
}

xedc_ensure_mariadb() {
  if mariadb -N -e 'SELECT 1' >/dev/null 2>&1; then
    return 0
  fi
  install -d -o mysql -g mysql -m 755 /run/mysqld
  if xedc_have_systemd; then
    systemctl start mariadb || systemctl start mysql || true
  elif command -v service >/dev/null 2>&1; then
    service mariadb start || service mysql start || true
  fi
  if ! mariadb -N -e 'SELECT 1' >/dev/null 2>&1; then
    mkdir -p /var/log/xedc-recovery
    mysqld --user=mysql --bind-address=127.0.0.1 --datadir=/var/lib/mysql >>/var/log/xedc-recovery/mysqld.log 2>&1 &
  fi
  local _
  for _ in $(seq 1 40); do
    if mariadb -N -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  xedc_die "MariaDB 没有启动。"
}

xedc_ensure_nginx() {
  if pgrep -x nginx >/dev/null 2>&1; then
    nginx -s reload
    return 0
  fi
  if xedc_have_systemd; then
    systemctl enable --now nginx
    return 0
  fi
  nginx
}

xedc_ensure_php_fpm() {
  local ver=$1
  local sock="/run/php/php${ver}-fpm.sock"
  [[ -S $sock ]] && return 0
  install -d -o www-data -g www-data /run/php
  if xedc_have_systemd; then
    systemctl enable --now "php${ver}-fpm" || true
  elif command -v service >/dev/null 2>&1; then
    service "php${ver}-fpm" start || true
  fi
  if [[ ! -S $sock ]]; then
    if [[ -x /usr/sbin/php-fpm${ver} ]]; then
      "/usr/sbin/php-fpm${ver}" --daemonize || true
    fi
  fi
  local _
  for _ in $(seq 1 20); do
    [[ -S $sock ]] && return 0
    sleep 1
  done
  xedc_warn "PHP-FPM 套接字未出现：${sock}"
  return 1
}

xedc_ensure_redis() {
  if command -v redis-cli >/dev/null 2>&1 && redis-cli ping >/dev/null 2>&1; then
    return 0
  fi
  if xedc_have_systemd; then
    systemctl enable --now redis-server || true
  elif command -v service >/dev/null 2>&1; then
    service redis-server start || true
  fi
  if command -v redis-cli >/dev/null 2>&1 && ! redis-cli ping >/dev/null 2>&1; then
    redis-server --daemonize yes --bind 127.0.0.1 || xedc_warn "Redis 未启动。"
  fi
}

xedc_mariadb_version() {
  local raw ver=""
  raw=$(mariadb --version 2>/dev/null || true)
  if [[ $raw =~ ([0-9]+\.[0-9]+\.[0-9]+)-MariaDB ]]; then
    ver=${BASH_REMATCH[1]}
  elif [[ $raw =~ Distrib[[:space:]]+([0-9]+\.[0-9]+\.[0-9]+) ]]; then
    ver=${BASH_REMATCH[1]}
  fi
  printf '%s\n' "$ver"
}

xedc_install_mariadb() {
  xedc_os_info
  local current=""
  if command -v mariadb >/dev/null 2>&1; then
    current=$(xedc_mariadb_version)
    if [[ $current == 12.* ]]; then
      xedc_log INFO "MariaDB ${current} 已安装。"
      return 0
    fi
    if mariadb -N -e 'SELECT 1' >/dev/null 2>&1; then
      xedc_die "BLOCKED 本机 MariaDB 是 ${current:-未知}，不是 12 系列。不会在已有数据库上原地升级。"
    fi
  fi
  local dist=ubuntu series="" candidate url
  [[ $XEDC_OS_ID == debian ]] && dist=debian
  for candidate in 12.3 12.2 12.1 12.0; do
    url="https://dlm.mariadb.com/repo/mariadb-server/${candidate}/repo/${dist}/dists/${XEDC_OS_CODENAME}/Release"
    if xedc_url_ok "$url"; then
      series=$candidate
      break
    fi
  done
  [[ -n $series ]] || xedc_die "BLOCKED ${XEDC_OS_ID} ${XEDC_OS_CODENAME} 没有可用的 MariaDB 12 软件源。没有安装其他版本。"
  xedc_log INFO "使用 MariaDB ${series} 软件源。"
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    return 0
  fi
  xedc_run apt-get install -y ca-certificates curl gnupg
  install -d -m 755 /etc/apt/keyrings
  curl -fsSL -o /etc/apt/keyrings/mariadb-keyring-2019.gpg https://supplychain.mariadb.com/mariadb-keyring-2019.gpg
  printf 'deb [signed-by=/etc/apt/keyrings/mariadb-keyring-2019.gpg] https://dlm.mariadb.com/repo/mariadb-server/%s/repo/%s %s main\n' \
    "$series" "$dist" "$XEDC_OS_CODENAME" >/etc/apt/sources.list.d/mariadb-12.list
  xedc_run apt-get update
  xedc_run apt-get install -y mariadb-server
  current=$(xedc_mariadb_version)
  [[ $current == 12.* ]] || xedc_die "BLOCKED MariaDB 安装结果是 ${current:-未知}，不是 12 系列。"
  xedc_log INFO "MariaDB ${current} 已安装。"
}

xedc_install_base_packages() {
  xedc_export_apt_env
  xedc_run apt-get update
  xedc_install_mariadb
  xedc_run apt-get install -y nginx redis-server composer unzip curl ca-certificates \
    git zstd jq age openssl ffmpeg python3 tar gnupg rsync
  if ! xedc_have node; then
    local node_release="https://deb.nodesource.com/node_20.x/dists/${XEDC_OS_CODENAME:-nodistro}/Release"
    if xedc_url_ok "$node_release"; then
      curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
      xedc_run apt-get install -y nodejs
    else
      xedc_warn "NodeSource 不支持当前发行版，改用系统仓库的 nodejs。"
      xedc_run apt-get install -y nodejs npm || xedc_warn "nodejs 未能安装。"
    fi
  fi
}

xedc_install_docker() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    return 0
  fi
  xedc_export_apt_env
  xedc_run apt-get update
  if ! xedc_apt_has docker.io; then
    xedc_warn "系统仓库没有 docker.io。Docker 服务恢复记为未完成。"
    return 1
  fi
  xedc_run apt-get install -y docker.io || return 1
  if xedc_apt_has docker-compose-v2; then
    xedc_run apt-get install -y docker-compose-v2 || xedc_warn "docker-compose-v2 安装失败。"
  elif xedc_apt_has docker-compose; then
    xedc_run apt-get install -y docker-compose || xedc_warn "docker-compose 安装失败。"
  fi
  if ! command -v docker >/dev/null 2>&1; then
    return 1
  fi
  if ! docker compose version >/dev/null 2>&1 && ! command -v docker-compose >/dev/null 2>&1; then
    xedc_warn "Docker 引擎已安装，Compose 不可用。"
    return 1
  fi
  return 0
}

xedc_ensure_composer() {
  if command -v composer >/dev/null 2>&1 && composer --version >/dev/null 2>&1; then
    return 0
  fi
  xedc_install_composer_phar
  composer --version >/dev/null 2>&1 || xedc_die "BLOCKED Composer 无法在当前 PHP 上运行。"
}

xedc_install_composer_phar() {
  if xedc_have composer; then
    return 0
  fi
  local installer expected actual
  installer=$(xedc_mktemp)
  curl -fsSL -o "$installer" https://getcomposer.org/installer
  expected=$(curl -fsSL https://composer.github.io/installer.sig)
  actual=$(php -r "echo hash_file('sha256', \$argv[1]);" "$installer")
  if [[ $expected != "$actual" ]]; then
    rm -f "$installer"
    xedc_die "Composer 安装脚本 SHA256 不一致，已拒绝执行。"
  fi
  php "$installer" --install-dir=/usr/local/bin --filename=composer
  rm -f "$installer"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
