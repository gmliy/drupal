# 测试报告

日期：2026-11-03。工具版本：2.0.0。

生产网站、生产数据库、Nginx、PHP、证书、cron、`/root/backup.sh` 和 Cloudflare 没有被修改。生产 `settings.php` 的修改时间仍是 2026-11-02 09:33:14 UTC。

## 隔离环境

恢复演练容器 `xedc-recover-drill`：

- 镜像 `ubuntu:24.04`
- 网络 `xedc-recover-lab`
- 内存 3 GiB，CPU 1.5，非特权
- 端口只发布 `127.0.0.1:18080 -> 80`
- 只读挂载密钥、工具目录和加密备份
- 容器内不存在 `/var/www/drupal/web/core`

域名迁移使用另一个容器 `xedc-recover-db`，MariaDB 只监听 `127.0.0.1:13306`。

## 单元和实验室套件

命令：

```bash
cd /opt/xedc-migration/tools/xedc-recover
python3 -m unittest tests.test_core tests.test_lab
```

最终代码完成后连续两轮：

| 轮次 | 结果 | 耗时 |
| --- | --- | --- |
| A | 17 tests, OK | 2.095s |
| B | 17 tests, OK | 1.701s |

覆盖 S1 到 S12。S12 在 `127.0.0.1:13306` 上完成改写、第二次 0 行和回滚。S11 使用进程内 S3 服务和 6 MB 分段上传。S10 的签名与 AWS 2013-05-24 `GET /test.txt` 公开向量一致。

## 生产只读备份

备份 ID：`xedc-20261103T135450Z-3cd3bf`。

`backup verify` 解密 code、database、private、public、secrets 后结果：

```json
{"id": "xedc-20261103T135450Z-3cd3bf", "status": "PASS", "checked": ["code", "database", "private", "public", "secrets"], "parent_id": ""}
```

manifest 中的计数：users 11，node 94，file_managed 273。校验输出和 manifest 没有密钥值。

## 全新服务器演练

同一容器里连续执行两次 `lab/drill.sh xedc-20261103T135450Z-3cd3bf`。中间没有改恢复逻辑。

| 轮次 | 退出码 | 耗时 | 结果 |
| --- | --- | --- | --- |
| 1 | 0 | 144.7s | `DRILL_OK` |
| 2 | 0 | 124.9s | `DRILL_OK` |

两次报告相同：

```json
{"home_bytes": 37192, "login_has_form": true, "drupal_header": true, "home_status": "HTTP/1.1 200 OK"}
```

从宿主机访问发布端口：

| 请求 | 结果 |
| --- | --- |
| `GET /`，Host `lab.xedc.test` | 200，标题 `DcLili - 精选美图、视频与优质文章`，`X-Generator: Drupal 11` |
| `GET /user/login` | 200，标题 `登录 \| DcLili`，页面含登录表单 |
| `GET /sites/default/files/comments/2026-10/IMG_2154_0.png` | 200，`image/png`，173184 字节 |
| 私有目录 URL | 404 |

导入后的数据库计数：users 11，node 94，file_managed 273。public 是指向 `/srv/xedc/public` 的符号链接。`xedc-storage-keys` 已删除。`active.json` 为 `public_mode=local`、`cdn_enabled=false`、`writes_paused=true`、`status=lab-isolated`。`settings.php` 的真实数据库名和用户是 `xedc_restore`，主机是 `127.0.0.1`，私有目录是 `/srv/xedc/private`，trusted host 是演练域名；示例注释里的占位口令没有被当成正式口令。

容器内版本：PHP 8.5.12，MariaDB 13.0.2，Nginx 1.31.8，ffmpeg 6.1.1，7-Zip 23.01，cryptography 41.0.7。Redis 是发行版的 7.0.15，生产机是 8.10.3。首页仍然返回 200。

## 演练中修复过的问题

