# XEDC 网络流量统计报告

日期：2026-11-02

服务器监控页的「网络」分类增加了累计流量。实时收发速度仍来自 Netdata。累计字节来自物理网卡的 sysfs 计数器，由 Drupal 按相邻两次成功读数记账。页面没有把速率积分成总流量，也没有把虚拟网卡加进主机流量。

当前统计网卡是 `ens5`。它是唯一一块物理网卡，也是默认路由所在的网卡。`docker0`、网桥和 `veth` 只出现在说明里，不参与累计。

## 1. 修改的文件

模块内：

- `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficSchema.php`
- `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficDelta.php`
- `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficCollector.php`
- `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficReport.php`
- `web/modules/custom/xedc_core/src/Ops/Traffic/NicGuard.php`
- `web/modules/custom/xedc_core/src/Ops/Traffic/CounterSource.php`
- `web/modules/custom/xedc_core/src/Ops/Traffic/SysfsCounterReader.php`
- `web/modules/custom/xedc_core/src/Drush/Commands/TrafficCommands.php`
- `web/modules/custom/xedc_core/src/Controller/MonitorController.php`
- `web/modules/custom/xedc_core/src/Ops/MonitorBoard.php`（只改了网络说明，避免和累计统计互相矛盾）
- `web/modules/custom/xedc_core/templates/xedc-core-traffic.html.twig`
- `web/modules/custom/xedc_core/templates/xedc-core-monitor-detail.html.twig`
- `web/modules/custom/xedc_core/js/monitor.js`
- `web/modules/custom/xedc_core/xedc_core.install`
- `web/modules/custom/xedc_core/xedc_core.module`
- `web/modules/custom/xedc_core/xedc_core.services.yml`
- `web/modules/custom/xedc_core/drush.services.yml`
- `web/modules/custom/xedc_core/tests/src/Unit/TrafficDeltaTest.php`
- `web/modules/custom/xedc_core/tests/manual/traffic-self-test.php`

系统定时任务（不在 Git 仓库里）：

- `/etc/cron.d/xedc-traffic`

没有新建模块，没有改 Netdata 配置，没有改 `xedc_ui_locale`、`xedc_auto_translate` 和文件资产管理。没有提交 Git。

## 2. 数据表

`hook_update_10009` 已执行，创建了两张表。新安装模块时 `hook_schema()` 也会带上它们。

`xedc_traffic_sample`：每次采样一行。

| 字段 | 含义 |
| --- | --- |
| iface, sampled | 网卡和采样时间，唯一键 |
| rx_bytes, tx_bytes | 当时的计数器原值 |
| rx_delta, tx_delta | 相对上一次有效计数器的增量；失败、基线和重置为空 |
| gap_seconds | 与上一次采样的实际间隔 |
| status | baseline、ok、sparse、gap、cross_day、reset、failed |
| counted | 1 表示增量已计入当天 |
| detail, created | 说明和写入时间 |

索引：主键 `id`，唯一键 `(iface, sampled)`，以及 `sampled`。

`xedc_traffic_day`：按网站时区的日期汇总。主键 `(iface, day)`，另有 `day` 索引。月度数字是这个表的合计，不再把快照加一遍。

`rx_bytes` / `tx_bytes` 是已分配到当天的流量。`unallocated_rx` / `unallocated_tx` 是跨日间隔里的增量，能看见，但不算进当天或当月。

## 3. 采集

两条路径，页面刷新不走任何一条：

- `/etc/cron.d/xedc-traffic` 每分钟执行 `drush xedc:traffic-sample`
- 原有每 15 分钟的 `drush cron` 里，`hook_cron()` 再调一次，作为分钟任务中断后的后备

读的是 `/sys/class/net/{name}/statistics/rx_bytes` 和 `tx_bytes`。名字必须先符合接口名规则，路径必须落在该网卡自己的 statistics 目录下，并且 sysfs 设备路径不能包含 `/virtual/`。浏览器拿不到这个路径，也不能提交路径。

