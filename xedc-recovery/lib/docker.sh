#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

docker_compose_pkg() {
  if dpkg-query -W -f '${Status}' docker-ce 2>/dev/null | grep -q 'install ok installed'; then
    printf '%s\n' docker-compose-plugin
    return 0
  fi
  if apt-cache show docker-compose-v2 >/dev/null 2>&1; then
    printf '%s\n' docker-compose-v2
    return 0
  fi
  if apt-cache show docker-compose-plugin >/dev/null 2>&1; then
    printf '%s\n' docker-compose-plugin
    return 0
  fi
  return 1
}

docker_enable_backports() {
  local id codename
  # shellcheck disable=SC1091
  . /etc/os-release
  id=${ID:-}
  codename=${VERSION_CODENAME:-}
  [[ "$id" == "debian" && -n "$codename" ]] || return 1
  if [[ ! -f /etc/apt/sources.list.d/xedc-backports.list ]]; then
    printf 'deb http://deb.debian.org/debian %s-backports main\n' "$codename" >/etc/apt/sources.list.d/xedc-backports.list
  fi
  xedc_apt_update
}

docker_install_compose_binary() {
  local arch asset dest
  arch=$(uname -m)
  case "$arch" in
    x86_64|aarch64|armv7) ;;
    armv7l) arch=armv7 ;;
    *) xedc_die "架构 ${arch} 没有对应的 Docker Compose 插件" ;;
  esac
  asset="docker-compose-linux-${arch}"
  if ! xedc_have curl; then
    xedc_apt_install curl ca-certificates || xedc_die "没有 curl，无法下载 Docker Compose 插件"
  fi
  dest=/usr/libexec/docker/cli-plugins/docker-compose
  mkdir -p "$(dirname "$dest")" /usr/lib/docker/cli-plugins
  xedc_log "系统源没有 compose 软件包，改为安装官方插件"
  curl -fsSL "https://github.com/docker/compose/releases/latest/download/${asset}" -o "$dest" \
    || xedc_die "下载 Docker Compose 插件失败"
  chmod 755 "$dest"
  cp -a "$dest" /usr/lib/docker/cli-plugins/docker-compose
}

docker_install_compose() {
  if docker compose version >/dev/null 2>&1; then
    xedc_log "Docker Compose $(docker compose version 2>/dev/null | xedc_first_line)"
    return 0
  fi
  xedc_apt_update
  local pkg=""
  pkg=$(docker_compose_pkg || true)
  if [[ -z "$pkg" ]]; then
    docker_enable_backports || true
    pkg=$(docker_compose_pkg || true)
  fi
  if [[ -n "$pkg" ]]; then
    xedc_apt_install "$pkg" || xedc_die "未能安装 ${pkg}"
    if ! docker compose version >/dev/null 2>&1; then
      apt-get install -y --reinstall "$pkg" || xedc_die "未能重装 ${pkg}"
    fi
  else
    docker_install_compose_binary
  fi
  docker compose version >/dev/null 2>&1 || xedc_die "docker compose version 失败。Ubuntu 需要 docker-compose-v2，Docker 官方源需要 docker-compose-plugin"
  xedc_log "Docker Compose $(docker compose version 2>/dev/null | xedc_first_line)"
}

