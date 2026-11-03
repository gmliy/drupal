# XEDC 安全优化计划

本文件只规划，不执行。审计阶段没有改代码、配置、Nginx、数据库或缓存。

每条都保持现有权限、审核、私有文件、CDN/R2、翻译和 SEO 行为。凡是要动密钥或删除文件的步骤，都先备份，并准备回滚。

服务器按实测理解：4 个逻辑 CPU、7.6GiB 内存、Swap 已用 1GiB。建议同时要能在更小的 2 vCPU / 2GB 机器上成立，所以不提出“把 Redis 扩到 1GB”或“把 PHP-FPM 子进程调到 20”这类方案。

## P0

### SEC-1 设置备份可被公开下载

- 位置：`web/sites/default/settings.php.bak-2026-11-01-1537`
- 表现：未登录 `GET` 返回 200 和 PHP 源码。文件被 Git 跟踪
- 原因：Nginx 只拒绝 `.php`，`.bak` 按静态文件发送。文件放在 web 根里
- 证据：审计时的 HTTP 状态与 `Content-Type: application/octet-stream`。正文未写入报告
- 做法：先在 Nginx 拒绝 `sites/default` 下的 `.bak`、`.sql`、`.ini` 备份；再把该文件移出 web 根；从工作树和远程历史移除。因为 URL 已经能读到口令，轮换数据库用户口令，并检查该文件里其余秘密（盐、哈希盐、API、存储密钥）是否也要轮换。同步改主机上的数据库账号配置，避免只改 Git
- 预期：该 URL 变为 403/404，仓库里不再有口令副本
- 影响：轮换口令时会短暂中断数据库连接，需要和 `settings.php` 一起改。不要改业务数据
- 难度：低（Nginx）到中（口令轮换与历史清理）
- 回滚：保留一份只存在于 root 家目录、权限 600 的旧口令记录，直到新口令确认可用。不要把这份记录放回 `web/`
- 验收：匿名请求返回 403 或 404，响应正文没有 `<?php`；`git grep` 找不到该备份；站点匿名首页在维护模式策略允许时仍能由 Drupal 响应
- 本阶段：`BLOCKED`（改 Nginx 和口令不在审计授权内）

### SEC-2 DeepL 密钥在 State

- 位置：`key_value`，collection `state`，name `xedc_auto_translate.api_key.deepl`
- 表现：序列化明文，长度 39。`key` 模块已启用
- 原因：密钥写进了 State API，而不是 Key 或环境变量
- 证据：只读 `state:get` 看到类型和长度。原值不记录
- 做法：在 Key 模块或环境变量中建立新密钥，改 `xedc_auto_translate` 的读取点去引用 Key，确认翻译仍能发起后再删 state 里的旧值，并在 DeepL 侧轮换
- 预期：数据库转储不再含有可用的 API 密钥
- 影响：只影响自动翻译。内容翻译结果不要删
- 难度：中。要读设置表单的保存路径后再改，避免翻译队列中断
- 回滚：保留旧密钥直到新密钥翻译一条测试内容成功。测试内容要用草稿，不要改已发布节点
- 验收：state 中该名字为空或消失；一篇草稿的缺失译文仍能生成；`config` 与 `watchdog` 里搜不到新密钥
- 本阶段：`BLOCKED`

### OPS-1 维护模式

- 表现：匿名内容 URL 返回 Drupal 503。登录页 200
- 证据：`system.maintenance_mode = 1`，源站响应头
- 做法：由站点负责人确认是否还要维护。若要对外开站，再关维护模式。不要为了做性能测试自动关闭
- 预期：关闭后匿名首页返回 200，且不是维护模板
- 影响：关闭会立刻公开当前已发布内容（photo 已发布翻译行 183、video 32、article 48，实体总数仍是 84 个节点）
- 难度：低
- 回滚：重新打开维护模式
- 验收：首页 200，`X-Generator: Drupal`，正文不是维护页标题
- 本阶段：`BLOCKED`

### OPS-4 上传临时目录权限

- 位置：Nginx `client_temp`，日志路径 `/var/cache/nginx/client_temp/`
- 表现：`Permission denied`，`POST /admin/content/image-imports/image` 与 `/admin/quick-gallery/upload` 出现 500
- 原因：worker 用户不能在该目录创建临时文件。大请求体会先落盘
- 证据：`error.log` 13:36 之后的多条 `crit`
- 做法：把 `client_temp` 的属主改回 Nginx worker，或把 `client_body_temp_path` 指到 worker 可写的目录。先 `namei -l` 看哪一层权限错了，再改目录，不要放宽整个 `/var/cache`
- 预期：同样大小的后台上传不再因为临时文件 500
- 影响：不改变导入业务规则。修之前失败的任务不会自动重试
- 难度：低
- 回滚：恢复目录属主
- 验收：用一张小图走现有导入接口得到 JSON 200 或业务层 4xx，而不是 Nginx 500。不要用生产图片做破坏性覆盖
- 本阶段：`BLOCKED`

