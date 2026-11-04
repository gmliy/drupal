#!/usr/bin/env bash
# 在隔离容器内恢复脱敏 Drupal 快照。调用方只挂载该快照、测试 age 私钥和本仓库。
set -Eeuo pipefail

printf '%s\n' '127.0.0.1 newer.example.test old.example.test' >>/etc/hosts
export XEDC_RECOVERY_TEST=1
export XEDC_AGE_KEY=/age/age.key
export XEDC_SITE_ROOT=/var/www/drupal-lab
export XEDC_STATE_ROOT=/var/lib/xedc-recovery
export XEDC_BACKUP_ROOT=/var/backups/xedc-drupal-lab
export XEDC_LOG_DIR=/var/log/xedc-recovery
mkdir -p "$XEDC_STATE_ROOT" "$XEDC_BACKUP_ROOT" "$XEDC_LOG_DIR"

restore=/opt/xedc-recovery/restore.sh
"$restore" \
  --snapshot /snapshot \
  --domain newer.example.test \
  --old-domain old.example.test \
  --new-domain newer.example.test \
  --php-version 8.5 \
  --force \
  --without-docker-services \
  --without-matomo \
  --without-netdata

printf 'php=%s bin=%s\n' "$(php -r 'echo PHP_VERSION;')" "$(command -v php)"
php -r 'exit(str_starts_with(PHP_VERSION, "8.5") ? 0 : 1);'
require_file() {
  if [[ ! -f $1 ]]; then
    printf '缺少文件 %s\n' "$1" >&2
    exit 1
  fi
}
require_file /var/www/drupal-lab/web/core/core.services.yml
require_file /var/www/drupal-lab/vendor/bin/drush
require_file /var/www/drupal-lab/web/modules/custom/lab_marker/lab_marker.module
require_file /var/www/drupal-lab/web/themes/custom/lab_theme/lab_theme.info.yml
require_file /var/www/drupal-lab/config/sync/core.extension.yml
require_file /var/www/drupal-lab/web/sites/default/files/hello.txt
require_file /var/www/drupal-lab/private/note.txt
grep -q 'public-fixture' /var/www/drupal-lab/web/sites/default/files/hello.txt
grep -q 'private-fixture' /var/www/drupal-lab/private/note.txt
if ! grep -q "host' => 'localhost'" /var/www/drupal-lab/web/sites/default/settings.local.php; then
  printf '%s\n' "settings.local.php 没有把数据库主机改成 localhost" >&2
  exit 1
fi
owner=$(stat -c '%U:%G' /var/www/drupal-lab/web/sites/default/files/hello.txt)
if [[ $owner != www-data:www-data ]]; then
  printf '文件属主是 %s\n' "$owner" >&2
  exit 1
fi