同一网卡用锁和 `(iface, sampled)` 唯一键防止并发重复记账。距离上一条快照不足 45 秒会跳过，不会假定间隔刚好是 60 秒。增量一律是两次计数器的差。

实测 `ens5` 从 18:00 起大约每 60 秒一条，间隔 60–61 秒，状态为 ok。成功采样不写日志，所以 `/var/log/xedc-traffic.log` 是空文件。

## 4. 网卡选择

`NicGuard` 只接受物理网卡：

1. 请求里的 `nic` 必须出现在当前物理网卡列表中，否则忽略。
2. 否则用 `/proc/net/route` 里的默认路由，且该网卡必须是物理网卡。
3. 否则用唯一的物理网卡。
4. 有多块物理网卡时，页面提供切换，一次只显示一块，不相加。

这台机器的结果是 `ens5`。`nic=../../etc/passwd` 的请求仍返回 `ens5`，响应里没有路径。

## 5. 今日、本月和历史

时区使用站点配置 `Asia/Shanghai`。日期边界和页面上的采集时间都按这个时区。

- 同一天、间隔不超过 180 秒：计入当天，完整性为完整。
- 同一天、180 秒到 6 小时：计入当天，标成采样偏疏。
- 同一天、超过 6 小时：计数器差值仍然计入当天，标成有缺口。
- 两次采样跨过本地零点：差值记在后一天的未分配字段，不拆进两天，页面说明未分配流量。
- 读取失败：写一条失败记录，不把流量改成 0，已有合计保留。
- 计数器变小：视为重启或重置，差值为空，下一次从新基线计算。
- 换网卡：按网卡名分开。新网卡从基线开始，旧网卡的日汇总保留。

本月是本月已有日汇总的合计。统计开始日晚于月初时，月份标成有缺口，并写明从哪一天开始。没有记录的日期显示「未采集」，不用 0 填充。

单位按 1024 进制：B、KB、MB、GB、TB。页面写明 `1 KB = 1024 B`。入站是服务器接收（下载），出站是服务器发送（上传）。

趋势有最近 7、30、90 天，每天一个点，下载和上传两条线。历史表可按日期查看。

## 6. 重启和计数器重置

新计数器小于旧计数器时不产生负数，这一次不计入流量，并把新读数当成基线。服务器重启后 sysfs 计数器从较小的值重新开始，走的是同一条规则。自测用 9000 降到 5，确认当天合计只保留重置前已经计入的 100 字节，加上重置后的 20 字节，合计 120，没有出现负数。

## 7. 保留和清理

原始快照保留 90 天。每日汇总不删。

清理在采集任务末尾进行，每次最多处理 40 个过期日。只有当天快照合计和日汇总一致时才删除快照。不一致就留下快照并记一条警告。已经删过的日期不再报警。清理不碰文件资产表。

## 8. 权限和安全

路由仍是 `access xedc server monitor`。JSON 为 `Cache-Control: no-store, private`。

| 检查 | 结果 |
| --- | --- |
| 匿名访问页面和 `/data` | 403，正文没有流量数字，也没有 `ens5` |
| 新建的普通用户 `xedc_traffic_audit` | 403。测试后已删除该用户 |
| 管理员 | 200 |
| `nic` 路径穿越 | 仍只返回 `ens5`，JSON 中没有 `/sys/` 和 `passwd` |
| 页面连刷 3 次 `/data` | `ens5` 快照条数保持 3，没有因为刷新而采集 |
| Shell | 没有新增执行命令的接口 |

## 9. 自动化测试

`drush php:script web/modules/custom/xedc_core/tests/manual/traffic-self-test.php`：37 项通过，0 项失败。覆盖正常差值、非 60 秒间隔、失败不记账、45 秒内重复执行、两个进程并发、计数器重置、跨日、跨月、7/30/90 天长度、单位、缺失日期、快照清理，以及虚拟网卡和路径被拒绝。