## P1

### BUG-1 空的导入工作目录

- 位置：`web/modules/custom/xedc_core/src/ImageBatchIntake.php` 52–77 行；调用方 `AssetUploadController::image()` 150–157 行
- 表现：`work_uri` 为空时 `copy()` 抛 `DirectoryNotReadyException`，控制器只捕获 `ImportException`，用户看到 500
- 证据：13:26:27 的 PHP 异常与上述行号一致
- 做法：`work_uri` 为空或 `prepareDirectory()` 失败时返回现有的 `fail()` 结构，不要把核心异常漏出。同时查出哪条任务把 `work_uri` 写成了空字符串，修那条任务的数据前先只读确认
- 预期：失败上传返回 422 JSON，FPM 错误日志不再出现这个 Type 的未捕获异常
- 影响：不改变优化参数和发布规则
- 难度：低
- 回滚：还原该文件
- 验收：构造一个 `work_uri` 为空的测试任务会得到明确错误；正常任务仍能写入 `private://` 工作目录。验收用新草稿，不改已发布相册
- 缓存/权限：不放宽 `TaskAccess`

### BUG-2 重试 URL 变成 undefined

- 位置：`web/modules/custom/xedc_core/js/asset-upload.js` 92–94 行；`TaskAccess::view()` 39–42 行
- 表现：请求 `/admin/content/image-imports/undefined/retry`。旧代码对 string 参数抛 TypeError。工作区里的 PHP 已拒绝非数字，这是审计前的未提交修改
- 做法：链接只在 `taskId` 为正整数时渲染。PHP 侧保留非数字即拒绝，并给路由要求加上与整数参数转换一致的失败行为（404 而不是 500）
- 预期：访问日志里不再出现字面量 `undefined`
- 影响：失败任务的“重试处理”只对真实任务 ID 出现
- 难度：低
- 回滚：还原 JS
- 验收：浏览器里让一个没有 task id 的失败行不出现该链接；对真实失败任务，链接仍指向整数 ID。不要在生产任务上点重试，除非那次重试是操作者想做的

### OPS-2 维护页的 public 缓存

- 表现：503 响应 `Cache-Control: max-age=3600, public`
- 证据：源站响应头。Cloudflare 这次是 DYNAMIC
- 做法：维护模式响应使用 `no-store` 或 `private, max-age=0`。这是核心或中间件行为，改之前要定位是哪一个响应订阅者加上了 max-age，避免把正常匿名页的 86400 秒缓存一起关掉
- 预期：维护页不再允许共享缓存
- 影响：正常页面缓存必须保持。验收时对比一篇匿名可缓存页面的 `X-Drupal-Cache` 仍可为 HIT
- 难度：中
- 回滚：还原改动的订阅者或配置
- 验收：503 为 `no-store`；关闭维护模式后，第二次匿名访问首页出现页面缓存 HIT（在页面本身可缓存的前提下）

### PERF-1 聚合 CSS/JS 684MB

- 位置：`web/sites/default/files/css` 246MB、2626 个文件；`files/js` 438MB、3972 个文件。最早 2026-10-12，最晚 2026-11-03
- 原因：聚合文件按哈希累积，旧哈希不会在日常请求里删除
- 做法：在低峰执行一次缓存重建，让 Drupal 删掉旧聚合并生成当前页面需要的文件。不要手工 `rm -rf files/css`。不要动 `files/gallery`、`files/styles`、`files/2026-*`
- 预期：css/js 目录降到当前主题实际聚合后的体积，通常是数十 MB 以内而不是 600MB 以上。重建后的第一次页面会变慢
- 影响：匿名页缓存同时变冷。不改变图片和节点
- 难度：低，但要选低峰。这台机器 Swap 已用 1GB，重建时 FPM 会忙
- 回滚：不需要回滚文件；若页面缺样式，再请求一次页面即可重新聚合。不要从备份把 684MB 旧哈希拷回去
- 验收：抽首页、`/meitu`、`/video`、一篇图片详情、后台内容列表，状态 200，样式表 200，没有整页无 CSS。磁盘占用下降
- 本阶段：`BLOCKED`（禁止清缓存）

### PERF-2 监控轮询

