# XEDC 备份与恢复

用 root 执行。把 `新域名`、`旧服务器`、`新服务器` 和时间戳换成实际值。网站在 `/var/www/drupal`。恢复工具克隆到 `/opt/xedc/drupal`，只检出 `xedc-recovery/`。当前仓库 `xxedc/drupal` 是私有仓库，地址 `git@github.com:xxedc/drupal.git`。

Git 克隆只带来恢复脚本和密钥。网站代码、数据库和文件来自迁移包。

## 一键命令

旧服务器快速备份，并上传到 R2：

```bash
sudo /var/www/drupal/xedc-recovery/backup.sh --upload-r2
```

这条命令先做一份新备份，再上传刚生成的迁移包。它不上传以前的旧包。本地目录出现 `STATUS` 为 `PASS` 只表示本机备份完成。上传日志里的 `result=PASS` 才表示 R2 上的大小和 SHA-256 已核对。

新服务器第一次拉取工具。用本机已有的 GitHub 读权限：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse git@github.com:xxedc/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
```

新服务器从 R2 拉取最新备份并恢复：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

这条命令安装 Deploy Key、age 私钥和 R2 凭据，更新恢复工具，列出备份桶，下载最新迁移包，核对 SHA-256 和 manifest，解密后执行 `restore.sh --mode=full`。不用单独拷贝 Deploy Key。

指定一份 R2 备份时：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --backup 20261105T214600Z --domain 新域名
```

省略 `--backup` 时用 R2 上最新的一份。域名不变就不要加 `--old-domain` 和 `--new-domain`。

## 怎么备份

在旧服务器、网站目录所在的机器上执行。

只做本地备份，不上传：

```bash
sudo /var/www/drupal/xedc-recovery/backup.sh
```

先检查命令，不写新快照：

```bash
sudo /var/www/drupal/xedc-recovery/backup.sh --dry-run
```

备份加上传：

```bash
sudo /var/www/drupal/xedc-recovery/backup.sh --upload-r2
```

已经有迁移包、只上传这一份，不重新备份：

```bash
sudo /var/www/drupal/xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst
```

上传前只检查本地文件和 R2 配置，不连接远端：

```bash
sudo /var/www/drupal/xedc-recovery/upload.sh --dry-run --file /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst
```

每次备份新建一个 UTC 时间戳目录，不覆盖上一份成功备份。自检失败时删掉未完成目录。成功后自动打迁移包。成功的快照目录超过 14 份时，会删掉最旧的快照目录，并留下最后一份 `PASS`。旁边的 `xedc-migration-*.tar.zst` 不在这条删除里。

备份同时把 `/root/.config/xedc-recovery/github-deploy.key` 复制到仓库工作副本 `xedc-recovery/secure/github-deploy.key`，并写入这份迁移包的加密 `secrets.enc`。旧迁移包不会被改写。备份本身不执行 `git push`。这把钥匙已经在私有仓库里；如果工作副本里的钥匙文件有变化，提交并推送后，其他机器 `git pull` 才能拿到新文件。加密包里的那一份在恢复解密时安装，不依赖这次推送。

旧服务器还没有 Deploy Key 时，先生成一把，把终端打印的公钥加到 GitHub 仓库 Deploy keys，只开读取，然后再备份：

```bash
sudo /var/www/drupal/xedc-recovery/recover.sh --init-deploy-key
```

确认本机和 R2：

```bash
sudo ls -lh /var/backups/xedc
sudo cat /var/backups/xedc/20261105T214600Z/STATUS
sudo /var/www/drupal/xedc-recovery/download.sh --source=r2 --list
sudo grep result= /var/log/xedc-recovery/upload-xedc-migration-20261105T214600Z.log
```

日志：

```text
/var/log/xedc-recovery/recover.log
/var/log/xedc-recovery/recovery.log
/var/log/xedc-recovery/upload-xedc-migration-时间戳.log
```

R2 凭据在 `/root/.config/xedc-recovery/r2.env`，权限 600。备份桶和网站媒体桶是分开的。对象键是 `xedc-backups/年/月/xedc-migration-时间戳.tar.zst`，同一目录还有 `.sha256` 和 `.manifest.public.json`。

