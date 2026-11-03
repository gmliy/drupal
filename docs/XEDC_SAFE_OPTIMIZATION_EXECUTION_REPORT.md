# XEDC 安全优化执行报告

日期：2026-11-03。HEAD 仍是 `e72409eba38874aaaba4c48e16d085951d222f10`，分支 `main`。没有 commit，没有 push。

执行前工作区已有 126 条 `git status` 记录（含上一阶段 5 份审计报告和更早的未提交修改）。这些修改没有被重置或覆盖。`ServerMonitor.php`、`monitor.js`、`xedc.libraries.yml` 在本阶段开始前就是已修改文件；`ImageBatchIntake.php` 和 `asset-upload.js` 在开始前就是未跟踪文件。本阶段只在这些文件上做了小段插入。

磁盘：根分区可用约 36GB。内存 available 约 3.7GiB。数据库备份存在且未恢复，包括 `/root/xedc-backups/drupal-20261101-before-config.sql.gz`、`/var/backups/xedc_before_admin_theme_20261101_120455.sql.gz`、`/root/drupal-backup/before-11.4.11.sql`。回滚不依赖这些备份。

维护模式在执行前后都是开启的。

## 1. 本阶段改过的文件

| 文件 | 相对本阶段开始时 | 做什么 |
| --- | --- | --- |
| `web/modules/custom/xedc_core/src/ImageBatchIntake.php` | 未跟踪文件上的插入 | 空工作目录不再抛未捕获异常 |
| `web/modules/custom/xedc_core/js/asset-upload.js` | 未跟踪文件上的条件收紧 | 重试链接只在正整数任务 ID 时出现 |
| `web/modules/custom/xedc_core/src/Ops/ServerMonitor.php` | 已有未提交差异上的两处数字 | 总览刷新 3→10 秒，成功快照缓存 3→8 秒 |
| `web/modules/custom/xedc_core/src/Controller/MonitorController.php` | 新的一行 | 设置里缺少刷新秒数时的回退值 3→10 |
| `web/modules/custom/xedc_admin_ui/src/ModerationGuard.php` | 从 Git 跟踪文件删除 | 确认无引用 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageManager.php` | 从 Git 跟踪文件删除 | 确认无引用 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchboard.php` | 从 Git 跟踪文件删除 | 确认无引用 |
| 本报告 | 新增 | |

没有改 Drupal Core、Contrib、`vendor`、`settings.php`、`config/sync`、Nginx、PHP-FPM、数据库结构、文件实体或用户内容。

`git diff HEAD` 里 `ServerMonitor.php` 还有本阶段之前就存在的缓存键改动（时区后缀等）。回滚本阶段时不能把整个文件 `checkout` 回 HEAD，否则会清掉那些已有修改。只应把下面两行改回 3。

执行期间磁盘上出现了 `docs/XEDC_ARCHIVE_IMPORT_PERFORMANCE_REPORT.md`（mtime 15:46:33Z）。本阶段没有写这个文件，也没有改它。

## 2. 批次记录

### 批次 A：空导入目录不再变成 500

状态：**FIXED**。上传请求本身 **NOT TESTED**。

证据仍在：审计日志里 `POST /admin/content/image-imports/image` 抛出 `DirectoryNotReadyException`，目标目录是空字符串。当前 `addFile()` 在复制前调用 `workReady()`（约 54–56 行）。目录为空、不含 `://`，或 `prepareDirectory()` 失败/抛错时，返回原有 `fail()` 数组，`ok` 为 false。控制器本来就把 `ok` 为 false 编成 422。`copy()` 另有 `\Throwable` 捕获（79–84 行），避免核心异常漏出。

成功路径不变：目录可用时仍 `copy()` 到 `work_uri`，校验字节数，再 `insertItems()`。权限、队列和优化参数没有改。

测试：

