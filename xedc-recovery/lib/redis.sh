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

redis_configure() {
  xedc_load_tune
  mkdir -p /etc/redis
  php "$XEDC_ROOT/lib/php/render.php" "$XEDC_ROOT/templates/redis-xedc.conf.tpl" /etc/redis/xedc.conf <<JSON
{"redis_maxmemory":"${REDIS_MAXMEMORY}"}
JSON
  if [[ -f /etc/redis/redis.conf ]] && ! grep -q '/etc/redis/xedc.conf' /etc/redis/redis.conf; then
    printf '\ninclude /etc/redis/xedc.conf\n' >>/etc/redis/redis.conf
  fi
}

redis_enable() {
  if xedc_svc redis-server || xedc_svc redis; then
    return 0
  fi
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
