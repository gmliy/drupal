# XEDC Drupal 自动化备份与跨服务器迁移系统 v2

`xedc-recovery/` 把 Drupal 站点备份成仓库外的快照，用 age 单独保存密钥，再在另一台 Ubuntu 或 Debian 上安装依赖并恢复。它覆盖数据库、公共文件、私有文件、Composer 项目、Nginx、PHP-FPM、域名替换和证书流程。

仓库里同时保留已发布的 `tools/xedc-recover`。那套工具的新备份不做客户端加密，说明在 [legacy/README-xedc-recover.md](legacy/README-xedc-recover.md)。本页命令只属于 `xedc-recovery/`。

当前没有 `.MIGRATION_DONE`。下文标成「未验收」的步骤不能当成已经完成。

## 快速使用

把 `203.0.113.10`、`198.51.100.20`、时间戳和域名换成自己的。迁移包里有数据库和业务文件，只放在私有位置，不要放进公开网站目录。

### 1. 原服务器一键备份

在仓库目录执行：

```bash
sudo xedc-recovery/backup.sh
```

成功后保留分项快照，并自动写出迁移包和校验文件：

```text
/var/backups/xedc/20261104T082520Z
/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst
/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst.sha256
```

已生成的这份包是 605176048 字节。SHA-256 为 `96149968b29fe370dfc4ee15e6ca404f33cc7a504f22e4f32a14b85f8ec08cc5`，`sha256sum -c` 的结果是 OK。

```bash
sudo ls -lh /var/backups/xedc/xedc-migration-*.tar.zst
sudo sha256sum /var/backups/xedc/xedc-migration-*.tar.zst
```

R2 配置好之后，可以在同一次备份里上传：

```bash
sudo xedc-recovery/backup.sh --upload-r2
```

已有成功快照、只补迁移包时：

```bash
sudo xedc-recovery/backup.sh --package-snapshot /var/backups/xedc/20261104T082520Z
```

### 2. 下载到自己的电脑

在原服务器先看路径、大小和校验值，再用 SSH 把两个文件下载到自己的电脑。不需要另外开 Web 下载服务。

Linux 或 macOS：

```bash
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst .
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst.sha256 .
sha256sum -c xedc-migration-20261104T082520Z.tar.zst.sha256
```

Windows PowerShell（系统自带 OpenSSH）：

```powershell
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst .
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst.sha256 .
Get-FileHash .\xedc-migration-20261104T082520Z.tar.zst -Algorithm SHA256
```

WinSCP、FileZilla 或其他 SFTP 客户端也可以下载这两个文件。PowerShell 显示的哈希必须和 `.sha256` 文件、原服务器 `sha256sum` 的结果一致。不一致就不要继续。下载后的文件请放在加密磁盘或权限受控的目录里。

### 3. 上传到新服务器

方式 A，在原服务器直接传到新服务器：

```bash
scp /var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst root@198.51.100.20:/root/
scp /var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst.sha256 root@198.51.100.20:/root/
```

方式 B，已经下载到自己的电脑后再上传：

```bash
scp xedc-migration-20261104T082520Z.tar.zst root@198.51.100.20:/root/
scp xedc-migration-20261104T082520Z.tar.zst.sha256 root@198.51.100.20:/root/
```

在新服务器校验、解压并恢复。解压后的目录名就是压缩包去掉 `.tar.zst` 的名字：

```bash
cd /root
sha256sum -c xedc-migration-20261104T082520Z.tar.zst.sha256
sudo apt-get update
sudo apt-get install -y zstd
tar --zstd -xf xedc-migration-20261104T082520Z.tar.zst
cd xedc-migration-20261104T082520Z
sudo ./restore.sh --mode=full --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

age 私钥不在包里。用另一条安全通道放到新服务器：

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /安全通道下载的/age.key /root/.config/xedc-recovery/age.key
```

方式 C 见下一节，从 R2 下载到新服务器。

### 4. Cloudflare R2

使用一个专用的私有 Bucket。不要使用网站公开文件那个 Bucket，不要开启公开访问，也不要绑定公开的自定义域名。

