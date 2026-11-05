#!/usr/bin/env bash
# 新服务器上的一条恢复命令。Deploy Key 从仓库副本自动安装。
set -Eeuo pipefail
SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
KEY=${XEDC_GITHUB_DEPLOY_KEY:-/root/.config/xedc-recovery/github-deploy.key}
DEST=${XEDC_GITHUB_CHECKOUT:-/opt/xedc/drupal}
if [[ $(id -u) -ne 0 ]]; then
  printf '%s\n' "请用 root 运行。" >&2
  exit 1
fi
# 显式指定了密钥路径时，只用该路径，避免测试或手工覆盖被仓库副本换掉。
if [[ -z ${XEDC_GITHUB_DEPLOY_KEY:-} && -f $SCRIPT_DIR/secure/github-deploy.key && ! -L $SCRIPT_DIR/secure/github-deploy.key ]]; then
  if [[ -f $KEY ]] && ! cmp -s "$SCRIPT_DIR/secure/github-deploy.key" "$KEY"; then
    printf '%s\n' "本机 Deploy Key 与仓库中的文件不一致。没有覆盖。" >&2
    exit 1
  fi
  if [[ ! -f $KEY ]]; then
    install -d -m 700 "$(dirname "$KEY")"
    install -m 600 "$SCRIPT_DIR/secure/github-deploy.key" "$KEY"
  fi
fi
if [[ ! -f $KEY || -L $KEY ]]; then
  printf '%s\n' "找不到只读 Deploy Key：${KEY}" >&2
  printf '%s\n' "这份私钥在私有仓库 xedc-recovery/secure/github-deploy.key，也会放进 R2 迁移包的加密密钥。请从已克隆的 xedc-recovery 目录运行本命令，密钥会自动安装。" >&2
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
chmod 600 "$DEST/xedc-recovery/secure/age.key" "$DEST/xedc-recovery/secure/r2.env" "$DEST/xedc-recovery/secure/github-deploy.key" 2>/dev/null || true
exec "$DEST/xedc-recovery/recover.sh" "$@"
