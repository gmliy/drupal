# 恢复工具测试报告

测试日期：2026-11-06。分支 `fix/r2-tmux-php85`。五个系统一起跑完，不是 `--quick`。宿主机内存只够并发 1，每容器大约 3.9GB。

静态检查：改过的脚本通过 `bash -n` 和 `shellcheck -S error`。

## 测试矩阵

耗时是容器运行时间。Ubuntu 22.04 的 R2，以及 Ubuntu 24.04 的域名和幂等，在第一次全量跑完后按原快照重跑并通过。第一次里 22.04 的 `latest-1` 选中了 MinIO 里上一轮留下的完整备份；24.04 的域名和幂等当时已经打印通过，随后因为测试脚本在容器运行中被改写，bash 在结尾报了引号错误。下表是最终通过的耗时。

| 系统 | 备份 | 恢复 | 域名 | 视频 | 幂等 | 续跑 | 只读自检 | R2 | PHP | 交互 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ubuntu 22.04 | 通过 38s | 通过 355s | 通过 133s | 通过 2s | 通过 224s | 通过 5s | 通过 3s | 通过 136s | 通过 1s | 通过 11s |
| Ubuntu 24.04 | 通过 36s | 通过 353s | 通过 121s | 通过 2s | 通过 187s | 通过 4s | 通过 2s | 通过 115s | 通过 1s | 通过 8s |
| Ubuntu 26.04 | 通过 42s | 通过 272s | 通过 52s | 通过 3s | 通过 92s | 通过 6s | 通过 3s | 通过 59s | 通过 1s | 通过 9s |
| Ubuntu 26.10 | 通过 43s | 通过 267s | 通过 83s | 通过 3s | 通过 148s | 通过 5s | 通过 3s | 通过 88s | 通过 1s | 通过 9s |
| Debian 12 | 通过 29s | 通过 216s | 通过 71s | 通过 2s | 通过 131s | 通过 4s | 通过 2s | 通过 74s | 通过 1s | 通过 8s |

日志在 `xedc-recovery/tests/logs/`。22.04 和 24.04 的主流程日志被后来的 `--from-stage` 清空，上表耗时来自当时的 `status.tsv`。从中途重跑时不再清空这份日志。

## PHP

恢复后 `php -v`、`php-fpm8.5` 和 nginx 的 `fastcgi_pass unix:/run/php/php8.5-fpm.sock` 都是 8.5。页面响应头 `X-PHP-Version` 也是 8.5。没有降级。

| 系统 | 来源 | 版本 |
| --- | --- | --- |
| Ubuntu 22.04 | ppa:ondrej/php | 8.5.12 |
| Ubuntu 24.04 | ppa:ondrej/php | 8.5.12 |
| Ubuntu 26.04 | 系统源 archive.ubuntu.com | 8.5.4-0ubuntu1.5 |
| Ubuntu 26.10 | 系统源 archive.ubuntu.com | 8.5.9-0ubuntu2.1 |
| Debian 12 | packages.sury.org/php | 8.5.12 |

Ubuntu 26.04 和 26.10 的系统源里已经有 8.5，没有再加 Ondřej 源。

## R2 与交互

备份上传使用官方 rclone 1.76.0。仍保留的 26.04、26.10、Debian 12 备份日志里，上传顺序是备份包、`.sha256`、`manifest.json`、`.complete`，然后是 `bootstrap/install.sh` 和 `bootstrap/xedc-recovery.tar.gz`。这些日志里没有 ERROR、NotImplemented 或重试。22.04 重跑的 R2 日志里 `latest-1` 是 `drupal-backup-20200101T000000`。

五个系统的交互测试都包含：没有 TTY 又没有凭据时直接失败并提示环境变量；环境变量凭据得到退出码 0；伪终端输入凭据得到退出码 0。日志里能看到「恢复失败，退出码 1」和「恢复完成，退出码 0」。

## 这轮修过的点

- 安装官方 rclone，装不上再回退系统包并警告。Cloudflare 关掉默认校验和、HEAD 和数据完整性保护，避免第一次上传返回 501 后再重试。
- 工具包固定为 `bootstrap/xedc-recovery.tar.gz` 和 `bootstrap/install.sh`。
- 域名、确认和 R2 凭据在进入 tmux 之前读完。凭据放在权限 600 的临时文件里，会话启动后立即删除。结束时写入真实退出码。
- 按清单安装 PHP 8.5。源里没有该版本就停止，不改用 8.3 或 8.4。`--php` 可以指定别的版本。

