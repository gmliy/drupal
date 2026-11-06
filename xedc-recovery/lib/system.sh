#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
if [[ -z "${XEDC_COMMON_LOADED:-}" ]]; then
  # shellcheck source=common.sh
  source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
fi

xedc_copy_dir() {
  local src=$1 dest=$2
  if [[ ! -d "$src" ]]; then
    return 0
  fi
  mkdir -p "$dest"
  rsync -a "$src/" "$dest/"
}

system_capture() {
  local dest=$1
  mkdir -p "$dest"
  xedc_copy_dir /etc/nginx "$dest/nginx"
  xedc_copy_dir /etc/php "$dest/php"
  xedc_copy_dir /etc/mysql "$dest/mysql"
  xedc_copy_dir /etc/redis "$dest/redis"
  xedc_copy_dir /etc/logrotate.d "$dest/logrotate"
  xedc_copy_dir /etc/ufw "$dest/ufw"
  if [[ -d /etc/fail2ban ]]; then
    xedc_copy_dir /etc/fail2ban "$dest/fail2ban"
  fi
  xedc_copy_dir /etc/sysctl.d "$dest/sysctl"
  mkdir -p "$dest/systemd" "$dest/cron.d" "$dest/cron" "$dest/rootfs"
  local unit
  shopt -s nullglob
  for unit in /etc/systemd/system/xedc-*; do
    cp -a "$unit" "$dest/systemd/"
  done
  local cronf
  for cronf in /etc/cron.d/xedc-* /etc/cron.d/php /etc/cron.d/certbot; do
    if [[ -f "$cronf" ]]; then
      cp -a "$cronf" "$dest/cron.d/"
    fi
  done
  shopt -u nullglob
  crontab -u root -l >"$dest/cron/root.cron" 2>/dev/null || true
  crontab -u www-data -l >"$dest/cron/www-data.cron" 2>/dev/null || true
  if [[ -d /etc/netdata ]]; then
    mkdir -p "$dest/netdata"
    rsync -a --exclude 'claim.conf' --exclude '*claim*' --exclude '*token*' /etc/netdata/ "$dest/netdata/"
  fi
  if [[ -d /opt/netdata/etc/netdata ]]; then
    mkdir -p "$dest/netdata-opt"
    rsync -a --exclude 'claim.conf' --exclude '*claim*' --exclude '*token*' /opt/netdata/etc/netdata/ "$dest/netdata-opt/"
  fi
  if [[ -d /opt/matomo ]]; then
    mkdir -p "$dest/docker/matomo"
    local item
    for item in compose.yaml docker-compose.yml .env; do
      if [[ -f "/opt/matomo/$item" ]]; then
        cp -a "/opt/matomo/$item" "$dest/docker/matomo/"
      fi
    done
  fi
  local script rel
  shopt -s nullglob
  for script in /usr/local/sbin/xedc-* /usr/local/bin/xedc-*; do
    if [[ -f "$script" ]]; then
      rel=${script#/}
      mkdir -p "$dest/rootfs/$(dirname "$rel")"
      cp -a "$script" "$dest/rootfs/$rel"
    fi
  done
  shopt -u nullglob
  {
    xedc_dump_perms /etc/nginx
    xedc_dump_perms /etc/php
    xedc_dump_perms /etc/mysql
    xedc_dump_perms /etc/redis
    xedc_dump_perms /etc/sysctl.d
    xedc_dump_perms /etc/cron.d
  } >"$dest/perms.tsv"
  {
    command -v ffmpeg || true
    command -v ffprobe || true
    command -v 7z || true
    command -v node || true
    command -v composer || true
  } >"$dest/bins.txt"
  if xedc_have docker && docker info >/dev/null 2>&1; then
    mkdir -p "$dest/docker/volumes"
    local vol
    while IFS= read -r vol; do
      [[ -z "$vol" ]] && continue
      case "$vol" in
        *matomo*|*xedc*)
          if docker run --rm -v "${vol}:/volume:ro" alpine tar -C /volume -cf - . 2>/dev/null | zstd -3 -o "$dest/docker/volumes/${vol}.tar.zst"; then
            printf '%s\n' "$vol" >>"$dest/docker/volumes.list"
          else
            xedc_warn "未能打包 Docker 卷 ${vol}"
          fi
          ;;
      esac
    done < <(docker volume ls -q)
  fi
}

xedc_install_filtered_file() {
  local src=$1 dest=$2
  local base filtered
  base=$(basename "$dest")
  if xedc_restore_skip "$base" || xedc_is_git_push_file "$src"; then
    xedc_log "不还原 ${base}：这是旧服务器上的 Git 推送任务"
    return 0
  fi
  filtered=$(mktemp)
  grep -Ev 'git[[:space:]]+push|xedc-push-gmliy' "$src" >"$filtered" || true
  if ! grep -Eq '^[^#[:space:]]' "$filtered"; then
    xedc_log "不还原 ${base}：内容只剩 Git 推送"
    rm -f "$filtered"
    return 0
  fi
  mkdir -p "$(dirname "$dest")"
  cp "$filtered" "$dest"
  rm -f "$filtered"
  xedc_apply_perms "$dest"
}

