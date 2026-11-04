#!/usr/bin/env bash
# 新服务器上的一条恢复命令。Deploy Key 只需放好一次。
set -Eeuo pipefail
KEY=${XEDC_GITHUB_DEPLOY_KEY:-/root/.config/xedc-recovery/github-deploy.key}
DEST=${XEDC_GITHUB_CHECKOUT:-/opt/xedc/drupal}
if [[ $(id -u) -ne 0 ]]; then
  printf '%s\n' "请用 root 运行。" >&2
  exit 1
fi
if [[ ! -f $KEY || -L $KEY ]]; then
  printf '%s\n' "找不到只读 Deploy Key：${KEY}" >&2
  printf '%s\n' "这是一次性准备。把源服务器的该文件复制到这个路径，权限 600，然后重新执行本命令。" >&2
  exit 1
fi
chmod 600 "$KEY"
install -d -m 700 /root/.config/xedc-recovery "$(dirname "$DEST")"
known=/root/.config/xedc-recovery/github-known-hosts
if [[ ! -s $known ]]; then
  printf '%s\n' 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl' >"$known"
  chmod 644 "$known"
fi
export GIT_SSH_COMMAND="ssh -i ${KEY} -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=${known}"
if ! command -v git >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y git openssh-client ca-certificates
fi
if [[ ! -d $DEST/.git ]]; then
  git clone --depth 1 --filter=blob:none --sparse git@github.com:xxedc/drupal.git "$DEST"
  git -C "$DEST" sparse-checkout set xedc-recovery
else
  git -C "$DEST" pull --ff-only
fi
chmod 700 "$DEST/xedc-recovery/secure" 2>/dev/null || true
chmod 600 "$DEST/xedc-recovery/secure/age.key" "$DEST/xedc-recovery/secure/r2.env" 2>/dev/null || true
exec "$DEST/xedc-recovery/recover.sh" "$@"
