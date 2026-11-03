# XEDC 磁盘空间审计报告

审计时间：2026-11-03 09:10 UTC  
主机：Ubuntu 24.04.4 LTS，内核 `7.0.0-1014-aws`  
范围：整机只读检查。本阶段没有删除、截断、prune、改配置、改库或改站点文件。

## 1. 当前磁盘

根分区是唯一有压力的文件系统。`/boot` 和 `/boot/efi` 分开挂载，空间充足。inode 没有耗尽。ext4 保留块为 0。

| 文件系统 | 容量 | 已用 | 可用 | 使用率 | 挂载点 | inode 已用 |
| --- | ---: | ---: | ---: | ---: | --- | ---: |
| `/dev/nvme0n1p1` | 29G | 21G | 7.7G | 73% | `/` | 349,100 / 3,801,088（10%） |
| `/dev/nvme0n1p16` | 881M | 183M | 637M | 23% | `/boot` | 2% |
| `/dev/nvme0n1p15` | 105M | 6.2M | 99M | 6% | `/boot/efi` | 不适用 |

块设备：`nvme0n1` 30G。根分区 7,601,915 个 4KiB 块，空闲 2,075,048 块。`df` 与 `du -xhd1 /` 都约 21G，没有大块“已删除但仍被进程占用”的空间。

已删除但仍打开的文件：MariaDB 在 `/tmp` 上的临时表约 0–2KB；Chromium 有若干已删除的 `/tmp/.org.chromium.Chromium.*`，单个最大约 20MB。`memfd` 不占 ext4。这部分不是磁盘下降的原因。

## 2. 目录占用

以下数字来自 `du -x`，硬链接只计一次。父子目录不要相加。

### 2.1 一级目录

| 路径 | 占用 |
| --- | ---: |
| `/var` | 6.4G |
| `/root` | 5.9G |
| `/usr` | 3.3G |
| `/` 上的 `/swapfile`（文件，不在子目录里） | 2.0G |
| `/tmp` | 1.8G |
| `/opt` | 1.1G |
| `/etc`、`/home`、`/srv` | 可忽略 |

### 2.2 最大的 30 个目录

按 `du` 实测排序。带缩进的行被上一行包含，不能再加进总和。

| # | 路径 | 占用 | 性质 |
| ---: | --- | ---: | --- |
| 1 | `/root/.cursor` | 1.9G | Cursor 会话与跟踪库 |
| 2 | `/root/.cursor/chats` | 1.8G | 其中 143 个会话 |
| 3 | `/var/lib/snapd` | 1.8G | Snap 包；`cache/` 与 `snaps/` 是硬链接 |
| 4 | `/usr/lib` | 1.7G | 系统库 |
| 5 | `/tmp/snap-private-tmp` | 1.6G | Snap Chromium 的私有 `/tmp` |
| 6 | `/var/www/drupal` | 1.5G | 站点 |
| 7 | `/var/lib/containerd` | 1.4G | 镜像层与 blob，对应 Docker 镜像 |
| 8 | `/var/www/drupal/web` | 1.2G | Drupal web root |
| 9 | `/opt/netdata` | 1.1G | 正在运行的 Netdata |
| 10 | `/root/.local` | 939M | cursor-agent 与 OpenCode |
| 11 | `/root/.cache` | 915M | Playwright、Composer、Chrome for Testing |
| 12 | `/var/www/drupal/web/sites/default/files` | 794M | 本地 public 文件，主体是 CSS/JS |
| 13 | `/usr/bin` | 592M | 系统程序 |
| 14 | `/var/lib/mysql` | 562M | 主机 MariaDB（Drupal） |
| 15 | `/root/.local/share/cursor-agent` | 558M | 正在使用的 Cursor CLI |
| 16 | `/usr/share` | 506M | 系统数据 |
| 17 | `/root/backups` | 481M | 每周 files 包，现有 3 份 |
| 18 | `/var/lib/docker` | 418M | 卷与容器层；镜像本体在 containerd |
| 19 | `/var/cache` | 404M | apt 等 |
| 20 | `/var/lib/mysql/drupal` | 398M | Drupal 数据目录 |
| 21 | `/root/.local/share/opencode` | 378M | OpenCode 数据，含 362MB 数据库 |
| 22 | `/root/.npm` | 365M | npm 缓存 |
| 23 | `/usr/src` | 336M | 两套内核头文件 |
| 24 | `/root/xedc-admin-round10` | 312M | 一轮后台 UI 测试留下的浏览器 |
| 25 | `/root/.opencode` | 268M | OpenCode 程序与 node_modules |
| 26 | `/var/lib/docker/volumes` | 412M | Matomo 应用卷与数据库卷 |
| 27 | `/root/snap/chromium` | 225M | Snap Chromium 用户数据 |
| 28 | `/root/bak` | 195M | 2026-10-29 主题备份，含 node_modules |
| 29 | `/var/www/drupal/web/themes/custom/xedc/node_modules` | 171M | 主题构建依赖 |
| 30 | `/var/www/drupal/web/core` | 161M | Drupal 核心 |

不重叠的大致构成：交换文件 2.0G、`/usr` 3.3G、Cursor 1.9G、Snap 1.8G、Chromium 临时配置 1.6G、Drupal 1.5G、容器镜像 1.4G、Netdata 1.1G、`/root/.local` 0.94G、`/root/.cache` 0.92G、MariaDB 0.56G、备份 0.48G、Docker 卷 0.42G、apt 缓存 0.40G、npm 缓存 0.37G，其余为测试残留和系统小目录。

## 3. 最大的 50 个文件

按 inode 去重。Snap 的 `snaps/*.snap` 与 `cache/<hash>` 是同一 inode（`nlink=2`），缓存目录不是第二份空间。下表只保留 `snaps/` 路径。