| 问题 | 根因 | 结果 |
| --- | --- | --- |
| 解包直接失败 | 备份里的硬链接被当成不安全路径；嵌套 `node_modules` 没有排除 | FIXED。硬链接复制成普通文件，越界路径仍拒绝 |
| settings 仍指向原库 | 改写命中了文件前部的示例注释 | FIXED。改为最后一处真实配置 |
| `nginx -t` 失败 | pid 目录不存在 | FIXED |
| Nginx 无法绑定 80 | 重复启动，旧进程还占着端口 | FIXED |
| 502 | `nginx` 用户不能连接 `www-data` 的套接字，目录模式过严 | FIXED |
| 500 Access denied | 第二次导入时 `CREATE USER IF NOT EXISTS` 不更新密码 | FIXED。导入时 `ALTER USER` |
| 演练脚本找不到 cryptography | 命令在安装该包之前就导入了加密模块 | FIXED |

这些修复之后才记录上面的连续两轮 PASS。

## 2026-11-03 15:08Z 最终测试审计

这次审计重新执行了测试，没有沿用上一份报告里的结论。日志在 `/var/lib/xedc-recover/audit/`，不进入 Git。

| 项目 | 命令与日志 | 时间 (UTC) | 状态 |
| --- | --- | --- | --- |
| 隔离容器 | `docker run` ubuntu:24.04，非特权，内存 3 GiB，端口 `127.0.0.1:18080`，三份只读挂载。`container-inspect.txt` | 15:10 | PASS |
| 单元与实验室，修复前 | `python3 -m unittest tests.test_core tests.test_lab`。`unit-round1.log`、`unit-round2.log`，各 18 项 OK | 15:11:04–15:11:09 | PASS |
| 全新恢复，修复前第 1 轮 | `docker exec xedc-recover-drill bash /tool/lab/drill.sh xedc-20261103T135450Z-3cd3bf`。`drill-round1.log`，退出码 0，`DRILL_OK` | 15:10:56–15:15:05 | PASS |
| Drush 可执行位 | `vendor/bin/drush` 被恢复程序改成 `644`，直接执行返回 126。根因是权限整理把归档里的可执行位清掉了 | 15:16 | FIXED |
| 单元与实验室，修复后连续两轮 | `unit-fixed-round1.log` 18 项、2.169s；`unit-fixed-round2.log` 18 项、1.925s。两份都是 OK | 15:17:15–15:17:19 | PASS |
| 全新恢复，修复后第 1 轮 | `drill-fixed-round1.log`，`DRILL_OK`，退出码 0 | 15:17:01–15:19:22 | PASS |
| 全新恢复，修复后第 2 轮 | `drill-fixed-round2.log`，`DRILL_OK`，退出码 0 | 15:20:20–15:22:44 | PASS |
| 备份校验 | `xedc-recover backup verify --backup-id xedc-20261103T135450Z-3cd3bf`。`verify.log` 为 PASS | 15:23:13–15:23:17 | PASS |

修复后的两轮演练都检查了这些结果：

- Drush 13.8.0 以 `755` 直接运行，`status` 显示 Drupal 11.4.11、数据库 Connected、主题 `xedc` / `xedc_admin`、私有目录 `/srv/xedc/private`。
- 一次性登录进入 uid 1 的账户页，HTTP 200，页面含管理工具栏。匿名访问 `/admin` 返回 403。
- 首页、`/user/login`、`/meinv`、`/bizhi/1`、视频页 `/videos/shipinlanmuziduandeceshineirong` 和一张 public PNG 都是 200。
- 数据库计数：users 11，node 94，file_managed 273，user__roles 2。
- 域名迁移、S3 分段上传下载恢复、AWS 签名向量、SSL 自签、错误配置回滚、断点续跑，都包含在上面两轮 18 项测试里。

生产 `settings.php`、Nginx 配置和 `/root/backup.sh` 的修改时间在审计结束后仍分别是 2026-11-02 09:33、2026-11-01 14:31 和 2026-11-02 22:50 UTC。演练容器已删除。

## 2026-11-03 16:18Z 完整恢复包

这次没有重新导出生产数据库。完整包复用已校验的备份 `xedc-20261103T135450Z-3cd3bf`。隔离容器 `xedc-recover-oneshot` 使用 ubuntu:24.04，非特权，内存 3 GiB，没有挂载，端口 `127.0.0.1:18081`。容器内没有生产网站目录。

