#!/usr/bin/env bash
# shellcheck disable=SC2016
# site-migrate.sh — 把 Drupal 站点的数据库、公共文件和私有文件迁到另一台服务器。
# 不包含代码。代码按 manifest 里的 git 提交号从仓库检出。
# 支持 Ubuntu 22.04 / 24.04。请用 root 运行。
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export APT_LISTCHANGES_FRONTEND=none
export GIT_TERMINAL_PROMPT=0
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1

SCRIPT_VERSION=1.0.0
LOG=/var/log/site-migrate.log
REPORT=/root/site-migrate-report.txt
CREDENTIALS=/root/site-migrate-credentials.txt
LOCK_FILE=/run/site-migrate.lock
DEFAULT_ROOT=/var/www/drupal
DEFAULT_REPO=https://github.com/xxedc/drupal.git
DEFAULT_PHP_MM=8.5

COMMAND=""
ROOT=$DEFAULT_ROOT
REPO=$DEFAULT_REPO
DOMAIN=""
OLD_DOMAIN=""
PACK=""
OUT=""
BACKUP=""
PHP_MM=""
FORCE=0
YES=0
MAINTENANCE=0
RESET_ADMIN=0

SECRETS=""
WORK=""
CNF=""
REPORT_WRITTEN=0
WRITE_FAIL_REPORT=0
EXPORT_MAINT_ACTIVE=0
EXPORT_MAINT_HAD_ROW=0
EXPORT_MAINT_HEX=""
IMPORT_MAINT=0
IMPORT_SUCCEEDED=0
ROLLBACK_CMD=""
DRUSH=""
DRUSH_URI="localhost"
DB_NAME=""
DB_USER=""
DB_PASS=""
DB_HOST="localhost"
DB_PORT="3306"
DB_PREFIX=""

usage() {
  cat <<'EOF'
用法:
  site-migrate.sh export  [--root 路径] [--out 目录] [--old-domain 域名] [--maintenance] [--yes]
  site-migrate.sh import  --pack 包.tgz --domain 新域名 [--root 路径] [--repo URL]
                          [--old-domain 域名] [--force] [--yes] [--reset-admin-pass]
  site-migrate.sh setup   --domain 域名 [--root 路径] [--repo URL] [--php 8.5]
  site-migrate.sh rollback --backup 目录 --yes [--root 路径]

export 默认只读。只有带 --maintenance 时才会临时打开维护模式，结束或异常退出时恢复原状。
import 不会执行 drush cim。目标库已有节点或 uid>1 的用户时，必须同时带 --force 和 --yes。
EOF
}

log() {
  local clean
  clean=$(redact "$*")
  mkdir -p "$(dirname "$LOG")"
  touch "$LOG"
  chmod 600 "$LOG"
  printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$clean" | tee -a "$LOG" >&2
}

