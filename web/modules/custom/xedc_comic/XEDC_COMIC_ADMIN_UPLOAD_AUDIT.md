# XEDC Comic — 后台上传入口与漫画管理功能核实报告

**模块**：`xedc_comic`  
**核实时间**：2026-11-14  
**环境**：当前生产 Drupal 站点  
**方式**：`drush route`、权限与路由访问、`ComicAdminController` / `ComicImportForm` 运行期构建；**本次未改代码、未重构**

---

## 一、漫画管理后台

| 功能 | 路径 | 路由名 | 控制器/表单 | 权限 | 路由注册 | 页面可运行 |
|------|------|--------|-------------|------|----------|------------|
| 漫画中心概览 | `/admin/xedc/comic` | `xedc_comic.admin` | `ComicAdminController::overview` | `administer comics` | PASS | PASS |
| 漫画管理 | `/admin/xedc/comic/comics` | `xedc_comic.admin_comics` | `ComicAdminController::comicList` | `administer comics` | PASS | PASS |
| 章节管理 | `/admin/xedc/comic/chapters` | `xedc_comic.admin_chapters` | `ComicAdminController::chapterList` | `manage comic chapters` | PASS | PASS |
| 图片管理 | `/admin/xedc/comic/pages` | `xedc_comic.admin_pages` | `ComicAdminController::pageList` | `manage comic pages` | PASS | PASS（无 `?chapter=` 时仅提示） |
| ZIP 批量导入 | `/admin/xedc/comic/import` | `xedc_comic.import` | `ComicImportForm` | `import comic archives` | PASS | PASS |
| 导入进度 | `/admin/xedc/comic/import/status` | `xedc_comic.import_status` | `ComicAdminController::importStatus` | `import comic archives` | PASS | PASS |
| 漫画统计 | `/admin/xedc/comic/statistics` | `xedc_comic.admin_stats` | `ComicAdminController::stats` | `view comic statistics` | PASS | **FAIL** |
| 阅读进度管理 | — | **无** | — | — | **FAIL** | **FAIL** |
| 漫画设置 | `/admin/xedc/comic/settings` | `xedc_comic.settings` | `ComicSettingsForm` | `administer comics` | PASS | NOT TESTED |
| 批量创建章节 | `/admin/xedc/comic/chapters/batch` | `xedc_comic.admin_chapter_batch` | `ComicChapterBatchForm` | `manage comic chapters` | PASS | NOT TESTED |

### 统计页故障（已复现）

`ComicStatsManager::topComics()` 在 Drupal 11 中链式调用 `->addExpression()` 后得到 **string**，继续 `->condition()` 报错：

```text
Call to a member function condition() on string
文件：web/modules/custom/xedc_comic/src/Service/ComicStatsManager.php（约第 121 行）
```

### 阅读进度

- 前台/API：`/api/comic/reading-progress`（`xedc_comic.api_progress`）
- 存储表：`xedc_comic_reading`
- **无**后台「阅读进度管理」路由或页面

### 权限

| 检查项 | administrator (uid=1) | content_editor |
|--------|----------------------|----------------|
| administer comics 等漫画后台权限 | PASS | **FAIL**（无任何 comic 相关权限） |

### xedc_admin 侧栏

| 检查项 | 状态 |
|--------|------|
| `web/themes/custom/xedc_admin/includes/navigation.theme` 含漫画 | FAIL（无「漫画」项） |
| `xedc_comic.links.menu.yml` → `system.admin_content` | PASS（「漫画中心」） |
| 原因 | 侧栏为主题内静态定义；未改主题故无飞厢入口。入口：**管理 → 内容 → 漫画中心**，或直链 `/admin/xedc/comic` |

### 浏览器整页 HTTP

使用 `http_kernel` 子请求时因 HTMX 订阅者报错 **500**（NOT TESTED 代表真实浏览器）。控制器直接渲染：除 **统计页** 外均为 PASS。

---

## 二、章节图片上传能力

| 能力 | 实现 | 状态 | 说明 |
|------|------|------|------|
| 单张图片上传 | 无 | **FAIL** | 无后台表单/路由 |
| 多张图片批量上传 | 无 | **FAIL** | 无 |
| ZIP 上传 | `/admin/xedc/comic/import` | **PASS（表单）** | `managed_file`，仅 `.zip`；须选漫画 + 章节 |
| PDF 上传/转图 | 无 | **FAIL** | 模块未实现；`xedc_core` 无漫画 PDF 流水线 |

- **章节管理**：列表 + 编辑链接；**无上传控件**
- **图片管理**（`/admin/xedc/comic/pages?chapter={nid}`）：只读缩略表；**无上传/替换/删除**

---

## 三、ZIP 导入实现核对