| # | 大小 | 最后修改 | 路径 |
| ---: | ---: | --- | --- |
| 1 | 2048 MB | 2026-10-24 12:29 | `/swapfile` |
| 2 | 616 MB | 2026-11-01 07:35 | `/var/lib/snapd/snaps/gnome-46-2404_177.snap` |
| 3 | 402 MB | 2026-11-01 07:35 | `/var/lib/snapd/snaps/mesa-2404_1839.snap` |
| 4 | 362 MB | 2026-10-25 13:14 | `/root/.local/share/opencode/opencode.db` |
| 5 | 287 MB | 2026-10-24 13:10 | `/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome` |
| 6 | 251 MB | 2026-11-01 19:00 | `/root/backups/files-2026-11-01.tar.gz` |
| 7 | 205 MB | 2026-10-22 12:30 | `/root/.opencode/bin/opencode` |
| 8 | 198 MB | 2026-10-24 13:11 | `/root/.cache/ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/chrome-headless-shell` |
| 9 | 187 MB | 2026-11-01 07:34 | `/var/lib/snapd/snaps/chromium_3569.snap` |
| 10 | 183 MB | 2026-10-23 12:21 | `/opt/netdata/usr/libexec/netdata/plugins.d/go.d.plugin` |
| 11 | 181 MB | 2026-10-23 12:54 | `/opt/netdata/bin/srv/netdata` |
| 12 | 141 MB | 2026-10-25 19:00 | `/root/backups/files-2026-10-25.tar.gz` |
| 13 | 141 MB | 2026-10-22 08:35 | `/root/.local/share/cursor-agent/versions/2026.10.22-27dcf5f/cursor-agent-worker-sea` |
| 14 | 137 MB | 2026-04-21 | `/usr/lib/x86_64-linux-gnu/libLLVM.so.20.1` |
| 15 | 132 MB | 2026-11-02 11:10 | `/root/xedc-admin-round10/browsers/webkit-2370/minibrowser-wpe/lib/libWPEWebKit-2.0.so.1.12.0` |
| 16 | 128 MB | 2026-10-22 08:33 | `/root/.local/share/cursor-agent/versions/2026.10.22-27dcf5f/cursor-agent-sea` |
| 17 | 123 MB | 2024-12-05 | `/usr/lib/x86_64-linux-gnu/libLLVM.so.19.1` |
| 18 | 121 MB | 2026-10-23 12:15 | `/opt/netdata/usr/share/netdata/topology-ip-intel/topology-ip-geo.mmdb` |
| 19 | 121 MB | 2026-10-28 16:14 | `/var/lib/docker/volumes/matomo_matomo-app-data/_data/misc/DBIP-City.mmdb` |
| 20 | 121 MB | 2026-10-22 08:31 | `/root/.local/share/cursor-agent/versions/2026.10.22-27dcf5f/node` |
| 21 | 118 MB | 2024-05-27 | `/usr/lib/llvm-18/lib/libLLVM.so.1` |
| 22 | 112 MB | 2026-10-19 16:16 | `/var/lib/containerd/io.containerd.content.v1.content/blobs/sha256/4633f536ccdec4a16333083dae0a93845971ba5bb631fdd1c5e56ad3e6dbc1a0` |
| 23 | 107 MB | 2026-10-14 18:32 | `/usr/bin/dockerd` |
| 24 | 103 MB | 2026-10-23 12:59 | `/opt/netdata/usr/libexec/netdata/plugins.d/otel-plugin` |
| 25 | 102 MB | 2026-11-02 11:10 | `/root/xedc-admin-round10/browsers/webkit-2370/minibrowser-gtk/lib/libwebkitgtk-6.0.so.4.20.0` |
| 26 | 96 MB | 2026-11-03 09:06 | `/var/lib/mysql/ib_logfile0` |
| 27 | 96 MB | 2026-11-02 14:57 | `/var/lib/docker/volumes/matomo_db-data/_data/ib_logfile0` |
| 28 | 95 MB | 2026-11-03 08:56 | `/root/.cursor/chats/8dcb43281e1a62f6a40b625d40a90125/957db136-02bc-4387-a6d9-d4d4566766bf/store.db` |
| 29 | 94 MB | 2026-03-24 | `/usr/bin/node` |
| 30 | 92 MB | 2026-11-01 07:34 | `/var/lib/snapd/snaps/gtk-common-themes_1535.snap` |
| 31 | 89 MB | 2026-10-18 03:00 | `/root/backups/files-2026-10-18.tar.gz` |
| 32 | 78 MB | 2026-10-23 12:44 | `/opt/netdata/usr/libexec/netdata/plugins.d/netflow-plugin` |
| 33 | 74 MB | 2026-10-13 00:38 | `/var/lib/snapd/snaps/core22_2955.snap` |
| 34 | 74 MB | 2026-06-10 10:15 | `/var/lib/snapd/snaps/core22_2411.snap` |
| 35 | 72 MB | 2026-10-08 | `/usr/libexec/docker/cli-plugins/docker-buildx` |
| 36 | 72 MB | 2026-11-02 21:53 | `/root/.cursor/chats/8dcb43281e1a62f6a40b625d40a90125/058d0d17-8794-4798-a0f9-6caee2ca30a3/store.db` |
| 37 | 70 MB | 2024-04-25 | `/var/lib/apt/lists/ap-southeast-1.ec2.archive.ubuntu.com_ubuntu_dists_noble_universe_binary-amd64_Packages` |
| 38 | 67 MB | 2026-10-19 16:16 | `/var/lib/containerd/io.containerd.content.v1.content/blobs/sha256/a151992c3a5d39e7404b6143eaf4aeabd0359bf10ecf0a761c7bb979836769c1` |
| 39 | 67 MB | 2026-11-01 07:34 | `/var/lib/snapd/snaps/core24_2124.snap` |
| 40 | 63 MB | 2026-11-03 09:05 | `/root/.cursor/ai-tracking/ai-code-tracking.db` |
| 41 | 62 MB | 2024-05-27 | `/usr/lib/llvm-18/lib/libclang-cpp.so.18.1` |
| 42 | 62 MB | 2026-10-16 06:31 | `/boot/initrd.img-7.0.0-1014-aws` |
| 43 | 61 MB | 2026-11-01 07:34 | `/var/lib/snapd/snaps/core26_646.snap` |
| 44 | 61 MB | 2026-11-02 05:04 | `/root/.cursor/chats/8dcb43281e1a62f6a40b625d40a90125/4b2f360d-f883-48fe-a553-6d5bc75941b1/store.db` |
| 45 | 57 MB | 2026-11-03 01:13 | `/var/cache/apt/pkgcache.bin` |
| 46 | 51 MB | 2026-11-03 08:07 | `/root/.cursor/chats/8dcb43281e1a62f6a40b625d40a90125/79280bcc-4b30-4666-8bf7-ba801fbcb24b/store.db` |
| 47 | 49 MB | 2026-06-10 10:15 | `/var/lib/snapd/snaps/snapd_26865.snap` |
| 48 | 45 MB | 2026-10-14 18:32 | `/usr/bin/docker` |
| 49 | 45 MB | 2026-10-22 08:35 | `/root/.local/share/cursor-agent/versions/2026.10.22-27dcf5f/file_service.linux-x64-gnu.node` |
| 50 | 44 MB | 2026-10-31 07:15 | `/root/xedc-migrate/xedc-migrate-20261031071514.tgz` |

