# XEDC 备份与恢复

日常备份和全新服务器恢复都从这里开始。密钥放在私有仓库的 `xedc-recovery/secure/`，加密迁移包放在 Cloudflare R2。

## 1. 快速备份

在源服务器、仓库目录里执行：

```bash
sudo xedc-recovery/backup.sh
```

备份并上传到 Cloudflare R2：

```bash
sudo xedc-recovery/backup.sh --upload-r2
```

已经有迁移包、只上传、不重新备份：

```bash
sudo xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst
```

本地备份在 `/var/backups/xedc`。成功的快照目录里有 `STATUS`，内容是 `PASS`。同一目录还有 `xedc-migration-时间.tar.zst`、同名 `.sha256` 和 `.manifest.public.json`。

查看 R2 里的迁移包：

```bash
sudo xedc-recovery/download.sh --source=r2 --list
```

没有迁移包时会写明“R2 中没有找到迁移包。”，这条命令仍然成功。上传是否完成看 `/var/log/xedc-recovery/upload-文件名.log` 里的 `result=PASS`。本地备份完成本身不等于已经上传到 R2。

## 2. 快速恢复

全新服务器先按下一节放好只读 Deploy Key，然后执行这一条：

```bash
sudo /opt/xedc/bootstrap-recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

它会用 Deploy Key 拉取私有仓库，读取其中的 age 私钥和 R2 配置，下载最新迁移包，校验 SHA-256、zstd 和 manifest，解密后再安装环境并恢复。

仓库已经在本机时，用同样参数直接恢复：

```bash
sudo xedc-recovery/recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

这条命令会自动完成：

- 检查系统、架构、内存、磁盘和到 GitHub 的网络。
- 用只读 Deploy Key 确认私有仓库；密钥从 `xedc-recovery/secure/age.key` 安装到本机，不需要每次粘贴私钥。
- 读取 `xedc-recovery/secure/r2.env`，不需要每次重填 R2 凭据。
- 下载 R2 上最新的迁移包，并同时取回 `.sha256` 和 `.manifest.public.json`。
- 校验 SHA-256、zstd 和 manifest。解密失败会立刻停止。
- 调用 `restore.sh --mode=full` 安装 Nginx、PHP 8.5、MariaDB 12、Composer、Docker，并恢复数据库、公共文件、私有文件、Matomo 和 Netdata 配置。

指定历史备份：

```bash
sudo xedc-recovery/recover.sh --backup 20261104T105132Z --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

只检查、不下载也不安装：

```bash
sudo xedc-recovery/recover.sh --dry-run
```

日志在 `/var/log/xedc-recovery/recover.log` 和 `/var/log/xedc-recovery/recovery.log`。命令退出码为 0，并且日志里有“恢复流程结束”时，这次恢复跑完了。数据库账号在 `/root/.drupal-credentials`。管理员密码与原站一致。

回滚：

```bash
sudo xedc-recovery/restore.sh --rollback
```

## 3. 首次使用前的准备

下面这些只做一次。之后备份用 `backup.sh`，恢复用上面的一条命令。

GitHub 只读授权。在源服务器执行：

```bash
sudo xedc-recovery/recover.sh --init-deploy-key
```

把打印出的公钥加到 `xxedc/drupal` 的 Deploy keys，只给读取权限。私钥留在 `/root/.config/xedc-recovery/github-deploy.key`，权限 600。新服务器把这一个文件复制到相同路径。不要把私钥提交到别的仓库。

把源服务器上的 `xedc-recovery/bootstrap-recover.sh` 和 `/root/.config/xedc-recovery/github-deploy.key` 复制到新服务器，然后执行：

```bash
sudo install -d -m 755 /opt/xedc
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo install -m 755 bootstrap-recover.sh /opt/xedc/bootstrap-recover.sh
```

age 私钥已经在 `xedc-recovery/secure/age.key`。不要重新生成，否则已经上传的迁移包无法解密。恢复程序会自己把它装到 `/root/.config/xedc-recovery/age.key`。

R2 配置在 `xedc-recovery/secure/r2.env`。恢复程序会在本机没有配置时安装它。Bucket 保持私有。

新服务器使用 Ubuntu 20.04、22.04、24.04、26.04、26.10，或 Debian 11、12、13。需要 root、能访问软件源和 GitHub 的网络，以及足够放下迁移包的磁盘。云安全组放行 80 和 443。脚本不修改安全组。

域名也可以写入 `/root/.config/xedc-recovery/restore.env`，权限 600。模板是 `xedc-recovery/contrib/restore.env.example`。写好以后可以只执行 `sudo xedc-recovery/recover.sh`。

## 4. 项目介绍

这套工具备份并恢复 Drupal 站点及其配套服务：数据库、公共文件、私有文件、Composer 项目、Nginx、PHP-FPM、Matomo、Netdata、Docker 配置和媒体工具。密钥用 age 加密，私钥不放进迁移包。

PHP 只使用 8.5，不会降到 8.4。新服务器安装 MariaDB 12 系列里当前软件源提供的版本。软件源没有 MariaDB 12 时会停止，并标明 BLOCKED，不会改装旧版本后当成成功。

支持的系统是上面列出的 Ubuntu 和 Debian。迁移包格式仍是 `.tar.zst`，配套 `.sha256` 和 `.manifest.public.json`，远端目录是 `xedc-backups/年/月/`。已经上传的包可以继续下载和恢复。

## 5. 常见问题

GitHub 认证失败：Deploy Key 不在 `/root/.config/xedc-recovery/github-deploy.key`，或公钥没有加到仓库的只读 Deploy keys。命令会非零退出，不会继续恢复。

R2 下载失败：看 `recover.log` 里的阶段。鉴权失败、Bucket 不存在和空列表是不同的错误。空列表不是成功下载。

age 解密失败：私钥与做备份时的私钥不一致。命令在解密失败后停止，不会导入数据库。不要重新生成 `secure/age.key`。

磁盘空间不足：命令在下载前停止，不删除已有备份。

PHP 8.5 安装失败：日志写 BLOCKED，不安装 8.4，也不切换网站。

MariaDB 12 安装失败：当前发行版没有 MariaDB 12 软件源，或本机已经有别的版本在运行。后一种情况不会原地升级。

恢复中断：再执行同一条 `recover.sh`。已完成的步骤会跳过，数据库不会重复导入。

回滚：`sudo xedc-recovery/restore.sh --rollback`。回滚日志没有写明校验通过时，不能把它当成已经恢复原状。

## 6. 详细文档

- [xedc-recovery/README.md](xedc-recovery/README.md)
- [xedc-recovery/docs/PACKAGE_DELIVERY.md](xedc-recovery/docs/PACKAGE_DELIVERY.md)
- [xedc-recovery/docs/KNOWN_ISSUES.md](xedc-recovery/docs/KNOWN_ISSUES.md)
- [xedc-recovery/docs/PROGRESS.md](xedc-recovery/docs/PROGRESS.md)
- [xedc-recovery/docs/XEDC_R2_UPLOAD_DOWNLOAD_FIX_REPORT.md](xedc-recovery/docs/XEDC_R2_UPLOAD_DOWNLOAD_FIX_REPORT.md)
