# XEDC 后台 PHP 性能诊断报告

日期：2026-11-02  
站点：https://gitxx.xyz  
任务：XEDC Admin PHP Performance Optimization

本轮测量后台 HTML 的服务端时间和布局稳定性。CSS 聚合、FOUC 和 Inter 字体预加载已在 `XEDC_ADMIN_PERFORMANCE_REPORT.md` 里处理，本轮没有再改那些 CSS。该报告和仓库里已有的未提交改动都保留着。

测试用的是本机 Playwright 自带的 Chromium（`chromium-1248`），不是真实 Safari。手机尺寸是 390×664 的视口模拟。

## 1. 环境

| 项目 | 实测 |
| --- | --- |
| 系统 | Ubuntu，Linux 7.0.0-1014-aws，4 核，内存 7.6GiB（诊断时已用约 5.1GiB，可用约 2.4GiB） |
| Web | Nginx，站点 `gitxx.xyz`，PHP 请求交给 `/run/php/php8.5-fpm.sock` |
| Drupal | 11.4.11，Drush 13.8.0，安装配置 `standard` |
| PHP | 8.5.12。网站请求由 php8.5-fpm 处理，不是 CLI |
| MariaDB | 13.0.2-MariaDB-ubu2404-log |
| Redis | 8.10.3，监听 127.0.0.1:6379 |
| 管理主题 / 前台主题 | `xedc_admin` / `xedc` |
| 分支 | `main`，与 `origin/main` 相同（`0ba7199`） |

诊断开始时工作区已有大量未提交和未跟踪文件。没有执行 `git reset`、`git clean`、`git checkout` 覆盖，没有改 `config/sync`，没有执行 `drush cim`，没有改 contrib 或 Drupal 核心源码，没有删除内容或用户。

Matomo 在 `x.gitxx.xyz`，由 Nginx 反代到 `127.0.0.1:8088`，不走这个 PHP-FPM 池。PHP-FPM 只有 `www` 一个池。

## 2. 优化前性能基线

桌面 1440×900，每个页面连续 3 次。下表是中位数。冷缓存指浏览器 HTTP 缓存关闭；热缓存指浏览器缓存开着再访问。两组都是 Drupal 缓存已经热的状态。原始记录在 `/root/xedc-admin-php-performance/browse-before.json`。

热缓存中位数：

| 页面 | TTFB | FCP | LCP | CLS | 最差 CLS |
| --- | ---: | ---: | ---: | ---: | ---: |
| `/admin` | 254ms | 476ms | 476ms | 0.000 | 0.000 |
| `/admin/content` | 459ms | 700ms | 700ms | 0.043 | 0.046 |
| `/admin/structure` | 190ms | 320ms | 320ms | 0.000 | 0.000 |
| `/admin/content/media` | 425ms | 640ms | 796ms | 0.046 | 0.046 |
| `/node/add/photo` | 252ms | 396ms | 576ms | 0.020 | 0.020 |
| `/node/add/video` | 408ms | 572ms | 572ms | 0.019 | 0.045 |
| `/node/add/article` | 311ms | 580ms | 580ms | 0.046 | 0.072 |
| `/admin/structure/views/view/content` | 252ms | 420ms | 420ms | 0.833 | 0.833 |
| `/admin/structure/taxonomy` | 172ms | 368ms | 368ms | 0.324 | 0.324 |
| `/admin/structure/taxonomy/manage/tags/overview` | 354ms | 552ms | 552ms | 0.569 | 0.586 |
| 图片栏目术语页 | 245ms | 388ms | 388ms | 0.566 | 0.566 |

冷缓存中位数和热缓存接近：内容列表 TTFB 444ms，Views 编辑 CLS 仍是 0.833，分类列表 CLS 仍是 0.324。CLS 不靠浏览器缓存。

全部采样 HTTP 200。没有新增的 CSS/JS 4xx，没有页面 JS 异常。响应头一直是 `X-Drupal-Cache: UNCACHEABLE (request policy)`、`X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)`、`Cache-Control: must-revalidate, no-cache, private`。

## 3. OPcache

CLI 和 FPM 不是同一套配置。CLI：`opcache.enable_cli=Off`，`memory_consumption=128`，`interned_strings_buffer=8`。这些数字不能代表网站。

FPM 在修改前，由 `/etc/php/8.5/fpm/conf.d/99-drupal.ini` 和 php.ini 默认值合成：

