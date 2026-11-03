# 服务器监控诊断与修复

日期：2026-11-03  
页面：`/admin/reports/xedc-monitor`  
接口：`/admin/reports/xedc-monitor/data`  
站点：https://gitxx.xyz

本次先核对工作区和运行版本，再沿 Netdata、Drupal 接口、页面和累计流量逐层对照。没有重写监控模块，没有清空流量表或 Netdata 历史，没有改 `config/sync`，没有提交 Git。

## 1. 故障现象

监控服务是启动的，CPU、负载、MariaDB 连接和 Redis 命中率能显示。同一页上大量已经采到的指标却显示「暂无数据」，状态仍标成正常。另外几项被标成「未采集」，原始接口里其实有数。MariaDB 的 InnoDB Buffer Pool 显示成约 106.77 TiB。

具体包括：

- 总内存、已用、可用、缓存、Swap 容量显示「暂无数据」。空闲偶尔能显示，是因为它碰巧是整数字节。
- 磁盘容量、读写速度、网卡收发速度、Redis 已用内存、容器内存同样显示「暂无数据」。
- 内存 Active / Inactive 显示「未采集」。
- 磁盘「内核聚合吞吐」显示「未采集」。
- Buffer Pool 数据量约 106.77 TiB。MariaDB 状态 `Innodb_buffer_pool_bytes_data` 当时是 111951872 字节，约 106.8 MiB。

Nginx 请求数、PHP-FPM 队列、主机压力、温度这些仍然是「未采集」。它们没有对应图表，这次没有改成 0。

## 2. 故障根本原因

三件独立的事叠在一起。

1. `OpsFormat::bytes()` 已改成调用 `StorageSizeFormatter`。这个格式化只接受整数。Netdata 的 `group=average` 和 MiB 换算会得到小数。小数被拒绝后，页面写成「暂无数据」，但指标状态仍是 `ok`。存储容量格式化本身不改，它继续拒绝 `12.5` 这类非整数字节。监控显示在进入格式化之前先四舍五入到字节。
2. `/api/v1/data` 返回的是维度显示名，不是图表目录里的维度 ID。`mem.reclaiming` 的 ID 是 `active`，数据标签是 `Active`。`system.io` 的 ID 是 `in` / `out`，数据标签是 `reads` / `writes`。页面按 ID 查找，于是显示「未采集」。
3. `mysql_local.innodb_buffer_pool_bytes` 的单位写成 MiB，采样值仍是字节。页面再按 MiB 乘 1024²，106.8 MiB 被显示成约 106.77 TiB。大于 8 TiB（按 MiB 解读）的采样改按字节显示。采集器如果以后自己除好了，小于这个门槛的值仍按 MiB 显示。

Netdata v2.12.2 在跑，只听 `127.0.0.1:19999`。`proc.plugin`、`diskspace.plugin`、`cgroups.plugin`、`apps.plugin`、`go.d/mysql`、`go.d/redis` 都有图表，最近采样在更新。健康检查在 `/opt/netdata/etc/netdata/netdata.conf` 里本来就是 `enabled = no`，告警接口因此是空的。这次没有打开它，也没有编造告警。

## 3. Netdata 服务检查结果

| 检查 | 结果 |
| --- | --- |
| `systemctl is-active netdata` | active，enabled，自 2026-11-02 15:07 UTC 运行 |
| 进程 | `netdata`、`apps.plugin`、`go.d.plugin` 都在 |
| 监听 | 只有 `127.0.0.1:19999` |
| `/api/v1/info` | HTTP 200，约 8 毫秒，版本 v2.12.2 |
| `/api/v1/charts` | HTTP 200，2042 张图表 |
| `/api/v1/data` | 抽查的系统、磁盘、网卡、MySQL、Redis 图表 HTTP 200，有最新点 |
| `/api/v1/alarms?all=1` | HTTP 200，`alarms` 为空对象 |
| `/api/v1/alarm_log` | HTTP 200，空数组 `[]` |
| 内存模式 | dbengine，tier 0 保留 7 天、上限 256MB |
| 实际已保存跨度 | `system.cpu` 最早点约 44 小时前，短于 7 天配置 |