1. 在 Cloudflare 控制台创建 R2 Bucket，位置自定，访问保持私有。
2. 记下 Account ID。它会出现在 R2 概览页，终端地址形如 `https://<AccountID>.r2.cloudflarestorage.com`。
3. 创建 R2 API Token。权限只选这一个 Bucket 的 Object Read 和 Object Write。不要给账户管理、用户管理或其他 Bucket 的权限。
4. 保存 Access Key ID 和 Secret Access Key。Secret 只显示一次。
5. 在源服务器或新服务器写配置，权限必须是 600。模板在 `xedc-recovery/contrib/r2.env.example`。

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /dev/null /root/.config/xedc-recovery/r2.env
```

文件内容：

```text
R2_ACCOUNT_ID=账户ID
R2_BUCKET=专用私有桶名称
R2_ACCESS_KEY_ID=访问密钥ID
R2_SECRET_ACCESS_KEY=访问密钥
R2_PROVIDER=Cloudflare
```

测试连接和列出对象：

```bash
sudo xedc-recovery/download.sh --source=r2 --list
```

上传发生在备份命令带 `--upload-r2` 时。对象路径是 `xedc-backups/年/月/xedc-migration-时间.tar.zst`，同时上传 `.sha256` 和去掉端点、桶名、密钥字段的 manifest。age 私钥不会上传。脚本核对远端大小，并下载回来比对 SHA-256。失败时退出码非零。脚本不会删除远端旧对象。

在新服务器下载并恢复：

```bash
git clone https://github.com/xxedc/drupal.git
cd drupal
sudo xedc-recovery/download.sh --source=r2 --backup=20261104T082520Z --output /root
cd /root
sha256sum -c xedc-migration-20261104T082520Z.tar.zst.sha256
sudo apt-get update
sudo apt-get install -y zstd
tar --zstd -xf xedc-migration-20261104T082520Z.tar.zst
cd xedc-migration-20261104T082520Z
sudo ./restore.sh --mode=full --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

省略 `--backup` 时下载该前缀下最新的一个迁移包。缺少 rclone 时，`download.sh` 会安装系统包里的 rclone。更换密钥时覆盖 `r2.env` 里的两行密钥，权限保持 600，并在 Cloudflare 撤销旧 Token。

这台机器还没有专用 R2 配置。上传和下载代码已对本地私有 S3 服务做过往返，包括上面这份 605176048 字节的迁移包，哈希与本地一致。还没有向真正的 Cloudflare 账户上传。

### 5. 新服务器准备

使用 Ubuntu 20.04、22.04、24.04、26.04、26.10，或 Debian 11、12、13。需要 root、能访问软件源的网络、足够放下迁移包和解压结果的磁盘，以及要使用的域名。云安全组需要放行 80 和 443，脚本不修改安全组。恢复前把 age 私钥放到上一节的路径。

### 6. 新服务器一键恢复

包内的 `./restore.sh` 会把快照参数补上。只装基础系统时：

