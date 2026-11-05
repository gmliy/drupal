# XEDC 智能标签管理 — 后台 UI 统一与左侧导航整合报告

日期：2026-11-04  
模块：`web/modules/custom/xedc_tags`  
环境：Drupal 11.4.11 / PHP 8.5.12 / Gin 5.0.16

## 1. UI 改造内容

- 仪表盘 `/admin/content/tags` 改为 Gin + XEDC Modern SaaS 布局：页头（标题/说明/最近扫描/状态）、核心统计、管理快捷入口、最近活动。
- 统计与快捷入口全部来自现有 `TagCatalog` / 健康 / SEO / AI / 审计 / 任务服务，模板不写死数字。
- 标签列表压缩筛选栏、低饱和状态徽标、数字对齐、批量操作条；保留 Drupal 分页与查询逻辑。
- 合并页按步骤展示（来源 → 目标 → 预览 → 影响 → 确认 → 回滚入口）；默认不创建 301，勾选 301 需二次确认。业务仍走 `TagMerger`。
- 各功能表单统一 `AdminFormBase` 页头与「返回总览」；控制器只读页共用同一 chrome。
- 新 CSS `css/admin.css` 全面改用 Gin CSS 变量（`--gin-*`），并适配 `html.gin--dark-mode`。

## 2. 左侧菜单实现方式

- **沿用 Drupal Menu API**，挂在系统「内容」菜单：`parent: system.admin_content`。
- 项目侧栏由 Gin 渲染 admin 菜单；`xedc_admin_nav` 仅前台导航，**未新建第二套侧边栏**。
- 去掉原先 12 个子菜单项，避免侧栏展开过多；子功能改由 **Local tasks（顶部标签）+ 仪表盘快捷入口** 进入。
- `XedcTagsServiceProvider` 将 `menu.active_trail` 替换为 `TagMenuActiveTrail`：访问任意 `xedc_tags.admin*` 路由时，侧栏「智能标签管理」保持激活。

## 3. 菜单所在位置

| 项 | 值 |
| --- | --- |
| 一级 | 内容管理（`system.admin_content`） |
| 子项显示名 | 智能标签管理 |
| 插件 ID | `xedc_tags.admin` |
| 路由 | `xedc_tags.admin_overview` |
| 路径 | `/admin/content/tags` |
| weight | 18（紧随现有内容相关项） |

未放入系统设置、运维中心或 SEO 菜单；未重复创建「内容管理」一级菜单。

## 4. 菜单权限

- 菜单链接跟随路由 `xedc_tags.admin_overview` 的 `_permission: view xedc tag center`。
- 子页面各自保留原路由权限（analysis / merge / aliases / run analysis 等）。
- 快捷入口按当前用户权限过滤；无权限不显示入口。
- 抽测：anonymous / authenticated 无法访问总览；`content_editor` 可查看中心、不可合并。

## 5. 修改文件清单（本轮 UI/导航相关）

**新增**

- `src/Menu/TagMenuActiveTrail.php`
- `src/XedcTagsServiceProvider.php`
- `templates/xedc-tags-admin-dashboard.html.twig`

**更新**

- `xedc_tags.links.menu.yml`（仅保留内容管理下一项）
- `xedc_tags.links.task.yml`（仪表盘标签文案）
- `xedc_tags.routing.yml`（总览标题）
- `xedc_tags.module`（dashboard theme hook）
- `xedc_tags.libraries.yml`
- `css/admin.css`
- `templates/xedc-tags-metrics.html.twig`
- `src/Controller/AdminController.php`
- `src/Form/AdminFormBase.php`
- `src/Form/ListActionForm.php`
- `src/Form/MergeForm.php`
- `src/Form/{Health,Duplicate,Alias,Recommend,AiAnalyze,Task,Unused}Action/Form.php`（统一 chrome）

未改：`TagMerger` 合并/回滚核心逻辑、表结构、前台 `/tags` 业务、Drupal Core / Gin 源码、`config/sync` 无关项。未 commit / push。

## 6. 页面覆盖范围

| 路径 | 状态 |
| --- | --- |
| `/admin/content/tags` | 新仪表盘 |
| `/admin/content/tags/list` | 列表 UI |
| `/admin/content/tags/health` | 统一 chrome |
| `/admin/content/tags/duplicates` | 统一 chrome |
| `/admin/content/tags/merge` | 分步 UI |
| `/admin/content/tags/aliases` | 统一 chrome |
| `/admin/content/tags/recommend` | 统一 chrome |
| `/admin/content/tags/relations` | 统一 chrome |
| `/admin/content/tags/seo` | 统一 chrome + 徽标 |
| `/admin/content/tags/ai` | 统一 chrome；不暴露密钥 |
| `/admin/content/tags/tasks` | 统一 chrome |
| `/admin/content/tags/unused` | 统一 chrome |
| `/admin/content/tags/history` | 统一 chrome + 回滚区 |
| `/admin/content/tags/term/{term}` | 统一 chrome |
| `/tags` | HTTP 200，未改业务 |

## 7. 深色模式检查结果

- CSS 表面色/文字/边框均绑定 `--gin-bg-*` / `--gin-color-*`。
- 徽标在 `html.gin--dark-mode` 下调高对比色。
- **未使用真实浏览器切换 Gin 深色模式做像素级验收**（本环境无可用可视化浏览器验收工具）；静态规则已按 Gin 机制编写。

## 8. 响应式检查结果

- CSS 断点覆盖：≤1280 / ≤1024 / ≤768 / ≤430。
- 统计与快捷入口自动降列；筛选条与表格横向滚动；操作按钮窄屏换行。
- **未用真实浏览器在 1920/1440/1280/768/390 视口截图验收**；不得视为视觉 QA 完成。

## 9. 路由测试结果

`drush cr` 成功；Drupal bootstrap Successful。

全部标签后台路径与 `/tags` 经 `http_kernel` 子请求：**HTTP 200**。AI 页未检出 API key 形态字符串。

Active trail：

- `xedc_tags.admin_list` → `xedc_tags.admin`
- `xedc_tags.admin_merge` → `xedc_tags.admin`

侧栏子菜单数：`child_menu_count=0`（仅一项挂在内容管理下）。

## 10. 原有业务测试结果

| 脚本 | 结果 |
| --- | --- |
| `tag-center-self-test.php` | SELF TEST PASS |
| `tag-phase2-security-audit-test.php` | PHASE2 AUDIT PASS |
| `tag-center-extended-test.php` | EXTENDED TEST PASS |
| `tag-text-test.php` | TAG TEXT PASS |

仪表盘抽样真实数据：`summary_total=34`（与库内 tags 术语一致）。

## 11. 未完成事项

1. 真实浏览器下的深色模式与多视口视觉验收（需人工或具备浏览器工具的会话）。
2. Gin 原生 page title 与自定义 `h1` 并存时，依赖 CSS `:has()` 隐藏；极旧浏览器可能看到双标题。
3. 未改 `xedc_admin`（仓库实际为 `xedc_admin_ui` / `xedc_admin_nav`）；导航已按现有 Menu API 完成，无需改 admin_ui。
4. 未自动 commit / push。

## 结论

智能标签管理已作为「内容管理」下级正式入口纳入 Gin 侧栏，子页激活轨迹正确，后台 UI 与 Gin + XEDC Admin 视觉语言对齐；标签业务、安全审计、合并回滚、AI 与队列能力保持不变并通过原有测试。
