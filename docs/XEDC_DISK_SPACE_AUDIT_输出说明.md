# 磁盘空间审计说明

日期：2026-11-03  
站点：https://gitxx.xyz  
主机：Ubuntu 24.04.4 LTS

逐项占用、前 50 个文件和每个候选的路径在 `docs/XEDC_DISK_SPACE_AUDIT_REPORT.md`。这次只做了只读检查，没有删除文件，没有清理 Docker，没有改配置或数据库。

## 现在磁盘是什么情况

根分区 29GB，已用 21GB，还剩 7.7GB，使用率 73%。`/boot` 另有 637MB 空闲。inode 只用了 10%，没有耗尽。已删除但仍被进程打开的文件可以忽略。

空间主要在这些地方，父子目录不要加在一起：

| 位置 | 占用 | 是什么 |
| --- | ---: | --- |
| `/usr` | 3.3G | 系统 |
| `/swapfile` | 2.0G | 交换文件 |
| `/root/.cursor` | 1.9G | Cursor 会话，其中聊天记录 1.8G |
| `/var/lib/snapd` | 1.8G | Snap。`cache/` 和 `snaps/` 是同一份文件的硬链接 |
| `/tmp/snap-private-tmp` | 1.6G | 无头 Chromium 的用户目录 |
| `/var/www/drupal` | 1.5G | 站点。其中 CSS/JS 聚合 668MB |
| `/var/lib/containerd` | 1.4G | Docker 镜像层 |
| `/opt/netdata` | 1.1G | 正在运行的监控 |
| `/root/.local` 与 `/root/.cache` | 0.94G + 0.92G | Cursor CLI、OpenCode、Playwright |
| `/var/lib/mysql` | 562MB | Drupal 数据库 |
| `/root/backups` | 481MB | 三份每周文件包 |

MariaDB 逻辑数据 143MB，二进制日志是关的。Matomo 数据库只有 2.7MB，卷里的大头是 96MB 的 redo log 和 121MB 的地理库。Nginx 日志按天轮转，总共 6.6MB。这些都不是磁盘变少的原因。

## 为什么会持续变少

有两处还在长，另外一处会把第一处再复制一份。

1. **CSS/JS 聚合文件。** `web/sites/default/files/css` 243MB，`js` 425MB。近 7 天新生成 398MB，11 月 1 日和 2 日最多。预处理是打开的，每次资源组合变化都会写出新文件。Drupal 11 只删除超过 45 天的聚合文件，而且现有的每 15 分钟 `drush cron` 不会调用这步清理。最早的文件是 10 月 12 日，所以现在执行缓存重建也删不掉它们。按最近 7 天的速度，大约每周增加 0.4GB。

2. **无头浏览器的用户目录。** Snap 把 `/tmp/<配置名>` 放到 `/tmp/snap-private-tmp/snap.chromium/tmp/`。每个目录大约 150MB。125 个进程已经退出的配置合计 1.28GB；两个仍在运行的配置合计 313MB，分别是 `xedc-cdp-9344` 和 `xedc-cdp-9355`。每次检查再用一个新目录，这块就会再跳一截。

3. **周日的文件备份跟着聚合目录变大。** `/root/backup.sh` 只在周日打包 `web/sites/default/files`，并且只保留 4 份。现在有 3 份：10 月 18 日 89MB、10 月 25 日 141MB、11 月 1 日 251MB。份数有上限，但每一份都会因为 CSS/JS 变大。`files/` 已被 gitignore，每日 `git add -A` 没有把这些文件提交进仓库。Git 仓库是 113MB。

sysstat 没有记录文件系统用量，所以没有“过去 7 天整盘净增多少”的实测曲线。上面的 398MB 是按聚合文件的修改日逐个相加的，这些文件名不会被覆盖，数字可信。

## 和业务文件的关系

`file_managed` 有 157 条记录，合计 106.5MB。公开文件的 URL 走到 `https://cdn.gitxx.xyz/public/...`。这 106.5MB 是对象记录，不是本地磁盘占用。

本地 `sites/default/files` 里除了聚合文件，还有：

| 内容 | 大小 | 判断 |
| --- | ---: | --- |
| 迁移状态为 verified 的原图副本 | 71.7MB，128 个文件 | 本地字节和迁移记录一致。公开地址走 CDN，但源站 Nginx 在本地文件还在时会直接发送它。不能因为 R2 上有就删除 |
| fid 792–796 五个 PNG | 21.6MB | `IMG_2398.png`、`IMG_2714_0.png`、`IMG_2717_0.png`、`IMG_2716_0.png`、`IMG_2715_0.png`。只在本地和 `file_managed`，R2 的 `public/` 与 `s3fs-public/` 都没有。这是唯一副本 |
| `styles/` | 18.7MB | 图片样式。包装器故意留在本地，删除后会重新生成 |
| 当前 ZIP `private/xedc-zips/27-37c09aa8592b4a80.zip` | 19.9MB | resource 27 的当前下载缓存 |
| 旧 ZIP `27-7d461e09902ff1c5.zip` | 19.8MB | 不是当前戳。模块的保留时间是 7 天，2026-11-09 18:31 UTC 之后才会自己删 |

`public://` 的 `file_exists` 在存储桶没有对象时会退回本地文件，所以不能用它证明 R2 上有副本。

导入目录、隔离目录、下载临时目录是空的。图片优化表没有行，也没有 `.xedc-staging`。视频播放副本只有 2.2MB。自定义模块源码 5.1MB。

Nginx 错误日志里重复出现 `Cannot redeclare function xedc_preprocess_html()`，大约 53 次，来自 `xedc.theme` 和 `themes/custom/xedc/includes/auth.inc`。这是代码错误，不占磁盘。

## 如果以后要腾空间

还没有执行。可以释放的量和前提：

| 档 | 大约空间 | 内容 | 前提 |
| --- | ---: | --- | --- |
| A | 2.0GB | 已退出的 Chromium 配置 1.28GB、apt 的 310 个 deb 233MB、Composer 缓存 146MB、npm 缓存 365MB | 不要删仍在运行的 `xedc-cdp-9344`、`xedc-cdp-9355`，也不要删 Playwright 正在用的配置 |
| B | 约 1.9GB | Playwright 浏览器、`xedc-admin-round10`、10 月 29 日主题备份、旧内核头、没有容器使用的 `ubuntu:24.04` 镜像、两份旧周包 | 先确认这些工具和回滚点不再需要 |
| B | 约 2.5GB | Cursor 聊天记录和 OpenCode | 会丢掉会话记录 |
| B | 0.67GB | 整个 `files/css` 和 `files/js` | 只适合在有回收策略的维护窗口做。页面会重新生成，之后还会继续增长 |
| C | 不释放 | 五个仅本地原图、当前 ZIP、最新周包、升级前 SQL、密钥 | 业务或恢复数据 |
| D | 不释放 | 交换文件、数据库、Matomo 卷、正在使用的镜像和 Snap、Netdata、vendor、core、Git | 运行依赖 |

只做 A，已用空间大约从 21GB 降到 19GB。聚合文件如果不改变回收方式，仍会按大约每周 0.4GB 继续增加，周日备份包也会跟着变大。

不要做的事：`docker system prune`、删除 Matomo 或 MariaDB 卷、清空 `files/css` 与 `files/js`、删除 fid 792–796、把 R2 上已有文件当成可以删除本地副本的理由。
