# XEDC 标签统计 — 左侧菜单修正与后台全量中文化报告

日期：2026-11-04  
模块：`web/modules/custom/xedc_tags`  
主题导航：`web/themes/custom/xedc_admin`

## 1. 菜单未显示的真实原因

此前仅把 Drupal Menu API 链接挂在 `system.admin_content`（`xedc_tags.links.menu.yml`）。

**用户实际看到的左侧栏不是 Gin 默认 admin 菜单树，而是自定义主题导航：**

`web/themes/custom/xedc_admin/includes/navigation.theme`  
函数：`_xedc_admin_navigation_definitions()`

该定义里「内容管理」子项原先只有：

- 内容列表  
- 创建内容  
- 图片导入  
- 快速发布  

没有「标签统计」。因此 YAML 已注册、路由可访问，但侧栏仍不显示。

`xedc_admin_ui` / `xedc_admin_nav` 不负责该侧栏；`TagMenuActiveTrail` 只影响 Drupal Menu API 激活轨迹，不能把项注入这套自定义导航。

## 2. 修复后的菜单层级

```
内容管理
├── 内容列表
├── 创建内容
├── 图片导入
├── 快速发布
└── 标签统计   ← 新增
```

实现：在 `_xedc_admin_navigation_definitions()` 的 `content.children` 末尾增加：

- title: `标签统计`
- route: `xedc_tags.admin_overview`
- match: `prefix`
- prefix: `/admin/content/tags`

同时保留 Menu API 备份项，标题改为「标签统计」。

## 3. 最终显示名称

| 位置 | 显示名 |
| --- | --- |
| 左侧自定义导航 | 标签统计 |
| Drupal Menu API | 标签统计 |
| 仪表盘标题 / 页眉 eyebrow | 标签统计 |
| 路由 `_title` | 标签统计 |

不再显示「智能标签管理」。

## 4. 菜单权限验证

- 路由权限仍为 `view xedc tag center`；`_xedc_admin_link()` 用 `Url::access()` 过滤。
- 管理员（uid1）：侧栏可见「标签统计」。
- `content_editor`：侧栏可见（角色含查看权限）。
- 匿名：侧栏不出现；直接访问 `/admin/content/tags` 拒绝。

未放宽任何权限。

## 5. 子页面激活状态验证

`prefix=/admin/content/tags` 覆盖全部子路径。实测：

| 路径 | 标签统计 active | 内容列表 active |
| --- | --- | --- |
| `/admin/content/tags` | Y | N |
| `/admin/content/tags/list` | Y | N |
| `/admin/content/tags/history` | Y | N |
| `/admin/content` | N | （内容列表自身逻辑） |

Local tasks 与仪表盘快捷入口保留。

## 6. 中文化覆盖的页面

展示层统一经 `xedc_tags.labels`（`TagLabels`）：

- 仪表盘（扫描状态、最近活动操作类型）
- 操作历史
- 分析任务（状态徽标）
- 合并回滚表（合并状态）
- AI 语义分析（建议类型、推荐操作、状态）
- 标签别名（来源文案）
- 健康检查（问题类型、级别）
- 其余页面原本已是中文表单文案，保持不变

## 7. 新增的状态映射（节选）

| 机器值 | 显示 |
| --- | --- |
| pending | 等待执行 |
| queued | 排队中 |
| running | 正在执行 |
| processing | 处理中 |
| paused | 已暂停 |
| completed | 已完成 |
| partial | 部分完成 |
| failed | 执行失败 |
| cancelled | 已取消 |
| expired | 已超时 |
| skipped | 已跳过 |
| rolled_back | 已回滚 |
| accepted / rejected / ignored | 已采纳 / 已拒绝 / 已忽略 |
| active / inactive | 启用 / 停用 |
| （未知） | 未知状态 |

## 8. 新增的操作类型映射（节选）

| 机器值 | 显示 |
| --- | --- |
| task_enqueue | 创建分析任务 |
| task_start | 开始执行任务 |
| task_complete | 完成分析任务 |
| task_fail | 分析任务失败 |
| task_cancel | 取消分析任务 |
| alias_create | 创建标签别名 |
| alias_update | 修改标签别名 |
| alias_delete | 删除标签别名 |
| merge / merge_execute | 执行标签合并 |
| merge_rollback | 回滚标签合并 |
| merge_redirect | 创建 301 跳转 |
| merge_redirect_cleanup | 清理合并创建的 301 跳转 |
| recommend_apply | 应用标签推荐 |
| ai_analyze | AI 标签分析 |
| （未知） | 其他操作 |

健康 code / severity、AI suggestion_type、recommended_action 亦有映射。

## 9. 是否还有英文机器值暴露

对 `/admin/content/tags`、`history`、`tasks`、`aliases`、`merge`、`/tags` 渲染结果扫描：

- 未再出现纯文本单元格形式的 `completed` / `task_enqueue` / `alias_create` / `alias_delete` / `merge_redirect_cleanup` / `pending` / `running` / `failed` / `cancelled`
- 历史页已出现对应中文：创建分析任务、创建/删除标签别名、清理合并创建的 301 跳转、执行/回滚标签合并、已完成

允许保留：路由名、类名、表名、技术缩写（AI、SEO、301、URL）以及测试脚本内部断言用的机器值。

未知机器值显示「其他操作」或「未知状态」，不回显原文。

## 10. 修改文件清单

**导航（必要）**

- `web/themes/custom/xedc_admin/includes/navigation.theme`

**xedc_tags**

- `src/TagLabels.php`（新增）
- `xedc_tags.services.yml`
- `xedc_tags.links.menu.yml`
- `xedc_tags.routing.yml`
- `xedc_tags.permissions.yml`
- `templates/xedc-tags-admin-dashboard.html.twig`
- `src/Controller/AdminController.php`
- `src/Form/AdminFormBase.php`
- `src/Form/TaskForm.php`
- `src/Form/RollbackForm.php`
- `src/Form/AiAnalyzeForm.php`
- `src/Form/AliasForm.php`
- `src/Form/HealthForm.php`
- `src/Menu/TagMenuActiveTrail.php`（注释）
- `tests/manual/tag-nav-chinese-check.php`（验收脚本）

未改 TagMerger / TagGuard / TagCatalog 业务逻辑；未改数据库记录；未 commit / push。

## 11. 四套原有测试结果

| 脚本 | 结果 |
| --- | --- |
| tag-center-self-test.php | SELF TEST PASS |
| tag-text-test.php | TAG TEXT PASS |
| tag-center-extended-test.php | EXTENDED TEST PASS |
| tag-phase2-security-audit-test.php | PHASE2 AUDIT PASS |

另：`drush cr` 成功；自定义导航树与激活状态脚本通过。

## 12. 前台兼容性

`/tags` HTTP 200，业务未改。

## 13. 是否有数据库数据变更

无。审计 `op`、任务 `status` 等机器值保持原样，仅展示层翻译。

## 14. 未解决问题

1. 未用真实浏览器对 Gin/xedc_admin 侧栏做像素级截图验收；菜单树与 access 已在运行时验证。
2. 「内容分类 → 标签管理」（taxonomy 词汇管理）仍存在，与「内容管理 → 标签统计」职责不同，未删除。
3. 权限界面描述中仍可能出现历史「智能标签…」措辞以外的模块 info/install 文案；不影响侧栏与后台页面标题。

## 结论

侧栏「标签统计」已真实出现在「内容管理」下，与内容列表/创建内容/图片导入/快速发布同级；标签后台面向管理员的状态与操作类型已中文化，业务逻辑与数据未改。
