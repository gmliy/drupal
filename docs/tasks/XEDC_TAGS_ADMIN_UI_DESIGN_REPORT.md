# XEDC 标签统计中心 — 后台 UI 视觉审查与统一改造报告

日期：2026-11-05  
模块：`web/modules/custom/xedc_tags`  
关联：`xedc_ai`（仅检查关联入口文案，未改业务）  
环境：Drupal 11.4.11 / PHP 8.5.12 / Gin 5.0.16 / 管理主题 `xedc_admin`

## 1. 全部后台页面清单（以路由为准）

| # | 路由 | 路径 | 类型 | 标题 |
| --- | --- | --- | --- | --- |
| 1 | `xedc_tags.admin_overview` | `/admin/content/tags` | Controller + Twig | 标签统计 |
| 2 | `xedc_tags.admin_list` | `/admin/content/tags/list` | Form | 标签列表 |
| 3 | `xedc_tags.admin_term` | `/admin/content/tags/term/{taxonomy_term}` | Controller | 标签详情 |
| 4 | `xedc_tags.admin_health` | `/admin/content/tags/health` | Form | 标签健康检查 |
| 5 | `xedc_tags.admin_duplicates` | `/admin/content/tags/duplicates` | Form | 重复标签检测 |
| 6 | `xedc_tags.admin_merge` | `/admin/content/tags/merge` | Form | 标签合并 |
| 7 | `xedc_tags.admin_history` | `/admin/content/tags/history` | Controller + RollbackForm | 操作历史 / 回滚 |
| 8 | `xedc_tags.admin_aliases` | `/admin/content/tags/aliases` | Form | 标签别名 |
| 9 | `xedc_tags.admin_unused` | `/admin/content/tags/unused` | Form | 未使用标签 |
| 10 | `xedc_tags.admin_recommend` | `/admin/content/tags/recommend` | Form | 智能推荐 |
| 11 | `xedc_tags.admin_relations` | `/admin/content/tags/relations` | Controller | 标签关联分析 |
| 12 | `xedc_tags.admin_seo` | `/admin/content/tags/seo` | Controller | 标签 SEO 检查 |
| 13 | `xedc_tags.admin_ai` | `/admin/content/tags/ai` | Form | AI 语义分析 |
| 14 | `xedc_tags.admin_tasks` | `/admin/content/tags/tasks` | Form | 分析任务 |
| 15 | `xedc_tags.admin_delete` | `/admin/content/tags/term/{id}/delete` | ConfirmForm | 删除标签 |

说明：不存在独立的「相似标签分析 / AI 建议详情 / 任务详情 / 别名编辑」路由；对应能力分别合并在重复检测、AI 列表行操作、任务表、别名页内。未新建路由。

前台 `/tags` 不在本次后台改造范围内（样式库仍为 `xedc_tags/xedc_tags`）。

## 2. 各页原有 UI 问题 → 实际修改

| 页面 | 原有问题 | 本次修改 | 状态 |
| --- | --- | --- | --- |
| 仪表盘 | 基础已齐，深色对比不足 | 保留统计/快捷入口/活动；深色变量与标题对比加强 | **已完成** |
| 列表 | Term ID 不突出；创建/更新信息弱 | 名称加 `#tid`；列改为更新时间；说明改 callout | **已完成** |
| 详情 | 事实表偏平；内容类型英文 | `xt-facts`；类型中文；表格卡片包装 | **已完成** |
| 健康 | 摘要为朴素表；筛选项弱 | 统计卡片网格；chip 筛选；表格卡片 | **已完成** |
| 重复 | 空状态弱；列拥挤 | callout/空状态；名称+#tid；风险 callout | **已完成** |
| 合并 | 已有分步，提示偏弱 | 警告 callout；保留预览/确认/301 二次确认 | **已完成** |
| 历史/回滚 | 回滚表显示裸 TID | 来源/目标显示名称；危险区面板 | **已完成** |
| 别名 | 创建区与列表混排 | 窄面板创建 + 筛选条 + 表格卡片；状态徽标 | **已完成** |
| 未使用 | 默认表格；操作拥挤 | 表格卡片；批量条；危险删除链接样式 | **已完成** |
| 推荐 | 表单无面板 | 输入面板 + callout | **已完成** |
| 关联 | 全量 chip 拥挤；Jaccard 机器感 | 高频 24 个 chip；「关联强度」文案；表格卡片 | **已完成** |
| SEO | 表格未卡片化 | callout + chip + 表格卡片 | **已完成** |
| AI | 说明弱；仅采纳/忽略 | 明确「仅建议」callout；接受/拒绝/忽略；建议原文展示 | **已完成** |
| 任务 | 表头英文 ID；布局散 | 中文表头；面板入队；状态徽标 | **已完成** |
| 删除 | 缺统一 chrome | xt-admin 页头 + 危险 callout + 危险按钮 | **已完成** |

## 3. CSS / Library

| 文件 | 变更 |
| --- | --- |
| `css/admin.css` | 统一令牌、panel/callout/facts、表格/筛选/合并/危险按钮、响应式、Gin 深色显式回退 |
| `xedc_tags.libraries.yml` | `admin` library version → `3.1` |

选择器均限定在 `.xt-admin` / `.gin--dark-mode .xt-admin`，未改 Gin 源码，未引入外部 CSS 框架。

## 4. Twig

| 文件 | 变更 |
| --- | --- |
| `templates/xedc-tags-admin-dashboard.html.twig` | 沿用（本轮未改结构） |
| `templates/xedc-tags-metrics.html.twig` | 既有 |
| 前台 `xedc-tags-index/term` | 未改 |

