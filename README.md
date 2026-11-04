# XEDC 备份与恢复

源服务器备份，新服务器恢复。密钥在私有仓库的 `xedc-recovery/secure/`，迁移包在 Cloudflare R2。

## 快速备份

在源服务器的仓库目录执行。

| 要做的事 | 命令 |
| --- | --- |
| 只在本机备份 | `sudo xedc-recovery/backup.sh` |
| 备份并上传到 R2 | `sudo xedc-recovery/backup.sh --upload-r2` |
| 上传已经存在的包 | `sudo xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst` |
| 查看 R2 里的包 | `sudo xedc-recovery/download.sh --source=r2 --list` |

```bash
sudo xedc-recovery/backup.sh
```

```bash
sudo xedc-recovery/backup.sh --upload-r2
```

备份写在 `/var/backups/xedc`。成功的快照目录里 `STATUS` 为 `PASS`，旁边有 `xedc-migration-时间.tar.zst`、同名 `.sha256` 和 `.manifest.public.json`。

R2 上传是否完成，看 `/var/log/xedc-recovery/upload-文件名.log` 里的 `result=PASS`。本机备份完成还不是 R2 备份完成。R2 里没有迁移包时，列表会写“R2 中没有找到迁移包。”，这条命令仍然成功。

## 快速恢复

新服务器先用下面的「拉取仓库」拿到工具，再执行一条恢复命令。

仓库已经在 `/opt/xedc/drupal` 时：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

Deploy Key 已经放好、还没有仓库时，用这一条。它会先拉取私有仓库，再恢复：

```bash
sudo /opt/xedc/bootstrap-recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

指定一份历史备份：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --backup 20261104T105132Z --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

只检查密钥和 R2 列表，不下载、不安装：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --dry-run
```

恢复会检查系统、内存、磁盘和网络，读取 age 私钥和 R2 配置，下载最新迁移包，校验 SHA-256、zstd 和 manifest，解密后安装 Nginx、PHP 8.5、MariaDB 12、Composer 和 Docker，并恢复数据库、公共文件、私有文件、Matomo 和 Netdata 配置。解密失败会停止。

日志在 `/var/log/xedc-recovery/recover.log` 和 `/var/log/xedc-recovery/recovery.log`。退出码为 0，且日志里有“恢复流程结束”，这次恢复跑完了。数据库账号在 `/root/.drupal-credentials`。管理员密码与原站一致。

回滚：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --rollback
```

## 拉取 GitHub 仓库

当前仓库是 **Private**。匿名访问 GitHub 会得到 404，所以新服务器现在要用私有方式。公开方式只在仓库改成 Public 之后才可用。

### 私有仓库

用只读 Deploy Key。在源服务器生成公钥：

```bash
sudo xedc-recovery/recover.sh --init-deploy-key
```

把打印出的那一行公钥加到 GitHub 仓库 `xxedc/drupal` 的 Deploy keys，只勾选读取。私钥留在 `/root/.config/xedc-recovery/github-deploy.key`，权限 600。不要把私钥提交到别的仓库。

把私钥和 `xedc-recovery/bootstrap-recover.sh` 复制到新服务器：

```bash
sudo install -d -m 755 /opt/xedc
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo install -m 755 bootstrap-recover.sh /opt/xedc/bootstrap-recover.sh
```

只拉取工具、先不恢复：

```bash
sudo apt-get update
sudo apt-get install -y git openssh-client ca-certificates
printf '%s\n' 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl' | sudo tee /root/.config/xedc-recovery/github-known-hosts >/dev/null
sudo chmod 644 /root/.config/xedc-recovery/github-known-hosts
sudo GIT_SSH_COMMAND='ssh -i /root/.config/xedc-recovery/github-deploy.key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.config/xedc-recovery/github-known-hosts' \
  git clone --depth 1 --filter=blob:none --sparse git@github.com:xxedc/drupal.git /opt/xedc/drupal
sudo GIT_SSH_COMMAND='ssh -i /root/.config/xedc-recovery/github-deploy.key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.config/xedc-recovery/github-known-hosts' \
  git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo chmod 700 /opt/xedc/drupal/xedc-recovery/secure
