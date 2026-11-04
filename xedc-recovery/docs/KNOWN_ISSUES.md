# 已知问题

## 公开历史里的凭据

`settings.php`、`config/sync/smtp.settings.yml` 和历史中的 `_server/db.sql` 已经在公开 Git 历史里。标签 `backup-before-migration-v2` 指向 `3f56c01`。不能通过改写历史消除。需要轮换数据库密码、hash salt 和 SMTP 密码。新备份不再把这些值放进未加密的项目包或私有文件包。

## 备份一致性

公共文件、私有文件和 Matomo 数据卷都是在服务运行时复制的。文件数量和数据库事务不是同一个时间点。这是 WARN，不是 PASS 被藏起来。

## 生产机不要启用新 timer

`/root/backup.sh` 仍由 crontab 在 07:00 和 19:00 运行。新的 03:00/15:00 timer 只随代码提供，没有在这台机器 enable。

## 恢复只验证了夹具

Ubuntu 24.04 容器用 PHP 8.5.12、MariaDB、Nginx 和 PHP-FPM 恢复了一份小夹具，并重复跑过一次。夹具不是 Drupal。没有 drush，就没有配置导入、数据库更新、管理员重置和一次性登录。容器里没有 systemd 和 Docker 守护进程，所以 SSL 等待 timer 没有启用，Matomo 容器没有拉起。Netdata、cron、失败回滚和真实 CA 都还没有验收。对账目前只比较少数字段，不能当成与生产站一致。

无 systemd 的机器上，`systemctl` 即使存在也不能用来启动服务。恢复改走 service 或直接启动进程。站点目录回滚已在临时目录核对哈希；Nginx 配置和数据库回滚还没有在运行中的服务上执行。

## 远端已有另一套工具

origin/main 在 `tools/xedc-recover/` 有一套已合并的备份工具，其规格写明新备份不做客户端加密。本任务的 `/root/TASK.md` 要求 age，并且代码在 `xedc-recovery/`。两套代码都保留。不要把远端工具的无加密行为当成 v2 的完成状态。

该工具的测试使用 AWS 文档示例访问键和虚构的 GitHub 令牌。敏感扫描会报这些示例。它们不是本站点的生产凭据。

## 对象存储

R2 清单只证明备份时能列出对象。恢复到另一台机器仍需要密文里的密钥，并且不会把生产密钥写进测试容器。

迁移包上传使用单独的 `/root/.config/xedc-recovery/r2.env`。这台机器没有这份专用凭据。客户端已对 `127.0.0.1` 上的私有 S3 服务验证上传、错误密钥、缺失对象，以及 605176048 字节迁移包的回读哈希。没有向 Cloudflare 或网站公开 Bucket 上传。脚本不会自动删除远端旧对象。

## PHP 8.5

恢复不再在缺少 8.5 时安装 8.4。Ondřej PPA 没有当前发行版时，会再试 `packages.sury.org`，最后才看系统仓库。

2026-11-04 隔离安装：Ubuntu 22.04、24.04、26.04 与 Debian 12、13 为 PHP 8.5.12 CLI 和 FPM。Ubuntu 26.10 使用系统仓库的 PHP 8.5.9。Ubuntu 20.04 的第三方源没有 `php8.5-*`，BLOCKED。Debian 11 在安装 curl 等工具时，安全仓库若干包 404，没有进入 PHP 安装，BLOCKED。日志在 `/var/log/xedc-recovery/php85-matrix/`。
