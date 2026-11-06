#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

drush_install() {
  local site bin
  site=${XEDC_SITE_ROOT:-/var/www/drupal}
  if [[ -x "$site/vendor/bin/drush" ]]; then
    ln -sfn "$site/vendor/bin/drush" /usr/local/bin/drush
  elif [[ -x "$site/bin/drush" ]]; then
    ln -sfn "$site/bin/drush" /usr/local/bin/drush
  elif xedc_have drush; then
    xedc_log "使用已有 drush: $(command -v drush)"
  else
    xedc_warn "站点里还没有 drush，composer install 之后会再链接"
    return 0
  fi
  bin=$(xedc_drush || true)
  xedc_log "Drush ${bin}"
}

drush_configure() { drush_install; }
drush_enable() { return 0; }
drush_verify() {
  local bin
  bin=$(xedc_drush)
  "$bin" --version >/dev/null 2>&1 || "$bin" status >/dev/null 2>&1 || xedc_die "drush 无法执行"
  xedc_log "Drush 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) drush_install ;;
    configure) drush_configure ;;
    enable) drush_enable ;;
    verify) drush_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