第 50 名之后紧接着同一大小的 `/root/site-migrate/site-migrate-20261031T095234Z.tgz`（44MB，2026-10-31 09:52）和 `/root/drupal-backup/before-11.4.11.sql`（33.5MB，2026-10-22 05:34）。

单个 CSS/JS 聚合文件都不大，所以没有进入前 50，但目录合计 668MB。

## 4. Docker

`docker system df` 与目录占用一致。build cache 为 0。没有执行 prune。

| 项目 | 占用 | 状态 |
| --- | ---: | --- |
| 镜像 `matomo:5-apache` | 869MB 独占 | 2 个容器使用 |
| 镜像 `mariadb:lts` | 464MB 独占 | `matomo-db-1` 使用 |
| 镜像 `ubuntu:24.04` | 119MB 独占 | 0 个容器 |
| 容器可写层 | 各约 4–25KB | 可忽略 |
| 卷 `matomo_matomo-app-data` | 217MB（目录 246MB） | 运行中的 Matomo 使用 |
| 卷 `matomo_db-data` | 174MB（目录 167MB） | 运行中的 Matomo 数据库 |
| 容器日志 | Matomo 5.8MB，数据库 24KB，cron 4KB | 没有失控 |
| overlay2 / containerd snapshots | 1.1G | 上述镜像的层 |
| content blobs | 330MB | 镜像内容 |

容器：

| 名称 | 镜像 | 状态 | 可写层 |
| --- | --- | --- | --- |
| `matomo-matomo-1` | `matomo:5-apache` | Up 18 小时 | 25KB |
| `matomo-db-1` | `mariadb:lts` | Up 18 小时，healthy | 25KB |
| `matomo-cron-1` | `matomo:5-apache` | Exited (137)，两周前 | 4KB |

Matomo 应用卷里最大的单文件是地理库 `misc/DBIP-City.mmdb`（121MB）。`tmp/cache` 与 `tmp/assets` 合计约 11MB。Matomo 数据库逻辑大小只有 2.7MB；卷里的 96MB 是 InnoDB redo log，不是业务表膨胀。

已停止的 cron 容器几乎不占空间，它和正在运行的 Matomo 共用镜像。不能清理 Matomo、MariaDB 镜像或这两个卷。

## 5. 数据库

### 5.1 主机 MariaDB（Drupal）

数据目录 `/var/lib/mysql` 562MB。`log_bin=OFF`，没有 binlog。redo log 固定 96MB（`ib_logfile0`）。`ibdata1` 12MB，undo 各约 11MB。慢查询日志 `/var/log/mysql/xedc-slow.log` 只有 19KB。`general_log` 关闭。

`information_schema` 中 `drupal` 库逻辑大小 142.8MB（数据 124.0MB + 索引 18.8MB），226 张表。目录比逻辑大小大约 250MB，主要是 redo/undo 和 InnoDB 文件预分配，不是某张失控的表。

最大的表：

| 表 | 数据+索引 | 约行数 | 说明 |
| --- | ---: | ---: | --- |
| `cache_page` | 18.3MB | 332 | 页面缓存 |
| `cache_dynamic_page_cache` | 15.5MB | 409 | 动态页缓存 |
| `cache_render` | 15.4MB | 1684 | 渲染缓存 |
| `cache_discovery` | 8.6MB | 290 | 发现缓存 |
| `locales_target` | 7.0MB | 42991 | 翻译，不能当缓存删 |
| `router` | 6.9MB | 1169 | 路由 |
| `cache_default` | 6.6MB | 253 | 默认缓存 |
| `cache_data` | 5.8MB | 883 | 数据缓存 |
| `key_value` | 4.2MB | 2126 | 状态与钩子表 |
| `locales_source` | 4.0MB | 17699 | 翻译源 |
| `cache_entity` | 3.5MB | 289 | 实体缓存 |
| `key_value_expire` | 3.1MB | 44 | 可过期键值 |
| `cache_container` | 3.1MB | 2 | 容器缓存 |
| `search_index` | 3.0MB | 13843 | 搜索索引 |
| `cache_config` | 2.9MB | 1781 | 配置缓存 |
| `config_import` / `config_export` / `config` | 各约 2.1–2.4MB |  | 配置 |
| `xedc_file_hash_work` | 2.1MB | 2300 | 文件哈希工作表 |
| `xedc_file_ref` | 1.8MB | 10153 | 文件引用 |
| `xedc_file_finding` | 1.5MB | 1935 | 重复/检查发现 |
| `watchdog` | 1.1MB | 1023 | 日志，见下 |
| `sessions` | 0.06MB | 216 | 会话 |

全部 `cache%` 表合计 82.1MB。默认缓存后端已经是 Redis（当前约 36MB / 上限 64MB）。`bootstrap`、`config`、`discovery` 仍走 ChainedFast，数据库表仍会被用到。这些表可以用 Drupal 缓存清理机制回收，不能直接 `TRUNCATE`。它们不是磁盘持续下降的原因。

`watchdog` 现有 1023 行，时间范围只覆盖 2026-11-03 01:59–08:55 UTC。其中 `cron` 675 行、`page not found` 227 行、`php` 54 行。表会周转，不会按这个速度堆满磁盘。`.ibd` 文件 15MB，大于当前数据，是回收后的空闲页。

