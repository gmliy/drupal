#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

ffmpeg_install() {
  xedc_apt_install ffmpeg
  if ! xedc_apt_install 7zip; then
    xedc_apt_install p7zip-full || xedc_warn "软件源没有 7z，压缩包导入会失败"
  fi
  xedc_log "ffmpeg $(ffmpeg -version 2>/dev/null | head -n 1)"
}

ffmpeg_configure() { return 0; }
ffmpeg_enable() { return 0; }

ffmpeg_verify() {
  local missing=0
  local enc
  for enc in libx264 aac; do
    if ! ffmpeg -hide_banner -encoders 2>/dev/null | grep -q "$enc"; then
      xedc_warn "ffmpeg 缺少编码器 ${enc}"
      missing=1
    fi
  done
  if (( missing == 1 )); then
    xedc_die "ffmpeg 不满足视频转码要求"
  fi
  xedc_log "ffmpeg 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) ffmpeg_install ;;
    configure) ffmpeg_configure ;;
    enable) ffmpeg_enable ;;
    verify) ffmpeg_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