`journalctl -u netdata` 里有 cgroup 命名和一次 go.d 对未注册 `prometheus_fluentd_exporter` 的隔离记录。MySQL 和 Redis 采集没有连接失败。没有为这次诊断重启 Netdata。

## 4. 采集器检查结果

| 采集器 | 结果 |
| --- | --- |
| proc.plugin | 在 `/api/v1/info` 的 collectors 里。CPU、内存、Swap、负载、磁盘、`system.net` 都有最新点 |
| diskspace.plugin | `/`、`/boot`、`/run` 的容量和 inode 都有 |
| cgroups.plugin | Matomo、Matomo DB、恢复演练数据库容器有 CPU、内存、IO、网卡 |
| apps.plugin | nginx、phpfpm、mariadb、redis 的进程、CPU、内存图表都有 |
| go.d/mysql | 43 张图。活动连接 1，上限 151，查询速率约 2.5 次/秒 |
| go.d/redis | 23 张图。客户端 1，内存约 51.6 MiB，命中率约 90.7% |

没有 Nginx `stub_status`，也没有 PHP-FPM status 图表。进程数不能代替请求数和队列。这两类继续显示「未采集」。`ens5` 的 `net_speed` 为 0，带宽使用率继续是「未采集」。

## 5. Drupal API 检查结果

`drush php:eval` 调用 `xedc_core.monitor`，并和 Netdata 原始点对照。

修复前，接口 HTTP 层是成功的，分类不会因为一个指标拖垮整个 JSON。坏在字段值：内存、磁盘容量、网络速率、Redis 内存、容器内存是「暂无数据」；Active、Inactive、`system.io` 是「未采集」；Buffer Pool 是 106.77 TiB。

修复后同一次快照：

- CPU 7.2%，随后两次刷新为 43.3% 和 15.2%，数值在变。
- 内存使用率 38.1%，可用 4.67 GiB，总内存 7.55 GiB。
- Swap 已用 729.55 MiB，总量 2.00 GiB。
- 根分区 21.74 GiB / 35.32 GiB，使用率 38.1%。`nvme0n1` 读 9.61 KiB/s，写 7.00 KiB/s。
- 网络约 3.38 KiB/s 收、6.33 KiB/s 发，随后刷新到数百 KiB/s。
- Buffer Pool 106.84 MiB，和 MariaDB 的字节计数一致。
- Redis 已用 51.64 MiB。容器内存分别为 3.36 MiB、9.56 MiB、80.30 MiB。

匿名访问页面和 JSON 都是 HTTP 403。

## 6. 前端检查结果

Chromium 无头模式用管理员一次性登录打开页面。总览四张卡为 6.4%、38.4%、38.1%、`↓ 1.97 KiB/s ↑ 6.01 KiB/s`。四个历史图都画出了 SVG，注释里有「最新一点」。整页「暂无数据」出现 0 次。

内存页 Active 1.69 GiB、Inactive 2.49 GiB。MariaDB 页 Buffer Pool 数据量 106.89 MiB。网络页今日下载 3.0 GB、今日上传 1.2 GB，本月相同，因为日表目前只有 2026-11-03。告警页写明接口没有告警定义，健康检查关闭时不会把空白写成正常。完整指标页能打开图表目录。

浏览器 stderr 里没有页面 `Uncaught` 或 `TypeError`。有几条系统 D-Bus 连接失败，和监控脚本无关。

分类切换会中止上一个实时请求，返回时如果分类已经变了就丢掉结果，避免旧分类覆盖新分类。聚合后的 `js_YTxKtM-mAcX47PLn9zhrtYFLm4yVnOJVKNDXaXQ-S58.js` 里有这段判断。