| 项目 | 日志 | 时间 (UTC) | 状态 |
| --- | --- | --- | --- |
| 单元与实验室，连续两轮 | `unit-bundle-ssl-r1.log`、`unit-bundle-ssl-r2.log`，各 21 项 OK | 16:13 前后 | PASS |
| 本地 S3 恢复包上传下载 | 包含在上面 21 项中。端口 `127.0.0.1:19001`。密钥文件没有上传 | 同上 | PASS |
| APT 公钥不可读 | `oneshot-round1.log` 前半段。`umask 077` 让 Nginx 和 MariaDB 公钥变成 `600`，APT 报 `NO_PUBKEY`，退出码 5 | 16:00:13–16:01:45 | FIXED |
| 无 HTTPS 的一次完整恢复 | 修复公钥权限后，`oneshot-round1.log` 退出码 0。首页、登录、图片、视频、计数和 uid 1 登录已检查 | 16:03:07–16:06:33 | PASS |
| Nginx 仍占用 80 | `oneshot-round2.log`。`service nginx stop` 找不到自定义 pid，再次启动失败 | 16:08:16–16:10:16 | FIXED |
| 最终恢复第 1 轮 | `oneshot-ssl-round1.log`，退出码 0。包 SHA256 `94cbdaeb15add903c927c70bbda3ea948582cd38eb0bdbb91edbcd6ecba6971b` | 16:13:21–16:15:32 | PASS |
| 最终恢复第 2 轮 | 删除 `/srv/xedc` 后重跑。`oneshot-ssl-round2.log`，退出码 0。同一 SHA256 | 16:15:58–16:18:28 | PASS |
| 重复执行保护 | 第 2 轮成功后再次执行，返回 `idempotent: true`，没有重写 | 16:18 后 | PASS |

两轮最终恢复都确认了这些结果：

- HTTP 200：首页、`/user/login`、`/meinv`、`/bizhi/1`、视频页、173184 字节的 PNG。匿名 `/admin` 为 403。私有 URL 为 404。
- 自签 HTTPS 的登录页返回 200。证书 CN 是 `lab.xedc.test`，私钥模式 `600`。
- 数据库：users 11，node 94，file_managed 273，user__roles 2。私有目录 295 个文件。public 是符号链接。
- Drush 模式 `755`，Drupal 11.4.11，数据库 Connected，主题 `xedc` / `xedc_admin`。uid 1 进入 `/user/1/edit`，页面含管理工具栏，标题为 `admin | DcLili`。
- 存储配置是 `lab-isolated`，没有 `xedc-storage-keys`。程序没有修改 Cloudflare。
- 生产 `settings.php`、Nginx 配置和 `/root/backup.sh` 的修改时间仍是 2026-11-02 09:33:14Z、2026-11-01 14:31:02Z 和 2026-11-02 22:50:02Z。

随后用同一份密文重新打包，补上 `/usr/bin/node` v20.20.2 和 npm 10.8.2。新包 SHA256 是 `d8e150020bfb2d61605a8e36421bbe1ef36e614df178c0b013c8bb8fca52b733`。两边 `PACKAGE.json` 的密文哈希一致。实际恢复使用的旧包保存在 `xedc-full-20261103-3cd3bf.restored.tar.zst`。这两份包都不进入 Git。

## 未通过项

| 项目 | 状态 | 原因 |
| --- | --- | --- |
| 向生产或专用 R2 桶上传 | PASS | 2026-11-03 17:09 UTC 已上传到 `xedc-recover/`。见下方一键命令记录。媒体前缀没有写入 |
| 正式 Let's Encrypt 和 DNS-01 | BLOCKED | 只生成 staging 计划，不申请正式证书 |
| replacement 角色整站启动 | NOT TESTED | 该角色会保留对象存储密钥。演练使用 lab，避免第二份网站写回生产 R2 |
| 在生产机安装 systemd 定时任务 | NOT TESTED | 生产主机上该操作被拒绝。临时目录渲染已通过 |
| 独立物理 VPS | NOT TESTED | 没有第二台服务器。全新系统演练使用隔离 Ubuntu 24.04 容器 |
| 对 github.com 的网络克隆 | NOT TESTED | 本地 Git 仓库的提交对齐已通过。生产备份工作区不干净，实际恢复使用包内快照 |