队列 `queue` 有 140 行过期的 `drupal_batch:*` 记录，数据约 0.02MB。

XEDC 业务表都很小。迁移表 `xedc_storage_migration_item`：verified 128 条（71.7MB 源大小）、pending 512 条（记录的源大小 286.8MB，其中图片 504 条 / 260.5MB、视频 8 条 / 26.3MB）、missing 36 条归档（0.2MB）。pending 是任务状态，不是 287MB 的本地文件。

### 5.2 Matomo MariaDB（Docker）

库 `matomo` 逻辑大小 2.7MB。最大表 `matomo_log_link_visit_action` 0.70MB。归档表只有 2026-10 一份，各 0.2–0.3MB。卷上的其余空间是数据库系统文件。不能删除这个卷。

### 5.3 可以走 Drupal 维护、但不能直接删的数据

- `cache_*`：Drupal 缓存 API 或 `drush cr` 的缓存箱清理。Redis 已承接默认箱后，仍要确认每个箱的后端，再决定数据库残留。
- `watchdog`：由 dblog 保留策略清理。
- `sessions`：随会话过期清理。现在可忽略。
- `search_index` / `search_dataset` / `search_total`：重建搜索索引时由 Drupal 维护，不是垃圾。
- `locales_*`、`router`、`config*`、全部 `xedc_*`：业务或运行数据。

禁止直接 `DELETE`、`OPTIMIZE`、`DROP`、`TRUNCATE`。

## 6. Drupal 文件

`/var/www/drupal` 合计 1.5G。

| 路径 | 占用 | 分类 |
| --- | ---: | --- |
| `web/sites/default/files/js` | 425MB | 聚合缓存。1916 个 `.js` + 1916 个 `.js.gz` |
| `web/sites/default/files/css` | 243MB | 聚合缓存。1293 个 `.css` + 1293 个 `.css.gz` |
| `web/themes/custom/xedc/node_modules` | 171MB | 主题构建依赖 |
| `web/core` | 161MB | 核心 |
| `vendor` | 119MB | Composer 依赖 |
| `.git` | 113MB | 4307 个松散对象，无 pack，无 garbage |
| `web/modules/contrib` | 38MB | 贡献模块 |
| `web/sites/default/files/2026-11` | 51MB | 本地原图副本，见第 7 节 |
| `private` | 41MB | 几乎全是 ZIP 缓存 |
| `web/sites/default/files/styles` | 21MB | 图片样式衍生物，设计上留在本地 |
| `web/sites/default/files/gallery` | 20MB | 已在 R2 核对过的本地副本 |
| `web/sites/default/files/2026-10` | 20MB | 同上 |
| `web/modules/custom` | 5.1MB | 自定义模块源码，不是垃圾 |
| `config` | 4.0MB | 配置同步 |
| `web/sites/default/files/php/twig` | 1.7MB | Twig PHP 存储 |
| `web/sites/default/files/xedc-playback` | 2.2MB | 播放副本，库中有记录且 R2 上也有 |
| `private/xedc-import`、`xedc-download-tmp`、`xedc-downloads` | 空目录 | 无残留导入包 |
| `private/xedc-quarantine` | 只有 `.htaccess` | 无隔离文件 |

`file_managed`：157 行，记录大小 106.5MB。`public` 147 个文件 / 106.5MB，`private` 10 个 / 约 0。`file_usage` 156 行，6 行指向已不存在的 fid，10 个 managed 文件没有 usage（合计记录 8.1MB）。public 流已接管到 R2，`realpath(public://)` 为空，所以这 106.5MB 是对象记录，不是本地占用。

公开 URL 生成到 `https://cdn.gitxx.xyz/public/...`。Nginx 对 `/sites/default/files/` 仍是 `try_files`：本地文件若还在，源站会直接把它发出去；不在则进 Drupal。因此“R2 上有同一字节”不等于可以删本地文件。

CSS/JS 预处理是打开的（`system.performance` 的 css/js preprocess 均为 1）。`.gitignore` 已忽略 `web/sites/*/files/`，聚合文件没有进入 Git。Git 113MB 来自代码历史，`git add -A` 的每日备份不会把 files 再提交进去。

## 7. 自定义模块与文件引用

自定义模块源码只有 5.1MB，没有在模块目录里堆临时文件。

对照结果：

| 检查项 | 数据库 | 磁盘 | 结论 |
| --- | --- | --- | --- |
| 图片导入 | `xedc_import_task` 1 条，status `published`，`archive_uri` 空，`cleanup_after=0` | `private/xedc-import` 空 | 没有留下原始导入包 |
| ZIP 下载 | `xedc_zip_job` 5 条，全部 `ready`，消息为缓存命中 | `private/xedc-zips/` 两个文件，都属 resource 27 | 当前戳是 `27-37c09aa8592b4a80.zip`；另一个戳已过期，见候选 |
| 图片优化 | `xedc_image_optimize` 0 行 | 无 `.xedc-staging` | 无优化残留 |
| 视频转码 | completed 3、failed 2、skipped 2 | `xedc-playback` 2.2MB，样本在 `xedc-samples` | 失败项是不可读样本和私有文件策略，不是大临时文件 |
| 文件清理 | 1 条 failed（开发期误合并，已回滚）、1 条 success | 无清理临时目录 | 无残留 |
| 重复文件 | `xedc_file_finding` 1.5MB、`xedc_file_hash_work` 2.1MB | 无报告文件堆 | 只在数据库 |
| AI 审核 | `xedc_ai_review` 2 条 failed | `private/xedc-ai-keys` 只有密钥文件 | 无审核临时图片 |
| R2 迁移 | verified 128 个本地文件字节与迁移记录一致 | 见下 | 这 128 个是已核对的本地副本。另有 5 个原图只在本地，不在迁移表里 |
| 会员上传失败 | 无对应临时目录 | 无 | 未发现遗留 |
| 隔离区 | 无业务行指向大文件 | 隔离目录为空 | 无 |

已核对、本地与对象大小一致的原图副本：

