# XEDC 备份与恢复

命令都在仓库的 `xedc-recovery/` 里，用 root 执行。把下面的域名和时间戳换成自己的。

## 快速备份

只在本机备份：

```bash
sudo xedc-recovery/backup.sh
```

备份并上传到 Cloudflare R2 的专用备份桶：

```bash
sudo xedc-recovery/backup.sh --upload-r2
```

已经有迁移包、只上传这一份：

```bash
sudo xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst
```

查看结果：

```bash
sudo ls -lh /var/backups/xedc
sudo cat /var/backups/xedc/20261105T214600Z/STATUS
sudo xedc-recovery/download.sh --source=r2 --list
```

本机目录是 `/var/backups/xedc`。成功的快照里 `STATUS` 为 `PASS`。迁移包、校验和公开清单在同一目录：

```text
/var/backups/xedc/时间戳/
/var/backups/xedc/xedc-migration-时间戳.tar.zst
/var/backups/xedc/xedc-migration-时间戳.tar.zst.sha256
/var/backups/xedc/xedc-migration-时间戳.manifest.public.json
```

上传日志在 `/var/log/xedc-recovery/upload-xedc-migration-时间戳.log`，成功时有 `result=PASS`。

## 快速恢复

新服务器先放好只读 Deploy Key，再执行一条命令。它会拉取私有仓库、取出 age 私钥和 R2 凭据、下载最新迁移包、校验、解密并恢复：

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /root/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo /root/bootstrap-recover.sh --domain example.com
```

`bootstrap-recover.sh` 可以从旧服务器的 `xedc-recovery/bootstrap-recover.sh` 复制到新服务器。仓库克隆之后，同一条命令也可以写成：

```bash
sudo xedc-recovery/bootstrap-recover.sh --domain example.com
```

Deploy Key 只用来读取 GitHub 私有仓库。age 私钥和 R2 凭据在仓库的 `xedc-recovery/secure/`，由恢复程序安装到 `/root/.config/xedc-recovery/`。恢复时不用再手填这两份密钥。

指定一份历史备份：

```bash
sudo xedc-recovery/recover.sh --backup 20261105T214600Z --domain example.com
```

省略 `--backup` 时使用 R2 上最新的迁移包。域名要改写时再加上 `--old-domain` 和 `--new-domain`。

日志：

```text
/var/log/xedc-recovery/recover.log
/var/log/xedc-recovery/recovery.log
```

恢复结束后查看：

```bash
sudo xedc-recovery/restore.sh --show-credentials
curl -skI --resolve example.com:443:127.0.0.1 https://example.com/
```

首页、登录页和内容页返回 200，数据库密码写在 `/root/.drupal-credentials`。

## 首次配置

在源服务器执行一次：

```bash
sudo xedc-recovery/recover.sh --init-deploy-key
```

把打印出来的公钥加到 GitHub 仓库 `xxedc/drupal` 的 Deploy keys，只给读取权限。私钥留在 `/root/.config/xedc-recovery/github-deploy.key`，权限 600。不要把这个私钥放进仓库。

age 私钥已经在私有仓库里，和历史备份对应。不要重新生成。

R2 使用专用私有备份桶，配置文件是 `/root/.config/xedc-recovery/r2.env`，权限 600。字段是 `R2_ACCOUNT_ID`、`R2_BUCKET`、`R2_ACCESS_KEY_ID`、`R2_SECRET_ACCESS_KEY`、`R2_PROVIDER`。网站媒体桶和备份桶分开。

新服务器只需要上面的 Deploy Key。有了它才能克隆私有仓库；仓库里再提供 age 私钥和 R2 凭据。

## 项目介绍

恢复程序面向 Ubuntu 22.04、24.04 和更新的受支持版本。新服务器安装 PHP 8.5 和 MariaDB 12。当前生产库是 MariaDB 13，导出的库可以导入 MariaDB 12。

备份范围：

- Drupal 代码、Composer 锁定文件、自定义模块和主题
- 数据库一致性导出
- `public://` 和 `private://` 文件
- Cloudflare R2 上的媒体对象只读镜像
- Matomo 的数据库卷和应用卷
- Netdata 配置
- Nginx、PHP、Cron、systemd 单元
- 数据库密码、hash salt、对象存储密钥和 AI 密钥，打进 age 加密的 `secrets.enc`

文件复制和 Docker 卷复制是运行中的崩溃一致副本，不是冻结快照。R2 镜像是列出之后逐个下载的，和数据库导出不是同一瞬间。

## 常见问题

GitHub 认证失败：确认 Deploy Key 在 `/root/.config/xedc-recovery/github-deploy.key`，权限 600，并且 GitHub 上只开了读取。age 私钥不能代替这把 SSH 密钥。

下载失败：先看 `sudo xedc-recovery/download.sh --source=r2 --list`。校验失败时删除的是这次的临时文件，已有迁移包不会被覆盖。

解密失败：使用仓库里现有的 age 私钥。不要换钥匙，否则旧备份无法解密。

恢复中断：重新执行同一条 `restore.sh` 或 `recover.sh`。已经完成的步骤会跳过。源服务器会拒绝把备份恢复到自己。

回滚：

```bash
sudo xedc-recovery/restore.sh --rollback
```

回滚使用恢复开始时在目标机留下的副本。它不改源服务器。

## 详细文档

- [2026-11-05 备份与恢复审计](xedc-recovery/docs/AUDIT-20261105-backup-restore.md)
- [设计](xedc-recovery/docs/DESIGN.md)
- [需求](xedc-recovery/docs/REQUIREMENTS.md)
- [已知问题](xedc-recovery/docs/KNOWN_ISSUES.md)
- [进度](xedc-recovery/docs/PROGRESS.md)
