# XEDC Comic Center — 开发交付说明

**模块**：`xedc_comic`  
**Drupal**：11.4.x · **PHP**：8.5  
**日期**：2026-11-14  

本文档记录漫画系统 `xedc_comic` 模块的审计结论、实现范围、启用方式、测试状态与已知限制。

---

## 一、安全约束执行情况

开发过程中遵守以下约束（与任务书一致）：

- 未修改 `config/sync`、Drupal Core、Contrib、Nginx/PHP-FPM/MariaDB/R2 全局配置。
- 未修改既有 `photo` / `video` / `article` 内容类型及其他模块业务逻辑。
- 未直接改表结构（除本模块 `hook_schema` 与 `comic_page` 实体表）。
- 图片上传走 `xedc_core` Storage Hub 文件流（`public://comics/...` → 现有公开前缀策略）。
- ZIP 解压复用 `xedc_core.archive`（`ArchiveManager`），含路径穿越防护与自然排序。

---

## 二、阶段 1 项目审计摘要

| 领域 | 可复用组件 |
|------|------------|
| 对象存储 | `xedc_core.storage_*`、`LocalFileBridge`、`RemoteFileWriter`、`DeliveryUrl`、`StorageUriResolver` |
| 图片优化 | `xedc_core.images`（`ImagePipeline::validate`）、`xedc_core.image_optimizer`、`xedc_core.image_optimize_repository` |
| ZIP | `xedc_core.archive`（`openAndExtract`、`sortFiles`、`unsafeRelative`） |
| VIP | `xedc_core.membership`（`MembershipManager::isActive`） |
| 收藏（全站会员中心） | `xedc_member.favorite_manager`，仅 `photo`/`video`/`article` — **未扩展** |
| 后台规范 | `/admin/xedc/*` + `xedc_admin` 主题；侧栏定义在 `xedc_admin/includes/navigation.theme` |

**潜在冲突**：会员中心收藏与 `xedc_notify` 更新提醒需改其他模块或主题，本次采用模块内方案或标记 BLOCKED。

---

## 三、架构与内容模型

### 3.1 节点类型（`xedc_comic.install` + `ContentInstaller` 创建，不写 config/sync）

| 类型 | machine name | 主要字段 |
|------|----------------|----------|
| 漫画 | `comic` | 封面、作者、分类、标签、简介、连载状态、更新时间、排序、阅读量、收藏量 |
| 章节 | `comic_chapter` | 所属漫画、序号、VIP/免费、发布时间、页数、章节状态 |

**词汇表**：`comic_category`（漫画分类）

### 3.2 自定义实体

| 实体 | ID | 说明 |
|------|-----|------|
| 漫画页 | `comic_page` | `chapter_id`、`image_file_id`、`page_number`、尺寸、文件名等；图片仅存 File 实体，二进制不入库 |

### 3.3 模块自建表

- `xedc_comic_import` / `xedc_comic_import_item` / `xedc_comic_import_log` — ZIP 导入任务
- `xedc_comic_reading` — 登录用户阅读进度
- `xedc_comic_favorite` — 漫画收藏（独立于 `xedc_member_favorite`）
- `xedc_comic_stats_daily` — 按天阅读汇总（Flood 限流后写入）

### 3.4 存储路径

默认配置 `paths.file_prefix: comics`：

```
public://comics/{comic_id}/{chapter_id}/p{页码}-{hash}.{ext}
```

对象键遵循现有 Storage Hub 公共文件夹前缀（与图库一致，非第二套 R2 配置）。

---

## 四、服务（`xedc_comic.services.yml`）

| 服务 ID | 职责 |
|---------|------|
| `xedc_comic.content_installer` | 安装/卸载内容模型 |
| `xedc_comic.manager` | 列表、章节、计数卡片 |
| `xedc_comic.storage` | 校验、优化、落盘、Delivery URL |
| `xedc_comic.import` | ZIP 任务、队列阶段 |
| `xedc_comic.reader` | 阅读器上下文、分页数据 |
| `xedc_comic.access` | 公开/VIP/管理权限 |
| `xedc_comic.reading` | 阅读进度持久化 |
| `xedc_comic.favorites` | 收藏切换与计数回写 |
| `xedc_comic.stats` | 阅读统计与排行榜 |
| `xedc_comic.page_repository` | 页实体分页查询 |
| `xedc_comic.seo` | Meta / Canonical / OG / JSON-LD |

**队列**：`xedc_comic_import`（`ComicImportWorker`），`hook_cron` 与导入表单会入队。

---

## 五、路由与前台页面

