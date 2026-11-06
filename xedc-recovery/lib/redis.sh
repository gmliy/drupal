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
  if ! sysctl --system >/tmp/xedc-sysctl.out 2>&1; then
    xedc_warn "sysctl --system 未能全部应用。真实服务器上请确认 vm.overcommit_memory=1"
    cat /tmp/xedc-sysctl.out >&2 || true
  fi
  rm -f /tmp/xedc-sysctl.out
}

redis_configure() {
  xedc_load_tune
  xedc_prepare_dir /etc/redis
  php "$XEDC_ROOT/lib/php/render.php" "$XEDC_ROOT/templates/redis-xedc.conf.tpl" /etc/redis/xedc.conf <<JSON
{"redis_maxmemory":"${REDIS_MAXMEMORY}"}
JSON
  xedc_apply_perms /etc/redis/xedc.conf
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
  xedc_svc redis-server || xedc_svc redis || true
  local i
  for i in 1 2 3 4 5 6 7 8 9 10; do
    if redis-cli ping 2>/dev/null | grep -q PONG; then
      return 0
    fi
    sleep 0.3
  done
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
