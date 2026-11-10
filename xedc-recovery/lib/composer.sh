#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

composer_install() {
  if xedc_have composer; then
    xedc_log "Composer 已安装: $(composer --version 2>/dev/null | xedc_first_line)"
    return 0
  fi
  xedc_apt_install curl ca-certificates unzip
  local setup
  setup=$(xedc_mktemp)
  curl -fsSL --max-time 60 -o "$setup" https://getcomposer.org/installer
  php "$setup" --install-dir=/usr/local/bin --filename=composer
  rm -f "$setup"
  xedc_log "Composer $(composer --version 2>/dev/null | xedc_first_line)"
}

composer_configure() { return 0; }
composer_enable() { return 0; }
composer_verify() {
  composer --version >/dev/null
  xedc_log "Composer 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) composer_install ;;
    configure) composer_configure ;;
    enable) composer_enable ;;
    verify) composer_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