并发结果是一个进程 `ok`、另一个 `skipped`，当天只增加了 4900 字节，没有加倍。

纯计算另跑了一次：137 秒间隔、重置、跨日、非法网卡名、多网卡选择、1.5 KB / 1.0 MB / 1.0 GB / 1.0 TB。通过。

环境里没有 PHPUnit 可执行文件，所以 `TrafficDeltaTest.php` 没有由 PHPUnit 启动。同一批断言已用 PHP 直接执行并通过。自测使用的 `zz*` 行已从两张表删除。

## 10. 浏览器

Chromium 无头模式使用管理员登录会话打开 `https://gitxx.xyz/admin/reports/xedc-monitor?section=network`。

桌面 1440 宽：原有顶栏、四张实时卡片和「网络」分类都在。其下是网络流量统计，四个累计卡片、7/30/90 天切换，以及「每日入站和出站流量」图。图例区分下载和上传。当时今日约 3.0 MB / 1.2 MB，本月同样数字并标成有缺口。图的最新一点是真实字节，不是随机数。

390 宽、加高窗口：服务器信息、分类和四张累计卡片都是单列。当时今日下载 29.4 MB、上传 5.2 MB，本月相同并标成有缺口。数字比桌面截图大，是因为分钟采集在两次截图之间又记入了真实流量。

页面 DOM 中有「未采集」（尚无记录的日期）和单位说明，没有 sysfs 路径。

## 11. 通过、失败和未验证

通过：

- 数据库更新 10009
- 采集、记账、跨日、重置、并发、清理
- 匿名和普通用户拒绝
- 管理员 JSON 与页面
- 刷新不触发采集
- 分钟 cron 实际写出 `ens5` 快照
- PHP 语法检查
- `drush cr`
- Chromium 桌面和 390 宽页面

未在浏览器里单独造出的状态：

- 采集失败和已过期。自测确认失败不会把合计写成 0，超过 5 分钟没有新的成功计数器会标成已过期。没有为了截图停掉采集。
- 页签隐藏后的降频。逻辑仍在 `monitor.js`：隐藏时间隔至少 30 秒或 4 倍。无头窗口保持可见，没有截到「后台」字样。
- 多块物理网卡的切换按钮。这台机器只有 `ens5`。选择函数已用 `ens5` / `ens6` 做过纯计算测试，没有在真机上看到第二块物理网卡。

没有失败项被记成通过。

## 12. 已知限制

- 累计从 2026-11-03（上海时区）的第一条基线开始。基线之前的流量无法补回，所以本月显示有缺口。
- 只有一天有数字时，7/30/90 天图里其余日期是断点，不是 0。
- 跨日采样的字节不分配到某一天。间隔很长时，当天合计仍然是计数器差值，但完整性不是「完整」。
- 分钟任务依赖 `/etc/cron.d/xedc-traffic`。删掉它之后，只剩每 15 分钟的 Drupal cron，间隔会被标成采样偏疏，字节仍然按计数器差值计入。
- 成功采样不产生日志。
- 带宽使用率仍取决于 Netdata 是否报告网卡速率。`ens5` 的速率为 0 时，实时速率卡片旁边的带宽使用率继续是未采集。

## 13. 回滚

1. 删除 `/etc/cron.d/xedc-traffic`。
2. 去掉 `xedc_core_cron()` 里的 `xedc_core.traffic` 调用，以及本次新增的服务、命令、控制器参数、模板和脚本。
3. 如需删掉已记入的流量：`DROP TABLE xedc_traffic_sample, xedc_traffic_day;`
4. `drush cr`

不需要回滚 Netdata，也不需要改文件资产数据。日汇总删除后无法从已清理的 90 天前快照恢复；目前快照都还在 90 天以内。
