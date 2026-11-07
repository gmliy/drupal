#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

certbot_webroot() {
  printf '%s\n' /var/www/letsencrypt
}

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
  certbot_ensure_snippets
  xedc_log "certbot $(certbot --version 2>&1 | head -n 1)"
}

certbot_ensure_snippets() {
  if ! command -v certbot >/dev/null 2>&1 || ! dpkg-query -W -f '${Status}' python3-certbot-nginx 2>/dev/null | grep -q 'install ok installed'; then
    xedc_apt_install certbot python3-certbot-nginx || xedc_die "未能安装 certbot 或 python3-certbot-nginx"
  fi
  (
    umask 022
    mkdir -p /etc/letsencrypt /var/www/letsencrypt/.well-known/acme-challenge
    chmod 755 /etc/letsencrypt /var/www/letsencrypt /var/www/letsencrypt/.well-known /var/www/letsencrypt/.well-known/acme-challenge
  )
  if [[ ! -s /etc/letsencrypt/options-ssl-nginx.conf ]]; then
    local src=""
    src=$(find /usr -path '*certbot_nginx*' -name 'options-ssl-nginx.conf' 2>/dev/null | head -n 1 || true)
    if [[ -n "$src" ]]; then
      cp "$src" /etc/letsencrypt/options-ssl-nginx.conf
    else
      cp "$XEDC_ROOT/templates/options-ssl-nginx.conf" /etc/letsencrypt/options-ssl-nginx.conf
    fi
    chmod 644 /etc/letsencrypt/options-ssl-nginx.conf
    xedc_log "已写入 /etc/letsencrypt/options-ssl-nginx.conf"
  fi
  if [[ ! -s /etc/letsencrypt/ssl-dhparams.pem ]]; then
    xedc_log "正在生成 /etc/letsencrypt/ssl-dhparams.pem（openssl dhparam 2048）"
    openssl dhparam -dsaparam -out /etc/letsencrypt/ssl-dhparams.pem 2048
    chmod 644 /etc/letsencrypt/ssl-dhparams.pem
  fi
}

certbot_local_ips() {
  {
    hostname -I 2>/dev/null || true
    ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 || true
    curl -4fsS --max-time 3 https://api.ipify.org 2>/dev/null || true
  } | tr ' ' '\n' | sed '/^$/d' | sort -u
}