没有在浏览器里等到下一次自动刷新，也没有点「重新获取」。这两项不记成通过。

## 7. 累计流量检查结果

`ens5` 存在且为 UP。sysfs 计数在读数之间继续增长。采样命令是 `/etc/cron.d/xedc-traffic`，每分钟以 `www-data` 执行 `drush xedc:traffic-sample`。

当时 `xedc_traffic_sample` 有 1234 行，全部是 `ens5`。没有重复的 `sampled`。已计入增量合计与 `xedc_traffic_day` 的 2026-11-03 行一致：接收 3197569567 字节，发送 1297467181 字节。1 行是起点，不计入流量；1231 行 `ok`，2 行 `sparse`。

间隔大于 90 秒的有 3 次：2026-11-02 21:08 的 360 秒，2026-11-03 04:45 的 120 秒，2026-11-03 05:31 的 300 秒。05:30 的 cron 日志里有一次 `DurationManager` 构造错误，那一轮采样没有写入。之后的采样恢复了，检查时最新行距当时不到 1 分钟，间隔 60 秒。日汇总因此是 `partial`。页面写的是「采样偏疏」和「有缺口」，没有把缺口改成 0。

只调用流量报表、不调用采样命令时，行数保持 1234。报表不会额外记账。

本月等于今日，因为日表只有这一天。这是统计起点还在 11 月 3 日，不是把以前的流量清掉。

## 8. 具体修复内容

- 监控用的字节显示先把有限、非负的小数四舍五入成整数，再交给现有的二进制单位格式化。负数、空值和存储格式化入口的行为不变。
- 内存 Active / Inactive 同时接受 `Active`/`active` 和 `Inactive`/`inactive`。
- 磁盘聚合吞吐同时接受 `reads`/`in` 和 `writes`/`out`。
- Buffer Pool 字节图在采样大到不可能是 MiB 时按字节显示，并取绝对值，避免面积图的负方向被当成没有数据。
- 告警接口两边都成功但没有任何定义时，页面说明健康检查是关闭的。
- 前端在切换分类时丢掉过期请求。

没有新增采集器，没有打开 Netdata 健康检查，没有改网卡计数，没有删表。

## 9. 修改文件清单

- `web/modules/custom/xedc_core/src/Ops/OpsFormat.php`
- `web/modules/custom/xedc_core/src/Ops/MonitorBoard.php`
- `web/modules/custom/xedc_core/src/Ops/ServerMonitor.php`
- `web/modules/custom/xedc_core/js/monitor.js`

`OpsFormat.php` 在这次任务开始前已经改过，用来把容量显示换成 1024 进制。这次只补了小数采样的四舍五入。`StorageSizeFormatter.php` 没有改。`ops.css` 也有别人的未提交修改，这次没动。

为了让聚合脚本生效，执行过 `drush cr`。这只清缓存，不清流量表和 Netdata。

## 10. 修复前后对比

| 指标 | 修复前 | 修复后 |
| --- | --- | --- |
| 内存容量 | 暂无数据 | 总内存 7.55 GiB，可用 4.67 GiB |
| Active / Inactive | 未采集 | 1.61 GiB / 2.44 GiB，浏览器复测 1.69 / 2.49 GiB |
| Swap | 暂无数据 | 已用 729.55 MiB，共 2.00 GiB |
| 根分区容量 | 使用率在，容量暂无数据 | 21.74 GiB 已用，35.32 GiB 剩余 |
| 磁盘读写 | 暂无数据，聚合吞吐未采集 | `nvme0n1` 与 `system.io` 都有 KiB/s |
| 网络速度 | `↓ 暂无数据/s ↑ 暂无数据/s` | 有 KiB/s，连续两次采样数值不同 |
| Redis 已用内存 | 暂无数据 | 51.64 MiB |
| 容器内存 | 暂无数据 | 3.36 / 9.56 / 80.30 MiB |
| Buffer Pool | 106.77 TiB | 106.84 MiB，浏览器 106.89 MiB |
| 告警 | 空列表，容易看成没有活动告警 | 写明没有告警定义，健康检查关闭 |

