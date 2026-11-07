# shellcheck shell=bash
# 公共函数。只能被 source。
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "只能 source lib/common.sh" >&2
  exit 1
fi
if [[ -n "${XEDC_COMMON_LOADED:-}" ]]; then
  return 0
fi
XEDC_COMMON_LOADED=1

set -euo pipefail

if [[ -z "${XEDC_ROOT:-}" ]]; then
  _xedc_caller="${BASH_SOURCE[1]:-${BASH_SOURCE[0]}}"
  _xedc_dir=$(cd "$(dirname "$_xedc_caller")" && pwd)
  case "$(basename "$_xedc_dir")" in
    lib) XEDC_ROOT=$(cd "$_xedc_dir/.." && pwd) ;;
    php) XEDC_ROOT=$(cd "$_xedc_dir/../.." && pwd) ;;
    *) XEDC_ROOT=$_xedc_dir ;;
  esac
  unset _xedc_caller _xedc_dir
fi
export XEDC_ROOT

XEDC_STATE_DIR="${XEDC_STATE_DIR:-/var/lib/xedc-recovery}"
XEDC_BACKUP_DIR="${XEDC_BACKUP_DIR:-/var/backups/xedc}"
XEDC_R2_ENV="${XEDC_R2_ENV:-/root/.config/xedc-recovery/r2.env}"
XEDC_BACKUP_LOG="${XEDC_BACKUP_LOG:-/var/log/xedc-backup.log}"
XEDC_RESTORE_LOG="${XEDC_RESTORE_LOG:-/var/log/xedc-restore.log}"

xedc_log() {
  local line
  line="$(date -u '+%H:%M:%S') $*"
  printf '%s\n' "$line" >&2
  # tmux 里标准错误已经重定向到日志，再追加一次会让同一行出现两遍。
  if [[ -n "${XEDC_LOG_FILE:-}" && -z "${XEDC_LOG_MIRROR:-}" ]]; then
    printf '%s\n' "$line" >>"$XEDC_LOG_FILE"
  fi
}

xedc_log_text() {
  local line
  while IFS= read -r line; do
    xedc_log "$line"
  done
}

xedc_warn() {
  xedc_log "警告: $*"
}

xedc_die() {
  xedc_log "失败: $*"
  exit 1
}

xedc_require_root() {
  if [[ "$(id -u)" -ne 0 ]]; then
    xedc_die "需要 root 运行"
  fi
}

xedc_have() {
  command -v "$1" >/dev/null 2>&1
}

xedc_mysqldump() {
  if xedc_have mysqldump; then
    mysqldump "$@"
  elif xedc_have mariadb-dump; then
    mariadb-dump "$@"
  else
    xedc_die "没有 mysqldump 或 mariadb-dump"
  fi
}

xedc_apt_env() {
  export DEBIAN_FRONTEND=noninteractive
  export NEEDRESTART_MODE=a
  export APT_LISTCHANGES_FRONTEND=none
  export UCF_FORCE_CONFFOLD=1
  if [[ "${XEDC_TEST:-0}" == 1 || ! -d /run/systemd/system ]]; then
    if [[ ! -x /usr/sbin/policy-rc.d ]]; then
      printf '%s\n' '#!/bin/sh' 'exit 101' >/usr/sbin/policy-rc.d
      chmod 755 /usr/sbin/policy-rc.d
    fi
  fi
  mkdir -p /etc/apt/apt.conf.d
  cat >/etc/apt/apt.conf.d/99xedc <<'EOF'
Acquire::http::Timeout "30";
Acquire::https::Timeout "30";
Acquire::Retries "3";
Dpkg::Options { "--force-confdef"; "--force-confold"; };
APT::Get::Assume-Yes "true";
EOF
}

xedc_apt_update() {
  xedc_apt_env
  mkdir -p "$XEDC_STATE_DIR"
  local stamp="$XEDC_STATE_DIR/apt-updated"
  if [[ -f "$stamp" ]]; then
    local now mtime age
    now=$(date +%s)
    mtime=$(stat -c %Y "$stamp")
    age=$((now - mtime))
    if (( age < 1800 )); then
      return 0
    fi
  fi
  apt-get update -y
  touch "$stamp"
}

xedc_apt_install() {
  xedc_apt_update
  apt-get install -y --no-install-recommends "$@"
}

