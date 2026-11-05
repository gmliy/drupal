# XEDC 备份与恢复

用 root 执行。把 `新域名`、`旧服务器`、`新服务器` 和时间戳换成实际值。当前仓库 `xxedc/drupal` 是私有仓库。

恢复工具只负责拉取脚本和密钥。网站代码、数据库和文件来自迁移包，不来自这次 Git 克隆。

## 获取恢复工具

工具目录固定是 `/opt/xedc/drupal`。第一次克隆是稀疏检出，只要 `xedc-recovery/`。

### 私有仓库

这是当前实现。仓库地址是 `git@github.com:xxedc/drupal.git`。

只读 Deploy Key 保存在私有仓库的 `xedc-recovery/secure/github-deploy.key`。备份时，服务器上的 `/root/.config/xedc-recovery/github-deploy.key` 会自动复制到这个文件，并写入 R2 迁移包里的加密 `secrets.enc`。新服务器不用再单独拷贝这把私钥。

新服务器第一次克隆可以用本机已有的 GitHub 读权限。克隆完成后，恢复命令会把仓库里的 Deploy Key 安装到 `/root/.config/xedc-recovery/github-deploy.key`：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse git@github.com:xxedc/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

`--dry-run` 会安装 age 私钥、R2 凭据和 Deploy Key，并列出 R2 备份。不下载、不安装网站、不恢复。

已经克隆过时，直接更新并安装密钥：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

已有 `/opt/xedc/drupal/.git` 时，脚本用刚安装的 Deploy Key 执行 `git pull --ff-only`。

只要更新工具、不启动恢复时：

```bash
sudo git -C /opt/xedc/drupal pull --ff-only
```

机器上如果没有任何能读取这个私有仓库的凭据，就无法完成第一次克隆。Deploy Key 本身在仓库里面，不能在完全没有凭据时凭空取出来。解密迁移包时，如果包内有这份密钥，也会安装到本机，供以后拉取仓库。

### 公有仓库

恢复程序没有公有仓库入口，也不会走 `https://github.com/xxedc/drupal.git`。

当前仓库是 Private。未登录时下面的命令会失败，也不能代替上面的 Deploy Key：

```bash
git clone --depth 1 https://github.com/xxedc/drupal.git
git -C drupal pull --ff-only
```

公有克隆即使以后能成功，也只是 Git 本身的用法。它不会安装 `secure/age.key`、`secure/r2.env` 和 `secure/github-deploy.key`，不能完成自动恢复。

## 密钥自动获取

age 私钥、R2 凭据和只读 Deploy Key 都在私有仓库的 `xedc-recovery/secure/`。`recover.sh` 或 `bootstrap-recover.sh` 拉下仓库后自动安装：

| 仓库文件 | 安装位置 | 权限 |
| --- | --- | --- |
| `xedc-recovery/secure/age.key` | `/root/.config/xedc-recovery/age.key` | 600 |
| `xedc-recovery/secure/r2.env` | `/root/.config/xedc-recovery/r2.env` | 600 |
| `xedc-recovery/secure/github-deploy.key` | `/root/.config/xedc-recovery/github-deploy.key` | 600 |

新服务器不要手填 age 私钥、R2 账号或 Deploy Key。本机如果已经有一份不同的同名文件，脚本会停止，不会覆盖。

Deploy Key 只用于读 GitHub，和 age 私钥不是同一把钥匙。迁移包的脚本目录不带这三份文件。Deploy Key 另外加密进 `secrets.enc`，恢复解密后自动安装。age 私钥和 `r2.env` 不进迁移包。

不要重新生成 age 私钥，否则已有备份无法解密。

## 从 R2 恢复

在新服务器执行。工具目录已经克隆到 `/opt/xedc/drupal` 之后：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

这条命令依次完成：拉取私有仓库、安装 age 私钥和 R2 凭据、列出备份桶、下载最新迁移包、核对 SHA-256、检查 manifest、解密 `secrets.enc`、执行 `restore.sh --mode=full`。