| 检查 | 结果 |
| --- | --- |
| `php -l ImageBatchIntake.php` | PASS |
| 容器里 `xedc_core.image_batch` 的类名 | PASS，仍是 `ImageBatchIntake` |
| 用真实图片 POST 导入 | NOT TESTED。会写任务和私有文件 |

### 批次 B：重试链接不再拼出 undefined

状态：**FIXED**。浏览器点击 **NOT TESTED**。

`asset-upload.js` 92–95 行：`Number(job.taskId)` 必须是大于 0 的整数才生成 `/retry` 链接。字符串 `"undefined"`、空值、`0`、`1.5`、`"12abc"` 在 Node 里复跑这 10 个条件，结果与预期一致。`node --check` 通过。

`TaskAccess::view()` 在本阶段开始前已经是 `int|string`，非数字返回 forbidden。那是已有未提交修改，本阶段没有再改这个 PHP 文件。因此旧日志里的 TypeError 不会只靠这次 JS 修改来解释历史请求；新页面不再发出这种链接。没有再请求 `/undefined/retry`。

### 批次 C：监控总览少打 Netdata

状态：**FIXED**（代码常量）。实际轮询次数 **NOT TESTED**。

修改前 `drush php:eval` 读到 `ServerMonitor::SECTIONS['overview']['refresh']` 为 3。修改后为 10。`cpu` 仍是 3。成功快照的内部缓存从 3 秒改为 8 秒（`snapshot()` 的 `$expire`）。失败快照仍是 15 秒。HTTP 响应仍由 `MonitorController::data()` 设置 `Cache-Control: no-store, private`，没有把监控 JSON 变成公共页面缓存。缓存键仍是服务器指标，不含用户内容。

`monitor.js` 已从 `settings.refresh` 读取秒数，本阶段没有改这个已修改的 JS，避免覆盖既有差异。总览页会显示 10 秒，因为服务端 `refresh_seconds` 来自 `SECTIONS['overview']`。这是刷新间隔，不是版式。CPU、内存、网络分类仍按各自的 3 秒。

没有浏览器会话，所以没有对比 data 接口的前后次数。不能写节省百分比。登录页 TTFB 不能当作监控优化的结果。

测试：

| 检查 | 结果 |
| --- | --- |
| `php -l ServerMonitor.php` 与 `MonitorController.php` | PASS |
| 容器能构造 `xedc_core.monitor` | PASS |
| 登录后打开监控页并数请求 | NOT TESTED |

### 批次 D：删除 ModerationGuard

状态：**FIXED**。后台审核点击 **NOT TESTED**。

删除前再次搜索：类名只出现在该文件和审计文档里。`xedc_admin_ui.services.yml` 只有 `xedc_admin_ui.dashboard_stats`。`config` 与 `key_value` 中计为 0。文件相对 HEAD 干净，SHA256 `7951891739b1473359d8bde5f09125e3a791b4a2c3a707434cc9e36b8feae96b`，114 行。副本在 `/tmp/xedc-opt-rollback/ModerationGuard.php`（临时目录，可能被清掉）。权威回滚是 Git。

删除后：`class_exists` 为 no，模块 `xedc_admin_ui` 仍启用，`drush status` bootstrap 为 Successful，管理主题仍是 `xedc_admin`。

没有单独开 Git 分支。工作区里已有一百多条无关修改，新分支不能把删除和那些修改隔开。这三个文件与 HEAD 一致，`git checkout HEAD -- <path>` 可以单独恢复。

### 批次 E：删除 StorageManager

状态：**FIXED**。对象存储页面 **NOT TESTED**。

删除前类名无 PHP/YAML 调用。配置和 key_value 计数为 0。SHA256 `2b79fa0c775970e4826bee0b873ae248dbf85a4c3e54d00f450704ae40d653f0`，105 行。

删除后 `class_exists` 为 no。仍能实例化 `xedc_core.storage_switch`（`StorageSwitchManager`）和 `xedc_core.storage_reader`（`RemoteFileReader`）。

