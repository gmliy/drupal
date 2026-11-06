#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

certbot_install() {
  xedc_apt_install certbot python3-certbot-nginx
  if ! xedc_apt_install python3-certbot-dns-cloudflare; then
    xedc_warn "未安装 Cloudflare DNS 插件。HTTP 验证仍可用"
  fi
  local pkg
  for pkg in dnsutils bind9-dnsutils; do
    if xedc_apt_install "$pkg"; then
      break
    fi
  done
  xedc_log "certbot $(certbot --version 2>&1 | head -n 1)"
}

certbot_issue() {
  local domain=$1 email=$2 www=$3
  local -a names=(-d "$domain")
  if [[ "$www" == "yes" || "$www" == "no" ]]; then
    names+=(-d "www.${domain}")
  fi
  if certbot --nginx "${names[@]}" --email "$email" --agree-tos --non-interactive --redirect; then
    xedc_log "已申请证书 ${domain}"
    return 0
  fi
  return 1
}

certbot_points_here() {
  local domain=$1
  local resolved local_ips ip
  resolved=$(dig +short "$domain" A 2>/dev/null || true)
  local_ips=$(hostname -I 2>/dev/null || true)
  for ip in $resolved; do
    if [[ "$local_ips" == *"$ip"* ]]; then
      return 0
    fi
  done
  return 1
}

certbot_cloudflare_hint() {
  local domain=$1
  if dig +short NS "$domain" 2>/dev/null | grep -qi cloudflare; then
    xedc_warn "域名使用 Cloudflare。证书生效后，SSL 模式请用 Full (strict)"
  fi
}

certbot_configure() { return 0; }
certbot_enable() {
  if xedc_systemd; then
    systemctl enable certbot.timer >/dev/null 2>&1 || true
  fi
}
certbot_verify() {
  certbot --version >/dev/null 2>&1 || xedc_die "certbot 不可用"
  xedc_log "certbot 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) certbot_install ;;
    configure) certbot_configure ;;
    enable) certbot_enable ;;
    verify) certbot_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
