# XEDC Comic 第六阶段：Views、前台导航及完整性补全 — 交付报告

**Drupal** 11.4.11 · **模块** `xedc_comic` · **主题** `xedc`  
**审计基线**：[`XEDC_COMIC_PHASE6_PREAUDIT.md`](XEDC_COMIC_PHASE6_PREAUDIT.md)  
**Git**：`main` @ `a726ee8`，工作区含未提交之 `xedc_admin_ui` / `xedc` / `xedc_comic`（未 reset/clean）

---

## 1. 新增 / 修改文件（本阶段增量）

| 文件 | 说明 |
|------|------|
| `config/install/views.view.xedc_comic_*.yml` | 三视图默认配置 |
| `src/Install/ComicViewsInstaller.php` | 安装视图 + `ensureBlockDisplays()` |
| `src/Plugin/views/{row,style}/*` | 漫画卡片 Views 插件 |
| `xedc_comic.install` | `11006` Views · `11007` 游客权限 · `11008` Block |
| `xedc_comic.links.menu.yml` | `main` 菜单「漫画」 |
| `src/Controller/ComicMemberController.php` | `/member/comic-favorites` · `/member/comic-history` |
| `templates/comic-member-*.twig` | 会员漫画收藏/历史 |
| `js/comic-member.js` · `css/comic.css` | 会员页 + 列表入口样式 |
| `js/reader.js` · `css/reader.css` · `comic-reader.html.twig` | 阅读器工具栏/全屏/重试/章节序号 |
| `js/comic-detail.js` | 收藏 API CSRF |
| `src/Service/ComicStatsManager.php` | Redis 标签失效修复 |
| `src/Service/ComicFavoriteStore.php` · `ComicReadingStore.php` | 列表/计数/删除 |
| `xedc_comic.routing.yml` | 会员页 + DELETE 阅读历史 API |
| `xedc_comic.module` | 会员 shell 导航注入 |
| `themes/custom/xedc/templates/xedc-member-shell.html.twig` | 侧栏增加漫画收藏/历史键 |
| `themes/custom/xedc/xedc.theme` · `xedc-header.html.twig` | 主导航漫画与子分类 |

---

## 2. Views 配置实体

| 机器名 | 显示名 | 基础表 | 分页 | 样式 |
|--------|--------|--------|------|------|
| `xedc_comic_list` | 漫画列表 | `node_field_data` (comic) | 24/full | `xedc_comic_card_grid` + `xedc_comic_card` |
| `xedc_comic_category` | 漫画分类列表 | 同上 + 参数 `field_comic_category` | 24 | 同上 |
| `xedc_comic_tag` | 漫画标签列表 | 同上 + 参数 `field_comic_tags` (tags) | 24 | 同上 |

**过滤**：已发布、`bundle=comic`、`disable_sql_rewrite=false`、权限 `view published comics`。  
**排序（列表，可暴露）**：更新时间、创建、阅读、收藏、标题。  
**显示**：Default + Block（`block_xedc_comic_*`），**无**与 `/comic` 冲突的 Page Display。  
**字段（后台 UI）**：标题/作者/阅读/收藏等；封面/分类/标签/连载由行插件与 `ComicManager` 与前台一致。

### Views 验收（CLI）

| 项 | 状态 |
|----|------|
| 配置实体落库（`View::load`） | **PASS** |
| Default + Block display 存在（`11008` 后） | **PASS** |
| 后台 `/admin/structure/views/view/xedc_comic_list`（uid1 子请求） | **PASS** 200 |
| 默认显示 execute + render | **PASS** |
| UI 修改 pager/sort/预览带 term | **NOT TESTED** |

---

## 3. 前台导航

| 项 | 值 |
|----|-----|
| 菜单 machine name | **`main`** |
| 漫画链接 | `xedc_comic.front` → `xedc_comic.home` → `/comic` |
| 桌面/移动 | 同一 `xn.items`；`/comic` 链路上 **active** = 全部 `/comic*` |
| 子分类 | 主题注入最多 8 个 `comic_category`（非海量菜单项） |

