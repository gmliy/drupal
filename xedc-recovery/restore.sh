#!/usr/bin/env bash
# 在空服务器上恢复 Drupal 备份。
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib/common.sh
source "$ROOT/lib/common.sh"
# shellcheck source=lib/r2.sh
source "$ROOT/lib/r2.sh"
# shellcheck source=lib/rclone.sh
source "$ROOT/lib/rclone.sh"
# shellcheck source=lib/system.sh
source "$ROOT/lib/system.sh"
# shellcheck source=lib/php.sh
source "$ROOT/lib/php.sh"
# shellcheck source=lib/nginx.sh
source "$ROOT/lib/nginx.sh"
# shellcheck source=lib/mariadb.sh
source "$ROOT/lib/mariadb.sh"
# shellcheck source=lib/redis.sh
source "$ROOT/lib/redis.sh"
# shellcheck source=lib/composer.sh
source "$ROOT/lib/composer.sh"
# shellcheck source=lib/drush.sh
source "$ROOT/lib/drush.sh"
# shellcheck source=lib/node.sh
source "$ROOT/lib/node.sh"
# shellcheck source=lib/ffmpeg.sh
source "$ROOT/lib/ffmpeg.sh"
# shellcheck source=lib/docker.sh
source "$ROOT/lib/docker.sh"
# shellcheck source=lib/netdata.sh
source "$ROOT/lib/netdata.sh"
# shellcheck source=lib/certbot.sh
source "$ROOT/lib/certbot.sh"

ORIGINAL_ARGS=("$@")
FROM=""
DOMAIN=""
OLD_DOMAIN=""
WWW=""
HTTPS="auto"
EMAIL=""
FORCE=0
VERIFY=0
RESUME=0
LIST_LOCAL=0
LIST_R2=0

usage() {
  cat <<'EOF'
用法: restore.sh --from local:/路径/包.tar.zst|--from r2:latest|r2:latest-1|r2:时间
  --domain 域名 [--old-domain 旧域名] [--www yes|no] [--https auto|self|off]
  [--email 邮箱] [--php 8.5] [--yes] [--force] [--verify] [--resume] [--list-local] [--list-r2]
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --from) FROM=${2:-}; shift 2 ;;
    --domain) DOMAIN=${2:-}; shift 2 ;;
    --old-domain) OLD_DOMAIN=${2:-}; shift 2 ;;
    --www) WWW=${2:-}; shift 2 ;;
    --https) HTTPS=${2:-}; shift 2 ;;
    --email) EMAIL=${2:-}; shift 2 ;;
    --php) XEDC_PHP_VERSION=${2:-}; shift 2 ;;
    --yes) XEDC_YES=1; shift ;;
    --force) FORCE=1; shift ;;
    --verify) VERIFY=1; shift ;;
    --resume) RESUME=1; shift ;;
    --list-local) LIST_LOCAL=1; shift ;;
    --list-r2) LIST_R2=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) xedc_die "未知参数: $1" ;;
  esac
done
export XEDC_YES=${XEDC_YES:-0}
if [[ -n "${XEDC_PHP_VERSION:-}" && ! "${XEDC_PHP_VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
  xedc_die "--php 需要 8.5 这种写法"
fi
export XEDC_PHP_VERSION="${XEDC_PHP_VERSION:-}"

list_local() {
  xedc_backup_perms
  local f base
  shopt -s nullglob
  for f in "$XEDC_BACKUP_DIR"/drupal-backup-*.tar.zst; do
    base=$(basename "$f" .tar.zst)
    if [[ -f "$XEDC_BACKUP_DIR/${base}.complete" ]]; then
      echo "$(xedc_backup_stamp_time "$base") $(du -h "$f" | awk '{print $1}') 完整 $f"
    else
      echo "$(xedc_backup_stamp_time "$base") 不完整 $f"
    fi
  done
  shopt -u nullglob
}

list_r2() {
  xedc_load_r2_env
  if ! xedc_r2_ready; then
    xedc_die "没有 R2 凭据，不能列出远端备份"
  fi
  local conf
  conf=$(xedc_rclone_conf)
  local -a complete=() all=()
  mapfile -t complete < <(xedc_r2_stamps "$conf")
  mapfile -t all < <(xedc_r2_all_archives "$conf")
  local item c mark
  for item in "${all[@]}"; do
    [[ -z "$item" ]] && continue
    mark=不完整
    for c in "${complete[@]}"; do
      [[ "$c" == "$item" ]] && mark=完整
    done
    echo "$(xedc_backup_stamp_time "$item") ${mark} r2:${item#drupal-backup-}"
  done
  rm -f "$conf"
}

if [[ "$LIST_LOCAL" == 1 ]]; then
  list_local
  exit 0
fi
if [[ "$LIST_R2" == 1 ]]; then
  xedc_require_root
  xedc_install_rclone
  list_r2
  exit 0
fi

ask_line() {
  local prompt=$1 var
  if [[ ! -t 0 ]]; then
    return 1
  fi
  read -r -p "$prompt" var || return 1
  printf '%s\n' "$var"
}

prepare_foreground() {
  if [[ "$VERIFY" == 1 || "$LIST_LOCAL" == 1 || "$LIST_R2" == 1 ]]; then
    return 0
  fi
  if [[ -z "$DOMAIN" ]]; then
    DOMAIN=$(ask_line "新域名: " || true)
  fi
  if [[ -z "$DOMAIN" ]]; then
    xedc_die "没有域名。请在命令里加上 --domain。当前不是交互终端时不能询问"
  fi
  if [[ "${XEDC_YES:-0}" != 1 && "${XEDC_RESOLVE_ONLY:-0}" != 1 ]]; then
    if [[ ! -t 0 ]]; then
      xedc_die "当前不是交互终端。确认安装请在命令末尾加 --yes"
    fi
    echo "将要安装的软件: nginx、PHP、MariaDB、Redis、Composer、Drush、Node、ffmpeg、7z、Docker、Netdata、Certbot"
    local answer
    answer=$(ask_line "输入 y 开始安装: " || true)
    case "$answer" in
      y|Y|yes|YES) XEDC_YES=1 ;;
      *) xedc_die "未确认，已停止" ;;
    esac
  fi
  if [[ "$FROM" == r2:* ]]; then
    xedc_load_r2_env
    if ! xedc_r2_ready; then
      if [[ ! -t 0 ]]; then
        xedc_die "没有 R2 凭据，而且当前不是交互终端。请设置 R2_ACCOUNT_ID、R2_ACCESS_KEY_ID、R2_SECRET_ACCESS_KEY、R2_BUCKET。新服务器不需要解密口令"
      fi
      R2_ACCOUNT_ID=$(ask_line "R2 Account ID: " || true)
      R2_ACCESS_KEY_ID=$(ask_line "Access Key ID: " || true)
      if ! read -r -s -p "Secret Access Key: " R2_SECRET_ACCESS_KEY; then
        xedc_die "无法读取 R2 Secret。请改用环境变量 R2_SECRET_ACCESS_KEY"
      fi
      echo
      R2_BUCKET=$(ask_line "Bucket: " || true)
      R2_PREFIX=$(ask_line "Prefix [xedc/]: " || true)
      R2_PREFIX=${R2_PREFIX:-xedc/}
      local keep
      keep=$(ask_line "保留凭据供以后备份？[y/N] " || true)
      if [[ "$keep" == "y" || "$keep" == "Y" ]]; then
        mkdir -p "$(dirname "$XEDC_R2_ENV")"
        umask 077
        cat >"$XEDC_R2_ENV" <<EOF
R2_ACCOUNT_ID=${R2_ACCOUNT_ID}
R2_ACCESS_KEY_ID=${R2_ACCESS_KEY_ID}
R2_SECRET_ACCESS_KEY=${R2_SECRET_ACCESS_KEY}
R2_BUCKET=${R2_BUCKET}
R2_PREFIX=${R2_PREFIX}
EOF
        chmod 600 "$XEDC_R2_ENV"
      fi
      xedc_load_r2_env
    fi
    if ! xedc_r2_ready; then
      xedc_die "R2 凭据不完整。需要 R2_ACCOUNT_ID、R2_ACCESS_KEY_ID、R2_SECRET_ACCESS_KEY、R2_BUCKET"
    fi
    export R2_ACCOUNT_ID R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_BUCKET R2_PREFIX R2_ENDPOINT
  fi
  export XEDC_YES DOMAIN
}

