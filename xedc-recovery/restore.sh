#!/usr/bin/env bash
# 从任意目录运行。不会在备份来源机器上执行恢复。
set -Eeuo pipefail

SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"
# shellcheck source=lib/database.sh
source "$SCRIPT_DIR/lib/database.sh"
# shellcheck source=lib/files.sh
source "$SCRIPT_DIR/lib/files.sh"
# shellcheck source=lib/environment.sh
source "$SCRIPT_DIR/lib/environment.sh"
# shellcheck source=lib/drupal.sh
source "$SCRIPT_DIR/lib/drupal.sh"
# shellcheck source=lib/domain.sh
source "$SCRIPT_DIR/lib/domain.sh"
# shellcheck source=lib/nginx.sh
source "$SCRIPT_DIR/lib/nginx.sh"
# shellcheck source=lib/ssl.sh
source "$SCRIPT_DIR/lib/ssl.sh"
# shellcheck source=lib/credentials.sh
source "$SCRIPT_DIR/lib/credentials.sh"
# shellcheck source=lib/github-software.sh
source "$SCRIPT_DIR/lib/github-software.sh"
# shellcheck source=lib/services.sh
source "$SCRIPT_DIR/lib/services.sh"
# shellcheck source=lib/media.sh
source "$SCRIPT_DIR/lib/media.sh"
# shellcheck source=lib/compare.sh
source "$SCRIPT_DIR/lib/compare.sh"
# shellcheck source=lib/verify.sh
source "$SCRIPT_DIR/lib/verify.sh"
# shellcheck source=lib/rollback.sh
source "$SCRIPT_DIR/lib/rollback.sh"

xedc_init

SNAPSHOT=""
DOMAIN=""
OLD_DOMAIN=""
NEW_DOMAIN=""
PHP_VERSION="8.5"
STEP=""
export DNS_PROVIDER=""
export SSL_METHOD=""
export XEDC_VERSION_STRATEGY=balanced
export XEDC_SSL_WILDCARD=0
KEEP_DB=0
FORCE=0
export AUTO_DNS=0
WITH_MATOMO=1
WITH_NETDATA=1
WITH_DOCKER=1
WITH_MEDIA=1

xedc_restore_usage() {
  cat <<'EOF'
用法:
  restore.sh --snapshot 目录 --domain 域名 [--php-version 8.5]
  restore.sh --snapshot 目录 --old-domain 旧域名 --new-domain 新域名
  restore.sh --show-credentials
  restore.sh --reset-admin --domain 域名
  restore.sh --login-link --domain 域名
  restore.sh --compare --snapshot 目录
  restore.sh --rollback
  restore.sh --dns-provider list
  restore.sh --retry-ssl --domain 域名
  restore.sh --renew-ssl --domain 域名
  restore.sh --ssl-status
  restore.sh --change-domain --old-domain 旧域名 --new-domain 新域名
  restore.sh --dns-provider cf --auto-dns-record --domain 域名
  restore.sh --dry-run --snapshot 目录 --domain 域名

敏感信息不要写在命令行里。数据库密码会写入 /root/.drupal-credentials。
EOF
}

