# XEDC 上传持久化、断点恢复、ZIP/7z、任务同步与前台原图大小

日期：2026-11-04。站点 https://gitxx.xyz。Drupal 11.4.11。模块 `xedc_core`、`xedc_quick`，主题 `xedc` / `xedc_admin`。

没有 commit，没有 push。没有改 `config/sync`、Drupal 核心、第三方模块、Nginx、PHP-FPM、R2/CDN 生产配置。没有新建第二套 Worker。没有擅自提高并发或放宽体积限制。

状态只用：PASS、FIXED、FAIL、NOT TESTED、BLOCKED。

## 直接回答

| 问题 | 结论 |
| --- | --- |
| 普通图片能否跨页面持续上传？ | **暂停后自动恢复（FIXED）**。Drupal 后台是整页跳转，离开页面时 XHR 会停；IndexedDB 保留 Blob 与会话，下一后台页由全局管理器接管并只补缺失分块。不能把“跳转期间仍在传”说成已实现。 |
| ZIP 能否跨页面持续上传？ | 同上：**暂停后自动恢复（PASS，浏览器）**。上传完成后交给现有 Worker。 |
| 7z 能否跨页面持续上传？ | 同上：**PASS（浏览器）**。真实 JPEG 的 7z 导入进入审核；无效测试 JPEG 被拒绝属内容校验，不是上传链路失败。 |
| 刷新后是否能够恢复？ | **PASS**。元数据与（允许时）Blob 在 IndexedDB；服务器会话为最终进度。 |
| 失败后是否可以继续上传？ | **PASS**。单项/批量重试；无 Blob 时提示重选并校验大小+SHA-256。 |
| 是否只补传缺失分块？ | **PASS**（脚本 + 管理器逻辑）。`missing_chunks` 为准。 |
| 压缩包上传完成后是否由 Worker 独立处理？ | **PASS**。仍用 `ImportProcessor` / `xedc-import-worker.service`。 |
| 快速发布是否使用统一上传管理器？ | **PASS（代码接入）**。`xedc_quick` 依赖 `upload_manager`；失败时仍可回退旧整文件接口。浏览器点操作系统文件框：**NOT TESTED**。 |
| 前台优化技术统计是否全部移除？ | **PASS**。详情页不再渲染优化后/节省/压缩率/已完成。后台统计保留。 |
| 前台原图大小是否正确显示？ | **PASS（有数据时）**。例：`/cosplay/cos/131` 显示 `原图:8.62 MiB`。无法恢复的历史数据仍显示「原图大小未知」，不伪造。 |
| 所有上传入口是否都记录原始大小？ | **PASS（新上传链路）**。`ImageBatchIntake` / `ImagePipeline` / 快速发布 / ZIP·7z 解压项写 `original_bytes`。 |
| 旧图片中哪些原始大小可以恢复？ | 可靠来源回填 **8** 条（同源哈希未优化）。其余见统计。 |
| 是否还有无法恢复的历史数据？ | **是**。`xedc_image_file` 仍有 **229** 条 `original_bytes=0`，无可靠来源，保持未知。 |

## 1. 原始问题与根因

### 上传

- 任务主要活在页面内存 + 弱化的 localStorage；IndexedDB 仅存会话提示，不存 Blob，也不在后台公共布局常驻。
- 切换 Drupal 菜单等于整页卸载，上传中断且不能自动在下一页接管。
- 图片批量任务完成后若未调用 `close`，任务会停在 `uploading`（本次已补上全局 `maybeCloseBatch`）。

### 前台原图大小

- 模板 `node--photo--full.html.twig` 在 `gallery_stats` 下展示内部优化文案（优化后 / 节省 / 压缩率 / 已完成），且当 `GalleryMetrics` 无已知原图时显示「原图大小未知」。
- 数据侧：`xedc_image_file.original_bytes` 历史大量为 0；优化记录、导入项、快速发布表无法覆盖全部旧文件。`rememberFile` 曾可能用 0 覆盖已有原图值（已修）。

## 2. 现有上传架构（保留）

仍使用会话接口：

- `POST .../upload/probe|session`
- `GET .../upload/sessions`
- `POST .../upload/session/{id}/chunk/{index}|complete|cancel`

兼容整文件：`/archive`、`/image`、`/draft`、`/close`。快速发布：`/admin/quick-gallery/upload/*`、`/reuse`。导入队列与 `xedc-import-worker.service` 不变。

## 3. 修改文件清单（本次相关）

新增：

- `web/modules/custom/xedc_core/js/upload-manager.js`
- `web/modules/custom/xedc_core/js/upload-panel.js`
- `web/modules/custom/xedc_core/css/upload-panel.css`
- `docs/XEDC_UPLOAD_PERSISTENCE_RETRY_ARCHIVE_LIVE_SYNC_AND_FRONTEND_IMAGE_SIZE_REPORT.md`

主要修改：

- `xedc_core.libraries.yml` / `xedc_core.module` / `xedc_core.routing.yml` / `xedc_core.install`（10017）
- `js/asset-upload.js`、`src/Controller/ImportController.php`、`src/Form/ImportListForm.php`
- `src/ImagePipeline.php`
- `xedc_quick`：`js/app.js`、`QuickController.php`、`xedc_quick.libraries.yml`
- 主题：`templates/content/node--photo--full.html.twig`、`xedc.theme`

工作区另有此前上传去重/标签等未提交改动，均保留，未 reset / clean。

## 4–8. IndexedDB、跨页、刷新、重试、断点

