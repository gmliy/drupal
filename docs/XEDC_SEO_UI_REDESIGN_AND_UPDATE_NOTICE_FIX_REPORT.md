# SEO 分析界面重做与安全更新提示位置

## 1. SEO 列表页原来的问题，以及这次改了什么

列表页路由是 `/admin/xedc/ai/seo`，控制器 `SeoController::page()`，主题钩子 `xedc_ai_seo`，模板 `xedc-ai-seo.html.twig`，样式库 `xedc_ai/admin`。

原来的列表是一组带标签的分区卡片，顶部还有“已分析、待处理建议、已采纳、低于 80 分、平均分”统计卡。字段挤在一起，表头不清晰。后台主题对 `.xedc-admin-content td` 使用 `white-space: nowrap`，直接改成普通表格会被压成一行。

这次改成桌面端数据表。列是：内容、SEO 分析、AI 用量、分析信息、操作。内容标题是主信息，类型和内容 ID 在标题下面。评分是数字，问题数和建议数是轻量数字标签。模型、Token 和费用放在用量列。时间统一为 `Y年n月j日 H:i`。状态仍用中文标签。操作只保留“查看详情”。统计卡已从页面拿掉，筛选、搜索、分页、新建分析和站点检查都还在。没有数据时仍是统一空状态。

## 2. SEO 详情页原来的问题，以及这次改了什么

详情页路由是 `/admin/xedc/ai/seo/{review}`，模板 `xedc-ai-seo-detail.html.twig`。

原来把修订号、发布、工作流和评分、Token、费用放在同一组卡片里。问题是一行接一行的文字。建议表和用量表抢同一层视觉。

现在顶部只放内容标题、类型、内容 ID、发布状态和工作流状态，以及返回列表。修订号移到折叠的“分析依据与记录”里。评分规则、扣分依据和评分维度说明没有加回来。评分算法、评分服务和历史数据没有删。

问题按条分开，严重程度是中文：高、中、低。建议表的列是建议字段、当前内容、建议修改、原因、状态。字段名走 `Labels::field()`，`Meta Title`、`Meta Description` 显示为 SEO 标题、页面描述。状态仍是待确认、已采纳、已忽略、采纳失败、版本冲突，以及原本就有的“不适用”。采纳、批量勾选、忽略、恢复、重新发布仍是原来的 `SeoApplyForm`，业务逻辑没有改。模型、Token、费用和时间放在后面的“AI 用量”。未核算费用仍使用原来的费用文案，不会被改成 0 元。Meta 来源里的 `[node:title]` 这类规则原文不再出现在建议区，中文说明保留。

## 3. 新界面的结构和视觉

列表和详情共用 `.xedc-seo`。页面宽度、13px 辅助文字、15px 小标题、细底边、32px 按钮和筛选控件、圆角状态标签是同一套。背景用现有后台变量，没有渐变，没有额外统计卡。

列表是一张全宽表，行悬停有浅底。800px 以下表头隐藏，每条记录按“内容 / SEO 分析 / AI 用量 / 分析信息 / 操作”竖排。详情的总评分是左侧大数字加等级（良好、一般、需改进、未评分），不是彩色大卡片。

## 4. 深色模式和移动端

浏览器里把 `xedcAdmin.theme` 设为 dark 后，`data-theme` 为 dark。详情文字颜色是 `rgb(228, 228, 231)`，不是白底黑字。390px 宽时列表和详情的 `scrollWidth` 都是 390，表头为 `display: none`，没有横向溢出。移动端列表截图是深色背景。

截图：

- `docs/tasks/screenshots/xedc-seo-redesign/list-desktop.png`
- `docs/tasks/screenshots/xedc-seo-redesign/detail-desktop.png`
- `docs/tasks/screenshots/xedc-seo-redesign/list-mobile.png`
- `docs/tasks/screenshots/xedc-seo-redesign/detail-dark.png`
- `docs/tasks/screenshots/xedc-seo-redesign/detail-mobile.png`
- `docs/tasks/screenshots/xedc-seo-redesign/admin.png`
- `docs/tasks/screenshots/xedc-seo-redesign/updates.png`

## 5. 安全更新提示重复出现的原因

不是主题把状态消息渲染了两次，也不是自定义模块在每次请求里再塞一条。`xedc_admin` 的 `page.html.twig` 和 `status-messages.html.twig` 只输出一次。

原因是 Drupal 核心 Update Status。`UpdateHooks::pageTop()` 在每条后台路由上调用更新检查；项目状态为 `NOT_SECURE` 时，向 Messenger 加一条错误。可用更新页和状态报告页自己会提前返回，改由报告正文显示。所以普通后台每一页都会多出这一句：“您的一个或多个模块有可用的安全更新……”

## 6. 改了哪些文件

- `web/modules/custom/xedc_core/xedc_core.module`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `web/modules/custom/xedc_ai/xedc_ai.routing.yml`
- `web/modules/custom/xedc_ai/src/Controller/SeoController.php`
- `web/modules/custom/xedc_ai/src/Labels.php`