while [[ $# -gt 0 ]]; do
  case $1 in
    --help|-h) xedc_restore_usage; exit 0 ;;
    --dry-run) export XEDC_DRY_RUN=1; shift ;;
    --force) FORCE=1; shift ;;
    --snapshot) SNAPSHOT=$2; shift 2 ;;
    --domain) DOMAIN=$2; shift 2 ;;
    --old-domain) OLD_DOMAIN=$2; shift 2 ;;
    --new-domain) NEW_DOMAIN=$2; shift 2 ;;
    --php-version) PHP_VERSION=$2; shift 2 ;;
    --versions)
      case ${2:-} in
        balanced|pinned|latest) export XEDC_VERSION_STRATEGY=$2 ;;
        *) xedc_die "--versions 只能是 balanced、pinned 或 latest。" ;;
      esac
      shift 2
      ;;
    --dns-credentials)
      [[ -f ${2:-} ]] || xedc_die "找不到 DNS 凭据文件。"
      cred_mode=$(stat -c %a "$2")
      [[ $cred_mode == 600 || $cred_mode == 400 ]] || xedc_die "DNS 凭据文件权限必须是 600。"
      export XEDC_DNS_ENV=$2
      shift 2
      ;;
    --wildcard) export XEDC_SSL_WILDCARD=1; shift ;;
    --step) STEP=$2; shift 2 ;;
    --dns-provider)
      if [[ ${2:-} == list ]]; then xedc_ssl_provider_list; exit 0; fi
      export DNS_PROVIDER=$2
      shift 2
      ;;
    --dns-manual) export SSL_METHOD=manual; shift ;;
    --ssl-method) export SSL_METHOD=$2; shift 2 ;;
    --auto-dns-record) export AUTO_DNS=1; shift ;;
    --retry-ssl) STEP=ssl; shift ;;
    --renew-ssl) STEP=renew; shift ;;
    --ssl-status) STEP=ssl-status; shift ;;
    --show-credentials) xedc_credentials_show; exit 0 ;;
    --reset-admin) STEP=reset-admin; shift ;;
    --login-link) STEP=login-link; shift ;;
    --keep-db-credentials) KEEP_DB=1; shift ;;
    --change-domain) STEP=change-domain; shift ;;
    --rollback) STEP=rollback; shift ;;
    --compare) STEP=compare; shift ;;
    --with-matomo) WITH_MATOMO=1; shift ;;
    --with-netdata) WITH_NETDATA=1; shift ;;
    --with-docker-services) WITH_DOCKER=1; shift ;;
    --with-media) WITH_MEDIA=1; shift ;;
    --without-matomo) WITH_MATOMO=0; shift ;;
    --without-netdata) WITH_NETDATA=0; shift ;;
    --without-docker-services) WITH_DOCKER=0; shift ;;
    --without-media) WITH_MEDIA=0; shift ;;
    *) xedc_die "未知参数：$1" ;;
  esac
done

xedc_should_run() {
  local name=$1
  [[ -z $STEP || $STEP == "$name" ]] || return 1
  if [[ -n $STEP ]]; then
    return 0
  fi
  xedc_step_done "$XEDC_STATE_FILE" "$name" && return 1
  return 0
}

xedc_mark() {
  [[ -n $STEP ]] && return 0
  xedc_step_mark "$XEDC_STATE_FILE" "$1"
}

if [[ $STEP == rollback ]]; then
  xedc_require_root
  latest=$(cat "$XEDC_STATE_ROOT/rollback-latest")
  xedc_rollback_apply "$latest" "${XEDC_SITE_ROOT}"
  exit 0
fi

if [[ $STEP == ssl-status ]]; then
  xedc_require_root
  if [[ -d /root/.acme.sh ]]; then
    find /root/.acme.sh -name '*.method' -print -exec cat {} \;
  else
    xedc_warn "还没有 acme.sh 记录。"
  fi
  exit 0
fi

if [[ $STEP == ssl || $STEP == renew ]]; then
  [[ -n $DOMAIN ]] || xedc_die "需要 --domain。"
  xedc_require_root
  cert_dir=/etc/xedc-recovery/ssl/$DOMAIN
  if [[ $STEP == renew ]]; then
    xedc_ssl_renew "$DOMAIN"
  else
    xedc_ssl_run "$DOMAIN" "${XEDC_SITE_ROOT}/web" "$cert_dir"
    if [[ -f $cert_dir/fullchain.pem ]] && command -v nginx >/dev/null 2>&1; then
      rendered=$(xedc_mktemp)
      stapling=no
      if xedc_cert_has_ocsp "$cert_dir/fullchain.pem"; then
        stapling=yes
      fi
      socket="/run/php/php${PHP_VERSION}-fpm.sock"
      if [[ -n ${XEDC_PHP_ACTUAL:-} ]]; then
        socket="/run/php/php${XEDC_PHP_ACTUAL}-fpm.sock"
      fi
      xedc_nginx_render "$DOMAIN" "${XEDC_SITE_ROOT}/web" "$socket" "$rendered" "$cert_dir" "$stapling"
      xedc_nginx_test_and_switch "$rendered" /etc/nginx/conf.d/xedc-drupal.conf
    fi
  fi
  exit 0
