# XEDC 备份与恢复

下面的命令都可以直接复制。`203.0.113.10` 是旧服务器地址，`198.51.100.20` 是新服务器地址，`20261104T105132Z` 是一份已经存在的迁移包时间戳。换成自己的地址和时间戳。域名换成要使用的域名。

## 快速备份

在旧服务器的仓库目录执行。

只在本机备份：

```bash
sudo xedc-recovery/backup.sh
```

备份并上传到 Cloudflare R2：

```bash
sudo xedc-recovery/backup.sh --upload-r2
```

已经有迁移包，只上传、不重新备份：

```bash
sudo xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst
```

查看本机备份：

```bash
sudo ls -lh /var/backups/xedc/xedc-migration-*.tar.zst
sudo sha256sum -c /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst.sha256
```

成功的快照目录在 `/var/backups/xedc/时间戳`，里面的 `STATUS` 是 `PASS`。迁移包、校验文件和公开清单在同一目录：

```text
/var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst
/var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst.sha256
/var/backups/xedc/xedc-migration-20261104T105132Z.manifest.public.json
```

查看 R2 里的迁移包：

```bash
sudo xedc-recovery/download.sh --source=r2 --list
```

没有迁移包时会写出“R2 中没有找到迁移包。”，这条命令仍然成功。上传是否完成看 `/var/log/xedc-recovery/upload-xedc-migration-20261104T105132Z.log` 里的 `result=PASS`。本机备份完成还不是 R2 备份完成。

age 私钥在 `/root/.config/xedc-recovery/age.key`，不在迁移包里。传到新服务器时要单独传这个文件。

## 快速恢复

有两种做法。文件已经传到新服务器时，用第一节的本地恢复。要从 R2 自动下载时，用第二节。

### 文件传过去之后，在新服务器本地恢复

先把 age 私钥放到固定位置，权限 600：

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /root/age.key /root/.config/xedc-recovery/age.key
```

在压缩包所在目录校验、安装解压工具、解压：

```bash
cd /root
sha256sum -c xedc-migration-20261104T105132Z.tar.zst.sha256
sudo apt-get update
sudo apt-get install -y zstd
tar --zstd -xf xedc-migration-20261104T105132Z.tar.zst
```

`sha256sum` 不是 `OK` 就不要继续。进入解压出来的目录，执行包里的恢复命令：

```bash
cd /root/xedc-migration-20261104T105132Z
sudo ./restore.sh --mode=full --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

这条命令会安装 Nginx、MariaDB、Composer、Docker、ffmpeg 和 PHP 8.5，然后恢复数据库、公共文件、私有文件、Matomo 和 Netdata 配置。域名不变时可以只写域名：

```bash
sudo ./restore.sh --mode=full --domain 新域名
```

新服务器上已经有网站目录或同名数据库、不能覆盖时：

```bash
sudo ./restore.sh --mode=restore --domain 新域名
```

`--mode=restore` 发现已有网站或已有数据表就停止。

恢复日志在 `/var/log/xedc-recovery/recovery.log`。日志里有“恢复流程结束”，并且命令退出码是 0，这次恢复跑完了。数据库账号在 `/root/.drupal-credentials`。管理员密码与原站一致。

查看结果：

```bash
php -v
php-fpm8.5 -v
sudo xedc-recovery/restore.sh --show-credentials
```

如果恢复程序不在 `/opt` 或仓库目录，上面最后一条改到解压目录里执行：

```bash
sudo ./restore.sh --show-credentials
```

回滚：

```bash
sudo ./restore.sh --rollback
```

### 新服务器从 R2 自动下载再恢复

这种方式不传迁移包，但新服务器要能读到私有仓库里的 `xedc-recovery/secure/age.key` 和 `secure/r2.env`。仓库放在 `/opt/xedc/drupal` 时：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

下载指定的一份，而不是最新的一份：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --backup 20261104T105132Z --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

只检查，不下载、不安装：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --dry-run
```

日志在 `/var/log/xedc-recovery/recover.log` 和 `/var/log/xedc-recovery/recovery.log`。回滚：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --rollback
```

## 旧服务器和新服务器之间传文件

要传 4 个文件。前 3 个是迁移包，第 4 个是解密私钥。私钥不要和迁移包打进同一个公开压缩包，也不要放到网站目录。

在旧服务器上确认文件存在：

```bash
sudo ls -lh /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst.sha256 /var/backups/xedc/xedc-migration-20261104T105132Z.manifest.public.json /root/.config/xedc-recovery/age.key
```

在旧服务器执行，把文件推到新服务器的 `/root/`：

```bash
scp /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst root@198.51.100.20:/root/
scp /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst.sha256 root@198.51.100.20:/root/
scp /var/backups/xedc/xedc-migration-20261104T105132Z.manifest.public.json root@198.51.100.20:/root/
scp /root/.config/xedc-recovery/age.key root@198.51.100.20:/root/age.key
```

也可以在新服务器执行，从旧服务器拉下来：

```bash
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst /root/
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst.sha256 /root/
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T105132Z.manifest.public.json /root/
scp root@203.0.113.10:/root/.config/xedc-recovery/age.key /root/age.key
sudo chmod 600 /root/age.key
```

传完后在新服务器核对，输出必须是 `OK`：

```bash
cd /root
sha256sum -c xedc-migration-20261104T105132Z.tar.zst.sha256
```

然后按上一节“文件传过去之后，在新服务器本地恢复”继续：安装私钥、解压、执行 `./restore.sh`。

