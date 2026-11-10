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

xedc_copy_filtered() {
  local src=$1 dest=$2 path rel
  [[ -d "$src" ]] || return 0
  mkdir -p "$dest"
  while IFS= read -r -d '' path; do
    rel=${path#"$src"/}
    if xedc_conf_excluded "$path" || xedc_excluded_path "$rel" || xedc_excluded "$(basename "$path")"; then
      xedc_log_detail "备份跳过 ${path}"
      continue
    fi
    if [[ -d "$path" ]]; then
      mkdir -p "$dest/$rel"
    else
      mkdir -p "$dest/$(dirname "$rel")"
      cp -a "$path" "$dest/$rel"
    fi
  done < <(find "$src" -mindepth 1 -print0)
}

system_capture() {
  local dest=$1
  mkdir -p "$dest"
  xedc_copy_filtered /etc/nginx "$dest/nginx"
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
    if xedc_excluded "$(basename "$unit")" || xedc_is_git_push_file "$unit"; then
      xedc_log "备份跳过 $(basename "$unit")"
      continue
    fi
    cp -a "$unit" "$dest/systemd/"
  done
  local cronf
  for cronf in /etc/cron.d/xedc-* /etc/cron.d/php /etc/cron.d/certbot; do
    if [[ -f "$cronf" ]]; then
      if xedc_excluded "$(basename "$cronf")" || xedc_is_git_push_file "$cronf"; then
        xedc_log "备份跳过 $(basename "$cronf")"
        continue
      fi
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
  if [[ -d /opt ]]; then
    local opt
    shopt -s nullglob
    for opt in /opt/*/compose.yaml /opt/*/docker-compose.yml; do
      [[ -f "$opt" ]] || continue
      if xedc_excluded "$opt" || xedc_excluded "$(basename "$(dirname "$opt")")"; then
        xedc_log "备份跳过 ${opt}"
        continue
      fi
      local proj
      proj=$(basename "$(dirname "$opt")")
      mkdir -p "$dest/docker/$proj"
      cp -a "$(dirname "$opt")/." "$dest/docker/$proj/"
    done
    shopt -u nullglob
  fi
  local script rel
  shopt -s nullglob
  for script in /usr/local/sbin/xedc-* /usr/local/bin/xedc-*; do
    if [[ -f "$script" ]]; then
      if xedc_excluded "$(basename "$script")" || xedc_is_git_push_file "$script"; then
        xedc_log "备份跳过 $(basename "$script")"
        continue
      fi
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
    # shellcheck source=docker.sh
    source "$(dirname "${BASH_SOURCE[0]}")/docker.sh"
    local vol allow saved=0 mount_bytes
    declare -A site_vols=()
    while IFS= read -r vol; do
      [[ -n "$vol" ]] && site_vols[$vol]=1
    done < <(xedc_docker_site_volumes)
    local -a vol_excluded_summary=()
    while IFS= read -r vol; do
      [[ -z "$vol" ]] && continue
      mount_bytes=$(docker system df -v 2>/dev/null | awk -v v="$vol" '$1=="Local" && $2==v {gsub(/[^0-9.]/,"",$3); print $3; exit}')
      if xedc_volume_included "$vol"; then
        site_vols[$vol]=1
        xedc_log_detail "include.list 强制包含卷 ${vol}"
      fi
      if xedc_excluded "$vol"; then
        xedc_backup_skip_logged "docker volume ${vol}" "${mount_bytes:-0}" "匹配 exclude.list"
        vol_excluded_summary+=("${vol}:exclude.list")
        continue
      fi
      if [[ -z "${site_vols[$vol]:-}" ]]; then
        xedc_backup_skip_logged "未使用的卷 ${vol}" "${mount_bytes:-0}" "无运行中容器挂载"
        vol_excluded_summary+=("${vol}:未挂载")
        local vb=${mount_bytes:-0}
        [[ "$vb" =~ ^[0-9]+$ ]] || vb=0
        if (( vb > 104857600 )) && xedc_volume_looks_like_data "$vol"; then
          xedc_say "※ 卷 ${vol} 未挂载但约 $(xedc_human_bytes "$vb")，名称似数据卷。确认备份请加 include.list"
        fi
        continue
      fi
      if docker run --rm -v "${vol}:/volume:ro" alpine tar -C /volume -cf - . 2>/dev/null | zstd -3 -o "$dest/docker/volumes/${vol}.tar.zst"; then
        printf '%s\n' "$vol" >>"$dest/docker/volumes.list"
        local vs
        vs=$(stat -c%s "$dest/docker/volumes/${vol}.tar.zst" 2>/dev/null || echo 0)
        saved=$((saved + vs))
      else
        xedc_warn "未能打包 Docker 卷 ${vol}"
      fi
    done < <(docker volume ls -q)
    if (( saved > 2147483648 )); then
      xedc_warn "Docker 卷备份合计超过 2GB（${saved} 字节），请确认站点确实需要"
    fi
    if ((${#vol_excluded_summary[@]} > 0)); then
      xedc_log_detail "Docker 卷排除: $(IFS=,; echo "${vol_excluded_summary[*]}")"
    fi
  fi
}

xedc_install_filtered_file() {
  local src=$1 dest=$2
  local base filtered
  base=$(basename "$dest")
  if xedc_excluded "$base" || xedc_restore_skip "$base"; then
    xedc_log "不还原 ${base}：按 exclude.list 排除"
    return 0
  fi
  if xedc_is_git_push_file "$src"; then
    xedc_log "不还原 ${base}：这是向 Git 推送的任务，只属于旧服务器"
    return 0
  fi
  filtered=$(xedc_mktemp)
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
  if xedc_excluded "$base" || xedc_restore_skip "$base"; then
    xedc_log "不还原 ${base}：按 exclude.list 排除"
    return 0
  fi
  if xedc_is_git_push_file "$src"; then
    xedc_log "不还原 ${base}：这是向 Git 推送的任务，只属于旧服务器"
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
    local ng
    shopt -s nullglob
    for ng in "$src/nginx/conf.d"/*; do
      [[ -f "$ng" ]] || continue
      if xedc_conf_excluded "$ng" || xedc_excluded "$(basename "$ng")"; then
        xedc_log "按 exclude.list 不还原 nginx 配置 $(basename "$ng")"
        continue
      fi
      if xedc_keep_manual "/etc/nginx/conf.d/$(basename "$ng")"; then
        continue
      fi
      cp -a "$ng" "/etc/nginx/conf.d/$(basename "$ng")"
      xedc_apply_perms "/etc/nginx/conf.d/$(basename "$ng")"
      xedc_remember_hash "/etc/nginx/conf.d/$(basename "$ng")"
    done
    shopt -u nullglob
    rm -f /etc/nginx/conf.d/drupal.conf /etc/nginx/conf.d/default.conf
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
    local root_cron
    root_cron=$(xedc_mktemp root.cron.XXXXXX)
    xedc_install_filtered_file "$src/cron/root.cron" "$root_cron"
    if [[ -f "$root_cron" ]]; then
      crontab -u root "$root_cron" || xedc_warn "root crontab 未能还原"
      rm -f "$root_cron"
    fi
  fi
  if [[ -s "$src/cron/www-data.cron" ]]; then
    local www_cron
    www_cron=$(xedc_mktemp www.cron.XXXXXX)
    xedc_install_filtered_file "$src/cron/www-data.cron" "$www_cron"
    if [[ -f "$www_cron" ]]; then
      crontab -u www-data "$www_cron" || xedc_warn "www-data crontab 未能还原"
      rm -f "$www_cron"
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
    xedc_apply_tree /etc/netdata
  fi
  if [[ -d "$src/netdata-opt" && -d /opt/netdata/etc/netdata ]]; then
    rsync -a --exclude 'claim.conf' --exclude '*claim*' --exclude '*token*' "$src/netdata-opt/" /opt/netdata/etc/netdata/
  fi
  if [[ -d "$src/docker" ]]; then
    local proj
    shopt -s nullglob
    for proj in "$src/docker"/*; do
      [[ -d "$proj" ]] || continue
      [[ "$(basename "$proj")" == "volumes" ]] && continue
      if xedc_excluded "$(basename "$proj")"; then
        xedc_log "按 exclude.list 不还原容器项目 $(basename "$proj")"
        continue
      fi
    done
    shopt -u nullglob
  fi
  if [[ -d "$src/rootfs" ]]; then
    local rel dest
    while IFS= read -r -d '' rel; do
      dest="/${rel#"$src/rootfs/"}"
      if xedc_excluded "$(basename "$rel")" || xedc_restore_skip "$(basename "$rel")"; then
        xedc_log "不还原 $(basename "$rel")：按 exclude.list 排除"
        continue
      fi
      if xedc_is_git_push_file "$rel"; then
        xedc_log "不还原 $(basename "$rel")：这是向 Git 推送的任务，只属于旧服务器"
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
  xedc_warn "Netdata claim token 不会恢复。请在新服务器重新 claim"
}

system_restore_volumes() {
  local src=$1 vol archive
  [[ -f "$src/docker/volumes.list" ]] || return 0
  if ! docker info >/dev/null 2>&1; then
    xedc_warn "Docker 守护进程还没起来，卷稍后再还原"
    return 0
  fi
  while IFS= read -r vol; do
    [[ -z "$vol" ]] && continue
    if xedc_excluded "$vol"; then
      xedc_log "按 exclude.list 不还原卷 ${vol}"
      continue
    fi
    archive="$src/docker/volumes/${vol}.tar.zst"
    [[ -f "$archive" ]] || continue
    docker volume create "$vol" >/dev/null
    zstd -dc "$archive" | docker run --rm -i -v "${vol}:/volume" alpine tar -C /volume -xf - || xedc_warn "未能还原 Docker 卷 ${vol}"
  done <"$src/docker/volumes.list"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "系统配置函数库" >&2
  exit 0
fi