fi

if [[ $STEP == change-domain ]]; then
  [[ -n $OLD_DOMAIN && -n $NEW_DOMAIN ]] || xedc_die "换域名需要 --old-domain 和 --new-domain。"
  xedc_require_root
  [[ -f $XEDC_STATE_ROOT/restore.cnf ]] || xedc_die "找不到恢复数据库配置。"
  [[ -f $XEDC_STATE_ROOT/secrets/db.json ]] || xedc_die "找不到解密后的数据库信息。"
  db_name=$(jq -r '.database' "$XEDC_STATE_ROOT/secrets/db.json")
  xedc_domain_replace "$XEDC_STATE_ROOT/restore.cnf" "$db_name" "$OLD_DOMAIN" "$NEW_DOMAIN" "$XEDC_STATE_ROOT/domain-report.json" \
    || xedc_die "域名替换失败，数据库已按快照回滚。"
  cert_dir=/etc/xedc-recovery/ssl/$NEW_DOMAIN
  if [[ ! -f $cert_dir/fullchain.pem ]]; then
    xedc_self_signed "$NEW_DOMAIN" "$cert_dir"
  fi
  rendered=$(xedc_mktemp)
  xedc_nginx_render "$NEW_DOMAIN" "${XEDC_SITE_ROOT}/web" "/run/php/php${PHP_VERSION}-fpm.sock" "$rendered" "$cert_dir"
  xedc_nginx_test_and_switch "$rendered" /etc/nginx/conf.d/xedc-drupal.conf
  xedc_nginx_old_redirect "$OLD_DOMAIN" "$NEW_DOMAIN"
  xedc_log INFO "域名已切换。请执行 restore.sh --retry-ssl --domain ${NEW_DOMAIN} 申请新证书。管理员密码与原站一致。"
  exit 0
fi

if [[ $STEP == reset-admin || $STEP == login-link ]]; then
  [[ -n $DOMAIN ]] || xedc_die "需要 --domain。"
  if [[ $STEP == reset-admin ]]; then
    xedc_reset_admin "$XEDC_SITE_ROOT" "https://${DOMAIN}"
  else
    xedc_login_link "$XEDC_SITE_ROOT" "https://${DOMAIN}"
  fi
  exit 0
fi

[[ -n $SNAPSHOT ]] || xedc_die "恢复需要 --snapshot。"
[[ -f $SNAPSHOT/STATUS && $(cat "$SNAPSHOT/STATUS") == PASS ]] || xedc_die "快照不是成功备份。"
[[ -f $SNAPSHOT/manifest.json ]] || xedc_die "快照缺少 manifest.json。"
DOMAIN=${DOMAIN:-${NEW_DOMAIN:-}}
[[ -n $DOMAIN || -n $STEP ]] || xedc_die "恢复需要 --domain。"

xedc_require_root
if ! command -v jq >/dev/null 2>&1 || ! command -v zstd >/dev/null 2>&1 || ! command -v age >/dev/null 2>&1 || ! command -v python3 >/dev/null 2>&1; then
  xedc_export_apt_env
  apt-get update
  apt-get install -y jq zstd age python3 ca-certificates curl tar openssl
