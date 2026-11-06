# 备份与恢复规格

本文件是 `xedc-recovery/` 的验收标准。实现和测试与这里冲突时，以本文件为准。

## 1. 环境

- 站点是 Drupal 11，目录自动探测，当前机器是 `/var/www/drupal`。
- 恢复目标是 Ubuntu 22.04、24.04、26.04、26.10 和 Debian 12 的空服务器，用 root 运行。
- 目标 PHP 以 manifest.json 的 `php.minor` 为准，缺省 8.5。可用 `--php 8.4` 覆盖。Ubuntu 22.04/24.04 使用 ppa:ondrej/php，Debian 12 使用 packages.sury.org/php。Ubuntu 26.04 及以上先看系统源，没有目标版本才加 Ondřej 源。源里没有该版本就停止，不降级。php 命令、PHP-FPM 套接字、nginx、cron 和 systemd 都用这个版本。
- 可以重复执行。路径和版本来自探测，不写死某台机器的口令或绝对版本号。
- 命令一行可以复制，输出短。

## 2. 发现

`manifest.json` 是恢复时的事实来源。`backup.sh` 每次重新探测，并和上一份清单对比，发现新的服务、软件或定时任务时写进日志。

探测要看站点实际用到什么，不能只列已经安装的包。包含 Composer 的 `ext-*`、Node 版本、自定义代码里的外部命令、Redis、对象存储、SMTP、Matomo、队列、监听端口、cron、systemd、nginx 上传限制、ffmpeg 编码器。

Netdata claim token、数据库密码、R2 密钥、SMTP 口令不得进入 Git。

`tests/discovery-report.md` 分成三类：已纳入、疑似相关但未纳入、已判定无关。无法自动恢复的项目写在 `REPORT.md`。

## 3. 备份

`backup.sh` 参数：

- `--target local|r2|both`，默认 `both`
- `--keep-local` 默认 7，`--keep-r2` 默认 30
- `--setup-r2` 交互配置并测试连通，可选每天两次的 cron，日志 `/var/log/xedc-backup.log`，失败可选 Telegram
- `--list` 列出本地和 R2 备份

备份内容：站点代码（排除 `vendor` 和 `node_modules`）、Drush 导出的配置、`settings.php`、数据库（`mysqldump --single-transaction --routines --triggers` 再压成 zstd）、公开和私有文件、nginx/PHP/Redis/MariaDB/cron/systemd/Docker/Netdata/logrotate/ufw/fail2ban/sysctl 以及自定义脚本清单。

输出 `tar.zst`，不加密，不用 age 或 gpg。备份前开维护模式，结束或失败都关掉。本地目录 `/var/backups/xedc` 权限 700，文件 600。

R2 使用官方最新稳定版 rclone，装不上才回退系统包并警告。Cloudflare 使用 provider=Cloudflare、region=auto、no_check_bucket、no_head、disable_checksum，不发送 ACL。凭据只放在 `/root/.config/xedc-recovery/r2.env`，权限 600。上传后校验 sha256，失败重试 3 次。桶可被匿名列出时警告。上传顺序是备份包、sha256、manifest，最后 `.complete`。然后再上传 `bootstrap/install.sh` 和 `bootstrap/xedc-recovery.tar.gz`。

## 4. 最新备份

文件名 `drupal-backup-YYYYmmddTHHMMSS.tar.zst`。最新版本按文件名时间戳，不按上传时间。上传顺序是包、sha256、manifest，最后 `.complete`。`latest` 只选择有 `.complete` 的对象。下载后校验，失败则改用上一份完整备份并说明。

## 5. 恢复

`restore.sh` 参数包括 `--from local:/路径`、`--from r2:latest`、`r2:latest-1`、`r2:时间版本`、`--domain`、`--old-domain`、`--www`、`--https auto|self|off`、`--email`、`--yes`、`--force`、`--verify`、`--resume`、`--list-local`、`--list-r2`。

没有 `--force` 时，发现已有站点就停止。有 `--force` 时先备份旧数据库。`--verify` 只读，不改系统。`--resume` 从失败步骤继续。

