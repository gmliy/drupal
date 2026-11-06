#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

nginx_install() {
  if xedc_have nginx; then
    xedc_log "nginx 已安装: $(nginx -v 2>&1)"
  fi
  xedc_apt_install nginx openssl
  xedc_log "nginx $(nginx -v 2>&1)"
}

nginx_render_site() {
  local domain=$1 www=$2 https=$3 web_root=$4 body=$5
  local canonical alias cert key sock mp4 ipv6_80 ipv6_443
  if [[ "$www" == "yes" ]]; then
    canonical="www.${domain}"
    alias=$domain
  else
    canonical=$domain
    alias="www.${domain}"
  fi
  cert="/etc/ssl/xedc/${domain}.crt"
  key="/etc/ssl/xedc/${domain}.key"
  sock=$(cat "$XEDC_STATE_DIR/php-sock" 2>/dev/null || echo /run/php/php8.5-fpm.sock)
  if nginx -V 2>&1 | grep -q with-http_mp4_module; then
    mp4="mp4;"
  else
    mp4=""
  fi
  ipv6_80=""
  ipv6_443=""
  if xedc_ipv6; then
    ipv6_80="listen [::]:80;"
    ipv6_443="listen [::]:443 ssl;"
  fi
  local tpl="$XEDC_ROOT/templates/nginx-http.conf.tpl"
  if [[ "$https" != "off" ]]; then
    tpl="$XEDC_ROOT/templates/nginx-https.conf.tpl"
    mkdir -p /etc/ssl/xedc
    if [[ ! -f "$cert" || ! -f "$key" ]]; then
      openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
        -keyout "$key" -out "$cert" -subj "/CN=${canonical}" >/dev/null 2>&1
    fi
    if [[ -f "$key" ]]; then
      xedc_apply_perms "$key"
    fi
  fi
  rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf
  xedc_prepare_dir /etc/nginx/conf.d
  cp "$XEDC_ROOT/templates/gzip.conf" /etc/nginx/conf.d/gzip.conf
  xedc_apply_perms /etc/nginx/conf.d/gzip.conf
  php "$XEDC_ROOT/lib/php/render.php" "$tpl" /etc/nginx/conf.d/xedc.conf <<JSON
{"canonical":"${canonical}","alias":"${alias}","web_root":"${web_root}","client_max_body_size":"${body}","php_sock":"${sock}","ssl_cert":"${cert}","ssl_key":"${key}","mp4_directive":"${mp4}","ipv6_80":"${ipv6_80}","ipv6_443":"${ipv6_443}"}
JSON
  xedc_apply_perms /etc/nginx/conf.d/xedc.conf
  if [[ -f "$cert" ]]; then
    xedc_apply_perms "$cert"
  fi
}

nginx_configure() {
  local domain=${XEDC_DOMAIN:-localhost}
  local www=${XEDC_WWW:-no}
  local https=${XEDC_HTTPS_MODE:-self}
  local web=${XEDC_WEB_ROOT:-/var/www/drupal/web}
  local body=220m
  if [[ -n "${XEDC_MANIFEST:-}" && -f ${XEDC_MANIFEST} ]]; then
    local got
    got=$(xedc_manifest_get "$XEDC_MANIFEST" nginx.client_max_body_size || true)
    [[ -n "$got" ]] && body=$got
  fi
  nginx_render_site "$domain" "$www" "$https" "$web" "$body"
}

nginx_enable() {
  if [[ -f /etc/nginx/conf.d/xedc.conf ]]; then
    xedc_assert_readable www-data /etc/nginx/conf.d/xedc.conf
  fi
  nginx -t || xedc_die "nginx 配置检测失败"
  xedc_svc nginx || xedc_die "nginx 没有启动"
}

nginx_verify() {
  nginx -t
  curl -fsS -o /dev/null http://127.0.0.1/ || curl -kfsS -o /dev/null https://127.0.0.1/ || xedc_die "nginx 未响应"
  xedc_log "nginx 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) nginx_install ;;
    configure) nginx_configure ;;
    enable) nginx_enable ;;
    verify) nginx_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