write_r2_handoff() {
  local f
  f=$(mktemp /tmp/xedc-r2.XXXXXX)
  chmod 600 "$f"
  cat >"$f" <<EOF
R2_ACCOUNT_ID=${R2_ACCOUNT_ID-}
R2_ACCESS_KEY_ID=${R2_ACCESS_KEY_ID-}
R2_SECRET_ACCESS_KEY=${R2_SECRET_ACCESS_KEY-}
R2_BUCKET=${R2_BUCKET-}
R2_PREFIX=${R2_PREFIX-}
R2_ENDPOINT=${R2_ENDPOINT-}
EOF
  chmod 600 "$f"
  printf '%s\n' "$f"
}

load_r2_handoff() {
  if [[ -z "${XEDC_R2_FILE:-}" || ! -f "$XEDC_R2_FILE" ]]; then
    return 0
  fi
  # shellcheck disable=SC1090
  set -a
  # shellcheck disable=SC1090
  source "$XEDC_R2_FILE"
  set +a
  rm -f "$XEDC_R2_FILE"
  unset XEDC_R2_FILE
}

enter_tmux() {
  if [[ -n "${XEDC_INSIDE_TMUX:-}" || "${XEDC_NO_TMUX:-0}" == "1" ]]; then
    return 0
  fi
  xedc_require_root
  if ! xedc_have tmux; then
    xedc_apt_install tmux
  fi
  mkdir -p "$(dirname "$XEDC_RESTORE_LOG")"
  if [[ "$RESUME" == 1 && -f "$XEDC_RESTORE_LOG" ]]; then
    chmod 600 "$XEDC_RESTORE_LOG"
  else
    : >"$XEDC_RESTORE_LOG"
    chmod 600 "$XEDC_RESTORE_LOG"
  fi
  local r2file="" cmd rcfile tail_pid=""
  rcfile=/tmp/xedc-restore.exit
  if [[ "$FROM" == r2:* ]]; then
    r2file=$(write_r2_handoff)
  fi
  cmd=$(mktemp)
  chmod 700 "$cmd"
  {
    printf '%s\n' '#!/bin/bash' 'set +e' 'set -o pipefail' 'export XEDC_INSIDE_TMUX=1' 'export XEDC_LOG_MIRROR=1'
    [[ -n "${XEDC_YES:-}" ]] && printf 'export XEDC_YES=%q\n' "$XEDC_YES"
    [[ -n "${XEDC_PHP_VERSION:-}" ]] && printf 'export XEDC_PHP_VERSION=%q\n' "$XEDC_PHP_VERSION"
    [[ -n "${XEDC_FAIL_STEP:-}" ]] && printf 'export XEDC_FAIL_STEP=%q\n' "$XEDC_FAIL_STEP"
    [[ -n "${XEDC_RESOLVE_ONLY:-}" ]] && printf 'export XEDC_RESOLVE_ONLY=%q\n' "$XEDC_RESOLVE_ONLY"
    [[ -n "${XEDC_TEST:-}" ]] && printf 'export XEDC_TEST=%q\n' "$XEDC_TEST"
    if [[ -n "$r2file" ]]; then
      printf 'r2file=%q\n' "$r2file"
      printf '%s\n' 'if [[ -f "$r2file" ]]; then' '  set -a' '  # shellcheck disable=SC1090' '  source "$r2file"' '  set +a' '  rm -f "$r2file"' 'fi' 'unset r2file'
    fi
    printf 'rcfile=%q\n' "$rcfile"
    printf 'logfile=%q\n' "$XEDC_RESTORE_LOG"
    printf '%s\n' 'printf "1\n" > "$rcfile"' 'chmod 600 "$rcfile"' 'chmod 600 "$logfile" 2>/dev/null || true'
    printf '%s\n' 'trap '\''ec=$?; printf "%s\n" "$ec" > "$rcfile"; chmod 600 "$rcfile"'\'' EXIT'
    printf 'bash %q ' "$0"
    printf '%q ' "$@"
    printf ' 2>&1 | tee -a %q\n' "$XEDC_RESTORE_LOG"
    printf '%s\n' 'rc=$?'
    printf '%s\n' 'if [[ "$rc" == 0 ]]; then'
    printf '%s\n' '  printf "%s 退出码 0\n" "$(date -u +%H:%M:%S)" >> "$logfile"'
    printf '%s\n' 'else'
    printf '%s\n' '  printf "%s 退出码 %s\n" "$(date -u +%H:%M:%S)" "$rc" >> "$logfile"'
    printf '%s\n' '  printf "%s 失败。日志: %s。查看: cat %s 或 tail -f %s。继续请在原命令末尾加上 --resume\n" "$(date -u +%H:%M:%S)" "$logfile" "$logfile" "$logfile" >> "$logfile"'
    printf '%s\n' 'fi'
    printf '%s\n' 'exit "$rc"'
  } >"$cmd"
  rm -f "$rcfile"
  tmux kill-session -t xedc-restore >/dev/null 2>&1 || true
  tmux new-session -d -s xedc-restore "bash $cmd"
  unset R2_ACCOUNT_ID R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_BUCKET R2_PREFIX R2_ENDPOINT
  printf '%s\n' "恢复在 tmux 会话 xedc-restore 中运行。断线后执行: tmux attach -t xedc-restore" >&2
  printf '%s\n' "日志: ${XEDC_RESTORE_LOG}" >&2
  printf '%s\n' "查看: cat ${XEDC_RESTORE_LOG} 或 tail -f ${XEDC_RESTORE_LOG}" >&2
  if [[ -t 0 && -t 1 ]]; then
    tmux attach -t xedc-restore || true
  else
    if command -v stdbuf >/dev/null 2>&1; then
      stdbuf -oL -eL tail -n +1 -F "$XEDC_RESTORE_LOG" &
    else
      tail -n +1 -F "$XEDC_RESTORE_LOG" &
    fi
    tail_pid=$!
  fi
  local waited=0
  while tmux has-session -t xedc-restore 2>/dev/null; do
    sleep 1
    waited=$((waited + 1))
    if (( waited > 86400 )); then
      break
    fi
  done
  if [[ -n "$tail_pid" ]]; then
    kill "$tail_pid" >/dev/null 2>&1 || true
    wait "$tail_pid" 2>/dev/null || true
  fi
  local i rc
  for i in 1 2 3 4 5 6 7 8 9 10; do
    [[ -s "$rcfile" ]] && break
    sleep 0.2
  done
  rm -f "$r2file" "$cmd"
  if [[ ! -s "$rcfile" ]] || ! [[ "$(cat "$rcfile")" =~ ^[0-9]+$ ]]; then
    printf '1\n' >"$rcfile"
    chmod 600 "$rcfile"
    printf '%s\n' "$(date -u '+%H:%M:%S') 退出码 1" >>"$XEDC_RESTORE_LOG"
  fi
  rc=$(cat "$rcfile")
  if [[ "$rc" == "0" ]]; then
    printf '%s\n' "恢复完成，退出码 0" >&2
  else
    printf '%s\n' "恢复失败，退出码 ${rc}" >&2
    printf '%s\n' "日志: ${XEDC_RESTORE_LOG}" >&2
    printf '%s\n' "查看: cat ${XEDC_RESTORE_LOG} 或 tail -f ${XEDC_RESTORE_LOG}" >&2
    printf '%s\n' "继续: 在原命令末尾加上 --resume" >&2
  fi
  exit "$rc"
}

