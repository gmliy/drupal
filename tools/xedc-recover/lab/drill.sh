#!/bin/bash
# Runs only inside a machine that does not contain the production web root.
set -euo pipefail
backup_id="${1:?backup id required}"
if [ -d /var/www/drupal/web/core ]; then
  echo "拒绝：看到了生产网站目录" >&2
  exit 3
fi
echo xedc-recover-lab > /etc/xedc-recover-lab
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y python3 python3-cryptography ca-certificates curl gnupg
python3 /tool/bin/xedc-recover install --yes
service mariadb start
service redis-server start || true
if ! pgrep -x php-fpm8.5 >/dev/null; then
  service php8.5-fpm start
fi
umask 077
python3 - <<'PY' > /root/xedc-restore-db.password
import secrets
print(secrets.token_hex(16))
PY
chmod 600 /root/xedc-restore-db.password
umask 022
export XEDC_RESTORE_DB_PASSWORD
XEDC_RESTORE_DB_PASSWORD="$(cat /root/xedc-restore-db.password)"
export XEDC_RESTORE_DB_PASSWORD
cat > /run/xedc-restore.json <<'EOF'
{
  "backup_root": "/backups",
  "key_file": "/keys/primary.key",
  "source_root": "/srv/xedc/code",
  "public_files": "web/sites/default/files",
  "private_files": "/srv/xedc/private"
}
EOF
chmod 600 /run/xedc-restore.json
python3 /tool/bin/xedc-recover --config /run/xedc-restore.json restore run \
  --backup-id "$backup_id" \
  --target /srv/xedc \
  --role lab \
  --yes \
  --import-database \
  --db-name xedc_restore \
  --db-user xedc_restore \
  --db-host 127.0.0.1 \
  --db-port 3306 \
  --trusted-host '^lab\.xedc\.test$' \
  --trusted-host '^localhost$'
python3 /tool/bin/xedc-recover ssl render \
  --server-name "lab.xedc.test localhost" \
  --webroot /srv/xedc/code/web \
  --prefix /tmp/xedc-nginx \
  --port 80 \
  --output /etc/nginx/nginx.conf
mkdir -p /tmp/xedc-nginx
nginx -t
php_sock="$(ls /run/php/php*-fpm.sock | head -1)"
if ! grep -q "$php_sock" /etc/nginx/nginx.conf; then
  sed -i "s#unix:/run/php/php8.5-fpm.sock#unix:${php_sock}#" /etc/nginx/nginx.conf
  nginx -t
fi
chown -R www-data:www-data /srv/xedc/private /srv/xedc/code/web/sites/default/settings.php
chmod 440 /srv/xedc/code/web/sites/default/settings.php
if id nginx >/dev/null 2>&1; then
  sed -i -E 's/^;*[[:space:]]*listen\.group[[:space:]]*=.*/listen.group = nginx/' /etc/php/8.5/fpm/pool.d/www.conf
  sed -i -E 's/^;*[[:space:]]*listen\.mode[[:space:]]*=.*/listen.mode = 0660/' /etc/php/8.5/fpm/pool.d/www.conf
  grep -q '^listen.group' /etc/php/8.5/fpm/pool.d/www.conf || echo 'listen.group = nginx' >> /etc/php/8.5/fpm/pool.d/www.conf
  grep -q '^listen.mode' /etc/php/8.5/fpm/pool.d/www.conf || echo 'listen.mode = 0660' >> /etc/php/8.5/fpm/pool.d/www.conf
fi
service php8.5-fpm restart
service nginx stop >/dev/null 2>&1 || true
nginx -s quit >/dev/null 2>&1 || true
sleep 0.5
service nginx start
nginx -t
curl -fsS -H 'Host: lab.xedc.test' http://127.0.0.1/user/login -o /tmp/login.html
curl -sS -D /tmp/home.headers -H 'Host: lab.xedc.test' http://127.0.0.1/ -o /tmp/home.html
python3 - <<'PY'
import json, pathlib
home = pathlib.Path("/tmp/home.html").read_text(errors="replace")
login = pathlib.Path("/tmp/login.html").read_text(errors="replace")
headers = pathlib.Path("/tmp/home.headers").read_text(errors="replace")
report = {
    "home_bytes": len(home),
    "login_has_form": "user-login-form" in login or "name=\"name\"" in login,
    "drupal_header": "Drupal" in headers or "Drupal" in home,
    "home_status": headers.splitlines()[0] if headers else "",
}
pathlib.Path("/tmp/drill-report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
print(json.dumps(report, ensure_ascii=False))
if not report["login_has_form"]:
    raise SystemExit(1)
PY
echo "DRILL_OK"
