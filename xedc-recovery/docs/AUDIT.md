# 审计

审计只读，没有修改线上 Nginx、PHP、数据库、`/root/backup.sh` 或站点文件。下面的结论来自当时的命令输出和备份自检，不记录密钥、邮箱、内网地址或对象存储 endpoint。

## 系统

- Ubuntu 24.04.4 LTS，内核 7.0.0-1014-aws，amd64，AWS 虚拟机。
- 内存约 7.6GB。备份前可用内存约 4.2GB，根分区剩余约 29GB。
- 已在监听的站点相关端口：80、443、本机 3306、6379、8088、19999。
- 本机 13306 上有一个未纳入站点的数据库端口。对应数据卷 `xedc-recover-db-data` 不导出、不停止。
- Nginx、PHP 8.5-FPM、MariaDB、Redis、Docker、Netdata、`xedc-import-worker` 均为 active。
- 防火墙未作为站点依赖处理。没有发现 fail2ban。
- root crontab 有两条站点相关任务：每天 07:00 和 19:00 执行 `/root/backup.sh`；每 15 分钟执行 Drush cron。新备份 timer 不在这台机器上启用，避免和现有任务抢资源。

## Drupal 与运行时

- Drupal core 与 core-recommended 均为 11.4.11。核心要求 PHP >= 8.3。
- 站点 PHP 是 `/usr/bin/php8.5` 8.5.12。当前 shell 里的 `node` 可能指向 Cursor 自带的 Node 24，站点 Node 是 `/usr/bin/node` v20.20.2。
- Nginx 1.31.6，站点配置在 `/etc/nginx/conf.d/drupal.conf`，Matomo 反代在 `matomo.conf`。
- MariaDB 13.0.2。快照中的库为 utf8mb4，228 张表全部 InnoDB，存储过程 0，触发器 0。root 密码不写入 manifest。
- Redis 8.10.3，只监听本机。
- 公开路由以现有 Nginx 和 Drupal 为准，包括首页、`/articles`、`/video`、`/meitu`、`/models`、`/user/login`、`/sitemap.xml`。`/admin` 未登录时应跳转，不能把 302 当成故障。

## 文件与对象存储

- 公共文件 8951 个，833985760 字节，打进 `files-public.tar.zst`。
- 私有目录另有对象存储密钥、AI 密钥和一份历史 `settings.php`。这些文件进入 age 密文，不进入未加密的私有文件包。
- 计入未加密私有包的文件是 319 个，147920426 字节。
- 对象存储正在使用，模式为 `cloudflare_r2`。只读清点结果：`public/` 765 个对象，`private/` 0 个对象。清单状态 PASS。endpoint 只留在仓库外的 manifest 里。

## 站点配套与媒体

- Matomo 由 Docker Compose 提供，Nginx 反代到本机 8088。数据卷 `matomo_db-data`、`matomo_matomo-app-data` 在容器运行时做文件级复制，不是事务快照。
- Netdata 监听本机 19999，没有经过站点 Nginx。
- `xedc-import-worker.service` 是站点队列服务，已纳入服务配置快照。
- ffmpeg 6.1.1-3ubuntu5，编码器包含 libx264、libx265、libvpx、libvpx-vp9、aac、libopus。没有 ImageMagick，也没有 yt-dlp。
- 播放器是主题里的 Plyr 和 hls.js，随项目源码归档。
- 源站证书工具是 Certbot。恢复改用固定版本的 acme.sh 3.1.6，不把旧证书私钥带去新机器。

## 仓库里已经存在的敏感历史

公开仓库的历史提交里已经有 `_server/db.sql`，跟踪的 `settings.php` 含数据库密码和 hash salt，`config/sync/smtp.settings.yml` 含 SMTP 密码。标签 `backup-before-migration-v2` 指向 `3f56c01`，与当前未推送的 main 祖先相同。本任务不改写历史。这些凭据应视为已经暴露，需要轮换。新代码的 `.gitignore` 只能阻止以后再加入同类文件，不能把已跟踪的 `db.sql` 变成未跟踪。

## 备份证据

仓库外快照：`/var/backups/xedc/20261104T061746Z`，状态 PASS。

自检通过的项目：SHA256、zstd、SQL 以 Dump completed 结束、manifest schema 2、公共文件数量不少于扫描值、`composer.lock` 与站点一致。另一次独立检查确认：项目归档中的 `settings.php` 和 `smtp.settings.yml` 已替换为占位符；未加密私有包不含数据库密码、hash salt、SMTP 密码、对象存储密钥和 AI 密钥；这些敏感文件只出现在 `secrets.enc` 解密后的内容里。age 私钥在 `/root/.config/xedc-recovery/age.key`，权限 600，不在快照目录中。

此前两份快照因为未加密包里混入了 `settings.php` 副本或 AI 密钥，已标记失效并删除。线上服务没有因此重启。
