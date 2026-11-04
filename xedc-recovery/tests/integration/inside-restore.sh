#!/usr/bin/env bash
# 在容器内执行。调用方负责挂载快照、age 私钥和这份代码。
set -Eeuo pipefail

printf '%s\n' '127.0.0.1 newer.example.test old.example.test' >>/etc/hosts
export XEDC_RECOVERY_TEST=1
export XEDC_AGE_KEY=/age/age.key
export XEDC_SITE_ROOT=/var/www/fixture-site
export XEDC_STATE_ROOT=/var/lib/xedc-recovery
export XEDC_BACKUP_ROOT=/var/backups/xedc-fixture
export XEDC_LOG_DIR=/var/log/xedc-recovery
mkdir -p "$XEDC_STATE_ROOT" "$XEDC_BACKUP_ROOT" "$XEDC_LOG_DIR"

restore=/opt/xedc-recovery/restore.sh
"$restore" \
  --snapshot /snapshot \
  --domain newer.example.test \
  --old-domain old.example.test \
  --new-domain newer.example.test \
  --mode=full \
  --php-version 8.5 \
  --force \
  --without-docker-services

php -r 'exit(str_starts_with(PHP_VERSION, "8.5") ? 0 : 1);'
php-fpm8.5 -v | head -n 1 | grep -q '8.5.'
grep -q 'php8.5-fpm.sock' /etc/nginx/conf.d/xedc-drupal.conf
php -m | grep -qi '^pdo_mysql$'
php -m | grep -qi '^gd$'
php -m | grep -qi '^mbstring$'
command -v composer >/dev/null
command -v ffmpeg >/dev/null
command -v nginx >/dev/null
command -v mariadb >/dev/null
test -f /var/www/fixture-site/web/sites/default/files/hello.txt
test -f /var/www/fixture-site/web/sites/default/settings.local.php
php <<'PHP'
<?php
$pdo = new PDO(
    'mysql:host=localhost;dbname=xedcfix;charset=utf8mb4',
    'xedcfix',
    trim((string) file_get_contents('/var/lib/xedc-recovery/db.pass'))
);
$row = $pdo->query("SELECT data FROM config WHERE name='system.site'")->fetchColumn();
$value = unserialize((string) $row, ['allowed_classes' => false]);
if (!is_array($value) || !str_contains((string) $value['url'], 'https://newer.example.test/')) {
    fwrite(STDERR, "域名替换后的序列化数据无法解析\n");
    exit(1);
}
if (str_contains((string) $row, 'old.example.test')) {
    fwrite(STDERR, "数据库仍有旧域名\n");
    exit(1);
}
echo "PASS serialized domain\n";
PHP
code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 https://newer.example.test/)
[[ $code == 200 ]]
admin=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 https://newer.example.test/admin)
[[ $admin == 302 ]]
"$restore" \
  --snapshot /snapshot \
  --domain newer.example.test \
  --old-domain old.example.test \
  --new-domain newer.example.test \
  --mode=full \
  --php-version 8.5 \
  --force \
  --without-docker-services
grep -q '异常差异: 0' /var/lib/xedc-recovery/docs/PARITY_REPORT.md
if "$restore" \
  --mode=restore \
  --snapshot /snapshot \
  --domain newer.example.test \
  --php-version 8.5
then
  printf '%s\n' 'FAIL restore 模式覆盖了已有网站' >&2
  exit 1
fi
printf '%s\n' 'PASS restore-mode-refuses'
printf '%s\n' FIXTURE_RESTORE_OK