| 项 | 修改前实测 |
| --- | --- |
| `opcache.enable` | 1 |
| `memory_consumption` | 192MB。已用约 113MB，空闲约 78MB，浪费 1.3MB（0.65%） |
| `interned_strings_buffer` | 8MB，`free=0`，已经写满 |
| `max_accelerated_files` | 20000。已缓存脚本 5816，哈希槽 8338 / 32531 |
| `validate_timestamps` / `revalidate_freq` | 1 / 2 秒 |
| `opcache.jit` | disable |
| 命中 | 626398 次命中，10086 次未命中，命中率 98.4% |
| 重启 | oom / hash / manual 都是 0 |

FPM 池 `www`：`pm=dynamic`，`max_children=5`，`start_servers=2`，`min_spare_servers=1`，`max_spare_servers=3`，`max_requests=500`。诊断时 3 个 worker，RSS 约 150–168MB。套接字队列是 0，没有积压。`pm.status_path` 和 slowlog 都没开。`memory_limit=512M`。5 个 worker 理论上限约 2.5GB，机器可用内存大约 2.4GB，当前没有排队，所以没有加大 `max_children`，也没有为了数字去开 slowlog。

改了什么：只把 FPM 的 `opcache.interned_strings_buffer` 从 8 调到 32。共享内存增加 24MB，整个 `www` 池一份，不是每个 worker 一份。脚本缓存还有 78MB 空闲，没有 oom restart，所以没有加大 `memory_consumption`。站点还有未提交代码，`validate_timestamps` 保持开启，避免改完 PHP 要手动重置缓存才能看到。

修改后（重启 FPM，并访问过后台页面）：

| 项 | 修改后 |
| --- | --- |
| interned buffer | 32MB。已用 15.7MB，空闲 17.8MB |
| 脚本缓存 | 已用约 102MB / 192MB，浪费 0.12% |
| 已缓存脚本 | 4309 |
| 命中 | 重启后重新计数：526119 命中，4745 未命中 |
| oom / hash restart | 0 |

8MB 在一次后台预热后就会不够（预热中途已经用到 14.8MB）。32MB 盖住了当前用量，还留有空闲。浏览器 TTFB 没有因此出现稳定下降，见第 14 节。这项修改去掉的是“字符串区已满”这个状态，不是一个已经测到的大幅度 TTFB 收益。

## 4. Redis

Redis 在跑。Drupal 模块 Redis 8.x-1.11 已启用，PHP redis 扩展已加载。`settings.php` 把默认 cache backend 设成 `cache.backend.redis`，前缀 `gitxx`，连接 127.0.0.1:6379。`services.yml` 只把 cache tag checksum 换成 Redis。lock 和 flood 仍是核心的数据库实现。

用反射看运行中的服务，bins 是：

| bin | backend |
| --- | --- |
| bootstrap、config、discovery | `ChainedFastBackend`：快层 APCu，一致层 Redis |
| data、default、entity、render、dynamic_page_cache、page、menu、toolbar、access_policy | `Drupal\redis\Cache\PhpRedis` |

APCu 在 FPM 里是开的（诊断时约 7528 条，命中远多于未命中）。所以 bootstrap / config / discovery 会先打 APCu，不会每次都走 Redis。

Redis 自身（任务结束时，计数从当天 15:07 服务启动累计）：

| 项 | 值 |
| --- | --- |
| 内存 | 11.78MB / 上限 64MB，策略 `allkeys-lfu` |
| key | 7294 |
| 命中率 | 181118 / (181118+23671) ≈ 88.4% |
| 驱逐 / 拒绝连接 | 0 / 0 |
| 碎片率 | 2.10 |

key 里能看到 `gitxx:render:`、`gitxx:dynamic_page_cache:`、`gitxx:config:`、`gitxx:entity:`、`gitxx:page:`。Redis 确实在存 Drupal 缓存。

后台 HTML 没有因此变快。登录请求被页面缓存拒绝（`UNCACHEABLE (request policy)`）。Dynamic Page Cache 在替换占位符之前就判定 `UNCACHEABLE (poor cacheability)`。原因见第 10 节。render bin 仍在 Redis 里，局部渲染缓存会用到；整页后台 HTML 不会。

没有把更多 bin 改到 Redis，也没有把 lock / flood 换掉。现有分工是对的：需要跨请求一致的缓存已经在 Redis，启动期热 bin 有 APCu。64MB 上限只用了约 12MB，没有驱逐。

