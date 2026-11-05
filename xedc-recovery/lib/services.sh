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
    xedc_redact_service_secrets "$dest/netdata"
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

xedc_redact_service_secrets() {
  local root=$1
  [[ -d $root ]] || return 0
  python3 - "$root" <<'PY'
import os, re, sys
root = sys.argv[1]
dsn = re.compile(r"(?i)(dsn:\s*[^:\s]+:)([^@\s]+)(@)")
password = re.compile(r"(?i)(password\s*[:=]\s*)(\S+)")
for dirpath, _, files in os.walk(root):
    for name in files:
        path = os.path.join(dirpath, name)
        try:
            text = open(path, encoding="utf-8").read()
        except UnicodeDecodeError:
            continue
        updated = dsn.sub(r"\1XEDC_REDACTED\3", text)
        updated = password.sub(r"\1XEDC_REDACTED", updated)
        if updated != text:
            open(path, "w", encoding="utf-8").write(updated)
            os.chmod(path, 0o600)
PY
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
    local err
    err=$(mktemp)
    chmod 600 "$err"
    if ! docker run --rm --network none --read-only \
      --mount "type=volume,source=${volume},target=/volume,readonly" \
      --mount "type=bind,source=${dest},target=/backup" \
      ubuntu:24.04 \
      tar -C /volume -cf "/backup/${volume}.tar" . 2>"$err"; then
      xedc_log FAIL "数据卷 ${volume} 导出失败。命令输出已脱敏写入日志。"
      xedc_log FAIL "$(cat "$err")"
      rm -f "$err"
      return 1
    fi
    if ! zstd -T2 -q --rm -f "$dest/${volume}.tar" 2>"$err"; then
      xedc_log FAIL "数据卷 ${volume} 压缩失败。命令输出已脱敏写入日志。"
      xedc_log FAIL "$(cat "$err")"
      rm -f "$err"
      return 1
    fi
    rm -f "$err"
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
  xedc_import_docker_volumes "$snapshot/docker-volumes"
  xedc_start_matomo_if_safe
  xedc_install_netdata_config "$extracted"
}

# 只创建还不存在的数据卷。已有数据卷一律不覆盖，哪怕它当前没有容器使用。
xedc_import_docker_volumes() {
  local dir=$1 archive base
  [[ -d $dir ]] || return 0
  while IFS= read -r archive; do
    [[ -n $archive ]] || continue
    base=$(basename "$archive" .tar.zst)
    if [[ ! $base =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
      xedc_warn "跳过名称不合法的数据卷归档。"
      continue
    fi
    if docker volume inspect "$base" >/dev/null 2>&1; then
      xedc_warn "数据卷 ${base} 已存在，不覆盖。"
      continue
    fi
    xedc_log INFO "导入新数据卷 ${base}。"
    if ! docker volume create "$base" >/dev/null; then
      xedc_warn "无法创建数据卷 ${base}。没有改动已有数据卷。"
      return 1
    fi
    if ! zstdcat "$archive" | docker run --rm -i --network none -v "${base}:/volume" mariadb:lts tar -C /volume -xf -; then
      xedc_warn "数据卷 ${base} 导入失败。没有改动其他数据卷。"
      docker volume rm "$base" >/dev/null 2>&1 || true
      return 1
    fi
  done < <(find "$dir" -maxdepth 1 -type f -name '*.tar.zst' | sort)
}

# 生产机上如果 Matomo 已在运行，或 8088 已被占用，这里直接返回。
xedc_start_matomo_if_safe() {
  local compose_src env_file ini
  if docker ps -a --filter name=^matomo-matomo-1$ --format '{{.Names}}' | grep -qx 'matomo-matomo-1'; then
    xedc_warn "已有 Matomo 容器，不启动，也不修改它。"
    return 0
  fi
  if command -v ss >/dev/null 2>&1 && ss -ltn | awk '{print $4}' | grep -qx '127.0.0.1:8088'; then
    xedc_warn "127.0.0.1:8088 已被占用，不启动 Matomo。"
    return 0
  fi
  if ! docker volume inspect matomo_db-data >/dev/null 2>&1 || ! docker volume inspect matomo_matomo-app-data >/dev/null 2>&1; then
    xedc_warn "Matomo 数据卷未导入，不启动容器。"
    return 0
  fi
  if [[ -e /opt/matomo/compose.yaml || -e /opt/matomo/.env ]]; then
    xedc_warn "已有 /opt/matomo 配置，不覆盖。"
    return 0
  fi
  compose_src=${XEDC_STATE_ROOT}/secrets/matomo-compose.yaml
  [[ -f $compose_src ]] || {
    xedc_warn "没有解密后的 Matomo compose，不启动容器。"
    return 0
  }
  install -d -m 700 /opt/matomo
  install -m 600 "$compose_src" /opt/matomo/compose.yaml
  ini=$(mktemp)
  chmod 600 "$ini"
  docker run --rm --network none -v matomo_matomo-app-data:/volume:ro mariadb:lts cat /volume/config/config.ini.php >"$ini"
  env_file=/opt/matomo/.env
  python3 - "$ini" "$env_file" <<'PY'
import sys
src, dest = sys.argv[1:]
section = ""
password = ""
for line in open(src, encoding="utf-8", errors="replace"):
    text = line.strip()
    if text.startswith("[") and text.endswith("]"):
        section = text.strip("[]")
        continue
    if section == "database" and text.startswith("password"):
        password = text.split("=", 1)[1].strip().strip('"').strip("'")
        break
if password == "":
    raise SystemExit("Matomo 数据卷里没有数据库密码，已停止启动。")
quoted = '"' + password.replace("\\", "\\\\").replace('"', '\\"') + '"'
open(dest, "w", encoding="utf-8").write("MARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n" % (quoted, quoted))
PY
  rm -f "$ini"
  chmod 600 "$env_file"
  # 不启动 cron。它会按公网地址归档，DNS 还指向旧服务器时会访问旧站点。
  docker compose -p matomo --project-directory /opt/matomo up -d db matomo
}

xedc_install_netdata_config() {
  local extracted=$1
  local src=$extracted/netdata/opt/netdata/etc/netdata
  [[ -d $src ]] || return 0
  if [[ -e /opt/netdata/etc/netdata/netdata.conf ]]; then
    xedc_log INFO "本机已有 Netdata 配置，不覆盖。备份中的配置留在恢复目录。"
    return 0
  fi
  if [[ ! -x /opt/netdata/bin/netdata && ! -x /opt/netdata/usr/sbin/netdata ]]; then
    xedc_warn "目标机还没有 Netdata。配置已解出，安装 v2.12.2 后再把该目录拷到 /opt/netdata/etc/netdata。本次不安装，避免替换正在运行的监控。"
    return 0
  fi
  mkdir -p /opt/netdata/etc/netdata
  cp -a "$src/." /opt/netdata/etc/netdata/
  xedc_log INFO "已写入 Netdata 配置。没有重启正在运行的 Netdata。"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 或 restore.sh 运行。" >&2
  exit 1
fi
