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

xedc_hash_tree() {
  local dir=$1 tmp
  [[ -d "$dir" ]] || { echo none; return 0; }
  tmp=$(xedc_mktemp)
  find "$dir" -type f -print0 | sort -z | xargs -0 sha256sum >"$tmp" 2>/dev/null || true
  sha256sum "$tmp" | awk '{print $1}'
  rm -f "$tmp"
}

# 有 package.json 就重新构建。失败时放回备份里的 build/，并提示。
xedc_theme_rebuild_one() {
  local dir=$1 stash="" rc=0 old="" new=""
  [[ -f "$dir/package.json" ]] || return 0
  if ! grep -q '"build"' "$dir/package.json"; then
    return 0
  fi
  stash=$(xedc_mktemp_d)
  if [[ -d "$dir/build" ]]; then
    cp -a "$dir/build" "$stash/build"
  fi
  (
    cd "$dir" || exit 1
    if [[ -f package-lock.json ]]; then
      npm ci --no-audit --no-fund
    else
      npm install --no-audit --no-fund
    fi
    npm run build
  ) || rc=$?
  if [[ "$rc" != 0 ]]; then
    xedc_warn "主题构建失败，回退使用备份里的编译产物: ${dir}"
    if [[ -d "$stash/build" ]]; then
      rm -rf "$dir/build"
      cp -a "$stash/build" "$dir/build"
    fi
    rm -rf "$stash"
    return 0
  fi
  if [[ -d "$stash/build" && -d "$dir/build" ]]; then
    old=$(xedc_hash_tree "$stash/build")
    new=$(xedc_hash_tree "$dir/build")
    if [[ "$old" == "$new" ]]; then
      xedc_log "主题重新构建产物与备份一致: ${dir}"
    else
      xedc_warn "主题重新构建产物与备份不一致，保留新构建结果: ${dir}"
    fi
  fi
  rm -rf "$stash"
}

xedc_theme_rebuild_all() {
  local root=$1 pkg
  [[ -d "$root" ]] || return 0
  while IFS= read -r pkg; do
    [[ -n "$pkg" ]] || continue
    xedc_theme_rebuild_one "$(dirname "$pkg")"
  done < <(find "$root" -name package.json -not -path '*/node_modules/*')
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