## 11. 真实测试命令

```bash
systemctl is-active netdata
ss -lnt | awk '$4 ~ /19999/'
curl -sS -m 8 -D - http://127.0.0.1:19999/api/v1/info -o /tmp/nd-info.json
curl -sS -m 15 -o /tmp/nd-charts.json -w '%{http_code}\n' http://127.0.0.1:19999/api/v1/charts
curl -sS -m 10 -o /tmp/nd-alarms.json -w '%{http_code}\n' 'http://127.0.0.1:19999/api/v1/alarms?all=1'
curl -sS -m 10 -o /tmp/nd-alarmlog.json -w '%{http_code}\n' http://127.0.0.1:19999/api/v1/alarm_log
journalctl -u netdata --since '6 hours ago' --no-pager -p warning
curl -sk -o /dev/null -w '%{http_code}\n' https://gitxx.xyz/admin/reports/xedc-monitor
curl -sk -o /dev/null -w '%{http_code}\n' https://gitxx.xyz/admin/reports/xedc-monitor/data
cd /var/www/drupal && ./vendor/bin/drush php:eval '...'   # 快照、14 个分类、历史范围、流量报表
./vendor/bin/drush sql:query "SELECT status, counted, COUNT(*) n FROM xedc_traffic_sample GROUP BY status, counted"
./vendor/bin/drush cr
chromium --headless=new --no-sandbox --disable-dev-shm-usage --user-data-dir=/tmp/xedc-monitor-chrome --virtual-time-budget=15000 --dump-dom '<管理员一次性登录后的监控地址>'
```

流量报表前后各数一次 `xedc_traffic_sample`，都是 1234。没有执行 `xedc:traffic-sample`，避免在 cron 之外再记一笔。

## 12. 测试结果

| 项目 | 结果 |
| --- | --- |
| Netdata 服务、端口、API | PASS |
| CPU | PASS |
| 内存 | FIXED |
| Swap | FIXED |
| 磁盘空间和读写 | FIXED |
| 网络实时速度 | FIXED |
| 今日、本月累计流量 | PASS |
| MariaDB | FIXED |
| Redis | FIXED |
| Docker | FIXED |
| Nginx 进程及状态指标 | FIXED。进程资源已显示。连接数和请求速率仍是未采集 |
| PHP-FPM 进程及状态指标 | FIXED。进程资源已显示。空闲、活动和队列仍是未采集 |
| 历史图表和时间范围 | PASS。1 分钟到 1 小时曲线完整。6 小时、24 小时、7 天有点，并标明短于所选范围 |
| 告警 | FIXED。接口仍为空，页面说明原因 |
| 完整指标搜索和分页 | PASS。`system.cpu` 搜到 1 条。全库 1981 条、67 页，第 0 页和第 1 页首条不同 |
| 采集器 | PASS。25 个采集器，期望项里缺图表的标成未采集 |
| 前端图表和分类 | FIXED |
| 自动刷新的下一次请求 | NOT TESTED |
| 手动刷新按钮 | NOT TESTED |
| 权限 | PASS。匿名 403，Netdata 只听本机 |
| 未采集指标 | PASS。没有补 0 |

实时指标至少做了两次间隔采样，CPU 和网络速度都变了。历史接口返回了时间序列。`system.cpu` 的单图历史有 60 个点。

## 13. 未解决问题

没有仍把已采到的数显示成「暂无数据」或错误数量级的项目。

这些是真实缺口，页面保持「未采集」或「有缺口」：

