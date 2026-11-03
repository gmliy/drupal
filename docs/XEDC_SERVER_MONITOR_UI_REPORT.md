# 服务器监控中心 UI 改造报告

日期：2026-11-02

页面：`/admin/reports/xedc-monitor`  
数据接口：`/admin/reports/xedc-monitor/data`  
模块：`xedc_core`  
验收环境：https://gitxx.xyz ，Chromium headless，已登录管理员会话。

本次只改监控页的模板、样式和页面脚本。Netdata 接入、累计流量采集、数据表、Cron、路由、权限和 JSON 字段没有改。

## 1. 改造前页面结构

页面仍由 Drupal 后台路由 `xedc_core.monitor` 渲染，库为 `xedc_core/ops`（`css/ops.css`、`js/monitor.js`）。同一套 CSS 也给文件管理页使用，类名是 `.xedc-ops`。

改造前监控页已经具备这些区域：

- 页头：连接状态、自动刷新、刷新间隔、手动刷新。
- 主机元信息：主机名、系统、内核、运行时间、采样时间、存储模式、保留时长、Netdata 版本。
- 分类导航：实时总览、CPU、内存、磁盘、网络、系统、Nginx、PHP-FPM、MariaDB、Redis、Docker、完整指标、采集器、告警。
- 时间范围：1 分钟、5 分钟、15 分钟、1 小时、6 小时、24 小时、7 天。
- 总览四张卡片：CPU 使用率、内存使用率、磁盘使用率、网络速度，以及核心占用、CPU 状态、服务、负载、磁盘 IO、内存、网卡。
- 各分类的指标组和历史图。
- 网络分类下的累计流量：今日/本月下载与上传、7/30/90 天、每日记录、网卡、完整性状态。
- 完整指标目录：搜索、分类、分页、当前页数值。
- 采集器目录。
- 告警空状态。

数据由 `MonitorController` 调用 `ServerMonitor`，网络分类再附加 `TrafficReport`。浏览器不访问 Netdata。

## 2. 改造后的页面结构

路由和区块没有新增，也没有第二套监控页。信息顺序调整为：

1. Dashboard 页头：标题「服务器监控」、说明「实时监控服务器运行状态与资源使用情况。」右侧为在线状态点、Netdata 版本、更新时间、自动刷新开关、间隔、重新获取。
2. 主机元信息卡片。
3. 横向分类导航。
4. 时间范围分段控件。
5. 四张资源总览卡片，带线性图标。
6. 当前分类内容、历史图、累计流量、完整指标、采集器、告警。

分类切换仍走原来的 `data-section` 点击和 `loadHistory()`。完整指标和采集器在客户端切换时，用接口里已经返回的 `browser`、`collectors` 画出来，不再在切换时把服务端渲染的目录清掉。搜索和分页仍是原来的 GET 链接，不新增请求。

## 3. 视觉规范

默认浅色，沿用后台主题变量，不另做主题开关。

- 页面背景 `--xedc-admin-background`（约 `#F7F7F8`）。
- 卡片 `--xedc-admin-surface`（白色），边框 `--xedc-admin-border`，圆角 12px，阴影使用 `--xedc-admin-shadow`。
- 文字 `--xedc-admin-foreground`，次级文字 `--xedc-admin-muted`。
- 数字 `tabular-nums`。
- 深色模式继续使用 `html.xedc-admin-active[data-theme="dark"]` 和 `localStorage` 键 `xedcAdmin.theme`。
- 图表描边只在浏览器里按系列上色：CPU 蓝、内存紫、磁盘橙、下载蓝、上传绿。接口里的 `color` 字段保持原值。
- 状态标签使用现有 success / warning / danger 徽章色。完整为绿，采样偏疏为黄，有缺口、失败、过期为红。
- 图标是 16px CSS mask，只用于四张总览卡，没有引入图标库。
- 新样式都写在 `.xedc-ops[data-xedc-monitor]` 下。文件管理页的 `.xedc-ops` 不套用这套规则。
- `prefers-reduced-motion: reduce` 时去掉图表加载渐变动画。

没有做小型趋势图。现有总览接口没有对应序列，不能用历史图裁一段冒充。

## 4. 修改文件

- `web/modules/custom/xedc_core/templates/xedc-core-monitor.html.twig`
- `web/modules/custom/xedc_core/templates/xedc-core-traffic.html.twig`
- `web/modules/custom/xedc_core/css/ops.css`
- `web/modules/custom/xedc_core/js/monitor.js`