- 迁移状态 `verified`：128 个文件，75,177,632 字节（71.7MB），0 个大小不一致。分布在 `2026-10`、`2026-11`、`gallery`、`pictures`、`comments`。
- 不在迁移表、只存在于本地磁盘：fid 792–796，合计约 21.6MB，路径为  
  `web/sites/default/files/2026-11/IMG_2398.png`、`IMG_2714_0.png`、`IMG_2717_0.png`、`IMG_2716_0.png`、`IMG_2715_0.png`。  
  它们在 `file_managed` 中，创建于 2026-11-02 23:21–23:23 UTC。`file_exists(public://)` 也会返回真，因为包装器在存储桶没有对象时会退回本地原图，这不能证明 R2 上有副本。Prefix 清点也确认这两个前缀都没有这五个文件。
- 同日稍后的 fid 797、798、808 等本地已经没有文件。

`XedcPublicS3fsStream` 把原图公开地址指到 CDN，并明确把图片样式留在本地 public 目录，供 `/sites/default/files/styles` 路由使用。`styles/` 833 个文件、18.7MB 是这个设计的衍生物，不是导入失败残留。原图仍出现在本地目录，是接管之后没有清掉的副本；本报告不把“R2 已有”当成删除许可。

ZIP：`ZipBuilder::readyUri()` 按原图 fid、大小、修改时间和文件名计算 16 位戳，TTL 为 7 天（`CACHE_TTL=604800`）。resource 27 的当前 URI 是 `private://xedc-zips/27-37c09aa8592b4a80.zip`，`inspect()` 为 ready / cached。`27-7d461e09902ff1c5.zip` 不是当前戳。`purgeExpired()` 只按年龄删除，这个旧包要到 2026-11-09 18:31 UTC 之后才会被模块自己清掉。下载临时目录是空的。

## 8. 临时文件、缓存和日志

| 路径 | 占用 | 说明 |
| --- | ---: | --- |
| `/tmp/snap-private-tmp/snap.chromium/tmp` | 1.6G | 125 个已退出浏览器配置 1.28G；2 个仍在运行 313MB |
| `/root/.cache/ms-playwright` | 681MB | Chromium 1248 与 headless shell |
| `/root/.npm` | 365MB | npm 缓存，其中 `_cacache` 344MB |
| `/var/cache/apt` | 348MB | 其中 `archives` 里 310 个 deb 共 233MB |
| `/var/lib/apt/lists` | 208MB | apt 索引，不是可随手删的 deb |
| `/root/.cache/composer` | 146MB | Composer 下载缓存，不是 `vendor` |
| `/opt/netdata/var/cache/netdata` | 143MB | 运行中的监控缓存 |
| `/var/log` | 139MB | journal 110MB，nginx 6.6MB |
| `/root/.cache/google-chrome-for-testing-headless` | 73MB | 测试浏览器 |
| `/tmp/xm-browser` | 56MB | 另一次浏览器测试 |
| `/tmp` 下其余 `xedc-*`、截图、`phpunit.phar` | 约 40MB | 测试残留 |
| systemd journal | 101MB | `journalctl --disk-usage` |
| `/var/log/nginx` | 6.6MB | 按日轮转，压缩包保留到 10 月中旬 |
| `/var/log/mysql` | 19KB | 慢日志 |
| `/var/tmp` | 64KB | 无堆积 |
| PHP-FPM 会话目录 `/var/lib/php/sessions` | 空 | 会话在数据库 |

Chromium 配置是这一组里最大、而且会继续增加的部分。Snap 把 `/tmp/<profile>` 放到 `/tmp/snap-private-tmp/snap.chromium/tmp/<profile>`。每个用户目录大约 150–170MB。当前进程仍占用：

- `/tmp/xedc-cdp-9344`（158MB，PID 238343）
- `/tmp/xedc-cdp-9355`（155MB，PID 253647）
- 另有一个 Playwright 进程使用 `/tmp/playwright_chromiumdev_profile-1wYl6G`

其余 125 个目录的 `SingletonLock` 指向的 PID 已不在进程表中，合计 1279.8MB。较大的已退出目录：

| 大小 | 目录最后变化 | 路径 | 锁指向的 PID |
| ---: | --- | --- | --- |
| 172MB | 2026-11-02 21:21 | `/tmp/snap-private-tmp/snap.chromium/tmp/xedc-chrome-profile` | 106366，已退出 |
| 168MB | 2026-11-02 16:40 | `.../xedc-chrome` | 15978，已退出 |
| 162MB | 2026-11-02 21:26 | `.../xedc-chrome-guest` | 108266，已退出 |
| 157MB | 2026-11-03 06:47 | `.../xedc-cdp-9341` | 229451，已退出 |
| 156MB | 2026-11-03 06:11 | `.../xedc-cdp-9333` | 221546，已退出 |
| 155MB | 2026-11-03 07:33 | `.../xv-chrome` | 241559，已退出 |
| 150MB | 2026-11-02 23:23 | `.../xp-chrome` | 134224，已退出 |

日志没有成为磁盘问题。Nginx access 当天约 0.8MB，前一天 2.6MB，更早的已 gzip。重复错误是主题函数重复声明，不是大日志：

`PHP Fatal error: Cannot redeclare function xedc_preprocess_html()`，`xedc.theme` 与 `themes/custom/xedc/includes/auth.inc`，当天 error log 里约 53 次。

另有少量 `upstream response is buffered to a temporary file`，请求是 `/xedc/download/.../archive`。`/var/cache/nginx` 只有 3.0MB，临时缓冲没有留下来。

`/root/.cursor/chats` 1.8G、143 个会话。最大的 `store.db` 在审计期间仍在写入（95MB，08:56 UTC）。`ai-code-tracking.db` 63MB，09:05 UTC 仍在写。

## 9. 近 7 天和 30 天

sysstat 从 10 月 26 日有采样，但没有文件系统用量活动（`sar -F` 不可用）。没有历史 `df` 序列，不能给出“7 天整盘增加了多少 GB”的实测差值。下面两处是按文件创建/修改日累加的，聚合文件每次都是新文件名，这个序列可信。

### CSS/JS 聚合（新文件，不是原地改写）

