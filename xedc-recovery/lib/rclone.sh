#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi

xedc_rclone_zip_arch() {
  case "$(uname -m)" in
    x86_64|amd64) printf '%s\n' linux-amd64 ;;
    aarch64|arm64) printf '%s\n' linux-arm64 ;;
    *) return 1 ;;
  esac
}

xedc_install_rclone() {
  local arch tmp zip bin dir
  arch=$(xedc_rclone_zip_arch || true)
  tmp=$(xedc_work_mktemp_d)
  if [[ -n "$arch" ]]; then
    xedc_apt_install unzip ca-certificates curl || true
    zip="$tmp/rclone.zip"
    if curl -fsSL --max-time 90 -o "$zip" "https://downloads.rclone.org/rclone-current-${arch}.zip" && unzip -qo "$zip" -d "$tmp"; then
      bin=$(find "$tmp" -type f -name rclone -print -quit)
      if [[ -n "$bin" ]]; then
        install -m 755 "$bin" /usr/local/bin/rclone
        install -m 755 "$bin" /usr/bin/rclone
        hash -r || true
        rm -rf "$tmp"
        if rclone version >/dev/null 2>&1; then
          xedc_log "rclone $(rclone version | awk 'NR==1{print $2}')"
          return 0
        fi
      fi
    fi
  fi
  rm -rf "$tmp"
  xedc_warn "官方 rclone 没有装上，改用系统自带版本"
  xedc_apt_install unzip rclone
  xedc_log "rclone $(rclone version | awk 'NR==1{print $2}')"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) xedc_install_rclone ;;
    *) echo "用法: $0 install" >&2; exit 2 ;;
  esac
fi