## 低内存、配置权限和 Compose

分支 `fix/redis-perms-compose`。下面是 Ubuntu 24.04 快照上的 Docker 结果。容器里的 systemd 不完整，右列没有在真实服务器上核对，不能记成通过。

| 用例 | Docker | 真实服务器还要看 |
| --- | --- | --- |
| 服务用户读取配置 | 通过，3s。`umask 077` 之后 Redis、MariaDB、PHP-FPM、nginx 的配置仍能被对应服务用户读取。故意写成 root 只读时，输出里有 `namei -l` | 真实 Redis 以 `redis` 用户启动，`/etc/redis/xedc.conf` 为 `redis:redis` `0640` |
| 低内存 2GB | 通过，1s。容器内存上限 2048MB 时 MariaDB 缓冲池 256M、PHP-FPM 子进程 3、Redis 96mb，并写入 `/etc/sysctl.d/99-xedc.conf` 的 `vm.overcommit_memory=1` | `sysctl vm.overcommit_memory` 实际是 1。容器里 `sysctl --system` 常常改不了内核 |
| 无 compose 插件 | 通过，10s。没有插件时直接失败，并说明安装 `docker-compose-v2` 或 `docker-compose-plugin`，没有只提示编排文件已还原。装上之后 `docker compose version` 成功。守护进程没起来时同样停止 | 真实机器上 Matomo 启动失败要打印 `docker compose logs` 并停止恢复 |

`--resume` 通过，40s。先把 `/etc/redis/xedc.conf` 收成 root 只读。Redis 日志是 `can't open config file '/etc/redis/xedc.conf': Permission denied`，进程没有起来。修复后从失败的 `services` 继续：日志里跳过了已完成的 database，然后 `开始 services`，结束是 `恢复完成`。配置变成 `redis:redis` `0640`，`PING` 得到 `PONG`。

带 `[Install]` 才 `enable`、没有 `[Install]` 不 `enable`、`xedc-import-worker` 等 worker 在自检通过后才启动，这些要在真实服务器上确认。Docker 里没有可用的 systemd，不能记成通过。这一轮还没有合并。

## 无法在 Docker 里验证

这些没有记成通过。步骤在 `xedc-recovery/tests/real-server-checklist.md`。

- systemd 的 enable、daemon-reload 和开机自启。容器里用 `service` 启动。
- Let's Encrypt 正式证书。测试使用自签证书。
- 真实 Cloudflare R2 上第一次上传不出现 501。这里用的是 MinIO。
- 人手在 SSH 里输入凭据后 `tmux attach -t xedc-restore`，断线再接上。测试用伪终端喂输入。

## 已知限制和手动步骤

- 对象存储里的媒体文件不在备份包内，恢复后继续用原来的私有桶。
- 备份不包含 `.git`。远程地址里如果有令牌，不会被打进备份包。
- `web/sites/default/settings.php` 仍留在历史提交里。`.gitignore` 已忽略它、`.env`、`r2.env` 和 `*.zst`。不要用 `git rm --cached` 去掉已跟踪的 `settings.php`，否则下次在生产目录拉取会删掉正在使用的文件。
- 生产环境当前没有启用 imagick。恢复时会尝试安装，装不上只警告。
- 实验室数据库容器、sing-box、snap、SSM、CUPS、浏览器调试端口不恢复。
- DNS、Cloudflare 记录、邮件 SPF/DKIM、Google 和 Telegram 回调、搜索引擎验证需要人工核对。
- `xedc-push-gmliy` 和其它向 Git 推送的任务不还原。令牌留在旧服务器。
- Netdata claim token 不进备份，恢复后要重新 claim。

## 合并

上一轮快进到 `main` 并已推送，没有单独的合并提交。当时记下的提交是 `fe5d883`，其后报告修订是 `1da59c8`。远端没有 `feature/recovery-v3`。

配置权限、Compose 和 Redis 续跑这一轮在分支 `fix/redis-perms-compose`，还没有合并，也没有推送。
