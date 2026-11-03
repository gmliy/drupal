# XEDC 性能审计

测量窗口：2026-11-03T15:22:04Z 至 2026-11-03T15:29:23Z。  
原则：没有测到的数字不写。维护模式开启期间，匿名内容页不是正常页面。

## 1. 前台

### 1.1 测到的 HTTP

源站通过 `https://gitxx.xyz`，响应里同时有 Nginx 与 Cloudflare。对本地 `127.0.0.1` 带 `Host: gitxx.xyz` 且不跟随重定向时，HTTP 是 301 到 `https://gitxx.xyz/`。带 TLS 直连 `127.0.0.1` 时，首页是 Nginx 直接返回的 Drupal 503，耗时约 71ms，不是 Cloudflare 边缘缓存的旧页面。

| URL | 结果 | 耗时 | 正文 | 缓存头 |
| --- | --- | ---: | --- | --- |
| `/user/login` | 200 | 约 205ms | 18845 字节 | 页面缓存 MISS；动态缓存 `UNCACHEABLE (poor cacheability)`；`Cache-Control: max-age=86400, public` |
| `/`、`/video`、`/articles`、`/search`、`/tags`、`/models` | 503 | 源站首页约 71–124ms | Drupal 维护页 HTML | `X-Drupal-Dynamic-Cache: UNCACHEABLE (503)`；`X-Drupal-Cache: UNCACHEABLE (response policy)`；`Cache-Control: max-age=3600, public`；这次 `cf-cache-status: DYNAMIC` |
| 猜测路径 `/photos` | 同样 503 | 不能据此判断别名是否存在 | | 图片列表的配置路径是 `meitu`，不是 `photos` |

登录页这一次不能外推到首页。动态缓存标记说明该响应带了不允许进入 Dynamic Page Cache 的上下文（用户表单的常见结果）。页面缓存头却给了 86400 秒。匿名登录页是否真的被 page cache 存住，只看到一次 MISS，没有第二次请求验证 HIT。状态：`NOT_TESTED` 第二次。

### 1.2 LCP、CLS、INP、请求数、图片体积

`NOT_TESTED`。没有浏览器性能轨迹，也没有在维护模式之外拿到内容 HTML。任务不允许为了测量去关闭维护模式或登录后写 session。

从代码和资源体积能确定的只有“会加载什么”，不是“用户看到的 LCP 是多少”：

- 每个使用主题全局库的页面都包含 `build/css/main.style.css` 193440 字节（磁盘 189KB）和 `build/js/main.script.js` 41592 字节。`xedc.info.yml` 第 19–20 行把 `xedc/style` 放进主题 `libraries`
- `xedc_library_info_alter()` 存在（`xedc.theme` 385 行），注释写明会去掉 jQuery。是否在所有页面生效：`NOT_TESTED`
- 视频播放库另加 `js/hls.min.js` 619692 字节、`js/plyr.min.js` 120871 字节、`css/plyr.css` 32612 字节
- 图片样式在配置里存在多档宽度。懒加载和 `srcset` 是否输出，要看具体 Twig 和核心 responsive image，本次没有渲染 HTML，`NOT_TESTED`
- CDN 响应头没有对一张内容图取样。`NOT_TESTED`

### 1.3 缓存设计（代码和配置，不是命中率）

- `system.performance` 的 page max-age 为 86400，CSS/JS preprocess 为开
- Redis 默认缓存。采样命中率约 90.7%（1240639 / (1240639+127699)）。这是 Redis 进程启动以来的累计值，不是某个页面的命中率
- Views `image_gallery`、`video_list`、`article_list`、`taxonomy_term`、`xedc_home` 使用 `xedc_bundle_tag`。插件在 `BundleTag.php` 把 bundle 的 cache tag 并进结果。这是合理的失效粒度，本次没有看某个 view 的 render cache 是否命中
- 主题 preprocess 给视频分类计数加了 `node_list:video`（`xedc_core.module` 908 行）。计数本身是一次 `entityQuery` count
- 登录页动态缓存不可缓存，见上表