不要重新生成 age 私钥。换了私钥，已有的 `secrets.enc` 解不开。

## 备份出来的文件

本机根目录是 `/var/backups/xedc`。下面的时间戳以 `20261105T214600Z` 为例。

传到新服务器或上传到 R2 的是这三份。三份要在一起：

| 文件 | 用途 |
| --- | --- |
| `xedc-migration-20261105T214600Z.tar.zst` | 迁移包。里面是快照、恢复脚本和 `恢复说明.md` |
| `xedc-migration-20261105T214600Z.tar.zst.sha256` | 迁移包的 SHA-256。恢复前用 `sha256sum -c` 核对，不一致就不要解压 |
| `xedc-migration-20261105T214600Z.manifest.public.json` | 不含密码的公开清单。下载和上传时用来确认这份清单属于这个压缩包 |

快照目录 `/var/backups/xedc/20261105T214600Z/` 留在旧服务器，用来核对和重新打包。打包失败时这个目录还在。

| 文件 | 用途 |
| --- | --- |
| `STATUS` | 内容是 `PASS` 才算成功备份。恢复会拒绝其他状态 |
| `BACKUP_REPORT.md` | 给人看的结果、库名、文件数量和警告数 |
| `manifest.json` | 完整清单。权限 600。表数、文件数、R2 镜像数量、来源机器都在这里 |
| `checksums.sha256` | 快照目录里各文件的校验 |
| `database.sql.zst` | 数据库导出 |
| `files-public.tar.zst` | 本机公共文件 |
| `files-private.tar.zst` | 本机私有文件 |
| `files-r2-public.tar.zst` 和 `files-r2-public.sha256` | 从媒体桶拉下来的公共对象副本和每个对象的 SHA-256 |
| `files-r2-private.tar.zst` 和 `files-r2-private.sha256` | 私有对象副本。当前私有前缀没有对象时，这个包是空的 |
| `project-source.tar.zst` | 站点源码。数据库密码和 hash salt 不在这个包里 |
| `composer.lock` | 备份当时的 Composer 锁定文件 |
| `system-config.tar.zst` | 系统配置摘录 |
| `services-config.tar.zst` | Matomo、Netdata、cron 等服务配置。密码已换成占位符 |
| `docker-volumes/` | Docker 卷的文件级复制。容器还在跑，所以不是同一瞬间的冻结快照 |
| `github-software.json` | 备份时记录的软件版本 |
| `secrets.enc` | age 加密的数据库密码、hash salt、邮件配置，以及这份备份收集到的 Deploy Key |

age 私钥不在快照里，也不在迁移包里。它在私有仓库的 `xedc-recovery/secure/age.key`。迁移包里的脚本目录同样不含 `age.key`、`r2.env` 和 `github-deploy.key`。Deploy Key 在 `secrets.enc` 里再放一份。

同一目录还有 `/var/backups/xedc/age.pub`，只是 age 公钥，不能解密。

11 月 4 日及更早的迁移包没有 R2 对象文件，只有清单。2026-11-05 及之后的新包才带 `files-r2-public.tar.zst`。更早的包里也没有 Deploy Key。

## 从仓库拉取工具

工具目录固定是 `/opt/xedc/drupal`。拉取之后，`bootstrap-recover.sh` 或 `recover.sh` 把密钥装到本机：

| 仓库文件 | 安装位置 | 权限 |
| --- | --- | --- |
| `xedc-recovery/secure/age.key` | `/root/.config/xedc-recovery/age.key` | 600 |
| `xedc-recovery/secure/r2.env` | `/root/.config/xedc-recovery/r2.env` | 600 |
| `xedc-recovery/secure/github-deploy.key` | `/root/.config/xedc-recovery/github-deploy.key` | 600 |

本机如果已经有一份不同的同名文件，脚本会停止，不会覆盖。Deploy Key 只用来读 GitHub，和 age 私钥不是同一把钥匙。

### 私有仓库

这是当前可用的拉取方法。

第一次：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse git@github.com:xxedc/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