指定一份历史备份：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --backup 20261105T214600Z --domain 新域名
```

省略 `--backup` 时用 R2 上最新的一份。域名要替换内容时再加上 `--old-domain 旧域名 --new-domain 新域名`。域名不变就不要加这两项。

只查看有哪些备份：

```bash
sudo /opt/xedc/drupal/xedc-recovery/download.sh --source=r2 --list
```

下载目录默认是 `/var/lib/xedc-recovery/incoming`。日志：

```text
/var/log/xedc-recovery/recover.log
/var/log/xedc-recovery/recovery.log
```

源服务器会拒绝把备份恢复到自己。

## 旧服务器迁到新服务器

### 旧服务器：备份并上传

```bash
sudo xedc-recovery/backup.sh
sudo xedc-recovery/backup.sh --upload-r2
```

第二条会先做一份新备份，再上传刚生成的包。它不会上传以前的旧包。备份同时把 Deploy Key 写入 `xedc-recovery/secure/github-deploy.key` 和这份迁移包的 `secrets.enc`。旧迁移包不会被改写。

旧服务器还没有 Deploy Key 时，先执行一次 `sudo xedc-recovery/recover.sh --init-deploy-key`，把打印的公钥加到仓库的 Deploy keys，只开读取，然后再备份。

已经有迁移包、只上传这一份：

```bash
sudo xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst
```

本机目录是 `/var/backups/xedc`。成功快照的 `STATUS` 为 `PASS`。同一目录还有：

```text
/var/backups/xedc/时间戳/
/var/backups/xedc/xedc-migration-时间戳.tar.zst
/var/backups/xedc/xedc-migration-时间戳.tar.zst.sha256
/var/backups/xedc/xedc-migration-时间戳.manifest.public.json
```

确认上传：

```bash
sudo ls -lh /var/backups/xedc
sudo cat /var/backups/xedc/20261105T214600Z/STATUS
sudo xedc-recovery/download.sh --source=r2 --list
sudo grep result= /var/log/xedc-recovery/upload-xedc-migration-20261105T214600Z.log
```

`result=PASS` 才表示远端大小和 SHA-256 已核对。本地备份完成还不是 R2 备份完成。

### 新服务器：下载并恢复

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

## 用 SCP 传输后恢复

在旧服务器把三个文件传到新服务器。迁移包里没有 age 私钥：

```bash
scp /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst root@新服务器:/root/
scp /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst.sha256 root@新服务器:/root/
scp /var/backups/xedc/xedc-migration-20261105T214600Z.manifest.public.json root@新服务器:/root/
```

新服务器先从私有仓库安装密钥，再校验、解压、恢复。`--dry-run` 会装上 Deploy Key、age 私钥和 R2 凭据：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run

cd /root
sha256sum -c xedc-migration-20261105T214600Z.tar.zst.sha256
sudo apt-get update
sudo apt-get install -y zstd
tar --zstd -xf xedc-migration-20261105T214600Z.tar.zst
cd xedc-migration-20261105T214600Z
sudo ./restore.sh --mode=full --domain 新域名
```

`sha256sum` 不是 `OK` 就不要解压。`--mode=full` 会安装 Nginx、MariaDB 12、Composer、ffmpeg、Docker 和 PHP 8.5，然后恢复站点。目标机已经有网站或同名数据库时，改用 `--mode=restore`；它发现已有内容会停止，不会覆盖。

## 新服务器完整命令

下面是从一台能读取私有仓库的新服务器恢复最新 R2 备份的顺序。不需要单独拷贝 Deploy Key：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse git@github.com:xxedc/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