| 路径 | 路由名 | 说明 |
|------|--------|------|
| `/comic` | `xedc_comic.home` | 漫画首页（推荐区、搜索筛选） |
| `/comic/{comic}` | `xedc_comic.detail` | 详情、章节目录、收藏 |
| `/comic/{comic}/{chapter}` | `xedc_comic.reader` | 阅读器壳 |
| `/comic/{comic}/{chapter}/pages` | `xedc_comic.api_pages` | 分页图片 JSON（**含 VIP 校验**） |
| `/api/comic/reading-progress` | `xedc_comic.api_progress` | POST 阅读进度（登录 + CSRF） |
| `/api/comic/{comic}/favorite` | `xedc_comic.api_favorite` | POST 收藏切换 |

### 阅读器

- **滚动** / **单页** / **双页**（窄屏隐藏双页）
- 懒加载 + 分批 API（默认每批 8 张，上限 40）
- 进度：游客 `localStorage`；登录用户合并服务端 `xedc_comic_reading`
- 库：`xedc_comic/reader`（`js/reader.js`、`css/reader.css`）

---

## 六、后台菜单

**Drupal 菜单**（`xedc_comic.links.menu.yml`）：内容 → **漫画中心** → `/admin/xedc/comic`

| 子页 | 路径 | 权限 |
|------|------|------|
| 概览 | `/admin/xedc/comic` | `administer comics` |
| 漫画管理 | `/admin/xedc/comic/comics` | `administer comics` |
| 章节管理 | `/admin/xedc/comic/chapters` | `manage comic chapters` |
| 批量章节 | `/admin/xedc/comic/chapters/batch` | `manage comic chapters` |
| 图片管理 | `/admin/xedc/comic/pages?chapter={nid}` | `manage comic pages` |
| ZIP 导入 | `/admin/xedc/comic/import` | `import comic archives` |
| 导入进度 | `/admin/xedc/comic/import/status?job={id}` | `import comic archives` |
| 阅读统计 | `/admin/xedc/comic/statistics` | `view comic statistics` |
| 设置 | `/admin/xedc/comic/settings` | `administer comics` |

分类维护：`/admin/structure/taxonomy/manage/comic_category/overview`

**说明**：`xedc_admin` 左侧飞厢导航 **未修改**（避免未经允许改主题）。后台可从上述 URL 或「内容」菜单进入。

---

## 七、ZIP 导入流程

1. 选择漫画 → 新建或选择章节 → 上传 ZIP（`private://xedc-comic-import`）
2. 创建 `xedc_comic_import` 任务 → 队列 `phase=extract` 调用 `ArchiveManager::openAndExtract`
3. 扫描图片、**自然排序**、写入 `xedc_comic_import_item`
4. 队列 `phase=process` 批处理（`import.batch_size`，默认 5）→ `ComicStorageManager::createPageFromPath`
5. 更新章节 `field_comic_page_count`、漫画 `field_comic_update_time`
6. 状态：`queued` / `processing` / `completed` / `partial` / `failed` / `cancelled`；进度取自真实 `processed/total`

安全：继承核心压缩包大小限制；解压目录 `private://xedc-import/tasks/{import_id}/`（与图库导入任务目录命名类似）。

---

## 八、权限（`xedc_comic.permissions.yml`）

| 权限 | 用途 |
|------|------|
| `administer comics` | 漫画中心管理 |
| `create comics` / `edit own comics` / `edit any comics` / `delete comics` | 内容 CRUD |
| `manage comic chapters` / `manage comic pages` | 章节与页 |
| `import comic archives` | ZIP 导入 |
| `view comic statistics` | 统计页 |
| `view published comics` | 前台阅读（`authenticated` 安装时授予） |

VIP 章节：`field_comic_vip` 且非 `field_comic_free` 时要求 `xedc_core.membership` 有效 VIP；**图片 API 同样校验**，不仅隐藏按钮。

---

## 九、SEO

详情页附加：Meta Description、Canonical、Open Graph、Schema.org `ComicSeries` JSON-LD（`ComicSeoBuilder`）。不覆盖全站既有 SEO 模块配置。

---

## 十、测试结果

| 检查项 | 状态 | 说明 |
|--------|------|------|
| PHP 语法 | **PASS** | 模块内 `.php` 经 `php -l` |
| 模块启用 | **PASS** | `drush pm:install xedc_comic` |
| 内容类型 `comic` | **PASS** | 含 `field_comic_cover`（image） |
| 实体 `comic_page` | **PASS** | |
| 路由注册 | **PASS** | `drush route` 列出全部 `xedc_comic.*` |
| 服务容器 | **PASS** | `xedc_comic.import` 等可解析 |
| Drupal CodeSniffer | **NOT TESTED** | 环境未安装 phpcs |
| PHPUnit | **NOT TESTED** | vendor 无 phpunit；已提供 `tests/src/Unit/NaturalSortTest.php` |
| ZIP/R2 生产导入 | **NOT TESTED** | 需测试环境与真实压缩包 |
| 前台 HTTP `/comic` | **FAIL** | 本机 `curl` 404，疑虚拟主机/站点 URL，非路由未注册 |
| 会员中心收藏集成 | **BLOCKED** | 未改 `xedc_member` |
| xedc_admin 侧栏入口 | **BLOCKED** | 未改 `navigation.theme` |
| xedc_notify 收藏提醒 | **BLOCKED** | 未对接 |

