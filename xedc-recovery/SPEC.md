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

探测要看站点实际用到什么，不能只列已经安装的包。包含 Composer 的 `ext-*`、Node 版本、自定义代码里的外部命令、Redis、对象存储、SMTP、队列、监听端口、cron、systemd、nginx 上传限制、ffmpeg 编码器。`exclude.list` 里的服务、容器、端口、域名和 Composer 包不写入清单。发现报告的「已判定无关」要写明「按 exclude.list 排除」。

Netdata claim token、数据库密码、R2 密钥、SMTP 口令不得进入 Git。

`tests/discovery-report.md` 分成三类：已纳入、疑似相关但未纳入、已判定无关。无法自动恢复的项目写在 `REPORT.md`。

## 3. 备份

`backup.sh` 参数：

- `--target local|r2|both`，默认 `both`
- `--keep-local` 默认 7，`--keep-r2` 默认 30
- `--setup-r2` 交互配置并测试连通，可选每天两次的 cron，日志 `/var/log/xedc-backup.log`，失败可选 Telegram
- `--list` 列出本地和 R2 备份

备份内容：站点代码（排除 `vendor` 和 `node_modules`）、Drush 导出的配置、`settings.php`、数据库（`mysqldump --single-transaction --routines --triggers` 再压成 zstd）、公开和私有文件、nginx/PHP/Redis/MariaDB/cron/systemd/Docker/Netdata/logrotate/ufw/fail2ban/sysctl 以及自定义脚本清单。`exclude.list` 命中的 nginx 站点、compose 项目、数据卷、单元、cron 和脚本不打包。站点仍启用清单里的 Drupal 模块时，日志写「该模块恢复时将被卸载」，不因此中断备份。打进新包之前，从暂存副本里去掉这些模块的配置、Composer 包和模块目录；主题和自定义模块里的硬编码保留。不改旧服务器上的文件。

输出 `tar.zst`，不加密，不用 age 或 gpg。备份前开维护模式，结束或失败都关掉。本地目录 `/var/backups/xedc` 权限 700，文件 600。

R2 使用官方最新稳定版 rclone，装不上才回退系统包并警告。Cloudflare 使用 provider=Cloudflare、region=auto、no_check_bucket、no_head、disable_checksum，不发送 ACL。凭据只放在 `/root/.config/xedc-recovery/r2.env`，权限 600。上传后校验 sha256，失败重试 3 次。桶可被匿名列出时警告。上传顺序是备份包、sha256、manifest，最后 `.complete`。然后再上传 `bootstrap/install.sh` 和 `bootstrap/xedc-recovery.tar.gz`。

## 4. 最新备份

文件名 `drupal-backup-YYYYmmddTHHMMSS.tar.zst`。最新版本按文件名时间戳，不按上传时间。上传顺序是包、sha256、manifest，最后 `.complete`。`latest` 只选择有 `.complete` 的对象。下载后校验，失败则改用上一份完整备份并说明。

## 5. 恢复

`restore.sh` 参数包括 `--from local:/路径`、`--from r2:latest`、`r2:latest-1`、`r2:时间版本`、`--domain`、`--old-domain`、`--www`、`--https auto|self|off`、`--email`、`--php`、`--yes`、`--force`、`--verify`、`--resume`、`--clean-excluded`、`--keep-matomo-module`、`--list-local`、`--list-r2`。`--https self` 只用自签证书，不向 Let's Encrypt 重复签发。`--matomo-domain` 不再恢复 Matomo，传入时只警告并忽略。

没有 `--force` 时，发现已有站点就停止。有 `--force` 时先备份旧数据库。`--verify` 只读，不改系统。`--resume` 从失败步骤继续，不重复已成功的步骤。还原前比较配置哈希，和上次脚本写入的不同就保留当前文件并提示，不覆盖手动修改。`--clean-excluded` 默认只打印残留；加上 `--yes` 才执行 `compose down -v`、删除 nginx 配置和停用单元。`--keep-matomo-module` 保留 Drupal 模块，Matomo 服务仍然不恢复。

域名、安装确认和 R2 凭据都在进入 tmux 之前于前台终端读完。没有 TTY 又缺参数时直接报错，并提示用命令参数或环境变量。凭据经权限 600 的临时文件交给会话，会话启动后立即删除，不进入日志、命令行和进程列表。随后自动 `tmux attach -t xedc-restore`。日志 `/var/log/xedc-restore.log`，权限 600，用 `cat` 或 `tail -f` 查看。会话结束写入真实退出码。失败时提示日志路径和 `--resume`。新服务器不需要解密口令。