xedc_mem_mb() {
  local host cgroup
  host=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
  cgroup=""
  if [[ -f /sys/fs/cgroup/memory.max ]]; then
    cgroup=$(cat /sys/fs/cgroup/memory.max)
  elif [[ -f /sys/fs/cgroup/memory/memory.limit_in_bytes ]]; then
    cgroup=$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes)
  fi
  if [[ -n "$cgroup" && "$cgroup" != "max" && "$cgroup" =~ ^[0-9]+$ ]]; then
    local mb=$((cgroup / 1024 / 1024))
    if (( mb > 128 && mb < host )); then
      echo "$mb"
      return 0
    fi
  fi
  echo "$host"
}

xedc_write_tune() {
  local mb
  mb=$(xedc_mem_mb)
  local memory_limit children start spare innodb connections redis opcache perf
  if (( mb <= 1200 )); then
    memory_limit=192M
    children=2
    start=1
    spare=1
    innodb=128M
    connections=30
    redis=64mb
    opcache=64
    perf=OFF
  elif (( mb <= 2200 )); then
    memory_limit=256M
    children=3
    start=1
    spare=2
    innodb=256M
    connections=50
    redis=96mb
    opcache=64
    perf=OFF
  elif (( mb <= 4500 )); then
    memory_limit=384M
    children=5
    start=2
    spare=3
    innodb=512M
    connections=80
    redis=128mb
    opcache=128
    perf=OFF
  else
    memory_limit=512M
    children=8
    start=2
    spare=3
    innodb=1G
    connections=150
    redis=256mb
    opcache=192
    perf=ON
  fi
  mkdir -p "$XEDC_STATE_DIR"
  cat >"$XEDC_STATE_DIR/tune.env" <<EOF
MEMORY_MB=$mb
MEMORY_LIMIT=$memory_limit
PM_MAX_CHILDREN=$children
PM_START=$start
PM_SPARE=$spare
INNODB_BUFFER=$innodb
MAX_CONNECTIONS=$connections
REDIS_MAXMEMORY=$redis
OPCACHE_MEMORY=$opcache
PERFORMANCE_SCHEMA=$perf
EOF
  # shellcheck disable=SC1090
  source "$XEDC_STATE_DIR/tune.env"
  xedc_log "内存 ${mb}MB，MariaDB ${innodb}，PHP-FPM 子进程 ${children}，Redis ${redis}"
}

xedc_load_tune() {
  if [[ ! -f "$XEDC_STATE_DIR/tune.env" ]]; then
    xedc_write_tune
  fi
  # shellcheck disable=SC1090
  source "$XEDC_STATE_DIR/tune.env"
}

xedc_os() {
  # shellcheck disable=SC1091
  . /etc/os-release
  printf '%s %s %s\n' "$ID" "${VERSION_ID:-}" "${VERSION_CODENAME:-}"
}

xedc_systemd() {
  [[ -d /run/systemd/system ]]
}

xedc_svc_diag() {
  local name=$1
  xedc_log "服务 ${name} 没有启动"
  if xedc_systemd; then
    xedc_log "systemctl status ${name}"
    systemctl status "$name" --no-pager -l >&2 || true
    xedc_log "journalctl -xeu ${name} -n 30"
    journalctl -xeu "$name" -n 30 --no-pager >&2 || true
    return 0
  fi
  if xedc_have service; then
    service "$name" status >&2 || true
  fi
}

xedc_svc() {
  local name=$1
  if xedc_systemd; then
    systemctl enable "$name" >/dev/null 2>&1 || true
    if systemctl restart "$name"; then
      return 0
    fi
    xedc_svc_diag "$name"
    return 1
  fi
  if xedc_have service; then
    if service "$name" restart || service "$name" start; then
      return 0
    fi
    xedc_svc_diag "$name"
    return 1
  fi
  return 1
}

xedc_render() {
  local src=$1 dest=$2
  php "$XEDC_ROOT/lib/php/render.php" "$src" "$dest"
}

xedc_php() {
  if [[ -x /usr/bin/php ]]; then
    echo /usr/bin/php
  elif xedc_have php; then
    command -v php
  else
    return 1
  fi
}