```bash
sudo ./restore.sh --mode=full --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

这会安装 Nginx、MariaDB、Composer、ffmpeg、Docker 和 PHP 8.5，再恢复源码、数据库、公共文件和私有文件。机器上已经有本站目录或同名数据库、只想补软件时：

```bash
sudo ./restore.sh --mode=restore --domain 新域名
```

`restore` 发现已有网站或已有数据表就停止，不会覆盖。

### 7. PHP 8.5

Drupal 只使用 PHP 8.5。不会安装 PHP 8.4、8.3 或 8.2 来代替，也不会在 8.5 安装失败后继续导入数据库。Nginx 只连接 `/run/php/php8.5-fpm.sock`。

恢复后检查：

```bash
php -v
php-fpm8.5 -v
```

两行都要以 8.5 开头。软件源没有 PHP 8.5 时，状态写成 BLOCKED，恢复停止。

2026-11-04 的隔离安装记录：Ubuntu 22.04、24.04、26.04 和 Debian 12、13 装上了 PHP 8.5.12 的 CLI 和 FPM。Ubuntu 26.10 的系统仓库装上了 PHP 8.5.9。Ubuntu 20.04 的 Ondřej 源里没有 `php8.5-*`，记 BLOCKED。Debian 11 在安装基础工具时，安全更新仓库返回 404，测试没有进入 PHP 安装，也记 BLOCKED。日志在 `/var/log/xedc-recovery/php85-matrix/`。

### 8. SSL

域名的 A 记录指向新服务器并且 80 端口可从公网访问时，用 HTTP-01：

```bash
sudo ./restore.sh --ssl-method http --retry-ssl --domain 新域名
```

有 DNS 服务商 API 时用 DNS-01。凭据文件权限 600：

```bash
sudo ./restore.sh --ssl-method dns --dns-provider cf --dns-credentials /root/.config/xedc-recovery/dns.env --retry-ssl --domain 新域名
```

解析和 API 都没有时，脚本安装自签名证书并等待。自签名不是正式证书。真实 CA 签发还没有在本次测试里完成。

### 9. 恢复完成后看哪里

日志在 `/var/log/xedc-recovery/recovery.log`。数据库账户在 `/root/.drupal-credentials`，权限 600，不要贴到工单或仓库。对账报告在 `xedc-recovery/docs/PARITY_REPORT.md`；目录不可写时在 `/var/lib/xedc-recovery/docs/PARITY_REPORT.md`。PHP 安装被拒绝时看 `/var/lib/xedc-recovery/PHP_STATUS`。

### 10. 常见故障

- SCP 连接失败：确认安全组放行 22、用户是 root、本机有对应私钥。`Permission denied (publickey)` 是认证失败，不是文件损坏。
- 上传中断：重新执行同一条 `scp`。校验不是 OK 就不要解压。
- R2 凭据错误：检查 `r2.env` 权限是否为 600，Key 是否只属于该 Bucket。命令会失败，不会把密钥打进日志。
- R2 对象不存在：`download.sh --source=r2 --list` 看实际名字，`--backup` 要填快照时间而不是整个文件名。
- SHA-256 不一致：停止恢复，重新下载。不要使用不完整文件。
- 磁盘空间不足：脚本在打包前检查剩余空间，不会为了腾空间删除已有快照。
- PHP 8.5 软件源不可用：恢复标记 BLOCKED 并停止，不会改装 8.4。
- 数据库导入失败：目标库不会被一份失败导入悄悄换成空库；看日志里的 FAIL 行。
- Composer 依赖失败：确认 PHP 是 8.5，再看 Composer 输出。不要用旧的 `vendor` 目录代替安装。
- SSL 申请失败：先确认 DNS 和安全组。自签名只表示还在等待，不表示证书已签发。
- Docker 卷恢复失败：没有 Docker 守护进程时只保存 compose 和配置，并记 WARN。卷是运行中复制的，不是事务快照。

## 目录

- [项目说明](#项目说明)
- [当前验收状态](#当前验收状态)
- [获取代码](#获取代码)
- [更新代码](#更新代码)
- [快速备份](#快速备份)
- [快速恢复](#快速恢复-1)
- [更换域名](#更换域名)
- [证书](#证书)
- [异地副本](#异地副本)
- [日志、路径和回滚](#日志路径和回滚)
- [凭据](#凭据)

详细参数、限制和测试记录在 [xedc-recovery/README.md](xedc-recovery/README.md)。

## 项目说明

备份在源服务器执行，恢复在目标服务器执行。两边都要用 root 运行 `backup.sh` 和 `restore.sh`。`git clone` 和 `git pull` 使用对仓库目录有写权限的用户。

一次备份生成一个新的 UTC 时间戳目录，不覆盖上一份成功快照。目录里有：

| 文件 | 内容 |
| --- | --- |
| `database.sql.zst` | MariaDB 导出，结尾应有 `Dump completed` |
| `files-public.tar.zst` | `web/sites/default/files` |
| `files-private.tar.zst` | 私有文件。`settings.php` 里的私钥、`*.key` 和对象存储密钥会从这里拿出去 |
| `project-source.tar.zst` | Composer 项目。不含 `vendor`、`web/core`、contrib、`node_modules` 和公共文件。`settings.php` 和 `config/sync` 以脱敏副本放回 |
| `secrets.enc` | age 加密的数据库密码、hash salt、对象存储密钥和站点私钥 |
| `services-config.tar.zst` | crontab、导入队列单元、脱敏后的 Matomo compose |
| `system-config.tar.zst` | 备份时复制的 Nginx 和 PHP 配置。恢复流程目前不把这份归档直接套到目标机 |
| `manifest.json` | schema 2 清单 |
| `checksums.sha256` | 快照文件校验 |
| `composer.lock` | 与站点 lock 文件的副本 |
| `STATUS` | 成功时内容是 `PASS` |

源服务器上的成功快照是 `/var/backups/xedc/20261104T061746Z`。公共文件 8951 个，私有归档 319 个，MariaDB 228 张 InnoDB 表。对象存储清单是 public 765、private 0。Matomo 两个数据卷是在容器运行时复制的，清单记为 WARN。这份快照不能在创建它的机器上恢复，脚本会用 machine-id 拒绝。

架构上的固定选择：

- 操作系统允许 Ubuntu 20.04、22.04、24.04、26.04、26.10，以及 Debian 11、12、13。名单以外的系统会停止。
- PHP 只允许 8.5。软件源没有 PHP 8.5 时标记 BLOCKED 并停止，不安装 8.4 或其他版本。传入 `--php-version 8.4` 会在安装前被拒绝。
- 数据库软件包是 MariaDB。恢复会新建数据库用户密码，除非显式加上 `--keep-db-credentials`。
- Docker、Matomo 和 Netdata 的配置会进入快照。目标机没有 Docker 时只保存配置并记 WARN。嵌套容器还没有拉起。
- 对象存储按站点配置识别 provider。清单记录对象数量。数量相同不等于对象内容已经恢复。
- 域名替换处理普通文本、URL、JSON 和 PHP 序列化，并重写序列化长度。缓存、watchdog、sessions 等表跳过。
- 证书默认在域名指向本机时走 HTTP-01，提供 DNS 服务商时走 DNS-01，否则安装 30 天自签名证书并等待。自签名不是正式签发。

安全上，age 私钥默认在 `/root/.config/xedc-recovery/age.key`，权限 600。公钥在 `/var/backups/xedc/age.pub`。私钥不进快照，不进 Git，也不进异地副本。

## 当前验收状态

| 项目 | 状态 |
| --- | --- |
| 生产备份、脱敏、SHA256、zstd 和 age | 快照 `20261104T061746Z` 自检通过 |
| Ubuntu 24.04 容器中的 PHP 8.5.12、MariaDB、Nginx、PHP-FPM | 不含生产数据的夹具已恢复，并重复执行一次 |
| 序列化域名替换 | 单元测试通过。夹具库替换后可以 unserialize |
| 空回滚目录和临时目录文件哈希 | 通过。空目录标 FAIL |
| 真实 Drupal 的 Composer、Drush、配置导入、数据库更新、首页和后台 | 未验收。隔离的 Drupal 11.4.11 测试快照已生成，干净容器恢复单独记录 |
| 运行中的数据库、Nginx、PHP-FPM 回滚 | 未验收 |
| 管理员重置和一次性登录 | 未验收 |
| HTTP-01、DNS-01、Let's Encrypt staging、续期 | 未向证书机构申请 |
| Matomo、Netdata、Docker 卷拉起 | 未验收 |
| 对象内容校验 | 只核对过备份时的对象数量 |
| Ubuntu 22.04、26.04、26.10，Debian 11、12、13 | 未跑 |
| Docker A 级 24 个场景、B 级子集、连续两轮 | 未跑 |

夹具测试入口是 `bash xedc-recovery/tests/integration/restore-docker.sh`。隔离 Drupal 测试入口是 `bash xedc-recovery/tests/integration/restore-drupal-docker.sh`。两条都只用于测试。生产恢复只用 `xedc-recovery/restore.sh`。

## 获取代码

仓库地址：<https://github.com/xxedc/drupal.git>。默认分支是 `main`。

```bash
git clone https://github.com/xxedc/drupal.git
cd drupal
git status -sb
git rev-parse HEAD
git describe --tags --always
xedc-recovery/backup.sh --help
xedc-recovery/restore.sh --help
```

`backup-before-migration-v2` 是迁移前的历史标签，不是当前工具版本。当前版本就是 `main` 的 HEAD。

正式使用的目录是克隆下来的 `xedc-recovery/`。操作手册是 [xedc-recovery/README.md](xedc-recovery/README.md)。进度和需求对照在 [xedc-recovery/docs/PROGRESS.md](xedc-recovery/docs/PROGRESS.md) 与 [xedc-recovery/docs/REQUIREMENTS.md](xedc-recovery/docs/REQUIREMENTS.md)。

旧的首页说明和 `site-migrate.sh` 在 [legacy/](legacy/)。不要用旧脚本做这次迁移。

## 更新代码

第一次使用走上一节的 `git clone`。已经克隆过的目录用下面的更新流程。

在仓库目录中，先看本地有没有未提交修改：

```bash
cd drupal
git status
git fetch origin
git log --oneline HEAD..origin/main
git diff --stat HEAD..origin/main
git pull --ff-only origin main
git rev-parse --short HEAD
git status -sb
```

`git pull --ff-only` 只在可以快进时更新。它若停下来，先处理本地修改或分叉，再做普通合并。不要执行 `git reset --hard`、`git clean` 或强制推送。

更新代码不会更新 `/var/backups/xedc` 里的快照，也不会替换 age 私钥。

## 快速备份

在源服务器、仓库目录中，用 root 执行。

备份前：

```bash
cd /var/www/drupal
git status -sb
sudo test -d /var/backups/xedc || sudo install -d -m 700 /var/backups/xedc
sudo test -d /root/.config/xedc-recovery || sudo install -d -m 700 /root/.config/xedc-recovery
df -h /var/backups
```

正式备份不需要额外配置文件。站点根默认是 `/var/www/drupal`。私钥不存在时，脚本会生成 `/root/.config/xedc-recovery/age.key` 并把公钥写到 `/var/backups/xedc/age.pub`。已有公钥和私钥对不上时，备份会停止。

```bash
sudo xedc-recovery/backup.sh
```

指定目录时：

```bash
sudo xedc-recovery/backup.sh --output /var/backups/xedc
```

只检查参数和流程、不保留快照时：

```bash
sudo xedc-recovery/backup.sh --dry-run
```

成功时命令最后一行是新目录，例如 `/var/backups/xedc/20261104T061746Z`。该目录里的 `STATUS` 为 `PASS`，`BACKUP_REPORT.md` 第一段结果也是 `PASS`。日志出现 `备份完成`。

查看已有快照、校验和清单：

```bash
sudo bash -c 'for d in /var/backups/xedc/20*; do printf "%s %s\n" "$(basename "$d")" "$(cat "$d/STATUS")"; done'
sudo bash -c 'cd /var/backups/xedc/快照时间 && sha256sum -c checksums.sha256'
sudo jq '{schema,drupal,database,files,warnings}' /var/backups/xedc/快照时间/manifest.json
sudo zstd -t /var/backups/xedc/快照时间/database.sql.zst
```

把 `快照时间` 换成 `ls` 看到的目录名。`checksums.sha256` 权限是 600，所以用 root。`sha256sum -c` 应全部为 `OK`。`jq` 里的 `schema` 应为 `2`。

失败时未完成目录会被删除。查看原因：

```bash
sudo tail -n 80 /var/log/xedc-recovery/recovery.log
```

私钥只留在源服务器的 `/root/.config/xedc-recovery/age.key`。复制到目标服务器时走独立的安全通道，权限保持 600。不要把它放进快照目录、Git 或异地 rsync。

生产备份不要设置 `XEDC_SQL_DUMP_FILE`、`XEDC_DRY_RUN`、`XEDC_SKIP_DOCKER`。这些是测试开关。

## 快速恢复

以下命令在目标服务器执行。源服务器只负责生成快照和保管私钥。

1. 准备 Ubuntu 20.04、22.04、24.04、26.04、26.10，或 Debian 11、12、13。目前干净系统恢复只在 Ubuntu 24.04 容器里跑过夹具。
2. 安装 Git，并克隆仓库。`restore.sh` 会继续安装 Nginx、MariaDB、PHP、Composer、age、zstd 和 jq。

```bash
sudo apt-get update
sudo apt-get install -y git ca-certificates
git clone https://github.com/xxedc/drupal.git
cd drupal
git rev-parse HEAD
xedc-recovery/restore.sh --help
```

3. 把快照目录传到目标机，例如 `/var/backups/xedc/快照时间`。目录权限保持 700。确认 `STATUS` 是 `PASS`。
4. 用安全通道放置私钥，并收紧权限：

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /安全通道下载的/age.key /root/.config/xedc-recovery/age.key
```

