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
- `xedc-push-gmliy` 定时任务里的令牌可能过期，要在新服务器上单独确认。
- Netdata claim token 不进备份，恢复后要重新 claim。

## 合并

快进到 `main` 并已推送，没有单独的合并提交。这次推送的提交是 `fe5d883`。远端没有 `feature/recovery-v3`。