sudo chmod 600 /opt/xedc/drupal/xedc-recovery/secure/age.key /opt/xedc/drupal/xedc-recovery/secure/r2.env
```

工具在 `/opt/xedc/drupal/xedc-recovery/`。这一份包含 `secure/age.key` 和 `secure/r2.env`。认证失败会停止。

### 公开仓库

仓库改为 Public 之后，新服务器不需要 Deploy Key，直接克隆：

```bash
sudo apt-get update
sudo apt-get install -y git ca-certificates
sudo git clone https://github.com/xxedc/drupal.git /opt/xedc/drupal
```

然后进入工具目录恢复：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

现在不要用这条公开命令。仓库仍是 Private，匿名克隆不会成功。`secure/` 里有解密私钥和 R2 配置，把仓库改成 Public 会把这些密钥公开。保持 Private，用上面的 Deploy Key。

### 不经过 GitHub

源服务器可以 SSH 时，直接复制工具目录。`203.0.113.10` 换成源服务器地址。

```bash
sudo apt-get update
sudo apt-get install -y openssh-client
sudo install -d -m 755 /opt/xedc/drupal
sudo scp -r root@203.0.113.10:/var/www/drupal/xedc-recovery /opt/xedc/drupal/
sudo chmod 700 /opt/xedc/drupal/xedc-recovery/secure
sudo chmod 600 /opt/xedc/drupal/xedc-recovery/secure/age.key /opt/xedc/drupal/xedc-recovery/secure/r2.env
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

从源服务器同步到新服务器时，同名文件会被更新。`198.51.100.20` 换成新服务器地址：

```bash
sudo rsync -a /var/www/drupal/xedc-recovery/ root@198.51.100.20:/opt/xedc/drupal/xedc-recovery/
```

同步后同样把 `secure/` 设为 700，把 `age.key` 和 `r2.env` 设为 600。

## 首次只做一次

- Deploy Key：源服务器 `--init-deploy-key`，公钥加到 GitHub，私钥复制到新服务器。
- age 私钥：已在 `xedc-recovery/secure/age.key`。不要重新生成，否则已有迁移包无法解密。
- R2 配置：已在 `xedc-recovery/secure/r2.env`。Bucket 保持私有。
- 域名可以写入 `/root/.config/xedc-recovery/restore.env`，权限 600。模板是 `xedc-recovery/contrib/restore.env.example`。写好后可以只执行 `recover.sh`。

新服务器使用 Ubuntu 20.04、22.04、24.04、26.04、26.10，或 Debian 11、12、13。需要 root、软件源网络和足够磁盘。云安全组放行 80 和 443。脚本不修改安全组。

## 项目介绍

这套工具备份并恢复 Drupal 及其配套服务：数据库、公共文件、私有文件、Composer 项目、Nginx、PHP-FPM、Matomo、Netdata、Docker 配置和媒体工具。密钥用 age 加密，私钥不放进迁移包。

PHP 只使用 8.5。新服务器安装 MariaDB 12 系列里当前软件源提供的版本。没有 MariaDB 12 软件源时停止并标明 BLOCKED，不会改装旧版本后当成成功。

迁移包是 `.tar.zst`，配套 `.sha256` 和 `.manifest.public.json`。远端目录是 `xedc-backups/年/月/`。已经上传的包可以继续下载和恢复。

## 常见问题

| 现象 | 处理 |
| --- | --- |
| GitHub 认证失败 | 检查 Deploy Key 是否在 `/root/.config/xedc-recovery/github-deploy.key`，公钥是否只读。命令会停止。 |
| 公开克隆失败 | 仓库仍是 Private。改用 Deploy Key，不要把仓库改成 Public。 |
| R2 下载失败 | 看 `recover.log` 的阶段。鉴权失败、Bucket 不存在和空列表是不同错误。 |
| age 解密失败 | 私钥与备份时不一致。命令停止，不导入数据库。不要重新生成 `secure/age.key`。 |
| 磁盘不足 | 下载前停止，不删除已有备份。 |
| PHP 8.5 安装失败 | 日志为 BLOCKED，不安装 8.4。 |
| MariaDB 12 安装失败 | 没有 12 系列软件源，或本机已有其他版本在运行。已有数据库不会原地升级。 |
| 恢复中断 | 再执行同一条 `recover.sh`。已完成步骤会跳过。 |
| 需要回滚 | `sudo /opt/xedc/drupal/xedc-recovery/restore.sh --rollback`。日志没有写明校验通过，就不能当成已经回到原状。 |

## 详细文档

- [xedc-recovery/README.md](xedc-recovery/README.md)
- [xedc-recovery/docs/PACKAGE_DELIVERY.md](xedc-recovery/docs/PACKAGE_DELIVERY.md)
- [xedc-recovery/docs/KNOWN_ISSUES.md](xedc-recovery/docs/KNOWN_ISSUES.md)
- [xedc-recovery/docs/PROGRESS.md](xedc-recovery/docs/PROGRESS.md)
- [xedc-recovery/docs/XEDC_R2_UPLOAD_DOWNLOAD_FIX_REPORT.md](xedc-recovery/docs/XEDC_R2_UPLOAD_DOWNLOAD_FIX_REPORT.md)