5. 恢复前检查。目标上的 `/var/www/drupal` 和同名数据库会被覆盖。已有站点时必须加上 `--force`，脚本会先做回滚快照。来源机器和目标机器的 machine-id 相同时，正式恢复会拒绝执行。

```bash
sudo test "$(cat /var/backups/xedc/快照时间/STATUS)" = PASS
sudo jq '.database.database,.drupal.version,.source.php' /var/backups/xedc/快照时间/manifest.json
df -h /var /var/www
```

6. 正式恢复。生产入口是 `restore.sh`。

```bash
cd /var/www/drupal
sudo xedc-recovery/restore.sh \
  --snapshot /var/backups/xedc/快照时间 \
  --domain 新域名 \
  --old-domain 旧域名 \
  --new-domain 新域名 \
  --php-version 8.5 \
  --force
```

`--domain` 是站点对外域名。`--old-domain` 和 `--new-domain` 交给序列化安全的替换。不加 `--force` 时，目标目录已存在会直接停止。

这条命令会安装软件包、用 Composer 安装依赖、解密 `secrets.enc`、导入数据库、解开公共和私有文件、写 `settings.local.php`、配置 Nginx 和 PHP-FPM、处理证书、按参数替换域名，并在项目含 Drush 时执行 `config:import`、`updatedb` 和 `cache:rebuild`。