| 验收 | 状态 |
|------|------|
| 菜单树含「漫画」 | **PASS** |
| 桌面/移动 Header 目视 | **NOT TESTED** |
| 列表/分类/标签/详情/阅读器 active | **PASS**（代码） / **NOT TESTED**（浏览器） |

---

## 4. 公开路由审计

| 页面 | 状态 | 备注 |
|------|------|------|
| 漫画首页 | **已完成并已验证**（CLI 200） | 筛选/排序/分页；登录用户「最近阅读」区块 |
| 漫画分类 | **已完成未全面验证** | 无 `comic_category` 术语时无可测 URL |
| 漫画标签 | **已完成并已验证** | `/comic/tag/15` |
| 漫画详情 | **已完成并已验证** | 收藏 + CSRF |
| 阅读器 | **FIXED + 已验证** | 章节序号、全屏、重试、`?page=` 续读 |
| 全站 `/search` 漫画 | **未实现** | 属 xedc 搜索聚合，本阶段未改 |
| `/member/comic-favorites` | **FIXED（新建）** | 独立表，非 xedc_member 收藏 |
| `/member/comic-history` | **FIXED（新建）** | 可删单条（DELETE API + CSRF） |
| VIP / 404 | **已实现未全测** | 逻辑在 `ComicAccessManager` |
| 移动端响应式 | **NOT TESTED** | CSS 已有断点 |

---

## 5. 阅读器 UI 审计

| 能力 | 状态 |
|------|------|
| 返回详情、漫画/章节标题、序号 | **PASS** |
| 滚动/单页/双页、上下章 | **PASS** |
| 分页 API、懒加载、失败重试 | **PASS** |
| 全屏 | **PASS**（`requestFullscreen`） |
| 进度 localStorage + 登录 API | **PASS** |
| 游客 vs 登录服务端记录 | **已区分**（文案 + API 登录要求） |

---

## 6. 收藏与阅读历史

| 能力 | 状态 |
|------|------|
| 详情收藏 toggle | **PASS**（+ CSRF **FIXED**） |
| 漫画收藏列表页 | **PASS**（路由 + 模板） / 浏览器 **NOT TESTED** |
| 阅读历史列表 + 继续阅读 | **PASS** |
| 并入 `/member/favorites` 统一表 | **BLOCKED**（设计为 `xedc_comic_favorite` 独立表） |

---

## 7. 权限与安全（抽样）

| 项 | 状态 |
|----|------|
| 游客 `view published comics` | **FIXED**（`11007`） |
| 维护模式下匿名 503 | **已知**（`system.maintenance_mode`） |
| 收藏/删历史 CSRF | **PASS** |
| Views SQL rewrite | **PASS** |
| 阅读器统计 Redis 500 | **FIXED** |

---

## 8. 视觉与多端

| 项 | 状态 |
|----|------|
| 桌面 / iPhone / 暗色 | **NOT TESTED**（非 CSS 存在即 PASS） |

---

## 9. 生产验收清单映射

### A. Views — 后台可见、可开、实体存在 **PASS**；UI 手测 **NOT TESTED**

### B. 导航 — 菜单 **PASS**；目视 **NOT TESTED**

### C. 前台 — 首页/详情/标签/阅读器 **PASS**（维护关、CLI）；分类 **PARTIAL**

### D–E. 权限/视觉 — 见上表

---

## 10. 未完成 / 后续

1. 真机与暗色模式浏览器验收  
2. Views UI：字段/排序/pager/带 term 预览  
3. 全站搜索接入 `comic`  
4. 可选：将漫画块布到首页（`block_xedc_comic_list`）  
5. 生产关闭维护模式后再验游客路径  

**结论**：第六阶段核心交付（Views 实体 + Block、主导航、会员漫画收藏/历史、阅读器补强、权限修复）已达可管理、可路由、可 CLI 验收；**目视与部分 UI 操作仍须现场补测**。