cd /var/www/drupal-lab
status=$(./vendor/bin/drush --uri=https://newer.example.test status --fields=bootstrap,drupal-version,db-status,theme)
printf '%s\n' "$status" | grep -q 'Successful'
printf '%s\n' "$status" | grep -q '11.4.11'
printf '%s\n' "$status" | grep -q 'Connected'
printf '%s\n' "$status" | grep -q 'lab_theme'
./vendor/bin/drush --uri=https://newer.example.test php:eval 'echo \Drupal::moduleHandler()->moduleExists("lab_marker") ? "lab_marker_on\n" : "lab_marker_off\n"; echo \Drupal::moduleHandler()->moduleExists("token") ? "token_on\n" : "token_off\n";' | tee /tmp/modules.txt
grep -q lab_marker_on /tmp/modules.txt
grep -q token_on /tmp/modules.txt
./vendor/bin/drush --uri=https://newer.example.test updatedb:status --format=json > /tmp/updb.json
php -r '$d=json_decode(file_get_contents("/tmp/updb.json"), true); if (!is_array($d) || $d) exit(1);'
./vendor/bin/drush --uri=https://newer.example.test config:status --format=json > /tmp/config-status.json
php -r '$d=json_decode(file_get_contents("/tmp/config-status.json"), true); if (!is_array($d) || $d) exit(1);'

php <<'PHP'
<?php
$pdo = new PDO(
    'mysql:host=localhost;dbname=xedcdrupal;charset=utf8mb4',
    'xedcdrupal',
    trim((string) file_get_contents('/var/lib/xedc-recovery/db.pass'))
);
$row = $pdo->query("SELECT data FROM lab_payload WHERE name='site'")->fetchColumn();
$value = unserialize((string) $row, ['allowed_classes' => false]);
if (!is_array($value) || ($value['url'] ?? '') !== 'https://newer.example.test/node/1') {
    fwrite(STDERR, "域名替换后的序列化数据无法解析\n");
    exit(1);
}
if (str_contains((string) $row, 'old.example.test')) {
    fwrite(STDERR, "测试载荷仍有旧域名\n");
    exit(1);
}
$charset = $pdo->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='xedcdrupal'")->fetch(PDO::FETCH_NUM);
if (($charset[0] ?? '') !== 'utf8mb4') {
    fwrite(STDERR, "数据库字符集不是 utf8mb4\n");
    exit(1);
}
echo "PASS serialized domain\n";
PHP

home=$(curl -sk -D /tmp/home.hdr -o /tmp/home.html -w '%{http_code}' --max-time 20 https://newer.example.test/)
login=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 https://newer.example.test/user/login)
admin=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 https://newer.example.test/admin)
home_location=$(awk 'tolower($1)=="location:" {print $2}' /tmp/home.hdr | tr -d '\r' | head -n 1)
printf '%s\n' "HTTP home=${home} login=${login} admin=${admin} location=${home_location}"
[[ $home == 200 ]]
[[ $login == 200 ]]
[[ $admin == 302 || $admin == 403 ]]
grep -q 'lab_marker' /var/lib/xedc-recovery/docs/DOMAIN_HARDCODE.md

# 数据库、Nginx、PHP-FPM 回滚。只动容器内服务。
# shellcheck source=../../lib/common.sh
source /opt/xedc-recovery/lib/common.sh
# shellcheck source=../../lib/environment.sh
source /opt/xedc-recovery/lib/environment.sh
# shellcheck source=../../lib/rollback.sh
source /opt/xedc-recovery/lib/rollback.sh
export XEDC_ROLLBACK_ROOT=/var/lib/xedc-recovery/rollback-proof
xedc_init

xedc_rollback_begin
xedc_rollback_capture_database xedcdrupal
printf '%s\n' 1 >"$XEDC_ROLLBACK_DIR/database/table-count"
if xedc_rollback_apply "$XEDC_ROLLBACK_DIR" /var/www/drupal-lab; then
  printf '%s\n' "错误的表数量不应通过回滚校验" >&2
  exit 1
fi
[[ $(cat "$XEDC_ROLLBACK_DIR/ROLLBACK_STATUS") == FAIL ]]
mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='xedcdrupal'" >"$XEDC_ROLLBACK_DIR/database/table-count"
xedc_rollback_apply "$XEDC_ROLLBACK_DIR" /var/www/drupal-lab
[[ $(cat "$XEDC_ROLLBACK_DIR/ROLLBACK_STATUS") == PASS ]]

xedc_rollback_begin
xedc_rollback_capture_database xedcdrupal
mariadb --defaults-extra-file=/var/lib/xedc-recovery/restore.cnf xedcdrupal -e "UPDATE lab_payload SET data='changed' WHERE name='site'"
xedc_rollback_apply "$XEDC_ROLLBACK_DIR" /var/www/drupal-lab
[[ $(cat "$XEDC_ROLLBACK_DIR/ROLLBACK_STATUS") == PASS ]]
php <<'PHP'
<?php
$pdo = new PDO(
    'mysql:host=localhost;dbname=xedcdrupal;charset=utf8mb4',
    'xedcdrupal',
    trim((string) file_get_contents('/var/lib/xedc-recovery/db.pass'))
);
$row = (string) $pdo->query("SELECT data FROM lab_payload WHERE name='site'")->fetchColumn();
$value = unserialize($row, ['allowed_classes' => false]);
if (!is_array($value) || ($value['url'] ?? '') !== 'https://newer.example.test/node/1') {
    fwrite(STDERR, "数据库回滚后载荷不一致\n");
    exit(1);
}
echo "PASS database rollback\n";
PHP

xedc_rollback_begin
XEDC_ROLLBACK_CAPTURE_NGINX=1 xedc_rollback_capture_tree /var/www/drupal-lab
printf '\n# xedc-rollback-test\n' >>/etc/nginx/conf.d/xedc-drupal.conf
xedc_rollback_apply "$XEDC_ROLLBACK_DIR" /var/www/drupal-lab
[[ $(cat "$XEDC_ROLLBACK_DIR/ROLLBACK_STATUS") == PASS ]]
if grep -q xedc-rollback-test /etc/nginx/conf.d/xedc-drupal.conf; then
  printf '%s\n' "Nginx 配置没有回到修改前" >&2
  exit 1
fi
nginx_code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 https://newer.example.test/)
[[ $nginx_code == 200 ]]
echo PASS nginx rollback

xedc_rollback_begin
xedc_rollback_capture_php_fpm 8.5
printf '\n; xedc-rollback-test\n' >>/etc/php/8.5/fpm/pool.d/www.conf
xedc_rollback_apply "$XEDC_ROLLBACK_DIR" /var/www/drupal-lab
[[ $(cat "$XEDC_ROLLBACK_DIR/ROLLBACK_STATUS") == PASS ]]
if grep -q xedc-rollback-test /etc/php/8.5/fpm/pool.d/www.conf; then
  printf '%s\n' "PHP-FPM 配置没有回到修改前" >&2
  exit 1
fi
fpm_code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 https://newer.example.test/)
[[ $fpm_code == 200 ]]
echo PASS php-fpm rollback

hash_before=$(./vendor/bin/drush --uri=https://newer.example.test sql:query "SELECT pass FROM users_field_data WHERE uid=1")
XEDC_ASSUME_YES=1 "$restore" --reset-admin --domain newer.example.test
hash_after=$(./vendor/bin/drush --uri=https://newer.example.test sql:query "SELECT pass FROM users_field_data WHERE uid=1")
[[ -n $hash_before && $hash_before != "$hash_after" ]]
./vendor/bin/drush --uri=https://newer.example.test php:eval '$lines=file("/root/.drupal-credentials", FILE_IGNORE_NEW_LINES); $pass=""; foreach ($lines as $line) { if (str_starts_with($line, "admin_password=")) { $pass=substr($line, 15); } } $user=\Drupal\user\Entity\User::load(1); echo \Drupal::service("password")->check($pass, $user->getPassword()) ? "admin-password-matches\n" : "admin-password-mismatch\n";' | tee /tmp/admin-check.txt
grep -q admin-password-matches /tmp/admin-check.txt
python3 - <<'PY'
from pathlib import Path
pw = ""
for line in Path("/root/.drupal-credentials").read_text().splitlines():
    if line.startswith("admin_password="):
        pw = line.split("=", 1)[1]
log = Path("/var/log/xedc-recovery/recovery.log").read_text(errors="replace")
if pw and pw in log:
    raise SystemExit("管理员密码出现在日志中")
if "新的管理员密码只显示这一次" in log:
    raise SystemExit("终端密码提示仍被写入日志")
print("PASS admin reset")
PY

./vendor/bin/drush --uri=https://newer.example.test -y config:set user.settings password_reset_timeout 1
./vendor/bin/drush --uri=https://newer.example.test cache:rebuild
"$restore" --login-link --domain newer.example.test > /var/lib/xedc-recovery/login.link
chmod 600 /var/lib/xedc-recovery/login.link
link=$(grep -E '^https?://' /var/lib/xedc-recovery/login.link | head -n 1)
[[ -n $link ]]
sleep 2
expired=$(curl -sk -o /tmp/expired.html -w '%{http_code}' --max-time 20 "$link")
if grep -q 'user/logout' /tmp/expired.html || grep -q 'fixture-admin' /tmp/expired.html; then
  printf '%s\n' "过期登录链接仍然进入账户" >&2
  exit 1
fi
printf '%s\n' "expired_link_http=${expired}"
./vendor/bin/drush --uri=https://newer.example.test -y config:set user.settings password_reset_timeout 86400
"$restore" --login-link --domain newer.example.test > /var/lib/xedc-recovery/login.link
link=$(grep -E '^https?://' /var/lib/xedc-recovery/login.link | head -n 1)
curl -sk -c /tmp/login.cj -o /tmp/login.html -L --max-redirs 5 --max-time 20 "$link" >/dev/null
if ! grep -q 'user/logout' /tmp/login.html; then
  printf '%s\n' "一次性登录没有进入已登录页面" >&2
  exit 1
fi
curl -sk -o /tmp/login2.html -L --max-redirs 5 --max-time 20 "$link" >/dev/null
if grep -q 'user/logout' /tmp/login2.html; then
  printf '%s\n' "一次性登录链接可以重复使用" >&2
  exit 1
fi
rm -f /var/lib/xedc-recovery/login.link /tmp/login.cj /tmp/login.html /tmp/login2.html /tmp/login.hdr
echo PASS one-time login

"$restore" \
  --snapshot /snapshot \
  --domain newer.example.test \
  --old-domain old.example.test \
  --new-domain newer.example.test \
  --php-version 8.5 \
  --force \
  --without-docker-services \
  --without-matomo \
  --without-netdata
python3 - <<'PY'
from pathlib import Path
text = Path("/var/lib/xedc-recovery/docs/PARITY_REPORT.md").read_text()
if "| Drupal | 11.4.11 | 11.4.11 | 相同 |" not in text:
    raise SystemExit("Drupal 版本对账未通过")
if "| 数据表数 | 50 | 50 | 相同 |" not in text:
    raise SystemExit("数据表数量对账未通过")
anomalies = [line for line in text.splitlines() if line.startswith("|") and line.rstrip().endswith("| 异常差异 |")]
unexpected = [line for line in anomalies if "公共文件数" not in line]
if unexpected:
    raise SystemExit("存在非文件数的异常差异")
print("PARITY_ANOMALY_ROWS", len(anomalies))
PY
printf '%s\n' DRUPAL_RESTORE_OK