7. 恢复后在目标服务器核对：

```bash
sudo test "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = 8.5
sudo mariadb -N -e "SHOW DATABASES"
sudo test -f /var/www/drupal/web/index.php
sudo test -f /var/www/drupal/vendor/bin/drush
sudo nginx -t
systemctl is-active nginx php8.5-fpm mariadb
cd /var/www/drupal && sudo ./vendor/bin/drush --uri=https://新域名 status
curl -sk -o /dev/null -w 'home:%{http_code}\n' --resolve 新域名:443:127.0.0.1 https://新域名/
curl -sk -o /dev/null -w 'login:%{http_code}\n' --resolve 新域名:443:127.0.0.1 https://新域名/user/login
curl -sk -o /dev/null -w 'admin:%{http_code}\n' --resolve 新域名:443:127.0.0.1 https://新域名/admin
sudo xedc-recovery/restore.sh --compare --snapshot /var/backups/xedc/快照时间
```

数据库密码在 `/root/.drupal-credentials`，权限 600。查看时不要把输出贴到工单或仓库：

```bash
sudo xedc-recovery/restore.sh --show-credentials
```

未登录访问 `/admin` 应跳转，返回 200 需要人工确认。HTTP 200 只说明页面有响应。Drush 的 bootstrap、配置状态和数据库内容要单独看。对账报告在 `xedc-recovery/docs/PARITY_REPORT.md`；代码目录不可写时写到 `/var/lib/xedc-recovery/docs/PARITY_REPORT.md`。