### 1.4 前端代码里和性能相关的事实

- `progress.js` 在导入进度元素上每 3 秒 `fetch` 一次，失败则 5 秒。元素使用 `once('xedc-import-progress')`，不会在同一次 attach 里重复绑定。访问日志里多个 `/admin/content/image-imports/{id}/progress` 各有几十到上百次，与这个间隔相符
- `files.js` 使用 `setInterval` 轮询 `statusUrl`。间隔要看该文件 20 行之后的具体毫秒数；调用点在文件统计页
- `monitor.js` 见后台一节
- `member.js`、`engagement.js`、`photo-detail.js` 没有在本次用浏览器检查重复监听。静态阅读：`engagement` 库依赖 `core/once`；`member.js` 的 document 级监听在 `DOMContentLoaded` 上挂一次
- 没有发现自定义代码里的同步 `XMLHttpRequest`
- 全局库里的脚本没有 `defer`/`async` 的库声明（`xedc.libraries.yml` 的 `main.script.js` 未写 header 以外的属性）。Drupal 默认把库 JS 放在 footer。是否阻塞首屏：`NOT_TESTED`
- 暗色、桌面、移动：视频相关 CSS 用同一文件里的 `@media` 与 `:is([data-bs-theme=dark],[data-theme=dark])`。`xedc-tags.js` 监听 `(min-width: 768px)`。没有测布局抖动

### 1.5 查询与 N+1

没有在页面请求上打开数据库查询日志（那需要改服务或装模块）。能从代码指出的查询点：

- `xedc_core_preprocess_taxonomy_term()` 901–907 行：每个视频分类页一次 count 查询，带 cache tag，不是循环内查询
- `_xedc_warm_view_nodes()`：列表预加载，方向是减少 N+1
- `HomeCards.php` 124 行：首页卡片用一条聚合 SQL，而不是逐节点查询。渲染是否走到这里：`NOT_TESTED`
- `GalleryMetrics.php` 164–189 行：文件统计页多条 `COUNT`/`SUM`，针对管理页
- `TakeoverReadiness.php` 484 行：`SELECT uri, filemime, filesize FROM file_managed` 全表。322 行，当前代价小，行数上去以后会涨

慢查询日志不能用来描述今天的前台。见第 4 节。

## 2. 后台

管理页面的计时是 `NOT_TESTED`（需要登录会话）。下面是代码、访问日志和错误日志。

### 2.1 访问日志里最频繁的路径

来自当天 `/var/log/nginx/access.log` 的路径计数（含静态资源与 403/404，不是纯页面时间）：

| 次数 | 路径 |
| ---: | --- |
| 251 | `/admin/reports/xedc-monitor/data` |
| 240 | `/contextual/render` |
| 211 | `/` |
| 118 | `/modules/contrib/statistics/statistics.php` |
| 114 | `/admin/content/image-imports/167/progress` |
| 94 | `/node/add/photo` |
| 90 | `/video` |
| 90 | `/admin/content/image-imports/157/progress` |
| 80 | `/media-library` |
| 63 | `/admin/content/image-imports` |

状态码：200 共 4712，403 共 1071，404 共 979，500 共 32，503 共 17（其中一部分是本次审计自己的请求）。

`contextual/render` 与 `statistics.php` 是核心/contrib 的附加请求。统计 JS 已被主题替换成 `statistics-lite.js`，访问日志仍出现原路径 118 次。替换是否覆盖全部页面：日志说明至少有请求打到原文件。`NOT_TESTED` 这些请求来自哪种主题。

### 2.2 监控

`ServerMonitor::SECTIONS` 总览刷新间隔 3 秒（`ServerMonitor.php` 29 行）。`snapshot()` 在成功时把缓存到期设为现在加 3 秒（81 行）。浏览器 `delay()` 也是 `max(3, refresh) * 1000`（`monitor.js` 195 行）。结果是：页面几乎每次轮询都赶上缓存过期，然后 `buildSnapshot()` 再问 Netdata。

隐藏标签时间隔至少 30 秒，这一点会降低后台标签的压力。

