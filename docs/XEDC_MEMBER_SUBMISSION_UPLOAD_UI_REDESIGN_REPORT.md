# XEDC 会员投稿系统 — 上传稳定性、后台 UI 与前台投稿体验

日期：2026-11-05。站点 https://gitxx.xyz。Drupal 11.4.11 / PHP 8.5.12。  
主题：前台 `xedc`，后台 `xedc_admin`（Radix；文档中的 Gin 仅作兼容变量，实际未安装 Gin）。

本轮 **未 commit、未 push**。未改 Drupal Core、第三方模块、`config/sync`、Nginx、PHP-FPM、R2/CDN。未使用 `git reset --hard` / `checkout --` / `clean -fd`。未覆盖其他模块既有未提交修改。

状态只用：PASS、FAIL、FIXED、NOT TESTED。

---

## 1. 上传问题根因

### 错误文本

`Error preparing Blob/File data to be stored in object store`

来自浏览器 IndexedDB structured clone，在 `IDBObjectStore.put()` 准备 Blob/File 数据时抛出（WebKit/Chromium 历史问题；空 File、已深度读取的 File、Safari 等场景更常见）。

### 发生环节

前端本地持久化，不是服务端 Session / 分片上传 / Worker。

调用链：

1. `enqueue()` / `runJob()` / `attachFile()` → `persist(job, true)`
2. `rowFromJob()` 把 `job.file`（File）写入 `row.blob`
3. `store.put(row)` → IndexedDB 结构化克隆失败

图片集（多张 image）与 ZIP/7z（archive）共用同一 `persist()`；压缩包更大、哈希更久，哈希后再次 `persist(includeBlob=true)` 更容易踩到 File 克隆问题。故障路径相同，触发频率可能不同。

### 根本原因

旧实现直接把同一 `File` 引用写入 IndexedDB。完整 SHA-256（`arrayBuffer()`）之后、或在部分浏览器上，该 File 的 structured clone 会失败。错误英文原文被拼进会员提示，看起来像“上传失败”。

与浏览器兼容有关：Chromium 桌面端本次可复现“修复后稳定写入”；直接 put-after-hash 在当前 Chromium 偶发不失败，但文献与历史报告确认 iOS Safari / 空 File / 大文件场景会抛该错。Safari/iOS：**NOT TESTED**。

### 修复要点（`upload-manager.js`）

| 改动 | 说明 |
| --- | --- |
| `cloneForPersist()` | 用 `file.slice(0, size, type)` 生成独立 Blob，避免把已读 File 直接 put |
| `probeBlobPersist()` | 启动探测：blob → ArrayBuffer（≤64MiB）→ 仅元数据 |
| `writeJobRow()` / `persist()` | 失败时降级；配额/Blob 错误转中文提示 |
| `enqueue` / `attachFile` | 创建时即克隆；持久化失败不谎称可断点恢复 |
| `loadPersisted()` | 支持 `blob` 与 `arraybuffer` 两种编码还原 |
| 哈希后重克隆 | `runJob` 在完整 SHA-256 后再 `cloneForPersist` 再持久化 |

用户提示示例：

- 文件暂存失败：无法保存所选文件，请重新选择文件后重试。
- 浏览器可用存储空间不足…
- 原文件暂时不可恢复，请重新选择原文件。当前标签页仍可继续上传。
- 所选文件与原任务不匹配。

未取消断点续传；未改 Worker；未把大文件改成 Base64 / localStorage。

### 验证

| 项 | 结果 |
| --- | --- |
| `node --check` upload-manager / panel / asset-upload | PASS |
| `browser-blob-persist-self-test.mjs`（Chromium：enqueue 图片+ZIP，IDB 含 Blob，`blobEncoding=blob`） | PASS |
| 上传取消 / Worker 交接逻辑（本轮仅叠加，未回退 cancel_pending） | 代码保留；专项回归 NOT TESTED（本轮） |
| iOS Safari | NOT TESTED |

---

## 2. 前台投稿 UI

### 信息架构结论

原先会员「创建/编辑」走 `node.settings.use_admin_theme`，进入 `xedc_admin`，并因 `import photo archives` 挂上完整 `xedc_import`（优化率、任务表、上传中心、inbox）与浮动上传面板。

### 本轮改动

| 页面 | 改动 |
| --- | --- |
| 投稿中心 `/member/submissions` | 改名为「内容投稿」；5 个入口卡片（图片 / 图片集 / 压缩包 / 视频 / 文章）；投稿须知；最近投稿按状态操作 |
| 图片投稿 `/node/add/photo` | 前台主题 + `xedc_member_node_form`；媒体库 + 会员简化上传 |
| 图片集 `?upload=gallery` | 同上，图片批量区高亮 |
| 压缩包 `?upload=archive` | 同上，压缩包区高亮；无任务表/压缩率/inbox |
| 视频 `/node/add/video` | 前台主题会员表单 |
| 文章 `/node/add/article` | 前台主题会员表单 |
| 投稿记录 | 状态徽标 + 按状态操作（继续编辑 / 查看详情 / 修改并重新提交 / 查看内容） |
| 投稿详情 | 仍用节点查看/编辑权限页；未新建独立 detail 路由（避免重复权限模型） |

关键机制：