重复执行同一条恢复命令时，状态文件里已经完成的步骤会跳过。要再次导入数据库，需要先处理 `/var/lib/xedc-recovery/restore-state.json`。不要为了重跑而删除唯一的成功快照。

测试夹具和隔离 Drupal 的容器脚本会挂载只读代码并限制内存。它们的结果不能代替上面这条生产命令。

## 更换域名

首次恢复时用上一节的 `--old-domain` 和 `--new-domain`。站点已经恢复过、只改域名时，在目标服务器执行：

```bash
sudo xedc-recovery/restore.sh --change-domain --old-domain 旧域名 --new-domain 新域名
```

这条命令读取 `/var/lib/xedc-recovery/restore.cnf` 和已解密的 `db.json`。替换前会导出一份 zstd 数据库快照。替换程序失败时用这份快照导回。空快照会停止，不会把空文件导入数据库。

它会改写序列化字符串的长度，跳过缓存和会话表，并扫描代码里的旧域名，写入 `DOMAIN_HARDCODE.md`。扫描只列出位置，不改主题和模块里的业务代码。相似域名和 `%2E` 编码由单元测试覆盖。失败回滚这一支还没有在 Drupal 库上单独跑过。

替换后检查：

```bash
sudo xedc-recovery/restore.sh --compare --snapshot /var/backups/xedc/快照时间
sudo test -f /var/lib/xedc-recovery/docs/DOMAIN_HARDCODE.md && sudo less /var/lib/xedc-recovery/docs/DOMAIN_HARDCODE.md
```