## 5. MariaDB

| 项 | 值 |
| --- | --- |
| 版本 | 13.0.2 |
| 连接 | 当时 2，历史最大 4，`max_connections=151` |
| 慢查询 | 本次进程启动后 `Slow_queries=0`。`long_query_time=1`，日志开着 |
| Buffer pool | 128MB。读请求 1918987，磁盘读 2583，命中率 99.87% |
| 空闲页 | 4773 / 8112，池子没有被打满 |
| 临时表 | `Created_tmp_tables=12700`，其中磁盘 3496（27.5%） |
| `tmp_table_size` / `max_heap_table_size` | 各 16MB |

`/var/log/mysql/xedc-slow.log` 里 15:07 重启前有一批 1–40 秒的查询。`Rows_examined` 只有 1–24 行，时间几乎全在 `Lock_time`。那是锁等待，不是缺索引的全表扫描。重启后的计数是 0。没有改 MariaDB 配置：buffer pool 命中率已经很高，空闲页也多，加大池子没有对应的查询证据。磁盘临时表比例偏高，但没有一条后台列表 SQL 被证明卡在临时表上。

## 6. Views SQL

内容列表是视图 `content` 的 `page_1`：表格、每页 50 条、字段为批量操作、标题、类型、作者、状态、更新时间、语言、操作链接。排序是 `node_field_data.changed DESC`。

库里已发布的 `node_field_data` 行是 263，未发布 14。`node_field_data` 上已有 `node_field__changed(changed)`、`node__status_type(status,type,nid)`、`node__title_type(title,type)`。`path_alias` 上已有 `path_alias__path_langcode_id_status(path,langcode,id,status)`。

以 uid 1 执行这个视图，返回 50 行。查询形态是 `node_field_data` 连接 `users_field_data`，`status` 条件，按 `changed` 倒序，`LIMIT 50`。把查询对象直接拼进 `EXPLAIN` 时，占位符被收成了 `0`，那份执行计划不能当证据，所以没有根据它加索引。

同一请求里的数据库日志才是可用的：

| 页面 | 第一次 | 同一进程再请求一次 |
| --- | --- | --- |
| `/admin/content` | 226 条查询，SQL 合计 47ms，其中 path_alias 200 条。墙钟 382ms | 24 条查询，SQL 11ms，path_alias 0 条。墙钟 273ms |
| 标签术语页 | 159 条查询，SQL 40ms，path_alias 132 条 | 25 条查询，SQL 7ms |

path_alias 是逐条别名查找，第二次被别名缓存吃掉。SQL 总共只有几十毫秒，页面墙钟是它的数倍到十倍。单独计时视图渲染：带操作链接第一次 372ms、第二次 191ms；拿掉操作链接字段后稳定在约 105–107ms。操作链接大约占热渲染的 80ms。这 80ms 是访问检查和链接生成，不是一条慢 SQL。

没有加索引，也没有改视图配置。操作链接是后台功能，不能为了这 80ms 拿掉。

## 7. Drupal PHP 执行

用内核请求在 Dynamic Page Cache 做决定的时刻读缓存元数据（优先级 8，DPC 是 7，附件替换还没发生）：

| 页面 | max-age | 高基数 context | HTML 里已有 form token | 占位符 |
| --- | --- | --- | --- | --- |
| `/admin` | -1 | `user`、`session` | 无 | 14 |
| `/admin/content` | 0 | `user`、`session` | 有 | 14 |
| Views 编辑 | -1 | `user`、`session` | 有（4 个 token） | 14 |
| 分类列表 | 0 | `user`、`session` | 有 | 14 |
| 标签术语页 | 0 | `user`、`session` | 有 | 14 |

DPC 的自动占位条件是 `max-age <= 0`，以及 context `user`、`session`。上面每一页都至少踩中一条，所以整页不会进 Dynamic Page Cache。

`user` 和 `session` 来自 `xedc_admin_preprocess_page()`。用户名画在页头里，快捷方式也按当前用户取。内容列表、分类术语页的 `max-age=0` 来自页面上已经渲染出来的表单（批量操作、术语概览、拖拽权重）。Views 编辑页的 max-age 虽是永久，但 HTML 里已经有表单 token。

如果只删掉页面上的 `user` / `session`，Views 编辑页会变成可以进 DPC 的响应，同一权限的用户会共用同一份带 token 的 HTML。没有做这个修改。

