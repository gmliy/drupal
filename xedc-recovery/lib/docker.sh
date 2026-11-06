#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

docker_install() {
  if xedc_have docker; then
    xedc_log "Docker 已安装: $(docker --version 2>/dev/null)"
    return 0
  fi
  if [[ "${XEDC_TEST:-0}" == 1 ]]; then
    xedc_apt_install docker.io
  else
    if ! xedc_apt_install docker.io; then
      xedc_die "Docker 安装失败"
    fi
    xedc_warn "已安装发行版 docker.io。生产环境若原来是 docker-ce，功能兼容"
  fi
  xedc_log "Docker $(docker --version 2>/dev/null)"
}

docker_configure() { return 0; }

docker_enable() {
  if xedc_systemd; then
    xedc_svc docker
    return 0
  fi
  if service docker start >/dev/null 2>&1; then
    return 0
  fi
  xedc_warn "当前环境没有 systemd，Docker 守护进程未启动。软件包已安装"
  return 0
}

docker_verify() {
  docker --version >/dev/null || xedc_die "docker 命令不可用"
  if docker info >/dev/null 2>&1; then
    xedc_log "Docker 守护进程正常"
  else
    xedc_warn "Docker 命令可用，守护进程未运行"
  fi
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) docker_install ;;
    configure) docker_configure ;;
    enable) docker_enable ;;
    verify) docker_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
