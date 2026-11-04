#!/usr/bin/env bash
# 在隔离容器里恢复夹具备份。不挂载生产站点、生产库或生产私钥。
set -Eeuo pipefail

if [[ $# -lt 2 ]]; then
  printf '%s\n' "用法: restore-docker.sh 快照目录 age私钥" >&2
  exit 2
fi
snapshot=$1
key=$2
[[ -f $snapshot/manifest.json && -f $snapshot/STATUS ]] || exit 2
[[ $(cat "$snapshot/STATUS") == PASS ]] || exit 2
[[ -f $key ]] || exit 2

avail=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
disk=$(df -k / | awk 'NR==2 {print $4}')
if [[ $avail -lt 1500000 || $disk -lt 8388608 ]]; then
  printf '%s\n' "BLOCKED 内存或磁盘低于限制，不启动测试容器。" >&2
  exit 2
fi

script_dir=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
repo=$(cd "$script_dir/../.." && pwd)
docker run --rm \
  --name xedc-fixture-restore \
  --memory=4g \
  --cpus=2 \
  --pids-limit=400 \
  -v "$snapshot:/snapshot:ro" \
  -v "$(dirname "$key"):/age:ro" \
  -v "$repo:/opt/xedc-recovery:ro" \
  ubuntu:24.04 \
  bash /opt/xedc-recovery/tests/integration/inside-restore.sh
