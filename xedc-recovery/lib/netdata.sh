#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

netdata_install() {
  if xedc_have netdata || [[ -x /opt/netdata/bin/netdata ]]; then
    xedc_log "Netdata 已安装"
    return 0
  fi
  if [[ "${XEDC_TEST:-0}" != 1 ]]; then
    local install_kind=""
    if [[ -n "${XEDC_MANIFEST:-}" && -f ${XEDC_MANIFEST} ]]; then
      install_kind=$(xedc_manifest_get "$XEDC_MANIFEST" netdata.install || true)
    fi
    if [[ "$install_kind" == "kickstart" ]]; then
      local ks
      ks=$(mktemp)
      if curl -fsSL --max-time 60 -o "$ks" https://get.netdata.cloud/kickstart.sh && bash "$ks" --dont-wait --stable-channel --disable-telemetry; then
        rm -f "$ks"
        xedc_warn "Netdata 已安装。claim token 不会恢复，请重新 claim"
        return 0
      fi
      rm -f "$ks"
      xedc_warn "Netdata kickstart 失败，改用软件包"
    fi
  fi
  if ! xedc_apt_install netdata; then
    xedc_warn "这个系统没有 Netdata 软件包。站点继续恢复，监控需要稍后手动安装并重新 claim"
    return 0
  fi
  xedc_warn "Netdata 已用软件包安装。云端 claim 需要在恢复后重新做"
}

netdata_configure() {
  local src=${XEDC_SYSTEM_DIR:-}
  if [[ -n "$src" && -d "$src/netdata" ]]; then
    mkdir -p /etc/netdata
    cp -a "$src/netdata/." /etc/netdata/ 2>/dev/null || true
    rm -f /etc/netdata/claim.conf /var/lib/netdata/cloud.d/claimed_id
  fi
}

netdata_enable() {
  if xedc_systemd; then
    xedc_svc netdata || xedc_warn "Netdata 服务未能启动"
    return 0
  fi
  if service netdata start >/dev/null 2>&1; then
    return 0
  fi
  xedc_warn "当前环境无法用 systemd 启动 Netdata"
}

netdata_verify() {
  if xedc_have netdata || [[ -x /opt/netdata/bin/netdata ]]; then
    xedc_log "Netdata 程序存在"
  else
    xedc_die "Netdata 未安装"
  fi
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) netdata_install ;;
    configure) netdata_configure ;;
    enable) netdata_enable ;;
    verify) netdata_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
