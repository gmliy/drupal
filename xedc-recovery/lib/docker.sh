#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

docker_install_compose() {
  if docker compose version >/dev/null 2>&1; then
    xedc_log "Docker Compose $(docker compose version 2>/dev/null | head -n 1)"
    return 0
  fi
  xedc_apt_update
  local pkg=""
  if dpkg-query -W -f '${Status}' docker-ce 2>/dev/null | grep -q 'install ok installed'; then
    pkg=docker-compose-plugin
  elif apt-cache show docker-compose-v2 >/dev/null 2>&1; then
    pkg=docker-compose-v2
  elif apt-cache show docker-compose-plugin >/dev/null 2>&1; then
    pkg=docker-compose-plugin
  fi
  if [[ -z "$pkg" ]]; then
    xedc_die "没有 compose 软件包。Ubuntu 安装 docker-compose-v2，Docker 官方源安装 docker-compose-plugin"
  fi
  xedc_apt_install "$pkg" || xedc_die "未能安装 ${pkg}"
  docker compose version >/dev/null 2>&1 || xedc_die "docker compose version 失败。Ubuntu 需要 docker-compose-v2，Docker 官方源需要 docker-compose-plugin"
  xedc_log "Docker Compose $(docker compose version 2>/dev/null | head -n 1)"
}

docker_install() {
  if ! xedc_have docker; then
    if ! xedc_apt_install docker.io; then
      xedc_die "Docker 安装失败"
    fi
    if ! dpkg-query -W -f '${Status}' docker-ce 2>/dev/null | grep -q 'install ok installed'; then
      xedc_warn "已安装发行版 docker.io。生产环境若原来是 docker-ce，功能兼容"
    fi
  else
    xedc_log "Docker 已安装: $(docker --version 2>/dev/null)"
  fi
  xedc_log "Docker $(docker --version 2>/dev/null)"
  docker_install_compose
}

docker_configure() { return 0; }

docker_compose_up() {
  local dir=$1 log
  log=$(mktemp)
  if ! docker compose version >"$log" 2>&1; then
    cat "$log" >&2 || true
    rm -f "$log"
    xedc_die "没有 docker compose。Ubuntu 安装 docker-compose-v2，Docker 官方源安装 docker-compose-plugin。不能只还原编排文件就继续"
  fi
  if ! docker info >"$log" 2>&1; then
    xedc_log "Docker 守护进程未运行，容器没有启动"
    cat "$log" >&2 || true
    rm -f "$log"
    xedc_die "Docker 守护进程未运行，容器没有启动。目录 ${dir}"
  fi
  if ! (cd "$dir" && docker compose up -d) >"$log" 2>&1; then
    xedc_log "容器启动失败，日志如下"
    cat "$log" >&2 || true
    (cd "$dir" && docker compose logs --no-color) >&2 || true
    rm -f "$log"
    xedc_die "容器启动失败: ${dir}"
  fi
  rm -f "$log"
  xedc_log "容器已启动: ${dir}"
}

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
