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

xedc_add_php_repo() {
  xedc_os_info
  xedc_export_apt_env
  local release=""
  if [[ $XEDC_OS_ID == ubuntu ]]; then
    release="https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${XEDC_OS_CODENAME}/Release"
  else
    release="https://packages.sury.org/php/dists/${XEDC_OS_CODENAME}/Release"
  fi
  if ! xedc_url_ok "$release"; then
    xedc_warn "PHP 第三方源没有 ${XEDC_OS_ID} ${XEDC_OS_CODENAME} 的 Release 文件，改用系统仓库。"
    return 1
  fi
  if [[ $XEDC_OS_ID == ubuntu ]]; then
    xedc_run apt-get install -y ca-certificates software-properties-common
    xedc_run add-apt-repository -y ppa:ondrej/php
  else
    xedc_run apt-get install -y ca-certificates curl gnupg
    xedc_run curl -fsSL -o /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
    printf 'deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ %s main\n' "$XEDC_OS_CODENAME" \
      > /etc/apt/sources.list.d/sury-php.list
  fi
  xedc_run apt-get update
}

xedc_filter_php_ini() {
  local src=$1 dest=$2
  grep -Ev '^[[:space:]]*(track_errors|allow_call_time_pass_reference|magic_quotes_gpc|register_globals|safe_mode|always_populate_raw_post_data|zend.ze1_compatibility_mode)[[:space:]]*=' "$src" >"$dest" || true
}

xedc_install_php() {
  local want=${1:-8.5}
  local current actual="$want"
  current=$(php -r 'echo PHP_VERSION;' 2>/dev/null || printf '%s' none)
  xedc_export_apt_env
  xedc_add_php_repo || true
  xedc_run apt-get update
  if ! xedc_apt_has "php${want}-fpm"; then
    if [[ $want == 8.5 && $(xedc_apt_has php8.4-fpm; echo $?) == 0 ]]; then
      xedc_warn "目标 PHP 8.5 没有可用软件包。下面是 composer 对 8.5 的阻塞信息，随后改用 8.4。"
      if [[ -f $XEDC_SITE_ROOT/composer.json ]]; then
        (cd "$XEDC_SITE_ROOT" && composer why-not php 8.5) || true
      fi
      actual=8.4
    else
      xedc_die "系统仓库和第三方源都没有 PHP ${want}。不会静默安装一个未记录的版本。"
    fi
  fi
  local pkg packages=(
    "php${actual}-fpm" "php${actual}-cli" "php${actual}-mysql" "php${actual}-xml"
    "php${actual}-gd" "php${actual}-mbstring" "php${actual}-curl" "php${actual}-zip"
    "php${actual}-intl" "php${actual}-bcmath" "php${actual}-apcu" "php${actual}-redis"
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
  installed=$(php"${actual}" -r 'echo PHP_VERSION;' 2>/dev/null || printf '%s' missing)
  printf '当前版本 → 目标版本 → 实际安装版本: %s → %s → %s\n' "$current" "$want" "$installed"
  export XEDC_PHP_ACTUAL=$actual
  if [[ $installed == missing ]]; then
    xedc_die "PHP ${actual} 安装后仍不可用。"
  fi
}

xedc_install_base_packages() {
  xedc_export_apt_env
  xedc_run apt-get update
  xedc_run apt-get install -y nginx mariadb-server redis-server composer unzip curl ca-certificates \
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