| 日期 | CSS | JS |
| --- | ---: | ---: |
| 2026-10-12 至 10-26 | 72.0MB | 162.4MB |
| 2026-10-27 | 0.4MB | 2.3MB |
| 2026-10-28 | 6.0MB | 16.5MB |
| 2026-10-29 | 38.4MB | 19.7MB |
| 2026-10-30 | 44.4MB | 48.9MB |
| 2026-10-31 | 9.1MB | 7.8MB |
| 2026-11-01 | 26.5MB | 53.8MB |
| 2026-11-02 | 32.9MB | 70.3MB |
| 2026-11-03（至约 09:05 UTC） | 7.4MB | 14.0MB |
| 合计 | 243MB | 425MB |

近 7 天（10-27 至 11-03）新增 398MB，其中 11-01 与 11-02 两天就占了 JS 的 124MB。自 10-12 以来全部 668MB 都在 30 天窗口内。最早文件 2026-10-12 04:00 UTC，最新文件审计当时仍在生成。

按这个速度，若无人回收，聚合目录大约每 7 天增加 0.4GB，主题或库组合变忙时更快。

### 其他能看出的增长

- 已退出的 Chromium 配置集中在 11-02 至 11-03，约 1.3GB。这是一次次新开 `user-data-dir` 留下的，不是一个文件在变大。
- `/root/backups` 的三份周包：10-18 为 89MB，10-25 为 141MB，11-01 为 251MB。包越来越大，是因为 `files/` 里的聚合文件被打进去了。
- Cursor `store.db` 在 11-02 和 11-03 仍在增大。整棵 `/root` 的 mtime 几乎都在 30 天内，这只能说明家目录是这台机器近期写出来的，不能把它换成“30 天净增 5.9GB”。
- MariaDB、Matomo、Nginx 日志、inode 在这次窗口里没有表现成主增长源。

## 10. 磁盘为什么会持续减少

按对未来空间的影响排序：

1. **Drupal CSS/JS 聚合只增不删。** 预处理开着。Drupal 11 的 `CssCollectionOptimizerLazy::deleteAll()` 和 JS 对应实现只删除早于 `aggregate_gc_threshold`（默认 45 天）的文件。`system` 的 cron 不调用它。根 crontab 每 15 分钟执行 `drush cron`，因此这个缺口不会被现有 cron 补上。`drush cr` 会调用 `deleteAll()`，但 45 天内的文件仍会保留。今天最早的聚合文件是 10 月 12 日，要到 11 月 26 日才满 45 天，而且那天仍然要有一次 `deleteAll()` 才会删。主题和资源库在近期频繁变化，每天产生新的哈希文件。这是站点目录里唯一还在稳定变大的部分。

2. **无头浏览器每次使用新的用户目录，退出后不收。** 一个目录约 150MB。两天内已经留下 1.3GB 死配置，另外 313MB 仍被两个 Chrome 进程占用。只要 UI 检查继续用新的 `--user-data-dir`，这块会继续跳增。

3. **Cursor 会话库。** 143 个聊天、1.8GB，最大的库今天仍在写。这是代理历史，不是网站数据，但会一直占根分区。

4. **每周文件包跟着聚合目录变大。** `/root/backup.sh` 只在周日打包 `web/sites/default/files`，并只保留 4 份（`ls -t | tail -n +5 | xargs rm`）。现在有 3 份。它不会无限增加份数，但每一份都会因为 CSS/JS 变大。下一份周日包会大于现在的 251MB。脚本同时还 `git add -A` 并推送；files 已被 gitignore，所以 Git 本身没有因此膨胀。

不是原因的部分：inode（10%）、binlog（关闭）、watchdog、会话、Matomo 数据库、Nginx 日志轮转、已删除未释放文件、自定义模块源码、空的导入/隔离/下载临时目录。

## 11. 清理候选

分级：

- **A**：有充分证据，删的是缓存或已退出进程的测试配置，不碰站点数据。仍建议在维护窗口做，并先确认没有新的浏览器刚刚启动。
- **B**：需要再确认用途。删了可以再生或只影响开发工具，但本次不能当成已经安全。
- **C**：业务或备份数据，禁止直接删除。
- **D**：系统、数据库、运行中的服务或当前依赖，禁止删除。

预计可释放空间见第 13 节。下面不执行任何删除。

### A

| 路径 | 大小 | 时间 | 依据 |
| --- | ---: | --- | --- |
| `/tmp/snap-private-tmp/snap.chromium/tmp/` 下 125 个已退出配置，不含下面两个活进程目录 | 1280MB | 目录变化集中在 2026-11-02 至 11-03 | `SingletonLock` 的 PID 不在进程表中，也不是当前 `--user-data-dir`。内容是 UI 检查的 Chromium 配置，不是 `file_managed` 或 R2 对象 |
| 其中 `xedc-chrome-profile` | 172MB | 2026-11-02 21:21 | 同上，PID 106366 已退出 |
| `xedc-chrome` | 168MB | 2026-11-02 16:40 | PID 15978 已退出 |
| `xedc-chrome-guest` | 162MB | 2026-11-02 21:26 | PID 108266 已退出 |
| `xedc-cdp-9341` | 157MB | 2026-11-03 06:47 | PID 229451 已退出 |
| `xedc-cdp-9333` | 156MB | 2026-11-03 06:11 | PID 221546 已退出 |
| `xv-chrome` | 155MB | 2026-11-03 07:33 | PID 241559 已退出 |
| `xp-chrome` | 150MB | 2026-11-02 23:23 | PID 134224 已退出 |
| 其余约 118 个小配置 | 约 160MB | 同期 | 同一判断 |
| `/var/cache/apt/archives` 中 310 个 `.deb` | 233MB | 目录 2026-11-02 16:47 | 已下载的包缓存。不包含 `/var/lib/apt/lists`，也不包含已安装文件 |
| `/root/.cache/composer` | 146MB | 目录 2026-10-15 | Composer 文件缓存。`vendor/` 另算，不在这里 |
| `/root/.npm` | 365MB | 目录 2026-10-25 | npm 缓存。主题 `node_modules` 不在这里 |

A 合计约 **2.0GB**。

不要删这两个仍在运行的目录：`.../xedc-cdp-9344`（158MB，PID 238343）和 `.../xedc-cdp-9355`（155MB，PID 253647）。也不要删 Playwright 正在使用的 `playwright_chromiumdev_profile-1wYl6G`。

