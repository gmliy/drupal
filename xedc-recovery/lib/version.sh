# shellcheck shell=bash
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "只能 source lib/version.sh" >&2
  exit 1
fi

xedc_version_value() {
  local key=$1 file
  file="${XEDC_ROOT}/VERSION"
  [[ -f "$file" ]] || return 0
  sed -n "s/^${key}=//p" "$file" | awk 'NR==1 { line = $0 } END { if (NR > 0) print line }'
}

xedc_print_version() {
  local ver built
  ver=$(xedc_version_value version)
  built=$(xedc_version_value built)
  printf '%s\n' "工具版本: ${ver:-未知}"
  printf '%s\n' "构建时间: ${built:-未知}"
}

xedc_warn_bootstrap_version() {
  local marker here remote remote_built
  marker="${XEDC_ROOT}/.installed-from"
  [[ -f "$marker" ]] || return 0
  [[ "$(tr -d '[:space:]' <"$marker")" == "r2" ]] || return 0
  here=$(xedc_version_value built)
  [[ -n "$here" ]] || return 0
  remote=$(curl -fsSL --max-time 15 "https://raw.githubusercontent.com/${XEDC_GITHUB_REPO:-gmliy/drupal}/main/xedc-recovery/VERSION" 2>/dev/null || true)
  remote_built=$(printf '%s\n' "$remote" | sed -n 's/^built=//p' | awk 'NR==1 { line = $0 } END { if (NR > 0) print line }')
  [[ -n "$remote_built" ]] || return 0
  if [[ "$here" < "$remote_built" ]]; then
    xedc_warn "R2 bootstrap 的工具版本 ${here} 低于 GitHub main ${remote_built}。请在旧服务器重新备份，或改用 GitHub 方式安装"
  fi
}