未改：`MonitorController.php`、`ServerMonitor.php`、`MonitorBoard.php`、`NetdataClient.php`、`NetdataCatalog.php`、`NetdataReader.php`、`src/Ops/Traffic/`、路由、权限、服务定义、`xedc_core.install`、Cron、Netdata 配置、`xedc_ui_locale`、`xedc_auto_translate`、Gin / Radix。

`ops.css` 约 16 KB，`monitor.js` 约 37 KB。仍是原来的 Drupal library，没有新的 npm 包。

这些文件在本次之前已有未提交改动。本次没有 `git add`、提交或推送。

## 5. 白色主题验收

Chromium 实测，卡片背景 `rgb(255, 255, 255)`，文字 `rgb(39, 39, 42)`，`color-scheme: light`。

- 1440：四列资源卡，页头工具在右侧，分类一行，图表区两列。
- 1280：标题单行（高度 33px），页面无横向溢出。
- 768：标题和说明横向排开，工具换行，资源卡两列，分类可横向滚动。修正过一版：窄容器里中文标题被挤成逐字竖排，现用容器查询在监控区宽度不足 920px 时让页头改为上下排列。
- 390：单列，页面 `scrollWidth` 与 `clientWidth` 差为 0。

网络分类可见今日下载、今日上传、本月下载、本月上传。今日为「完整」，本月为「有缺口」。缺口来自采集开始日期，不是样式把缺失画成 0。

完整指标页：1759 个图表，第 1 / 59 页，搜索框、分类、表格和下一页都在。

## 6. 深色主题验收

通过现有 `xedcAdmin.theme=dark` 切换，不新增开关。

- 1440 与 390：卡片背景 `rgb(32, 32, 36)`，文字 `rgb(228, 228, 231)`，`data-theme=dark`。
- 页头、元信息、资源卡、导航选中态、分段控件跟随同一套变量。
- 图表坐标轴颜色读 `--ops-chart-axis`，深色下是边框色，不是写死的白底。
- 搜索框、表格表头、状态标签使用 `--xedc-admin-surface`、`--xedc-admin-table-head` 和徽章变量。

未单独打开图表 Tooltip 浮层。Tooltip 文字颜色同样走监控容器的前景色和表面色变量。

## 7. 桌面端截图

截图在 `/tmp/xedc-monitor-ui/`。仓库里没有既定的监控截图目录，因此没有把图片提交进项目。

- `desktop-1440-light.png`
- `laptop-1280-light.png`
- `network-1440-light.png`
- `browser-1440-light.png`
- `alarms-1440-light.png`
- `desktop-1440-dark.png`

1440 总览可见 CPU、内存、磁盘、网络四卡，以及 CPU 0–3。网络页可见四张累计流量卡和 7/30/90 天切换。完整指标表头为图表、分类、单位、维度。

## 8. 手机端截图

- `tablet-768-light.png`
- `mobile-390-light.png`
- `mobile-390-dark.png`

768 和 390 的页面级横向溢出都是 0。分类导航在容器内滚动，右侧分类被裁切是导航条滚动，不是整页溢出。390 上资源卡和元信息为单列。768 上网络速率在两列卡片里会换行，数值没有被裁掉。

## 9. 功能回归

| 项目 | 结果 |
| --- | --- |
| 页面加载，管理员可见 | 通过。标题、已连接、Netdata v2.12.2 |
| CPU / 内存 / 磁盘 / 网络实时值 | 通过。总览四卡有百分比、容量和上下行速率 |
| 累计流量 | 通过。四张卡、完整性标签、7/30/90、每日表。接口含 `traffic` |
| 历史范围 | 通过。7 个范围按钮。点击 5 分钟后 `aria-pressed=true` |
| 分类 | 通过。总览、CPU、内存、磁盘、网络、系统、Nginx、PHP-FPM、MariaDB、Redis、Docker、完整指标、采集器、告警均切到对应内容 |
| 自动刷新与手动刷新 | 通过。开关和「重新获取」仍在。间隔仍按分类返回，总览为 3 秒，完整指标为 60 秒 |
| 完整指标搜索、分页 | 通过。GET 表单 `q`、分类下拉、30 行、下一页。客户端从总览点进完整指标后同样出现 |
| 采集器 | 通过。切换后 25 行，「已发现采集器」 |
| 告警 | 通过。空状态文案在，没有伪造告警 |
| 多网卡切换 | 跳过。当前只有物理网卡 ens5，页面不渲染切换器 |
| 未采集 | 通过。Nginx、PHP-FPM 显示「未采集」，不是 0 |
| 失败 / 已过期 | 未单独注入故障。脚本仍保留 `data-xedc-stale` 和上次成功时间 |
| 后台页签降频 | 未在无头浏览器里把页签标为 hidden。代码仍用 `document.hidden` 把间隔显示为「后台 N 秒」并拉长等待 |

