# 迁移包交付说明

说明范围是在已有 `xedc-recovery` 上补齐的交付流程：自动打包、本地下载、R2 传输、新服务器恢复，以及 PHP 8.5 限制。依据是本轮代码、仓库外快照和隔离测试日志。没有写入密钥、邮箱、内网地址或对象存储 endpoint。

没有创建 `/var/www/drupal/.MIGRATION_DONE`。本轮改动还在工作区，没有提交。

## 结论

`backup.sh` 在分项快照成功后会生成独立迁移包。当前这份已经生成并校验。新服务器的恢复入口只接受 PHP 8.5，不会改用 8.4。真实 Cloudflare 账户没有配置，因此没有向 Cloudflare 上传。

## 迁移包

| 项目 | 值 |
| --- | --- |
| 快照 | `/var/backups/xedc/20261104T082520Z`，`STATUS=PASS`，打包后仍保留 |
| 迁移包 | `/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst` |
| 校验文件 | `/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst.sha256` |
| 大小 | 605176048 字节 |
| SHA-256 | `96149968b29fe370dfc4ee15e6ca404f33cc7a504f22e4f32a14b85f8ec08cc5` |
| 校验 | `sha256sum -c` 为 OK |

打包命令是：

```bash
sudo xedc-recovery/backup.sh --package-snapshot /var/backups/xedc/20261104T082520Z
```

正常备份不再需要这个参数。`xedc_backup_main` 在自检通过后调用同一个 `xedc_migration_package`。这次没有重新导出生产库。打包失败会让命令失败，并删除未通过校验的半成品压缩包，不删除原来的分项快照。

解压后的目录名是 `xedc-migration-20261104T082520Z`。里面有快照、`restore.sh`、`xedc-recovery/download.sh`、恢复所需的 `lib/`、`恢复说明.md`、`VERSIONS.txt` 和数据库与 Nginx/PHP 模板说明。`age.key` 和 `r2.env` 不在包内。随包上传用的 manifest 去掉了端点、桶名和密钥字段。

## 下载和上传

查看：

```bash
sudo ls -lh /var/backups/xedc/xedc-migration-*.tar.zst
sudo sha256sum /var/backups/xedc/xedc-migration-*.tar.zst
```

Linux 或 macOS 下载到自己的电脑。地址换成自己的服务器：

```bash
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst .
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst.sha256 .
sha256sum -c xedc-migration-20261104T082520Z.tar.zst.sha256
```

Windows PowerShell：

```powershell
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst .
scp root@203.0.113.10:/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst.sha256 .
Get-FileHash .\xedc-migration-20261104T082520Z.tar.zst -Algorithm SHA256
```

原服务器直接传到新服务器，或从自己的电脑再传一次，目标都是新服务器的 `/root/`。新服务器上先安装 `zstd` 再解压：

```bash
cd /root
sha256sum -c xedc-migration-20261104T082520Z.tar.zst.sha256
sudo apt-get update
sudo apt-get install -y zstd
tar --zstd -xf xedc-migration-20261104T082520Z.tar.zst
cd xedc-migration-20261104T082520Z
sudo ./restore.sh --mode=full --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

age 私钥单独放到 `/root/.config/xedc-recovery/age.key`，权限 600。

隔离 SSH 容器收到了这份完整包，容器内 `sha256sum -c` 为 OK。没有 `zstd` 时 `tar --zstd` 不能解压，所以说明里要求先安装它。

## Cloudflare R2

配置文件是 `/root/.config/xedc-recovery/r2.env`，权限必须是 600。模板在 `xedc-recovery/contrib/r2.env.example`。Bucket 必须是专用私有桶，不要使用网站公开文件的桶，也不要打开公开访问。Token 只授予该 Bucket 的对象读和对象写。

```bash
sudo xedc-recovery/backup.sh --upload-r2
sudo xedc-recovery/download.sh --source=r2 --list
sudo xedc-recovery/download.sh --source=r2 --backup=20261104T082520Z --output /root
```

远端对象键是 `xedc-backups/年/月/xedc-migration-时间.tar.zst`，同时上传 `.sha256` 和脱敏 manifest。上传后核对远端大小，并下载回来比对 SHA-256。失败退出码非零。脚本不删除远端旧对象，也不上传 age 私钥。

这台机器没有这份专用配置。客户端对 `127.0.0.1` 上的私有 S3 服务完成了小对象上传、回读、错误密钥、缺失对象，以及上述 605176048 字节迁移包的回读。回读哈希与本地一致。没有向 Cloudflare 上传。

## 恢复和 PHP 8.5

`--mode=full` 面向只有基础系统的新服务器，会安装 Nginx、MariaDB、Composer、ffmpeg、Docker 和 PHP 8.5，然后恢复站点。`--mode=restore` 发现已有网站目录或已有数据表就停止，不覆盖。

Drupal 只使用 PHP 8.5。没有软件包时写入 `/var/lib/xedc-recovery/PHP_STATUS` 并停止，不导入数据库，不把 Nginx 指到其他版本。`--php-version 8.4` 在安装前被拒绝。Ondřej PPA 没有当前发行版时，再试 `packages.sury.org`，最后才看系统仓库。

Ubuntu 24.04 夹具日志 `/var/log/xedc-recovery/fixture-full-20261104.log` 记录 `8.3.6 → 8.5 → 8.5.12`，`FIXTURE_EXIT:0`。首页 200，`/admin` 302。`--mode=restore` 因目录已存在而停止。夹具不是 Drupal，没有 Drush。

PHP 8.5 安装矩阵见 `/var/log/xedc-recovery/php85-matrix/`：

| 系统 | 结果 |
| --- | --- |
| Ubuntu 22.04、24.04、26.04 | PASS，PHP 8.5.12 CLI 和 FPM |
| Ubuntu 26.10 | PASS，系统仓库 PHP 8.5.9 CLI 和 FPM |
| Debian 12、13 | PASS，PHP 8.5.12 CLI 和 FPM |
| Ubuntu 20.04 | BLOCKED，Ondřej focal 源没有 `php8.5` 包 |
| Debian 11 | BLOCKED，安装 curl 时安全仓库返回 404，没有进入 PHP 安装 |

这些 PASS 只表示该发行版能装上 PHP 8.5。除 Ubuntu 24.04 夹具外，没有在每个系统上做完整站点恢复。

## 没有写成通过的项目

- 真实 Cloudflare R2 上传和下载。
- 正式 CA 证书。夹具走的是自签名等待模式。
- 生产 Drupal 的 `composer install`、Drush、配置导入和数据库更新。
- Matomo 进程和 Netdata 进程。快照里有脱敏 compose 和 Netdata 配置，夹具只恢复了模板。
- 运行中的数据库、Nginx 和 PHP-FPM 回滚。
- Docker A 级其余场景、B 级完整恢复和连续两轮。

错误 age 私钥会在解密 `secrets.enc` 时失败。损坏的迁移包不能通过 SHA-256 或解压检查。这两项用的是测试夹具，不是生产密钥。

生产机的 `settings.php` 和 `/root/backup.sh` 没有被本轮改写。线上 Nginx、PHP-FPM、数据库和 Docker 容器没有为这些测试重启。
