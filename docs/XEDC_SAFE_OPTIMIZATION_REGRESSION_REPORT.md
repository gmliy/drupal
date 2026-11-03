# XEDC 第二阶段安全优化回归验收

日期：2026-11-03。站点 https://gitxx.xyz。Drupal 11.4.11。HEAD `e72409eba38874aaaba4c48e16d085951d222f10`。验收开始时 `git status --porcelain` 133 行，结束时只应多出本报告。没有 commit，没有 push，没有改代码，没有清缓存，没有改 `config/sync`、Nginx、PHP-FPM 或数据库结构。

验收开始时维护模式是开启的（`system.maintenance_mode = 1`）。大约 16:19 UTC 再次读取时已经是 0。这次验收没有改这个 state。前半段匿名请求因此是 503，后半段匿名请求是 200。

## 结论

| 问题 | 结论 |
| --- | --- |
| 第二阶段修改是否全部有效 | 已测到的代码路径有效。真实上传、后台点击和 Netdata 停机没有测 |
| 三个删除的类是否安全 | PASS。容器、配置、数据库和调用链都不依赖它们 |
| 图片导入是否通过 | 隔离调用 PASS。正式上传接口 NOT TESTED |
| 上传重试是否通过 | 列出的空值、负数、小数和非法字符串 PASS。超大数字会生成 `1e+21/retry`，这一项 FAIL |
| 监控轮询是否通过 | 总览间隔和成功缓存 PASS。CPU 分类的实际请求和监控页面外观 NOT TESTED |
| 前后台页面是否通过 | 前台若干页面已在浏览器里看到内容。后台功能、暗色模式和点击交互 NOT TESTED |
| 是否存在安全漏洞 | FAIL，P0。未登录可以下载 `settings.php.bak-2026-11-01-1537` |
| 是否可以进入下一阶段优化 | 不可以。先隔离这份备份、轮换数据库凭据，并确认 Git 历史里的副本 |

## 1. 环境

| 项 | 结果 |
| --- | --- |
| 分支 | `main`，与开始时同一个 HEAD |
| 工作区 | 开始 133 条 porcelain。验收没有改已有文件 |
| 数据库行数 | 隔离脚本开始和结束都是导入任务 2、`file_managed` 322、`node` 86、队列 143。之后另一次只读查询队列是 142，名称合计为 7 个 batch 各 20 条再加 2 条。脚本自身没有插入或删除 |
| 测试分层 | 代码检查、Drush 隔离调用、匿名 HTTP、本机 headless Chromium。没有登录后台，也没有另一套数据库 |

## 2. 三个删除的类

状态：**PASS**。浏览器里的审核页和存储切换页 **NOT TESTED**。

检查的类：

- `Drupal\xedc_admin_ui\ModerationGuard`
- `Drupal\xedc_core\Ops\Storage\StorageManager`
- `Drupal\xedc_core\Ops\Storage\StorageSwitchboard`

| 检查 | 操作 | 结果 | 状态 |
| --- | --- | --- | --- |
| 文件和自动加载 | `class_exists` 与文件是否存在；`vendor/composer` 的 autoload 文件没有这三个类名 | 都不可加载 | PASS |
| 服务容器 | 读两个模块的 services YAML，并实例化在用服务 | YAML 没有这三个类。`xedc_core.storage_switch` 是 `StorageSwitchManager`，`xedc_core.storage_delivery` 是 `DeliveryUrl`，reader、writer、guard 也能创建 | PASS |
| 插件和其他模块 | `git grep` HEAD 的 PHP/YAML。contrib 和主题源码再搜一次 | 只有这三个文件自己的类声明。工作区里的自定义代码没有剩余引用 | PASS |
| 配置和 key_value | 用完整类名 `LIKE` | 三个类都是 config 0、key_value 0 | PASS |
| 调用链 | 实例化切换和分发服务，对一条 `public://` 和一条 `private://` 调用 `forUri`。没有调用 `inspect()` 或 `execute()`，避免写 state | 公开地址 `kind=cdn`。私有地址 `kind=private`，`cdn=false`，`permanent=false`。前台改写仍走 `xedc_core_file_url_alter()` → `PublicUrl::alter()`，不经过已删的门面 | PASS |
| 模块仍在 | `moduleExists` | `xedc_admin_ui` 和 `xedc_core` 都是 yes。默认主题 `xedc`，管理主题 `xedc_admin`。Gin 主题权重是 0，但不是当前管理主题 | PASS |