- DB：`xedc-upload-v2` / store `jobs`；字段含 client id、文件名、大小、MIME、SHA-256、kind、入口、session、task、分块进度、状态、错误、重试、时间、Blob（≤约 220 MiB）。
- 持久化失败会明确提示，不假装已保存。
- `BroadcastChannel` + localStorage 领袖租约：多标签只有一个执行者。
- 恢复：本地任务 → 服务器 sessions → 以服务器已确认分块为准 → 有 Blob 则自动续传，否则要求重选并校验。
- 重试：面板与上传中心支持单项/失败批量；成功项不重传。

技术选择：**IndexedDB + BroadcastChannel**。未上 Service Worker / Background Sync（后台登录、CSRF、Blob、Safari 挂起风险高）。因此跨菜单是「暂停 + 自动恢复」，不是「跳转期间仍在上传」。

## 9–11. ZIP/7z、快速发布、全局面板、自动同步

- ZIP/7z 走同一管理器与分块会话；完成后现有 Worker 解压导入。
- 快速发布接入同一管理器（kind=`quick`），限额仍约 25 MiB；保留旧上传回退。
- 后台公共布局通过 `hook_page_attachments` 加载面板；白底简洁，兼容深色与窄屏。
- 上传中心：有活动约 4s、空闲约 20s、隐藏页更慢；`visibilitychange` 立即同步。新增 `GET /admin/content/image-imports/sync` 更新任务表进度，不整页刷新。

## 12–16. 权限、幂等、性能

- 会话与任务仍按用户与权限隔离；CSRF、SHA-256、分块锁、配额、并发 2 / 压缩包 1 未放宽。
- 去重与 `client_upload_id` 幂等沿用既有实现。
- Worker 仍单实例；未提高并发。

## 17–19. 原图大小根因与修复

| 项 | 内容 |
| --- | --- |
| 根因 | 前台渲染了优化统计；`original_bytes` 历史缺失；少数路径可能覆盖原图值 |
| 采集 | 优化前字节写入 import item / `rememberFile` / quick_orig |
| 存储 | `xedc_image_file.original_bytes`、`xedc_import_item.original_bytes`、`xedc_quick_orig.bytes`、`xedc_image_optimize.source_bytes` |
| 定义 | original = 优化前；optimized = 最终文件；整数字节；展示用 1024 进位 |
| 复用 | 不覆盖已有可靠 `original_bytes`；同哈希未改写文件可用 `bytes` 回填 |
| 旧数据 | update **10017**：开始缺失 237，可靠恢复 8，仍缺失 229 |

回填只使用：optimize.source_bytes、import original_bytes、quick_orig、source_sha256==stored_sha256。不把当前优化后大小冒充原图。

## 20–29. 前台清理与显示

- 删除详情页优化统计块；`xedc_meta.gallery_stats = FALSE`。
- 保留标题、图集、分类、标签、互动等。
- 有数据时显示真实原图总大小（如 8.62 MiB）；无数据保持「原图大小未知」。
- 后台文件统计 / 优化表单 / GalleryMetrics 管理用途保留。
- 已对样例节点做 cache tag 失效并 `drush cr`。

## 30–36. 测试结果

| 测试 | 结果 |
| --- | --- |
| PHP / JS 语法 | PASS |
| 去重自测 12 | PASS |
| 断点续传自测 23 | PASS |
| 图片二阶段自测 19 | PASS |
| 导入 sync API | PASS |
| Worker 独立性（ZIP/7z 完成后离开页面） | PASS |
| 浏览器：全局面板、IndexedDB Blob、菜单切换后恢复、只服务端进度续传 | PASS（Headless Chromium） |
| 浏览器：ZIP 跨页后交 Worker | PASS（task 248 → review，item original_bytes=159） |
| 浏览器：7z 跨页后交 Worker | PASS（task 250 → review，original_bytes=723） |
| 前台真实页：优化文案移除 | PASS |
| 前台真实页：原图大小 | PASS（有数据节点）；未知节点诚实显示未知 |
| 桌面 Safari / iPhone Safari | **NOT TESTED** |
| 操作系统原生文件框 | **NOT TESTED**（用页面内 `File`） |
| 快速发布完整人工浏览器路径 | **NOT TESTED**（代码接入 PASS） |
| 跳转期间真正持续传输 | **NOT TESTED / 不做虚假承诺**（整页导航下为暂停恢复） |
| 多标签竞争上传 | 领袖机制已实现；双人并行压测 **NOT TESTED** |

## 37. 已知浏览器限制

- 整页导航会中断进行中的 XHR；依赖下一页自动恢复。
- iOS Safari 可能挂起页面或清理存储；不能承诺关浏览器后无限后台上传。
- IndexedDB 配额不足时提示用户，并保留服务器已确认分块。

## 38–39. 数据库变更与回滚

- Schema 版本 **10017**（在 10016 之上）。
- 10017 只 UPDATE 空的 `original_bytes`，可反向把误写行设回 0（本次仅写可验证来源，风险低）。
- 代码回滚：还原本次列出的自定义文件；勿 `git reset --hard` / `git clean`。勿删正式媒体与 R2 对象。会话表可保留。

## 40. Git 工作区

- 分支：`main`，HEAD `2184737feee3dfcfee88c96c5847b798287bac7a`。
- 大量未提交修改（含本任务与既有上传/标签工作）均保留。未 commit、未 push。

## 验收对照（摘要）

1–12 上传持久化 / 跨页恢复 / 重试 / 缺块 / 快速发布接入 / 自动同步 / Worker / 幂等：已实现并按上表测试。  
13–16 原图采集与区分、ZIP/7z 内图、复用不丢：新链路 PASS；旧数据部分 FIXED、部分诚实未知。  
17–19 前台去优化统计、真原图显示、后台保留：PASS。  
20 本报告。