菜单树：配置菜单第一次加载加访问检查约 50ms，结构菜单约 27ms；同一进程再来一次分别降到约 14ms 和 3ms。这是每次后台请求都会做的导航构建，但不是内容列表 400ms 的主体。

## 8. 自定义代码

查过 `xedc_core`、`xedc_admin` 主题、`xedc_member`、`xedc_ui_locale`、`xedc_ai` 里会在请求路径上跑的钩子。

- `xedc_ui_locale` 的 `QueueKick` 在 `KernelEvents::TERMINATE` 上，而且只在 state `xedc_ui_locale.kick` 有值时处理队列。当前这个 state 是空的，不进 TTFB。
- `xedc_core_preprocess_node()` 只在节点模板上。内容列表用的是字段行，不走它。
- 主题 `preprocess_page()` 每次重建导航，包括配置菜单和结构菜单的访问检查。见上一节的计时。没有在这里做文件扫描或统计汇总。
- 没有找到自定义 `hook_node_access` 或 `hook_entity_operation`。操作链接的成本在核心 Views。
- 若干管理页（对象存储、AI、文件清理）自己把 `max-age` 设为 0。那些不是这次测的内容列表。

没有因为“代码看起来重”去改这些模块。

## 9. 后台页面实际调用链

一次热的 `/admin/content`（同一 PHP 进程里的第二次）大约 273ms 墙钟，SQL 11ms。时间在 PHP：视图渲染、50 行的操作链接、主题导航。冷的第一次还有约 200 次别名查询，SQL 也只有 47ms。

`/admin` 第二次可以到约 77ms，`/admin/structure` 第一次就大约 139ms。内容列表慢，是因为这一页要渲染带表单和操作链接的 50 行，而且整页不能进 Dynamic Page Cache。

## 10. TTFB 根因

后台 TTFB 是 PHP 生成 HTML，不是 CSS 文件个数。这一点和上一份 CSS 报告一致，这次用查询日志和缓存头再次对上了。

内容列表比其他后台页慢，是因为：

1. 整页对 Dynamic Page Cache 不可缓存。表单 token 已经写进 HTML，`max-age` 为 0；主题又给整页加了 `user` 和 `session`。
2. 视图每次重新渲染 50 行。操作链接字段热渲染大约 80ms，第一次更慢。
3. 别名查找在缓存冷的时候会打出上百条查询，但 SQL 总时间仍然远小于 PHP。
4. 数据库没有成为这一页的墙钟瓶颈。Buffer pool 命中率 99.87%，这一页的 SQL 是几十毫秒。

没有安全的办法在不共用表单 token 的前提下把这一页放进 Dynamic Page Cache，所以 TTFB 没有用缓存开关去“优化”。

## 11. CLS 根因

布局偏移发生在文档加载之后约 300–800ms，和 CSS 文件到达的时间对不上。来源是 `PerformanceObserver` 的 `layout-shift`。

Views 编辑页，热缓存第一次，CLS 0.833，两段：

- 418ms，0.438：`.dropbutton-widget` 高度从 314px 收到 33px，`#edit-displays` 跟着动。
- 484ms，0.395：`#edit-display-settings` 再排一次。

分类列表 CLS 0.254–0.324：表格行从大约 79–225px 高收到大约 61px。标签页和图片栏目页更明显，行高从 147px 收到 61px，CLS 0.57 左右。内容列表也有同类的小幅行高变化，CLS 0.043。

原因是同一个机制，不是把 Views 的补丁抄到分类页。`dropbutton.js` 在行为执行时才给第一个之后的 `li` 加上 `secondary-action`，并插入箭头。主题原来的横向工具条样式写在 `html.xedc-admin-active.js` 上，而 Drupal 的 `js` class 要等页脚脚本。第一帧因此是竖排的全部操作，脚本跑完才变成 32px 的横条。分类表每一行都有这样一个操作按钮，所以行高一起变。

`html.html.twig` 里第一段内联脚本，在 CSS 之前，已经给根元素加上 `xedc-admin-js`。当时没有用它来固定操作按钮的第一帧。

分类术语页在按钮收好之后还剩一段：core `tabledrag.js` 在表前插入“显示行权重”，并把权重列设成 `display:none`。表的 y 下移约 38px，高度少约 38px。词汇列表上这段只贡献 CLS 0.022。标签页因为表更高，同一段移动贡献大约 0.12。`tabledrag-hide` 这个 class 是脚本加上的，初始 HTML 里没有，不能靠提前写的 CSS 把权重列藏掉。没有改核心 tabledrag。

