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
  cert="/etc/letsencrypt/live/${domain}/fullchain.pem"
  key="/etc/letsencrypt/live/${domain}/privkey.pem"
  sock=$(cat "$XEDC_STATE_DIR/php-sock" 2>/dev/null || true)
  if [[ -z "$sock" ]]; then
    if ! declare -F php_target_version >/dev/null 2>&1; then
      # shellcheck source=php.sh
      source "$(dirname "${BASH_SOURCE[0]}")/php.sh"
    fi
    sock="/run/php/php$(php_target_version)-fpm.sock"
  fi
  local nginx_build
  nginx_build=$(nginx -V 2>&1 || true)
  if grep -q with-http_mp4_module <<<"$nginx_build"; then
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
  fi
  rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf
  xedc_prepare_dir /etc/nginx/conf.d
  if ! xedc_keep_manual /etc/nginx/conf.d/gzip.conf; then
    cp "$XEDC_ROOT/templates/gzip.conf" /etc/nginx/conf.d/gzip.conf
    xedc_apply_perms /etc/nginx/conf.d/gzip.conf
    xedc_remember_hash /etc/nginx/conf.d/gzip.conf
  fi
  if ! xedc_keep_manual /etc/nginx/conf.d/xedc.conf; then
    php "$XEDC_ROOT/lib/php/render.php" "$tpl" /etc/nginx/conf.d/xedc.conf <<JSON
{"canonical":"${canonical}","alias":"${alias}","web_root":"${web_root}","client_max_body_size":"${body}","php_sock":"${sock}","ssl_cert":"${cert}","ssl_key":"${key}","mp4_directive":"${mp4}","ipv6_80":"${ipv6_80}","ipv6_443":"${ipv6_443}"}
JSON
    xedc_apply_perms /etc/nginx/conf.d/xedc.conf
    xedc_remember_hash /etc/nginx/conf.d/xedc.conf
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

nginx_load_certbot() {
  if ! declare -F certbot_ensure_snippets >/dev/null 2>&1; then
    # shellcheck source=certbot.sh
    source "$(dirname "${BASH_SOURCE[0]}")/certbot.sh"
  fi
}

