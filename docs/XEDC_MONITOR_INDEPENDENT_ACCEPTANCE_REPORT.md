# 服务器监控独立验收

日期：2026-11-03  
页面：`/admin/reports/xedc-monitor`  
接口：`/admin/reports/xedc-monitor/data`  
站点：https://gitxx.xyz  
依据：`docs/XEDC_MONITOR_DIAGNOSIS_AND_REPAIR_REPORT.md`

本次按第一阶段报告逐项重测，不沿用其中的 PASS / FIXED。没有清空流量表，没有重建监控表，没有清 Netdata 历史，没有打开健康检查，没有改 `config/sync`，没有提交 Git。

## 工作区

开始时分支是 `main`。第一阶段改过的四个文件仍是未提交修改：

- `web/modules/custom/xedc_core/src/Ops/OpsFormat.php`
- `web/modules/custom/xedc_core/src/Ops/MonitorBoard.php`
- `web/modules/custom/xedc_core/src/Ops/ServerMonitor.php`
- `web/modules/custom/xedc_core/js/monitor.js`

这些修改和报告第 8、9 节一致：小数采样先四舍五入到字节，Active / Inactive 和 `system.io` 接受显示名，Buffer Pool 在采样大到不可能是 MiB 时按字节显示，告警空白写明健康检查关闭，切换分类时丢掉过期请求。`StorageSizeFormatter.php` 没有被第一阶段改过。本次没有回滚这些文件。

## 对照结果

| 第一阶段项目 | 第一阶段结论 | 独立复核 | 最终结论 |
| --- | --- | --- | --- |
| Netdata 服务 | PASS | 服务 active、enabled，自 2026-11-02 15:07 UTC 运行，`NRestarts=0`。只听 `127.0.0.1:19999`。`/api/v1/info`、`/charts`、`/alarms?all=1`、`/alarm_log` 都是 HTTP 200。版本 v2.12.2，dbengine。告警对象为空，日志为 `[]`。`netdata.conf` 里 `[health] enabled = no`。`system.cpu` 最早点是 2026-11-01 18:39 UTC，跨度约 44 小时，短于配置的 7 天。 | PASS |
| CPU | PASS | 总览卡连续读到 10.5%、29.5%、20.4%、47.6%。用户、系统、I/O 等待分开。空闲由 100% 减去已采集状态，并标明推算。1 分钟到 1 小时的曲线有点。 | PASS |
| 内存 | FIXED | `mem.reclaiming` 的数据标签是 `Active` / `Inactive`，不是维度 ID。页面读到 Active 3.41 GiB、Inactive 2.61 GiB。总内存 7.55 GiB，可用、已用、缓存、空闲都是 GiB / MiB，没有「暂无数据」。原始 `system.ram` 仍是 MiB 小数。 | PASS |
| Swap | FIXED | 原始 used 728.71 MiB、free 1319.29 MiB。页面已用 728.71 MiB。读写维度仍是 `in` / `out`。 | PASS |
| 磁盘 | FIXED | 根分区 21.79 GiB 已用、35.27 GiB 可用、共 57.08 GiB，使用率 38.2%。`nvme0n1` 实时读写是正的 KiB/s。分类历史图原先把 writes 画成负数，见下方新问题。 | FIXED |
| 网络速度 | FIXED | `system.net` 和 `ens5` 的 sent 在原始接口里是负数。总览和网卡表取绝对值，浏览器看到接收、发送都是正的 KiB/s，连续刷新数值不同。`net_speed.ens5` 仍是 0，带宽使用率保持未采集。 | PASS |
| 累计流量 | PASS | 字节合计与日表一致，页面请求不新增采样。采集时间原先按上海时区写死，和 uid 1 的 UTC 时钟差 8 小时。日流量图在点击「网络」后会被别的图盖成「暂无数据」。两处都已修。 | FIXED |
| MariaDB | FIXED | 同一时刻 `Innodb_buffer_pool_bytes_data` 与 Netdata `data` 都是 112148480，随后又都是 113704960。页面显示约 108.3 MiB，不是 106 TiB。连接 1 或 2，上限 151。 | PASS |
| Redis | FIXED | 图表单位是 bytes。used 55046888 显示为 52.52 MiB，随后页面 52.84 MiB。命中率 90.7%。 | PASS |
| Docker | FIXED | 三个带镜像的 cgroup：Matomo 3.36 MiB、Matomo DB 约 9.3 MiB、恢复演练库 80.31 MiB。临时 Chromium scope 没有被当成容器。 | PASS |
| Nginx | FIXED | 进程 5，CPU 和内存有数。活跃连接、Reading、Writing、Waiting、请求数仍是未采集，因为没有 stub_status。 | PASS |
| PHP-FPM | FIXED | 进程资源有数。空闲、活动、队列、慢请求仍是未采集，因为没有 status 页。 | PASS |
| 历史图表 | PASS | 1 分钟到 1 小时曲线完整。6 小时、24 小时、7 天有点，并写明短于所选范围，没有补 0。7 天的时间文字曾经被另一时区的缓存带偏，已按账号时区分开缓存。 | FIXED |
| 告警 | FIXED | 两个接口都成功且为空。页面写明健康检查关闭，不把空白写成正常。 | PASS |
| 完整指标 | PASS | 搜索 `system.cpu` 得到 1 条、1 页。全库当时 2005 条、67 页。第 1 页首条是 `netdata.collector_ipc_cgroups_lookup_server_requests`，第 2 页首条是 `system.processes`。 | PASS |
| 采集器 | PASS | proc、diskspace、cgroups、apps、go.d/mysql、go.d/redis 都在。缺 stub_status、PHP-FPM status、cpufreq、温度、宿主机压力的项标成未采集。这次读取的失败次数是 0。 | PASS |
| 前端 | FIXED | 十个分类都能切到自己的标题，一个分类的「未采集」不会清空其他分类。浅色卡片是白色，深色卡片是 `rgb(32, 32, 36)`，文字 `rgb(228, 228, 231)`。Console 没有 Uncaught。点击「网络」时日流量图被擦掉，已修。 | FIXED |
| 自动刷新 | NOT TESTED | 总览打开后 9 秒内又发出 4 次 `/data?section=overview`。更新时间从 15:00:19 变为 15:00:29，CPU 从 29.5% 变为 20.4%。间隔显示 3 秒。 | PASS |
| 手动刷新 | NOT TESTED | 在内存分类点击「重新获取」，地址变为 `?refresh=1`，更新时间前进，四张总览卡重新取值。按钮会回到总览，不留在刚才的分类。 | PASS |

