# 内容列表性能报告

页面：`https://gitxx.xyz/admin/content`

测量日期：2026-11-02。浏览器是本机 Playwright Chromium，不是 Safari。计时没有复用上一轮后台报告里的数字；上一轮给出的热缓存 TTFB 只作为对照基线写在下面。

## 原始性能基线

上一轮热缓存、每页 50 条时记录的对照值：

| 指标 | 数值 |
| --- | --- |
| 内容列表优化前 TTFB | 459ms |
| 内容列表优化后 TTFB | 538ms |
| PHP 渲染相关 SQL | 11–47ms |
| 操作链接字段热渲染 | 约 80ms |

这一轮重新拆开 `/admin/content` 之后，对照值成立：数据库不是主因。同一进程里第二次渲染时 SQL 可以降到十几毫秒，页面仍然要花数百毫秒。

## View 配置

| 项 | 值 |
| --- | --- |
| 路由 | `system.admin_content` |
| 控制器 | `Drupal\views\Routing\ViewPageController` |
| View ID | `content` |
| Display ID | `page_1` |
| 实际路径 | `/admin/content` |
| 配置里保存的 path | `admin/content/node`（没有独立路由，实际由上面的路由接管） |
| 样式 | table，行插件 fields |
| 分页 | full，每页 50 |
| Views 结果缓存 | tag |
| 字段 | `node_bulk_form`、`title`、`type`、`name`、`status`、`changed`、`langcode`、`operations` |
| 暴露的过滤 | 标题、内容类型、发布状态、语言 |
| 未暴露的过滤 | `status_extra`（node_status） |
| 配置里的排序 | 无 |
| 实际执行的排序 | `node_field_data.changed DESC` |
| Relationship | `uid`，连接 `users_field_data` |

实际查询（uid 1，节点访问被展开成恒真条件）：

```sql
SELECT node_field_data.langcode, users_field_data.langcode, node_field_data.nid, users_field_data.uid
FROM node_field_data
INNER JOIN users_field_data ON node_field_data.uid = users_field_data.uid
WHERE (node_field_data.status = 1 OR (node_field_data.uid = 1 AND ...) OR 1 = 1)
ORDER BY node_field_data.changed DESC
LIMIT 50 OFFSET 0
```

站点有 277 条 `node_field_data`、504 条路径别名、4 种语言（zh-hans、en、ru、ja）。`node_access` 只有 1 行，realm 为 `all`。没有按节点的授权表。

## PHP 执行分析

同一请求里按阶段计时（uid 1，50 行）。第一次进程偏冷，第二次及以后是同一进程里的热路径。

| 阶段 | 耗时 | SQL |
| --- | --- | --- |
| 初始化 display | 62ms | 2 条，1.7ms |
| 执行查询 | 43ms | 3 条，2.7ms |
| 从结果行取 50 个实体 | 0.0ms | 0 |
| `access('update')` ×50 | 1.1ms | 0 |
| `access('delete')` ×50 | 1.0ms | 0 |
| `access('view')` ×50 | 1.0ms | 0 |
| 翻译权限 ×50 | 2.7ms | 0 |
| 四种 URL 各生成一次 | 每种约 5–10ms | 每种约 17 条别名查询 |
| `getOperations()` ×50（权限和 URL 已在上面算过） | 18ms | 0 |
| 把 50 个操作菜单渲染成 HTML | 136–170ms | 约 200 条别名查询，SQL 约 38ms |

操作菜单的四个链接是：编辑、翻译、删除、查看。自定义模块没有 `hook_entity_operation`。翻译链接来自核心 Content Translation。

新进程里只渲染操作字段：136.1ms，200 条 `path_alias` 查询，SQL 38.4ms。查询本身不是慢查询，每条大约 0.2ms，200 次往返加上 Drupal 数据库层才堆出这 136ms。权限判断不是这部分的耗时。

整页内核子请求（Drupal 已经启动，只计算 `/admin/content` 这一次处理）：

| | 中位数 | 查询数 | SQL | 别名查询 |
| --- | --- | --- | --- | --- |
| 改代码前，连续 3 次 | 650.5ms | 235 | 56.7ms | 207 |
| 改代码后，缓存已热，连续 3 次 | 597.5ms | 85 | 30.2ms | 57 |

