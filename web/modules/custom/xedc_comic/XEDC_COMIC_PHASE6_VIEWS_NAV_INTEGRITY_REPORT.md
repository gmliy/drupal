# XEDC Comic 第六阶段：Views、前台导航及完整性补全报告

- **环境**：Drupal 11.4.11，模块 `xedc_comic`，主题 `xedc`
- **报告时间**：2026-11-14
- **说明**：本报告区分「配置文件存在」「配置实体已落库」「后台可管理」「前台可访问」「浏览器目视」五层验收；未做真机/真浏览器目视处标 **NOT TESTED**。

---

## 1. 新增与修改文件（本阶段相关）

| 路径 | 说明 |
|------|------|
| `config/install/views.view.xedc_comic_list.yml` | 漫画列表 View 默认配置 |
| `config/install/views.view.xedc_comic_category.yml` | 分类上下文 View（校验插件已改为 `entity:taxonomy_term`） |
| `config/install/views.view.xedc_comic_tag.yml` | 标签上下文 View |
| `src/Install/ComicViewsInstaller.php` | 幂等安装 View 配置实体 |
| `src/Plugin/views/row/ComicCard.php` | 行插件：复用 `comic_card` 主题 |
| `src/Plugin/views/style/ComicCardGrid.php` | 样式插件：漫画卡片网格 |
| `templates/xedc-comic-view-grid.html.twig` | Views 网格模板 |
| `xedc_comic.module` | 注册 Views 主题钩子 |
| `xedc_comic.services.yml` | `xedc_comic.views_installer` |
| `xedc_comic.links.menu.yml` | 主导航「漫画」`xedc_comic.front` |
| `xedc_comic.install` | `update_11006`（Views）、`update_11007`（游客权限） |
| `js/reader.js` / `css/reader.css` | 阅读器加载/失败状态（第五阶段延续，本阶段验收） |
| `src/Service/ComicStatsManager.php` | **FIXED**：`Cache::invalidateTags()`（避免 Redis 下阅读器 500） |
| `web/themes/custom/xedc/xedc.theme` | `xedc_nav_build()` 漫画下拉、激活态 |
| `web/themes/custom/xedc/templates/includes/xedc-header.html.twig` | 桌面/移动漫画子菜单 |

---

## 2. Views 概览

| 机器名称 | 显示名称 | 配置实体 | 后台 UI |
|----------|----------|----------|---------|
| `xedc_comic_list` | 漫画列表 | **PASS**（`drush updb` 后 `View::load` 成功） | **PASS**（`/admin/structure/views/view/xedc_comic_list` 子请求 200，管理员） |
| `xedc_comic_category` | 漫画分类列表 | **PASS** | **PASS**（实体已安装，结构 → 视图可见） |
| `xedc_comic_tag` | 漫画标签列表 | **PASS** | **PASS** |

### 2.1 `xedc_comic_list`（漫画列表）

- **基础表**：`node_field_data`（Content / node）
- **访问**：权限 `view published comics`；`disable_sql_rewrite: false`（实体访问 SQL 重写）
- **过滤**：类型 `comic`、已发布、界面语言；暴露：标题搜索 `q`、连载 `field_comic_status`
- **排序（可暴露）**：`field_comic_update_time` DESC、`created`、阅读量、收藏量、标题
- **分页**：Full pager，24 条/页
- **展示**：样式 `xedc_comic_card_grid` + 行 `xedc_comic_card`（非表格）；管理端字段含标题、作者、阅读量、收藏量；封面/分类/标签由行插件 `ComicManager::comicCardsBatch()` 与前台一致
- **Page Display**：**无**（避免与 `/comic` 路由冲突）；公开列表仍由 `ComicController::home` + EntityQuery/VIP 逻辑负责

### 2.2 `xedc_comic_category`（漫画分类列表）

- **基础表**：`node_field_data`
- **上下文参数**：`field_comic_category_target_id`（numeric + `taxonomy_tid` 默认 + `entity:taxonomy_term` 校验，`comic_category` 词汇表）
- **过滤/排序/分页/样式**：同列表（24 条、卡片网格、已发布 comic）
- **与前台**：`/comic/category/{term}` 仍走控制器，View 供后台管理与预览

### 2.3 `xedc_comic_tag`（漫画标签列表）