## 12. 实际修改

### OPcache interned strings

问题：FPM 的 8MB interned string 区 `free=0`。  
证据：`opcache_get_status()`，以及调到 32MB 后同一次预热用到 14.8MB。  
修改：`opcache.interned_strings_buffer = 32`。  
文件：`/etc/php/8.5/fpm/conf.d/99-drupal.ini`。  
备份：`/etc/php/8.5/fpm/conf.d/99-drupal.ini.bak-2026-11-02-php-perf`。  
风险：多占 24MB 共享内存。没有动 `memory_limit`、子进程数、时间戳校验。  
验证：`php-fpm8.5 -t` 通过，重启后 `interned_buffer=33554432`，首页和后台页面返回正常。  
回滚：用备份覆盖 ini，再 `systemctl restart php8.5-fpm`。

### 操作按钮第一帧

问题：Views 编辑 CLS 0.833，分类列表 CLS 0.324，标签页 CLS 0.57。第一帧竖排，脚本之后变成横条。  
证据：上面的 layout-shift 源。  
修改：操作按钮的闭合样式从 `html.xedc-admin-active.js` 改到 `html.xedc-admin-active.xedc-admin-js`，让头部内联脚本的 class 在第一帧就生效。在 `dropbutton.js` 还没标 `secondary-action`、还没插入箭头时，只显示第一项，并用 32px（窄屏 44px）的 `padding-right` 留出箭头宽度。脚本加上 `dropbutton-multiple` 之后，这条预隐藏规则不再匹配，原来的 `.secondary-action { display: none }` 和箭头规则接管。只有一个操作的按钮不会被加 padding。  
文件：`web/themes/custom/xedc_admin/css/admin-ui.css`。  
风险：无 JavaScript 时，多操作按钮在第一帧只看见第一项。后台 Views、分类拖拽本身就依赖脚本；脚本执行后菜单仍可打开。  
验证：见第 14、15 节。Views 菜单能打开“分析视图 / 重复的视图 / 对显示重新排序 / 删除视图”。分类菜单能打开编辑、字段、翻译、删除。  
回滚：把该文件里 `xedc-admin-active.xedc-admin-js` 的操作按钮选择器改回 `xedc-admin-active.js`，并删掉“第一帧只留第一项和箭头空位”那几条规则。不要用 `git checkout` 还原整个 `admin-ui.css`，这个文件在本轮之前就有未提交改动。然后 `drush cr`。

本轮执行过一次 `drush cr`，让聚合 CSS 重新生成。没有执行 `drush cim`。

没有改的项，以及原因：

- 没有加大 OPcache 脚本内存、没有关 `validate_timestamps`、没有开 JIT。
- 没有改 FPM `max_children` 和 slowlog。
- 没有改 Redis bin、MariaDB 配置。
- 没有加索引。
- 没有把后台页放进 Dynamic Page Cache。
- 没有安装新模块，没有关 BigPipe、Views、Navigation、Media Library。
- 没有改 tabledrag 的核心脚本。Views 编辑页在按钮收拢之后仍有 CLS 0.368，来源矩形只有数像素到 62px 的位移，和第一帧的 314px 按钮不是同一件事。没有再叠一层会改 Views 布局的 CSS。

## 13. 修改文件

| 路径 | 动作 |
| --- | --- |
| `/etc/php/8.5/fpm/conf.d/99-drupal.ini` | `interned_strings_buffer=32` |
| `/etc/php/8.5/fpm/conf.d/99-drupal.ini.bak-2026-11-02-php-perf` | 修改前备份 |
| `web/themes/custom/xedc_admin/css/admin-ui.css` | 操作按钮第一帧样式 |

`XEDC_ADMIN_PERFORMANCE_REPORT.md` 没有改。

## 14. 优化前后对比

1440×900，3 次的中位数。百分比是（后 − 前）/ 前。负值是变快或 CLS 变小。

热缓存：

