#!/bin/bash
# Isolated Ubuntu 24.04 restore. Refuses to run if the production tree is visible.
set -euo pipefail
if [ -d /var/www/drupal/web/core ]; then
  echo "拒绝：看到了生产网站目录" >&2
  exit 3
fi
umask 022
export DEBIAN_FRONTEND=noninteractive
export TMPDIR=/tmp
echo "=== 磁盘 ==="
df -BM / /tmp
findmnt /tmp || true
apt-get update
apt-get install -y python3 python3-cryptography ca-certificates curl zstd tar
mkdir -p /var/lib/xedc-recover /proof
cat > /run/xedc-restore.json <<'EOF'
{
  "backup_root": "/var/lib/xedc-recover/backups",
  "key_file": "/var/lib/xedc-recover/keys/primary.key",
  "source_root": "/srv/xedc/code",
  "public_files": "web/sites/default/files",
  "private_files": "/srv/xedc/private",
  "database": {"mode": "skip", "name": "xedc_restore"}
}
EOF
chmod 600 /run/xedc-restore.json
set +e
python3 /tool/bin/xedc-restore \
  --config /run/xedc-restore.json \
  --file /packages/xedc-full-20261103T183111Z-18ce30.tar.zst \
  --domain lab.xedc.test \
  --yes \
  --role lab \
  --target /srv/xedc \
  --json > /proof/restore.json 2> /proof/restore.err
restore_code=$?
set -e
cp -a /srv/xedc/INSTALL_REPORT.json /proof/INSTALL_REPORT.json 2>/dev/null || true
pgrep -a nginx
pgrep -a php-fpm
pgrep -a mariadbd
mariadb -u xedc_restore -p"$(cat /var/lib/xedc-recover/restore-db.password)" -h 127.0.0.1 -N -e "SELECT 1" xedc_restore
runuser -u www-data -- /srv/xedc/code/vendor/bin/drush --root=/srv/xedc/code/web status --uri=http://lab.xedc.test
python3 - <<'PY'
from pathlib import Path
public = Path("/srv/xedc/public")
private = Path("/srv/xedc/private")
images = [path for path in public.rglob("*") if path.is_file() and path.suffix.lower() in {".jpg", ".jpeg", ".png", ".webp", ".gif"}]
videos = [path for path in list(public.rglob("*")) + list(private.rglob("*")) if path.is_file() and path.suffix.lower() in {".mp4", ".webm", ".mkv", ".mov", ".m3u8", ".ts"}]
private_files = [path for path in private.rglob("*") if path.is_file()]
if not images:
    raise SystemExit("没有找到图片文件")
if not videos:
    raise SystemExit("没有找到视频文件")
if not private_files:
    raise SystemExit("没有找到 private 文件")
print(f"图片 {len(images)} 视频 {len(videos)} private {len(private_files)}")
PY
grep -R "lab.xedc.test" /etc/nginx >/dev/null
{
  echo "HOME"
  curl -sI -H "Host: lab.xedc.test" http://127.0.0.1/ | head -n 8
  echo "LOGIN"
  curl -s -o /dev/null -w "%{http_code}\n" -H "Host: lab.xedc.test" http://127.0.0.1/user/login
  echo "ADMIN"
  curl -s -o /dev/null -w "%{http_code}\n" -H "Host: lab.xedc.test" http://127.0.0.1/admin
} | tee /proof/http.txt
grep -n "file_temp_path" /srv/xedc/code/web/sites/default/settings.php | tee /proof/temp-setting.txt
php -r 'echo PHP_VERSION, "\n";' | tee /proof/php.txt
mariadb --version | tee /proof/mariadb.txt
sha256sum /packages/xedc-full-20261103T183111Z-18ce30.tar.zst | tee /proof/package.sha256
find /srv/xedc/public /srv/xedc/private /srv/xedc/code/web/modules/custom /srv/xedc/code/web/themes/custom /srv/xedc/code/composer.json /srv/xedc/code/composer.lock -type f ! -name settings.php -print0 | sort -z | xargs -0 sha256sum > /proof/files.sha256
python3 - <<PY
import json
from pathlib import Path
report = {}
path = Path("/proof/INSTALL_REPORT.json")
if path.is_file():
    report = json.loads(path.read_text(encoding="utf-8"))
files = report.get("files") or {}
print("restore_exit", ${restore_code})
print("report_status", report.get("status"))
print("file_error", report.get("file_error") or "")
print("checked", files.get("checked"), "missing", files.get("missing"), "records", files.get("records"))
if report.get("status") == "PASS" and not files.get("missing") and ${restore_code} == 0:
    print("SIMPLE RESTORE CHECKS PASSED")
else:
    raise SystemExit("恢复没有通过。文件引用或命令退出码不允许记为成功。")
PY
