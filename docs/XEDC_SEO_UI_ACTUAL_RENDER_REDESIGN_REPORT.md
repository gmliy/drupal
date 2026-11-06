# SEO 分析页面真实渲染定位与重新设计

日期：2026-11-06  
环境：Drupal 11.4.11，PHP 8.5.12，管理主题 `xedc_admin`（Gin 5.0.16 已安装但不是当前管理主题）。  
站点：`https://gitxx.xyz`。浏览器：本机 Chromium，远程调试，缓存已关闭。

本次没有 Git commit、push、reset、checkout。没有修改 `config/sync`、`composer.lock`。没有执行 `drush updatedb`。没有提交采纳、忽略、恢复、重新发布或「开始 SEO 分析」。

## 1. 之前 UI 修改没有在浏览器里形成新页面的原因

之前改过的 Twig **就是**正在渲染的模板，不是改错文件。

修改前用浏览器打开 `/admin/xedc/ai/seo`，页面根节点 class 是 `xedc-ai xedc-seo`。这个 class 只存在于 `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`。主题注册表里的 hook 是模块自己的 `xedc_ai_seo` / `xedc_ai_seo_detail`，模板路径是 `modules/custom/xedc_ai/templates`。`web/themes` 下没有同名覆盖。聚合 CSS `css_YwFmYb41PCYbphvxmFQtcrqgRIj-E5O4tIfxz4uSTxY.css`（theme=`xedc_admin`）里已经有 `.xedc-seo` 规则。所以不是缓存没清、也不是模板没被调用。

浏览器里看起来仍像原来的后台，是因为页面结构还是 Drupal 管理页：主题标题、本地任务、一块普通筛选、一张无边框数据表。列还是「内容信息 / SEO 评分 / 分析结果 / AI 用量 / 分析状态 / 分析时间 / 操作」。样式写在全模块共用的 `css/ai.css` 里，只改了颜色、字号和换行，没有换掉表格的信息结构。管理主题 `xedc_admin` 对 `.xedc-admin-content th/td` 有 `white-space: nowrap`，旧选择器压不住时表格会挤成一行，更像默认后台表。

项目里没有单独的「SEO 设计页」或配置页。相关路由只有列表、详情、内容 ID 查询和任务状态。

## 2. 列表页实际渲染入口

| 项 | 实际值 |
| --- | --- |
| URL | `/admin/xedc/ai/seo` |
| Route | `xedc_ai.seo` |
| Controller | `Drupal\xedc_ai\Controller\SeoController::page` |
| 数据 | `ReviewRepository::search()`，`PagerSelectExtender`，每页 20 条 |
| Render Array | `#theme` = `xedc_ai_seo`，另有 `#rows`、`#filters`、`#suggest`、`#pager`、`#audit` |
| Twig | `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig` |
| 根容器 | `div.xedc-seo-dashboard` |
| Library | `xedc_ai/seo` 1.2，依赖 `xedc_ai/admin`（`css/ai.css` + `js/ai.js`） |
| 专属 CSS | `web/modules/custom/xedc_ai/css/seo.css` |

筛选是模板里的 GET 表单，不是 Drupal Form API 表格。开始分析仍是 `SeoSuggestForm`，嵌在标题区右侧。分页元素挂在 Render Array 上。

## 3. 详情页实际渲染入口

| 项 | 实际值 |
| --- | --- |
| URL | `/admin/xedc/ai/seo/{review}`，验收用了 `/admin/xedc/ai/seo/207` 对应内容 |
| Route | `xedc_ai.seo_detail` |
| Controller | `SeoController::detail` |
| Render Array | `#theme` = `xedc_ai_seo_detail`，`#review`，`#apply` 为 `SeoApplyForm` |
| Twig | `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig` |
| 根容器 | `div.xedc-seo-detail` |
| Library | 同一个 `xedc_ai/seo` 1.2 |

建议的勾选、单条采纳、批量采纳、全选、忽略、恢复、版本冲突和重新发布仍由 `SeoApplyForm` 输出，模板没有重写这些按钮的提交逻辑。页面上没有「评分规则」区块。

另外两个接口不是页面：`xedc_ai.seo_lookup` 返回内容 ID 预览，`xedc_ai.seo_status` 返回任务状态。

## 4. 本次为了让新布局真正出现而改的文件

- `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`  
  根节点改为 `xedc-seo-dashboard`，表格改为 `xedc-seo-grid`，列拆成要求的 8 列。
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`  
  根节点改为 `xedc-seo-detail`，增加评分条和右侧用量栏。
- `web/modules/custom/xedc_ai/css/seo.css`（新文件）  
  只作用在上述两个根容器上。
- `web/modules/custom/xedc_ai/css/ai.css`  
  删除原来的 `.xedc-seo` 布局规则。其他 AI 页面仍用这份文件。
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`  
  新增 `seo` library，版本 1.2。`admin` library 版本 4.1。
- `web/modules/custom/xedc_ai/src/Controller/SeoController.php`  
  列表和详情的 `#attached` 从 `xedc_ai/admin` 改为 `xedc_ai/seo`。

工作区里还有更早任务留下的未提交改动（采纳表单、工作流重新发布、`xedc_core` 的更新提示钩子等）。这次没有回退它们，也没有再改评分、建议生成或翻译逻辑。

## 5. 新布局

列表：

- 标题下是一句说明。有权限时，右侧是「内容 ID」和「开始 SEO 分析」。
- 筛选和表格放在同一块圆角浅底容器里。筛选条在表格上方。
- 列：内容（标题、类型、内容 ID）、SEO 评分（色块）、问题、建议、AI 用量（模型、Token、费用）、状态、分析时间、查看详情。
- 已保存的站点检查收进折叠区，不再占表格主体。
- 宽度 800px 以下，表头隐藏，每一行变成带字段名的信息块。