## 5. Form / Controller

| 文件 | 变更要点 |
| --- | --- |
| `AdminFormBase.php` | chrome 转义；callout/panel/tableCard/tagNameMarkup；panel 保持根级 `#parents` |
| `ListActionForm.php` | 名称+#tid、更新时间、callout |
| `HealthForm.php` | 统计网格、chip、表格卡片 |
| `AiAnalyzeForm.php` | 面板、接受/拒绝/忽略、无密钥展示 |
| `TaskForm.php` | 面板、中文表头、表格卡片 |
| `AliasForm.php` | 创建面板、筛选、徽标 |
| `DuplicateActionForm.php` | 空状态、风险 callout、列精简 |
| `UnusedActionForm.php` | 表格卡片、批量条 |
| `RecommendForm.php` | 输入面板 |
| `MergeForm.php` | 警告 callout |
| `RollbackForm.php` | 名称展示、危险区、确认路径 |
| `DeleteTermForm.php` | 统一 chrome、危险提示 |
| `AdminController.php` | 关联/SEO/历史/详情布局与中文 |
| `TagLabels.php` | 自由文本建议原样展示（不误映成「其他建议」） |

未改：`TagMerger` / `TagGuard` / `TagCatalog` 核心逻辑、AI 用量/预算/模型配置、队列状态机持久化值。

## 6. 中文化范围

- 状态：经 `TagLabels::status()`（含 pending/queued/running/completed/failed/cancelled/expired/processing/skipped 等）
- 操作类型：`TagLabels::operation()`
- 任务类型 / 别名类型 / 建议类型 / 健康码 / SEO 建议：展示层中文
- 表格标题、按钮、空状态、callout、筛选 chip：中文
- 内部机器值（DB status/op、route、服务 ID）保持不变

## 7–11. 视觉与响应式检查结果

| 项 | 结果 | 验证方式 |
| --- | --- | --- |
| 浅色模式 | **已通过测试** | Playwright 13 路由截图 + chrome 断言 |
| 深色模式 | **已通过测试** | 强制 `gin--dark-mode` 后表面 `#2a2a2d`、标题白色对比通过；截图已更新 |
| 桌面 1440/1280 | **已通过测试** | 浏览器脚本 + 截图 |
| 平板 768 / 手机 430/375 | **已通过测试** | 列表页无横向溢出；截图已存 |
| 表格横向滚动 | **已完成** | `.xt-table-wrap` |
| AI 无 API Key 泄露 | **已通过测试** | 页面文案扫描 |

截图目录：`docs/tasks/screenshots/xedc-tags-ui/`  
（含 `light-1440-*`、`light-1280-*`、`light-768/430/375-list`、`dark-1440-overview/list` 等）

浏览器脚本：`web/modules/custom/xedc_tags/tests/manual/browser-tags-admin-ui.mjs`  
结果：`SUMMARY pass=60 fail=0`（另补深色对比复测 PASS）

## 12. 自动化回归

| 脚本 | 结果 |
| --- | --- |
| tag-center-self-test.php | SELF TEST PASS |
| tag-center-extended-test.php | EXTENDED TEST PASS |
| tag-text-test.php | TAG TEXT PASS（Drush abnormal 因脚本 `exit`，既有现象） |
| tag-phase2-security-audit-test.php | PHASE2 AUDIT PASS |
| tag-ai-output-optimization-test.php | OPTIMIZATION TEST PASS |

数据安全（本轮测试前后）：

| 项 | 值 |
| --- | --- |
| 标签总数 | 34 → 34 |
| `node__field_tags` | 81 → 81 |
| `xedc_ai_usage` | 14 → 14 |
| `source=xedc_tags` | 2 → 2 |

真实 AI API：**未调用**。新增费用：**无**。

## 13. Git 修改文件清单（本轮 UI 相关）

**主要更新**

- `web/modules/custom/xedc_tags/css/admin.css`
- `web/modules/custom/xedc_tags/xedc_tags.libraries.yml`
- `web/modules/custom/xedc_tags/src/Form/AdminFormBase.php`
- `web/modules/custom/xedc_tags/src/Form/{ListAction,Health,AiAnalyze,Task,Alias,DuplicateAction,UnusedAction,Recommend,Merge,Rollback,DeleteTerm}Form.php`
- `web/modules/custom/xedc_tags/src/Controller/AdminController.php`
- `web/modules/custom/xedc_tags/src/TagLabels.php`
- `web/modules/custom/xedc_tags/tests/manual/browser-tags-admin-ui.mjs`
- `docs/tasks/screenshots/xedc-tags-ui/*`
- `docs/tasks/XEDC_TAGS_ADMIN_UI_DESIGN_REPORT.md`

未 commit / push。

## 14. 未解决问题

1. **独立「任务详情 / AI 建议详情 / 别名编辑」页不存在**：能力在列表行内完成；若产品要独立详情页需另开需求。
2. **深色模式依赖 class**：自动化通过强制 `gin--dark-mode` 验证模块 CSS；管理员需在 Gin 设置中开启深色主题才会日常生效。
3. **关联页仅展示使用量前 24 个 chip**：避免 34 个全量拥挤；完整选择仍可通过列表进详情再看关联。
4. **前台 `/tags` 未纳入本次后台 SaaS 改造**。

## 结论

标签统计中心全部既有后台路由已完成统一 chrome、表格/表单面板、状态徽标、中文展示与深色/响应式加固；功能与权限、生产标签数据、AI 用量统计均未破坏。视觉验收以 Playwright 实机截图为准，非口头推断。