客户端切换分类时，`renderDetail()` 原先只画指标组和告警，会丢掉接口里的完整指标和采集器。这次在同一响应上补上这两块的 DOM，字段和分页链接与 Twig 一致。没有新接口。

## 10. API 改造前后对比

URL 仍是 `/admin/reports/xedc-monitor/data`。

总览顶层字段：`agent`、`cards`、`connection`、`cores`、`cores_note`、`cpu_rows`、`detail`、`error`、`hostname`、`interfaces`、`io`、`kernel`、`load`、`memory`、`memory_mode`、`nav`、`ok`、`os`、`processes`、`refresh_seconds`、`retention`、`section`、`services`、`totals_note`、`updated`、`uptime`。

四张卡名称仍是 CPU 使用率、内存使用率、磁盘使用率、网络速度。

网络分类仍有 `traffic.cards`：今日下载流量、今日上传流量、本月下载流量、本月上传流量。`span=7`，`iface=ens5`。响应里没有 `/sys/`。

CPU 历史图 `charts.s0.lines[].color` 仍是 `#171717`、`#52525b` 这类原值。页面上的蓝、紫、橙色只在 `chartColor()` 里画线，不回写 JSON。

`Cache-Control: no-store, private`。

三次管理员数据请求前后，`xedc_traffic_sample` 中 `ens5` 行数都是 60，页面刷新没有触发累计流量采集。

## 11. 权限测试

权限仍是 `access xedc server monitor`，路由名 `xedc_core.monitor` 与 `xedc_core.monitor_data`。

- 匿名访问数据接口：403。
- 新建无该权限用户 `xedc_ui_probe`（uid 297）访问数据接口：403，响应里没有指标。测试后已删除该用户。
- 管理员：200，能看到实时数据。

## 12. 性能检查

- 没有新的轮询、Cron 或数据采集请求。
- 自动刷新仍是 `loadLive()`，历史切换仍是带 `series=1` 的 `loadHistory()`。
- 分类刷新秒数仍来自接口：总览 3 秒，完整指标 60 秒。
- 实时刷新走 `patchDetail()` / `applyOverview()`，不每次重建图表 DOM。
- 图表颜色在原有 `draw()` 里计算，没有第二次初始化。
- 样式限定在 `[data-xedc-monitor]`，不改后台其他页面的 `body`、`table`、`button`。

## 13. 通过、失败、跳过、未验证

通过：浅色与深色下的页头、资源卡、分类、时间范围、四类实时指标、累计流量、历史图容器、完整指标、采集器、告警空状态、匿名与无权限拒绝、管理员访问、接口字段、刷新不采样、1440 / 1280 / 768 / 390 无整页横向滚动。

失败：无。

跳过：多网卡切换。主机只有 ens5。

未验证：图表 Tooltip 浮层的逐项对比；把浏览器页签真正设为后台后的降频；人为断开 Netdata 后的失败态和过期态。过期文案逻辑还在脚本里，这次没有制造故障。

## 14. 已知问题

- 后台主题会再输出一次路由标题「服务器监控」，所以页头上看起来有两行相同标题。路由标题属于既有后台主题，这次没有改。
- 本月流量标签是「有缺口」，因为累计统计从 2026-11-03（上海时区）才开始。这是数据完整性，不是界面把缺失显示成 0。
- 只有一块物理网卡，网卡切换控件不会出现。
- Nginx stub_status、PHP-FPM status 仍是「未采集」。
- 768 宽度下，网络速率两个数字会在卡片内换行。
- 成功的流量 Cron 不写日志。这与本次界面无关。

## 15. 回滚

不要对整个仓库执行 `git checkout` 或 `git reset`。上述四个文件里还有本次 UI 之前的未提交监控和流量改动。

只回滚本次界面时，撤掉：

- `ops.css` 末尾 `.xedc-ops[data-xedc-monitor]` 作用域及其容器查询。
- `monitor.js` 里的 `chartColor()`、`axisColor()`、`renderCatalog()`、`renderCollectors()`，以及 `renderDetail()` 对这两块的调用。告警面板条件可一并恢复为原来的 `if (payload.alarms)`。
- `xedc-core-monitor.html.twig` 页头状态点和四张卡的图标节点。
- `xedc-core-traffic.html.twig` 上的 `xedc-ops-pill` 类。

然后执行：

```bash
sudo -u www-data /usr/local/bin/drush cr
```

数据表、Cron、Netdata 和 JSON 不需要回滚，因为它们没有被这次改动碰到。