当天 251 次 data 请求若来自一个开着的监控页，大约对应十几分钟到几小时的停留，取决于中间有没有 403 或暂停。日志没有请求耗时字段（`log_format main` 不含 `$request_time`），所以没有 TTFB。

Netdata 进程 RSS 约 68MB，另有 go.d 与 apps 插件。

### 2.3 导入与上传

- 进度轮询 3 秒，多个任务 ID 同时出现在日志里（157、158、166、167、169）
- 队列仍留着 `drupal_batch:166` 到 `172` 各 20 行，以及 `199` 的 2 行
- PHP-FPM `pm.max_children=5` 在 11:25 和 14:41 打满，全天日志累计 20 次警告
- Nginx `client_temp` 权限错误与 `DirectoryNotReadyException` 见主报告 OPS-4、BUG-1。失败请求本身也占用 worker
- `ImportProcessor::processBatch()` 持有锁 `xedc_import:{id}`。没有测锁等待时间

### 2.4 文件分析

`FileAnalysisRunner::cron()` 在自动开关打开时每 86400 秒启动一轮，批次进行中会入队。最新 16 个批次状态都是 `done`。扫描表有数千到一万行，对当前 322 个文件来说工作集已经大于文件数，因为多批次历史还在。

`finish()` 247 行对 `file_managed` 做 `COUNT` 和 `SUM(filesize)`。322 行可忽略。

未使用文件判断如果只看 `file_usage`，会和字段引用不一致。这是正确性问题和性能问题叠在一起：扫错来源会反复产生“未使用”结果。见主报告第 8 节。

### 2.5 大表单

节点编辑、媒体库、Views 批量操作没有计时。`ContentEntityOperations` 的注释写明内容列表原先每个请求渲染五十个操作菜单，所以换成了可缓存的 handler。这是已落地的优化，效果数字 `NOT_TESTED`。

`/node/add/photo` 当天 94 次，说明编辑页在被使用。没有表单构建耗时。

## 3. 服务器

采样约 15:22–15:29 UTC。

| 资源 | 值 |
| --- | --- |
| 负载 | 3.35–3.50，4 个逻辑 CPU |
| 内存 | 7.6GiB，available 约 2.7GiB |
| Swap | 2.0GiB 中已用 1.0GiB。`vmstat` 1 秒采样 `si/so` 只有个位数，不是持续疯狂换页，但 swap 已占用说明此前压力超过物理内存空闲 |
| MariaDB RSS | 约 232MB，另有一个 219MB 的 `mariadbd` 行（`ps` 里两条，可能是线程显示重复，没有用 `ps -L` 拆开）。`systemctl show mariadb` 的 `MemoryCurrent` 约 227MB |
| PHP-FPM | 上限 5，服务内存约 217MB |
| Nginx | 服务内存约 18MB，4 个 worker |
| Redis | 53MB / 64MB，策略 `allkeys-lfu`，淘汰 0，拒绝连接 0 |
| Netdata | 主进程约 68MB |
| Docker | Matomo 与其数据库、恢复用数据库合计约 76MB（stats 采样）。Matomo 容器内存上限显示为 7.55GiB，那是容器能看到的主机内存，不是限额配置已被读到。没有检查 compose 的 `mem_limit` |

磁盘 I/O：`vmstat` 两拍里 `bi/bo` 最高约 372 / 3724，单次采样，不能当全天均值。`iowait` 在这一拍是 0。

日志体积：Nginx access 当天 2.1MB，昨天 2.6MB，没有异常膨胀。MySQL 慢日志 19KB。PHP-FPM 日志主要是启动通知和 max_children 警告。

两个 MariaDB 同时存在：宿主机实例，加上 Docker 里的 `xedc-recover-db` 和 `matomo-db-1`。恢复库限制 512MiB、实际 61MiB。这是内存常驻成本。

### 索引与慢查询

自定义表的二级索引清单已在扫描输出中核对，导入、点赞、文件引用、AI、流量表都有与 WHERE 条件对应的索引。没有把“缺索引”写成事实。

