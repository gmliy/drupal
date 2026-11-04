#!/usr/bin/env bash
# 在 Ubuntu 24.04 容器中恢复隔离 Drupal 测试快照。
# 这是测试入口，不是生产恢复命令。生产恢复使用 xedc-recovery/restore.sh。
set -Eeuo pipefail

if [[ $# -lt 2 ]]; then
  printf '%s\n' "用法: restore-drupal-docker.sh 快照目录 age私钥" >&2
  exit 2
fi
snapshot=$1
key=$2
[[ -f $snapshot/manifest.json && -f $snapshot/STATUS ]] || exit 2
[[ $(cat "$snapshot/STATUS") == PASS ]] || exit 2
[[ -f $key ]] || exit 2
[[ $(jq -r '.database.database' "$snapshot/manifest.json") == xedcdrupal ]] || {
  printf '%s\n' "只接受隔离测试库 xedcdrupal 的快照。" >&2
  exit 2
}
case $snapshot in
  /var/backups/xedc/*)
    printf '%s\n' "拒绝把生产备份目录送进测试容器。" >&2
    exit 2
    ;;
esac

avail=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
disk=$(df -k / | awk 'NR==2 {print $4}')
if [[ $avail -lt 1500000 || $disk -lt 8388608 ]]; then
  printf '%s\n' "BLOCKED 内存或磁盘低于限制，不启动测试容器。" >&2
  exit 2
fi

script_dir=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
repo=$(cd "$script_dir/../.." && pwd)
docker run --rm \
  --name xedc-drupal-restore \
  --memory=3g \
  --cpus=2 \
  --pids-limit=512 \
  -v "$snapshot:/snapshot:ro" \
  -v "$(dirname "$key"):/age:ro" \
  -v "$repo:/opt/xedc-recovery:ro" \
  ubuntu:24.04 \
  bash /opt/xedc-recovery/tests/integration/inside-drupal-restore.sh