### B

| 路径 | 大小 | 时间 | 依据 |
| --- | ---: | --- | --- |
| `/var/www/drupal/web/sites/default/files/css` | 243MB | 文件 2026-10-12 至 2026-11-03 09:04 | 可再生的聚合缓存，但 Nginx 正在从磁盘提供。今天没有任何文件超过 45 天。整目录删除会造成一次重建高峰。应改回收策略，而不是手工 `rm` |
| `/var/www/drupal/web/sites/default/files/js` | 425MB | 同上，最新 09:05 | 同上。`.gz` 与明文成对，不能只删其中一种 |
| `/root/.cache/ms-playwright` | 681MB | 浏览器文件 2026-10-24 | 测试浏览器，下次 Playwright 会重新下载。若近期还要跑 UI 测试，删了会拖慢那次运行 |
| `/root/.cursor/chats` | 1.8GB | 多个 `store.db` 今天仍在写 | 143 个会话历史。不是站点数据，删除会丢掉代理记录 |
| `/root/.cursor/ai-tracking/ai-code-tracking.db` | 63MB | 2026-11-03 09:05 | 代码跟踪库，今天仍在写 |
| `/root/.local/share/opencode` 与 `/root/.opencode` | 646MB | `opencode.db` 2026-10-25，二进制 2026-10-22 | 开发工具及 362MB 数据库。先确认不再使用 OpenCode |
| `/root/xedc-admin-round10` | 312MB | 浏览器库 2026-11-02 11:10 | 一轮后台验收留下的 WebKit/GTK 和截图。看目录名是已结束的轮次，仍应确认没有脚本还指向它 |
| `/root/bak/xedc-2026-10-29-1923` | 195MB | 2026-10-29 | 旧主题树，182MB 是 `node_modules`。是备份副本 |
| `/var/www/drupal/web/themes/custom/xedc/node_modules` | 171MB | 随主题安装 | 运行站点不直接读它，构建要它。可用 `npm ci` 恢复，但不是缓存垃圾 |
| `/usr/src/linux-aws-6.17-headers-6.17.0-1017` 与 `/usr/src/linux-headers-6.17.0-1017-aws` | 166MB | 随旧内核 | 上一套内核头。正在运行的是 7.0.0-1014。留着可以回滚，不要和当前头文件一起处理 |
| 镜像 `ubuntu:24.04`（ID `fe81560990fa`） | 119MB | 8 天前 | `docker system df` 显示 0 个容器。先确认没有 compose 或脚本还要它 |
| `/root/.cache/google-chrome-for-testing-headless` | 73MB | 缓存目录内 | 测试用 Chrome |
| 迁移状态 verified、本地大小与对象一致的 128 个原图 | 71.7MB | 在 `files/2026-10`、`2026-11`、`gallery`、`pictures`、`comments` | 对象存储上有相同字节，公开 URL 走 CDN。Nginx 对源站路径仍会优先发送本地文件。在确认没有页面或缓存还请求源站本地路径之前，不能删 |
| `/var/www/drupal/web/sites/default/files/styles` | 18.7MB | 衍生物 | 流包装器有意把样式留在本地。删了会重新生成，生成期间源站样式会未命中 |
| `/var/www/drupal/private/xedc-zips/27-7d461e09902ff1c5.zip` | 19.8MB | 2026-11-02 18:31 | 不是 resource 27 的当前戳。模块会在 7 天 TTL 后自己删。提前删只省这 20MB，并绕过模块自己的生命周期 |
| `/root/backups/files-2026-10-18.tar.gz` | 89MB | 2026-10-18 03:00 | 更旧的 files 周包。脚本设计是保留 4 份，现在只有 3 份 |
| `/root/backups/files-2026-10-25.tar.gz` | 141MB | 2026-10-25 19:00 | 同上。最新的 11-01 那份不要动 |
| `/root/xedc-migrate/xedc-migrate-20261031071514.tgz` | 44MB | 2026-10-31 07:15 | 迁移包 |
| `/root/site-migrate/site-migrate-20261031T095234Z.tgz` | 44MB | 2026-10-31 09:52 | 迁移包 |
| `/tmp/xm-browser` 及 `/tmp/xedc-chrome*`、`/tmp/xedc-shots`、`/tmp/thumb-acc`、`/tmp/chrome-profile` | 约 90MB | 11 月上旬 | 测试浏览器和截图。先确认没有进程把它们当用户目录 |
| 已禁用的旧 Snap 修订：`amazon-ssm-agent` 13009、`core22` 2411、`snapd` 26865 | 名义约 150MB | 2026-06 至 10 月 | 它们和 `/var/lib/snapd/seed` 硬链接。只去掉禁用修订，块可能仍然被 seed 引用，实际释放可能接近 0 |

B 里如果接受“测试工具和旧轮次可以不要”，大约还有 **1.9GB**（Playwright、round10、bak、旧内核头、未使用的 ubuntu 镜像、Chrome for Testing、小的 `/tmp` 测试目录、两个旧周包、两份迁移包）。会话历史和 OpenCode 另计约 **2.5GB**。CSS/JS 若在维护窗口按回收策略处理，目录现值为 **0.67GB**，但会重新生成，不应当成永久释放。

已核对的本地原图副本 71.7MB 放在 B，是因为对象存储上有相同字节，但没有证明源站不再读本地路径。fid 792–796 的 21.6MB 是仅本地存在的业务原图，列在 C。

### C