| 页面 | 前 TTFB | 后 TTFB | 变化 | 前 FCP | 后 FCP | 前 CLS | 后 CLS | CLS 变化 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 后台首页 | 254 | 185 | −27% | 476 | 456 | 0.000 | 0.000 | 0 |
| 内容列表 | 459 | 538 | +17% | 700 | 824 | 0.043 | 0.002 | −95% |
| 结构 | 190 | 238 | +25% | 320 | 432 | 0.000 | 0.000 | 0 |
| 媒体列表 | 425 | 500 | +18% | 640 | 772 | 0.046 | 0.046 | 0 |
| 美图添加 | 252 | 342 | +36% | 396 | 628 | 0.020 | 0.020 | 0 |
| 视频添加 | 408 | 257 | −37% | 572 | 924 | 0.019 | 0.019 | 0 |
| 文章添加 | 311 | 332 | +7% | 580 | 624 | 0.046 | 0.046 | 0 |
| Views 编辑 | 252 | 229 | −9% | 420 | 512 | 0.833 | 0.368 | −56% |
| 分类列表 | 172 | 194 | +13% | 368 | 440 | 0.324 | 0.022 | −93% |
| 标签管理 | 354 | 423 | +19% | 552 | 636 | 0.569 | 0.129 | −77% |
| 图片栏目 | 245 | 250 | +2% | 388 | 448 | 0.566 | 0.124 | −78% |

热缓存最差值（3 次里的最大 TTFB / 最大 CLS）：

| 页面 | 前 TTFB | 后 TTFB | 前 CLS | 后 CLS |
| --- | ---: | ---: | ---: | ---: |
| 后台首页 | 311 | 241 | 0.000 | 0.000 |
| 内容列表 | 515 | 586 | 0.046 | 0.002 |
| Views 编辑 | 274 | 274 | 0.833 | 0.368 |
| 分类列表 | 246 | 226 | 0.324 | 0.022 |
| 标签管理 | 465 | 530 | 0.586 | 0.137 |

冷缓存（浏览器缓存关闭）中位数：

| 页面 | 前 TTFB | 后 TTFB | 前 FCP | 后 FCP | 前 CLS | 后 CLS |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 后台首页 | 172 | 211 | 416 | 456 | 0.000 | 0.000 |
| 内容列表 | 444 | 481 | 668 | 768 | 0.043 | 0.000 |
| Views 编辑 | 215 | 348 | 428 | 756 | 0.833 | 0.368 |
| 分类列表 | 187 | 184 | 432 | 428 | 0.324 | 0.022 |
| 标签管理 | 364 | 400 | 560 | 724 | 0.582 | 0.125 |

TTFB 和 FCP 有的变快、有的变慢，同一页 3 次之间也能差 100ms 以上（美图添加热缓存最差 TTFB 771ms）。经过 Cloudflare 的这组数不能说明 OPcache 改动降低了 TTFB。内容列表热缓存中位数从 459ms 变成 538ms，是变慢，不是变快。

CLS 是稳定的。Views 编辑 3 次都是 0.368（之前 3 次都是 0.833）。分类列表 3 次都是 0.022（之前 0.324）。314px 收到 33px 的那一段在修改后的记录里没有了。

本机绕过 Cloudflare、Drupal 缓存已经热的第二次和第三次（修改后才测的，没有修改前的对照）：

| 页面 | 第二次 | 第三次 |
| --- | ---: | ---: |
| `/admin` | 151ms | 160ms |
| `/admin/content` | 466ms | 411ms |
| Views 编辑 | 230ms | 186ms |
| 分类列表 | 141ms | 127ms |
| 标签管理 | 278ms | 264ms |

内容列表在源站上仍要 400ms 多。这和“SQL 只有几十毫秒、页面不能进 Dynamic Page Cache”一致。

1280×800 和 390×664 各只有一次热加载，不当成中位数。分类列表 CLS 在 1280 从 0.400 降到 0.026，在 390 从 0.411 降到 0.030。标签页在 1280 从 0.554 降到 0.126，在 390 从 0.498 降到 0.100。Views 编辑在 1280 从 0.431 到 0.402，在 390 从 0.196 到 0.259。窄屏上 Views 的剩余偏移没有像 1440 那样从 0.83 降下来，因为窄屏本来就没有那段 314px 的高按钮占满首屏。

## 15. 浏览器测试

脚本：`/root/xedc-admin-php-performance/browse.js`、`functions.js`。截图在 `/root/xedc-admin-php-performance/shots/`。

功能（修改后，真实点击，没有提交、删除或发布）：