xedc_manifest_get() {
  local file=$1 expr=$2
  php "$XEDC_ROOT/lib/php/manifest_get.php" "$file" "$expr"
}

xedc_backup_perms() {
  mkdir -p "$XEDC_BACKUP_DIR"
  chmod 700 "$XEDC_BACKUP_DIR"
  local f
  shopt -s nullglob
  for f in "$XEDC_BACKUP_DIR"/drupal-backup-*; do
    chmod 600 "$f" || true
  done
  shopt -u nullglob
}

xedc_load_r2_env() {
  if [[ -f "$XEDC_R2_ENV" ]]; then
    # shellcheck disable=SC1090
    set -a
    # shellcheck disable=SC1090
    source "$XEDC_R2_ENV"
    set +a
  fi
  if [[ -z "${R2_ENDPOINT:-}" && -n "${R2_ACCOUNT_ID:-}" ]]; then
    R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
  fi
  if [[ -n "${R2_PREFIX:-}" && "${R2_PREFIX}" != */ ]]; then
    R2_PREFIX="${R2_PREFIX}/"
  fi
  R2_PREFIX="${R2_PREFIX:-xedc/}"
}

xedc_r2_ready() {
  [[ -n "${R2_ACCESS_KEY_ID:-}" && -n "${R2_SECRET_ACCESS_KEY:-}" && -n "${R2_BUCKET:-}" && -n "${R2_ENDPOINT:-}" ]]
}

xedc_as_www() {
  if xedc_have runuser; then
    runuser -u www-data -- "$@"
  else
    local cmd
    cmd=$(printf '%q ' "$@")
    su -s /bin/bash www-data -c "$cmd"
  fi
}

xedc_drush() {
  local site=${XEDC_SITE_ROOT:-/var/www/drupal}
  if [[ -x "$site/vendor/bin/drush" ]]; then
    echo "$site/vendor/bin/drush"
  elif [[ -x "$site/bin/drush" ]]; then
    echo "$site/bin/drush"
  elif xedc_have drush; then
    command -v drush
  else
    return 1
  fi
}

xedc_step_dir() {
  echo "${XEDC_STATE_DIR}/steps"
}

xedc_step_done() {
  [[ -f "$(xedc_step_dir)/$1.done" ]]
}

xedc_step_mark() {
  mkdir -p "$(xedc_step_dir)"
  touch "$(xedc_step_dir)/$1.done"
}

xedc_step_clear() {
  rm -rf "$(xedc_step_dir)"
  mkdir -p "$(xedc_step_dir)"
}

xedc_fail_inject() {
  local name=$1
  if [[ "${XEDC_FAIL_STEP:-}" == "$name" && ! -f "$(xedc_step_dir)/failed-$name" ]]; then
    mkdir -p "$(xedc_step_dir)"
    touch "$(xedc_step_dir)/failed-$name"
    xedc_die "步骤 ${name} 失败。日志：${XEDC_RESTORE_LOG}。继续：加上 --resume 再执行同一条命令"
  fi
}

xedc_restore_skip() {
  local name=$1
  if declare -F xedc_excluded >/dev/null 2>&1 && xedc_excluded "$name"; then
    return 0
  fi
  if [[ -n "${XEDC_MANIFEST:-}" && -f ${XEDC_MANIFEST} ]]; then
    if php -r '
      $j = json_decode((string) file_get_contents($argv[1]), true);
      if (!is_array($j)) {
        exit(1);
      }
      foreach ($j["restore_skip"] ?? [] as $item) {
        $n = is_array($item) ? (string) ($item["name"] ?? "") : (string) $item;
        if ($n === $argv[2]) {
          exit(0);
        }
      }
      exit(1);
    ' "$XEDC_MANIFEST" "$name"; then
      return 0
    fi
  fi
  return 1
}

xedc_is_git_push_file() {
  local file=$1
  [[ -f "$file" ]] || return 1
  grep -Eq 'git[[:space:]]+push|xedc-push-gmliy' "$file"
}

xedc_unit_has_install() {
  local file=$1
  [[ -f "$file" ]] || return 1
  grep -Eq '^\[Install\][[:space:]]*$' "$file"
}

xedc_is_worker_unit() {
  [[ "$1" == *worker* ]]
}