if [[ -z "${XEDC_INSIDE_TMUX:-}" ]]; then
  prepare_foreground
fi
if [[ -z "${XEDC_INSIDE_TMUX:-}" && "${XEDC_NO_TMUX:-0}" != "1" ]]; then
  tmux_args=("${ORIGINAL_ARGS[@]}")
  if [[ "${XEDC_YES:-0}" == 1 ]]; then
    tmux_args+=(--yes)
  fi
  if [[ -n "$DOMAIN" ]]; then
    tmux_args+=(--domain "$DOMAIN")
  fi
  enter_tmux "${tmux_args[@]}"
fi
load_r2_handoff

export XEDC_LOG_FILE=$XEDC_RESTORE_LOG
mkdir -p "$(dirname "$XEDC_RESTORE_LOG")" "$XEDC_STATE_DIR" /var/lock
touch "$XEDC_RESTORE_LOG"
chmod 600 "$XEDC_RESTORE_LOG"
lock=/var/lock/xedc-restore.pid
if [[ -f "$lock" ]]; then
  oldpid=$(cat "$lock" 2>/dev/null || true)
  if [[ -n "$oldpid" && "$oldpid" != "$$" ]] && kill -0 "$oldpid" 2>/dev/null; then
    xedc_die "恢复已在运行。查看进度: tmux attach -t xedc-restore"
  fi
fi
printf '%s\n' "$$" >"$lock"

