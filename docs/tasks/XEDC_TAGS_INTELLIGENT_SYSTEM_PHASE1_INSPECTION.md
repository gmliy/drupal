# XEDC 智能标签管理系统 — 阶段一检查报告

检查时间：2026-11-04  
环境：Drupal 11.4.11 / PHP 8.5.12 / MariaDB / 主题 xedc / 后台 Gin（xedc_admin）

## 已有功能

- 词汇表 `tags`（标签）存在，当前 34 个术语，Term ID 全部保留。
- 内容字段：`node.photo|video|article.field_tags` 均指向 `tags`。
- 模块 `xedc_tags` 已启用：前台 `/tags`、标签详情、后台 `/admin/content/tags`。
- 已实现：统计目录（TagCatalog）、重复/相似检测、合并预览/执行/回滚、推荐、关联、SEO 检查、操作审计、未使用标签、删除保护。
- 可复用：`xedc_ai`（模型/密钥/预算/客户端）、`redirect`、`metatag`、`pathauto`。

## 需要新增 / 补齐

1. 标签别名（同义词/历史名，非 path alias）表与管理页。
2. 智能健康检查独立页面（低质量、命名规范、孤立、趋势分档）。
3. AI 语义分析（复用 xedc_ai，严格校验输出；AI 不可用时规则检测仍可用）。
4. 分析任务表 + Queue Worker（全量/增量扫描，防并发）。
5. 仪表盘指标补齐（图片/视频/文章标签数、高低频、待合并/SEO/推荐）。
6. 列表筛选项补齐（健康、SEO、内容类型、使用次数）。
7. 合并后可选 301（Redirect 模块），默认关闭、需确认。
8. 前台总览增加按图片/视频/文章筛选。

## 可复用，不重复建设

- Taxonomy / Metatag / Pathauto / Redirect / xedc_ai 密钥与模型。
- 前台内容卡片：meitu-card、video-card、article teaser。
- 现有合并事务与字段快照回滚。

## 需要修改的文件（概要）

- `web/modules/custom/xedc_tags/*`（服务、表单、路由、权限、schema、模板、CSS）
- 交付报告写入 `docs/tasks/`，不改无关模块与 config/sync 密钥。

## 潜在兼容性与数据风险

- 不得改 Term ID、字段机名、现有别名与 Metatag 规则。
- 合并不自动删来源术语；回滚依赖快照，合并后新增的关联不覆盖。
- AI Key 只走 xedc_ai SecretStore，不进 Git / 普通配置导出。

## 测试方案

- 现有 `tag-center-self-test.php`、`tag-text-test.php`。
- 新增：别名 CRUD、健康检查、AI 校验失败路径、任务队列、前台筛选、权限。
- 仅使用临时测试术语与节点，测完清理。