如果还要传整套恢复脚本，而不是只用迁移包里自带的 `restore.sh`，在新服务器执行：

```bash
sudo install -d -m 755 /opt/xedc/drupal
sudo scp -r root@203.0.113.10:/var/www/drupal/xedc-recovery /opt/xedc/drupal/
sudo chmod 700 /opt/xedc/drupal/xedc-recovery/secure
sudo chmod 600 /opt/xedc/drupal/xedc-recovery/secure/age.key /opt/xedc/drupal/xedc-recovery/secure/r2.env
```

从旧服务器把工具目录同步到新服务器，同名文件会被更新：

```bash
sudo rsync -a /var/www/drupal/xedc-recovery/ root@198.51.100.20:/opt/xedc/drupal/xedc-recovery/
```

同步之后在新服务器把 `secure/` 设为 700，把其中的 `age.key` 和 `r2.env` 设为 600。

## 拉取 GitHub 仓库

当前仓库是私有的。匿名 `git clone` 不会成功。公开克隆只在仓库改成 Public 之后才可用。`secure/` 里有解密私钥和 R2 配置，不要为了匿名克隆把仓库改成 Public。

### 私有仓库

在旧服务器生成只读 Deploy Key 的公钥：

```bash
sudo xedc-recovery/recover.sh --init-deploy-key
```

把终端打印出的公钥加到 GitHub 仓库 `xxedc/drupal` 的 Deploy keys，只给读取权限。私钥在 `/root/.config/xedc-recovery/github-deploy.key`。把这个私钥和 `xedc-recovery/bootstrap-recover.sh` 复制到新服务器：

```bash
scp /root/.config/xedc-recovery/github-deploy.key root@198.51.100.20:/root/github-deploy.key
scp /var/www/drupal/xedc-recovery/bootstrap-recover.sh root@198.51.100.20:/root/bootstrap-recover.sh
```

在新服务器放好这两个文件：

```bash
sudo install -d -m 755 /opt/xedc
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /root/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo install -m 755 /root/bootstrap-recover.sh /opt/xedc/bootstrap-recover.sh
```

然后一条命令拉取私有仓库并恢复：

```bash
sudo /opt/xedc/bootstrap-recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

只拉取工具、先不恢复时：

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

拉下来的工具在 `/opt/xedc/drupal/xedc-recovery/`。

### 公开仓库

仓库改为 Public 之后才使用：

```bash
sudo apt-get update
sudo apt-get install -y git ca-certificates
sudo git clone https://github.com/xxedc/drupal.git /opt/xedc/drupal
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

现在仓库仍是 Private，这条公开克隆不会成功。

## 第一次要准备的东西

这些只做一次。

- 迁移包在 `/var/backups/xedc/`。age 私钥在 `/root/.config/xedc-recovery/age.key`，也在私有仓库的 `xedc-recovery/secure/age.key`。不要重新生成，否则已经做好的迁移包无法解密。
- R2 配置在 `xedc-recovery/secure/r2.env`。自动从 R2 下载时才会用到。只做本地文件恢复时不用它。
- 域名可以写入 `/root/.config/xedc-recovery/restore.env`，权限 600。模板是 `xedc-recovery/contrib/restore.env.example`。

新服务器使用 Ubuntu 20.04、22.04、24.04、26.04、26.10，或 Debian 11、12、13。需要 root 和足够磁盘。云安全组放行 80 和 443。脚本不修改安全组。

PHP 只使用 8.5。新服务器安装 MariaDB 12 系列里当前软件源提供的版本。没有这个软件源时会停止，并标明 BLOCKED，不会改装旧版本后当成成功。

## 常见问题

GitHub 认证失败：Deploy Key 不在 `/root/.config/xedc-recovery/github-deploy.key`，或公钥没有加到仓库的只读 Deploy keys。命令会停止。

公开克隆失败：仓库仍是 Private。改用 Deploy Key，或改用上面的 `scp` 传文件。不要把仓库改成 Public。

R2 下载失败：看 `/var/log/xedc-recovery/recover.log`。鉴权失败、Bucket 不存在和空列表是不同的错误。

age 解密失败：新服务器上的私钥不是做这份备份时的私钥。恢复会停止，不会导入数据库。

`sha256sum` 不是 OK：文件没有传完整。重新传迁移包和 `.sha256`，不要解压。

磁盘不足：命令在下载前停止，不删除已有备份。

PHP 8.5 安装失败：日志是 BLOCKED，不会安装 8.4。

MariaDB 12 安装失败：没有 12 系列软件源，或这台机器已经有其他版本的数据库在运行。已有数据库不会被原地升级。

恢复中断：在同一目录再执行同一条 `./restore.sh`。已经完成的步骤会跳过。

回滚：在解压目录执行 `sudo ./restore.sh --rollback`。日志没有写明校验通过，就不能当成已经回到原状。

## 详细文档

- [xedc-recovery/README.md](xedc-recovery/README.md)
- [xedc-recovery/docs/PACKAGE_DELIVERY.md](xedc-recovery/docs/PACKAGE_DELIVERY.md)
- [xedc-recovery/docs/KNOWN_ISSUES.md](xedc-recovery/docs/KNOWN_ISSUES.md)
- [xedc-recovery/docs/PROGRESS.md](xedc-recovery/docs/PROGRESS.md)
- [xedc-recovery/docs/XEDC_R2_UPLOAD_DOWNLOAD_FIX_REPORT.md](xedc-recovery/docs/XEDC_R2_UPLOAD_DOWNLOAD_FIX_REPORT.md)