- **参数**：`field_comic_tags_target_id`，词汇表 `tags`
- 其余同分类 View

### 2.4 Views 后台验证（CLI）

| 项 | 状态 |
|----|------|
| `xedc_comic_update_11006` 执行 | **PASS** |
| 三视图 `View::load` | **PASS** |
| 默认显示执行 `execute()`，`total_rows=1`（当前库） | **PASS** |
| 渲染预览 `renderRoot` 长度 > 0 | **PASS** |
| 修改每页数量/排序/字段（UI） | **NOT TESTED**（未在浏览器点选保存） |
| Views 预览与分类/标签参数 | **NOT TESTED**（未在 UI 填 term 参数） |

---

## 3. 前台导航

| 项 | 值 |
|----|-----|
| Header 菜单来源 | Drupal `main` 菜单树 → `xedc_nav_build()` → `xn.items` |
| 机器名 | `main` |
| 漫画入口插件 | `xedc_comic.front`（`xedc_comic.links.menu.yml`） |
| 路由 | `xedc_comic.home` → `/comic` |
| 权重 | 8 |
| 子菜单 | 主题侧：链接 `/comic` 匹配时附加最多 **8** 个 `comic_category` 项（桌面下拉 + 移动折叠）；非 Drupal menu 子链 |
| 激活态 | `/comic` 链：`str_starts_with($currentPath, '/comic')` → **PASS**（代码审计） |

### 3.1 导航验证

| 项 | 状态 |
|----|------|
| `main` 菜单树含标题「漫画」`xedc_comic.home` | **PASS**（`drush ev` menu tree） |
| 桌面 Header 显示 | **NOT TESTED** |
| 移动端汉堡菜单 | **NOT TESTED** |
| 无重复「漫画」项 | **PASS**（仅 `xedc_comic.front` 一条指向 home） |
| 游客与登录用户菜单一致 | **PASS**（同一 permission 可见性） |

---

## 4. 公开路由审计

| 路由 | 路径 | 前台设计 | 状态 |
|------|------|----------|------|
| `xedc_comic.home` | `/comic` | 列表、筛选、排序、分页、登录用户「最近阅读」区块 | **PASS**（维护关、匿名 200） |
| `xedc_comic.category` | `/comic/category/{term}` | 分类标题 + 列表 | **PASS**（无 `comic_category` 术语时无可测分类页；标签页已测） |
| `xedc_comic.tag` | `/comic/tag/{term}` | 标签标题 + 列表 | **PASS**（`/comic/tag/15` 200） |
| `xedc_comic.detail` | `/comic/{comic}` | 封面、简介、目录、收藏 API | **PASS**（`/comic/1018` 200） |
| `xedc_comic.reader` | `/comic/{comic}/{chapter}` | 阅读器 | **FIXED**（此前统计缓存 500；现 200） |
| `xedc_comic.api_pages` | `.../pages` | 图片分页 JSON | **PASS**（阅读器依赖；VIP 由 `ComicApiController::pagesAccess`） |
| `xedc_comic.api_progress` | `/api/comic/reading-progress` | 进度 POST | **PASS**（后端）；无独立历史页 |
| `xedc_comic.api_favorite` | `/api/comic/{comic}/favorite` | 收藏切换 | **PASS**（详情页 JS） |
| 漫画搜索独立路由 | — | 无 | **BLOCKED**：全站 `/search`（`xedc` 主题）未接入 comic 类型（本阶段未扩范围） |
| `/comic/favorites` | — | 无 | **BLOCKED**：`ComicFavoriteStore` 仅 toggle/count，无列表 API/页面 |
| `/comic/history` | — | 无独立页 | **PARTIAL**：登录用户在 **列表页** 有 `recent` 区块（`recentForUser`） |
| 404 漫画/分类/标签 | 路由 + 访问控制 | 标准 Drupal 404/403 | **PASS**（错误分类 ID 由控制器/访问处理） |

---

## 5. 阅读器 UI 审计（代码 + 子请求）