然后安装软件、建库、导入、还原代码。`composer install` 前先 `composer check-platform-reqs`，不兼容就列出包并停止，不忽略平台检查。数据库导入和代码还原之后、`drush updb` 与 `cim` 之前，默认卸载 `exclude.list` 里的 Drupal 模块：删掉 `config/sync` 里的对应配置和 `core.extension.yml` 条目，删掉引用它们的 block、views 和菜单，`drush pm:uninstall`，`drush config:delete`，清掉相关 state 和 key_value，再 `composer remove` 存在的包。然后 `updb`、`cim`、`cr`，并确认模块仍未启用。被其他模块依赖时列出依赖，卸载失败写入失败项，不中断整个恢复。配置和数据库里的跟踪脚本、站点 ID、matomo 域名自动清理。主题和自定义模块里的硬编码只报告文件和行号。`--keep-matomo-module` 跳过这一步。接着构建主题、改 `settings.php`、还原服务、处理域名和 HTTPS、自检。自检通过后再启动名字里带 worker 的单元，例如 `xedc-import-worker`。首页 HTML 不得出现 matomo 跟踪脚本，也不得请求 matomo 域名。

还原服务时的顺序是：复制备份里的 nginx（跳过排除清单）、证书基础文件、把旧域名换成新域名、写主站配置、补上缺失证书、`nginx -t`、启动 Docker、还原卷、再启动未被排除的容器。旧备份里的 Matomo 文件也跳过：不还原 `matomo.conf`，不 `compose up`，不建网络和数据卷，不为它的子域名申请证书。`--resume` 会再次从备份复制配置；若文件哈希与上次脚本写入的不同，则保留手动修改。域名变更后不得留着旧域名。

写入或启用 nginx 站点之前，先安装 `certbot` 和 `python3-certbot-nginx`，并保证 `/etc/letsencrypt/options-ssl-nginx.conf` 与 `ssl-dhparams.pem` 存在。插件包里没有时，用模板写入等价文件。`nginx -t` 之前扫描 `include`、`ssl_certificate`、`ssl_certificate_key`、`ssl_dhparam`。证书不存在就在引用路径写临时自签证书，报告「使用自签证书，待申请正式证书」。DNS 已指向本机公网 IP 时，用 `certbot certonly --webroot` 替换，失败不中断恢复。已有有效正式证书则不重复签发。非主站配置单独检测，有问题的移到 `/etc/nginx/disabled/` 并列入失败项，主站通过后继续。`--https self` 不申请正式证书。`nginx -t` 仍失败时输出完整报错和配置行号，并说明已经尝试的自动修复。

服务专用配置不沿用旧服务器上可能错误的权限，由脚本显式设置：Redis 配置 `redis:redis` `0640`，目录 `0755`；MariaDB `mysql:mysql` `0640`；PHP-FPM 与 nginx `root:www-data` `0640`；Netdata `root:netdata` `0640`。其他 `/etc` 文件使用清单记录值，没有记录时 `root:root` `0644`。全局 umask 为 `022`。`settings.php` 和 `.env` 单独设为 `0640` 或 `0600`，属组让 `www-data` 可读。每个服务启动前执行 `sudo -u <用户> test -r <文件>`，失败则输出 `namei -l`，自动修复一次再试。服务启动失败时输出 `systemctl status` 和 `journalctl -xeu <服务> -n 30`。

Redis 把 `vm.overcommit_memory=1` 写入 `/etc/sysctl.d/99-xedc.conf` 并执行 `sysctl --system`。

安装 Docker 时同时安装 Compose，并且在启动任何容器之前完成。Ubuntu 用 `docker-compose-v2`，Docker 官方源用 `docker-compose-plugin`。`docker compose version` 失败就停止。容器启动失败要输出容器日志和原因，列入失败项。Netdata 和其他非核心服务失败不中断主站恢复，最终报告醒目列出。

systemd 单元还原后，只对含 `[Install]` 的单元执行 enable。没有这一段的不 enable，由 timer 或其他单元触发。`exclude.list` 中的单元，以及内容是向 Git 推送的任务，不还原。原因写在发现报告里。

`xedc-recovery/exclude.list` 一行一条规则，可写注释。首批是 `matomo` 和 `xedc-push-gmliy`。它同时作用于备份、恢复和探测。以后要排除别的服务，只改这个文件。

手动卸载已恢复站点上的模块：

```bash
drush pm:uninstall matomo matomo_reports -y
drush config:delete matomo.settings -y
composer remove drupal/matomo drupal/matomo_reports
drush cr
```

## 6. 软件模块

`lib/` 下每个软件一个文件，包含安装、配置、启用、验证，并且可以单独执行，例如 `bash lib/mariadb.sh install`。已安装则跳过，版本不符则升级并提示。配置来自 `templates/` 和清单。内存从 1GB 到 8GB 以上分别调整 MariaDB、PHP-FPM 和 Redis。

## 7. 域名