---

## 十一、已知问题与未完成项

1. **收藏**：仅在漫画详情与 `xedc_comic_favorite`；不出现在 `/member/favorites`。
2. **后台图片**：列表预览为主；单张替换/批量删除 UI 未做完整表单（可删实体或重导 ZIP）。
3. **阅读器**：RTL、全屏、断点合并登录前 local 记录等可再增强。
4. **导入解压路径**与图库任务共用 `xedc-import/tasks/{id}` 命名空间，运维时勿与图库导入 ID 冲突（不同表主键，目录 ID 为漫画 import id）。
5. **章节删除**：`hook_entity_delete` 删除本章所有 `comic_page` 及关联 file usage；共享文件去重仍依赖 `xedc_core` 全局规则。

---

## 十二、启用与回滚

### 启用

```bash
cd /var/www/drupal
drush pm:install xedc_comic -y
drush cr
```

### 回滚（无漫画数据时）

```bash
drush pm:uninstall xedc_comic -y
drush cr
```

若已有 `comic` / `comic_chapter` 节点，须先删除或迁移后再卸载（卸载会尝试移除空 bundle）。

### 创建第一本漫画（管理 UI）

```bash
# 浏览器：/node/add/comic
# 或 drush（示例，请勿在生产乱写测试数据）
# drush node:create --type=comic --title="示例" --status=0
```

---

## 十三、新增文件清单（概要）

```
web/modules/custom/xedc_comic/
├── xedc_comic.info.yml
├── xedc_comic.module
├── xedc_comic.install
├── xedc_comic.routing.yml
├── xedc_comic.services.yml
├── xedc_comic.permissions.yml
├── xedc_comic.libraries.yml
├── xedc_comic.links.menu.yml
├── xedc_comic.links.task.yml
├── XEDC_COMIC_REPORT.md          ← 本文件
├── config/install/xedc_comic.settings.yml
├── config/schema/xedc_comic.schema.yml
├── src/Entity/ComicPage.php + Access + StorageSchema
├── src/Controller/*.php
├── src/Form/*.php
├── src/Service/*.php
├── src/Install/ContentInstaller.php
├── src/Repository/ComicPageRepository.php
├── src/Routing/ComicRouteSubscriber.php
├── src/Plugin/QueueWorker/ComicImportWorker.php
├── templates/*.html.twig
├── css/*.css
├── js/*.js
└── tests/...
```

---

## 十四、可选后续（需你确认再做）

1. 在 `xedc_admin/includes/navigation.theme`「内容管理」下增加「漫画中心」链接。
2. 通过 **event / 小范围补丁** 将 `comic` 纳入会员收藏（若允许极小改动 `xedc_member`）。
3. 测试环境执行真实 ZIP → R2 对象校验与 VIP 越权 API 测试。
4. 接入现有 Sitemap 模块（若项目有统一 XML Sitemap 扩展点）。

---

**维护**：功能变更请先读本模块 `config/schema` 与 `SPEC` 类约束；存储问题以 `xedc_core` Storage 文档与 Journal 为准。

---

## 十五、表单字段显示修复（2026-11-14）

### 根因

- 字段 Storage / Field Config **已创建**，但 `entity_form_display.node.comic.default` 与 `comic_chapter.default` **不存在**。
- 原 `ContentInstaller::ensureDisplays()` 仅在「表单显示已存在」时追加组件；字段已存在时提早 `return`，**从未创建 Form Display**。

### 修复

- 重写 `ContentInstaller::repairContentModel()`：幂等创建/更新全部字段 + `syncFormDisplay()` / `syncViewDisplay()`。
- 新增 `xedc_comic_update_11001()`，已启用站点执行 `drush updb` 即可补齐。
- 系统维护字段在表单中隐藏：`field_comic_views`、`field_comic_favorites`、`field_comic_update_time`（漫画）、`field_comic_page_count`（章节）。

### 章节状态字段名

- 章节使用 `field_comic_chapter_status`（非 `field_comic_status`），避免与漫画连载状态共用 `list_string` 存储导致选项冲突。

### 验证结果

| 项 | 状态 |
|----|------|
| `entity_form_display.node.comic.default` | **FIXED** |
| `entity_form_display.node.comic_chapter.default` | **FIXED** |
| 漫画表单字段（封面/作者/分类/标签/简介/连载/排序） | **PASS** |
| 章节表单字段（所属漫画/序号/VIP/免费/发布时间/章节状态） | **PASS** |
| `entity_view_display` 默认 | **FIXED** |
| `comic_category` 词汇表 | **PASS** |
| `drush updb` + `drush cr` | **PASS** |
| 字段保存与回显（Drush 测试节点） | **PASS** |
| 浏览器 `/node/add/comic` 人工点检 | **NOT TESTED**（请本地确认） |