| 路径 | 大小 | 原因 |
| --- | ---: | --- |
| `/root/backups/files-2026-11-01.tar.gz` | 251MB | 最新的 files 周包，恢复点 |
| `/var/www/drupal/private/xedc-zips/27-37c09aa8592b4a80.zip` | 19.9MB | 当前 ZIP 缓存，2026-11-02 19:44。下载会命中它 |
| `files/xedc-playback`、`files/xedc-samples` | 2.2MB | `xedc_video_transcode` 与 `file_managed` 引用，R2 上也有播放副本 |
| `web/sites/default/files/2026-11/IMG_2398.png`、`IMG_2714_0.png`、`IMG_2717_0.png`、`IMG_2716_0.png`、`IMG_2715_0.png` | 21.6MB | fid 792–796，2026-11-02 23:21–23:23。只在本地和 `file_managed`，R2 两个前缀都没有对象 |
| `file_managed` 所代表的对象 | 记录 106.5MB | 业务文件记录。有本地副本的不能凭记录删除 |
| `/root/drupal-backup/before-11.4.11.sql` | 33.5MB | 升级前数据库转储 |
| `private/xedc-storage-keys`、`private/xedc-ai-keys`、`private/xedc-storage-runtime` | 数 KB | 运行密钥与开关，禁止删除 |
| `private/backups/settings.php.before-object-storage` | 40KB | 接管前的 settings 副本 |

### D

| 路径 | 大小 | 原因 |
| --- | ---: | --- |
| `/swapfile` | 2.0G | 交换文件 |
| `/usr` 除去已单独标出的旧内核头 | 约 3.1G | 系统 |
| `/var/lib/snapd` 当前修订，含 Chromium、gnome、mesa、gtk | 约 1.6G 唯一块 | 正在被无头 Chromium 使用 |
| `/var/lib/containerd` 中 matomo 与 mariadb 层 | 约 1.3G | 运行中的容器镜像 |
| `/opt/netdata` | 1.1G | `netdata` 服务 active |
| `/var/lib/mysql` | 562MB | Drupal 数据库 |
| `/root/.local/share/cursor-agent` | 558MB | 当前 Cursor CLI |
| `/var/lib/docker/volumes/matomo_*` | 413MB | Matomo 数据与库 |
| `/var/www/drupal/vendor`、`web/core`、`web/modules/contrib` | 318MB | 运行依赖 |
| `/var/www/drupal/.git` | 113MB | 仓库历史。备份脚本每天提交并推送 |
| `/var/lib/docker/volumes/.../DBIP-City.mmdb` | 121MB | Matomo 地理库 |
| `/var/lib/mysql/ib_logfile0` 与 Matomo 的 redo log | 各 96MB | 数据库日志，固定大小 |
| Redis 内存中的缓存 | 36MB | 不在磁盘上；上限 64MB |
| 当前内核 `/boot` 与 `/usr/src` 里的 7.0.0-1014 头文件 | `/boot` 上约 80MB，头文件约 170MB | 正在运行 |

## 12. 不能删除的原因（汇总）

- 对象存储里已经有文件，只说明 CDN 那份存在。本地 `verified` 副本仍可能被源站 Nginx 直接送出。
- `styles/` 是流包装器故意留在本地的衍生物。
- 当前 ZIP `27-37c09aa8592b4a80.zip` 是下载缓存命中对象。
- `file_managed`、播放副本、密钥、配置同步、最新周备份、升级前 SQL，都是业务或恢复数据。
- MariaDB 数据文件、redo log、Matomo 卷、正在使用的镜像、Snap 当前修订、Netdata、交换文件、vendor、core、Git，删了会让站点或主机起不来。
- 数据库缓存表即使改由 Redis 承接，也要走 Drupal 缓存 API，不能 `TRUNCATE`。

## 13. 预计可以释放多少

| 档 | 内容 | 约可释放 | 条件 |
| --- | --- | ---: | --- |
| A | 已退出的 Chromium 配置、apt deb 缓存、Composer 缓存、npm 缓存 | **2.0GB** | 跳过仍在运行的两个 CDP 配置和 Playwright 配置 |
| B 工具残留 | Playwright 浏览器、round10、10-29 主题备份、旧内核头、未使用 ubuntu 镜像、Chrome for Testing、小测试目录、两份旧周包、两份迁移包 | **约 1.9GB** | 确认对应工具和回滚点不再需要 |
| B 历史 | Cursor 聊天与 OpenCode | **约 2.5GB** | 接受丢掉会话和 OpenCode 数据 |
| B 聚合 | `files/css` + `files/js` | **0.67GB** | 只在有回收策略的维护窗口。页面会重新生成，随后继续增长 |
| 现在不能算 | 已核对的本地原图副本，以及仅本地存在的五个原图 | 0.09GB | 前者先确认源站不再读本地路径；后者是唯一副本 |

只做 A，根分区大约从 21G 降到 19G，可用空间从 7.7G 升到约 9.7G。  
A 加上已确认的工具残留，大约再多 1.9GB。  
把会话历史也算上，上限大约 6.4GB，可用空间可到约 14G。这不是建议一次做完，只是上限。

即使释放了这些空间，只要 CSS/JS 聚合继续按近 7 天的速度增长（约 0.4GB/周），并且每次 UI 检查再留下 150MB 浏览器配置，根分区会再次下降。周日的 files 包还会把聚合目录再复制一份，最多保留 4 份。

## 14. 建议的后续顺序

本次没有做。若下一阶段允许处理，顺序应是：

1. 只清理 A 档，并在清理前再核对一次 Chrome 进程和 `--user-data-dir`。
2. 给聚合文件补上回收。现有 cron 不会调用 `deleteAll()`，而默认阈值是 45 天。需要单独决定阈值和调用点。在那之前不要手工删除整个 `css/`、`js/`。
3. 浏览器检查改为复用用户目录，或在进程退出时只删除自己的已退出配置。
4. 本地原图副本单独开一次核对：源站访问日志里是否还有 `/sites/default/files/2026-*` 和 `gallery` 的命中。有命中就保留。
5. 不要 prune Docker，不要动 Matomo 卷、MariaDB、Git、vendor 和 `swapfile`。

## 15. 检查方法

只读命令包括 `df`、`df -i`、`findmnt`、`lsblk`、`du`、`find`、`lsof +L1`、`stat`、`docker system df`、`docker ps`、`journalctl --disk-usage`、`snap list --all`、`crontab -l`、`nginx -T`、`sar`/`sadf`、Drush 只读 SQL 与 `php:eval`。`file_exists(public://)` 在存储桶没有对象时会退回本地文件，不能单独当作 R2 存在的证据。没有 `rm`、`truncate`、`docker system prune`、`DELETE`、`OPTIMIZE`、`DROP` 或配置写入。