旧域名的 HTTP 入口会配成跳到新域名。证书不会自动变成正式证书，需要按下一节重新申请。

## 证书

证书命令都在目标服务器上，用 root 执行。下面的方式里，只有自签名等待模式在 Ubuntu 24.04 夹具容器里走到过。HTTP-01、DNS-01、手动 DNS、Let's Encrypt staging、正式签发、续期和回滚都还没有实际申请，不能标成通过。

查看将使用的服务商变量名：

```bash
sudo xedc-recovery/restore.sh --dns-provider list
sudo xedc-recovery/restore.sh --ssl-status
```

自签名等待模式是默认退路。域名还没指向本机、也没有 DNS 凭据时，脚本安装 30 天自签名证书。容器里没有 systemd init，等待 timer 不会启用。事后重试：

```bash
sudo xedc-recovery/restore.sh --retry-ssl --domain 新域名
```

HTTP-01。域名的 A 记录已经指向这台机器，并且 80 端口可从公网访问：

```bash
sudo xedc-recovery/restore.sh --ssl-method http --retry-ssl --domain 新域名
```

DNS-01。凭据文件权限必须是 600 或 400。Cloudflare 使用 `CF_Token`，或 `CF_Key` 加 `CF_Email`。

```bash
sudo install -m 600 /安全通道下载的/dns.env /root/.config/xedc-recovery/dns.env
sudo xedc-recovery/restore.sh \
  --ssl-method dns \
  --dns-provider cf \
  --dns-credentials /root/.config/xedc-recovery/dns.env \
  --retry-ssl \
  --domain 新域名
```

自动写 Cloudflare A 记录：

```bash
sudo xedc-recovery/restore.sh --dns-provider cf --auto-dns-record --retry-ssl --domain 新域名
```

手动 DNS。脚本打印 `_acme-challenge` TXT 后等待公共 DNS，默认 180 秒，可用 `XEDC_DNS_SLEEP` 调整：

```bash
sudo xedc-recovery/restore.sh --dns-manual --retry-ssl --domain 新域名
```

Let's Encrypt 的测试环境还没有跑过。需要试签发时先设服务器名，避免直接打正式环境：

```bash
sudo XEDC_SSL_SERVER=letsencrypt_test xedc-recovery/restore.sh --ssl-method http --retry-ssl --domain 新域名
```

默认 `XEDC_SSL_SERVER=letsencrypt`。正式签发失败时，代码会再试 ZeroSSL。ZeroSSL 失败会返回非零，不会被记成成功。本地 Pebble 没有单独入口。

通配符不能用 HTTP-01：

```bash
sudo xedc-recovery/restore.sh --wildcard --ssl-method dns --dns-provider cf --retry-ssl --domain 新域名
```

续期和状态：