nginx_prepare_http() {
  local file
  shopt -s nullglob
  for file in /etc/nginx/conf.d/*.conf /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*; do
    [[ -f "$file" ]] || continue
    php "$XEDC_ROOT/lib/php/nginx_acme.php" "$file"
  done
  shopt -u nullglob
}

nginx_dump_relevant() {
  local dir
  for dir in /etc/nginx/conf.d /etc/nginx/sites-available /etc/nginx/sites-enabled; do
    [[ -d "$dir" ]] || continue
    grep -RInE '^[[:space:]]*(server_name|ssl_certificate|ssl_certificate_key|ssl_dhparam|include)[[:space:]]' "$dir" 2>/dev/null || true
  done
}

nginx_test() {
  local out
  if out=$(nginx -t 2>&1); then
    return 0
  fi
  xedc_log "nginx 配置检测失败，完整输出："
  printf '%s\n' "$out" | xedc_log_text
  xedc_log "相关配置行（server_name、ssl_certificate、include）："
  nginx_dump_relevant | xedc_log_text
  return 1
}

nginx_domain_from_cert() {
  local cert=$1 base
  base=$(basename "$(dirname "$cert")")
  if [[ "$cert" == /etc/letsencrypt/live/* ]]; then
    printf '%s\n' "$base"
    return 0
  fi
  base=$(basename "$cert")
  base=${base%.crt}
  base=${base%.pem}
  printf '%s\n' "$base"
}

nginx_ensure_certs() {
  nginx_load_certbot
  certbot_ensure_snippets
  local file cert key domain
  shopt -s nullglob
  for file in /etc/nginx/conf.d/*.conf /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*; do
    [[ -f "$file" ]] || continue
    while read -r cert key; do
      [[ -n "$cert" && -n "$key" ]] || continue
      [[ "$cert" == *'$'* || "$key" == *'$'* ]] && continue
      if [[ -s "$cert" && -s "$key" ]]; then
        continue
      fi
      domain=$(nginx_domain_from_cert "$cert")
      [[ -n "$domain" ]] || continue
      if [[ "$cert" == "/etc/letsencrypt/live/${domain}/fullchain.pem" && "$key" == "/etc/letsencrypt/live/${domain}/privkey.pem" ]]; then
        certbot_selfsign "$domain"
        continue
      fi
      xedc_warn "证书路径不是 live 目录，仍写到引用的位置: ${cert}"
      certbot_selfsign "$domain"
      if [[ -s "$(certbot_live_dir "$domain")/fullchain.pem" ]]; then
        mkdir -p "$(dirname "$cert")" "$(dirname "$key")"
        cp "$(certbot_live_dir "$domain")/fullchain.pem" "$cert"
        cp "$(certbot_live_dir "$domain")/privkey.pem" "$key"
        touch "$(dirname "$cert")/.selfsigned"
      fi
    done < <(awk '
      $1 == "ssl_certificate" { gsub(/;/, "", $2); cert = $2 }
      $1 == "ssl_certificate_key" { gsub(/;/, "", $2); if (cert != "") print cert, $2 }
    ' "$file")
  done
  shopt -u nullglob
}

nginx_core_file() {
  local base
  base=$(basename "$1")
  case "$base" in
    xedc.conf|gzip.conf|nginx.conf|mime.types|fastcgi.conf|fastcgi_params|proxy_params|scgi_params|uwsgi_params|koi-utf|koi-win|win-utf) return 0 ;;
  esac
  return 1
}

nginx_drop_excluded() {
  local file
  shopt -s nullglob
  for file in /etc/nginx/conf.d/* /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*; do
    [[ -e "$file" ]] || continue
    if xedc_conf_excluded "$file"; then
      rm -f "$file"
      xedc_log "按 exclude.list 跳过 nginx 配置 $(basename "$file")"
    fi
  done
  shopt -u nullglob
}

nginx_ref_lines() {
  local file=$1
  awk '
    $1 == "include" || $1 == "ssl_certificate" || $1 == "ssl_certificate_key" || $1 == "ssl_dhparam" {
      gsub(/;/, "", $2)
      if ($2 != "") print $1, $2
    }
  ' "$file"
}

nginx_ensure_refs() {
  nginx_load_certbot
  local file kind path domain
  shopt -s nullglob
  for file in /etc/nginx/conf.d/*.conf /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*; do
    [[ -f "$file" ]] || continue
    while read -r kind path; do
      [[ -n "$kind" && -n "$path" ]] || continue
      [[ "$path" == *'$'* || "$path" == *'*'* ]] && continue
      if [[ -e "$path" ]]; then
        continue
      fi
      case "$(basename "$path")" in
        options-ssl-nginx.conf|ssl-dhparams.pem)
          certbot_ensure_snippets
          ;;
        *)
          if [[ "$kind" == "ssl_certificate" || "$kind" == "ssl_certificate_key" || "$kind" == "ssl_dhparam" ]]; then
            domain=$(nginx_domain_from_cert "$path")
            if [[ "$kind" == "ssl_dhparam" ]]; then
              mkdir -p "$(dirname "$path")"
              if [[ ! -s /etc/letsencrypt/ssl-dhparams.pem ]]; then
                certbot_ensure_snippets
              fi
              if [[ -s /etc/letsencrypt/ssl-dhparams.pem && "$path" != /etc/letsencrypt/ssl-dhparams.pem ]]; then
                cp /etc/letsencrypt/ssl-dhparams.pem "$path"
              fi
            elif [[ -n "$domain" ]]; then
              xedc_warn "证书不存在，写入临时自签证书: ${path}。使用自签证书，待申请正式证书"
              certbot_selfsign "$domain"
              if [[ "$path" != "$(certbot_live_dir "$domain")/fullchain.pem" && "$path" != "$(certbot_live_dir "$domain")/privkey.pem" ]]; then
                mkdir -p "$(dirname "$path")"
                if [[ "$kind" == "ssl_certificate" && -s "$(certbot_live_dir "$domain")/fullchain.pem" ]]; then
                  cp "$(certbot_live_dir "$domain")/fullchain.pem" "$path"
                  touch "$(dirname "$path")/.selfsigned"
                fi
                if [[ "$kind" == "ssl_certificate_key" && -s "$(certbot_live_dir "$domain")/privkey.pem" ]]; then
                  cp "$(certbot_live_dir "$domain")/privkey.pem" "$path"
                fi
              fi
            fi
          else
            xedc_warn "配置 ${file} 引用了不存在的文件 ${path}"
          fi
          ;;
      esac
    done < <(nginx_ref_lines "$file")
  done
  shopt -u nullglob
}

nginx_quarantine_sites() {
  local file base origin tmp list
  mkdir -p /etc/nginx/disabled
  chmod 755 /etc/nginx/disabled
  if nginx -t >/dev/null 2>&1; then
    return 0
  fi
  tmp=$(xedc_mktemp_d)
  list="$tmp/list"
  : >"$list"
  shopt -s nullglob
  for file in /etc/nginx/conf.d/* /etc/nginx/sites-enabled/*; do
    [[ -f "$file" || -L "$file" ]] || continue
    if nginx_core_file "$file"; then
      continue
    fi
    base=$(basename "$file")
    origin=$(dirname "$file")
    mv "$file" "$tmp/$base"
    printf '%s %s\n' "$base" "$origin" >>"$list"
  done
  shopt -u nullglob
  if ! nginx -t >/dev/null 2>&1; then
    while read -r base origin; do
      [[ -n "$base" && -n "$origin" ]] || continue
      mv "$tmp/$base" "$origin/$base"
    done <"$list"
    rm -rf "$tmp"
    return 1
  fi
  while read -r base origin; do
    [[ -n "$base" && -n "$origin" ]] || continue
    mv "$tmp/$base" "$origin/$base"
    if nginx -t >/dev/null 2>&1; then
      xedc_log "站点配置 ${base} 可以保留"
      continue
    fi
    mv "$origin/$base" "/etc/nginx/disabled/$base"
    xedc_fail_item "nginx 站点 ${base} 无法通过检测，已移到 /etc/nginx/disabled/${base}"
    if declare -F note_manual >/dev/null 2>&1; then
      note_manual "nginx 站点 ${base} 已隔离到 /etc/nginx/disabled/${base}。主站继续"
    fi
  done <"$list"
  rm -rf "$tmp"
  nginx -t >/dev/null 2>&1
}

nginx_reload() {
  if nginx -t >/dev/null 2>&1 && nginx -s reload >/dev/null 2>&1; then
    return 0
  fi
  xedc_svc nginx
}

nginx_enable() {
  nginx_load_certbot
  nginx_drop_excluded
  certbot_ensure_snippets
  nginx_prepare_http
  nginx_ensure_refs
  nginx_ensure_certs
  if [[ -f /etc/nginx/conf.d/xedc.conf ]]; then
    xedc_assert_readable www-data /etc/nginx/conf.d/xedc.conf
  fi
  local err=""
  if ! err=$(nginx_test 2>&1); then
    printf '%s\n' "$err" >&2
    xedc_log "已尝试补上 certbot 片段、临时自签证书，并隔离非主站配置"
    if nginx_quarantine_sites && nginx_test >/dev/null; then
      xedc_log "主站 nginx 配置可以通过检测"
    else
      nginx_test >&2 || true
      xedc_die "nginx 配置检测失败。上面是完整报错和配置行号。已尝试写入 options-ssl-nginx.conf、ssl-dhparams.pem、临时自签证书，并把出错的非主站配置移到 /etc/nginx/disabled/"
    fi
  fi
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
