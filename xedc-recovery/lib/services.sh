#!/usr/bin/env bash
# shellcheck shell=bash

xedc_collect_services() {
  local dest=$1 secret_dir=$2
  mkdir -p "$dest/systemd" "$dest/cron" "$dest/matomo" "$dest/netdata" "$dest/docker"
  if crontab -l >"$dest/cron/root.cron" 2>/dev/null; then
    chmod 600 "$dest/cron/root.cron"
  else
    printf '%s\n' "" >"$dest/cron/root.cron"
  fi
  crontab -u www-data -l >"$dest/cron/www-data.cron" 2>/dev/null || printf '%s\n' "" >"$dest/cron/www-data.cron"
  if [[ -f /etc/systemd/system/xedc-import-worker.service ]]; then
    cp -a /etc/systemd/system/xedc-import-worker.service "$dest/systemd/xedc-import-worker.service"
  fi
  if [[ -f /opt/matomo/compose.yaml ]]; then
    cp -a /opt/matomo/compose.yaml "$secret_dir/matomo-compose.yaml"
    chmod 600 "$secret_dir/matomo-compose.yaml"
    python3 - "$secret_dir/matomo-compose.yaml" "$dest/matomo/compose.yaml" <<'PY'
import re, sys
src, dest = sys.argv[1:]
text = open(src, encoding="utf-8").read()
text = re.sub(r"(?i)(password:\s*).+", r"\1\"XEDC_REDACTED\"", text)
open(dest, "w", encoding="utf-8").write(text)
PY
  fi
  if [[ -d /opt/netdata/etc/netdata ]]; then
    mkdir -p "$dest/netdata"
    find /opt/netdata/etc/netdata -type f \( -name '*.conf' -o -name '*.conf.d' \) -print0 2>/dev/null | \
      tar --null -T - -cf - 2>/dev/null | tar -C "$dest/netdata" -xf - 2>/dev/null || \
      cp -a /opt/netdata/etc/netdata/netdata.conf "$dest/netdata/netdata.conf" 2>/dev/null || true
  fi
  if xedc_have docker; then
    docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' >"$dest/docker/containers.tsv" || true
    docker volume ls --format '{{.Name}}' >"$dest/docker/volumes.txt" || true
  fi
  jq -n \
    --arg matomo "$([[ -f /opt/matomo/compose.yaml ]] && echo yes || echo no)" \
    --arg netdata "$(systemctl is-active netdata 2>/dev/null || echo inactive)" \
    --arg worker "$(systemctl is-active xedc-import-worker 2>/dev/null || echo inactive)" \
    '{matomo_compose: $matomo, netdata: $netdata, import_worker: $worker}' >"$dest/services.json"
}

xedc_export_docker_volumes() {
  local dest=$1
  mkdir -p "$dest"
  local volume
  if ! xedc_have docker; then
    xedc_warn "没有 Docker，无法导出数据卷。"
    printf '%s\n' "docker-unavailable" >"$dest/WARNING.txt"
    return 1
  fi
  for volume in matomo_db-data matomo_matomo-app-data; do
    if ! docker volume inspect "$volume" >/dev/null 2>&1; then
      xedc_warn "数据卷不存在：${volume}"
      continue
    fi
    xedc_log INFO "只读导出数据卷 ${volume}。文件级复制不是事务一致快照。"
    docker run --rm --network none --read-only \
      --mount "type=volume,source=${volume},target=/volume,readonly" \
      --mount "type=bind,source=${dest},target=/backup" \
      ubuntu:24.04 \
      tar -C /volume -cf "/backup/${volume}.tar" .
    zstd -T2 -q --rm -f "$dest/${volume}.tar"
    printf '%s\n' "${volume}: crash-consistent file copy while the container kept running" >>"$dest/CONSISTENCY.txt"
  done
  if docker volume inspect xedc-recover-db-data >/dev/null 2>&1; then
    printf '%s\n' "xedc-recover-db-data excluded: not a confirmed site dependency" >>"$dest/EXCLUDED.txt"
  fi
}

xedc_restore_services() {
  local snapshot=$1
  local extracted=$2
  mkdir -p "$extracted"
  if [[ -f $snapshot/services-config.tar.zst ]]; then
    zstdcat "$snapshot/services-config.tar.zst" | tar -C "$extracted" -xf -
  fi
  if [[ -f $extracted/systemd/xedc-import-worker.service ]]; then
    xedc_run cp "$extracted/systemd/xedc-import-worker.service" /etc/systemd/system/xedc-import-worker.service
    xedc_run systemctl daemon-reload || true
  fi
  if [[ -f $extracted/matomo/compose.yaml ]]; then
    xedc_log INFO "已恢复 Matomo compose 模板。密钥需从 secrets 写回后才能启动。"
  fi
  if ! xedc_have docker; then
    xedc_warn "目标机没有可用的 Docker 守护进程。已保存 compose 和启动参数，容器未实际拉起。"
    return 0
  fi
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 或 restore.sh 运行。" >&2
  exit 1
fi