system_restore_unit() {
  local src=$1
  local base dest
  base=$(basename "$src")
  if xedc_restore_skip "$base" || xedc_is_git_push_file "$src"; then
    xedc_log "不还原 ${base}：这是旧服务器上的 Git 推送任务"
    return 0
  fi
  mkdir -p /etc/systemd/system
  dest="/etc/systemd/system/${base}"
  cp -a "$src" "$dest"
  xedc_apply_perms "$dest"
  if xedc_is_worker_unit "$base"; then
    mkdir -p "$XEDC_STATE_DIR"
    printf '%s\n' "$base" >>"$XEDC_STATE_DIR/deferred-units"
    xedc_log "${base} 等站点自检通过后再启动"
    return 0
  fi
  if ! xedc_unit_has_install "$dest"; then
    xedc_log "${base} 没有 [Install]，不 enable，由定时器或其他单元拉起"
    return 0
  fi
  printf '%s\n' "$base" >>"$XEDC_STATE_DIR/enable-units"
}

system_start_deferred() {
  local list="$XEDC_STATE_DIR/deferred-units"
  [[ -s "$list" ]] || return 0
  if ! xedc_systemd; then
    xedc_warn "没有 systemd，worker 已复制但未启动。真实服务器会在自检通过后启动"
    return 0
  fi
  local base dest
  while IFS= read -r base; do
    [[ -z "$base" ]] && continue
    dest="/etc/systemd/system/${base}"
    [[ -f "$dest" ]] || continue
    if xedc_unit_has_install "$dest"; then
      systemctl enable "$base" || xedc_warn "未能启用 ${base}"
    else
      xedc_log "${base} 没有 [Install]，不 enable"
    fi
    systemctl start "$base" || xedc_die "worker ${base} 没有启动。日志: ${XEDC_RESTORE_LOG}"
    xedc_log "已启动 ${base}"
  done <"$list"
}