提示修复只做了一处：`xedc_core_module_implements_alter()` 去掉 `update` 模块的 `page_top`。Update Status 模块仍然启用。检查、缓存、cron 邮件、`/admin/reports/updates` 和 `/admin/reports/status` 的报告数据都还在。没有改核心文件，没有用 CSS 隐藏，没有清空消息队列。

缓存重建后，`page_top` 的实现是 `system, toolbar, navigation`，不再包含 `update`。

## 7. 提示现在留在哪里

安全更新提示留在 Drupal 自己的报告里：

- `/admin/reports/updates` 可用更新
- `/admin/reports/status` 状态报告

模块列表、主题页和其他普通后台不再通过 `page_top` 往 Messenger 里加这一条。

## 8. 已经确认不再重复显示的页面

浏览器登录后逐页看正文，这些页面没有“安全更新”或“请马上升级”：

- `/admin`
- `/admin/content`
- `/admin/xedc/ai/seo`
- `/admin/xedc/ai/seo/207`
- `/admin/xedc/engagement/members`
- `/admin/content/tags`
- `/admin/people/xedc-notifications`

`/admin/reports/updates` 和 `/admin/reports/status` 仍然有这条提示，并且页面上能看到 Scheduler。

空内容 ID 不会通过 HTML5 校验。用一个不存在的内容 ID `999999999` 提交后，页面出现“未找到对应内容”一类错误，没有开始分析，也没有重新出现安全更新提示。普通表单错误仍然显示。

## 9. 安全更新是否真实

Update Status 已启用。最近一次成功检查是 `2026-11-06T00:45:03+08:00`。缓存里 19 个项目为当前版本，3 个为普通版本更新，1 个为安全更新。

安全更新：

| 项目 | 当前版本 | 建议版本 | 公告 |
| --- | --- | --- | --- |
| Scheduler | 2.3.0 | 2.3.1 | SA-CONTRIB-2026-306，CVE-2026-122075，中等危急，访问绕过 |

`composer.json` 约束是 `drupal/scheduler: ^2.3`，`composer.lock` 锁在 2.3.0。2.3.1 的发布类型在 Drupal 更新数据里是 Security update。发布页：https://www.drupal.org/project/scheduler/releases/2.3.1

普通版本更新，不是这次安全提示的来源：

- Key 8.x-1.22 → 8.x-1.23
- Metatag 2.2.4 → 2.2.5
- Token 8.x-1.17 → 8.x-1.18

没有升级，没有改 `composer.lock`。

## 10. Docker 测试

`web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.yml` 用只读挂载跑 `php:8.3-cli` 的 `tests/manual/seo-ops-test.php`。结果是 OK，0 failed。覆盖评分、建议解析、勾选 key、ALT 和费用状态。它不启动 Drupal，也不渲染页面。

现有实验室库没有内容和 SEO 记录，不能登录后打开这两页。没有把生产库导入实验室，也没有改生产站点的数据库配置。

## 11. 浏览器验证

在本机已有的 Chromium（远程调试 9222）里登录运行中的站点。聚合样式里能匹配到 `xedc-seo-table`。列表单元格计算样式是 `white-space: normal`。

已确认：

- 列表有表头，16 行，没有统计卡，没有安全更新提示。
- 内容类型筛选为美图后为 12 行，且仍是美图。
- 搜索 `q=17` 得到 8 行，并出现内容 ID 17。
- 当前结果不足 20 条，页面上没有分页条。分页器仍由 `PagerSelectExtender` 按每页 20 条挂上。`?page=1` 没有打出安全提示，表格仍在。
- 详情评分显示 100，等级为良好。没有“评分规则”。问题 5 条。建议表和采纳表单都在。
- “全选待处理建议”把“已选择 0 条建议”变成“已选择 5 条建议”，再点一次会取消。没有提交表单。
- 详情页没有 `Meta Title`，没有 `[node:title]`，有“SEO 标题”和“图片 ALT”。

没有在生产内容上执行单条采纳、批量采纳、忽略、恢复、版本冲突或翻译保护。这些会写入内容或分析记录。采纳表单和勾选脚本还在，Docker 用例覆盖了勾选 key 的解析。

## 12. 还没解决的问题和风险

- Scheduler 2.3.0 的安全更新是真的。提示从普通后台拿掉了，更新本身没有安装。
- 采纳、忽略、恢复和版本冲突没有在浏览器里点提交。
- 实验室库没有数据，浏览器验证发生在正在运行的站点上，不是一个带内容的 Docker 站点。
- 模块列表和主题页也不再收到这条 Messenger 提示。需要看安全更新时，打开可用更新或状态报告。

## 没有做的事

- 没有修改生产内容，没有执行生产数据库结构升级。为了让新模板和 `page_top` 修改生效，在运行中的站点执行了一次缓存重建。
- 没有修改 `config/sync`。
- 没有修改 Composer 依赖，没有修改 `composer.lock`，没有执行 `composer update`。
- 没有执行 Git commit、push、reset、checkout 或清理工作区。
