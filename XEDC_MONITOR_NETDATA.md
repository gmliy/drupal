# XEDC 服务器监控：Netdata 接入说明

日期：2026-11-02

现有后台页面「服务器监控」已改为读取本机 Netdata。页面仍在自定义模块 `xedc_core` 里，路径、权限、卡片布局和菜单没有另做一套。没有采集到的指标显示「未采集」，不用 0 代替。

访问地址：`/admin/reports/xedc-monitor`  
数据接口：`/admin/reports/xedc-monitor/data`  
权限：`access xedc server monitor`（「查看服务器监控」，仅管理员）

## 1. 本机 Netdata

| 项目 | 结果 |
| --- | --- |
| 版本 | v2.12.2 |
| 服务 | `netdata.service` 运行中，开机自启 |
| 监听 | 只监听 `127.0.0.1:19999`，只允许本机连接 |
| 认证 | 没有单独的 API 密钥；浏览器拿不到地址和密钥 |
| 存储 | `dbengine`，采样间隔 2 秒 |
| 已保存跨度 | 约 22 小时（`system.cpu` 最早到最新采样；随 Agent 运行时间变化） |
| 配置保留 | tier 0 配置为 7 天，库里只有本次启动后的数据 |
| 图表 | 1759 张，3696 个维度（目录会随采集器变化） |

已启用的采集来源：`proc.plugin`、`diskspace.plugin`、`cgroups.plugin`、`apps.plugin`、`go.d/mysql`、`go.d/redis`，以及 Netdata 自身的 pulse/stats。

没有 Nginx stub_status、PHP-FPM status、CPU 频率、温度传感器或宿主机压力图表。

机器上的网卡是 `ens5`（物理）、`docker0` 和两块网桥（虚拟）。磁盘是 `nvme0n1`，挂载点是 `/`、`/boot`、`/run`。Docker 容器是 `matomo-matomo-1`（`matomo:5-apache`）和 `matomo-db-1`（`mariadb:lts`）。MariaDB 图表来自本机 MySQL 采集（Unix socket），容器资源在 Docker 分类里单独显示，两边不加总。告警接口当时是空的。

## 2. 数据怎么进来

浏览器只请求 Drupal 管理路由。PHP 在服务器上访问本机 Netdata 的只读 API：

- `/api/v1/info`
- `/api/v1/charts`
- `/api/v1/data`
- `/api/v1/alarms?all=1`
- `/api/v1/alarm_log`

图表目录缓存 90 秒。总览快照缓存 3 秒。实时轮询不带历史曲线；切换分类或时间范围时才取历史。历史点数大约 30 到 96，上限 120。一次分类请求最多读 80 张图，并行请求按 12 个一组，超时默认 4 秒。

地址只允许 `http://127.0.0.1` 或 `http://localhost`，默认 `http://127.0.0.1:19999`（配置项 `xedc_core.settings` 的 `netdata_base_url`）。图表 ID 必须出现在刚发现的目录里，不能提交任意 URL，也没有执行 Shell 的接口。JSON 响应带 `Cache-Control: no-store, private`。

请求失败时页面保留上一次成功的数值，并标明数据已过期。Netdata 连不上时总览走原来的未连接状态。

## 3. 页面分类

顶部保留服务器名、Netdata 版本、连接状态、最后更新时间、刷新间隔和自动刷新开关。分类是：

| 分类 | 刷新 |
| --- | --- |
| 实时总览 | 3 秒 |
| CPU、内存、网络 | 3 秒 |
| 磁盘 | 5 秒 |
| 系统、Nginx、PHP-FPM、MariaDB、Redis | 10 秒 |
| Docker | 8 秒 |
| 告警 | 15 秒 |
| 完整指标、采集器 | 60 秒 |

浏览器页签隐藏后，间隔提高到原来的 4 倍，并且不少于 30 秒。自动刷新开关记在本机 `localStorage`（`xedcMonitorAuto`）。

历史范围：1 分钟、5 分钟、15 分钟、1 小时、6 小时、24 小时、7 天。曲线来自 Netdata，不在 Drupal 里再存一份原始序列。没有另建告警表。

完整指标按 30 条分页，可按分类和关键字搜索。当前页才读取实时值。

数值状态：正常、推算、未采集、暂不可用、采集失败、已过期。空闲 CPU 是用 100% 减去已采集状态得到的，标成「推算」。写方向在 Netdata 里是负数，页面显示绝对值。网卡速率由计数器和采样间隔换算，单位从 kilobits/s 换成字节每秒。没有把速率积分成累计流量。

## 4. 已接入的指标

