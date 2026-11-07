#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi

domain_resolve() {
  local manifest=${XEDC_MANIFEST:-${MANIFEST:-}}
  if [[ -z "${OLD_DOMAIN:-}" && -n "$manifest" && -f "$manifest" ]]; then
    OLD_DOMAIN=$(xedc_manifest_get "$manifest" domain || true)
  fi
  [[ -n "${OLD_DOMAIN:-}" ]] || OLD_DOMAIN=${DOMAIN:-}
  if [[ -n "${MATOMO_DOMAIN:-}" ]]; then
    :
  elif [[ -n "${XEDC_MATOMO_DOMAIN:-}" ]]; then
    MATOMO_DOMAIN=$XEDC_MATOMO_DOMAIN
  else
    MATOMO_DOMAIN="x.${DOMAIN:-}"
  fi
  OLD_MATOMO="x.${OLD_DOMAIN}"
  export OLD_DOMAIN MATOMO_DOMAIN OLD_MATOMO
  mkdir -p "$XEDC_STATE_DIR"
  cat >"$XEDC_STATE_DIR/domain-map" <<EOF
old=${OLD_DOMAIN}
new=${DOMAIN:-}
old_matomo=${OLD_MATOMO}
new_matomo=${MATOMO_DOMAIN}
EOF
  chmod 644 "$XEDC_STATE_DIR/domain-map"
}

domain_replace_file() {
  local file=$1
  [[ -f "$file" ]] || return 0
  [[ -n "${OLD_DOMAIN:-}" || -n "${OLD_MATOMO:-}" ]] || return 0
  php -r '
    $file = $argv[1];
    $oldM = $argv[2];
    $newM = $argv[3];
    $old = $argv[4];
    $new = $argv[5];
    $text = file_get_contents($file);
    if ($text === false) {
      exit(0);
    }
    $next = $text;
    $token = "\0XEDC-MATOMO\0";
    if ($oldM !== "" && $oldM !== $newM) {
      $next = str_replace($oldM, $token, $next);
    }
    if ($old !== "" && $old !== $new) {
      $next = str_replace($old, $new, $next);
    }
    if ($oldM !== "" && $oldM !== $newM) {
      $next = str_replace($token, $newM, $next);
    }
    if ($next !== $text) {
      file_put_contents($file, $next);
    }
  ' "$file" "${OLD_MATOMO:-}" "${MATOMO_DOMAIN:-}" "${OLD_DOMAIN:-}" "${DOMAIN:-}"
}

domain_replace_tree() {
  local root=$1 file
  [[ -d "$root" ]] || return 0
  while IFS= read -r file; do
    [[ -n "$file" ]] || continue
    if xedc_conf_excluded "$file" || xedc_excluded "$(basename "$file")"; then
      rm -f "$file"
      xedc_log "按 exclude.list 移除 ${file}"
      continue
    fi
    domain_replace_file "$file"
  done < <(grep -RIl -e "${OLD_DOMAIN}" -e "${OLD_MATOMO:-}" "$root" 2>/dev/null || true)
}

domain_apply_configs() {
  domain_resolve
  domain_replace_tree /etc/nginx/conf.d
  domain_replace_tree /etc/nginx/sites-available
  domain_replace_tree /etc/nginx/sites-enabled
  domain_replace_tree /etc/cron.d
  if [[ "${OLD_DOMAIN}" == "${DOMAIN:-}" && "${OLD_MATOMO:-}" == "${MATOMO_DOMAIN:-}" ]]; then
    return 0
  fi
  domain_replace_file /etc/netdata/netdata.conf
  local site=${XEDC_SITE_ROOT:-${SITE:-}}
  if [[ -n "$site" ]]; then
    domain_replace_file "${site}/web/robots.txt"
    domain_replace_file "${site}/web/sitemap.xml"
  fi
  xedc_log "域名配置已替换：${OLD_DOMAIN} -> ${DOMAIN:-}"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "域名替换函数库" >&2
  exit 0
fi