note_secret() {
  local value=${1-}
  [[ -n $value && ${#value} -ge 8 ]] || return 0
  value=${value//$'\n'/}
  [[ -n $SECRETS ]] || return 0
  printf '%s\n' "$value" >>"$SECRETS"
}

redact() {
  local text=${1-} secret
  if [[ -n $SECRETS && -f $SECRETS ]]; then
    while IFS= read -r secret || [[ -n $secret ]]; do
      [[ -n $secret ]] || continue
      text=${text//"$secret"/***}
    done <"$SECRETS"
  fi
  printf '%s' "$text" | sed -E \
    -e 's#(://)[^/@[:space:]]+@#\1***@#g' \
    -e 's#((password|passwd|hash_salt)["'\''[:space:]]*[:=]["'\''[:space:]]*)[^"'\''[:space:]]+#\1***#Ig'
}

die() {
  log "错误: $*"
  if [[ $WRITE_FAIL_REPORT == 1 && $REPORT_WRITTEN != 1 ]]; then
    write_report "失败" "失败原因: $*"
  fi
  exit 1
}

cleanup() {
  set +e
  if [[ $EXPORT_MAINT_ACTIVE == 1 ]]; then
    if restore_export_maint; then
      log "已将维护模式恢复为导出前的值。"
    else
      log "错误: 未能恢复维护模式。请检查 key_value 表中的 system.maintenance_mode。"
    fi
  fi
  if [[ $IMPORT_SUCCEEDED != 1 && $IMPORT_MAINT == 1 ]]; then
    log "导入未完成，维护模式保持开启。"
  fi
  if [[ $IMPORT_SUCCEEDED != 1 && -n $ROLLBACK_CMD ]]; then
    log "导入未成功，数据没有自动回滚。可执行: ${ROLLBACK_CMD}"
    printf '回滚命令: %s\n' "$ROLLBACK_CMD" >&2
  fi
  [[ -n $CNF && -f $CNF ]] && rm -f "$CNF"
  [[ -n $WORK && -d $WORK ]] && rm -rf "$WORK"
  [[ -n $SECRETS && -f $SECRETS ]] && rm -f "$SECRETS"
}

require_root() {
  [[ $(id -u) -eq 0 ]] || die "请用 root 运行。"
}

require_ubuntu() {
  local version=""
  [[ -f /etc/os-release ]] || die "无法确认系统版本。仅支持 Ubuntu 22.04 和 24.04。"
  # shellcheck disable=SC1091
  version=$(. /etc/os-release && printf '%s' "${VERSION_ID:-}")
  case $version in
    22.04|24.04) log "系统 Ubuntu ${version}。" ;;
    *) die "仅支持 Ubuntu 22.04 和 24.04，当前是 ${version:-未知}。" ;;
  esac
}

acquire_lock() {
  mkdir -p "$(dirname "$LOCK_FILE")"
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    die "已有 site-migrate.sh 在运行。为避免同时改站，本次已退出。"
  fi
  chmod 600 "$LOCK_FILE" || true
}

init_runtime() {
  umask 022
  SECRETS=$(mktemp)
  chmod 600 "$SECRETS"
  WORK=$(mktemp -d /root/site-migrate-work.XXXXXX)
  chmod 700 "$WORK"
  CNF=$WORK/my.cnf
  trap cleanup EXIT
  acquire_lock
  require_ubuntu
}

abs_path() {
  local path=$1
  [[ $path == /* ]] || die "路径必须是绝对路径: ${path}"
  case $path in
    *..*) die "路径不能包含 .. : ${path}" ;;
    *[[:space:]]*) die "路径不能包含空格: ${path}" ;;
  esac
  printf '%s' "$path"
}

valid_domain() {
  [[ $1 =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]]
}

regex_domain() {
  printf '%s' "$1" | sed 's/\./\\./g; s/-/\\-/g'
}

trusted_patterns() {
  local domain=$1 apex
  if [[ $domain == www.* ]]; then
    apex=${domain#www.}
    printf '%s\n' "^$(regex_domain "$domain")$" "^$(regex_domain "$apex")$"
  else
    printf '%s\n' "^$(regex_domain "$domain")$" "^$(regex_domain "www.${domain}")$"
  fi
}

parse_args() {
  COMMAND=${1-}
  [[ -n $COMMAND ]] || { usage; exit 1; }
  shift
  while [[ $# -gt 0 ]]; do
    case $1 in
      --root)
        [[ $# -ge 2 ]] || die "--root 需要路径。"
        ROOT=$(abs_path "$2")
        shift 2
        ;;
      --repo)
        [[ $# -ge 2 ]] || die "--repo 需要 URL。"
        REPO=$2
        shift 2
        ;;
      --domain)
        [[ $# -ge 2 ]] || die "--domain 需要域名。"
        DOMAIN=$2
        shift 2
        ;;
      --old-domain)
        [[ $# -ge 2 ]] || die "--old-domain 需要域名。"
        OLD_DOMAIN=$2
        shift 2
        ;;
      --pack)
        [[ $# -ge 2 ]] || die "--pack 需要包路径。"
        PACK=$(abs_path "$2")
        shift 2
        ;;
      --out)
        [[ $# -ge 2 ]] || die "--out 需要目录。"
        OUT=$(abs_path "$2")
        shift 2
        ;;
      --backup)
        [[ $# -ge 2 ]] || die "--backup 需要目录。"
        BACKUP=$(abs_path "$2")
        shift 2
        ;;
      --php)
        [[ $# -ge 2 ]] || die "--php 需要版本，例如 8.5。"
        PHP_MM=$2
        shift 2
        ;;
      --force) FORCE=1; shift ;;
      --yes) YES=1; shift ;;
      --maintenance) MAINTENANCE=1; shift ;;
      --reset-admin-pass) RESET_ADMIN=1; shift ;;
      -h|--help) usage; exit 0 ;;
      *) die "未知参数: $1" ;;
    esac
  done
  if [[ -n $DOMAIN ]]; then
    valid_domain "$DOMAIN" || die "域名无效: ${DOMAIN}"
  fi
  if [[ -n $OLD_DOMAIN ]]; then
    valid_domain "$OLD_DOMAIN" || die "旧域名无效: ${OLD_DOMAIN}"
  fi
  if [[ -n $PHP_MM && ! $PHP_MM =~ ^8\.[0-9]+$ ]]; then
    die "--php 只接受主次版本，例如 8.5。"
  fi
}

gitc() {
  git -c safe.directory="$ROOT" -C "$ROOT" "$@"
}

drush_bin() {
  DRUSH=$ROOT/vendor/bin/drush
  [[ -x $DRUSH ]]
}

run_drush() {
  (
    cd "$ROOT" || exit 1
    "$DRUSH" -l "http://${DRUSH_URI}" "$@"
  )
}

bootstrap_ok() {
  local boot
  drush_bin || return 1
  boot=$(run_drush status --field=bootstrap 2>/dev/null || true)
  [[ $boot == "Successful" ]]
}

maint_set() {
  local value=$1
  [[ $value == 0 || $value == 1 ]] || return 1
  run_drush php:eval "\\Drupal::state()->set('system.maintenance_mode', ${value});" >/dev/null
}

systemd_ready() {
  [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1 && systemctl show-environment >/dev/null 2>&1
}

allow_services_without_systemd() {
  if systemd_ready; then
    return 0
  fi
  printf '#!/bin/sh\nexit 0\n' >/usr/sbin/policy-rc.d
  chmod 755 /usr/sbin/policy-rc.d
}

apt_update() {
  apt-get update || apt-get update || die "apt-get update 失败。"
}

apt_install() {
  apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install "$@"
}

php_mm_from_full() {
  printf '%s' "$1" | awk -F. '{print $1 "." $2}'
}

ensure_php() {
  local mm=$1
  [[ $mm =~ ^8\.[0-9]+$ ]] || die "无法安装 PHP 版本 ${mm}。"
  apt_update
  if ! apt-cache show "php${mm}-cli" >/dev/null 2>&1; then
    log "系统源没有 PHP ${mm}，添加 ondrej/php。"
    apt_install ca-certificates curl gnupg software-properties-common apt-transport-https
    add-apt-repository -y ppa:ondrej/php || die "无法添加 ondrej/php。"
    apt_update
  fi
  apt-cache show "php${mm}-cli" >/dev/null 2>&1 || die "Ubuntu 软件源中没有 php${mm}-cli。请确认 ondrej/php 是否支持该版本。"
  apt_install \
    "php${mm}-cli" \
    "php${mm}-fpm" \
    "php${mm}-mysql" \
    "php${mm}-gd" \
    "php${mm}-mbstring" \
    "php${mm}-xml" \
    "php${mm}-curl" \
    "php${mm}-zip" \
    "php${mm}-intl" \
    "php${mm}-bcmath" \
    "php${mm}-apcu" \
    "php${mm}-readline" \
    ca-certificates curl unzip git openssl nginx mariadb-server composer
  update-alternatives --install /usr/bin/php php "/usr/bin/php${mm}" "${mm//./}" >/dev/null
  update-alternatives --set php "/usr/bin/php${mm}" >/dev/null
  PHP_MM=$mm
  local current
  current=$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')
  [[ $current == "$mm" ]] || die "PHP 切换失败。期望 ${mm}，当前 ${current}。"
}

start_mariadb() {
  local i
  if systemd_ready; then
    systemctl enable mariadb
    systemctl restart mariadb
  else
    mkdir -p /run/mysqld
    chown mysql:mysql /run/mysqld
    if ! mysqladmin --protocol=socket -u root ping >/dev/null 2>&1; then
      if [[ -x /etc/init.d/mariadb ]]; then
        /etc/init.d/mariadb start || true
      fi
    fi
    if ! mysqladmin --protocol=socket -u root ping >/dev/null 2>&1; then
      mysqld_safe --datadir=/var/lib/mysql --user=mysql >>"$LOG" 2>&1 &
    fi
  fi
  for ((i = 1; i <= 60; i++)); do
    if mysqladmin --protocol=socket -u root ping >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  die "MariaDB 未能启动。"
}

start_php_fpm() {
  local bin="/usr/sbin/php-fpm${PHP_MM}"
  local sock="/run/php/php${PHP_MM}-fpm.sock"
  if systemd_ready; then
    systemctl enable "php${PHP_MM}-fpm"
    systemctl restart "php${PHP_MM}-fpm"
  else
    mkdir -p /run/php
    if [[ ! -S $sock ]]; then
      "$bin" --daemonize
    fi
  fi
  local i
  for ((i = 1; i <= 30; i++)); do
    [[ -S $sock ]] && return 0
    sleep 1
  done
  die "PHP-FPM 未能启动，找不到 ${sock}。"
}

start_nginx() {
  nginx -t || die "Nginx 配置检测失败。"
  if systemd_ready; then
    systemctl enable nginx
    systemctl restart nginx
    return 0
  fi
  if [[ -f /run/nginx.pid ]] && kill -0 "$(cat /run/nginx.pid)" 2>/dev/null; then
    nginx -s reload || die "Nginx 重载失败。"
  else
    nginx || die "Nginx 启动失败。"
  fi
}

write_vhost() {
  local domain=$1 root_dir=$2 sock www_name
  sock="/run/php/php${PHP_MM}-fpm.sock"
  if [[ $domain == www.* ]]; then
    www_name=${domain#www.}
  else
    www_name="www.${domain}"
  fi
  cat > /etc/nginx/sites-available/drupal.conf <<EOF
# Managed by site-migrate.sh. HTTP only.
server {
    listen 80;
    server_name ${domain} ${www_name};
    root ${root_dir}/web;
    index index.php;
    client_max_body_size 220m;
    server_tokens off;

    location = /favicon.ico { log_not_found off; access_log off; }
    location = /robots.txt { allow all; log_not_found off; access_log off; }
    location ~ \\..*/.*\\.php\$ { return 403; }
    location ~ ^/sites/.*/private/ { return 403; }
    location ~ ^/sites/[^/]+/files/.*\\.php\$ { deny all; }
    location ~ (^|/)\\. { return 403; }
    location ~ /vendor/.*\\.php\$ { deny all; }

    location / { try_files \$uri /index.php?\$query_string; }
    location @rewrite { rewrite ^ /index.php; }

    location ~ \\.php\$ {
        fastcgi_split_path_info ^(.+?\\.php)(|/.*)\$;
        try_files \$fastcgi_script_name =404;
        include fastcgi_params;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_read_timeout 300s;
        fastcgi_pass unix:${sock};
    }

    location ~ ^/sites/.*/files/styles/ { try_files \$uri @rewrite; }
    location ~ ^(/[a-z\\-]+)?/system/files/ { try_files \$uri /index.php?\$query_string; }
}
EOF
  chmod 644 /etc/nginx/sites-available/drupal.conf
  ln -sfn /etc/nginx/sites-available/drupal.conf /etc/nginx/sites-enabled/drupal.conf
  rm -f /etc/nginx/sites-enabled/default
}

mysql_root() {
  mysql --protocol=socket -u root "$@"
}

db_exists() {
  local name=$1 count
  count=$(mysql_root -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${name}'")
  [[ ${count:-0} -ge 1 ]]
}

db_has_content() {
  local db=$1 table count
  [[ $db =~ ^[A-Za-z0-9_]+$ ]] || return 1
  [[ ${DB_PREFIX:-} =~ ^[A-Za-z0-9_]*$ ]] || return 1
  db_exists "$db" || return 1
  for table in "${DB_PREFIX}node_field_data" "${DB_PREFIX}users_field_data"; do
    count=$(mysql_root -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}' AND table_name='${table}'")
    [[ ${count:-0} == 1 ]] || continue
    if [[ $table == "${DB_PREFIX}users_field_data" ]]; then
      count=$(mysql_root -N -B -e "SELECT COUNT(*) FROM \`${db}\`.\`${table}\` WHERE uid > 1")
    else
      count=$(mysql_root -N -B -e "SELECT COUNT(*) FROM \`${db}\`.\`${table}\`")
    fi
    [[ ${count:-0} -gt 0 ]] && return 0
  done
  return 1
}

write_cnf() {
  local user=$1 pass=$2 host=$3 port=$4
  note_secret "$pass"
  php -r '
    $pass = str_replace(["\\", "\""], ["\\\\", "\\\""], $argv[2]);
    $ini = "[client]\nuser=".$argv[1]."\npassword=\"".$pass."\"\nhost=".$argv[3]."\nport=".$argv[4]."\n";
    file_put_contents($argv[5], $ini);
    chmod($argv[5], 0600);
  ' "$user" "$pass" "$host" "$port" "$CNF"
}

mysql_site() {
  mysql --defaults-extra-file="$CNF" "$DB_NAME" "$@"
}

sql_scalar() {
  mysql_site -N -B -e "$1" | tr -d '[:space:]'
}

table_exists() {
  local name=$1 count
  count=$(sql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='${name}'")
  [[ ${count:-0} == 1 ]]
}

random_token() {
  php -r 'echo substr(str_replace(["+","/","="], "", base64_encode(random_bytes(24))), 0, 24);'
}

create_db_user() {
  local db=$1 user=$2 pass=$3
  note_secret "$pass"
  mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${user}'@'localhost' IDENTIFIED BY '${pass}';
ALTER USER '${user}'@'localhost' IDENTIFIED BY '${pass}';
GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'localhost';
FLUSH PRIVILEGES;
SQL
}

choose_new_database() {
  local base candidate
  base=$(basename "$ROOT" | tr -cd 'A-Za-z0-9_')
  [[ -n $base ]] || base=site
  if [[ $base == drupal ]]; then
    candidate=drupal
  else
    candidate="drupal_${base}"
  fi
  candidate=${candidate:0:48}
  if db_exists "$candidate"; then
    candidate="${candidate:0:40}_$(openssl rand -hex 3)"
  fi
  printf '%s' "$candidate"
}

settings_file() {
  printf '%s' "$ROOT/web/sites/default/settings.php"
}

read_settings_json() {
  local file=$1
  php -r '
    $databases = [];
    $settings = [];
    $config = [];
    include $argv[1];
    $d = $databases["default"]["default"] ?? [];
    if (isset($d["prefix"]) && is_array($d["prefix"])) {
      fwrite(STDERR, "array prefix\n");
      exit(2);
    }
    echo json_encode([
      "database" => (string) ($d["database"] ?? ""),
      "username" => (string) ($d["username"] ?? ""),
      "password" => (string) ($d["password"] ?? ""),
      "host" => (string) ($d["host"] ?? "localhost"),
      "port" => (string) ($d["port"] ?? "3306"),
      "prefix" => (string) ($d["prefix"] ?? ""),
      "hash_salt" => (string) ($settings["hash_salt"] ?? ""),
      "private" => (string) ($settings["file_private_path"] ?? ""),
      "config_sync" => (string) ($settings["config_sync_directory"] ?? ""),
      "trusted" => (is_array($settings["trusted_host_patterns"] ?? null) && isset($settings["trusted_host_patterns"][0]) && is_string($settings["trusted_host_patterns"][0])) ? $settings["trusted_host_patterns"][0] : "",
    ], JSON_UNESCAPED_SLASHES);
  ' "$file"
}

load_db_from_settings() {
  local file json
  file=$(settings_file)
  [[ -f $file ]] || return 1
  json=$(read_settings_json "$file") || return 1
  DB_NAME=$(php -r 'echo json_decode($argv[1], true)["database"] ?? "";' "$json")
  DB_USER=$(php -r 'echo json_decode($argv[1], true)["username"] ?? "";' "$json")
  DB_PASS=$(php -r 'echo json_decode($argv[1], true)["password"] ?? "";' "$json")
  DB_HOST=$(php -r 'echo json_decode($argv[1], true)["host"] ?? "localhost";' "$json")
  DB_PORT=$(php -r 'echo json_decode($argv[1], true)["port"] ?? "3306";' "$json")
  DB_PREFIX=$(php -r 'echo json_decode($argv[1], true)["prefix"] ?? "";' "$json")
  [[ $DB_PREFIX =~ ^[A-Za-z0-9_]*$ ]] || die "不支持的数据库表前缀。"
  [[ -n $DB_NAME && -n $DB_USER ]] || return 1
  note_secret "$DB_PASS"
  local test_cnf
  test_cnf=$(mktemp)
  chmod 600 "$test_cnf"
  php -r '
    $pass = str_replace(["\\", "\""], ["\\\\", "\\\""], $argv[2]);
    $ini = "[client]\nuser=".$argv[1]."\npassword=\"".$pass."\"\nhost=".$argv[3]."\nport=".$argv[4]."\n";
    file_put_contents($argv[5], $ini);
    chmod($argv[5], 0600);
  ' "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT" "$test_cnf"
  if mysql --defaults-extra-file="$test_cnf" -N -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1; then
    rm -f "$test_cnf"
    write_cnf "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT"
    return 0
  fi
  rm -f "$test_cnf"
  return 1
}

count_query() {
  local table=$1 where=${2-}
  local sql="SELECT COUNT(*) FROM \`${DB_PREFIX}${table}\`"
  if ! table_exists "${DB_PREFIX}${table}"; then
    printf '0'
    return 0
  fi
  if [[ -n $where ]]; then
    sql+=" WHERE ${where}"
  fi
  sql_scalar "$sql"
}

structure_only() {
  local table=$1 base=${1#"$DB_PREFIX"}
  case $base in
    cache|cache_*|sessions|watchdog|flood|key_value_expire) return 0 ;;
    *) return 1 ;;
  esac
}

dump_migration_sql() {
  local dest=$1 err
  err=$(mktemp)
  chmod 600 "$err"
  local -a struct=() data=()
  local table
  while IFS= read -r table; do
    [[ -n $table ]] || continue
    if structure_only "$table"; then
      struct+=("$table")
    else
      data+=("$table")
    fi
  done < <(mysql --defaults-extra-file="$CNF" -N -B -e "SHOW TABLES" "$DB_NAME")
  if ! {
    printf 'SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n'
    if [[ ${#struct[@]} -gt 0 ]]; then
      mysqldump --defaults-extra-file="$CNF" --single-transaction --default-character-set=utf8mb4 --no-tablespaces --no-data "$DB_NAME" "${struct[@]}" 2>>"$err"
    fi
    if [[ ${#data[@]} -gt 0 ]]; then
      mysqldump --defaults-extra-file="$CNF" --single-transaction --default-character-set=utf8mb4 --no-tablespaces "$DB_NAME" "${data[@]}" 2>>"$err"
    fi
    printf 'SET FOREIGN_KEY_CHECKS=1;\n'
  } | gzip -c >"$dest"; then
    die "mysqldump 失败。$(redact "$(cat "$err")")"
  fi
  rm -f "$err"
  [[ -s $dest ]] || die "数据库导出文件是空的。"
}

dump_backup_sql() {
  local dest=$1
  if ! db_exists "$DB_NAME"; then
    return 1
  fi
  local tables
  tables=$(mysql_root -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")
  [[ ${tables:-0} -gt 0 ]] || return 1
  mysqldump --protocol=socket -u root --single-transaction --default-character-set=utf8mb4 --no-tablespaces --databases "$DB_NAME" | gzip -c >"$dest"
  [[ -s $dest ]]
}

count_files_in() {
  local dir=$1 generated=$2
  if [[ ! -d $dir ]]; then
    printf '0'
    return 0
  fi
  if [[ $generated == 1 ]]; then
    find "$dir" \( -path '*/php' -o -path '*/php/*' -o -path '*/css' -o -path '*/css/*' -o -path '*/js' -o -path '*/js/*' -o -path '*/styles' -o -path '*/styles/*' \) -prune -o -type f -print | wc -l | tr -d '[:space:]'
  else
    find "$dir" -type f | wc -l | tr -d '[:space:]'
  fi
}

assert_symlinks_inside() {
  local dir=$1 link target
  [[ -d $dir ]] || return 0
  while IFS= read -r link; do
    target=$(readlink -f "$link" || true)
    case $target in
      "$dir"|"$dir"/*) ;;
      *) die "目录中有指向外部的符号链接，已中止: ${link}" ;;
    esac
  done < <(find "$dir" -type l)
}

pack_tree() {
  local src=$1 dest=$2 kind=$3
  local empty
  if [[ ! -d $src ]]; then
    empty=$(mktemp -d)
    tar -C "$empty" -czf "$dest" .
    rm -rf "$empty"
    return 0
  fi
  assert_symlinks_inside "$src"
  if [[ $kind == public ]]; then
    tar -C "$src" --exclude=php --exclude=css --exclude=js --exclude=styles -czf "$dest" .
  else
    tar -C "$src" -czf "$dest" .
  fi
}

sha256_of() {
  sha256sum "$1" | awk '{print $1}'
}

manifest_get() {
  local key=$1 file=$2
  awk -F= -v k="$key" '$1==k {sub(/^[^=]*=/,""); print; exit}' "$file"
}

public_files_dir() {
  printf '%s' "$ROOT/web/sites/default/files"
}

inside_webroot() {
  local path=$1 webroot parent real rest
  [[ -n $path ]] || return 0
  [[ $path == /* ]] || return 0
  webroot=$(readlink -f "$ROOT/web")
  parent=$path
  while [[ ! -d $parent && $parent != / ]]; do
    parent=$(dirname "$parent")
  done
  real=$(readlink -f "$parent")
  rest=${path#"$parent"}
  real="${real}${rest}"
  case $real in
    "$webroot"|"$webroot"/*) return 0 ;;
    *) return 1 ;;
  esac
}

decide_private_path() {
  local current=$1
  if [[ -z $current ]] || inside_webroot "$current"; then
    printf '%s' "$ROOT/private"
  else
    printf '%s' "$current"
  fi
}

git_dirty_paths() {
  [[ -d $ROOT/.git ]] || return 0
  gitc status --porcelain --untracked-files=all | sed 's/^...//' | while IFS= read -r path; do
    case $path in
      web/sites/default/settings.php|web/sites/default/settings.local.php) ;;
      web/sites/*/files|web/sites/*/files/*) ;;
      *) printf '%s\n' "$path" ;;
    esac
  done
}

restore_scaffold_files() {
  local path restored=0
  while IFS= read -r path; do
    [[ -n $path ]] || continue
    case $path in
      web/robots.txt|web/.htaccess|web/web.config|web/sites/default/default.settings.php|web/sites/default/default.services.yml|web/sites/development.services.yml)
        gitc checkout -- "$path" || return 1
        restored=1
        ;;
    esac
  done < <(git_dirty_paths || true)
  [[ $restored == 1 ]] && log "已恢复 Composer 脚手架改动的跟踪文件，以便重新检出。"
  return 0
}

require_clean_or_yes() {
  local dirty
  dirty=$(git_dirty_paths || true)
  if [[ -n $dirty ]]; then
    log "工作区有未提交改动。这些改动不会进入迁移包，新服务器只会检出已提交的代码。"
    printf '%s\n' "$dirty" >&2
    [[ $YES == 1 ]] || die "未提交改动需要确认。确认后请加上 --yes 再导出。"
  fi
}

drupal_version_of() {
  local file=$1
  [[ -f $file ]] || die "找不到 ${file}，无法确认 Drupal 版本。"
  sed -n "s/.*const VERSION = '\\([^']*\\)'.*/\\1/p" "$file" | head -n 1
}

composer_install() {
  [[ -f $ROOT/composer.json && -f $ROOT/composer.lock ]] || die "缺少 composer.json 或 composer.lock。"
  COMPOSER_MEMORY_LIMIT=768M composer install --no-dev --no-interaction --prefer-dist --no-progress --working-dir="$ROOT"
}

ensure_code() {
  local commit=$1
  if [[ ! -e $ROOT ]]; then
    mkdir -p "$(dirname "$ROOT")"
    log "克隆代码到 ${ROOT}。仓库 $(redact "$REPO")"
    git clone "$REPO" "$ROOT" || die "克隆仓库失败。"
  elif [[ ! -d $ROOT/.git ]]; then
    die "目录 ${ROOT} 已存在但不是 git 仓库。为避免覆盖未知文件，已中止。"
  fi
  restore_scaffold_files || die "无法恢复 Composer 脚手架文件。"
  local dirty
  dirty=$(git_dirty_paths || true)
  if [[ -n $dirty ]]; then
    printf '%s\n' "$dirty" >&2
    die "目标代码目录有未提交改动，已中止，避免 checkout 覆盖。"
  fi
  if ! gitc cat-file -e "${commit}^{commit}" 2>/dev/null; then
    log "本地没有提交 ${commit}，尝试获取。"
    gitc fetch origin "$commit" || gitc fetch origin || die "无法获取提交 ${commit}。请确认仓库可访问，且该提交已推送。"
  fi
  gitc cat-file -e "${commit}^{commit}" 2>/dev/null || die "仓库里没有提交 ${commit}。导入已中止。"
  gitc checkout --detach "$commit" || die "检出 ${commit} 失败。"
  log "已检出 ${commit}。"
}

check_versions() {
  local manifest_php=$1 manifest_drupal=$2 commit=$3
  local have_php have_mm want_mm have_drupal
  have_php=$(php -r 'echo PHP_VERSION;')
  have_mm=$(php_mm_from_full "$have_php")
  want_mm=$(php_mm_from_full "$manifest_php")
  have_drupal=$(drupal_version_of "$ROOT/web/core/lib/Drupal.php")
  if [[ $have_drupal != "$manifest_drupal" ]]; then
    die "Drupal 版本不匹配。包内为 ${manifest_drupal}，提交 ${commit} 检出的代码是 ${have_drupal}。请确认导出后的代码已推送，并重新导出或检出对应提交。"
  fi
  if [[ $have_mm != "$want_mm" ]]; then
    die "PHP 版本不匹配。包内 PHP 为 ${manifest_php}（要求主次版本 ${want_mm}），当前是 ${have_php}。请安装 PHP ${want_mm} 后重新执行导入。"
  fi
  if [[ $have_php != "$manifest_php" ]]; then
    log "PHP 补丁版本不同：包内 ${manifest_php}，当前 ${have_php}。主次版本 ${want_mm} 相同，继续。"
  else
    log "PHP ${have_php} 与 Drupal ${have_drupal} 与包内记录一致。"
  fi
}

disk_ok() {
  local path=$1 need=$2 dir avail
  dir=$path
  [[ -d $dir ]] || dir=$(dirname "$path")
  avail=$(df -B1 --output=avail "$dir" | tail -n 1 | tr -d '[:space:]')
  if [[ ${avail:-0} -lt $need ]]; then
    die "磁盘空间不足：${dir} 可用 ${avail} 字节，至少需要包大小的 3 倍（${need} 字节）。"
  fi
  log "磁盘检查通过：${dir} 可用 ${avail} 字节，需要 ${need} 字节。"
}

safe_tar_list() {
  local archive=$1 member
  while IFS= read -r member; do
    case $member in
      /*|../*|*/../*|*/..) die "压缩包里有不安全路径: ${member}" ;;
    esac
  done < <(tar -tzf "$archive")
}

extract_archive() {
  local archive=$1 dest=$2
  safe_tar_list "$archive"
  mkdir -p "$dest"
  tar --no-same-owner -xzf "$archive" -C "$dest"
}

write_settings_block() {
  local salt=$1 private=$2 sync=$3
  local file default spec
  file=$(settings_file)
  default=$ROOT/web/sites/default/default.settings.php
  spec=$WORK/settings-spec.json
  mkdir -p "$(dirname "$file")"
  chmod u+w "$(dirname "$file")" || true
  if [[ -f $file ]]; then
    chmod u+w "$file" || true
  fi
  php -r '
    $spec = [
      "database" => $argv[1],
      "username" => $argv[2],
      "password" => $argv[3],
      "prefix" => $argv[4],
      "host" => $argv[5],
      "port" => $argv[6],
      "hash_salt" => $argv[7],
      "private" => $argv[8],
      "config_sync" => $argv[9],
      "trusted" => array_values(array_filter(explode("\n", $argv[10]), "strlen")),
    ];
    file_put_contents($argv[11], json_encode($spec, JSON_UNESCAPED_SLASHES));
    chmod($argv[11], 0600);
  ' "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_PREFIX" "$DB_HOST" "$DB_PORT" "$salt" "$private" "$sync" "$(trusted_patterns "$DOMAIN")" "$spec"
  php -r '
    $spec = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $path = $argv[2];
    $default = $argv[3];
    if (!is_file($path)) {
      if (!is_file($default)) { fwrite(STDERR, "missing default.settings.php\n"); exit(1); }
      if (!copy($default, $path)) { exit(1); }
    }
    $raw = file_get_contents($path);
    $begin = "// BEGIN site-migrate\n";
    $end = "// END site-migrate\n";
    $start = strpos($raw, $begin);
    $finish = strpos($raw, $end);
    if ($start !== false && $finish !== false && $finish > $start) {
      $raw = substr($raw, 0, $start) . substr($raw, $finish + strlen($end));
    }
    $db = [
      "database" => $spec["database"],
      "username" => $spec["username"],
      "password" => $spec["password"],
      "prefix" => $spec["prefix"],
      "host" => $spec["host"],
      "port" => (string) $spec["port"],
      "isolation_level" => "READ COMMITTED",
      "driver" => "mysql",
      "namespace" => "Drupal\\mysql\\Driver\\Database\\mysql",
      "autoload" => "core/modules/mysql/src/Driver/Database/mysql/",
    ];
    $block = $begin;
    $block .= "\$databases[\"default\"][\"default\"] = " . var_export($db, true) . ";\n";
    $block .= "\$settings[\"hash_salt\"] = " . var_export($spec["hash_salt"], true) . ";\n";
    $block .= "\$settings[\"trusted_host_patterns\"] = " . var_export($spec["trusted"], true) . ";\n";
    $block .= "\$settings[\"file_private_path\"] = " . var_export($spec["private"], true) . ";\n";
    if (($spec["config_sync"] ?? "") !== "") {
      $block .= "\$settings[\"config_sync_directory\"] = " . var_export($spec["config_sync"], true) . ";\n";
    }
    $block .= $end;
    if (file_put_contents($path, rtrim($raw) . "\n\n" . $block) === false) { exit(1); }
  ' "$spec" "$file" "$default" || die "写入 settings.php 失败。"
  chown root:www-data "$file"
  chmod 440 "$file"
  rm -f "$spec"
  log "已更新 settings.php 的数据库连接、trusted_host_patterns、hash_salt 和私有文件路径。"
}

fix_ownership() {
  local public=$1 private=$2
  mkdir -p "$public" "$private"
  chown -R www-data:www-data "$public" "$private"
  find "$public" "$private" -type d -exec chmod 775 {} +
  find "$public" "$private" -type f -exec chmod 664 {} +
  chown root:www-data "$ROOT/web/sites/default"
  chmod 755 "$ROOT/web/sites/default"
}

replace_tree() {
  local archive=$1 dest=$2
  mkdir -p "$dest"
  find "$dest" -mindepth 1 -delete
  extract_archive "$archive" "$dest"
}

git_head() {
  gitc rev-parse HEAD
}

write_report() {
  local result=$1 body=$2
  umask 077
  {
    printf '结果: %s\n' "$result"
    printf '时间: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '脚本版本: %s\n' "$SCRIPT_VERSION"
    printf '%s\n' "$body"
  } >"$REPORT"
  chmod 600 "$REPORT"
  REPORT_WRITTEN=1
  log "验收报告已写入 ${REPORT}"
}

like_pattern() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/%/\\%/g; s/_/\\_/g'
}

sql_quote() {
  printf '%s' "$1" | sed "s/'/''/g"
}

scan_old_domain() {
  local domain=$1 like q names count name
  like=$(like_pattern "$domain")
  q=$(sql_quote "$like")
  {
    printf '残留旧域名扫描（只报告，不修改）:\n'
    printf '旧域名: %s\n' "$domain"
    if table_exists config; then
      names=$(mysql_site -N -B -e "SELECT name FROM config WHERE data LIKE '%${q}%' ESCAPE '\\\\' ORDER BY name" || true)
      count=$(printf '%s\n' "$names" | awk 'NF{c++} END{print c+0}')
      printf '含旧域名的配置项数量: %s\n' "$count"
      if [[ -n $names ]]; then
        printf '%s\n' "$names" | head -n 200 | sed 's/^/  配置: /'
      fi
      for name in matomo.settings smtp.settings system.site; do
        if printf '%s\n' "$names" | grep -qx "$name"; then
          printf '重点: %s 含旧域名，未修改\n' "$name"
        else
          printf '重点: %s 未发现旧域名\n' "$name"
        fi
      done
      if printf '%s\n' "$names" | grep -q 'metatag'; then
        printf '重点: metatag 相关配置含旧域名，未修改\n'
      else
        printf '重点: metatag 未发现旧域名\n'
      fi
    else
      printf '配置表不存在，跳过配置扫描。\n'
    fi
    if table_exists node__body; then
      count=$(sql_scalar "SELECT COUNT(*) FROM node__body WHERE body_value LIKE '%${q}%' ESCAPE '\\\\'")
      printf '节点正文含旧域名的行数: %s\n' "$count"
    else
      printf '节点正文表不存在。\n'
    fi
    if table_exists menu_link_content_data; then
      count=$(sql_scalar "SELECT COUNT(*) FROM menu_link_content_data WHERE link__uri LIKE '%${q}%' ESCAPE '\\\\' OR link__title LIKE '%${q}%' ESCAPE '\\\\'")
      printf '菜单链接含旧域名的行数: %s\n' "$count"
    else
      printf '菜单链接表不存在。\n'
    fi
    printf '以上用户内容和邮件、统计、Metatag 地址均未做字符串替换。\n'
  }
}

data_gate() {
  local nodes users
  nodes=$(count_query node_field_data)
  users=$(count_query users_field_data "uid > 1")
  log "目标库节点数 ${nodes}，uid>1 用户数 ${users}。"
  if [[ ${nodes:-0} -gt 0 || ${users:-0} -gt 0 ]]; then
    if [[ $FORCE != 1 || $YES != 1 ]]; then
      die "目标库已有节点或 uid>1 的用户。覆盖前已中止，没有备份也没有写入。确认要覆盖时，请同时加上 --force 和 --yes。"
    fi
    log "已同时提供 --force 和 --yes，将在备份后覆盖目标库。"
  fi
}

create_backup() {
  local stamp dir public private settings had_tables=0 had_settings=0 had_public=0 had_private=0
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  dir=/root/site-migrate-backup-${stamp}
  mkdir -p "$dir"
  chmod 700 "$dir"
  public=$(public_files_dir)
  private=$1
  settings=$(settings_file)
  if [[ -n $DB_NAME ]] && dump_backup_sql "$dir/database.sql.gz"; then
    chmod 600 "$dir/database.sql.gz"
    had_tables=1
    log "已备份数据库到 ${dir}/database.sql.gz"
  else
    log "导入前没有可备份的数据库表。"
  fi
  if [[ -d $public ]]; then
    had_public=1
    pack_tree "$public" "$dir/public-files.tgz" public
    chmod 600 "$dir/public-files.tgz"
  fi
  if [[ -n $private && -d $private ]]; then
    had_private=1
    pack_tree "$private" "$dir/private-files.tgz" private
    chmod 600 "$dir/private-files.tgz"
  fi
  if [[ -f $settings ]]; then
    had_settings=1
    cp -a "$settings" "$dir/settings.php"
    chmod 600 "$dir/settings.php"
  fi
  cat >"$dir/backup.txt" <<EOF
version=1
root=${ROOT}
public_path=${public}
private_path=${private}
database=${DB_NAME}
had_tables=${had_tables}
had_settings=${had_settings}
had_public=${had_public}
had_private=${had_private}
EOF
  chmod 600 "$dir/backup.txt"
  ROLLBACK_CMD="$(readlink -f "$0") rollback --backup ${dir} --yes"
  printf '回滚命令: %s\n' "$ROLLBACK_CMD"
  log "覆盖前备份目录: ${dir}"
}

ensure_database() {
  if load_db_from_settings; then
    log "沿用当前 settings.php 里的数据库连接。"
    return 0
  fi
  local db="" user pass named=""
  if [[ -f $(settings_file) ]]; then
    named=$(read_settings_json "$(settings_file)" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["database"] ?? "";')
  fi
  if [[ $named =~ ^[A-Za-z0-9_]+$ ]]; then
    if ! db_has_content "$named" || [[ $FORCE == 1 && $YES == 1 ]]; then
      db=$named
    else
      log "settings.php 指向的数据库 ${named} 已有内容，但账号无法连接。为避免覆盖，改用一个新库。"
    fi
  fi
  if [[ -z $db ]]; then
    db=$(choose_new_database)
  fi
  user=${db:0:32}
  pass=$(random_token | tr -d '\n')
  create_db_user "$db" "$user" "$pass"
  DB_NAME=$db
  DB_USER=$user
  DB_PASS=$pass
  DB_HOST=localhost
  DB_PORT=3306
  [[ $DB_PREFIX =~ ^[A-Za-z0-9_]*$ ]] || DB_PREFIX=""
  write_cnf "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT"
  log "已准备数据库 ${DB_NAME} 和账号 ${DB_USER}。密码只写入凭据文件。"
}

write_credentials() {
  local admin_name=$1 admin_uid=$2 new_pass=${3-}
  umask 077
  {
    printf '数据库名: %s\n' "$DB_NAME"
    printf '数据库账号: %s\n' "$DB_USER"
    printf '数据库密码: %s\n' "$DB_PASS"
    printf '数据库主机: %s\n' "$DB_HOST"
    printf '管理员用户名: %s\n' "$admin_name"
    printf '管理员 uid: %s\n' "$admin_uid"
    if [[ -n $new_pass ]]; then
      printf '管理员新密码: %s\n' "$new_pass"
      printf '登录说明: 已重置该管理员密码。请使用上面的新密码登录。\n'
    else
      printf '登录说明: Drupal 只保存密码哈希，这里不记录原密码。请用搬家前的原密码登录。\n'
    fi
  } >"$CREDENTIALS"
  chmod 600 "$CREDENTIALS"
  log "凭据已写入 ${CREDENTIALS}（权限 600）。"
  printf '凭据文件: %s\n' "$CREDENTIALS"
}

reset_admin_password() {
  local name=$1 pass
  pass=$(random_token | tr -d '\n')
  note_secret "$pass"
  run_drush user:password "$name" "$pass" >/dev/null
  printf '%s' "$pass"
}

update_base_url() {
  local current scheme value
  if ! run_drush config:get simple_sitemap.settings base_url --format=string >/dev/null 2>&1; then
    log "没有 simple_sitemap.settings，跳过 base_url。"
    printf '未配置'
    return 0
  fi
  current=$(run_drush config:get simple_sitemap.settings base_url --format=string 2>/dev/null || true)
  scheme=http
  [[ $current == https:* ]] && scheme=https
  value="${scheme}://${DOMAIN}"
  run_drush config:set simple_sitemap.settings base_url "$value" -y >/dev/null
  log "simple_sitemap.settings base_url 已设为 ${value}。"
  printf '%s' "$value"
}

http_code() {
  local code="" i
  for ((i = 1; i <= 5; i++)); do
    code=$(curl -sI -o /dev/null -w '%{http_code}' --max-time 60 -H "Host: ${DOMAIN}" "http://127.0.0.1/" || true)
    if [[ $code == 200 || $code == 3* ]]; then
      printf '%s' "$code"
      return 0
    fi
    sleep 2
  done
  printf '%s' "${code:-000}"
}

prepare_environment() {
  local mm=$1
  allow_services_without_systemd
  ensure_php "$mm"
  start_mariadb
  start_php_fpm
  if [[ -n $DOMAIN ]]; then
    write_vhost "$DOMAIN" "$ROOT"
    start_nginx
  fi
}

cmd_setup() {
  [[ -n $DOMAIN ]] || die "setup 必须提供 --domain。"
  local mm=${PHP_MM:-$DEFAULT_PHP_MM}
  log "开始安装环境。域名 ${DOMAIN}，目录 ${ROOT}，PHP ${mm}。"
  prepare_environment "$mm"
  if [[ ! -e $ROOT ]]; then
    mkdir -p "$(dirname "$ROOT")"
    git clone "$REPO" "$ROOT" || die "克隆仓库失败。"
    log "已克隆仓库到 ${ROOT}。"
  elif [[ -d $ROOT/.git ]]; then
    log "目录已存在，跳过克隆。"
  fi
  if [[ -f $ROOT/composer.json ]]; then
    composer_install
  fi
  if [[ -f $(settings_file) ]] && load_db_from_settings; then
    log "数据库连接可用，setup 不改写 settings.php。"
  elif [[ -f $(settings_file) ]] && db_has_content "$(read_settings_json "$(settings_file)" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["database"] ?? "";')"; then
    log "settings.php 指向的数据库已有内容但账号无法连接。setup 不覆盖该库。"
  else
    local salt private sync json
    json=""
    if [[ -f $(settings_file) ]]; then
      json=$(read_settings_json "$(settings_file)")
      DB_PREFIX=$(php -r '$j=json_decode($argv[1], true); echo $j["prefix"] ?? "";' "$json")
    fi
    ensure_database
    salt=""
    sync=""
    private=""
    if [[ -n $json ]]; then
      salt=$(php -r '$j=json_decode($argv[1], true); echo $j["hash_salt"] ?? "";' "$json")
      sync=$(php -r '$j=json_decode($argv[1], true); echo $j["config_sync"] ?? "";' "$json")
      private=$(php -r '$j=json_decode($argv[1], true); echo $j["private"] ?? "";' "$json")
    fi
    if [[ -z $salt ]]; then
      salt=$(php -r 'echo substr(strtr(base64_encode(random_bytes(48)), "+/", "-_"), 0, 55);')
      log "settings.php 没有 hash_salt，已新生成。"
    else
      log "沿用 settings.php 里已有的 hash_salt。"
    fi
    note_secret "$salt"
    private=$(decide_private_path "$private")
    mkdir -p "$private"
    write_settings_block "$salt" "$private" "$sync"
    write_credentials "尚未导入" "-"
  fi
  start_nginx
  log "环境安装完成。可以重新执行本命令，已安装的步骤会跳过。"
}

enable_export_maint() {
  local table="${DB_PREFIX}key_value" hex new_hex
  table_exists "$table" || die "找不到 ${table}，无法打开维护模式。导出已中止，站点未被修改。"
  hex=$(mysql_site -N -B -e "SELECT HEX(value) FROM \`${table}\` WHERE collection='state' AND name='system.maintenance_mode'" || true)
  hex=$(printf '%s' "$hex" | tr -d '[:space:]')
  if [[ -n $hex ]]; then
    [[ $hex =~ ^[0-9A-Fa-f]+$ ]] || die "维护模式的现有值无法安全读回。导出已中止，站点未被修改。"
    EXPORT_MAINT_HAD_ROW=1
    EXPORT_MAINT_HEX=$hex
  else
    EXPORT_MAINT_HAD_ROW=0
    EXPORT_MAINT_HEX=""
  fi
  new_hex=$(php -r 'echo bin2hex(serialize(1));')
  if [[ $EXPORT_MAINT_HAD_ROW == 1 ]]; then
    mysql_site -e "UPDATE \`${table}\` SET value=UNHEX('${new_hex}') WHERE collection='state' AND name='system.maintenance_mode'"
  else
    mysql_site -e "INSERT INTO \`${table}\` (collection, name, value) VALUES ('state', 'system.maintenance_mode', UNHEX('${new_hex}'))"
  fi
  EXPORT_MAINT_ACTIVE=1
  bust_maint_cache
}

bust_maint_cache() {
  local table
  table="${DB_PREFIX}cache_bootstrap"
  if table_exists "$table"; then
    mysql_site -e "DELETE FROM \`${table}\` WHERE cid='values'"
  fi
  for table in "${DB_PREFIX}cache_page" "${DB_PREFIX}cache_dynamic_page_cache"; do
    if table_exists "$table"; then
      mysql_site -e "DELETE FROM \`${table}\`"
    fi
  done
}

restore_export_maint() {
  local table="${DB_PREFIX}key_value"
  [[ $EXPORT_MAINT_ACTIVE == 1 ]] || return 0
  EXPORT_MAINT_ACTIVE=0
  if [[ $EXPORT_MAINT_HAD_ROW == 1 ]]; then
    [[ $EXPORT_MAINT_HEX =~ ^[0-9A-Fa-f]+$ ]] || return 1
    mysql_site -e "UPDATE \`${table}\` SET value=UNHEX('${EXPORT_MAINT_HEX}') WHERE collection='state' AND name='system.maintenance_mode'"
  else
    mysql_site -e "DELETE FROM \`${table}\` WHERE collection='state' AND name='system.maintenance_mode'"
  fi
  bust_maint_cache
}

detect_old_domain_db() {
  local table="${DB_PREFIX}config" hex host pattern
  if [[ -n $OLD_DOMAIN ]]; then
    printf '%s' "$OLD_DOMAIN"
    return 0
  fi
  if table_exists "$table"; then
    hex=$(mysql_site -N -B -e "SELECT HEX(data) FROM \`${table}\` WHERE name='simple_sitemap.settings'" || true)
    hex=$(printf '%s' "$hex" | tr -d '[:space:]')
    if [[ $hex =~ ^[0-9A-Fa-f]+$ ]]; then
      host=$(php -r '
        $raw = hex2bin($argv[1]);
        $data = @unserialize($raw);
        if (!is_array($data)) { exit(0); }
        $url = (string) ($data["base_url"] ?? "");
        $h = parse_url($url, PHP_URL_HOST);
        if (is_string($h)) { echo $h; }
      ' "$hex" || true)
      if valid_domain "${host:-}"; then
        printf '%s' "$host"
        return 0
      fi
    fi
  fi
  pattern=$(php -r 'echo json_decode($argv[1], true)["trusted"] ?? "";' "$SETTINGS_JSON")
  host=$(php -r '
    $p = $argv[1];
    $p = preg_replace("/^\^/", "", $p);
    $p = preg_replace("/\$$/", "", $p);
    $p = str_replace(["\\.", "\\-"], [".", "-"], $p);
    if (preg_match("/^[A-Za-z0-9.-]+$/", $p)) { echo $p; }
  ' "$pattern" || true)
  printf '%s' "$host"
}

cmd_export() {
  local out_dir=${OUT:-/root/site-migrate}
  out_dir=$(abs_path "$out_dir")
  [[ -d $ROOT ]] || die "站点目录不存在: ${ROOT}"
  [[ -d $ROOT/.git ]] || die "站点目录不是 git 仓库，无法记录提交号。导出已中止。"
  require_clean_or_yes
  case $(readlink -f "$out_dir") in
    "$(readlink -f "$ROOT")"/web|"$(readlink -f "$ROOT")"/web/*)
      die "输出目录不能放在 webroot 内。"
      ;;
  esac
  load_db_from_settings || die "无法读取 settings.php 或连接数据库。导出已中止，站点未被修改。"
  SETTINGS_JSON=$(read_settings_json "$(settings_file)")
  log "开始只读导出。不修改数据库内容、上传文件和 settings.php。"
  if [[ $MAINTENANCE == 1 ]]; then
    enable_export_maint || die "无法打开维护模式。导出已中止。"
    log "已临时打开维护模式。结束或异常退出时会恢复原来的值。"
  fi
  local old_domain private salt sync nodes users files public stamp stage manifest
  old_domain=$(detect_old_domain_db | tr -d '[:space:]')
  valid_domain "$old_domain" || die "无法确定旧域名（得到「${old_domain:-空}」）。请用 --old-domain 指定。导出已中止，站点未被修改。"
  private=$(php -r 'echo json_decode($argv[1], true)["private"] ?? "";' "$SETTINGS_JSON")
  salt=$(php -r 'echo json_decode($argv[1], true)["hash_salt"] ?? "";' "$SETTINGS_JSON")
  note_secret "$salt"
  sync=$(php -r 'echo json_decode($argv[1], true)["config_sync"] ?? "";' "$SETTINGS_JSON")
  nodes=$(count_query node_field_data)
  users=$(count_query users_field_data)
  public=$(public_files_dir)
  files=$(count_files_in "$public" 1)
  if [[ -n $private && -d $private ]]; then
    local private_real public_real
    private_real=$(readlink -f "$private")
    public_real=$(readlink -f "$public" 2>/dev/null || printf '%s' "$public")
    case $private_real in
      "$public_real"|"$public_real"/*) ;;
      *) files=$((files + $(count_files_in "$private" 0))) ;;
    esac
  fi
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  stage=$WORK/stage
  mkdir -p "$stage"
  chmod 700 "$stage"
  dump_migration_sql "$stage/database.sql.gz"
  pack_tree "$public" "$stage/public-files.tgz" public
  if [[ -n $private && -d $private ]]; then
    pack_tree "$private" "$stage/private-files.tgz" private
  fi
  umask 077
  {
    printf 'time=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'script_version=%s\n' "$SCRIPT_VERSION"
    printf 'git_commit=%s\n' "$(git_head)"
    printf 'drupal_version=%s\n' "$(drupal_version_of "$ROOT/web/core/lib/Drupal.php")"
    printf 'php_version=%s\n' "$(php -r 'echo PHP_VERSION;')"
    printf 'old_domain=%s\n' "$old_domain"
    printf 'node_count=%s\n' "$nodes"
    printf 'user_count=%s\n' "$users"
    printf 'file_count=%s\n' "$files"
    printf 'private_path=%s\n' "$private"
    printf 'db_prefix=%s\n' "$DB_PREFIX"
    printf 'config_sync_directory=%s\n' "$sync"
    printf 'hash_salt=%s\n' "$salt"
    printf 'database.sql.gz=%s\n' "$(sha256_of "$stage/database.sql.gz")"
    printf 'public-files.tgz=%s\n' "$(sha256_of "$stage/public-files.tgz")"
    if [[ -f $stage/private-files.tgz ]]; then
      printf 'private-files.tgz=%s\n' "$(sha256_of "$stage/private-files.tgz")"
    fi
  } >"$stage/manifest.txt"
  chmod 600 "$stage/manifest.txt" "$stage/database.sql.gz" "$stage/public-files.tgz"
  [[ -f $stage/private-files.tgz ]] && chmod 600 "$stage/private-files.tgz"
  mkdir -p "$out_dir"
  chmod 700 "$out_dir"
  local tgz=$out_dir/site-migrate-${stamp}.tgz
  local sha=$tgz.sha256
  if [[ -f $stage/private-files.tgz ]]; then
    tar -C "$stage" -czf "$tgz" manifest.txt database.sql.gz public-files.tgz private-files.tgz
  else
    tar -C "$stage" -czf "$tgz" manifest.txt database.sql.gz public-files.tgz
  fi
  chmod 600 "$tgz"
  (
    cd "$out_dir"
    sha256sum "$(basename "$tgz")" >"$(basename "$sha")"
  )
  chmod 600 "$sha"
  log "导出完成。节点 ${nodes}，用户 ${users}，文件 ${files}，旧域名 ${old_domain}。"
  printf '%s\n' "$tgz"
  printf '%s\n' "$sha"
}

verify_pack() {
  local pack=$1 dir=$2 sha member expected actual
  if [[ -f ${pack}.sha256 ]]; then
    log "校验包的 sha256。"
    (
      cd "$(dirname "$pack")"
      sha256sum -c "$(basename "${pack}.sha256")"
    ) || die "包的 sha256 与 ${pack}.sha256 不一致。导入已中止，站点未被修改。"
  else
    log "旁边没有 ${pack}.sha256，跳过整包校验，仍会校验 manifest 里的文件。"
  fi
  safe_tar_list "$pack"
  tar -xzf "$pack" -C "$dir"
  [[ -f $dir/manifest.txt && -f $dir/database.sql.gz && -f $dir/public-files.tgz ]] || die "包内缺少 manifest.txt、database.sql.gz 或 public-files.tgz。"
  for member in database.sql.gz public-files.tgz private-files.tgz; do
    [[ -f $dir/$member ]] || continue
    expected=$(manifest_get "$member" "$dir/manifest.txt")
    [[ -n $expected ]] || die "manifest 缺少 ${member} 的 sha256。"
    actual=$(sha256_of "$dir/$member")
    [[ $actual == "$expected" ]] || die "${member} 的 sha256 与 manifest 不一致。导入已中止，站点未被修改。"
  done
  log "manifest 内文件 sha256 已核对。"
}

cmd_import() {
  WRITE_FAIL_REPORT=1
  [[ -n $DOMAIN ]] || die "import 必须提供 --domain。"
  [[ -n $PACK && -f $PACK ]] || die "找不到迁移包。请用 --pack 指定 .tgz 文件。"
  local unpack=$WORK/pack
  mkdir -p "$unpack"
  local pack_size need
  pack_size=$(stat -c%s "$PACK")
  need=$((pack_size * 3))
  disk_ok /root "$need"
  disk_ok "$(dirname "$ROOT")" "$need"
  verify_pack "$PACK" "$unpack"
  local manifest=$unpack/manifest.txt
  local commit drupal_ver php_ver old_domain nodes_m users_m files_m private_src sync salt
  commit=$(manifest_get git_commit "$manifest")
  drupal_ver=$(manifest_get drupal_version "$manifest")
  php_ver=$(manifest_get php_version "$manifest")
  old_domain=$(manifest_get old_domain "$manifest")
  nodes_m=$(manifest_get node_count "$manifest")
  users_m=$(manifest_get user_count "$manifest")
  files_m=$(manifest_get file_count "$manifest")
  private_src=$(manifest_get private_path "$manifest")
  sync=$(manifest_get config_sync_directory "$manifest")
  salt=$(manifest_get hash_salt "$manifest")
  DB_PREFIX=$(manifest_get db_prefix "$manifest")
  [[ $DB_PREFIX =~ ^[A-Za-z0-9_]*$ ]] || die "manifest 中的数据库表前缀无效。"
  [[ -n $OLD_DOMAIN ]] && old_domain=$OLD_DOMAIN
  [[ -n $commit && -n $drupal_ver && -n $php_ver && -n $old_domain ]] || die "manifest 缺少提交号、Drupal 版本、PHP 版本或旧域名。"
  valid_domain "$old_domain" || die "manifest 中的旧域名无效: ${old_domain}"
  note_secret "$salt"
  log "包内提交 ${commit}，Drupal ${drupal_ver}，PHP ${php_ver}，旧域名 ${old_domain}。"
  local php_mm
  php_mm=$(php_mm_from_full "$php_ver")
  prepare_environment "$php_mm"
  if [[ -f $(settings_file) ]] && load_db_from_settings; then
    data_gate
  fi
  ensure_code "$commit"
  composer_install
  check_versions "$php_ver" "$drupal_ver" "$commit"
  drush_bin || die "找不到可执行的 ${ROOT}/vendor/bin/drush。请先完成 composer install。"
  ensure_database
  data_gate
  local private_final public
  private_final=$(decide_private_path "$private_src")
  public=$(public_files_dir)
  create_backup "$private_final"
  if [[ -n $salt ]]; then
    log "沿用迁移包里的旧 hash_salt。"
  elif [[ -f $(settings_file) ]]; then
    salt=$(read_settings_json "$(settings_file)" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["hash_salt"] ?? "";')
    if [[ -n $salt ]]; then
      log "迁移包没有 hash_salt，沿用 settings.php 里已有的值。"
    fi
  fi
  if [[ -z $salt ]]; then
    salt=$(php -r 'echo substr(strtr(base64_encode(random_bytes(48)), "+/", "-_"), 0, 55);')
    log "旧站点和 settings.php 都没有 hash_salt，已新生成。"
  fi
  note_secret "$salt"
  DB_PREFIX=$(manifest_get db_prefix "$manifest")
  write_settings_block "$salt" "$private_final" "$sync"
  write_cnf "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT"
  DRUSH_URI=$DOMAIN
  if bootstrap_ok; then
    maint_set 1 || die "无法打开维护模式。"
    IMPORT_MAINT=1
    log "已打开维护模式。"
  else
    log "导入数据库前站点还不能 bootstrap。导入后再打开维护模式。"
  fi
  log "导入数据库。"
  gzip -dc "$unpack/database.sql.gz" | mysql --defaults-extra-file="$CNF" "$DB_NAME"
  log "解压公共文件和私有文件。"
  replace_tree "$unpack/public-files.tgz" "$public"
  mkdir -p "$private_final"
  if [[ -f $unpack/private-files.tgz ]]; then
    replace_tree "$unpack/private-files.tgz" "$private_final"
  fi
  fix_ownership "$public" "$private_final"
  drush_bin || die "找不到可执行的 ${ROOT}/vendor/bin/drush。"
  bootstrap_ok || die "导入数据库后 bootstrap 仍未成功。请使用前面打印的回滚命令恢复。"
  maint_set 1 || die "导入后无法打开维护模式。"
  IMPORT_MAINT=1
  log "执行 drush updatedb。"
  run_drush updatedb -y
  log "执行 drush cache:rebuild。"
  run_drush cache:rebuild
  local base_url admin_name admin_uid new_pass=""
  base_url=$(update_base_url)
  admin_uid=$(sql_scalar "SELECT uid FROM ${DB_PREFIX}users_field_data WHERE uid=1")
  admin_name=$(mysql_site -N -B -e "SELECT name FROM ${DB_PREFIX}users_field_data WHERE uid=1")
  [[ -n $admin_name ]] || admin_name="(没有 uid 1)"
  if [[ $RESET_ADMIN == 1 && -n $admin_uid ]]; then
    new_pass=$(reset_admin_password "$admin_name")
    log "已重置管理员密码，新密码只写入凭据文件。"
  fi
  fix_ownership "$public" "$private_final"
  start_php_fpm
  start_nginx
  local nodes users files boot code scan body result
  nodes=$(count_query node_field_data)
  users=$(count_query users_field_data)
  files=$(count_files_in "$public" 1)
  if [[ -n $private_final && -d $private_final ]]; then
    local private_real public_real
    private_real=$(readlink -f "$private_final")
    public_real=$(readlink -f "$public")
    case $private_real in
      "$public_real"|"$public_real"/*) ;;
      *) files=$((files + $(count_files_in "$private_final" 0))) ;;
    esac
  fi
  boot=$(run_drush status --field=bootstrap 2>/dev/null | tr -d '[:space:]' || true)
  if maint_set 0; then
    IMPORT_MAINT=0
    log "已关闭维护模式。"
    run_drush cache:rebuild
  else
    log "关闭维护模式失败，首页检查可能不是正常页面。"
  fi
  code=$(http_code)
  scan=$(scan_old_domain "$old_domain")
  result=成功
  local reasons=""
  [[ $nodes == "$nodes_m" ]] || reasons+="节点数不一致（包内 ${nodes_m}，当前 ${nodes}）"$'\n'
  [[ $users == "$users_m" ]] || reasons+="用户数不一致（包内 ${users_m}，当前 ${users}）"$'\n'
  [[ $files == "$files_m" ]] || reasons+="文件数不一致（包内 ${files_m}，当前 ${files}）"$'\n'
  [[ $boot == "Successful" ]] || reasons+="bootstrap 为 ${boot:-未知}"$'\n'
  [[ $code == 200 || $code == 3* ]] || reasons+="首页 HTTP 为 ${code}"$'\n'
  if [[ -n $reasons ]]; then
    result=失败
    maint_set 1 || true
    IMPORT_MAINT=1
    log "验收未通过，已重新打开维护模式。"
  else
    IMPORT_SUCCEEDED=1
  fi
  write_credentials "$admin_name" "${admin_uid:-未知}" "$new_pass"
  body=$(
    printf '域名: %s\n' "$DOMAIN"
    printf '旧域名: %s\n' "$old_domain"
    printf 'git 提交: %s\n' "$commit"
    printf '节点数: 包内 %s / 当前 %s\n' "$nodes_m" "$nodes"
    printf '用户数: 包内 %s / 当前 %s\n' "$users_m" "$users"
    printf '文件数: 包内 %s / 当前 %s\n' "$files_m" "$files"
    printf 'bootstrap: %s\n' "${boot:-未知}"
    printf '首页 HTTP: %s\n' "$code"
    printf '私有文件路径: %s\n' "$private_final"
    printf 'simple_sitemap base_url: %s\n' "$base_url"
    printf '回滚命令: %s\n' "$ROLLBACK_CMD"
    if [[ -n $reasons ]]; then
      printf '失败原因:\n%s\n' "$reasons"
    fi
    printf '%s\n' "$scan"
  )
  write_report "$result" "$body"
  printf '验收报告: %s\n' "$REPORT"
  if [[ $result != 成功 ]]; then
    die "验收未通过，详情见 ${REPORT}"
  fi
  log "导入完成并通过验收。"
}

cmd_rollback() {
  [[ -n $BACKUP && -d $BACKUP ]] || die "请用 --backup 指定导入前生成的备份目录。"
  [[ $YES == 1 ]] || die "回滚会覆盖数据库和文件。确认后请加上 --yes。"
  if ! mysqladmin --protocol=socket -u root ping >/dev/null 2>&1; then
    allow_services_without_systemd
    start_mariadb
  fi
  [[ -f $BACKUP/backup.txt ]] || die "备份目录里没有 backup.txt。"
  local root_from public private dbname had_tables had_settings had_public had_private
  root_from=$(manifest_get root "$BACKUP/backup.txt")
  public=$(manifest_get public_path "$BACKUP/backup.txt")
  private=$(manifest_get private_path "$BACKUP/backup.txt")
  dbname=$(manifest_get database "$BACKUP/backup.txt")
  had_tables=$(manifest_get had_tables "$BACKUP/backup.txt")
  had_settings=$(manifest_get had_settings "$BACKUP/backup.txt")
  had_public=$(manifest_get had_public "$BACKUP/backup.txt")
  had_private=$(manifest_get had_private "$BACKUP/backup.txt")
  [[ -n $root_from ]] || die "backup.txt 缺少 root。"
  if [[ $ROOT != "$DEFAULT_ROOT" && $ROOT != "$root_from" ]]; then
    die "--root 与备份里的目录不一致: ${root_from}"
  fi
  ROOT=$root_from
  log "开始回滚 ${BACKUP} 到 ${ROOT}。"
  if [[ -f $BACKUP/settings.php ]]; then
    mkdir -p "$(dirname "$(settings_file)")"
    chmod u+w "$(dirname "$(settings_file)")" || true
    chmod u+w "$(settings_file)" 2>/dev/null || true
    cp -a "$BACKUP/settings.php" "$(settings_file)"
    chown root:www-data "$(settings_file)"
    chmod 440 "$(settings_file)"
  elif [[ $had_settings == 0 && -f $(settings_file) ]]; then
    rm -f "$(settings_file)"
  fi
  if [[ -f $BACKUP/database.sql.gz ]]; then
    gzip -dc "$BACKUP/database.sql.gz" | mysql_root
  elif [[ $had_tables == 0 && $dbname =~ ^[A-Za-z0-9_]+$ ]]; then
    mysql_root -e "DROP DATABASE IF EXISTS \`${dbname}\`; CREATE DATABASE \`${dbname}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    log "已把空库 ${dbname} 恢复成导入前的空库。"
  fi
  if [[ -f $BACKUP/public-files.tgz && -n $public ]]; then
    replace_tree "$BACKUP/public-files.tgz" "$public"
  elif [[ $had_public == 0 && -n $public && -d $public ]]; then
    find "$public" -mindepth 1 -delete
  fi
  if [[ -f $BACKUP/private-files.tgz && -n $private ]]; then
    replace_tree "$BACKUP/private-files.tgz" "$private"
  elif [[ $had_private == 0 && -n $private && -d $private ]]; then
    find "$private" -mindepth 1 -delete
  fi
  if [[ -n $public && -d $public ]]; then
    local own_private=$private
    [[ -n $own_private && -d $own_private ]] || own_private=$public
    fix_ownership "$public" "$own_private"
  fi
  drush_bin || true
  if bootstrap_ok; then
    DRUSH_URI=$DOMAIN
    [[ -n $DOMAIN ]] || DRUSH_URI=localhost
    run_drush cache:rebuild || log "回滚后 cache:rebuild 失败，请手动执行 drush cr。"
    maint_set 0 || log "回滚后未能关闭维护模式。"
  fi
  WRITE_FAIL_REPORT=1
  write_report "回滚成功" "备份目录: ${BACKUP}"$'\n'"站点目录: ${ROOT}"
  log "回滚完成。"
}

main() {
  if [[ ${1-} == -h || ${1-} == --help ]]; then
    usage
    exit 0
  fi
  require_root
  parse_args "$@"
  case $COMMAND in
    export|import|setup|rollback) ;;
    *) usage; exit 1 ;;
  esac
  init_runtime
  log "site-migrate.sh ${SCRIPT_VERSION} 命令 ${COMMAND}"
  case $COMMAND in
    export) cmd_export ;;
    import) cmd_import ;;
    setup) cmd_setup ;;
    rollback) cmd_rollback ;;
  esac
}

main "$@"