改代码后的第一次请求发生在 `drush cr` 之后，1949.7ms、1154 条查询。那是缓存重建后的冷请求，没有放进上表。

动态页面缓存的响应头仍然是 `UNCACHEABLE (poor cacheability)`。批量操作表单带有 `form_token`，整页不能进页面缓存，也不能进动态页面缓存。这次没有改这个行为。

## SQL 分析

内容列表的主查询有索引可用，执行大约 3ms。慢的是渲染阶段的路径别名：每个操作链接各自查一次 `path_alias`。50 行 × 4 个链接 = 200 次。别名管理器本来想用 Fiber 把这些查询合并，但内容列表在同一个渲染 Fiber 里顺序生成链接，每次只合并 1 条路径，合并没有发生。

`node_access` 不是 N+1。uid 1 的访问条件被优化成恒真。

没有新增索引。上一轮用占位符 `0` 做的 EXPLAIN 不能用来判断索引。

## 操作链接耗时分析

逐项核对的结果：

| 问题 | 实测 |
| --- | --- |
| 每行是否重复加载实体 | 否。Views 结果里已经有实体，50 次 `getEntity()` 为 0ms |
| 是否重复执行 Entity Access | 每行 3 次（update、delete、view），外加 1 次翻译权限。50 行合计约 6ms |
| 是否重复构建 URL | 是。每行 4 个 URL，并且每个 URL 单独查路径别名 |
| 是否重复读取用户权限 | 权限结果有静态缓存，热路径上可以忽略 |
| 是否重复读取路由 | 路由匹配不是这 136ms 的来源 |
| 是否有不必要的 Render Array | 每个链接都走完整的链接渲染和 dropbutton 主题。这是核心操作字段的正常路径 |
| 是否有不必要的数据库查询 | 200 次路径别名查询是明确的重复查询 |
| 能否使用 Render Cache | 能。链接里没有 CSRF token。删除确认页自己的 token 在打开删除页时生成 |

操作字段渲染出的 HTML 抽查不含 `form_token`。批量表单的 token 仍在页面其它位置，每次请求新生成。

## 真实瓶颈

1. 50 个操作菜单的渲染，其中大约 200 次路径别名查询。这是这一轮改动针对的部分。
2. 其余页面工作：display 初始化、批量操作表单、标题链接、后台导航。改完之后热请求仍有约 57 次别名查询和大约 600ms 的内核耗时。
3. 整页不能缓存，因为批量表单必须带当前用户的 CSRF token。

自定义模块里和 Views 相关的钩子没有参与这个页面：

- `xedc_member_views_query_alter` 只处理媒体库。
- `xedc_home_views_query_alter` 只处理首页和图片库。
- `xedc_models_views_query_alter` 只处理图片库。
- `xedc_core` 的 `preprocess_node` 不用于这个字段式列表。
- 没有自定义 `hook_entity_operation`。

这些文件没有改。

## 修改文件

- `web/modules/custom/xedc_core/src/Plugin/views/field/ContentEntityOperations.php`（新文件）
- `web/modules/custom/xedc_core/xedc_core.module`（文件末尾增加 `hook_views_plugins_field_alter`）

`xedc_core.module` 里原有的未提交修改都保留了。`web/themes/custom/xedc_admin/css/admin-ui.css` 和 `xedc_admin.theme` 没有动。没有改核心、第三方模块和 `config/sync`，没有执行 `drush cim`，没有删内容，没有改每页条数。

## 修改原因

只替换 `content` 这个 View 的操作字段类。其它实体的操作字段仍走核心类。

渲染前先按下面的缓存项读取已经生成的菜单 HTML：

- 缓存键：实体类型、实体 ID、实体语言、当前页的 destination 哈希
- 缓存上下文：`user`、`languages:language_url`，以及渲染器必需的 `languages:language_interface`、`theme`、`user.permissions`
- 缓存标签：该节点的标签、当前用户的标签
- max-age：沿用访问检查的结果，uid 1 上是永久缓存（直到标签失效）

因此：

- 不同用户不共用菜单。
- 权限变化或用户保存后，旧菜单失效。
- 节点保存后，该行菜单失效。
- 第 1 页和第 2 页、不同筛选条件的“返回地址”不混用。
- 不缓存 CSRF token。

缓存未命中时仍调用核心 `EntityOperations::render()`，链接种类和目标与原来一致。

## 优化前后性能