system_restore() {
  local src=$1
  [[ -d "$src" ]] || return 0
  if [[ -f "$src/perms.tsv" ]]; then
    XEDC_PERMS_FILE="$src/perms.tsv"
    export XEDC_PERMS_FILE
  fi
  if [[ -d "$src/nginx/conf.d" ]]; then
    xedc_prepare_dir /etc/nginx/conf.d
    cp -a "$src/nginx/conf.d/." /etc/nginx/conf.d/
    rm -f /etc/nginx/conf.d/drupal.conf /etc/nginx/conf.d/default.conf
    xedc_apply_tree /etc/nginx/conf.d
  fi
  local ver=""
  if [[ -f "$XEDC_STATE_DIR/php-version" ]]; then
    ver=$(cat "$XEDC_STATE_DIR/php-version")
  fi
  if [[ -n "$ver" && -d "$src/php/${ver}/fpm/conf.d" ]]; then
    mkdir -p "/etc/php/${ver}/fpm/conf.d"
    local ini
    shopt -s nullglob
    for ini in "$src/php/${ver}/fpm/conf.d"/99-*.ini; do
      cp -a "$ini" "/etc/php/${ver}/fpm/conf.d/"
      xedc_apply_perms "/etc/php/${ver}/fpm/conf.d/$(basename "$ini")"
    done
    shopt -u nullglob
  elif [[ -d "$src/php" ]]; then
    xedc_warn "备份里的 PHP 配置与当前版本不一致，已改用模板"
  fi
  if [[ -d "$src/cron.d" ]]; then
    xedc_prepare_dir /etc/cron.d
    local cronf
    shopt -s nullglob
    for cronf in "$src/cron.d"/*; do
      [[ -f "$cronf" ]] || continue
      xedc_install_filtered_file "$cronf" "/etc/cron.d/$(basename "$cronf")"
    done
    shopt -u nullglob
  fi
  if [[ -s "$src/cron/root.cron" ]]; then
    xedc_install_filtered_file "$src/cron/root.cron" /tmp/xedc-root.cron
    if [[ -f /tmp/xedc-root.cron ]]; then
      crontab -u root /tmp/xedc-root.cron || xedc_warn "root crontab 未能还原"
      rm -f /tmp/xedc-root.cron
    fi
  fi
  if [[ -s "$src/cron/www-data.cron" ]]; then
    xedc_install_filtered_file "$src/cron/www-data.cron" /tmp/xedc-www.cron
    if [[ -f /tmp/xedc-www.cron ]]; then
      crontab -u www-data /tmp/xedc-www.cron || xedc_warn "www-data crontab 未能还原"
      rm -f /tmp/xedc-www.cron
    fi
  fi
  if [[ -d "$src/systemd" ]]; then
    mkdir -p "$XEDC_STATE_DIR"
    : >"$XEDC_STATE_DIR/deferred-units"
    : >"$XEDC_STATE_DIR/enable-units"
    local unit
    shopt -s nullglob
    for unit in "$src/systemd"/*; do
      [[ -f "$unit" ]] || continue
      system_restore_unit "$unit"
    done
    shopt -u nullglob
    if xedc_systemd; then
      systemctl daemon-reload || true
      local base
      while IFS= read -r base; do
        [[ -z "$base" ]] && continue
        systemctl enable "$base" || xedc_warn "未能启用 ${base}"
        if [[ "$base" == *.timer ]]; then
          systemctl start "$base" || xedc_warn "未能启动定时器 ${base}"
        fi
      done <"$XEDC_STATE_DIR/enable-units"
    else
      xedc_warn "没有 systemd，自定义单元已按规则复制但未启用"
    fi
  fi
  if [[ -d "$src/logrotate" ]]; then
    mkdir -p /etc/logrotate.d
    cp -a "$src/logrotate/." /etc/logrotate.d/
  fi
  if [[ -d "$src/sysctl" ]]; then
    xedc_prepare_dir /etc/sysctl.d
    cp -a "$src/sysctl/." /etc/sysctl.d/
    xedc_apply_tree /etc/sysctl.d
    sysctl --system >/dev/null 2>&1 || xedc_warn "部分 sysctl 未能应用"
  fi
  if [[ -d "$src/ufw" ]]; then
    mkdir -p /etc/ufw
    cp -a "$src/ufw/." /etc/ufw/
  fi
  if [[ -d "$src/fail2ban" ]]; then
    mkdir -p /etc/fail2ban
    cp -a "$src/fail2ban/." /etc/fail2ban/
    xedc_svc fail2ban || xedc_warn "fail2ban 未能启动"
  fi
  if [[ -d "$src/netdata" ]]; then
    mkdir -p /etc/netdata
    rsync -a --exclude 'claim.conf' --exclude '*claim*' --exclude '*token*' "$src/netdata/" /etc/netdata/
  fi
  if [[ -d "$src/netdata-opt" && -d /opt/netdata/etc/netdata ]]; then
    rsync -a --exclude 'claim.conf' --exclude '*claim*' --exclude '*token*' "$src/netdata-opt/" /opt/netdata/etc/netdata/
  fi
  if [[ -d "$src/docker/matomo" ]]; then
    mkdir -p /opt/matomo
    local item
    for item in compose.yaml docker-compose.yml .env; do
      if [[ -f "$src/docker/matomo/$item" ]]; then
        cp -a "$src/docker/matomo/$item" "/opt/matomo/$item"
        xedc_apply_perms "/opt/matomo/$item"
      fi
    done
    if [[ -f /opt/matomo/compose.yaml || -f /opt/matomo/docker-compose.yml ]]; then
      if ! declare -F docker_compose_up >/dev/null 2>&1; then
        # shellcheck source=docker.sh
        source "$(dirname "${BASH_SOURCE[0]}")/docker.sh"
      fi
      docker_compose_up /opt/matomo
    fi
  fi
  if [[ -d "$src/rootfs" ]]; then
    local rel dest
    while IFS= read -r -d '' rel; do
      dest="/${rel#"$src/rootfs/"}"
      if xedc_restore_skip "$(basename "$rel")" || xedc_is_git_push_file "$rel"; then
        xedc_log "不还原 $(basename "$rel")：这是旧服务器上的 Git 推送任务"
        continue
      fi
      mkdir -p "$(dirname "$dest")"
      cp -a "$rel" "$dest"
      xedc_apply_perms "$dest"
    done < <(find "$src/rootfs" -type f -print0)
  fi
  if [[ -d "$src/netdata" || -d "$src/netdata-opt" ]]; then
    xedc_warn "Netdata 配置已还原。claim token 不在备份里，请重新 claim"
  fi
  if [[ -f "$src/docker/volumes.list" ]] && docker info >/dev/null 2>&1; then
    local vol archive
    while IFS= read -r vol; do
      [[ -z "$vol" ]] && continue
      archive="$src/docker/volumes/${vol}.tar.zst"
      [[ -f "$archive" ]] || continue
      docker volume create "$vol" >/dev/null
      zstd -dc "$archive" | docker run --rm -i -v "${vol}:/volume" alpine tar -C /volume -xf - || xedc_warn "未能还原 Docker 卷 ${vol}"
    done <"$src/docker/volumes.list"
  fi
  xedc_warn "Netdata claim token 不会恢复。请在新服务器重新 claim"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "系统配置函数库" >&2
  exit 0
fi
