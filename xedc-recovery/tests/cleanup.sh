#!/usr/bin/env bash
# 清理测试容器、网络和临时卷。保留 logs 与缓存卷。
set -euo pipefail
ids=$(docker ps -aq --filter name=xedc- || true)
if [[ -n "$ids" ]]; then
  # shellcheck disable=SC2086
  docker rm -f $ids >/dev/null 2>&1 || true
fi
docker network rm xedc-test >/dev/null 2>&1 || true
images=$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -E '^xedc-snap:' || true)
if [[ -n "$images" ]]; then
  # shellcheck disable=SC2086
  docker rmi -f $images >/dev/null 2>&1 || true
fi
echo "已清理测试容器、网络和快照。日志仍在 tests/logs，缓存卷已保留。"