fi
SOURCE_HASH=$(jq -r '.source.machine_id_sha256 // empty' "$SNAPSHOT/manifest.json")
xedc_assert_restore_target "$SOURCE_HASH"
xedc_os_supported
if xedc_is_aws; then
  xedc_warn "检测到 AWS。请在控制台安全组放行 80 和 443。脚本不会修改安全组。"
fi
xedc_log INFO "版本策略：${XEDC_VERSION_STRATEGY}"
XEDC_STATE_FILE=$XEDC_STATE_ROOT/restore-state.json
xedc_state_init "$XEDC_STATE_FILE"
TARGET=${XEDC_SITE_ROOT}
WEB_ROOT=$TARGET/web
PHP_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

if xedc_should_run packages; then
  xedc_install_base_packages
  xedc_install_composer_phar
  xedc_mark packages
fi
if xedc_should_run php; then
  xedc_install_php "$PHP_VERSION"
  PHP_SOCKET="/run/php/php${XEDC_PHP_ACTUAL}-fpm.sock"
  xedc_mark php
fi
if xedc_should_run source; then
  mkdir -p "$(dirname "$TARGET")"
  if [[ -e $TARGET && $FORCE != 1 && ! -L $TARGET ]]; then
    xedc_die "目标目录已存在。确认覆盖请加 --force。覆盖前会先做回滚快照。"
  fi
  xedc_rollback_begin
  if [[ -e $TARGET ]]; then
    mv "$TARGET" "$XEDC_ROLLBACK_DIR/site-before"
  fi
  mkdir -p "$TARGET"
  zstdcat "$SNAPSHOT/project-source.tar.zst" | tar -C "$TARGET" -xf -
  xedc_mark source
fi
if xedc_should_run composer; then
  xedc_composer_install "$TARGET"
  xedc_mark composer
fi
SECRET_DIR=$XEDC_STATE_ROOT/secrets
if xedc_should_run secrets; then
  rm -rf "$SECRET_DIR"
  mkdir -p "$SECRET_DIR"
  chmod 700 "$SECRET_DIR"
  age -d -i "${XEDC_AGE_KEY:-/root/.config/xedc-recovery/age.key}" -o "$SECRET_DIR/secrets.tar" "$SNAPSHOT/secrets.enc" \
    || xedc_die "无法解密 secrets.enc。请提供备份时的 age 私钥。"
  tar -C "$SECRET_DIR" -xf "$SECRET_DIR/secrets.tar"
  rm -f "$SECRET_DIR/secrets.tar"
  xedc_mark secrets
fi
xedc_load_db_identity() {
  [[ -f $SECRET_DIR/db.json ]] || return 0
  db_name=$(jq -r '.database' "$SECRET_DIR/db.json")
  db_user=$(jq -r '.username' "$SECRET_DIR/db.json")
}

if xedc_should_run database; then
  xedc_ensure_mariadb
  xedc_load_db_identity
  passfile=$XEDC_STATE_ROOT/db.pass
  if [[ $KEEP_DB == 1 ]]; then
    jq -r '.password' "$SECRET_DIR/db.json" >"$passfile"
  else
    xedc_random_password >"$passfile"
  fi
  chmod 600 "$passfile"
  xedc_create_database_user "$db_name" "$db_user" "$passfile"
  xedc_write_client_cnf "$SECRET_DIR/db.json" "$XEDC_STATE_ROOT/restore.cnf"
  # 新密码写进临时配置，避免沿用旧密码导入。
  php /dev/stdin "$passfile" "$XEDC_STATE_ROOT/restore.cnf" <<'PHP'
<?php
$pass = trim((string) file_get_contents($argv[1]));
$cnf = $argv[2];
$lines = [];
foreach (file($cnf, FILE_IGNORE_NEW_LINES) as $line) {
    $lines[] = str_starts_with($line, "password=") ? "password=" . $pass : $line;
}
file_put_contents($cnf, implode("\n", $lines) . "\n");
chmod($cnf, 0600);
PHP
  xedc_import_database "$XEDC_STATE_ROOT/restore.cnf" "$db_name" "$SNAPSHOT/database.sql.zst"
  xedc_mark database
