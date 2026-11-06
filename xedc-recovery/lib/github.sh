#!/usr/bin/env bash
# shellcheck shell=bash
# 用只读 Deploy Key 拉取私有仓库。私钥路径可以配置，内容不写入日志。

xedc_github_repo() {
  printf '%s\n' "${XEDC_GITHUB_REPO:-git@github.com:gmliy/drupal.git}"
}

xedc_github_deploy_key() {
  printf '%s\n' "${XEDC_GITHUB_DEPLOY_KEY:-/root/.config/xedc-recovery/github-deploy.key}"
}

xedc_github_known_hosts() {
  local file=${XEDC_GITHUB_KNOWN_HOSTS:-/root/.config/xedc-recovery/github-known-hosts}
  install -d -m 700 "$(dirname "$file")"
  if [[ ! -s $file ]]; then
    printf '%s\n' 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl' >"$file"
    chmod 644 "$file"
  fi
  printf '%s\n' "$file"
}

xedc_github_ssh_command() {
  local key known
  key=$(xedc_github_deploy_key)
  known=$(xedc_github_known_hosts)
  printf 'ssh -i %q -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%q' "$key" "$known"
}

xedc_github_init_deploy_key() {
  local key pub
  key=$(xedc_github_deploy_key)
  install -d -m 700 "$(dirname "$key")"
  if [[ ! -s $key ]]; then
    ssh-keygen -t ed25519 -N '' -C 'xedc-recovery-readonly' -f "$key" >/dev/null
  fi
  chmod 600 "$key"
  pub="${key}.pub"
  [[ -f $pub ]] || xedc_die "Deploy Key 公钥不存在。"
  chmod 644 "$pub"
  printf '%s\n' "把下面这一行加到 GitHub 仓库 gmliy/drupal 的 Deploy keys，只勾选读取权限。"
  printf '%s\n' "加好之后，新服务器只需要保留私钥文件，不要把私钥提交到其他仓库。"
  cat "$pub"
}

xedc_github_require_key() {
  local key
  key=$(xedc_github_deploy_key)
  [[ -f $key && ! -L $key ]] || xedc_die "找不到 GitHub 只读 Deploy Key。先在源服务器执行 recover.sh --init-deploy-key，把公钥加到仓库后，把私钥放到 ${key}。"
  chmod 600 "$key" || true
}

xedc_github_ls_remote() {
  xedc_github_require_key
  local ssh_cmd
  ssh_cmd=$(xedc_github_ssh_command)
  if ! GIT_SSH_COMMAND=$ssh_cmd git ls-remote "$(xedc_github_repo)" HEAD >/dev/null; then
    return 1
  fi
}

xedc_github_sync() {
  local dest=${XEDC_GITHUB_CHECKOUT:-/opt/xedc/drupal}
  xedc_github_require_key
  local ssh_cmd
  ssh_cmd=$(xedc_github_ssh_command)
  export GIT_SSH_COMMAND=$ssh_cmd
  install -d -m 755 "$(dirname "$dest")"
  if [[ ! -d $dest/.git ]]; then
    git clone --depth 1 --filter=blob:none --sparse "$(xedc_github_repo)" "$dest"
    git -C "$dest" sparse-checkout set xedc-recovery
  else
    git -C "$dest" pull --ff-only
  fi
  [[ -f $dest/xedc-recovery/recover.sh ]] || xedc_die "私有仓库里没有恢复脚本。拉取已停止。"
  [[ -f $dest/xedc-recovery/secure/age.key ]] || xedc_die "私有仓库里没有 age 私钥。拉取已停止。"
  chmod 700 "$dest/xedc-recovery/secure" || true
  chmod 600 "$dest/xedc-recovery/secure/age.key" "$dest/xedc-recovery/secure/r2.env" 2>/dev/null || true
  printf '%s\n' "$dest"
}