- CPU：总使用率、user、system、nice、iowait、irq、softirq、steal、空闲（推算）、4 个核心、负载 1/5/15 分钟、中断、上下文切换。
- 内存：总量、已用、可用、空闲、buffers、cached、active、inactive、Swap 总量/已用/空闲、Swap 读写、OOM 速率。System V 共享内存另有图表。
- 磁盘：每个挂载点的容量、已用、可用、使用率、inode；`nvme0n1` 的读写吞吐、IOPS、利用率、延迟、队列。物理盘、分区和挂载点分开，不重复加总。
- 网络：每块网卡的收发速率、数据包、错误、丢包、链路状态。全机 `system.net` 作为汇总。
- 系统：运行时间、负载、进程、运行/阻塞进程、文件描述符、中断、上下文切换、熵。
- Nginx、PHP-FPM：只有 `apps.plugin` 的进程 CPU、内存和进程数。连接数和请求速率没有对应图表。
- MariaDB：本机采集的连接、查询速率、慢查询、运行线程、InnoDB Buffer Pool、锁、网络流量。
- Redis：内存、峰值、客户端、阻塞客户端、命令速率、Key 数量、命中率、网络、bgsave。
- Docker：`matomo-matomo-1` 和 `matomo-db-1` 的状态、CPU、内存、内存限制、网络和磁盘。镜像为 `[none]` 的 cgroup 不当成容器。

## 5. 未采集

这些项在页面上标成未采集，并写明原因：

- CPU 实时频率、温度、宿主机压力
- Meminfo Shmem
- 网卡累计字节和累计数据包
- 带宽使用率（`ens5` 报告的速率为 0，没有可靠带宽）
- TCP 建立、失败、重传
- Nginx 活跃连接、Reading、Writing、Waiting、请求总量和请求速率
- PHP-FPM 活跃/空闲/最大进程、Listen Queue、慢请求
- 无法从现有计数器准确算出的 TPS
- Redis miss 次数（命中率图表存在，miss 计数不存在）
- 容器运行时间
- Netdata 原生告警（接口为空，没有另造告警，也没有另做 Drupal 阈值告警）

## 6. 代码

改动限制在监控相关文件：

- `web/modules/custom/xedc_core/src/Ops/NetdataClient.php`：回环地址校验、超时、并行读取
- `web/modules/custom/xedc_core/src/Ops/NetdataCatalog.php`：图表目录和分类
- `web/modules/custom/xedc_core/src/Ops/NetdataReader.php`：实时采样和历史序列
- `web/modules/custom/xedc_core/src/Ops/MonitorBoard.php`：分类页、采集器、告警
- `web/modules/custom/xedc_core/src/Ops/ServerMonitor.php`：总览、历史、刷新间隔
- `web/modules/custom/xedc_core/src/Controller/MonitorController.php`：页面和 JSON
- `web/modules/custom/xedc_core/templates/xedc-core-monitor.html.twig`
- `web/modules/custom/xedc_core/templates/xedc-core-monitor-detail.html.twig`
- `web/modules/custom/xedc_core/js/monitor.js`
- `web/modules/custom/xedc_core/css/ops.css`（只追加监控样式）
- `web/modules/custom/xedc_core/xedc_core.services.yml`（增加 `xedc_core.netdata_catalog`）

服务：`xedc_core.netdata`、`xedc_core.netdata_catalog`、`xedc_core.monitor`。

没有新建模块，没有改 Netdata 程序，没有给 PHP-FPM 提权，没有改 `xedc_ui_locale` 和 `xedc_auto_translate`。

## 7. 验证

- 无登录访问页面和数据接口均为 403，响应里没有指标，也没有 `19999`。
- 不存在的图表和带 `../` 的图表 ID 返回「图表不存在或未采集」。
- 非回环地址被拒绝。对本机一个不返回数据的端口，0.8 秒超时后失败。
- 重建缓存后的一段采样：124 次 Netdata 请求，0 次失败，累计耗时 541 毫秒。分类接口大约 19–60 毫秒。
- 总览实测示例：CPU 90.9%，内存 74.3%，根分区 63.6%，下行约 15.66 KB/s，上行约 4.45 KB/s。连续两次刷新 CPU 从 88.6% 变为 91.0%。
- Chromium 打开总览，四张卡片、四核、CPU 状态和四条历史曲线均为实测，每条约 60 个点。390 像素宽时卡片和分类改为单列。
- PHP 语法检查通过。

未在页面上单独截到的情况：没有为了测试停掉 Netdata；无头浏览器窗口保持可见，所以没有截到「后台降频」那一行。页签隐藏后的降频逻辑在 `monitor.js` 里。当时告警列表为空，警告、严重、已恢复没有真实条目可看。