- 位置：`ServerMonitor.php` 29 行与 81 行；`monitor.js` 169 与 195 行
- 表现：刷新间隔 3 秒，成功快照 TTL 也是 3 秒。当天 data URL 251 次
- 做法：总览刷新改为 10 秒，缓存 TTL 改为 8 秒，让大多数轮询命中 Redis。隐藏标签的 30 秒逻辑保留。Netdata 仍是数据源，不在 PHP 里再开一套采集
- 预期：同样开着监控页时，PHP 重建快照的次数大约降到原来的三分之一。Netdata 本身的采集频率不变
- 影响：画面慢 7 秒。不改变权限
- 难度：低
- 回滚：改回 3 秒
- 验收：打开监控 1 分钟，data 请求约 6 次而不是 20 次；响应 JSON 仍含 CPU、内存、磁盘。用访问日志计数，不要只看界面

### PERF-6 FPM 上限

- 表现：`pm.max_children=5` 被达到 20 次。worker RSS 约 50–153MB，`memory_limit` 512M
- 做法：先修 OPS-4 和 BUG-1，避免失败上传占满 5 个 worker。然后只在观察 `pm.max_children` 仍经常打满、且 `available` 内存长期高于 1.5GB 时，把 `max_children` 从 5 调到 6 或 7。不要一步调到 10 以上。2GB 机器上应维持 5 甚至降到 4，并把 `memory_limit` 从 512M 降到 256M，以免 5 个 worker 的理论上限超过物理内存
- 预期：上传高峰少一些 502/504。内存不因此进入持续 swap-in
- 影响：子进程越多，MariaDB 和 Redis 越容易被挤
- 难度：低，但要看监控
- 回滚：改回 5
- 验收：`php-fpm.log` 在业务高峰不再出现 max_children；`free` 的 available 不掉到 500MB 以下

## P2

### PERF-9 陈旧的数据库缓存表

- 表现：`cache_page`、`cache_render`、`cache_dynamic_page_cache`、`cache_data` 以及 discovery/bootstrap/config 的 `created` 最大值都是 2026-11-01 15:36，合计数十 MB
- 做法：先确认 ChainedFast 的一致后端。若这三张 bootstrap/config/discovery 表不再被写，再在维护窗口 `TRUNCATE` 已经确认由 Redis 接管的 bin。一次只清一张，然后请求首页和管理页
- 预期：数据库缩小约 50MB 或更多。运行时命中率仍看 Redis
- 影响：若判断错，下一次请求会重建该 bin，页面变慢数秒，不丢内容
- 难度：中（判断后端）
- 回滚：无法恢复旧缓存内容，也不需要。缓存会重建
- 验收：截断后 Redis `keyspace_hits` 仍增长；对应表的 `MAX(created)` 要么保持不动，要么出现新的当天时间戳且页面正常
- 本阶段：`BLOCKED`

### PERF-4 残留 batch 队列

- 表现：`drupal_batch:166`–`172` 各 20 行，`199` 有 2 行，`expire=0`
- 做法：确认没有浏览器还开着这些 batch 页面之后，删除这些 `queue.name`。不要删其他队列名，不要删 `xedc_*` 业务表
- 预期：队列表去掉 162 行。进度页不再指向已死的 batch
- 影响：若仍有页面持有该 batch id，那个页面的进度会停。导入任务表 `xedc_import_task` 不该由这个删除来改状态
- 难度：低
- 回滚：没有业务数据可回滚。删除前 `SELECT` 导出这 162 行到 `/root` 而不是 web 目录
- 验收：`SELECT name, COUNT(*) FROM queue GROUP BY name` 不再有这些 batch；现有导入任务的 `xedc_import_task` 行数不变

### PERF-3 全局 Bootstrap

- 位置：`xedc/build/css/main.style.css`、`main.script.js`，由主题全局库引入
- 做法：用覆盖率看 `main.script.js` 里哪些 Bootstrap 组件真的在首页执行。只把未使用的 JS 组件从构建入口拿掉。CSS 先不要大拆，避免改变布局。189KB 压缩后传输会更小，本次没有测传输体积
- 预期：首页 JS 下降。视觉不变
- 影响：若删掉仍被某页使用的组件，下拉或弹层会坏。必须逐页看首页、图片、视频、文章、登录、会员
- 难度：中
- 回滚：还原 `build.mjs` 入口并重新构建
- 验收：上述页面的交互仍在，构建产物变小。维护模式关闭后再做

### PERF-5 Redis 上限

- 表现：53.17MB / 64MB，淘汰 0，命中率约 90.7%
- 做法：维持 64MB，直到 `evicted_keys` 开始持续增长。增长后再考虑提到 96MB 或 128MB，并同时看 Swap。不要为了“更安全”改成 `noeviction`，否则缓存写失败会影响页面
- 预期：现在无需操作
- 验收：继续看 `evicted_keys` 与 `used_memory`