一条真实公开文件经 `forUri` 得到 CDN 地址后做了 HEAD：HTTP 200，`image/webp`。这是一条对象的读取，不是整个 Bucket 的清单。

## 3. 图片导入

正式上传没有打到生产接口。原因是成功路径和非法图片路径会写 `xedc_import_task`、私有文件或队列。没有第二套数据库。隔离方式是在 Drush 进程里换上不写库的任务仓库和文件系统，再调用真正的 `ImageBatchIntake::addFile()`。脚本开始和结束时生产计数相同。

| 场景 | 环境 | 结果 | 状态 |
| --- | --- | --- | --- |
| 正常工作目录 | 隔离双对象，8×8 JPEG | `ok=true`，`phase=queued`，`stored=true`，`task_id=424242`。队列假对象收到 1 次，内存里的 kick 记录 1 个 id。没有调用真实 `destruct()`，所以没有跑 `processBatch` | PASS |
| 工作目录为空 | 已有任务的 `work_uri` 是空字符串 | 返回“工作目录没有准备好”，没有 `copy`，没有 `insert`。响应里的 `task_id` 是 0，不是已有任务号 | PASS |
| 目录准备返回 false | `prepareDirectory()` 返回 false | 同样拒绝，没有复制 | PASS |
| 目录不可写 | `prepareDirectory()` 抛异常 | 异常被接住，返回失败结构，没有复制 | PASS |
| 复制失败 | `copy()` 抛异常 | 返回“无法写入工作目录”，没有插入明细 | PASS |
| 保存后大小不一致 | 隔离文件系统返回另一份大小 | 调用了删除，没有插入明细 | PASS |
| 非法文件 | 文本冒充 jpg | 没有复制。会调用 `insertItems`，状态是 failed。这是原有逻辑，隔离环境没有写生产表 | PASS |
| 空文件、缺文件、坏 token、超过 50MB | 真实 `xedc_core.image_batch`，在 `openTask()` 之前返回 | 四次都是 `ok=false`。任务、文件、节点、队列计数不变 | PASS |
| 已有活动任务 | 隔离仓库 `hasActive=true` | 抛 `ImportException`，文案含“正在处理”，没有 `create` | PASS |
| 控制器 422 | 用 uid 1 调 `image()`，nid 1，文件是测试模式的上传对象 | 活动任务异常返回 422，正文 `phase=failed`。匿名调用返回 403，没有复制 | PASS |
| 匿名 HTTP | 维护模式关闭后，对图片和压缩包地址发空 POST | 都是 403。没有进入控制器 | PASS |
| ZIP 损坏、路径穿越、只有非图片 | 对 `xedc_core.archive` 的私有 `extractZip()` 做反射，目录在 `/tmp` | 损坏包抛“ZIP 已损坏”。穿越路径抛“不安全”，`/tmp` 下没有逃出的文件。非图片被跳过，skipped=1，没有写出 txt。合法小 ZIP 只解进目标目录 | PASS |
| 正式成功上传和正式压缩包上传 | 会写任务和私有文件 | 没有做 | NOT TESTED |

`openTask()` 第 162 行自己的 `prepareDirectory()` 仍在 try 之外。那里失败时，控制器只捕获 `ImportException`，核心的目录异常仍会变成 500，而且 `create()` 已经先插入了任务行。这次验收没有故意把生产目录弄成不可写。这一项是代码检查，状态 **FAIL**：第二阶段挡住的是复制前的空目录，不是创建任务时的目录准备。

失败结构与成功结构的差别：`fail()` 含 `ok/received/stored/queued/optimized/linked/completed/phase/task_id/item_id/filename/bytes/error/message`，`task_id` 为 0。控制器用 `ok` 决定 200 或 422。重试链接又要求任务号大于 0，所以这条失败响应不会再拼出 `/0/retry`。

## 4. 上传重试链接

环境：`node --check` 通过。下面的 URL 是直接执行 `asset-upload.js` 第 92–99 行，前缀来自 `ImportListForm` 的 `progressBase`，即 `/admin/content/image-imports/`。没有在浏览器里点击按钮。点击和错误提示 **NOT TESTED**。

