#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

node_major() {
  if ! xedc_have node; then
    echo 0
    return 0
  fi
  node -p 'process.versions.node.split(".")[0]'
}

node_install_tarball() {
  local file name dest
  mkdir -p /var/cache/xedc/node
  file=$(curl -fsSL --max-time 60 https://nodejs.org/dist/latest-v20.x/SHASUMS256.txt | awk '/linux-x64\.tar\.xz$/ {print $2; exit}')
  if [[ -z "$file" ]]; then
    xedc_die "无法确定 Node 20 安装包名称"
  fi
  dest="/var/cache/xedc/node/${file}"
  if [[ ! -f "$dest" ]]; then
    curl -fL --max-time 180 -o "$dest" "https://nodejs.org/dist/latest-v20.x/${file}"
  fi
  tar -xJf "$dest" -C /usr/local --strip-components=1
}

node_install() {
  local major
  major=$(node_major)
  if (( major >= 18 )); then
    xedc_log "Node $(node -v) 已满足主题要求，跳过"
    return 0
  fi
  if (( major > 0 )); then
    xedc_warn "Node 版本过低，升级到 20"
  fi
  xedc_apt_install ca-certificates curl xz-utils
  node_install_tarball
  xedc_log "Node $(node -v)"
}

node_configure() { return 0; }
node_enable() { return 0; }
node_verify() {
  node -v >/dev/null
  npm -v >/dev/null
  xedc_log "Node 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) node_install ;;
    configure) node_configure ;;
    enable) node_enable ;;
    verify) node_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