### 配置漂移

- `xedc_core.image_optimize` 只在活动库，键为 `sync_bytes`
- 做法：下次正常的配置导出时带上它。不要在这次审计里 export
- 验收：`config/sync` 出现同名 yml，且与活动配置键一致

### CODE-5 Gin

- 管理主题已经是 `xedc_admin`（Radix）
- 做法：确认没有配置依赖 Gin 之后，再决定是否卸载 `gin` 和未启用的 `gin_toolbar`。卸载前导出配置并在暂存环境打开 `/admin/content` 与节点编辑页
- 风险：若有区块或 CKEditor 样式仍写 Gin 的类，后台会变样
- 本阶段不卸载

### CODE-8 dev 分支模块

- `mailsystem`、`matomo`、`redirect`、`smtp` 是 dev，`twig_tweak` 是 alpha
- 做法：锁定到上游稳定标签前，先在暂存环境跑邮件、重定向、Matomo 片段和 Twig 滤镜。不要在生产直接 `composer update`
- 预期：版本可复现
- 回滚：`composer.lock` 回退

### CODE-6 workspaces

- 模块开启，`workspace` 行数 0
- 做法：确认内容审核不依赖工作区后，再在暂存环境卸载 `workspaces_ui` 与 `workspaces`。核心模块卸载要跑更新钩子，本阶段禁止
- 验收：审核流仍能把 `needs_review` 送到 `published`；节点数不变

## P3

### CODE-1 与 CODE-2

- `ModerationGuard.php`、`StorageManager.php`、`StorageSwitchboard.php` 没有容器注册
- 做法：若下一个版本仍不接线，再删除类。删除 `Storage*` 门面时不要动 `StorageSwitchManager` 和 `xedc_storage.runtime.php`
- 验收：导入、图片 URL、对象存储设置页仍可用
- 难度：低
- 回滚：Git 还原这三个文件

### CODE-3 备份文件

- 66 个 `.bak`。先处理 SEC-1 的 settings 备份
- 其余主题 bak（`xedc.theme.bak*`、`auth.css.bak*`、两份 355KB 的 `main.style.css.bak-*`）在确认没有人还拿它们做对照后，移出仓库
- 不要删除无 `.bak` 后缀的正式文件
- 验收：页面 CSS 仍是 189KB 的 `main.style.css`，不是 355KB 的 bak

### DUP-1 模特 URL

- 两套函数行为不同，见主报告
- 做法：抽一个共有的 URL 生成器之前，先给两个函数写输入输出样例：相对路径、带语言前缀、带 query、路由生成失败
- 只有样例一致时才合并
- 验收：站点地图里的 `/models/{id}` 与 hreflang 仍正确

### DUP-2 video-card.css

- 两个库都声明了同一文件
- 做法：`video_list` 依赖 `xedc/video-card`，去掉重复的 css 路径
- 验收：视频列表卡片样式仍在，聚合 CSS 里该文件只出现一次

### DUP-5 图标两份

- 主题目录 8.3MB SVG，npm 里还有 `bootstrap-icons`
- 做法：先统计 Twig 和 CSS 是否引用 `/themes/custom/xedc/assets/icons/`。若构建已经把用到的图标打进 CSS，再考虑不要把全量 SVG 部署到可访问目录
- 风险：删了仍被 `<img src>` 使用的 SVG 会破图
- 验收：抽查首页、视频卡片、会员菜单的图标仍显示

## 明确不做

- 不卸载 `audit` 或 `site_audit`
- 不把 `file_usage` 为空的 8 个文件删除，包括 `private://xedc-samples/secret-hevc.mp4`
- 不重建 R2、不改图片优化参数、不改 CDN 域名
- 不合并 `FileReferenceAnalyzer` 和 `StorageOperationGuard::referenced()`
- 不提高 Redis 或 FPM 到会压满 2GB 内存的配置
- 不用关闭权限检查、关闭审核或拉长缓存 max-age 到忽略用户态的方式换速度

## 建议实施顺序

1. SEC-1 与口令轮换，同时挡住 `.bak`
2. OPS-4 上传临时目录
3. BUG-1、BUG-2
4. 确认维护模式去留，并修正 503 的缓存头
5. SEC-2 密钥迁出 State
6. 低峰重建聚合缓存（PERF-1）
7. 监控间隔（PERF-2）
8. 再决定 batch 行、数据库旧 cache 表、Gin 和 workspaces

第 6 步以后才做 P3 的删除。P3 全部可以推迟，不影响这次审计发现的故障。