### 批次 F：删除 StorageSwitchboard

状态：**FIXED**。对象存储页面 **NOT TESTED**。

同样无调用、配置计数 0。SHA256 `633ad2e143c13301a55e621d4da1125dd322b3590c2fd779c256a9f62953fa9c`，153 行。

删除后 `class_exists` 为 no。`xedc_core.storage_switch` 与 `xedc_core.storage_delivery`（`DeliveryUrl`）仍能从容器取出。`file_managed` 322 行，`node` 84 行，与删除前相同。

## 3. 重复代码

没有合并。

| 项 | 状态 | 原因 |
| --- | --- | --- |
| DUP-1 两套模特站点地图 URL | BLOCKED | 审计写明正则替换和 `parse_url` + 路由生成的失败行为不同。没有一份输入输出对照证明可以互换 |
| DUP-2 `video-card.css` 在两个库里各声明一次 | BLOCKED | 视频列表模板只附加 `xedc/video_list`。若改成依赖 `xedc/video-card`，依赖会先加载，CSS 顺序会反转。审计没有证明层叠结果相同。没有删除 CSS 文件 |
| DUP-3 多套文件引用判断 | BLOCKED | 审计写明数据源不同，`file_usage` 会漏。不能合成一个函数 |
| DUP-5 两份 Bootstrap Icons | BLOCKED | 没有统计哪些 SVG 被页面 URL 引用 |

## 4. 明确未执行的优化

| 项 | 状态 | 原因 |
| --- | --- | --- |
| SEC-1 `settings.php.bak` 可被下载 | BLOCKED | 修复需要改 Nginx，并轮换口令。两者都在禁止修改的范围内。没有删除这个备份，避免在未轮换前只从工作区拿走副本 |
| SEC-2 State 里的翻译密钥 | BLOCKED | 要改密钥存放和翻译读取路径，并轮换密钥。本阶段不改 state |
| OPS-1 维护模式 | BLOCKED | 关闭会公开站点。执行前后匿名首页都是 503 |
| OPS-2 503 的 `public` 缓存头 | BLOCKED | 审计没有定位到自定义代码。改核心响应订阅者不在允许范围 |
| OPS-4 Nginx `client_temp` 权限 | BLOCKED | 禁止改 Nginx |
| PERF-1 684MB 聚合 CSS/JS | BLOCKED | 需要在生产清缓存 |
| PERF-3 拆 Bootstrap | BLOCKED | 审计没有组件级覆盖率。拆构建会改变 UI 的风险没有被排除 |
| PERF-4 删除残留 batch 行 | BLOCKED | 队列行是数据库记录。执行前队列 142 行，执行后仍是 142 行 |
| PERF-5 调整 Redis 上限 | BLOCKED | 审计结论是现在不必改，而且改的是服务配置 |
| PERF-6 调整 FPM `max_children` | BLOCKED | 禁止改 PHP-FPM |
| PERF-9 截断数据库 cache 表 | BLOCKED | 缓存操作，且 ChainedFast 是否仍读这些表没有新的证据 |
| 配置导出 `xedc_core.image_optimize` | BLOCKED | 禁止改 `config/sync` |
| 卸载 Gin、workspaces 或 dev 分支模块 | BLOCKED | 禁止卸载和更换依赖 |
| 66 个 `.bak` 文件 | BLOCKED | 禁止批量删除。其中 settings 备份属于 SEC-1 |

## 5. 性能前后

同一条件：维护模式开着，匿名请求，没有登录。

