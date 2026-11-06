# 恢复工具测试报告

测试日期：2026-11-06。分支 `feature/recovery-v3`。完整五系统一起跑过，不是 `--quick`。Ubuntu 26.10 在补上 MariaDB 套接字检测后单独重跑，域名替换已通过。

静态检查：全部 `.sh` 通过 `bash -n` 和 `shellcheck -S error`。

## 测试矩阵

状态只有通过。耗时来自各阶段容器运行时间。

| 系统 | 备份 | 恢复 | 域名 | 视频 | 幂等 | 续跑 | 只读自检 | R2 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ubuntu 22.04 | 通过 29s | 通过 277s | 通过 95s | 通过 2s | 通过 233s | 通过 5s | 通过 3s | 通过 90s |
| Ubuntu 24.04 | 通过 34s | 通过 280s | 通过 83s | 通过 2s | 通过 164s | 通过 5s | 通过 3s | 通过 127s |
| Ubuntu 26.04 | 通过 35s | 通过 302s | 通过 86s | 通过 4s | 通过 204s | 通过 7s | 通过 3s | 通过 109s |
| Ubuntu 26.10 | 通过 33s | 通过 293s | 通过 101s | 通过 2s | 通过 187s | 通过 6s | 通过 3s | 通过 103s |
| Debian 12 | 通过 23s | 通过 208s | 通过 63s | 通过 2s | 通过 114s | 通过 5s | 通过 3s | 通过 58s |

日志在 `xedc-recovery/tests/logs/`。主流程是 `<系统>.log`，分支是 `<系统>-<阶段>.log`。

并发按内存自动降到 1。每容器大约 3.6GB，且内存和交换上限相同，避免把宿主机交换打满。

## 这轮修过的点

- Ubuntu 26.04 的 Ondřej PHP 源没有 Release 文件。源加不上去时删掉该源，改用系统里的 PHP 8.5。
- Ubuntu 26.10 的客户端是 `mariadb` / `mariadb-dump`，不再假定一定有 `mysql`。
- Ubuntu 26.10 域名替换改为连接实际的 MariaDB 套接字，并写进 PHP 的 `pdo_mysql.default_socket`。
- 备份包里的 manifest 成员是 `./manifest.json`。恢复和 `r2:latest-1` 都按这个路径读取。
- 系统没有 Netdata 软件包时给出警告并继续恢复。Ubuntu 26.04 的软件源里没有这个包。

## 无法在 Docker 里验证

这些没有记成通过。

- systemd 的完整启用、开机自启和守护进程监督。容器里用 `service` 启动。
- Let's Encrypt 的正式证书和自动续期。测试使用自签证书。
- 真实的 Cloudflare R2。测试用 MinIO，桶前缀按系统分开。
- Netdata 云端 claim。令牌不进备份，恢复后需要重新 claim。
- Docker 守护进程。容器里只能确认客户端已安装。

真实机器上的步骤见 `xedc-recovery/tests/real-server-checklist.md`。

## 已知限制和手动步骤

- 对象存储里的媒体文件不在备份包内，恢复后继续用原来的私有桶。
- 备份不包含 `.git`。远程地址里如果有令牌，不会被打进备份包。
- `web/sites/default/settings.php` 仍留在历史提交里。`.gitignore` 已忽略它、`.env`、`r2.env` 和 `*.zst`。不要用 `git rm --cached` 去掉已跟踪的 `settings.php`，否则下次在生产目录拉取会删掉正在使用的文件。
- 生产环境当前没有启用 imagick。恢复时会尝试安装，装不上只警告。
- 实验室数据库容器、sing-box、snap、SSM、CUPS、浏览器调试端口不恢复。
- DNS、Cloudflare 记录、邮件 SPF/DKIM、Google 和 Telegram 回调、搜索引擎验证需要人工核对。
- `xedc-push-gmliy` 定时任务里的令牌可能过期，要在新服务器上单独确认。
- Ubuntu 26.04 / 26.10 使用发行版 PHP 8.5。更早的系统从 Ondřej 源安装 PHP 8.5；源不可用时安装不低于 8.2 的最高版本并警告。

## 合并

合并提交：`0ba2319`。已合并到 `main`。