```bash
sudo xedc-recovery/restore.sh --renew-ssl --domain 新域名
sudo xedc-recovery/restore.sh --ssl-status
sudo openssl x509 -in /etc/xedc-recovery/ssl/新域名/fullchain.pem -noout -issuer -dates
```

acme.sh 固定为 3.1.6，并核对 SHA256。签发日志在 `/var/log/xedc-recovery/acme-issue.log`。

## 异地副本

工具内置的复制只在环境变量指向一个本地目录时发生。它使用 `rsync -a` 复制快照目录，不复制 age 私钥。

```bash
sudo install -d -m 700 /mnt/offsite/xedc
sudo XEDC_OFFSITE_DEST=/mnt/offsite/xedc xedc-recovery/backup.sh
```

手工传到另一台机器时，只传快照目录。中断后可以重复执行同一条 rsync，已传完的文件会跳过：

```bash
rsync -a --partial /var/backups/xedc/快照时间/ user@backup-host:/var/backups/xedc/快照时间/
ssh user@backup-host 'cd /var/backups/xedc/快照时间 && sha256sum -c checksums.sha256'
```

不要把 `/root/.config/xedc-recovery/age.key` 放进这条 rsync。私钥另走安全通道。

保留策略留下最近 14 份 `PASS`，并且不会删除最后一份成功快照。传输出错时不要手动删除源上唯一的 `PASS` 目录。远端校验不是 `OK` 时，保留源目录再传一次。

## 日志、路径和回滚

| 路径 | 用途 | 权限 |
| --- | --- | --- |
| `/var/backups/xedc/` | 快照和 `age.pub` | 目录 700 |
| `/root/.config/xedc-recovery/age.key` | age 私钥 | 600 |
| `/root/.config/xedc-recovery/dns.env` | DNS API 凭据，可选用 | 600 |
| `/var/lib/xedc-recovery/` | 恢复状态、`restore.cnf`、`db.pass`、回滚目录 | 700 |
| `/var/log/xedc-recovery/recovery.log` | 备份和恢复日志 | 600 |
| `/root/.drupal-credentials` | 恢复后的数据库账户。重置管理员后，新密码也写在这里 | 600 |
| `/etc/xedc-recovery/ssl/域名/` | 证书和私钥 | 目录 700 |

失败时先看日志里的 `FAIL` 行。日志会遮盖密码、私钥和 `hash_salt`。

```bash
sudo tail -n 100 /var/log/xedc-recovery/recovery.log
sudo cat /var/lib/xedc-recovery/rollback-latest
```

回滚使用最近一次恢复前写下的目录：

```bash
sudo xedc-recovery/restore.sh --rollback
sudo cat "$(cat /var/lib/xedc-recovery/rollback-latest)/ROLLBACK_STATUS"
```

有站点目录快照时，它把目录移回原位并核对 SHA256。数据库快照存在时重新导入，并核对表数量。捕获过 Nginx 和 PHP-FPM 池配置时写回并重载。没有可核对的内容，或核对失败，`ROLLBACK_STATUS` 为 `FAIL`。不要把 FAIL 当成已经恢复。

运行中的数据库、Nginx 和 PHP-FPM 回滚还没有在验收记录里通过。生产机上的 Nginx、PHP-FPM 和 MariaDB 不能拿来做这次试验。

定时单元在 `xedc-recovery/contrib/systemd/`，时间是 03:00 和 15:00。这台生产机没有启用。现有 crontab 里的 `/root/backup.sh` 保持原样。

## 凭据

不要把快照、`*.sql`、`*.zst`、`*.age`、私钥、`settings.local.php` 或 `.drupal-credentials` 提交到 Git。

公开历史里已经有数据库转储，以及被跟踪的 `settings.php` 和 `config/sync/smtp.settings.yml` 中的口令。标签 `backup-before-migration-v2` 指向包含这些内容的提交。历史没有改写。数据库密码、hash salt 和 SMTP 密码需要在数据库、Drupal 和邮件服务里轮换。轮换还没有完成，也不能标成完成。对象存储和 AI 密钥是否已在云上轮换，没有验证。

新的成功快照会把这些值放进 `secrets.enc`，并在标 `PASS` 前扫描未加密归档。