certbot_points_here() {
  local domain=$1 ip resolved local_ips
  if ! command -v dig >/dev/null 2>&1; then
    xedc_warn "没有 dig，无法确认 ${domain} 是否指向本机"
    return 1
  fi
  resolved=$(dig +short "$domain" A 2>/dev/null || true)
  [[ -n "$resolved" ]] || return 1
  local_ips=$(certbot_local_ips)
  for ip in $resolved; do
    [[ "$ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || continue
    if printf '%s\n' "$local_ips" | grep -Fxq "$ip"; then
      return 0
    fi
  done
  return 1
}

certbot_live_dir() {
  printf '%s\n' "/etc/letsencrypt/live/$1"
}

certbot_cert_is_real() {
  local dir cert subj issuer
  dir=$(certbot_live_dir "$1")
  cert="${dir}/fullchain.pem"
  [[ -s "$cert" && -s "${dir}/privkey.pem" ]] || return 1
  [[ -f "${dir}/.selfsigned" ]] && return 1
  subj=$(openssl x509 -in "$cert" -noout -subject 2>/dev/null || true)
  issuer=$(openssl x509 -in "$cert" -noout -issuer 2>/dev/null || true)
  [[ -n "$subj" && "$subj" != "$issuer" ]] || return 1
  openssl x509 -in "$cert" -noout -checkend 0 >/dev/null 2>&1
}

certbot_selfsign() {
  local domain=$1
  local dir cert key san
  dir=$(certbot_live_dir "$domain")
  cert="${dir}/fullchain.pem"
  key="${dir}/privkey.pem"
  if [[ -s "$cert" && -s "$key" ]]; then
    return 0
  fi
  san="DNS:${domain}"
  if [[ "${WWW:-no}" == "yes" && "$domain" != www.* ]]; then
    san="${san},DNS:www.${domain}"
  fi
  (
    umask 022
    mkdir -p "$dir"
    chmod 755 "$dir"
    if ! openssl req -x509 -nodes -days 90 -newkey rsa:2048 \
      -keyout "$key" -out "$cert" \
      -subj "/CN=${domain}" \
      -addext "subjectAltName=${san}" >/dev/null 2>&1; then
      openssl req -x509 -nodes -days 90 -newkey rsa:2048 \
        -keyout "$key" -out "$cert" \
        -subj "/CN=${domain}" >/dev/null 2>&1
    fi
    touch "${dir}/.selfsigned"
    chmod 644 "$cert" "${dir}/.selfsigned"
    chmod 640 "$key"
    chown root:root "$cert" "$key" "${dir}/.selfsigned"
  )
  xedc_log "已为 ${domain} 写入临时自签证书 ${cert}"
}

certbot_issue_webroot() {
  local domain=$1 email=$2 role=$3
  local -a names=(-d "$domain")
  if [[ "$role" == "main" && "${WWW:-no}" == "yes" ]]; then
    names+=(-d "www.${domain}")
  fi
  certbot certonly --webroot -w "$(certbot_webroot)" "${names[@]}" \
    --email "$email" --agree-tos --non-interactive --keep-until-expiring \
    --cert-name "$domain"
}

certbot_manual_command() {
  local domain=$1 email=$2 role=$3
  local cmd="certbot certonly --webroot -w $(certbot_webroot) -d ${domain}"
  if [[ "$role" == "main" && "${WWW:-no}" == "yes" ]]; then
    cmd="${cmd} -d www.${domain}"
  fi
  cmd="${cmd} --email ${email:-你的邮箱} --agree-tos --non-interactive --keep-until-expiring && nginx -s reload"
  printf '%s\n' "$cmd"
}

certbot_upgrade_domain() {
  local domain=$1 role=$2
  local mode=${XEDC_HTTPS_MODE:-${HTTPS:-auto}}
  local email=${EMAIL:-}
  local dir cmd
  [[ -n "$domain" ]] || return 0
  if xedc_excluded "$domain"; then
    xedc_log "按 exclude.list 不为 ${domain} 申请证书"
    return 0
  fi
  if [[ "$mode" == "self" || "$mode" == "off" ]]; then
    return 0
  fi
  dir=$(certbot_live_dir "$domain")
  if certbot_cert_is_real "$domain"; then
    xedc_log "${domain} 已有有效证书，不重复签发"
    return 0
  fi
  cmd=$(certbot_manual_command "$domain" "$email" "$role")
  if ! certbot_points_here "$domain"; then
    xedc_warn "${domain} 的 DNS 还没指向本机，继续使用临时自签证书"
    if declare -F note_manual >/dev/null 2>&1; then
      note_manual "${domain} 目前是临时自签证书（${dir}/.selfsigned）。DNS 指向本机公网 IP 之后执行: ${cmd}"
    fi
    return 0
  fi
  if [[ -z "$email" ]]; then
    xedc_warn "${domain} 没有邮箱，不能申请正式证书"
    if declare -F note_manual >/dev/null 2>&1; then
      note_manual "${domain} 缺少 --email，仍是临时自签证书。补上邮箱后执行: ${cmd}"
    fi
    return 0
  fi
  if [[ -f "${dir}/.selfsigned" ]]; then
    rm -rf "$dir"
  fi
  if certbot_issue_webroot "$domain" "$email" "$role" && certbot_cert_is_real "$domain"; then
    xedc_log "已申请正式证书 ${domain}"
    if declare -F nginx_reload >/dev/null 2>&1; then
      nginx_reload || xedc_warn "证书已签发，但 nginx 重载失败"
    fi
    return 0
  fi
  xedc_warn "${domain} 正式证书没有签下来，恢复临时自签证书"
  rm -rf "$dir"
  certbot_selfsign "$domain"
  if declare -F note_manual >/dev/null 2>&1; then
    note_manual "${domain} 申请正式证书失败，目前仍是临时自签。稍后执行: ${cmd}"
  fi
  return 0
}

certbot_cloudflare_hint() {
  local domain=$1
  if dig +short NS "$domain" 2>/dev/null | grep -qi cloudflare; then
    xedc_warn "域名使用 Cloudflare。证书生效后，SSL 模式请用 Full (strict)。橙色云代理时 HTTP 验证到不了本机，需要先改成仅 DNS"
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
