#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi

modules_php() {
  php "$XEDC_ROOT/lib/php/strip_excluded.php" "$@"
}

modules_keep() {
  [[ "${XEDC_KEEP_MODULES:-0}" == 1 ]]
}

modules_warn_enabled() {
  local site=$1 mod
  [[ -d "$site" ]] || return 0
  while IFS= read -r mod; do
    [[ -n "$mod" ]] || continue
    xedc_warn "模块 ${mod} 恢复时将被卸载"
  done < <(modules_php --site "$site" --mode warn-enabled)
}

modules_strip_composer() {
  local site=$1
  if modules_keep; then
    xedc_log "保留排除清单里的 Drupal 模块，对应服务仍不恢复"
    return 0
  fi
  modules_php --site "$site" --mode composer --report "$XEDC_STATE_DIR/module-report.json" >/dev/null
  xedc_log "已从 composer.json 和 composer.lock 去掉排除清单中的包"
}

modules_cleanup() {
  local site=$1
  local bin mod dep pkg report cnf skip
  local -a mods=() deps=() blocked=() pkgs=()
  if modules_keep; then
    xedc_log "已按 --keep-matomo-module 保留模块，Matomo 服务仍不恢复"
    return 0
  fi
  mkdir -p "$XEDC_STATE_DIR"
  report="$XEDC_STATE_DIR/module-report.json"
  mapfile -t mods < <(modules_php --site "$site" --mode list-modules)
  mapfile -t pkgs < <(modules_php --site "$site" --mode list-packages)
  bin=$(xedc_drush || true)
  for mod in "${mods[@]+"${mods[@]}"}"; do
    [[ -n "$mod" ]] || continue
    mapfile -t deps < <(modules_php --site "$site" --mode dependents --module "$mod")
    if ((${#deps[@]} > 0)); then
      xedc_warn "模块 ${mod} 被其他模块依赖："
      for dep in "${deps[@]}"; do
        [[ -n "$dep" ]] && xedc_log "依赖 ${dep}"
      done
      blocked+=("$mod")
    fi
    if [[ -n "$bin" ]]; then
      if ! "$bin" -r "$site" pm:uninstall "$mod" -y; then
        xedc_fail_item "卸载模块 ${mod} 失败"
        blocked+=("$mod")
      else
        xedc_log "已卸载模块 ${mod}"
      fi
    fi
  done
  if [[ -n "$bin" ]]; then
    for mod in "${mods[@]+"${mods[@]}"}"; do
      [[ -n "$mod" ]] || continue
      "$bin" -r "$site" config:delete "${mod}.settings" >/dev/null 2>&1 || true
    done
  fi
  export COMPOSER_ALLOW_SUPERUSER=1
  local -a keep_pkgs=()
  for pkg in "${pkgs[@]+"${pkgs[@]}"}"; do
    [[ -n "$pkg" ]] || continue
    skip=0
    for mod in "${blocked[@]+"${blocked[@]}"}"; do
      if [[ "$pkg" == *"/${mod}" || "$pkg" == *"${mod}"* ]]; then
        skip=1
      fi
    done
    if [[ "$skip" == 1 ]]; then
      keep_pkgs+=("$pkg")
      xedc_fail_item "因依赖未删除 composer 包 ${pkg}"
      continue
    fi
    if command -v composer >/dev/null 2>&1; then
      if ! composer remove --no-interaction --no-update --working-dir="$site" "$pkg"; then
        xedc_warn "composer remove ${pkg} 未完成，改为直接修改 composer.json 与 lock"
      else
        xedc_log "已执行 composer remove ${pkg}"
      fi
    fi
  done
  if ((${#keep_pkgs[@]} > 0)); then
    XEDC_KEEP_PACKAGES=$(IFS=,; printf '%s' "${keep_pkgs[*]}")
    export XEDC_KEEP_PACKAGES
  else
    unset XEDC_KEEP_PACKAGES
  fi
  cnf=$(mktemp)
  chmod 600 "$cnf"
  if [[ -n "${DB_USER:-}" && -n "${DB_PASS:-}" && -n "${DB_NAME:-}" ]]; then
    cat >"$cnf" <<EOF
[client]
user=${DB_USER}
password=${DB_PASS}
host=${DB_HOST:-localhost}
port=${DB_PORT:-3306}
database=${DB_NAME}
EOF
    modules_php --site "$site" --mode all --cnf "$cnf" --database "$DB_NAME" --report "$report" >/dev/null
  else
    modules_php --site "$site" --mode all --report "$report" >/dev/null
  fi
  rm -f "$cnf"
  unset XEDC_KEEP_PACKAGES
  local files=0 lines=0
  if [[ -f "$report" ]]; then
    files=$(php -r '$j=json_decode((string) file_get_contents($argv[1]), true); echo count($j["files"] ?? []);' "$report" 2>/dev/null || echo 0)
    lines=$(php -r '$j=json_decode((string) file_get_contents($argv[1]), true); echo (int) ($j["lines"] ?? 0);' "$report" 2>/dev/null || echo 0)
    php -r '$j=json_decode((string) file_get_contents($argv[1]), true); foreach (($j["code_refs"] ?? []) as $r) { echo $r["file"], ":", $r["line"], "\n"; }' "$report" >"$XEDC_STATE_DIR/module-code-refs.txt" || true
  fi
  xedc_log "已删除配置文件 ${files} 个，清理配置行 ${lines} 行"
  if [[ -s "$XEDC_STATE_DIR/module-code-refs.txt" ]]; then
    xedc_warn "主题或自定义模块里有排除服务的硬编码，未改代码。见 ${XEDC_STATE_DIR}/module-code-refs.txt"
    if declare -F note_manual >/dev/null 2>&1; then
      note_manual "硬编码引用见 ${XEDC_STATE_DIR}/module-code-refs.txt（未改代码）"
    fi
  fi
  if ((${#mods[@]} > 0)); then
    printf '%s\n' "${mods[@]}" >"$XEDC_STATE_DIR/removed-modules.txt"
  else
    : >"$XEDC_STATE_DIR/removed-modules.txt"
  fi
}

modules_assert_disabled() {
  local site=$1 bin
  if modules_keep; then
    return 0
  fi
  if ! modules_php --site "$site" --mode assert-disabled; then
    xedc_fail_item "配置导入之后，排除清单中的模块仍写在 core.extension.yml"
    return 1
  fi
  bin=$(xedc_drush || true)
  if [[ -n "$bin" ]]; then
    if "$bin" -r "$site" pm:list --status=enabled 2>/dev/null | grep -E 'matomo.*Enabled|Enabled.*matomo|piwik.*Enabled' >/dev/null; then
      xedc_fail_item "drush pm:list 仍显示排除模块已启用"
      return 1
    fi
  fi
  xedc_log "排除模块在配置导入之后仍是未启用"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "模块清理函数库" >&2
  exit 0
fi