匿名访问页面和 JSON 都是 HTTP 403。

## 第一阶段修复是否真实有效

有效。报告里的四类代码改动都在当前文件里，并且对得上原始接口：

- 内存、Swap、磁盘容量、Redis 内存、容器内存不再因为小数被格式化拒掉。
- Active、Inactive、`system.io` 的 reads / writes 能对上 Netdata 返回的显示名。
- Buffer Pool 按字节解释后和 MariaDB 状态一致。
- 告警页说明健康检查是关的。
- 快速切换分类时，后一次点击的分类留在页面上。先点 CPU 再点内存，停稳后标题是内存，有 Active，没有 CPU 频率那一行。

没有发现「只改了前端、采集仍是空的」或「只改了 API、页面仍是暂无数据」。总览、内存、磁盘、MariaDB、Redis、Docker 的数字都能在 Netdata 原始点或 MariaDB 状态里对上。

## 第一阶段误判和遗漏

第一阶段把已修复的容量、Buffer Pool 和维度名判对了。下面几项它判成通过，实际还有问题。

1. 磁盘和网卡的分类历史图仍使用 Netdata 的负方向。`disk.nvme0n1` 的 writes 原始值是 -462.834 KiB/s，图上的最新一点也是负数。旁边的实时数字已经取过绝对值。总览的网络图本来就取绝对值，分类图没有。
2. 从总览点到「网络」后，日流量图变成「暂无数据」并且没有 SVG。直接打开 `?section=network` 是正常的，因为页面初始化会在画完指标图之后再画一次流量图。分类切换只走前一条路径，`drawMap()` 按 `data-chart` 找面板，流量图的 id 是 `traffic`，指标图字典里没有这个键，于是把刚画好的图盖掉。第一阶段看到的是今日 3.0 GB 这张卡，没有发现这张图被盖掉。
3. 历史图的时间文字做进了缓存。Drush 按站点默认的 Asia/Shanghai 生成「2026-11-02 02:39」，7 天图的缓存还没过期时，时区为 UTC 的管理员在浏览器里也看到这句。同一会话里 6 小时图已经过期并按 UTC 显示成 2026-11-01 18:39。两条说的是同一个最早采样点。
4. 累计流量的「最近采集」写死 Asia/Shanghai，监控页其他时间跟随账号时区。uid 1 的时区是 UTC，站点默认是上海。页面头部 15:05，流量采集时间曾经显示 23:05。字节数没有错，时钟看起来差了 8 小时。

总览原先写「累计流量未采集」。网络分类里其实有计数器合计。这句容易看成整站没有累计流量。接口表那一列确实没有填累计字节，原话过满。

MariaDB 虚拟内存约 24.00 TiB 不是单位换算错误。`app.mariadb_vmem_usage` 是 25170320 MiB。三个 `mariadbd` 进程的 VSZ 分别约 8 TiB，加在一起约 24 TiB；RSS 合计约 331 MiB，和 `app.mariadb_mem_usage` 一致。这是进程虚拟地址空间的合计，第一阶段没有改它，本次也不改。

## 本次修复