### 内核子请求（同一方法，改前改后各 3 次）

| | 改前中位数 | 改后热缓存中位数 |
| --- | --- | --- |
| 墙钟 | 650.5ms | 597.5ms |
| SQL | 56.7ms | 30.2ms |
| 查询数 | 235 | 85 |
| 路径别名查询 | 207 | 57 |

三次改前：672.0、645.5、650.5ms。三次改后：580.8、597.5、619.5ms。

### 浏览器 TTFB（1440×900，各先打开一次再测 3 次，做了两轮）

改前：535、483、419，以及 628、439、414。两轮中位数 483ms 和 439ms。六次中位数 461ms。

改后：443、394、324，以及 401、386、356。两轮中位数 394ms 和 386ms。六次中位数 390ms。

六次中位数相差 71ms。单次之间的波动超过 100ms，所以不能把这个差写成稳定的百分比。方向和内核计时一致：热缓存请求更快，查询更少。

同一轮浏览器里，缓存已经热的内容列表：

| 视口 | 模式 | 次数 | TTFB 中位数 | FCP 中位数 | LCP 中位数 | CLS 中位数 |
| --- | --- | --- | --- | --- | --- | --- |
| 1440×900 | 热 | 3 | 414ms | 660ms | 660ms | 0.000 |
| 1440×900 | 冷（禁用浏览器缓存） | 3 | 369ms | 620ms | 620ms | 0.000 |
| 1280×800 | 热 | 3 | 439ms | 644ms | 644ms | 0.004 |
| 1280×800 | 冷 | 3 | 413ms | 652ms | 652ms | 0.004 |
| 390×664 | 热 | 3 | 417ms | 608ms | 608ms | 0.000 |
| 390×664 | 冷 | 3 | 373ms | 564ms | 564ms | 0.000 |

管理页 HTML 带有不可公开缓存的响应头，禁用浏览器缓存没有稳定地拉高 TTFB。LCP 和 FCP 几乎相同，因为最大的内容就是首屏表格。

重复打开同一 URL 的耗时：

| 动作 | 3 次 TTFB | 中位数 | 结果 |
| --- | --- | --- | --- |
| 搜索“储存测试” | 164、166、167 | 166ms | 表单提交后 1 行，标题匹配 |
| 筛选内容类型“美图” | 411、358、578 | 411ms | 50 行 |
| 打开第 2 页 | 361、404、357 | 361ms | 50 行，地址 `/admin/content?page=1` |

搜索只有 1 行，所以比 50 行的列表快，这不是把每页条数改小了。

## 浏览器功能回归

1440×900 上实际操作：

- 搜索框提交后能找到“对象的储存测试文件一”。
- 内容类型筛选生效。
- 分页进入第 2 页，仍是 50 行。
- 第一行操作菜单展开后有：编辑、翻译、删除、查看。链接带 `destination=/admin/content`。
- 行复选框可以勾上。批量动作包含“发布内容”“取消内容发布”“删除内容”。没有提交这个表单，没有改任何内容。
- 50 个操作菜单都在。页面状态 200。
- 控制台没有新的 error，CSS 和 JS 没有 4xx。

1280×800 和 390×664 都能打开列表、看到筛选和批量区域，并能勾选行。截图在 `/root/xedc-content-list-performance/shots/`。

## 回滚方案

1. 删除 `web/modules/custom/xedc_core/src/Plugin/views/field/ContentEntityOperations.php`。
2. 删除 `xedc_core.module` 末尾的 `xedc_core_views_plugins_field_alter()`。
3. 执行 `vendor/bin/drush cr --uri=https://gitxx.xyz`。

不要用 `git checkout` 或 `git reset` 回滚 `xedc_core.module`，这个文件里还有这次任务之前的未提交修改。

## 尚未解决的问题

- 热缓存下浏览器 TTFB 中位数仍约 390ms，内核子请求约 600ms。操作菜单只占其中一部分。
- 标题链接和后台菜单仍有大约 57 次路径别名查询。
- 新的筛选、搜索或分页地址第一次打开时要重新生成菜单；从第二次起才命中。
- 批量操作表单让整页无法进入动态页面缓存。把整页缓存起来会把 CSRF token 发给别的用户，所以没有做。
- 单次 TTFB 波动经常超过 100ms。少于大约 50ms 的差别无法从三次浏览器采样里单独证明。
