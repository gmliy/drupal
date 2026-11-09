#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

redis_install() {
  if xedc_have redis-server; then
    xedc_log "Redis 已安装: $(redis-server --version 2>/dev/null | head -n 1)"
  fi
  xedc_apt_install redis-server
  local got
  got=$(redis-server --version 2>/dev/null | head -n 1 || true)
  xedc_log "Redis ${got}"
}

redis_overcommit() {
  xedc_prepare_dir /etc/sysctl.d
  local file=/etc/sysctl.d/99-xedc.conf
  if [[ -f "$file" ]] && grep -q '^vm.overcommit_memory=' "$file"; then
    sed -i 's/^vm.overcommit_memory=.*/vm.overcommit_memory=1/' "$file"
  else
    printf '%s\n' 'vm.overcommit_memory=1' >>"$file"
  fi
  xedc_apply_perms "$file"
  local sysctl_out
  sysctl_out=$(xedc_mktemp sysctl.XXXXXX)
  if ! sysctl --system >"$sysctl_out" 2>&1; then
    xedc_warn "sysctl --system 未能全部应用。真实服务器上请确认 vm.overcommit_memory=1"
    cat "$sysctl_out" >&2 || true
  fi
  rm -f "$sysctl_out"
}

redis_configure() {
  # 根因：进程 umask 077 时，新建文件是 0600、目录是 0700，属主是 root。
  # 若再把组改成 redis 却不给组读权限，就是实测的 -rw------- root redis。
  # redis-server.service 的 User=redis。ProtectSystem=true 并不挡住读取 /etc；
  # 单元里的 UMask=007 只影响 redis 自己创建的文件。本机没有强制执行的 redis AppArmor 配置。
  # 所以失败是自主访问控制，不是 AppArmor。写完后必须显式 chown redis:redis、chmod 0640，目录 0755。
  xedc_load_tune
  xedc_prepare_dir /etc/redis
  if xedc_keep_manual /etc/redis/xedc.conf; then
    :
  else
    php "$XEDC_ROOT/lib/php/render.php" "$XEDC_ROOT/templates/redis-xedc.conf.tpl" /etc/redis/xedc.conf <<JSON
{"redis_maxmemory":"${REDIS_MAXMEMORY}"}
JSON
    xedc_apply_perms /etc/redis/xedc.conf
    xedc_remember_hash /etc/redis/xedc.conf
  fi
  if [[ -f /etc/redis/redis.conf ]] && ! grep -q '/etc/redis/xedc.conf' /etc/redis/redis.conf; then
    printf '\ninclude /etc/redis/xedc.conf\n' >>/etc/redis/redis.conf
  fi
  if [[ -f /etc/redis/redis.conf ]]; then
    xedc_apply_perms /etc/redis/redis.conf
  fi
  redis_overcommit
}

redis_enable() {
  if [[ -f /etc/redis/xedc.conf ]]; then
    xedc_assert_readable redis /etc/redis/xedc.conf
  fi
  if [[ -f /etc/redis/redis.conf ]]; then
    xedc_assert_readable redis /etc/redis/redis.conf
  fi
  if ! xedc_svc redis-server && ! xedc_svc redis; then
    xedc_warn "Redis 错误日志："
    tail -n 40 /var/log/redis/redis-server.log >&2 || true
    xedc_die "Redis 没有启动"
  fi
  local i
  for i in 1 2 3 4 5 6 7 8 9 10; do
    if redis-cli ping 2>/dev/null | grep -q PONG; then
      return 0
    fi
    sleep 0.3
  done
  xedc_svc_diag redis-server || true
  xedc_warn "Redis 错误日志："
  tail -n 40 /var/log/redis/redis-server.log >&2 || true
  xedc_die "Redis 没有启动"
}

redis_verify() {
  redis-cli ping | grep -q PONG
  xedc_log "Redis 正常"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  xedc_require_root
  case "${1:-install}" in
    install) redis_install ;;
    configure) redis_configure ;;
    enable) redis_enable ;;
    verify) redis_verify ;;
    *) echo "用法: $0 install|configure|enable|verify" >&2; exit 2 ;;
  esac
fi