fi
if xedc_should_run files; then
  mkdir -p "$WEB_ROOT/sites/default/files"
  zstdcat "$SNAPSHOT/files-public.tar.zst" | tar -C "$WEB_ROOT/sites/default/files" -xf -
  private=$(jq -r '.settings_public.private_path // .private_path // empty' "$SNAPSHOT/manifest.json")
  if [[ -z $private ]]; then
    private=$TARGET/private
  fi
  mkdir -p "$private"
  zstdcat "$SNAPSHOT/files-private.tar.zst" | tar -C "$private" -xf -
  if [[ -d $SECRET_DIR/storage-keys ]]; then
    cp -a "$SECRET_DIR/storage-keys/." "$private/"
  fi
  if [[ -d $SECRET_DIR/private-settings ]]; then
    cp -a "$SECRET_DIR/private-settings/." "$private/"
  fi
  if [[ -d $SECRET_DIR/private-keys ]]; then
    cp -a "$SECRET_DIR/private-keys/." "$private/"
  fi
  chown -R www-data:www-data "$WEB_ROOT/sites/default/files" "$private"
  xedc_mark files
fi
if xedc_should_run settings; then
  xedc_write_settings_local "$SECRET_DIR/db.json" "$XEDC_STATE_ROOT/db.pass" "$DOMAIN" "$WEB_ROOT/sites/default/settings.local.php"
  chgrp www-data "$WEB_ROOT/sites/default/settings.local.php" || true
  xedc_ensure_settings_include "$WEB_ROOT/sites/default/settings.php"
  xedc_mark settings
fi
if xedc_should_run nginx; then
  if [[ -z ${XEDC_PHP_ACTUAL:-} ]]; then
    if command -v php >/dev/null 2>&1; then
      XEDC_PHP_ACTUAL=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
      export XEDC_PHP_ACTUAL
    else
      export XEDC_PHP_ACTUAL=$PHP_VERSION
    fi
  fi
  PHP_SOCKET="/run/php/php${XEDC_PHP_ACTUAL}-fpm.sock"
  xedc_ensure_php_fpm "$XEDC_PHP_ACTUAL" || true
  xedc_ensure_redis || true
  cert_dir=/etc/xedc-recovery/ssl/$DOMAIN
  if [[ ! -f $cert_dir/fullchain.pem ]]; then
    xedc_self_signed "$DOMAIN" "$cert_dir"
  fi
  rendered=$(xedc_mktemp)
  xedc_nginx_render "$DOMAIN" "$WEB_ROOT" "$PHP_SOCKET" "$rendered" "$cert_dir"
  xedc_nginx_test_and_switch "$rendered" /etc/nginx/conf.d/xedc-drupal.conf
  xedc_mark nginx
fi
if xedc_should_run ssl; then
  cert_dir=/etc/xedc-recovery/ssl/$DOMAIN
  xedc_ssl_run "$DOMAIN" "$WEB_ROOT" "$cert_dir"
  rendered=$(xedc_mktemp)
  stapling=no
  if xedc_cert_has_ocsp "$cert_dir/fullchain.pem"; then
    stapling=yes
  fi
  xedc_nginx_render "$DOMAIN" "$WEB_ROOT" "$PHP_SOCKET" "$rendered" "$cert_dir" "$stapling"
  xedc_nginx_test_and_switch "$rendered" /etc/nginx/conf.d/xedc-drupal.conf
  xedc_mark ssl
fi
if xedc_should_run services; then
  if [[ $WITH_DOCKER == 1 || $WITH_MATOMO == 1 || $WITH_NETDATA == 1 ]]; then
    xedc_restore_services "$SNAPSHOT" "$XEDC_STATE_ROOT/services" || xedc_warn "配套服务只恢复了配置。"
  fi
  xedc_mark services