域名、安装确认和 R2 凭据都在进入 tmux 之前于前台终端读完。没有 TTY 又缺参数时直接报错，并提示用命令参数或环境变量。凭据经权限 600 的临时文件交给会话，会话启动后立即删除，不进入日志、命令行和进程列表。随后自动 `tmux attach -t xedc-restore`。日志 `/var/log/xedc-restore.log`，权限 600，用 `cat` 或 `tail -f` 查看。会话结束写入真实退出码。失败时提示日志路径和 `--resume`。新服务器不需要解密口令。

然后安装软件、建库、导入、还原代码、`composer install --no-dev`、构建主题、改 `settings.php`、还原服务、`drush updb`、`cim`、`cr`、处理域名和 HTTPS、自检。

## 6. 软件模块

`lib/` 下每个软件一个文件，包含安装、配置、启用、验证，并且可以单独执行，例如 `bash lib/mariadb.sh install`。已安装则跳过，版本不符则升级并提示。配置来自 `templates/` 和清单。内存从 1GB 到 8GB 以上分别调整 MariaDB、PHP-FPM 和 Redis。

## 7. 域名

域名不变则原样恢复。变更时改 nginx 名称和证书路径，裸域与 www 互相跳转，按 DNS 决定正式证书或自签。Cloudflare 代理时提示使用 Full (strict)。

Drupal 的 trusted host、base_url、cookie domain 要改。配置和数据库里的旧域名先统计再替换，序列化数据按序列化规则改，替换前先备份数据库。主题和模块里写死的旧域名只报告，不改代码。

## 8. 引导

`install.sh` 不含密钥。先装 git、curl、tmux、rclone，再取工具，最后执行 `restore.sh`，参数原样传递。

取值顺序：有 `GH_TOKEN` 则从 GitHub API 下载 `gmliy/drupal` 的 `xedc-recovery`；否则有 R2 凭据则下载 `bootstrap/`；都没有则提示输入 `GH_TOKEN`。

## 9. 自检

首页、`/user/login`、`/admin`、`/models`、`/articles`、`/sitemap.xml` 返回 200。同时看 Drush、数据库、Redis、PHP-FPM、cron、ffmpeg、Docker。输出用 ✔、✘ 和警告，并列出需要手工做的事。

## 10. 测试

先做 `bash -n` 和 shellcheck，有 error 级问题就不要启动容器。`tests/run-all.sh` 默认同时跑五个系统。内存不够就降低并发。`--quick` 只跑 Ubuntu 24.04 和 26.04，合并前必须跑满五个系统。

基础镜像只预装 curl 和证书。nginx、PHP、MariaDB 必须在恢复流程里真正安装。用小型夹具数据。MinIO 模拟 R2。某个系统失败不影响其他系统。

主流程通过后做快照，再并行跑域名替换、视频、连续恢复两次、`--resume`、`--verify`、R2 的 latest 和 latest-1。缺 `.complete` 的备份必须被跳过。

Docker 里做不到的项目单独列出，不能写成通过。提供 `tests/real-server-checklist.md` 和 `tests/cleanup.sh`。

## 11. 文档

仓库根目录 `README.md` 用中文。开头一屏写完备份和一条命令恢复。命令都放在代码块里。

## 12. 交付

`xedc-recovery/` 含 `install.sh`、`backup.sh`、`restore.sh`、`lib/`、`templates/`、`tests/`。根目录 `README.md`。`xedc-recovery/REPORT.md`。`tests/discovery-report.md`。

## 13. 安全

不得把密码、密钥、R2 凭据、备份包、`settings.php`、`.env` 提交到 Git。覆盖数据库或删除目录需要 `--force`，并且先备份旧数据。

## 14. 合并

全部测试没有失败项、工作区干净、已经跑过五个系统而不是只跑 `--quick` 之后，才可以合并到默认分支并推送。优先快进。有冲突就先变基再合并，不丢弃已有文件。推送后确认 `README.md` 和 `xedc-recovery/` 在远端，并确认默认分支上的 `install.sh` 原始地址可以访问。然后删除远端 `feature/recovery-v3`，把合并提交哈希写进 `REPORT.md`。有失败则停在分支上，不合并。