- Nginx 没有 stub_status，没有请求数和活动连接。
- PHP-FPM 没有 status 页，没有空闲进程、活动进程和 listen queue。
- 主机没有 PSI、温度和 cpufreq 图表。
- `ens5` 速率为 0，带宽使用率未采集。
- 累计流量有 3 次历史间隔偏长，日汇总是 partial。05:30 UTC 那次和 cron 日志里的 `DurationManager` 构造错误对得上。当前采样已恢复，这次没有改视频模块。
- MariaDB 虚拟内存显示约 24 TiB。图表单位是 MiB，这是进程虚拟地址空间，不是 RSS。RSS 约 330 MiB。没有改这个数。
- Netdata 健康检查仍关闭，所以没有告警定义。保留期配置是 7 天，库里目前大约 44 小时。

## 14. 未测试项目

- 浏览器里等待一个自动刷新周期，并确认发出了下一次 `/data` 请求。Chromium 的 netlog 没有写出文件。
- 点击页面上的「重新获取」。
- 两个管理员会话同时看监控。
- 把页签放到后台后的降频。
- 拔掉或停掉 Netdata 后的失败态和过期态。没有为了测试停服务。

接口刷新和页面首次渲染测过，不能代替上面几项。

## 15. 风险和限制

- 字节显示会把 0.5 字节以上的小数四舍五入。这只影响展示。存储模块如果直接调用 `StorageSizeFormatter`，非整数字节仍然是「暂无数据」。
- Buffer Pool 的 8 TiB 门槛是为了分辨「单位写成 MiB、值仍是字节」。一台真有数 TiB Buffer Pool 的机器会需要再看这个判断。这台机器的池子是 128 MiB 上限。
- 告警仍依赖现有的关闭配置。打开健康检查会改变 Netdata 配置，这次没有做。
- 6 小时以上的历史图会带「短于所选范围」，因为库里的点不够 7 天。图上没有补 0。
- `drush cr` 已执行。PHP OPcache 的 `validate_timestamps` 是开的，Web 请求会在数秒内读到新代码。

## 16. 回滚方法

只回滚这次监控修复时：

- `MonitorBoard.php`、`ServerMonitor.php`、`monitor.js` 恢复到本任务之前的内容。
- `OpsFormat::bytes()` 恢复成直接 `return StorageSizeFormatter::formatBytes($bytes);`，不要把整个文件退回 Git HEAD。HEAD 里还是旧的 `ByteSizeMarkup`，退回去会连之前的容量单位改动一起取消。

不需要回滚数据库、cron 或 Netdata。修复没有写入这些地方。

回滚后内存容量、网络速度和 Buffer Pool 会回到这次看到的错误显示。流量历史不受影响。

## 故障清单

| 项目 | 检查内容 | 结果 |
| --- | --- | --- |
| Netdata 服务 | 服务、端口、API | PASS |
| CPU | 实时与每核 | PASS |
| 内存 | 容量、Active、Inactive | FIXED |
| Swap | 用量和读写 | FIXED |
| 磁盘 | 空间和读写 | FIXED |
| 网络速度 | RX/TX | FIXED |
| 累计流量 | 今日、本月 | PASS |
| MariaDB | 连接和 Buffer Pool | FIXED |
| Redis | 内存、命中率、命令 | FIXED |
| Docker | 容器 CPU 和内存 | FIXED |
| Nginx | 进程资源；请求数未采集 | FIXED |
| PHP-FPM | 进程资源；队列未采集 | FIXED |
| 历史图表 | 七个时间范围 | PASS |
| 告警 | 空接口和页面说明 | FIXED |
| 完整指标 | 搜索、分页、分类 | PASS |
| 采集器 | 列表和缺失项 | PASS |
| 前端 | 图表、分类、过期请求 | FIXED |
| 自动刷新 | 浏览器中的下一次请求 | NOT TESTED |
| 手动刷新 | 按钮 | NOT TESTED |

可以恢复日常查看。CPU、内存、Swap、磁盘、网络速度、流量合计、MariaDB、Redis 和容器内存都会显示实际采样。没有采集器的项目仍是「未采集」。