# 站点 compose 项目引用的卷名 + 这些项目运行中容器挂载的卷。
xedc_docker_site_volumes() {
  local vol proj opt name mounts
  if ! command -v docker >/dev/null 2>&1; then
    return 0
  fi
  docker info >/dev/null 2>&1 || return 0
  declare -A out=()
  shopt -s nullglob
  for opt in /opt/*/compose.yaml /opt/*/docker-compose.yml /opt/*/docker-compose.yaml; do
    [[ -f "$opt" ]] || continue
    proj=$(basename "$(dirname "$opt")")
    if xedc_excluded "$proj" || xedc_excluded "$opt"; then
      continue
    fi
    if docker compose -f "$opt" config --volumes 2>/dev/null; then
      while IFS= read -r vol; do
        [[ -n "$vol" ]] && out["$vol"]=1
      done < <(docker compose -f "$opt" config --volumes 2>/dev/null || true)
    fi
    while IFS= read -r name; do
      [[ -n "$name" ]] || continue
      if xedc_excluded "$name"; then
        continue
      fi
      while IFS= read -r vol; do
        [[ -n "$vol" ]] && out["$vol"]=1
      done < <(docker inspect "$name" --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' 2>/dev/null || true)
    done < <(docker ps -a --filter "label=com.docker.compose.project=${proj}" --format '{{.Names}}' 2>/dev/null || true)
  done
  shopt -u nullglob
  printf '%s\n' "${!out[@]}"
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
  local dir=$1 critical=${2:-0} log reason
  if xedc_excluded "$dir" || xedc_excluded "$(basename "$dir")"; then
    xedc_log "按 exclude.list 不启动 $(basename "$dir")"
    return 0
  fi
  log=$(xedc_mktemp)
  if ! docker_install_compose >"$log" 2>&1; then
    reason=$(cat "$log")
    printf '%s\n' "$reason" >&2
    rm -f "$log"
    xedc_fail_item "没有 docker compose，目录 ${dir}。${reason}"
    if [[ "$critical" == 1 ]]; then
      xedc_die "docker compose version 失败。Ubuntu 需要 docker-compose-v2，Docker 官方源需要 docker-compose-plugin"
    fi
    return 0
  fi
  if ! docker compose version >/dev/null 2>&1; then
    rm -f "$log"
    xedc_fail_item "docker compose version 失败，目录 ${dir}"
    if [[ "$critical" == 1 ]]; then
      xedc_die "docker compose version 失败"
    fi
    return 0
  fi
  if ! docker info >"$log" 2>&1; then
    reason=$(cat "$log")
    xedc_log "Docker 守护进程未运行，容器没有启动: ${dir}"
    printf '%s\n' "$reason" >&2
    rm -f "$log"
    xedc_fail_item "Docker 守护进程未运行，容器没有启动: ${dir}"
    if [[ "$critical" == 1 ]]; then
      xedc_die "Docker 守护进程未运行，容器没有启动。目录 ${dir}"
    fi
    return 0
  fi
  if ! (cd "$dir" && docker compose up -d) >"$log" 2>&1; then
    xedc_log "容器启动失败: ${dir}"
    printf '%s\n' "$(cat "$log")" >&2
    (cd "$dir" && docker compose logs --no-color) >&2 || true
    reason=$(tail -n 20 "$log" | tr '\n' ' ')
    rm -f "$log"
    xedc_fail_item "容器启动失败: ${dir}。${reason}"
    if [[ "$critical" == 1 ]]; then
      xedc_die "容器启动失败: ${dir}"
    fi
    return 0
  fi
  rm -f "$log"
  xedc_log "容器已启动: ${dir}"
}

docker_launch_restored() {
  local src=$1 dir name
  [[ -d "$src/docker" ]] || return 0
  shopt -s nullglob
  for dir in "$src/docker"/*; do
    [[ -d "$dir" ]] || continue
    name=$(basename "$dir")
    if xedc_excluded "$name"; then
      xedc_log "按 exclude.list 不启动 ${name}"
      continue
    fi
    if [[ ! -f "$dir/compose.yaml" && ! -f "$dir/docker-compose.yml" ]]; then
      continue
    fi
    mkdir -p "/opt/${name}"
    cp -a "$dir/." "/opt/${name}/"
    docker_compose_up "/opt/${name}" 0 || xedc_fail_item "容器项目 ${name} 没有启动"
  done
  shopt -u nullglob
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

matomo_launch() {
  xedc_log "Matomo 服务不在恢复范围内"
  if [[ -d /opt/matomo ]]; then
    xedc_log "按 exclude.list 不启动 /opt/matomo"
  fi
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