## 2026-11-03 17:15Z 一键备份和 R2 恢复

工具版本 2.2.0。生产 `settings.php` 修改时间仍是 2026-11-02 09:33:14 UTC，`/root/backup.sh` 仍是 2026-11-02 22:50:02 UTC。测试前后 `https://gitxx.xyz/` 都是 200。

单元测试：

```bash
cd /opt/xedc-migration/tools/xedc-recover
python3 -m unittest tests.test_oneshot tests.test_core tests.test_lab
```

2026-11-03 16:53 UTC，26 tests OK，5.923 秒。覆盖一条命令的本地 S3 上传、回读解密、保留策略不删除媒体键、Git 提交覆盖和不可用提交回退。

### 连续两轮全新 Ubuntu 恢复

每轮都是新的 `ubuntu:24.04` 容器。非特权，内存 3 GiB，网络 `xedc-recover-lab`，没有 docker.sock，没有生产目录。只读挂载密钥、工具和已有恢复包。命令是 `xedc-restore --file /packages/xedc-full-20261103-3cd3bf.tar.zst --domain lab.xedc.test --yes --role lab`。

| 轮次 | 时间 (UTC) | 退出码 | 结果 |
| --- | --- | --- | --- |
| 1 | 16:54:15–16:58:15 | 0 | PASS。日志 `oneshot-cli-round1.log`。端口 `127.0.0.1:18082` |
| 2 | 16:58:56–17:03:27 | 0 | PASS。日志 `oneshot-cli-round2.log`。端口 `127.0.0.1:18083` |

两轮宿主机检查相同：首页 200，登录页含 `user-login-form`，匿名 `/admin` 403，PNG 173184 字节。数据库 users 11、node 94、file_managed 273。Drush 显示 Drupal 11.4.11，数据库 Connected。对象存储密钥目录不存在。容器已删除。

这份包是更早的恢复包，当时还没有记录 Git 提交，所以代码来源是 `package-snapshot` / `no-commit-recorded`。

### 生产一键备份和 R2

```bash
ionice -c 3 nice -n 19 python3 /opt/xedc-migration/tools/xedc-recover/bin/xedc-backup --json
```

2026-11-03 17:04:33–17:09:20 UTC，退出码 0。日志 `xedc-backup-live.log`。

| 项目 | 结果 |
| --- | --- |
| 备份编号 | `xedc-20261103T170434Z-764a29` |
| 包 | `xedc-full-20261103T170644Z-764a29.tar.zst` |
| 大小 | 621434331 字节 |
| SHA256 | `0ba8019cc3e2244f705e95a49c0f7ff2e6473d54e783ece464466f3edd1dcc0a` |
| 数据库 | users 11，node 94，file_managed 334 |
| 文件漂移 | 0 |
| Git | `e72409eba38874aaaba4c48e16d085951d222f10`，分支 `main`，137 项未提交改动 |
| 回读解密 | PASS |
| R2 | 桶 `dclili`，前缀 `xedc-recover/packages/`，3 个对象。HEAD `Content-Length` 621434331 |

上传前该前缀是空的。上传后没有 `public/` 或 `private/` 对象。密钥没有上传。

### 不挂载本地包、从 R2 恢复

容器 `xedc-restore-r2r`，`ubuntu:24.04`，非特权，内存 3 GiB，网络 `xedc-recover-lab`，端口 `127.0.0.1:18084`。没有生产目录，没有本地备份包挂载。

```bash
xedc-restore --backup xedc-full-20261103T170644Z-764a29 --domain lab.xedc.test --yes --role lab
```

2026-11-03 17:10:38–17:14:42 UTC，退出码 0。日志 `r2-restore-round.log`。容器内下载文件的 SHA256 与上面一致。首页 200，登录 200，匿名后台 403，PNG 173184 字节。数据库 users 11、node 94、file_managed 334。代码来源 `package-snapshot`，原因 `working-tree-dirty`，提交号与备份一致。R2 检查为列出备份前缀，`writes` 为 false。对象存储密钥目录不存在。容器和临时凭据文件已删除。