- 速率类分类图（单位含 `/s`、kilobit 或 KiB）绘制时取绝对值，和实时数字同一方向。容量图仍用原值。
- 监控快照、分类和历史的缓存键加上当前时区。一个时区写进去的时间文字不会发给另一个时区。
- 累计流量的采集时间和开始时间改为跟监控页同一个时钟。日界仍是 Asia/Shanghai。两个钟不一致时，页面加一句说明。
- 总览说明改为：接口表只显示速率，累计流量在网络分类。
- `drawMap()` 跳过流量统计面板，避免分类切换把日流量图擦成「暂无数据」。

复测：

- 点击「网络」后，图注为「最新一点：入站流量（下载） 3291821068 B · 出站流量（上传） 1336889098 B。共 7 个采样。」有 SVG。
- 点击「磁盘」后，writes 为 625.592 KiB/s，是正数。根分区 avail / used 仍是 GiB。
- uid 1 的头部时间 15:05:24，最近采集 15:05:01。说明里有「累计流量按 Asia/Shanghai 划分日期。采集时间跟随当前账号时区。」
- 6 小时和 7 天的最早采样都写成 2026-11-01 18:39，不再一个 UTC、一个上海。

`drush cr` 只为让新的 `monitor.js` 进入聚合脚本。流量表行数在这次浏览器请求前后都是 1259，没有因为刷新页面增加。

## 修改文件

本次独立验收新增或改动：

- `web/modules/custom/xedc_core/src/Ops/ServerMonitor.php`
- `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficReport.php`
- `web/modules/custom/xedc_core/js/monitor.js`
- `docs/XEDC_MONITOR_INDEPENDENT_ACCEPTANCE_REPORT.md`
- `docs/XEDC_PROGRESS.md`
- `docs/monitor-independent-acceptance/` 下的截图

没有改第一阶段的 `OpsFormat.php`、`MonitorBoard.php`，没有改 `config/sync`。

## 累计流量核对

`ens5` 为 UP。1 秒内 sysfs 接收、发送计数都在增加。Cron 是 `/etc/cron.d/xedc-traffic`，每分钟以 www-data 执行 `drush xedc:traffic-sample`。

抽样时 `xedc_traffic_sample` 有 1 行 baseline 不计入，1246 行 ok 和 2 行 sparse 已计入。已计入接收 3231765226 字节、发送 1316045256 字节，与 `xedc_traffic_day` 的 2026-11-03 行一致。没有重复的 `sampled`。日表只有这一天，完整性是 partial，所以本月等于今日。页面是 3.0 GB 和 1.2 GB，按 1024 进位后与字节数相符，旁边写明 1 KB = 1024 B。

间隔大于 90 秒的仍是原来 3 次：360 秒、300 秒、120 秒。日志停在 2026-11-03 05:30，内容是当时的 `DurationManager` 构造错误。那之后 cron 没有再写错误，最新采样间隔 60 秒。本次没有改视频模块。

只调用流量报表时行数不变。浏览器连续请求监控 JSON 期间行数也保持 1259。

## 测试证据

Netdata 与数据库在本机执行。页面用 Chromium 无头和 uid 1 的一次性登录打开。截图在 `docs/monitor-independent-acceptance/`：总览浅色、总览深色、内存、磁盘图、指标搜索。

浏览器 Console 没有页面脚本的 Uncaught 或 TypeError。

## 未解决

这些是没有对应采集器，或是真实计数，页面没有改成 0：

- Nginx 没有 stub_status。
- PHP-FPM 没有 status 页。
- 没有 cpufreq、温度和宿主机 PSI。
- `ens5` 的 speed 为 0，带宽使用率未采集。
- 健康检查仍关闭，所以没有告警定义。
- 流量日表仍是 partial，本月只有 11 月 3 日这一天。
- MariaDB 进程资源里的 vmem 约 24 TiB，是三份虚拟地址空间合计。RSS 约 331 MiB。
- Netdata 库里大约 44 小时，短于 7 天配置。
- 「重新获取」会回到总览。数据会更新，当前分类要再点一次。

## 未测试

- 两个管理员同时看监控。
- 把页签放到后台后的降频。代码里后台间隔至少 30 秒，没有实测。
- 停掉 Netdata 后的失败态。没有为了测试停服务。
- 除 xedc_admin 以外的后台主题。浅色和深色测的是当前后台主题。

## 能否日常使用

可以。CPU、内存、Swap、磁盘容量和读写、网络速率、累计流量、MariaDB、Redis、容器内存都会显示实际采样。没有采集器的项目仍是「未采集」或「有缺口」。本次补上的图方向、流量图和时钟，复测已经按页面上的结果核对。

## 后续建议

- 若希望监控页全程使用北京时间，把 uid 1 的时区从 UTC 改成 Asia/Shanghai 或留空。留空会跟随站点默认的上海。日界已经是上海。
- 需要 Nginx 请求数或 PHP-FPM 队列时，再单独接 stub_status 和 FPM status。不要用进程数代替。
- 需要真正的告警时再打开 Netdata 健康检查。打开前要接受它会改 Netdata 配置。
- 「重新获取」可以改成带上当前分类和时间范围。这次没有改，避免把一个能刷新的链接做成另一套导航。
