#!/bin/bash
# Fresh Ubuntu restore. Refuses to run if the production tree is visible.
set -euo pipefail
if [ -d /var/www/drupal/web/core ]; then
  echo "拒绝：看到了生产网站目录" >&2
  exit 3
fi
umask 022
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y python3 python3-cryptography ca-certificates curl zstd tar
mkdir -p /var/lib/xedc-recover
cat > /run/xedc-restore.json <<'EOF'
{
  "backup_root": "/var/lib/xedc-recover/backups",
  "key_file": "/keys/primary.key",
  "source_root": "/srv/xedc/code",
  "public_files": "web/sites/default/files",
  "private_files": "/srv/xedc/private",
  "database": {"mode": "skip", "name": "xedc_restore"}
}
EOF
chmod 600 /run/xedc-restore.json
python3 /tool/bin/xedc-restore \
  --config /run/xedc-restore.json \
  --file /packages/xedc-full-20261103-3cd3bf.tar.zst \
  --domain lab.xedc.test \
  --yes \
  --role lab \
  --target /srv/xedc \
  --json