`slow_query_log=ON`，`long_query_time=1`，输出到文件。32 条记录，最慢的 15 条时间从 41.16s 到 2.07s。日志开始标记是 `261101 23:06:17`（2026-11-01）。查询形态包括：

- `locales_target` / `locales_source` 一类的语言加载（各出现 7 次的两条形态）
- `SELECT SQL_NO_CACHE ... FROM cache_page`、`cache_render`、`cache_dynamic_page_cache`、`cache_discovery`、`cache_default`。`SQL_NO_CACHE` 加整表缓存读取，更像备份或迁移，不像页面请求
- 菜单树、路径别名、实体加载

这些数字不能写成“首页有一条 41 秒 SQL”。状态：历史慢查询存在；与 11 月 3 日页面的对应关系 `NOT_TESTED`。

### 缓存分层

Redis 在干活。MariaDB 的 `cache_*` 行自 2026-11-01 15:36 起没有新的 `created`。库总体 149.9MB 里，页面、动态页、render、discovery 四张表就超过 57MB。它们是切换缓存后端之后留下的。截断是缓存操作，本阶段没有做。

风险：`settings.php` 注释说 bootstrap、config、discovery 仍使用 ChainedFast。若 ChainedFast 的一致后端仍是数据库，这三张表停更可能表示快速后端已经吃掉读取，数据库副本变陈旧；也可能表示一致后端也已经不是这些表。本次没有读 ChainedFast 的运行时类配置到“下一次写入会落在哪”。在确认之前不能把 `TRUNCATE cache_bootstrap` 当成无风险。

Redis 64MB 已经用到 53MB。`allkeys-lfu` 在写满后会淘汰任意键。当前淘汰次数是 0，所以还没有发生。把 `maxmemory` 提到几百 MB 在这台 7.6GB 的机器上做得到，但会和已使用的 1GB swap、MariaDB、FPM、Matomo 抢内存。按 2GB 机器来规划则不应该加大。

## 4. 和 CDN 的界限

首页 503 的 `cf-cache-status` 是 `DYNAMIC`，`Server` 先是 Cloudflare（经公网）或 Nginx（直连源站）。源站 71ms 是 Drupal 生成维护页的时间，不是 CDN 回源命中，也不是图片 CDN。

登录页 205ms 含 TLS 与 Cloudflare。没有 `Server-Timing` 把 PHP 时间拆出来。不能把 205ms 写成 PHP 执行时间。

图片 CDN 主机名出现在登录页 CSP 的 `img-src`（`https://cdn.gitxx.xyz`）。没有请求一张 CDN 图片，因此没有图片 TTFB。

## 5. 性能结论分级

| ID | 结论 | 证据等级 |
| --- | --- | --- |
| PERF-1 | 聚合 CSS/JS 占 684MB，时间跨度从 10 月 12 日到 11 月 3 日 | 磁盘实测 |
| PERF-2 | 监控 3 秒刷新与 3 秒缓存 TTL 对齐，data 接口当天 251 次 | 代码行号 + 访问日志 |
| PERF-3 | 全局 Bootstrap CSS 189KB + JS 41KB | 文件大小 + `xedc.info.yml` |
| PERF-4 | 162 行残留 batch，进度接口仍被轮询 | 队列表 + 访问日志 |
| PERF-5 | Redis 64MB 上限，已用 83%，尚未淘汰 | `INFO` |
| PERF-6 | Swap 已用 1GB，FPM 子进程上限被打满 20 次 | `free`、FPM 日志 |
| PERF-7 | 11 月 1 日慢查询最长 41 秒，含语言表和整表 cache 读取 | 慢日志。未绑定到今天的页面 |
| PERF-8 | 视频页 HLS 脚本 620KB | 文件大小。页面是否加载 `NOT_TESTED` |
| PERF-9 | 数据库 cache 表自 11 月 1 日 15:36 后无新行，仍占数十 MB | `created` 聚合 |

前台 LCP/CLS/INP：`NOT_TESTED`。  
后台页面 TTFB：`NOT_TESTED`。