| 环节 | 实现 | 复用 XEDC | 状态 |
|------|------|-----------|------|
| 上传表单 | `ComicImportForm` | — | PASS（构建含 comic/chapter/archive） |
| 验证 | `FileExtension: zip`，`private://xedc-comic-import` | private 流 | PASS |
| 漫画/章节选择 | autocomplete + 可新建章节 | — | PASS |
| 解压 + 自然排序 | `xedc_core.archive`（`ArchiveManager`） | 是 | PASS（代码路径） |
| 图片校验 | `ComicStorageManager` → `ImagePipeline::validate` | 是 | PASS（代码路径） |
| 图片优化 | `ImageOptimizer` + `ImageOptimizeRepository` | 是 | PASS（代码路径） |
| 存储落盘 | `public://comics/{comic_id}/{chapter_id}/` | Storage Hub 公开流 | PASS（代码路径） |
| 队列 | `xedc_comic_import` / `ComicImportWorker` | — | PASS（插件已注册） |
| ComicPage + File 实体 | `ComicStorageManager::createPageFromPath` | — | PASS（代码路径） |
| 导入日志 | `xedc_comic_import_log` | — | PASS |
| 重复导入（同章同 SHA-256） | `ComicImportManager::createJob` | — | PASS |
| 取消任务 | `ComicImportManager::cancelJob` | — | **BLOCKED**（无 UI/路由） |
| 失败重试 | 队列可再入队；单张失败记入 item | 部分 | NOT TESTED |
| R2 实际上传 | `file_system->copy` → 远程 public | — | NOT TESTED |
| 端到端真实 ZIP | — | — | NOT TESTED（库中 import 任务数 0） |

---

## 四、上传入口缺失 — 设计方案（待确认后开发）

统一 **章节导入中心**（建议仍以 `/admin/xedc/comic/import` 为枢纽），**必选**目标漫画与章节。

| 能力 | 建议 |
|------|------|
| ZIP 批量 | 保留现有表单；补进度页取消/重试 |
| 多图批量 | 新表单：多 `managed_file` 或评估复用 `xedc_core` 上传会话 |
| PDF → 图片 | **BLOCKED**：需先确认 `pdftoppm`/Imagick 与运维策略 |

约束：全程 `ImagePipeline` / `ImageOptimizer` + `public://comics/...`，不绕过 Storage Hub，不改 R2 全局配置。

---

## 五、生产环境保护

核实过程未修改数据库内容、未执行导入、未改 `config/sync`、Core、Contrib 及其他内容类型。

---

## 六、关键文件与服务

| 类型 | 路径 / ID |
|------|-----------|
| 路由 | `web/modules/custom/xedc_comic/xedc_comic.routing.yml` |
| 后台控制器 | `src/Controller/ComicAdminController.php` |
| ZIP 表单 | `src/Form/ComicImportForm.php` |
| 导入服务 | `xedc_comic.import` → `ComicImportManager.php` |
| 存储服务 | `xedc_comic.storage` → `ComicStorageManager.php` |
| 解压 | `xedc_core.archive` → `ArchiveManager` |
| 队列 Worker | `src/Plugin/QueueWorker/ComicImportWorker.php` |
| 菜单（Drupal） | `xedc_comic.links.menu.yml` |

---

## 七、结果汇总

| 类别 | 状态 |
|------|------|
| 后台路由与主体页面（除统计） | PASS |
| 漫画统计页 | **FAIL** |
| 阅读进度管理（后台） | **FAIL**（未实现） |
| xedc_admin 飞厢菜单 | **BLOCKED** |
| ZIP 导入表单与代码链路 | PASS |
| ZIP/R2 实跑 | NOT TESTED |
| 单图 / 多图 / PDF 上传 | **FAIL** |
| content_editor 漫画权限 | **FAIL** |

---

## 八、未解决问题与建议优先级

1. **P0**：修复 `ComicStatsManager::topComics()` 查询写法 → 恢复 `/admin/xedc/comic/statistics`
2. **P1**：章节单图/多图上传，或产品明确仅 ZIP
3. **P2**：导入取消/重试后台 UI（`cancelJob` 已有）
4. **P2**：`content_editor` 权限与 `xedc_comic_install` 授予策略对齐
5. **P3**：`navigation.theme` 漫画入口（需确认是否允许改主题）
6. **P3**：后台阅读进度列表（新路由，读 `xedc_comic_reading`）

---

## 九、相关文档

- `XEDC_COMIC_REPORT.md` — 模块总体交付
- `XEDC_COMIC_CONTENT_MODEL_ACCEPTANCE.md` — 内容模型验收

---

*本文档仅记录核实结果；修复实施需单独确认后执行。*