WORK=""
RCLONE_CONF=""
ARCHIVE=""
STAMP=""
MANIFEST=""
SITE=""
DB_NAME=""
DB_USER=""
DB_PASS=""
DB_HOST="localhost"
DB_PORT="3306"
MANUAL="$XEDC_STATE_DIR/manual.txt"
trap 'rm -f /var/lock/xedc-restore.pid; cleanup_secrets' EXIT

cleanup_secrets() {
  if [[ -n "$RCLONE_CONF" && -f "$RCLONE_CONF" ]]; then
    rm -f "$RCLONE_CONF"
  fi
  if [[ -n "$WORK" && -d "$WORK" ]]; then
    rm -rf "$WORK"
  fi
  return 0
}
note_manual() {
  mkdir -p "$XEDC_STATE_DIR"
  printf '%s\n' "$*" >>"$MANUAL"
}

run_step() {
  local name=$1 rc=0
  shift
  if xedc_step_done "$name"; then
    xedc_log "跳过已完成步骤 ${name}"
    return 0
  fi
  xedc_fail_inject "$name"
  xedc_log "开始 ${name}"
  "$@"
  rc=$?
  if [[ "$rc" != 0 ]]; then
    return "$rc"
  fi
  xedc_step_mark "$name"
}

prompt_r2() {
  xedc_load_r2_env
  if xedc_r2_ready; then
    return 0
  fi
  xedc_die "没有 R2 凭据。请设置 R2_ACCOUNT_ID、R2_ACCESS_KEY_ID、R2_SECRET_ACCESS_KEY、R2_BUCKET。日志: ${XEDC_RESTORE_LOG}。继续请加上 --resume"
}

download_one() {
  local conf=$1 stamp=$2 dest=$3
  xedc_r2 "$conf" copyto "$(xedc_r2_remote "${stamp}.tar.zst")" "$dest/${stamp}.tar.zst"
  xedc_r2 "$conf" copyto "$(xedc_r2_remote "${stamp}.tar.zst.sha256")" "$dest/${stamp}.tar.zst.sha256"
  xedc_r2 "$conf" copyto "$(xedc_r2_remote "${stamp}.manifest.json")" "$dest/${stamp}.manifest.json" || true
  (
    cd "$dest"
    sha256sum -c "${stamp}.tar.zst.sha256"
  )
}