`--dry-run` 安装三份密钥并列出 R2 备份。不下载迁移包，不安装网站，不恢复。

已经克隆过，只要更新工具并安装密钥：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

已有 `/opt/xedc/drupal/.git` 时，脚本用 Deploy Key 执行 `git pull --ff-only`。

只要更新代码、连密钥安装都不做：

```bash
sudo git -C /opt/xedc/drupal pull --ff-only
```

### 公有仓库

恢复程序没有公有仓库入口。下面是普通的 HTTPS 克隆，当前仓库是 Private，未登录时会失败，也不能代替上面的 Deploy Key：

```bash
git clone --depth 1 https://github.com/xxedc/drupal.git
git -C drupal pull --ff-only
```

匿名克隆成功也不会安装 `secure/age.key`、`secure/r2.env` 和 `secure/github-deploy.key`，不能完成自动恢复。

## 从 R2 恢复

工具已经在 `/opt/xedc/drupal` 之后，在新服务器执行：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

只查看有哪些备份：

```bash
sudo /opt/xedc/drupal/xedc-recovery/download.sh --source=r2 --list
```

下载到指定目录，不立刻恢复：

```bash
sudo /opt/xedc/drupal/xedc-recovery/download.sh --source=r2 --backup 20261105T214600Z --output /root
```

省略 `--backup` 时下载最新一份，同时下载 `.sha256` 和 `.manifest.public.json`。同名文件已存在时不覆盖。默认下载目录是 `/var/lib/xedc-recovery/incoming`。

源服务器会拒绝把备份恢复到自己。

域名要替换内容时：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

域名可以写在 `/root/.config/xedc-recovery/restore.env`，之后可以不带 `--domain`：

```text
DOMAIN=新域名
OLD_DOMAIN=旧域名
NEW_DOMAIN=新域名
```

## 旧服务器把备份传到新服务器

在旧服务器执行。三个文件都要传。迁移包里没有 age 私钥：

```bash
scp /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst root@新服务器:/root/
scp /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst.sha256 root@新服务器:/root/
scp /var/backups/xedc/xedc-migration-20261105T214600Z.manifest.public.json root@新服务器:/root/
```

先拷到自己的电脑再转到新服务器时，同样带上这三个文件。Windows PowerShell 用 `Get-FileHash .\xedc-migration-20261105T214600Z.tar.zst -Algorithm SHA256` 对照 `.sha256` 里的值。

## 新服务器本地恢复

先拉取工具，让 age 私钥装到本机。解密 `secrets.enc` 需要这把钥匙：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse git@github.com:xxedc/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

然后在新服务器核对、解压、恢复：

```bash
cd /root
sha256sum -c xedc-migration-20261105T214600Z.tar.zst.sha256
sudo apt-get update
sudo apt-get install -y zstd
tar --zstd -xf xedc-migration-20261105T214600Z.tar.zst
cd xedc-migration-20261105T214600Z
sudo ./restore.sh --mode=full --domain 新域名
```

`sha256sum` 不是 `OK` 就不要解压。`--mode=full` 会安装 Nginx、MariaDB 12、Composer、ffmpeg、Docker 和 PHP 8.5，然后恢复站点。目标机已经有网站或同名数据库时，改用 `--mode=restore`。它发现已有内容会停止，不会覆盖。

域名要替换时加上 `--old-domain 旧域名 --new-domain 新域名`。要沿用快照里的数据库密码时加上 `--keep-db-credentials`。默认会生成新的数据库密码，并写进凭据文件。

从这份解压目录恢复时，快照就是当前目录里的 `snapshot/`。`./restore.sh` 会自己找到它。

## 恢复后怎么看密码