要指定备份时，把最后一条换成：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --backup 20261105T214600Z --domain 新域名
```

## 恢复后检查

这些命令在新服务器执行。`curl` 使用 `--resolve`，避免域名仍指向旧服务器时误判成功。

```bash
php -v
php-fpm8.5 -v
cd /var/www/drupal && ./vendor/bin/drush --uri=https://新域名 status --fields=bootstrap,drupal-version,db-status
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --show-credentials
```

数据库账户在 `/root/.drupal-credentials`。PHP 和 PHP-FPM 都要是 8.5。Drush 应显示 bootstrap 成功且数据库已连接。

页面：

```bash
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/user/login
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/admin
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/articles
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/video
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/models
```

首页、登录、文章、视频、模特期望 200。匿名访问 `/admin` 返回 403 或跳转登录都表示后台没有对匿名开放。

文件和对账：

```bash
find /var/www/drupal/web/sites/default/files -type f | wc -l
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --compare --snapshot /var/lib/xedc-recovery/incoming/xedc-migration-20261105T214600Z/snapshot
```

从 R2 恢复时，快照在 `/var/lib/xedc-recovery/incoming/xedc-migration-时间戳/snapshot`。用 SCP 解压时，快照在解压目录里的 `snapshot/`。恢复结束时已经写过对账；这份文件在恢复程序的 `docs/PARITY_REPORT.md`，该目录不可写时在 `/var/lib/xedc-recovery/docs/PARITY_REPORT.md`。

Matomo 在目标机没有同名容器、`127.0.0.1:8088` 空闲、且 Docker 可用时才会启动：

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/
docker ps --filter name=matomo- --format '{{.Names}} {{.Status}} {{.Ports}}'
```

Netdata 进程不会自动安装。恢复只解出配置；目标机已有 `/opt/netdata/etc/netdata/netdata.conf` 时不会覆盖，也不会重启现有服务。

证书状态：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --ssl-status
```

## 注意事项

恢复完成并确认新服务器可用之前，不要删除旧服务器，也不要删除 `/var/backups/xedc` 里的历史迁移包。

| 项目 | 恢复时怎么处理 |
| --- | --- |
| DNS | 不会自动改公网解析。确认新站正常后，再把域名指到新服务器 |
| TLS | 域名还没指向新服务器时，脚本安装自签名证书。指向之后执行 `restore.sh --retry-ssl --domain 新域名` |
| SMTP | 邮件配置在加密的 `secrets.enc` 里。供应商侧的发信授权要仍然有效，否则需要在供应商处重新开通 |
| R2 媒体 | 凭据随 `secrets.enc` 恢复。网站继续使用原来的媒体桶。备份桶和媒体桶是分开的 |
| 支付、OAuth | 当前站点没有独立的支付或 OAuth 模块 |

其他限制：

- 支持 Ubuntu 20.04、22.04、24.04、26.04、26.10 和 Debian 11、12、13。PHP 必须是 8.5，装不到就停止，不会改用 8.4。Ubuntu 20.04 和 Debian 11 以前没有可用的 PHP 8.5 包，会标记 BLOCKED。
- 新服务器安装 MariaDB 12。生产库是 MariaDB 13 时，导出的库可以导入 12。
- 文件和 Matomo 数据卷是运行中复制的，不是同一瞬间的冻结快照。
- 11 月 4 日及更早的迁移包没有 R2 对象副本，只有清单。2026-11-05 之后的新包才带对象文件。
- 备份前就已经不在磁盘、也不在 R2 的私有导入包无法找回。
- 恢复中断后重新执行同一条命令，已完成的步骤会跳过。下载得到的同名文件不会被覆盖。下载中途掐断后的断点续传没有单独验证。
- 回滚只使用新服务器上恢复开始时留下的副本，不修改旧服务器：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --rollback
```

## 详细文档

- [恢复工具操作手册](xedc-recovery/README.md)
- [2026-11-05 结果说明](xedc-recovery/docs/RESULT-20261105-backup-restore.md)
- [2026-11-05 审计记录](xedc-recovery/docs/AUDIT-20261105-backup-restore.md)
- [设计](xedc-recovery/docs/DESIGN.md)
- [已知问题](xedc-recovery/docs/KNOWN_ISSUES.md)