step_fetch() {
  mkdir -p "$XEDC_STATE_DIR/downloads"
  local dest="$XEDC_STATE_DIR/downloads"
  if [[ "$FROM" == local:* ]]; then
    local path=${FROM#local:}
    [[ -f "$path" ]] || xedc_die "找不到本地备份 $path"
    ARCHIVE=$path
    STAMP=$(basename "$path" .tar.zst)
    if [[ -f "${path}.sha256" ]]; then
      (cd "$(dirname "$path")" && sha256sum -c "$(basename "$path").sha256") || xedc_die "本地 sha256 不一致"
    elif [[ -f "$(dirname "$path")/${STAMP}.tar.zst.sha256" ]]; then
      (cd "$(dirname "$path")" && sha256sum -c "${STAMP}.tar.zst.sha256") || xedc_die "本地 sha256 不一致"
    else
      xedc_warn "本地备份没有 sha256 文件"
    fi
    if [[ -f "$(dirname "$path")/${STAMP}.manifest.json" ]]; then
      MANIFEST="$(dirname "$path")/${STAMP}.manifest.json"
    fi
  elif [[ "$FROM" == r2:* ]]; then
    local spec=${FROM#r2:}
    prompt_r2
    xedc_install_rclone
    RCLONE_CONF=$(xedc_rclone_conf)
    local -a stamps=()
    mapfile -t stamps < <(xedc_r2_stamps "$RCLONE_CONF")
    if ((${#stamps[@]} == 0)); then
      xedc_die "R2 上没有带 .complete 的完整备份"
    fi
    local chosen=""
    chosen=$(xedc_choose_stamp "$spec" "${stamps[@]}") || xedc_die "找不到备份 ${spec}"
    local idx=0 i
    for i in "${!stamps[@]}"; do
      [[ "${stamps[$i]}" == "$chosen" ]] && idx=$i
    done
    while (( idx >= 0 )); do
      chosen=${stamps[$idx]}
      rm -f "$dest/${chosen}.tar.zst" "$dest/${chosen}.tar.zst.sha256"
      if download_one "$RCLONE_CONF" "$chosen" "$dest"; then
        ARCHIVE="$dest/${chosen}.tar.zst"
        STAMP=$chosen
        MANIFEST="$dest/${chosen}.manifest.json"
        break
      fi
      xedc_warn "备份 ${chosen} 校验失败，尝试上一份"
      idx=$((idx - 1))
      ARCHIVE=""
    done
    [[ -n "$ARCHIVE" ]] || xedc_die "没有校验通过的备份"
  else
    xedc_die "请指定 --from local:/路径 或 --from r2:latest"
  fi
  if [[ -z "$MANIFEST" || ! -f "$MANIFEST" ]]; then
    local unpack
    unpack=$(mktemp -d)
    set +o pipefail
    zstd -dc "$ARCHIVE" | tar -C "$unpack" -xf - ./manifest.json 2>/dev/null || true
    if [[ ! -f "$unpack/manifest.json" ]]; then
      zstd -dc "$ARCHIVE" | tar -C "$unpack" -xf - manifest.json 2>/dev/null || true
    fi
    set -o pipefail
    if [[ ! -f "$unpack/manifest.json" ]]; then
      xedc_die "备份里没有 manifest.json"
    fi
    MANIFEST="$unpack/manifest.json"
  fi
  cp "$MANIFEST" "$XEDC_STATE_DIR/manifest.json"
  printf '%s\n' "$ARCHIVE" >"$XEDC_STATE_DIR/archive.path"
  MANIFEST="$XEDC_STATE_DIR/manifest.json"
  local when size domain
  when=$(xedc_backup_stamp_time "$STAMP")
  size=$(du -h "$ARCHIVE" | awk '{print $1}')
  domain=$(xedc_manifest_get "$MANIFEST" domain)
  echo "将使用备份"
  echo "时间: ${when}"
  echo "大小: ${size}"
  echo "来源域名: ${domain}"
  echo "文件: ${ARCHIVE}"
  if [[ "${XEDC_RESOLVE_ONLY:-0}" == 1 ]]; then
    echo "已选择 ${STAMP}"
    exit 0
  fi
}

step_packages() {
  echo "将要安装的软件: nginx、PHP、MariaDB、Redis、Composer、Drush、Node、ffmpeg、7z、Docker、Netdata、Certbot"
  xedc_confirm "输入 y 开始安装: "
  export XEDC_MANIFEST=$MANIFEST
  php_install
  nginx_install
  mariadb_install
  redis_install
  composer_install
  node_install
  ffmpeg_install
  docker_install
  netdata_install
  certbot_install
  xedc_apt_install cron rsync
}

prepare_db_names() {
  DB_NAME=$(xedc_manifest_get "$MANIFEST" database.name)
  [[ -n "$DB_NAME" ]] || DB_NAME=drupal
  DB_USER=${DB_NAME}
  DB_HOST=$(xedc_manifest_get "$MANIFEST" database.host)
  DB_PORT=$(xedc_manifest_get "$MANIFEST" database.port)
  [[ -n "$DB_HOST" ]] || DB_HOST=localhost
  [[ -n "$DB_PORT" ]] || DB_PORT=3306
  if [[ -z "$DB_PASS" ]]; then
    DB_PASS=$(openssl rand -hex 16)
  fi
}

backup_existing() {
  local bin stamp
  bin=$(mariadb_root || true)
  if [[ -n "$bin" ]]; then
    stamp=$(date -u '+%Y%m%dT%H%M%S')
    mkdir -p "$XEDC_BACKUP_DIR"
    chmod 700 "$XEDC_BACKUP_DIR"
    if "$bin" --protocol=socket -u root -e "USE \`${DB_NAME}\`" >/dev/null 2>&1; then
      "$bin" --protocol=socket -u root -N -e "SHOW TABLES FROM \`${DB_NAME}\`" | grep -q . && \
        xedc_mysqldump --protocol=socket -u root --single-transaction "$DB_NAME" | zstd -3 -o "$XEDC_BACKUP_DIR/pre-overwrite-${stamp}.sql.zst" || true
      chmod 600 "$XEDC_BACKUP_DIR/pre-overwrite-${stamp}.sql.zst" 2>/dev/null || true
      xedc_log "已备份旧数据库"
    fi
  fi
}

step_database() {
  prepare_db_names
  mariadb_configure
  mariadb_enable
  if [[ -f "$SITE/web/sites/default/settings.php" && "$FORCE" == 1 ]]; then
    backup_existing
  fi
  local bin sql
  bin=$(mariadb_root)
  sql=$(mktemp)
  cat >"$sql" <<EOF
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
  "$bin" --protocol=socket -u root <"$sql"
  rm -f "$sql"
  if [[ ! -d "$WORK" ]]; then
    WORK=$(mktemp -d)
    zstd -dc "$ARCHIVE" | tar -C "$WORK" -xf -
  elif [[ ! -f "$WORK/db/site.sql.zst" ]]; then
    zstd -dc "$ARCHIVE" | tar -C "$WORK" -xf -
  fi
  zstd -dc "$WORK/db/site.sql.zst" | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' | "$bin" --protocol=socket -u root "$DB_NAME"
  printf '%s\n' "$DB_PASS" >"$XEDC_STATE_DIR/db.pass"
  chmod 600 "$XEDC_STATE_DIR/db.pass"
  xedc_log "数据库 ${DB_NAME} 已导入"
}

extract_archive() {
  if [[ -z "$WORK" || ! -d "$WORK/code" ]]; then
    WORK=$(mktemp -d)
    zstd -dc "$ARCHIVE" | tar -C "$WORK" -xf -
  fi
}

step_code() {
  extract_archive
  SITE=$(xedc_manifest_get "$MANIFEST" site_root)
  [[ -n "$SITE" ]] || SITE=/var/www/drupal
  export XEDC_SITE_ROOT=$SITE
  mkdir -p "$SITE"
  local -a exclude=()
  if [[ "$XEDC_ROOT" == "$SITE"* ]]; then
    exclude+=(--exclude 'xedc-recovery/')
  fi
  rsync -a "${exclude[@]}" "$WORK/code/" "$SITE/"
  local private
  private=$(xedc_manifest_get "$MANIFEST" private_path)
  [[ -n "$private" ]] || private="$SITE/private"
  mkdir -p "$SITE/web/sites/default/files" "$private"
  rsync -a "$WORK/files/public/" "$SITE/web/sites/default/files/"
  if [[ -d "$WORK/files/private" ]]; then
    rsync -a "$WORK/files/private/" "$private/"
  fi
  if [[ -f "$SITE/composer.json" ]]; then
    export COMPOSER_ALLOW_SUPERUSER=1
    xedc_log "检查 Composer 平台要求"
    if [[ -f "$SITE/composer.lock" ]] && ! composer check-platform-reqs --no-interaction --working-dir="$SITE"; then
      xedc_die "Composer 平台要求不满足。上面列出了不兼容的包。没有忽略平台检查。日志: ${XEDC_RESTORE_LOG}。可在原命令末尾加 --resume"
    fi
    if [[ ! -f "$SITE/composer.lock" ]]; then
      xedc_log "没有 composer.lock，安装依赖后再检查平台要求"
    fi
    composer install --no-dev --no-interaction --prefer-dist --working-dir="$SITE"
    if ! composer check-platform-reqs --no-interaction --working-dir="$SITE"; then
      xedc_die "Composer 平台要求不满足。上面列出了不兼容的包。没有忽略平台检查。日志: ${XEDC_RESTORE_LOG}。可在原命令末尾加 --resume"
    fi
  fi
  local pkg dir
  if [[ -d "$SITE/web/themes/custom" ]]; then
    while IFS= read -r pkg; do
      dir=$(dirname "$pkg")
      if grep -q '"build"' "$pkg"; then
        if [[ -f "$dir/package-lock.json" ]]; then
          npm ci --prefix "$dir"
        else
          npm install --prefix "$dir"
        fi
        npm run build --prefix "$dir"
      fi
    done < <(find "$SITE/web/themes/custom" -name package.json -not -path '*/node_modules/*')
  fi
  chown -R www-data:www-data "$SITE/web/sites/default/files" "$private"
  find "$SITE/web/sites/default/files" "$private" -type d -exec chmod 755 {} +
  find "$SITE/web/sites/default/files" "$private" -type f -exec chmod 644 {} +
  drush_install
}

step_settings() {
  local settings="$SITE/web/sites/default/settings.php"
  [[ -f "$settings" ]] || xedc_die "备份里没有 settings.php"
  chmod u+w "$settings"
  local salt=""
  salt=$(php "$ROOT/lib/php/read_salt.php" "$settings" || true)
  local https_flag=on
  [[ "$HTTPS" == "off" ]] && https_flag=off
  if [[ -z "$DB_PASS" && -f "$XEDC_STATE_DIR/db.pass" ]]; then
    DB_PASS=$(cat "$XEDC_STATE_DIR/db.pass")
  fi
  prepare_db_names
  php "$ROOT/lib/php/patch_settings.php" \
    --file "$settings" \
    --database "$DB_NAME" \
    --username "$DB_USER" \
    --password "$DB_PASS" \
    --host "$DB_HOST" \
    --port "$DB_PORT" \
    --domain "$DOMAIN" \
    --https "$https_flag" \
    --hash-salt "$salt"
  chown root:www-data "$settings"
  chmod 440 "$settings"
}

step_services() {
  extract_archive
  export XEDC_MANIFEST=$MANIFEST
  export XEDC_DOMAIN=$DOMAIN
  export XEDC_WWW=${WWW:-no}
  export XEDC_HTTPS_MODE=$HTTPS
  export XEDC_SITE_ROOT=$SITE
  export XEDC_WEB_ROOT="$SITE/web"
  if [[ "$HTTPS" == "auto" ]]; then
    export XEDC_HTTPS_MODE=self
  fi
  XEDC_SYSTEM_DIR="$WORK/system"
  export XEDC_SYSTEM_DIR
  if [[ -d "$WORK/system" ]]; then
    system_restore "$WORK/system"
  fi
  xedc_write_tune
  php_configure
  local ver pool
  ver=$(php_version_file)
  pool="$WORK/system/php/${ver}/fpm/pool.d/www.conf"
  if [[ -f "$pool" ]]; then
    grep '^env\[' "$pool" >>"/etc/php/${ver}/fpm/pool.d/www.conf" || true
  fi
  redis_configure
  mariadb_configure
  nginx_configure
  netdata_configure
  php_enable
  redis_enable
  mariadb_enable
  nginx_enable
  docker_enable
  netdata_enable
  certbot_enable
  xedc_svc cron || xedc_warn "cron 未能启动"
  php_align_paths
}

step_drush() {
  local bin
  bin=$(xedc_drush)
  "$bin" -r "$SITE" updatedb -y || "$bin" -r "$SITE" updb -y
  "$bin" -r "$SITE" config:import -y || "$bin" -r "$SITE" cim -y || xedc_warn "配置导入没有执行"
  "$bin" -r "$SITE" cache:rebuild || "$bin" -r "$SITE" cr
}

replace_in_file() {
  local file=$1 from=$2 to=$3
  [[ -f "$file" ]] || return 0
  php -r 'file_put_contents($argv[1], str_replace($argv[2], $argv[3], file_get_contents($argv[1])));' "$file" "$from" "$to"
}

step_domain() {
  if [[ -z "$DB_PASS" && -f "$XEDC_STATE_DIR/db.pass" ]]; then
    DB_PASS=$(cat "$XEDC_STATE_DIR/db.pass")
  fi
  prepare_db_names
  local old=${OLD_DOMAIN:-}
  [[ -n "$old" ]] || old=$(xedc_manifest_get "$MANIFEST" domain)
  if [[ -z "$old" || "$old" == "$DOMAIN" ]]; then
    xedc_log "域名未改变，保持原样"
    return 0
  fi
  local cnf bin stamp
  cnf=$(mktemp)
  chmod 600 "$cnf"
  cat >"$cnf" <<EOF
[client]
user=${DB_USER}
password=${DB_PASS}
host=${DB_HOST}
port=${DB_PORT}
EOF
  stamp=$(date -u '+%Y%m%dT%H%M%S')
  bin=$(mariadb_root)
  "$bin" --protocol=socket -u root "$DB_NAME" -e 'SELECT 1' >/dev/null
  xedc_mysqldump --defaults-extra-file="$cnf" --single-transaction "$DB_NAME" | zstd -3 -o "$XEDC_BACKUP_DIR/pre-domain-${stamp}.sql.zst"
  chmod 600 "$XEDC_BACKUP_DIR/pre-domain-${stamp}.sql.zst"
  xedc_log "域名替换预览"
  php "$ROOT/lib/php/domain_replace.php" --from "$old" --to "$DOMAIN" --cnf "$cnf" --database "$DB_NAME" \
    --path "$SITE/config" --dry-run
  local f
  for f in "$SITE/web/robots.txt" "$SITE/web/sitemap.xml" /etc/netdata/netdata.conf /opt/matomo/compose.yaml; do
    replace_in_file "$f" "$old" "$DOMAIN"
  done
  if [[ -d /etc/cron.d ]]; then
    while IFS= read -r f; do
      replace_in_file "$f" "$old" "$DOMAIN"
    done < <(grep -RIl "$old" /etc/cron.d 2>/dev/null || true)
  fi
  php "$ROOT/lib/php/domain_replace.php" --from "$old" --to "$DOMAIN" --cnf "$cnf" --database "$DB_NAME" \
    --path "$SITE/config" --apply
  if [[ -n "$EMAIL" && -f "$SITE/config/sync/system.site.yml" ]]; then
    php -r '$p=$argv[1]; $m=$argv[2]; $t=file_get_contents($p); $t=preg_replace("/^mail:.*$/m", "mail: ".$m, $t); file_put_contents($p,$t);' \
      "$SITE/config/sync/system.site.yml" "$EMAIL"
  fi
  local bin_drush
  bin_drush=$(xedc_drush || true)
  if [[ -n "$bin_drush" ]]; then
    "$bin_drush" -r "$SITE" cr >/dev/null 2>&1 || true
  fi
  if grep -RIn --exclude-dir=vendor --exclude-dir=node_modules "$old" "$SITE/web/modules" "$SITE/web/themes" >"$XEDC_STATE_DIR/hardcoded.txt" 2>/dev/null; then
    note_manual "主题或模块代码仍包含旧域名，见 ${XEDC_STATE_DIR}/hardcoded.txt（未改代码）"
  fi
  rm -f "$cnf"
  note_manual "检查第三方回调、搜索引擎验证、邮件 SPF/DKIM 是否仍指向 ${old}"
}

step_https() {
  export XEDC_DOMAIN=$DOMAIN
  export XEDC_WWW=${WWW:-no}
  export XEDC_WEB_ROOT="$SITE/web"
  certbot_cloudflare_hint "$DOMAIN" || true
  if [[ "$HTTPS" == "off" ]]; then
    export XEDC_HTTPS_MODE=off
    nginx_configure
    nginx_enable
    note_manual "未启用 HTTPS"
    return 0
  fi
  if [[ "$HTTPS" == "auto" ]] && [[ -n "$EMAIL" ]] && certbot_points_here "$DOMAIN"; then
    if certbot_issue "$DOMAIN" "$EMAIL" "${WWW:-no}"; then
      nginx_enable || true
      return 0
    fi
    xedc_warn "certbot 失败，改用自签证书"
  elif [[ "$HTTPS" == "auto" ]]; then
    xedc_warn "域名尚未指向本机，先使用自签证书。DNS 生效后可再执行 certbot"
    note_manual "DNS 指向本机后执行: certbot --nginx -d ${DOMAIN} -d www.${DOMAIN} --email ${EMAIL:-你的邮箱}"
  fi
  export XEDC_HTTPS_MODE=self
  nginx_configure
  nginx_enable
  note_manual "当前是自签证书，浏览器会提示不受信任"
}

selfcheck() {
  local fail=0 code path scheme bin
  scheme=https
  [[ "$HTTPS" == "off" ]] && scheme=http
  for path in / /user/login /admin /models /articles /sitemap.xml; do
    code=$(curl -k -s -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" "${scheme}://${DOMAIN}${path}" || true)
    if [[ "$code" == "200" ]]; then
      echo "✔ ${path} ${code}"
    else
      echo "✘ ${path} ${code}"
      fail=1
    fi
  done
  bin=$(xedc_drush || true)
  if [[ -n "$bin" ]] && "$bin" -r "$SITE" status >/dev/null 2>&1; then
    echo "✔ drush status"
  else
    echo "✘ drush status"
    fail=1
  fi
  if mariadb_root >/dev/null 2>&1; then
    echo "✔ 数据库"
  else
    echo "✘ 数据库"
    fail=1
  fi
  if [[ "$(redis-cli ping 2>/dev/null || true)" == "PONG" ]]; then
    echo "✔ Redis"
  else
    echo "✘ Redis"
    fail=1
  fi
  local ver got sock hdr masters php_line probe_file probe_body
  if ! command -v pgrep >/dev/null 2>&1; then
    xedc_apt_install procps
  fi
  ver=$(php_target_version)
  php_line=$(php -v 2>/dev/null | awk 'NR==1 {print}' || true)
  got=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;' 2>/dev/null || true)
  if [[ "$got" == "$ver" && "$php_line" == "PHP ${ver}."* ]]; then
    echo "✔ php -v ${php_line}"
  else
    echo "✘ php -v ${php_line:-缺失}，需要 ${ver}.x"
    fail=1
  fi
  sock="/run/php/php${ver}-fpm.sock"
  masters=""
  local pid exe
  for pid in $(pgrep php-fpm || true); do
    exe=$(readlink "/proc/${pid}/exe" 2>/dev/null || true)
    masters+="${exe}"$'\n'
  done
  if [[ -S "$sock" && "$masters" == *"/php-fpm${ver}"* ]]; then
    echo "✔ PHP-FPM ${ver}"
  else
    echo "✘ PHP-FPM ${ver}"
    printf '%s\n' "$masters"
    fail=1
  fi
  old_fpm=$(printf '%s\n' "$masters" | grep -F '/php-fpm' | grep -v "/php-fpm${ver}" || true)
  if [[ -n "$old_fpm" ]]; then
    echo "✘ 仍有其他 PHP-FPM"
    printf '%s\n' "$old_fpm"
    fail=1
  fi
  if grep -R --include='*.conf' -F "unix:${sock}" /etc/nginx/conf.d >/dev/null 2>&1; then
    echo "✔ nginx ${sock}"
  else
    echo "✘ nginx 未使用 ${sock}"
    fail=1
  fi
  probe_file="${SITE}/web/xedc-php-probe.php"
  printf '%s\n' '<?php header("X-PHP-Version: " . PHP_VERSION); echo PHP_VERSION;' >"$probe_file"
  chmod 644 "$probe_file"
  probe_body=$(curl -k -s --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" "${scheme}://${DOMAIN}/xedc-php-probe.php" || true)
  hdr=$(curl -k -sI --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" "${scheme}://${DOMAIN}/xedc-php-probe.php" | tr -d '\r' | awk 'tolower($1)=="x-php-version:" {print $2; exit}' || true)
  rm -f "$probe_file"
  if [[ "$probe_body" == "${ver}."* && "$hdr" == "${ver}."* ]]; then
    echo "✔ 页面 PHP ${hdr}"
  else
    echo "✘ 页面由 PHP ${hdr:-${probe_body:-无响应}} 处理，需要 ${ver}"
    fail=1
  fi
  if crontab -l 2>/dev/null | grep -q . || grep -Rqs . /etc/cron.d/xedc-* /etc/cron.d/xedc-drupal 2>/dev/null || ls /etc/cron.d/*xedc* >/dev/null 2>&1; then
    echo "✔ cron"
  else
    echo "✘ cron"
    fail=1
  fi
  if ffmpeg -hide_banner -encoders 2>/dev/null | grep -F libx264 >/dev/null; then
    echo "✔ ffmpeg"
  else
    echo "✘ ffmpeg"
    fail=1
  fi
  if docker info >/dev/null 2>&1; then
    echo "✔ Docker"
  elif docker --version >/dev/null 2>&1; then
    echo "警告 Docker 命令可用，守护进程未运行"
  else
    echo "✘ Docker"
    fail=1
  fi
  echo "你需要手动做的事："
  if [[ -f "$MANUAL" ]]; then
    sort -u "$MANUAL"
  fi
  echo "- 确认 DNS 已指向这台服务器"
  echo "- 到 Netdata 重新 claim（令牌不会从旧服务器带过来）"
  echo "- 检查邮箱 SPF/DKIM、第三方登录回调和搜索引擎验证"
  echo "- 对象存储里的媒体文件不在备份包内，请继续使用原来的私有桶"
  return "$fail"
}

if [[ "$VERIFY" == 1 ]]; then
  SITE=$(xedc_site_root || true)
  [[ -n "$SITE" ]] || SITE=/var/www/drupal
  export XEDC_SITE_ROOT=$SITE
  [[ -n "$DOMAIN" ]] || DOMAIN=$(php -r 'echo "localhost";')
  if [[ -z "$DOMAIN" || "$DOMAIN" == "localhost" ]]; then
    if [[ -f "$XEDC_STATE_DIR/manifest.json" ]]; then
      DOMAIN=$(xedc_manifest_get "$XEDC_STATE_DIR/manifest.json" domain || true)
    fi
  fi
  [[ -n "$DOMAIN" ]] || DOMAIN=localhost
  selfcheck || exit 1
  exit 0
fi

if [[ "$RESUME" != 1 ]]; then
  xedc_step_clear
fi
: >"$MANUAL"

if [[ -z "$DOMAIN" ]]; then
  xedc_die "恢复必须提供 --domain"
fi
if [[ -n "$WWW" && "$WWW" != "yes" && "$WWW" != "no" ]]; then
  xedc_die "--www 只能是 yes 或 no"
fi
case "$HTTPS" in
  auto|self|off) ;;
  *) xedc_die "--https 只能是 auto、self 或 off" ;;
esac

if [[ "$RESUME" == 1 ]]; then
  if [[ -f "$XEDC_STATE_DIR/archive.path" ]]; then
    ARCHIVE=$(cat "$XEDC_STATE_DIR/archive.path")
  fi
  if [[ -f "$XEDC_STATE_DIR/manifest.json" ]]; then
    MANIFEST="$XEDC_STATE_DIR/manifest.json"
  fi
  if [[ -f "$XEDC_STATE_DIR/db.pass" ]]; then
    DB_PASS=$(cat "$XEDC_STATE_DIR/db.pass")
  fi
fi
ensure_php() {
  if [[ "${XEDC_RESOLVE_ONLY:-0}" == 1 || "$VERIFY" == 1 ]]; then
    return 0
  fi
  local ver got=""
  ver=$(php_target_version)
  if xedc_have php; then
    got=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
  fi
  if [[ "$got" != "$ver" ]]; then
    php_install
  fi
}

ensure_php
run_step fetch step_fetch
if [[ -z "$WWW" ]]; then
  www_flag=$(xedc_manifest_get "$MANIFEST" www || true)
  if [[ "$www_flag" == "1" ]]; then
    WWW=yes
  else
    WWW=no
  fi
fi
SITE=$(xedc_manifest_get "$MANIFEST" site_root)
[[ -n "$SITE" ]] || SITE=/var/www/drupal
export XEDC_SITE_ROOT=$SITE
if [[ -f "$SITE/web/sites/default/settings.php" && "$FORCE" != 1 && "$RESUME" != 1 ]]; then
  xedc_die "站点已存在。覆盖请加 --force，旧数据会先备份"
fi
run_step packages step_packages
run_step database step_database
run_step code step_code
run_step settings step_settings
run_step services step_services
run_step drush step_drush
run_step domain step_domain
run_step https step_https
if ! run_step selfcheck selfcheck; then
  xedc_die "自检未通过。日志: ${XEDC_RESTORE_LOG}。查看: tail -f ${XEDC_RESTORE_LOG}。可在原命令末尾加 --resume"
fi
xedc_log "恢复完成"