域名不变则原样恢复，同时仍会删掉排除清单命中的站点配置。变更时改 nginx 的 `server_name` 和证书路径，包括 `/etc/nginx/conf.d/`、`sites-available`、`sites-enabled`。排除清单里的站点直接删除，不为它们换域名或申请证书。裸域与 www 互相跳转。80 端口要能提供 `/.well-known/acme-challenge/`。按 DNS 决定正式证书或自签。Cloudflare 代理时提示使用 Full (strict)，橙色云时 HTTP 验证到不了源站。

Drupal 的 trusted host、base_url、cookie domain 要改。配置和数据库里的旧域名先统计再替换，序列化数据按序列化规则改，替换前先备份数据库。主题和模块里写死的旧域名只报告，不改代码。

## 8. 引导

`install.sh` 不含密钥。先装 git、curl、tmux、官方最新 rclone，再取工具，最后执行 `restore.sh`，参数原样传递。下载后打印 `VERSION` 里的版本号和构建时间，并记下来源是 GitHub 还是 R2。`restore.sh` 启动时打印版本。若 R2 bootstrap 的构建时间早于 GitHub main，警告并提示重新备份或改用 GitHub。`backup.sh` 每次用当前仓库重新打包 `bootstrap/xedc-recovery.tar.gz`。`xedc-recovery/VERSION` 在提交钩子里自动更新。

取值顺序：有 `GH_TOKEN` 则从 GitHub API 下载 `gmliy/drupal` 的 `xedc-recovery`；否则有 R2 凭据则下载 `bootstrap/`；都没有则提示输入 `GH_TOKEN`。

## 9. 自检

首页、`/user/login`、`/admin`、`/models`、`/articles`、`/sitemap.xml` 返回 200。未使用 `--keep-matomo-module` 时，首页不得出现 matomo 跟踪脚本或对 matomo 域名的请求。同时看 Drush、数据库、Redis、PHP-FPM、cron、ffmpeg、Docker。输出用 ✔、✘ 和警告，列出失败项、已卸载模块、删除的配置数量、未改动的硬编码位置，以及需要手工做的事。

## 10. 测试

先做 `bash -n` 和 shellcheck，有 error 级问题就不要启动容器。`tests/run-all.sh` 默认同时跑五个系统。内存不够就降低并发。`--quick` 只跑 Ubuntu 24.04 和 26.04，合并前必须跑满五个系统。

基础镜像只预装 curl 和证书。nginx、PHP、MariaDB 必须在恢复流程里真正安装。用小型夹具数据。MinIO 模拟 R2。某个系统失败不影响其他系统。

主流程通过后做快照，再并行跑域名替换、视频、连续恢复两次、`--resume`、`--verify`、R2 的 latest 和 latest-1。缺 `.complete` 的备份必须被跳过。

Docker 里做不到的项目单独列出，不能写成通过。提供 `tests/real-server-checklist.md` 和 `tests/cleanup.sh`。

另外要覆盖：以 `umask 077` 还原后服务用户仍能读取配置，故意改成 `root:root` `0600` 会自动修好并重试；大约 2GB 内存的参数；没有 Compose 插件时自动安装并通过 `docker compose version`；缺 `options-ssl-nginx.conf` 和证书时 `nginx -t` 仍能通过，写错的非主站配置被移到 `disabled/`；`xedc-push-gmliy` 不还原，没有 `[Install]` 的单元不 enable；旧备份里的 Matomo 服务和模块在恢复后都不存在，新备份包的清单、nginx、compose、单元和 Composer 要求里没有 Matomo；`--clean-excluded`、`--keep-matomo-module` 和被自定义模块依赖时继续恢复各测一次；`--resume` 从 Redis 或 nginx 失败处继续，且不覆盖手动修改。容器里的 systemd 不完整，真实证书、真实 Cloudflare R2 和真人 tmux 交互记在报告的真实服务器核对，不能写成 Docker 已通过。

## 11. 文档

仓库根目录 `README.md` 用中文。开头一屏写完备份和一条命令恢复。命令都放在代码块里。

## 12. 交付

`xedc-recovery/` 含 `install.sh`、`backup.sh`、`restore.sh`、`lib/`、`templates/`、`tests/`。根目录 `README.md`。`xedc-recovery/REPORT.md`。`tests/discovery-report.md`。

## 13. 安全

不得把密码、密钥、R2 凭据、备份包、`settings.php`、`.env` 提交到 Git。覆盖数据库或删除目录需要 `--force`，并且先备份旧数据。

## 14. 合并

全部测试没有失败项、工作区干净、已经跑过五个系统而不是只跑 `--quick` 之后，才可以合并到默认分支并推送。优先快进。有冲突就先变基再合并，不丢弃已有文件。推送后确认 `README.md` 和 `xedc-recovery/` 在远端，并确认默认分支上的 `install.sh` 原始地址可以访问。然后删除远端 `feature/recovery-v3`，把合并提交哈希写进 `REPORT.md`。有失败则停在分支上，不合并。