xedc_recorded_perm() {
  local path=$1 line
  [[ -n "${XEDC_PERMS_FILE:-}" && -f "$XEDC_PERMS_FILE" ]] || return 1
  line=$(awk -F '\t' -v p="$path" '$1 == p { print $2, $3, $4; exit }' "$XEDC_PERMS_FILE")
  [[ -n "$line" ]] || return 1
  printf '%s\n' "$line"
}

xedc_default_perm() {
  local path=$1
  local user=root group=root mode=0644
  if [[ -d "$path" ]]; then
    mode=0755
  fi
  case "$path" in
    /etc/redis|/etc/redis/*)
      if [[ -d "$path" ]]; then
        user=root
        group=root
        mode=0755
      else
        user=redis
        group=redis
        mode=0640
      fi
      ;;
    /etc/mysql/debian.cnf|/etc/mysql/debian-start)
      mode=0600
      ;;
    /etc/mysql|/etc/mysql/*)
      if [[ -d "$path" ]]; then
        mode=0755
      else
        user=mysql
        group=mysql
        mode=0640
      fi
      ;;
    /etc/php/*)
      if [[ -d "$path" ]]; then
        mode=0755
      else
        user=root
        group=www-data
        mode=0640
      fi
      ;;
    /etc/nginx/*)
      if [[ -d "$path" ]]; then
        mode=0755
      else
        user=root
        group=www-data
        mode=0640
      fi
      ;;
    /etc/netdata|/etc/netdata/*|/opt/netdata/etc/netdata|/opt/netdata/etc/netdata/*)
      if [[ -d "$path" ]]; then
        mode=0755
      else
        user=root
        group=netdata
        mode=0640
      fi
      ;;
    /etc/ssl/xedc/*.key)
      mode=0600
      ;;
    *.env|*/.env)
      mode=0600
      ;;
    */settings.php)
      user=root
      group=www-data
      mode=0640
      ;;
  esac
  printf '%s %s %s\n' "$mode" "$user" "$group"
}