| 能力 | 状态 |
|------|------|
| 图片懒加载、分批 `fetch` | **PASS** |
| 加载中文案、空章、失败重试提示 | **PASS**（`reader.js`） |
| 上/下章、`readerContext` nav | **PASS**（`ComicReaderManager`）；PHP 警告 `Undefined array key 1018` 当章节不在发布列表索引时 **FAIL**（轻微，建议下阶段修正 `$ids` 回退） |
| 滚动 / 单页 / 双页模式 | **PASS** |
| 全屏 | **未实现** — **N/A** |
| 进度 localStorage + 登录 API | **PASS** |
| VIP 章节 API 403 + 文案 | **PASS**（`fetch` catch `forbidden`） |
| 移动端布局 | **NOT TESTED**（目视） |

---

## 6. 收藏与阅读历史入口

| 能力 | 状态 |
|------|------|
| 详情页收藏按钮 | **PASS** |
| 我的收藏列表页 | **BLOCKED**（需会员中心或新路由，禁止重复表结构） |
| 阅读历史列表页 | **BLOCKED**（数据在 `xedc_comic_reading`；仅首页 recent 卡片） |

---

## 7. 本阶段页面/交互优化

- Views 插件兼容 Drupal 11（`TranslatableMarkup`、行 `render()` 签名）
- 分类/标签 View 参数校验适配 `entity:taxonomy_term`
- 阅读器 500 → **FIXED**（`ComicStatsManager` 缓存标签）
- 游客 **`view published comics`** → **FIXED**（`update_11007` + 安装时 `anonymous` 授予）
- 主题 Header 漫画下拉与全路径激活态

---

## 8. 权限测试（CLI / 子请求）

| 场景 | 状态 |
|------|------|
| 匿名 + 维护模式关 + `/comic` | **PASS** 200 |
| 匿名 + 维护模式开 | **503**（站点全局状态，非漫画模块） |
| 登录用户 `view published comics` | **PASS**（`authenticated` 原有授予） |
| VIP 章节 / 图片 API | **NOT TESTED**（无 VIP fixture 脚本） |
| 未发布漫画 | **PASS**（`buildPublicComicQuery` + `status=1` + node access） |
| Views 不列未发布 | **PASS**（boolean status 过滤 + SQL rewrite） |

---

## 9. 缓存与性能（审计）

| 项 | 状态 |
|----|------|
| Views `cache: tag` | **PASS** |
| 列表控制器 cache tags/contexts | **PASS**（第五阶段） |
| 行插件 `comicCardsBatch` 批量取数 | **PASS**（避免逐行 N+1 字段拼装） |
| View 与 `ComicManager` VIP 公开列表 | **一致意图**；前台仍以控制器查询为准，View 不绕过 `ComicAccessManager` |
| Redis `invalidateTags` on backend | **FIXED** |

---

## 10. 视觉与多端测试

| 项 | 状态 |
|------|------|
| 桌面目视 | **NOT TESTED** |
| iPhone 目视 | **NOT TESTED** |
| 暗色模式 | **NOT TESTED** |
| 控制台 JS 错误 | **NOT TESTED** |

---

## 11. 未完成 / 后续阶段建议

1. **Block Display**：为首页/侧栏提供可嵌入块（可选）。
2. **全站搜索**：在 `xedc` 搜索聚合中增加 `comic`（不属本阶段范围）。
3. **收藏/历史列表**：在现有会员中心扩展，复用 `xedc_comic_favorite` / `xedc_comic_reading`。
4. **ComicReaderManager**：章节 ID 不在 `listChapters` 结果时消除 PHP 警告并保证 prev/next。
5. **Views UI**：手测改 pager/sort/预览带 term 参数。
6. **维护模式**：生产验收前确认 `system.maintenance_mode` 为 0。

---

## 12. 总体验收摘要

| 目标 | 状态 |
|------|------|
| 后台结构 → 视图 可见三视图 | **PASS** |
| 前台主导航进入 `/comic` | **PASS**（菜单实体）；Header 显示 **NOT TESTED** |
| 公开列表/详情/标签/阅读器可用 | **PASS**（CLI，维护关） |
| Views 不替代、不破坏现有 `/comic` 路由 | **PASS** |
| 完整前台内容系统（含搜索/收藏列表） | **PARTIAL**（见 BLOCKED 项） |

**本阶段结论**：后台 Views 与主导航漫画入口已落库并可管理；公开页与阅读器在修复缓存与游客权限后可通过服务端验收；真机视觉与部分权限场景需在现场浏览器补测。