- `MemberSubmissionThemeNegotiator`（priority 100）强制默认前台主题
- `xedc_member_form_node_form_alter`：`#access = FALSE` 隐藏推荐/轮播/语言/作者/修订/sitemap/scheduler 等
- `xedc_core_attach_member_photo_upload()`：会员专用上传块；员工仍用原 staff 面板
- 浮动 `upload_panel` 仅 `administer xedc image imports` / `administer nodes`

浏览器实测（uid=2，Chromium）：投稿中心 5 卡、无后台壳、photo/video/article 会员表单、隐藏 featured/slideshow/任务表/压缩率、archive 高亮 — **全部 PASS**。截图：`docs/tasks/screenshots/xedc-member-submission/`。

---

## 3. 后台 UI

实际检查路由：

| 路由 | 结果 |
| --- | --- |
| `/admin/content/image-imports` | 列表有内容；`dashboard.css` 经聚合加载；布局可用。PASS |
| `/admin/content/image-imports/{task}` | preview 改为 `<figure>`；`admin.css` 操作按钮/深色变量；PASS |
| `/admin/content/moderated` | `.xedc-gallery-status` 独立 library，不再挂 `asset_upload`；样式生效（`inline-flex`）。PASS |
| `/admin/quick-gallery` | 未改功能；本轮未专项回归。NOT TESTED |
| `/admin/xedc/ai/submissions` | 未改模板；本轮未专项回归。NOT TESTED |

样式缺失原因与修复：

1. `GalleryStatus` 误挂 `asset_upload`（重、且无状态样式）→ 改为 `xedc_core/gallery_status` + `gallery-status.css`
2. 详情预览 markup 与 `.xedc-import-grid figure` 不一致 → 改为真实 `<figure>` / `<figcaption>`
3. `admin.css` 硬编码白底/蓝链 → 对齐 Gin/xedc_admin 变量，统一操作按钮

后台导航：非员工隐藏「图片导入」「创建内容」（`permission` 过滤），避免会员误入后台工具。

说明：生产后台主题是 **xedc_admin**，不是 Gin；保留 `--gin-*` 兼容选择器。

---

## 4. 前后台信息隔离

从前台会员视角移除（服务端 `#access` / 不渲染，不是 CSS 隐藏）：

- `field_featured` / `field_slideshow`
- `langcode` / `field_comment` / `author` / `revision_*` / sitemap / scheduler
- 员工导入状态文案（压缩率、原图/优化统计）
- 导入任务表、进度 Worker 面板、服务器 inbox
- 后台壳、浮动上传面板、「图片导入」导航

会员仍可见且需要的：标题、描述、栏目、标签、模特、媒体库、简化上传进度、审核状态（草稿/提交审核）。

权限：会员不能改 `published`（既有 `hook_node_presave`）；上传 Session API 仍按登录用户隔离（既有服务端逻辑）。

---

## 5. 测试结果

| 项 | 结果 |
| --- | --- |
| PHP/JS 语法 | PASS |
| `drush cr` | PASS |
| IndexedDB Blob 持久化自测（Chromium） | PASS |
| 会员投稿中心 5 入口 / 移动端 / 深色 | PASS |
| 会员 photo/video/article 前台主题与表单 | PASS |
| 会员页无 featured/slideshow/任务表/压缩率/后台壳 | PASS |
| archive 模式高亮 | PASS |
| 后台导入列表/详情样式（聚合 CSS 实测） | PASS |
| moderated gallery-status 样式 | PASS |
| 单图/图集/ZIP/7z 真实上传到 R2 | NOT TESTED（本轮未跑完整上传链路） |
| 上传中取消 / Worker 交接回归 | NOT TESTED（代码保留既有修复） |
| 多标签页 / 刷新恢复 / SHA 不匹配 | NOT TESTED |
| iOS Safari | NOT TESTED |
| 快速发布 / ALT / 图片优化回归 | NOT TESTED |

---

## 6. Git 状态

- 分支：`main`
- HEAD：`93da5e3df281a1737ae4a8e805b7eec419b85582`
- 本轮主要修改（叠加在既有未提交工作上）：
  - `web/modules/custom/xedc_core/js/upload-manager.js`（及 panel/asset-upload 提示）
  - `web/modules/custom/xedc_core/xedc_core.module` / `libraries.yml` / `css/admin.css` / `css/gallery-status.css`
  - `web/modules/custom/xedc_core/src/Controller/ImportController.php`
  - `web/modules/custom/xedc_core/src/Plugin/views/field/GalleryStatus.php`
  - `web/modules/custom/xedc_member/*`（theme negotiator、表单、模板、CSS、Controller）
  - `web/themes/custom/xedc_admin/includes/navigation.theme`
  - 测试与报告：`tests/manual/browser-blob-persist-self-test.mjs`、`browser-member-submission-ui.mjs`、本报告
- 原有未提交修改（会员中心、标签、通知、VIP、上传取消等）**保持**
- commit：否  
- push：否

---

## 7. 完成标准对照

1. Blob/File 根因已定位并修复 — FIXED  
2–6. 图片集/ZIP 持久化路径加固；断点续传与取消交接未拆除 — FIXED（完整线上上传回归 NOT TESTED）  
7–9. 前台投稿统一设计 + 后台内容隔离 — PASS（浏览器）  
10–11. 后台投稿相关页检查并修样式缺口 — PASS  
12–13. 桌面/移动/深色（会员投稿）— PASS  
14. 工作流权限逻辑未放宽 — PASS（代码层）  
15. 未覆盖其他未提交修改 — PASS  