| 检查 | 结果 |
| --- | --- |
| Views 编辑按钮 | 打开后能看到“分析视图”“重复的视图”“对显示重新排序”“删除视图” |
| 分类列表操作 | 打开后能看到编辑词汇、增加术语、管理字段、翻译、删除词汇 |
| 标签页操作 | 打开后能看到“编辑” |
| 美图添加 | 标题框可见；“添加媒体”打开媒体库对话框，里面有媒体条目 |
| 视频、文章添加 | 标题框可见 |
| 390 宽抽屉 | 点击菜单按钮后侧栏 `aria-hidden` 不再是 true，宽度约 232px |

1280×800 的页面采样都是 HTTP 200。桌面和手机截图里，导航、表格、Views 三列和媒体库对话框都在视口内。

## 16. Console 和网络

修改前后的性能采样里，页面 `pageerror`、console error、以及 CSS/JS 的 4xx/5xx 都是 0 条。

Nginx 错误日志里的 PHP Fatal 是今天 10:23 的 `StorageCredentialForm` 类名冲突，和 14:20 的 `ReviewActionForm` 缺少 `submitForm`。都在本轮开始之前。本轮重启 FPM 和测试期间没有新的 Fatal。

## 17. 未解决

- 内容列表热缓存 TTFB 仍大约 0.4–0.5 秒（浏览器中位数这次是 538ms，源站热请求大约 410–470ms）。主体是不可缓存的视图渲染，不是缺索引。
- Views 编辑 CLS 从 0.833 降到 0.368，仍高于 0.1。按钮从 314px 收到 33px 的那一段已经不在了。剩下的是加载后 `#edit-display-settings` 的再排，源矩形只有大约 4–8px，和标题上移大约 62px 的另一小段。没有足够把握再改一版 CSS 而不动 Views 布局。
- 标签页和图片栏目页 CLS 大约 0.12–0.13，剩下的是 tabledrag 插入“显示行权重”并收起权重列。词汇列表这一段只有 0.022。
- 后台 HTML 仍然不进 Dynamic Page Cache。这是表单 token 和 `user` / `session` context 的结果，不是 Redis 没连上。
- OPcache interned 区不再是满的，但浏览器 TTFB 没有测出对应的下降。
- FPM slowlog 仍然关闭。没有排队，就没有为了日志去改池配置。

## 18. 风险

- interned buffer 多 24MB 共享内存。当前机器内存够用。若以后把这段 ini 拷到内存更紧的机器，需要再看。
- 操作按钮在脚本运行前就按闭合状态排版。无脚本的浏览器看不到第二项及以后的操作，直到脚本加上箭头。本站后台编辑依赖脚本。
- `drush cr` 清过一次渲染缓存和聚合。之后的第一次访问会重新生成聚合，这是预期的，不是数据丢失。
- 没有改权限、路由、表单结构和视图配置。

## 19. 回滚

OPcache：

```bash
cp -a /etc/php/8.5/fpm/conf.d/99-drupal.ini.bak-2026-11-02-php-perf /etc/php/8.5/fpm/conf.d/99-drupal.ini
php-fpm8.5 -t && systemctl restart php8.5-fpm
```

CSS：按第 12 节把 `admin-ui.css` 里本轮的操作按钮选择器和预隐藏规则改回去，再 `drush cr`。不要 `git checkout` 这个文件。

不需要回滚数据库或 Redis。

## 20. 后续建议

这些都没有在本轮做，因为要么没有测到收益，要么会碰到表单缓存的正确性。

1. 内容列表若还要降 TTFB，只能减少每次重渲染的工作，例如让操作链接的渲染结果按权限进 render cache，同时保证批量操作表单仍是占位符、不进整页缓存。需要单独设计，不能靠删掉 `user` context。
2. Views 编辑页剩余的 CLS 0.368 要继续对着 `#edit-display-settings` 在脚本运行后的位置变化看，不要再猜 dropbutton。
3. 术语页剩余的大约 0.12 来自 tabledrag 的权重开关。若要消掉，应在主题里给开关留出不推动表格的位置，并在 390 宽上确认不挡住说明文字。
4. 生产发布稳定之后，可以把 `opcache.revalidate_freq` 提高到 60，或在部署脚本里重置 OPcache 并关闭 `validate_timestamps`。现在仓库还有未提交代码，不适合关校验。
5. FPM 仍然只有 5 个子进程。出现套接字排队再考虑加大，并同时看 512MB 的 `memory_limit` 会不会把 7.6GB 内存打满。
