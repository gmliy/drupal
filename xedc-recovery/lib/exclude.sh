# shellcheck shell=bash
# 排除清单。由 common.sh 在加载末尾 source。
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "只能 source lib/exclude.sh" >&2
  exit 1
fi
if [[ -n "${XEDC_EXCLUDE_LOADED:-}" ]]; then
  return 0
fi
XEDC_EXCLUDE_LOADED=1

xedc_exclude_file() {
  if [[ -n "${XEDC_EXCLUDE_FILE:-}" ]]; then
    printf '%s\n' "$XEDC_EXCLUDE_FILE"
    return 0
  fi
  printf '%s\n' "${XEDC_ROOT}/exclude.list"
}

xedc_exclude_rules() {
  local file line
  file=$(xedc_exclude_file)
  [[ -f "$file" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    line=${line%%#*}
    line=${line#"${line%%[![:space:]]*}"}
    line=${line%"${line##*[![:space:]]}"}
    [[ -n "$line" ]] || continue
    printf '%s\n' "$line"
  done <"$file"
}

xedc_exclude_glob() {
  local name=$1 pattern=$2 base
  [[ -n "$name" && -n "$pattern" ]] || return 1
  if [[ "$pattern" != *"*"* && "$pattern" != *"?"* ]]; then
    [[ "$name" == *"$pattern"* ]]
    return
  fi
  base=$(basename "$name")
  case "$name" in
    $pattern) return 0 ;;
  esac
  case "$base" in
    $pattern) return 0 ;;
  esac
  return 1
}

xedc_excluded_glob() {
  xedc_exclude_glob "$@"
}

xedc_excluded() {
  local name=$1 rule
  [[ -n "$name" ]] || return 1
  while IFS= read -r rule; do
    [[ -n "$rule" ]] || continue
    if xedc_exclude_glob "$name" "$rule"; then
      return 0
    fi
  done < <(xedc_exclude_rules)
  return 1
}

# 相对路径（如 code/docs-archive/foo）或绝对路径。
xedc_excluded_path() {
  local path=$1 rel base
  [[ -n "$path" ]] || return 1
  base=$(basename "$path")
  if xedc_excluded "$path" || xedc_excluded "$base"; then
    return 0
  fi
  rel=${path#/}
  while IFS= read -r rule; do
    [[ -n "$rule" ]] || continue
    if xedc_exclude_glob "$rel" "$rule" || xedc_exclude_glob "$path" "$rule"; then
      return 0
    fi
    if [[ "$rel" == "$rule"/* ]] || [[ "$rel" == "$rule" ]]; then
      return 0
    fi
  done < <(xedc_exclude_rules)
  return 1
}

xedc_backup_skip_logged() {
  local what=$1 bytes=${2:-0}
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\t%s\n' "$what" "${bytes:-0}" >>"${XEDC_STATE_DIR}/backup-skipped.tsv"
  if [[ "$bytes" =~ ^[0-9]+$ && "$bytes" -gt 0 ]]; then
    xedc_log "备份已排除: ${what}（约 ${bytes} 字节）"
  else
    xedc_log "备份已排除: ${what}"
  fi
}

xedc_conf_excluded() {
  local file=$1 name
  [[ -n "$file" ]] || return 1
  if xedc_excluded "$file" || xedc_excluded "$(basename "$file")"; then
    return 0
  fi
  [[ -f "$file" ]] || return 1
  while IFS= read -r name; do
    [[ -n "$name" ]] || continue
    if xedc_excluded "$name"; then
      return 0
    fi
  done < <(awk '{
    for (i = 1; i <= NF; i++) {
      if ($i == "server_name") {
        for (j = i + 1; j <= NF; j++) {
          gsub(/[{;]/, "", $j)
          if ($j != "" && $j != "}") print $j
        }
      }
    }
  }' "$file")
  return 1
}

xedc_log_exclude_list() {
  local rule file
  file=$(xedc_exclude_file)
  xedc_log "排除清单 ${file}"
  while IFS= read -r rule; do
    [[ -n "$rule" ]] && xedc_log "排除: ${rule}"
  done < <(xedc_exclude_rules)
}

xedc_log_test_cache_volumes() {
  command -v docker >/dev/null 2>&1 || return 0
  docker info >/dev/null 2>&1 || return 0
  local vol
  while IFS= read -r vol; do
    [[ -n "$vol" ]] || continue
    if xedc_excluded "$vol"; then
      xedc_log "发现测试/缓存卷 ${vol}，备份时将排除"
    fi
  done < <(docker volume ls -q 2>/dev/null || true)
}

xedc_fail_item() {
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\n' "$*" >>"$XEDC_STATE_DIR/failures.txt"
  xedc_warn "失败项: $*"
}

xedc_excluded_residue_list() {
  local path name
  shopt -s nullglob
  for path in /opt/* /etc/nginx/conf.d/* /etc/nginx/sites-available/* /etc/nginx/sites-enabled/* \
    /etc/systemd/system/* /etc/cron.d/* /usr/local/sbin/* /usr/local/bin/*; do
    [[ -e "$path" ]] || continue
    name=$(basename "$path")
    if xedc_conf_excluded "$path" || xedc_excluded "$name"; then
      printf '%s\n' "$path"
    fi
  done
  shopt -u nullglob
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    while IFS= read -r name; do
      [[ -n "$name" ]] || continue
      if xedc_excluded "$name"; then
        printf 'container %s\n' "$name"
      fi
    done < <(docker ps -a --format '{{.Names}}' 2>/dev/null || true)
    while IFS= read -r name; do
      [[ -n "$name" ]] || continue
      if xedc_excluded "$name"; then
        printf 'volume %s\n' "$name"
      fi
    done < <(docker volume ls -q 2>/dev/null || true)
    while IFS= read -r name; do
      [[ -n "$name" ]] || continue
      if xedc_excluded "$name"; then
        printf 'network %s\n' "$name"
      fi
    done < <(docker network ls --format '{{.Name}}' 2>/dev/null || true)
  fi
}

xedc_clean_excluded_print() {
  local line any=0
  xedc_log "排除清单残留（只打印，不删除）："
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    any=1
    printf '%s\n' "$line"
    xedc_log "残留 ${line}"
  done < <(xedc_excluded_residue_list)
  if [[ "$any" == 0 ]]; then
    xedc_log "没有排除清单对应的残留"
  fi
}

xedc_clean_excluded_apply() {
  local line path name
  xedc_log "开始清理排除清单残留"
  shopt -s nullglob
  for path in /opt/*; do
    [[ -d "$path" ]] || continue
    if ! xedc_excluded "$(basename "$path")"; then
      continue
    fi
    if [[ -f "$path/compose.yaml" || -f "$path/docker-compose.yml" ]]; then
      if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        (cd "$path" && docker compose down -v) >/dev/null 2>&1 || xedc_warn "compose down 未完成: ${path}"
      fi
    fi
    rm -rf "$path"
    xedc_log "已删除 ${path}"
  done
  shopt -u nullglob
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    case "$line" in
      container\ *)
        name=${line#container }
        docker rm -f "$name" >/dev/null 2>&1 || true
        xedc_log "已删除容器 ${name}"
        ;;
      volume\ *)
        name=${line#volume }
        docker volume rm "$name" >/dev/null 2>&1 || true
        xedc_log "已删除数据卷 ${name}"
        ;;
      network\ *)
        name=${line#network }
        docker network rm "$name" >/dev/null 2>&1 || true
        xedc_log "已删除网络 ${name}"
        ;;
      *)
        if [[ -f "$line" && "$line" == /etc/systemd/system/* ]]; then
          name=$(basename "$line")
          systemctl disable --now "$name" >/dev/null 2>&1 || true
        fi
        if [[ -f "$line" && "$line" == /etc/nginx/* ]]; then
          local cert
          while IFS= read -r cert; do
            [[ -n "$cert" && -e "$cert" ]] || continue
            if [[ "$cert" == /etc/letsencrypt/live/* ]]; then
              rm -rf "$(dirname "$cert")"
            fi
          done < <(awk '$1 == "ssl_certificate" || $1 == "ssl_certificate_key" { gsub(/;/, "", $2); print $2 }' "$line")
        fi
        rm -rf "$line"
        xedc_log "已删除 ${line}"
        ;;
    esac
  done < <(xedc_excluded_residue_list)
  xedc_log "排除清单残留已清理"
}