数据库账户和管理员说明在 `/root/.drupal-credentials`，权限 600。查看：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --show-credentials
```

人还在解压出来的迁移包目录里时：

```bash
sudo ./restore.sh --show-credentials
```

文件里有这些字段：

```text
url=
admin_user=
admin_password=与原站一致
database=
database_user=
database_password=
database_host=localhost
database_port=3306
```

`database_password` 是新服务器上的数据库密码。没有加 `--keep-db-credentials` 时，它是恢复时新生成的，和旧服务器上的数据库密码不同。`admin_password` 写成「与原站一致」表示没有改 uid 1，后台仍用原来的管理员密码。终端和日志不打印这些密码。

要换成新的管理员密码时，在新服务器执行。它会要求输入 `yes`。新密码写进上面的 `admin_password`，不在终端显示：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --reset-admin --domain 新域名
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --show-credentials
```

要一条一次性登录链接：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --login-link --domain 新域名
```

## 恢复后检查

这些命令在新服务器执行。`curl` 使用 `--resolve`，避免域名仍指向旧服务器时打到旧机器。

```bash
php -v
php-fpm8.5 -v
cd /var/www/drupal && ./vendor/bin/drush --uri=https://新域名 status --fields=bootstrap,drupal-version,db-status
```

PHP 和 PHP-FPM 都要是 8.5。Drush 应显示 bootstrap 成功且数据库已连接。

```bash
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/user/login
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/admin
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/articles
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/video
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/models
```

首页、登录、文章、视频、模特期望 200。匿名访问 `/admin` 返回 403 或跳转登录，表示后台没有对匿名开放。

文件和对账：

```bash
find /var/www/drupal/web/sites/default/files -type f | wc -l
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --compare --snapshot /var/lib/xedc-recovery/incoming/xedc-migration-20261105T214600Z/snapshot
```

从 R2 恢复时，快照在 `/var/lib/xedc-recovery/incoming/xedc-migration-时间戳/snapshot`。报告在恢复程序的 `docs/PARITY_REPORT.md`。该目录不可写时在 `/var/lib/xedc-recovery/docs/PARITY_REPORT.md`。

Matomo 在目标机没有同名容器、`127.0.0.1:8088` 空闲、且没有现成的 `/opt/matomo/compose.yaml` 时才会启动：

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/
docker ps --filter name=matomo- --format '{{.Names}} {{.Status}} {{.Ports}}'
```

Netdata 不会自动安装。目标机已有 `/opt/netdata/etc/netdata/netdata.conf` 时不会覆盖，也不会重启现有服务。

证书：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --ssl-status
```

## 重要说明

恢复完成并确认新服务器可用之前，不要删除旧服务器，也不要删 `/var/backups/xedc` 里的迁移包。

| 项目 | 恢复时怎么处理 |
| --- | --- |
| DNS | 不会自动改公网解析。确认新站正常后，再把域名指到新服务器 |
| TLS | 域名还没指向新服务器时，脚本安装自签名证书。指向之后执行 `restore.sh --retry-ssl --domain 新域名` |
| SMTP | 邮件配置在 `secrets.enc` 里。供应商侧的发信授权要仍然有效 |
| R2 媒体 | 网站继续使用原来的媒体桶。备份桶和媒体桶是分开的 |
| 支付、OAuth | 当前站点没有独立的支付或 OAuth 模块 |
| 配置导入 | 默认不执行 `config:import`。需要时才设置 `XEDC_CONFIG_IMPORT=1` |

其他限制：

- 支持 Ubuntu 20.04、22.04、24.04、26.04、26.10 和 Debian 11、12、13。CPU 为 amd64 或 arm64。PHP 必须是 8.5，装不到就停止，不会改用 8.4。Ubuntu 20.04 和 Debian 11 以前没有可用的 PHP 8.5 包，会标记 BLOCKED。
- 新服务器安装 MariaDB 12。从 MariaDB 13 导出的库可以导入 12。
- 恢复中断后重新执行同一条命令，已完成的步骤会跳过。下载得到的同名文件不会被覆盖。
- 回滚只使用新服务器上恢复开始时留下的副本：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --rollback
```

- 备份前就已经不在磁盘、也不在 R2 的私有导入包无法找回。
- 生产机原有的 `/root/backup.sh` 定时任务没有被这套脚本改掉。`xedc-recovery/contrib/systemd/` 里的 timer 没有在生产机启用。

操作手册在 [xedc-recovery/README.md](xedc-recovery/README.md)。