| 输入 | 状态要求 | 生成的 URL | 状态 |
| --- | --- | --- | --- |
| 167，failed | failed | `/admin/content/image-imports/167/retry` | PASS |
| `"167"`，partial | partial | 同上 | PASS |
| 0、负数、null、undefined、`"undefined"`、`"null"`、1.5、NaN、Infinity、`"12abc"`、空字符串 | failed | 不生成链接 | PASS |
| 167，success 或没有 status | 非失败 | 不生成链接 | PASS |
| `Number.MAX_SAFE_INTEGER` | failed | 十进制长数字加 `/retry` | PASS |
| `1e21` | failed | `/admin/content/image-imports/1e+21/retry` | FAIL |

`Number.isInteger(1e21)` 为 true，`String(1e21)` 是 `1e+21`。这不是 `/undefined/retry`。数据库自增任务号到不了这个数量级，但按本次列出的超大整数，结果是一条错误路径。验收没有改代码。

`poll()` 在 `job.taskId` 为假值时直接返回，不会请求进度地址。字符串 `"undefined"` 仍是真值，那条进度地址不在这次重试链接的修改里。

## 5. 服务器监控

代码文件修改时间是 15:48:20 UTC。

| 检查 | 操作 | 结果 | 状态 |
| --- | --- | --- | --- |
| 总览 10 秒，CPU、内存、网络 3 秒 | 读 `ServerMonitor::SECTIONS` | 10、3、3、3 | PASS |
| 成功缓存 8 秒 | 缓存里没有总览快照时调用一次 `snapshot(false)`，再读过期时间。没有做缓存重建 | `ok=1`，`refresh=10`，过期距当时 8 秒。紧接着第二次调用返回同一个 `updated` | PASS |
| 失败缓存 15 秒 | 源码是 `$snapshot['ok'] ? 8 : 15`。没有停 Netdata | 表达式在源码里。运行中的失败分支没走到 | NOT TESTED |
| 缺少刷新配置时用 10 | 控制器源码和空数组表达式 | 结果是 10。页面渲染 NOT TESTED | PASS |
| JSON 是 private、no-store | CLI 调用 `MonitorController::data()` | `Cache-Control: no-store, private`，HTTP 200。快照和数据里没有 uid、mail | PASS |
| 匿名访问 data 接口 | 维护模式关闭后的 GET | 403。403 页面自己的缓存头不是这份 JSON 头 | PASS |
| Netdata 不可用 | 反射 `emptySnapshot()`；`NetdataClient::get()` 捕获 `GuzzleException` | 返回 `ok=false` 的数组，连接文字是“未连接”，刷新秒数 10。没有真的断开 Netdata | NOT TESTED |
| 实际请求间隔 | Nginx access.log 里 `section=overview` 且状态 200 | 15:48 之前 175 个间隔的中位数是 3 秒。16:08:26、16:08:36、16:08:46 三次连续间隔都是 10 秒。样本只有这一小段，不写节省百分比。日志里没有 `section=cpu` | PASS |
| 监控页外观和数据是否在页面上更新 | 没有登录后的浏览器 | 匿名打开是 403 | NOT TESTED |

这次 CLI 调用按正常监控路径写了一条快照缓存和一次 data 响应缓存。没有执行 `drush cr`，也没有调用 `invalidate()`。

## 6. 前台和后台

浏览器是本机 headless Chromium，未登录。暗色按钮和移动端菜单只看到图标，没有点击。收藏、点赞、分享、下载、搜索提交和视频播放没有操作。这些交互一律 **NOT TESTED**。

维护模式仍开着时，匿名首页、列表和后台都是 503，标题分别是站点名或“网站正在维护”。这段不能当成内容验收。

维护模式变为关闭后：

| 页面 | 方法 | 看到的内容 | 状态 |
| --- | --- | --- | --- |
| 首页 | 浏览器 1280 宽 | 导航、搜索框、轮播、标签和“最新美图” | PASS |
| 登录 | 浏览器桌面和 390 宽 | 用户名、密码、登录按钮、注册入口。窄屏变成图标和菜单按钮 | PASS |
| `/meitu` | 浏览器桌面和 390 宽 | “美图列表 共 45 组”，分类和两列或四列卡片 | PASS |
| `/video` | 浏览器 1280 宽 | “8 个视频”，封面、时长和标题 | PASS |
| `/xiuren/130` | 浏览器 1280 宽 | 大图、张数和体积、收藏、点赞、分享 | PASS |
| 文章详情 | 浏览器 1280 宽 | 标题、正文图和侧边文章列表 | PASS |
| `/articles`、`/tags`、`/tags/luoli`、`/models`、`/search/node` | 只看 HTTP 和标题 | 都是 200。搜索框没有提交 | NOT TESTED |
| 视频详情 | HTTP | 200，HTML 里有播放器标记，9 处 `cdn.gitxx.xyz`。没有截图，也没有按播放 | NOT TESTED |
| `/member` | HTTP 和标题 | 200，但是登录页，不是会员中心 | NOT TESTED |
| 公开会员主页 | 没有打开具体 slug | | NOT TESTED |
| 后台各功能 | 匿名 | 监控 data、图片上传、压缩包上传都是 403。内容管理、投稿、审核、文件中心、SEO、翻译、Matomo 没有登录后查看 | NOT TESTED |
| Gin | 配置 | 主题已启用，管理主题仍是 `xedc_admin`。没有打开 Gin 页面 | NOT TESTED |
| 暗色模式 | 浏览器 | 只看到切换图标，页面仍是浅色 | NOT TESTED |