xedc_service_path() {
  local path=$1
  case "$path" in
    /etc/redis|/etc/redis/*|/etc/mysql|/etc/mysql/*|/etc/php|/etc/php/*|/etc/nginx|/etc/nginx/*|/etc/netdata|/etc/netdata/*|/opt/netdata/etc/netdata|/opt/netdata/etc/netdata/*)
      return 0
      ;;
  esac
  return 1
}

xedc_apply_perms() {
  local path=$1 spec mode user group
  [[ -e "$path" ]] || return 0
  # 服务配置不沿用旧机器记下来的权限。umask 077 会把它们写成 root 的 0600，服务用户读不到。
  if xedc_service_path "$path"; then
    spec=$(xedc_default_perm "$path")
  elif spec=$(xedc_recorded_perm "$path"); then
    :
  else
    spec=$(xedc_default_perm "$path")
  fi
  read -r mode user group <<<"$spec"
  local fallback=0
  if ! id "$user" >/dev/null 2>&1; then
    user=root
    fallback=1
  fi
  if ! getent group "$group" >/dev/null 2>&1; then
    group=root
    fallback=1
  fi
  if [[ "$fallback" == 1 ]]; then
    if [[ -d "$path" ]]; then
      mode=0755
    else
      mode=0644
    fi
  fi
  chown "$user:$group" "$path"
  chmod "$mode" "$path"
  if [[ -d "$path" ]]; then
    # 目录上原有的 setgid，数字模式清不掉。2770 再 chmod 0755 会变成 2755。
    chmod a-s "$path"
  fi
}

xedc_apply_tree() {
  local dir=$1 path
  [[ -d "$dir" ]] || return 0
  while IFS= read -r -d '' path; do
    xedc_apply_perms "$path"
  done < <(find "$dir" -print0)
}

xedc_prepare_dir() {
  mkdir -p "$1"
  xedc_apply_perms "$1"
}

xedc_dump_perms() {
  local root=$1 path mode user group
  [[ -d "$root" ]] || return 0
  while IFS= read -r -d '' path; do
    mode=$(stat -c '%a' "$path")
    user=$(stat -c '%U' "$path")
    group=$(stat -c '%G' "$path")
    printf '%s\t%s\t%s\t%s\n' "$path" "$mode" "$user" "$group"
  done < <(find "$root" -print0)
}

xedc_fix_perm_chain() {
  local path=$1
  local dir
  xedc_apply_perms "$path"
  dir=$(dirname "$path")
  while [[ "$dir" != "/" && "$dir" != "." ]]; do
    if xedc_service_path "$dir"; then
      xedc_apply_perms "$dir"
    fi
    dir=$(dirname "$dir")
  done
}

xedc_assert_readable() {
  local user=$1 file=$2 try
  [[ -e "$file" ]] || xedc_die "配置不存在: ${file}"
  for try in 1 2; do
    if id "$user" >/dev/null 2>&1 && runuser -u "$user" -- test -r "$file"; then
      return 0
    fi
    xedc_log "用户 ${user} 无法读取 ${file}"
    if xedc_have namei; then
      namei -l "$file" >&2 || true
    else
      ls -ld "$file" "$(dirname "$file")" >&2 || true
    fi
    if [[ "$try" == 2 ]]; then
      xedc_die "用户 ${user} 无法读取 ${file}"
    fi
    xedc_log "已按服务用户重设 ${file} 的权限并重试"
    xedc_fix_perm_chain "$file"
  done
}

xedc_file_hash() {
  sha256sum "$1" | awk '{print $1}'
}

xedc_hash_db() {
  printf '%s\n' "${XEDC_STATE_DIR}/written.sha256"
}

xedc_remember_hash() {
  local path=$1 hash db tmp
  [[ -f "$path" ]] || return 0
  hash=$(xedc_file_hash "$path")
  mkdir -p "$XEDC_STATE_DIR"
  db=$(xedc_hash_db)
  tmp=$(mktemp)
  if [[ -f "$db" ]]; then
    awk -v p="$path" '$1 != p { print }' "$db" >"$tmp" || true
  fi
  printf '%s %s\n' "$path" "$hash" >>"$tmp"
  mv "$tmp" "$db"
  chmod 600 "$db"
}

xedc_user_modified() {
  local path=$1 stored="" db
  [[ -f "$path" ]] || return 1
  db=$(xedc_hash_db)
  [[ -f "$db" ]] || return 1
  stored=$(awk -v p="$path" '$1 == p { print $2; exit }' "$db")
  [[ -n "$stored" ]] || return 1
  [[ "$(xedc_file_hash "$path")" != "$stored" ]]
}

xedc_keep_manual() {
  local path=$1
  if xedc_user_modified "$path"; then
    xedc_warn "保留手动修改的配置 ${path}"
    xedc_apply_perms "$path"
    return 0
  fi
  return 1
}

xedc_ipv6() {
  [[ -f /proc/net/if_inet6 ]]
}

xedc_confirm() {
  local prompt=$1
  if [[ "${XEDC_YES:-0}" == 1 ]]; then
    xedc_log "已使用 --yes，跳过确认"
    return 0
  fi
  if [[ -n "${XEDC_INSIDE_TMUX:-}" || ! -t 0 ]]; then
    xedc_die "当前不是交互终端。确认安装请在命令末尾加 --yes。日志: ${XEDC_RESTORE_LOG}。查看: cat ${XEDC_RESTORE_LOG}。继续请加上 --resume"
  fi
  local answer
  read -r -p "$prompt" answer || xedc_die "无法读取确认。请在命令末尾加 --yes。日志: ${XEDC_RESTORE_LOG}"
  case "$answer" in
    y|Y|yes|YES) return 0 ;;
    *) xedc_die "未确认，已停止" ;;
  esac
}

xedc_site_root() {
  if [[ -n "${XEDC_SITE_ROOT:-}" && -f "${XEDC_SITE_ROOT}/web/sites/default/settings.php" ]]; then
    echo "$XEDC_SITE_ROOT"
    return 0
  fi
  if [[ -f /var/www/drupal/web/sites/default/settings.php ]]; then
    echo /var/www/drupal
    return 0
  fi
  local found
  found=$(find /var/www /srv /opt -path '*/web/sites/default/settings.php' -print -quit 2>/dev/null || true)
  if [[ -n "$found" ]]; then
    dirname "$(dirname "$(dirname "$(dirname "$found")")")"
    return 0
  fi
  return 1
}

# shellcheck source=exclude.sh
source "$(dirname "${BASH_SOURCE[0]}")/exclude.sh"
