# XEDC Comic 后台异常诊断报告

**阶段**：第七阶段 · 诊断先行  
**路径**：`/var/www/drupal` · **分支** `main` @ `a726ee8`（未改动用户其它未提交文件）  
**时间**：2026-11-14

## 审计范围摘要

| 项 | 结论 |
|----|------|
| 后台路由 | 9 条主路由（总览、漫画/章节/图片、导入/任务、统计、设置） |
| Shell | `ComicAdminShell` + `comic-admin-layout.html.twig` |
| 统计 | `ComicAdminMetrics`、`ComicStatsManager` |
| 主题变量 | `hook_theme` 变量型模板 |
| 生产数据（Drush） | 漫画 1、章节 1、图片 2、阅读量 7 |

---

## 异常清单

| 页面 | 异常表现 | 根本原因 | 修复方案 | 状态 |
|------|----------|----------|----------|------|
| 全部漫画中心页 | 标题/导航/仪表盘空白或仅剩外层 Drupal 壳 | `ComicAdminShell::page()` 中主题变量大部分用 `#key`，但 **`content` 曾无 `#` 前缀**，子页面内嵌主题同理时混用；子主题未传入 `content` | 统一 `#content` 及所有 `#theme` 变量；导航 `active` 按路由标记 | **FIXED** |
| `/admin/xedc/comic` 总览 | 统计卡、最近漫画、任务区不显示 | 同上 + 概览模板变量未传 | `#stat_cards` 等 + 增强 `ComicAdminMetrics` | **FIXED** |
| `/admin/xedc/comic/statistics` | HTTP 500 | `SelectQuery::addExpression()` **链式**写在 `condition()` 返回值上，返回 string 后调用 `execute()` | 拆分为独立 `$query` 变量（今日/7天/30天） | **FIXED** |
| 漫画列表封面 | 偶发不显示 | `comicCard()` 依赖 `field_comic_cover` 文件 URL；无封面时为空（非 Bug） | 保留占位 `xedc-comic-thumb--empty` | **PASS** |
| 图片管理无 `?chapter=` | 仅章节选择器 | 设计如此（须选章节） | 文档化；入口从章节列表「图片」链 | **PASS** |
| 阅读记录后台 | 无菜单 | 无独立管理路由；数据在 `xedc_comic_reading` | 本阶段 **不** 入导航（未实现） | **BLOCKED** |
| 漫画批量发布/删除 | 无多选批量 | 未实现 | 记入后续；列表仍为分页 EntityQuery | **BLOCKED** |
| 表单 Gin 分组 | 默认 Entity Form Display | 未改 content type 配置 | 模块 install 已有 display；深度优化后续 | **NOT TESTED** |
| 桌面/暗色目视 | — | 无浏览器 | 标记 NOT TESTED | **NOT TESTED** |

## Drupal 主题变量说明（根因详解）

模块内自定义 `#theme` 钩子声明为 **`variables`** 类型时，Render API 要求传入 **`#variable_name`**，Twig 中对应 `variable_name`。  
此前 Shell 使用 `'content' => $build`（无 `#`），导致：

1. 被当作非法子元素或无法注入模板；
2. 整页 `{{ content }}` 为空，表现为「漫画中心大片空白」。

**正确示例**：

```php
[
  '#theme' => 'xedc_comic_admin_layout',
  '#page_title' => $title,
  '#content' => $content,
]
```

## CLI 修复后抽检（uid 1）

| URL | HTTP | 标记 |
|-----|------|------|
| `/admin/xedc/comic` | 200 | 含「漫画中心」、`xedc-comic-stat` |
| `/admin/xedc/comic/comics` | 200 | 含 `xedc-comic-table` |
| `/admin/xedc/comic/statistics` | 200 | 含统计卡与表 |

---

**结论**：显示类主因是 **Render Array 主题变量键名不一致** + **统计页 SQL 链式错误**；非数据库无数据。修复后再做 UI 增强。