图片地址：`/meitu` 的 11 张图指向 `gitxx.xyz`，不是 `cdn.gitxx.xyz`。图片详情里有 5 处 `cdn.gitxx.xyz`。另有一条 `file_managed` 的公开文件 CDN HEAD 为 200、`image/webp`。列表走源站、详情走 CDN 不是这次 diff 改出来的，这里只记录现状。

## 7. settings.php.bak

状态：**FAIL**。严重程度：**P0**。

| 项 | 证据 |
| --- | --- |
| 位置 | `web/sites/default/settings.php.bak-2026-11-01-1537`，37509 字节。Git 正在跟踪 |
| Nginx | `location ~ \.php$` 只处理以 `.php` 结尾的文件。`location ~ (^|/)\.` 只拦点文件。`.bak` 会按静态文件从站点根目录发出 |
| 未登录 GET | `https://gitxx.xyz/sites/default/settings.php.bak-2026-11-01-1537` 返回 200，`application/octet-stream`，`cf-cache-status: DYNAMIC`。正文以 `<?php` 开头，含 `$databases` 和 password 键。抽样 37509 字节里没有 deepl 字样。凭据值没有写入本报告 |
| 保留的 Nginx 日志 | 这个确切路径有 2 次 HTTP 200：15:25:41 和 16:15:34 UTC，分别来自先前审计和本次验收。10 月几次探测的是 `/settings.php.bak` 和 `/sites/default/settings.php.bak`，返回 404。Cloudflare 侧日志 NOT TESTED |
| 其他可下载备份 | `web/themes/custom/xedc` 下 65 个带 bak 的文件全部 200。其中 17 个正文像 PHP 源码。这 65 个的前 20KB 都没有 `$databases` 或 password 键 |
| 不会当成源码下载的 PHP | `default.settings.php` 和测试夹具里的 `settings.php` 被当成 PHP 执行，返回空的 HTML，不是配置正文 |
| Git | 文件在当前 HEAD，也在提交 `aac5110`（2026-11-01 19:00:46 UTC）。拿到仓库就能读到 |
| 站外副本 | `private/backups/settings.php.before-object-storage` 权限 0444，不在 Web 根下；按公开 URL 请求得到 404。`/root/drupal-backup/r2-public-takeover/settings.php` 权限 600。两份都没有打开，不能据此说它们是否含同一组凭据 |

必须做、但本次按约束没有做：

1. 立刻让 Web 服务器拒绝这个路径，并把文件移出 `web/`。
2. 轮换数据库用户口令，并检查该用户还能访问哪些库。
3. 从 Git 历史里去掉这个 blob。只在工作区删除不能消除 `aac5110`。
4. 处理站外的 settings 副本。确认不再需要后再删除，删除前先保证还有一份受控备份。
5. 65 个主题 bak 也应停止公开下载。它们这次没有查出数据库口令，但 PHP 源码仍然公开。

同一时段还看到维护模式首页的响应头是 `Cache-Control: max-age=3600, public`。这是维护模式仍开着时的匿名首页。本次没有改核心或 Nginx，所以没有动它。

## 8. 数据和依赖

隔离脚本没有改变导入任务、文件、节点和队列计数。没有 `updb`，没有删 `file_managed`。`composer.lock` 和 contrib 源码不在本次 diff 里。CDN 的一次 HEAD 成功。R2 控制台和对象清单 NOT TESTED。

## 9. 工作区

验收结束前，porcelain 与开始时的 133 行相比没有新增或消失。本报告写入后会多这一条未跟踪文件。HEAD 不变。