详情：

- 顶部：类型、内容 ID、标题、发布状态、工作流状态、返回列表。
- 一条四格指标：综合评分和等级、问题数、建议数、待确认数。
- 左侧是问题和建议。建议仍按字段、当前值、建议值、原因、状态和原有操作按钮展示。
- 右侧窄栏是模型、Token、费用、分析时间。
- 图片 ALT、分析历史和日志仍在页面下方。800px 以下指标和侧栏改为上下排列。

视觉用 `xedc_admin` 的 `--xedc-admin-*` 变量，所以深色模式跟着管理主题走。样式选择器都带 `.xedc-seo-dashboard` 或 `.xedc-seo-detail`，不写进全站 CSS。

## 6. 修改前后截图

目录：`docs/tasks/screenshots/xedc-seo-actual/`

修改前（仍是管理表格，根 class 为 `xedc-ai xedc-seo`）：

- `before-list-desktop.png`
- `before-detail-desktop.png`
- `before-list-mobile.png`
- `before-detail-mobile.png`

修改后：

- `after-list-desktop.png`：筛选条进入表格容器，评分是色块，问题和建议分成两列。
- `after-detail-desktop.png`：四格评分条，AI 用量在右侧栏。
- `after-list-mobile.png`：筛选改为纵向。
- `after-list-mobile-rows.png`：每一行是带「内容 / SEO 评分 / 问题 / 建议 / AI 用量 / 状态 / 分析时间 / 操作」标签的信息块。
- `after-detail-mobile.png`：评分四格改为上下排列。
- `after-detail-dark.png`：同一详情页的深色模式。

## 7. DOM 根容器

缓存关闭后的页面检查：

- 列表：`document.querySelector('.xedc-seo-dashboard')` 存在，class 为 `xedc-seo-dashboard`。
- 表头依次为：内容、SEO 评分、问题、建议、AI 用量、状态、分析时间、操作。
- 主表 16 行。单元格 `white-space` 为 `normal`。容器 `border-radius` 为 `12px`。
- 详情：`.xedc-seo-detail`、`.xedc-seo-metrics`、`.xedc-seo-side` 都存在。
- 指标文案：`100 综合评分 · 良好 5 问题 6 建议 2 待确认`。
- 桌面布局列宽为 `1093px 240px`。
- 正文中没有「评分规则」。
- 旧的 `.xedc-ai-board`、`.xedc-ai-cards` 不存在。

## 8. CSS 实际加载

`drush cr` 之后，浏览器禁用缓存重新打开列表。命中 `.xedc-seo-dashboard` 的样式表是：

`https://gitxx.xyz/sites/default/files/css/css_cQ6SX8RoJYp6IHXwjT-LBVddl1ZxTRGUacJUU7kQsiQ.css?delta=0&language=zh-hans&theme=xedc_admin&include=...`

磁盘上的这份聚合文件第 30 行包含 `.xedc-seo-dashboard,.xedc-seo-detail{max-width:1080px;...}`，以及 `.xedc-seo-sheet`、`.xedc-seo-grid`、`.xedc-seo-metrics`。Library 版本是 1.2。样式表请求没有 404。控制台没有新的 JavaScript 错误。

## 9. 桌面端和移动端

桌面 1440：

- 列表和详情都打开。
- 搜索 `q=视频`：4 行，标题都是「墨迹二班测试视频标题」。
- 筛选 `bundle=photo&ops=failed`：3 行，都是美图、分析失败。
- 主表当前 16 条。查询每页 20 条，所以 Drupal 没有输出分页导航。`?page=1` 仍是这 16 条。分页元素仍在 `SeoController::page()` 的 Render Array 里，数据超过 20 条时会显示。
- 从列表标题链到详情。

移动 390：

- 列表 `scrollWidth` 为 390，表头 `display` 为 `none`。
- 详情 `scrollWidth` 为 390，根容器存在。
- 行内容按字段名纵向排列，不是把桌面表格缩小。

## 10. 深色模式

`localStorage` 的 `xedcAdmin.theme` 设为 `dark` 后重新打开详情。`data-theme` 为 `dark`。侧栏背景 `rgb(32, 32, 36)`，文字 `rgb(228, 228, 231)`。截图是 `after-detail-dark.png`。移动端截图是在切回 light 之后拍的。

## 11. 业务功能回归

浏览器里只做了不写库的操作：

- 全选待处理建议：`已选择 0 条建议` 变为 `已选择 5 条建议`，再次点击恢复。没有提交。
- 详情页仍有采纳、忽略、恢复和「采纳建议并重新发布」表单。没有点击提交。
- 没有点击「开始 SEO 分析」。

Docker 逻辑测试（`web/modules/custom/xedc_ai/tests/manual/docker`，不启动 Drupal、不连生产库）：

`OK 0 failed`。包含已撤下内容的重新发布决策：`denied`、`apply_failed`、`conflict`、`no_transition`。

页面能完整渲染，本次没有新的 PHP 致命错误。评分算法、ALT 零 Token 检查、建议数据结构和 `xedc_auto_translate` 没有改。

## 12. 尚未解决的问题

- 当前 SEO 记录只有 16 条，少于每页 20 条，分页导航不会出现。分页代码还在，这次不能在浏览器里点到第二页。
- `xedc_ai_update_10003` 仍未执行。按约束没有跑生产库 schema 升级。
- 工作流「重新发布」只存在于当前活动配置。`config/sync/workflows.workflow.xedc_editorial.yml` 没有这条迁移。以后做配置导入会把它清掉。本次没有改 `config/sync`。
- 采纳、忽略、恢复和重新发布没有在生产内容上提交。节点 13 仍是已撤下。
