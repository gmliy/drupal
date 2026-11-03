#!/bin/bash
# Download one backup from R2 and restore it. No production tree and no local package mount.
set -euo pipefail
if [ -d /var/www/drupal/web/core ]; then
  echo "拒绝：看到了生产网站目录" >&2
  exit 3
fi
backup="${1:?backup name required}"
umask 022
export DEBIAN_FRONTEND=noninteractive
if [ -f /secrets/env ]; then
  set -a
  # shellcheck disable=SC1091
  . /secrets/env
  set +a
fi
apt-get update
apt-get install -y python3 python3-cryptography ca-certificates curl zstd tar
python3 /tool/bin/xedc-restore \
  --config /secrets/restore.json \
  --backup "$backup" \
  --domain lab.xedc.test \
  --yes \
  --role lab \
  --target /srv/xedc \
  --json