fi
if xedc_should_run media; then
  if [[ $WITH_MEDIA == 1 ]]; then
    xedc_media_install || xedc_warn "媒体软件未完全安装。"
    xedc_media_smoke "$XEDC_STATE_ROOT/media-smoke" || xedc_warn "转码冒烟测试未通过。"
  fi
  xedc_mark media
fi
if xedc_should_run domain && [[ -n $OLD_DOMAIN && -n $NEW_DOMAIN ]]; then
  xedc_load_db_identity
  xedc_domain_replace "$XEDC_STATE_ROOT/restore.cnf" "$db_name" "$OLD_DOMAIN" "$NEW_DOMAIN" "$XEDC_STATE_ROOT/domain-report.json" \
    || xedc_die "域名替换失败，数据库已按快照回滚。"
  docs_dir=$(xedc_docs_dir)
  xedc_scan_hardcode "$TARGET" "$docs_dir/DOMAIN_HARDCODE.md" "$OLD_DOMAIN"
  xedc_nginx_old_redirect "$OLD_DOMAIN" "$NEW_DOMAIN"
  xedc_mark domain
fi
if xedc_should_run drush; then
  if [[ -x $TARGET/vendor/bin/drush ]]; then
    xedc_drush_rebuild "$TARGET" "https://${DOMAIN}"
  else
    xedc_warn "项目里没有 drush，已跳过 config:import、updatedb 和 cache:rebuild。"
  fi
  xedc_mark drush
fi
if xedc_should_run credentials; then
  xedc_load_db_identity
  admin=""
  if [[ -x $TARGET/vendor/bin/drush ]]; then
    admin=$(cd "$TARGET" && ./vendor/bin/drush --uri="https://${DOMAIN}" sql:query "SELECT name FROM users_field_data WHERE uid=1" | head -n 1) || true
  fi
  xedc_credentials_write "https://${DOMAIN}" "$admin" "$db_name" "$db_user" "$XEDC_STATE_ROOT/db.pass" localhost 3306
  xedc_mark credentials
fi
if xedc_should_run verify; then
  xedc_verify_site "https://${DOMAIN}" || xedc_warn "部分 HTTP 验收未通过。"
  xedc_mark verify
fi
if [[ $STEP == compare || -z $STEP ]]; then
  live=$(xedc_mktemp)
  docs_dir=$(xedc_docs_dir)
  if [[ -z ${db_name:-} ]]; then
    xedc_load_db_identity
  fi
  tables=0
  if [[ -n ${db_name:-} ]] && mariadb -N -e 'SELECT 1' >/dev/null 2>&1; then
    tables=$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db_name}'" 2>/dev/null || echo 0)
  fi
  [[ $tables =~ ^[0-9]+$ ]] || tables=0
  public_files=0
  if [[ -d $WEB_ROOT/sites/default/files ]]; then
    public_files=$(find "$WEB_ROOT/sites/default/files" -type f | wc -l | tr -d ' ')
  fi
  [[ $public_files =~ ^[0-9]+$ ]] || public_files=0
  jq -n \
    --arg php "$(php -r 'echo PHP_VERSION;')" \
    --argjson tables "$tables" \
    --argjson public_files "$public_files" \
    --arg ffmpeg "$(ffmpeg -version 2>/dev/null | awk 'NR==1 {print $3}')" \
    '{php:$php, tables:$tables, public_files:$public_files, ffmpeg:$ffmpeg}' >"$live"
  xedc_compare_write "$SNAPSHOT/manifest.json" "$live" "$docs_dir/PARITY_REPORT.md" || xedc_warn "对账发现异常差异，见 ${docs_dir}/PARITY_REPORT.md。"
fi

xedc_log INFO "恢复流程结束。凭据在 ${XEDC_CREDENTIALS_FILE}。管理员密码与原站一致。"
