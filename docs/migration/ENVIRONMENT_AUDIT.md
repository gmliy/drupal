# 环境审计

审计时间：2026-11-03。方式是只读命令，没有重启服务，没有修改生产配置。密钥、数据库密码和 hash salt 只记录“是否存在”，不记录值。

## 操作系统

| 项目 | 实际值 |
| --- | --- |
| 系统 | Ubuntu 24.04.4 LTS (noble) |
| 内核 | 7.0.0-1014-aws，x86_64 |
| CPU | AMD EPYC 7R13，4 线程 |
| 内存 | 7.6 GiB，审计时可用约 4.4 GiB |
| Swap | 2 GiB 文件 |
| 磁盘 | 根分区 58G，已用约 21G，可用约 37G，ext4 |
| 负载 | 审计时约 0.7 |

监听端口：80、443（Nginx）、22（sshd）、127.0.0.1:3306（MariaDB）、127.0.0.1:6379（Redis）、127.0.0.1:8088（Matomo）、127.0.0.1:19999（Netdata）。

## Drupal 运行环境

| 项目 | 实际值 |
| --- | --- |
| Drupal | 11.4.11 |
| PHP CLI / FPM | 8.5.12，来自 ondrej/php |
| Nginx | 1.31.6，来自 nginx.org mainline |
| MariaDB | 13.0.2，库名 `drupal`，字符集 utf8mb4 / utf8mb4_general_ci，约 150 MB，226 张表 |
| Composer | 2.10.3 |
| Drush | 13.8.0 |
| Node | 系统 node 包是 20.20.2。当前 PATH 里的 `node` 来自 Cursor Agent，是 24.22.1。主题构建需要 Node 18 以上，运行站点不依赖它 |
| npm | 10.8.2 |
| Redis | 8.10.3，仅监听本机，作为缓存 |
| OpenSSL | 3.0.13 |
| Python | 3.12.3，cryptography 41.0.7 |

PHP 扩展：apcu、bcmath、curl、gd、igbinary、intl、mbstring、mysqli、pdo_mysql、redis、zip、xml、opcache 等。站点实际使用其中的数据库、gd、redis、curl、mbstring、xml、zip。

启用的自定义模块：`xa_views`、`xedc_admin_nav`、`xedc_admin_ui`、`xedc_ai`、`xedc_audit`、`xedc_auto_translate`、`xedc_core`、`xedc_home`、`xedc_mail`、`xedc_member`、`xedc_models`、`xedc_moderation`、`xedc_quick`、`xedc_scheduler`、`xedc_site_audit`、`xedc_tags`、`xedc_ui_locale`。

主题：默认 `xedc`，管理主题 `xedc_admin`。

路径：

- 代码：`/var/www/drupal`
- Web 根：`/var/www/drupal/web`
- public files：`web/sites/default/files`，约 806 MB
- private files：`/var/www/drupal/private`，约 96 MB
- `file_managed`：public 183 条，private 12 条
- trusted host：`gitxx.xyz`、`www.gitxx.xyz`
- 对象存储运行状态：`public_mode=cloudflare_r2`，CDN `https://cdn.gitxx.xyz`。凭据在 private 目录，审计没有读取

数据库账号和 hash salt 存在于 `settings.php`。该文件已经被 Git 跟踪，这是既有情况，本次没有修改它。

## 服务分类

| 类别 | 服务 |
| --- | --- |
| Drupal 必需 | Nginx、PHP 8.5-FPM、MariaDB、public/private 文件、自定义模块和主题 |
| Drupal 增强 | Redis 缓存、ffmpeg、ffprobe、7z、Certbot、Composer、Drush、s3fs/R2 |
| 服务器运维 | ssh、cron、unattended-upgrades、chrony、netdata、旧的 `/root/backup.sh` |
| 与 Drupal 恢复无关 | Matomo（`x.gitxx.xyz`，独立 Docker 网络和卷）、Netdata、CUPS、fwupd、Amazon SSM |

Matomo 使用自己的 `matomo:5-apache` 和 `mariadb:lts` 容器，数据库卷是 `matomo_db-data`。它不是 Drupal 恢复的一部分，本次没有连接或修改它。

定时任务：root crontab 在 07:00 和 19:00（上海时间）运行 `/root/backup.sh`，每 15 分钟运行 Drush cron。`/etc/cron.d` 另有 certbot、php、xedc-traffic。这些都没有被修改。

`/root/backup.sh` 会在仓库里 `git add -A` 并推到 `main`。因此本项目的代码放在独立 worktree `/opt/xedc-migration` 的分支 `feature/migration-v2`，避免被下一次旧备份提交进主分支。
