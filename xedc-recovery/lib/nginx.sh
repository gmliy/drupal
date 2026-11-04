#!/usr/bin/env bash
# shellcheck shell=bash

xedc_nginx_render() {
  local domain=$1 root_dir=$2 socket=$3 dest=$4 cert=$5 stapling=${6:-no}
  local server_names=$domain
  if [[ $domain != www.* && $domain != *.*.*.* ]]; then
    server_names="$domain www.$domain"
  fi
  local mp4_lines="" stapling_lines=""
  if [[ $stapling == yes ]]; then
    stapling_lines=$'ssl_stapling on;\n    ssl_stapling_verify on;\n    resolver 1.1.1.1 8.8.8.8 valid=300s;\n    resolver_timeout 5s;'
  fi
  if xedc_have nginx && nginx -V 2>&1 | grep -q http_mp4_module; then
    mp4_lines=$'mp4;\n        mp4_buffer_size 1m;\n        mp4_max_buffer_size 5m;'
  fi
  cat >"$dest" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${server_names};
    root ${root_dir};
    index index.php;
    client_max_body_size 220m;
    location ^~ /.well-known/acme-challenge/ { allow all; }
    location / { return 301 https://\$host\$request_uri; }
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${server_names};
    root ${root_dir};
    index index.php;
    client_max_body_size 220m;
    ssl_certificate ${cert}/fullchain.pem;
    ssl_certificate_key ${cert}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ${stapling_lines}
    add_header Strict-Transport-Security "max-age=300" always;
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { allow all; access_log off; log_not_found off; }
    location ^~ /.well-known/acme-challenge/ { allow all; }
    location ~ \\..*/.*\\.php\$ { return 403; }
    location ~ ^/sites/.*/private/ { return 403; }
    location ~ ^/sites/[^/]+/files/.*\\.php\$ { deny all; }
    location / { try_files \$uri /index.php?\$query_string; }
    location @rewrite { rewrite ^ /index.php; }
    location ~ \\.php\$ {
        include fastcgi_params;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${socket};
        fastcgi_read_timeout 300s;
    }
    location ~* ^/sites/default/files/.+\\.(avif|webp|jpe?g|png|svg|woff2?)\$ {
        add_header Cache-Control "public, max-age=2592000";
        try_files \$uri @rewrite;
    }
    location ~* \\.(mp4|m4v|webm|m3u8)\$ {
        ${mp4_lines}
        try_files \$uri @rewrite;
    }
}
EOF
}

xedc_nginx_old_redirect() {
  local old=$1 new=$2 dest=/etc/nginx/conf.d/xedc-drupal-old.conf
  cat >"$dest" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${old} www.${old};
    return 301 https://${new}\$request_uri;
}
EOF
  if ! nginx -t; then
    rm -f "$dest"
    nginx -t || true
    xedc_die "旧域名跳转配置无效，已删除。"
  fi
  if [[ ${XEDC_DRY_RUN:-0} != 1 ]]; then
    systemctl reload nginx
  fi
}

xedc_nginx_test_and_switch() {
  local new_file=$1 live=$2
  local backup
  backup=$(xedc_mktemp)
  if [[ -f $live ]]; then
    cp -a "$live" "$backup"
  fi
  cp "$new_file" "$live"
  if ! nginx -t; then
    if [[ -s $backup ]]; then
      cp -a "$backup" "$live"
    else
      rm -f "$live"
    fi
    nginx -t || true
    xedc_die "nginx 配置检查失败，已恢复原文件。"
  fi
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    return 0
  fi
  systemctl reload nginx
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