| 指标 | 修改前 | 修改后 | 说明 |
| --- | --- | --- | --- |
| `/user/login` | 200，83ms，18845 字节，页面缓存 HIT，动态缓存 UNCACHEABLE | 200，67ms，18845 字节，同样的缓存头 | 正文长度相同。16ms 差值在一次采样里没有意义，不记成优化收益 |
| `/` | 503，84ms | 503，93ms | 仍是维护页。内容页 TTFB、LCP、CLS、INP 都是 NOT TESTED |
| 总览刷新常量 | 3 | 10 | 只测了 PHP 常量，没有测浏览器请求次数 |
| `file_managed` / `node` / `queue` | 322 / 84 / 142 | 322 / 84 / 142 | 没有删数据 |

监控、导入、视频页的查询数、内存和 CPU 没有前后对照。NOT TESTED。

## 6. 功能验收

前台业务页（首页、图片、视频、文章、搜索、标签、模特、会员、收藏、点赞、下载、分享）没有在浏览器里走通。维护模式让匿名请求得到 503。状态 **NOT TESTED**。登录页只核对了状态码、字节数和缓存头，没有核对版式，UI 不标 PASS。

后台页面没有登录，审核、导入、监控、文件中心、SEO、翻译、AI 都是 **NOT TESTED**。路由名仍能解析：

- `xedc_core.import_retry` → `/admin/content/image-imports/{task}/retry`
- `xedc_core.monitor` → `/admin/reports/xedc-monitor`
- `xedc_core.image_optimize` → `/admin/config/media/image-optimization`
- `xedc_models.page` → `/models`
- `xedc_member.overview` → `/member`

默认主题 `xedc`，管理主题 `xedc_admin`。Gin 仍安装，没有更换。

Twig 文件没有改，没有做模板编译。**NOT TESTED**。没有跑 CSS 构建。`asset-upload.js` 只做了 `node --check`。PHPUnit 没有与这三类删除或 `ImageBatchIntake` 对应的现有测试，没有新装测试工具，整套 manual 自测会写数据，所以没有跑。**NOT TESTED**。

Drupal 日志最新几条是 cron 完成，不是这次编辑的异常。PHP-FPM 日志没有新的致命错误。Nginx 错误日志末尾仍是审计阶段请求 `settings.php` 时留下的记录，没有这次优化产生的新栈。

## 7. 数据库、文件和第三方

- 没有 `updb`、没有改表、没有删 `file_managed` 或节点。行数见上表。
- R2、CDN、图片优化参数、翻译 state 没有改。
- `composer.lock` 和 `package.json` 没有改。
- 在用的存储服务 `StorageSwitchManager`、`RemoteFileReader`、`DeliveryUrl` 在删除门面类之后仍能从容器创建。

## 8. 回滚

跟踪文件：

```bash
git checkout HEAD -- \
  web/modules/custom/xedc_admin_ui/src/ModerationGuard.php \
  web/modules/custom/xedc_core/src/Ops/Storage/StorageManager.php \
  web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchboard.php \
  web/modules/custom/xedc_core/src/Controller/MonitorController.php
```

`ServerMonitor.php` 只把总览 `refresh` 从 10 改回 3，把成功快照的 `8` 改回 `3`。不要整文件回退。

`ImageBatchIntake.php` 和 `asset-upload.js` 不在 HEAD 里。回滚时删掉 `workReady()` 以及对 `copy()` 的 try/catch，恢复为直接 `prepareDirectory()` 和 `copy()`；JS 恢复为 `if (job.taskId && ...)` 并且 href 使用 `job.taskId`。

临时副本：`/tmp/xedc-opt-rollback/` 里有三个被删类的原文件。机器清理 `/tmp` 后只剩 Git 回滚。

## 9. 遗留

- 设置备份仍可被未登录下载。这是当前最大的未处理风险，本阶段按禁止项停住。
- 维护模式仍开着，所以上面的前台验收不能标 PASS。
- 导入 500 的代码路径已改成返回失败结构，但没有用一张图片打上传接口。
- 监控是否真的少请求，要在登录后看访问日志。
- 上传临时目录权限、聚合文件体积、残留 batch、数据库旧 cache 表都还在。
