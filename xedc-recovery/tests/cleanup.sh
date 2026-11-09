#!/usr/bin/env bash
# 清理测试容器、网络、专用缓存卷与快照。
set -euo pipefail
ids=$(docker ps -aq --filter name=xedc- || true)
if [[ -n "$ids" ]]; then
  # shellcheck disable=SC2086
  docker rm -f $ids >/dev/null 2>&1 || true
fi
docker network rm xedc-test >/dev/null 2>&1 || true
while IFS= read -r vol; do
  [[ -n "$vol" ]] || continue
  docker volume rm "$vol" >/dev/null 2>&1 || true
done < <(docker volume ls -q 2>/dev/null | grep -E '^xedc-test-' || true)
images=$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -E '^xedc-snap:' || true)
if [[ -n "$images" ]]; then
  # shellcheck disable=SC2086
  docker rmi -f $images >/dev/null 2>&1 || true
fi
echo "已清理测试容器、网络、xedc-test-* 卷与快照。日志仍在 tests/logs"
