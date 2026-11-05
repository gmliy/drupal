# XEDC SEO 分析中心二次专项修复

日期：2026-11-05。只处理列表渲染、提交提示、内容预览、评分规则占位、详情发布/状态，以及全站安全更新提示的核查。没有改评分算法、ALT 检查、队列 Worker、采纳保护、`config/sync`、生产内容，也没有升级模块或执行 Git commit。

---

## 1. 六项问题的根因

| # | 问题 | 根因 |
| --- | --- | --- |
| 1 | 列表样式看起来没变 | 生产环境 Twig `auto_reload` 关闭。`xedc-ai-seo.html.twig` 源文件在 17:04 已改成分组表，但 `sites/default/files/php/twig/` 里仍是 11:32 编译的旧模板，表头仍是「标题 / 类型 / 内容 ID / 修订 / 评分…」。浏览器一直渲染旧 HTML。另外，当前管理主题是 `xedc_admin`，`admin-ui.css` 用 `.xedc-admin-active .xedc-admin-content td`（高于模块表格选择器）把所有单元格设成 `white-space: nowrap`。即便新表格编译出来，也会被压成不换行的旧表。Gin 5.0.16 已安装，但管理主题不是 Gin，这次页面没有加载 Gin 的 CSS。 |
| 2 | 提交后出现等待说明 | `SeoSuggestForm` 在有未完成任务时直接输出「等待处理」和「任务已提交，正在后台执行…」。`ai.js` 在缺少这两段时还会再创建它们。 |
| 3 | 内容 ID 下面没有标题 | 上一轮为了去掉工作流机器名，把预览一起从页面上拿掉了。识别接口还在，页面不再调用。 |
| 4 | 详情仍有评分规则 | 源模板已经删掉该区块，但 11:16 的旧 Twig 缓存仍输出「评分规则」和「分析完成后显示扣分依据。」控制器已不再传入 `rules`，所以这块只剩空说明。 |
| 5 | 「发布」「状态」只有名称 | 旧模板读 `review.created` 和 `review.status`。新控制器只提供 `publish_time` 和 `publish_label`。两个名字对不上，标签后面就是空的。 |
| 6 | 全站安全更新警告 | Update Manager 启用且检查成功。不是缓存过期，也不是检查失败。Scheduler 2.3.0 有一条真实安全更新。 |

---

## 2. 修改文件

- `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/js/ai.js`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`（库版本 1.8 → 2.0）
- `web/modules/custom/xedc_ai/src/Controller/SeoController.php`
- `web/modules/custom/xedc_ai/src/Form/SeoSuggestForm.php`
- `web/modules/custom/xedc_ai/src/Seo/ContentLookup.php`

执行了 `drush cr`，让新模板替换掉旧的 Twig 编译结果。没有改数据库结构，没有改内容。

---

## 3. 列表样式之前为什么没有生效

上一轮改的是源文件，页面用的是缓存里的旧模板。旧 DOM 没有 `.xedc-ai-seo-list`，所以新的列宽 CSS 没有作用对象。缓存重建之后，如果继续用 `<table>`，管理主题的 `td { white-space: nowrap }` 仍会盖过模块样式。

这次列表不再使用表格。每一行是 `.xedc-ai-board-row` 网格，六组信息各自带字段名。选择器写成 `html.xedc-admin-active .xedc-ai .xedc-ai-board-row`，不依赖 `td`。

浏览器实际加载的样式在聚合文件里：

`/sites/default/files/css/css_uzhZk3JzCU-io5Z6qsIo9hKPZSZaKguXHGFq4gKIT0g.css`

该文件包含 `.xedc-ai-board-row`。1280 宽下计算样式为 `display: grid`，六列宽度约为 278 / 172 / 205 / 156 / 147 / 115 像素。

---

## 4. 新列表布局

桌面端一行六组：

- 内容信息：标题、内容类型、内容 ID
- SEO 结果：SEO 评分、问题数、建议数
- AI 用量：模型、Token、费用
- 分析时间
- 处理状态
- 操作：查看详情

内容 ID 是节点 ID。修订 ID 不出现在列表里。筛选、分页和「查看详情」仍走原来的查询和路由。

截图：

- `docs/tasks/screenshots/xedc-ai-seo-second/list-1280.png`
- `docs/tasks/screenshots/xedc-ai-seo-second/list-390.png`
- `docs/tasks/screenshots/xedc-ai-seo-second/list-dark-1280.png`

390 宽时表头隐藏，每条记录变成纵向卡片。实测第一张卡片宽 324px，页面 `scrollWidth` 不大于视口。每项都有标签，例如「内容类型：美图」「SEO 评分：100」。

---

## 5. 提交提示

已从页面上删除：

- 等待处理
- 任务已提交，正在后台执行
- 请稍候，页面会自动更新状态

任务容器不再带边框和内边距。进行中只把按钮文字改成「分析中」，不另加说明卡片。完成后才显示评分和「查看分析结果」链接；失败时显示错误。打开列表时页面正文里没有上述等待句。

没有在生产环境点击「开始 SEO 分析」，避免真实模型调用。

---

## 6. 后台异步任务

保留的路径没有改：

- `SeoSuggestForm::submitForm()` 仍调用 `ReviewEnqueuer::request()`
- 仍设置 `xedc_ai.queue_kick`
- AJAX 提交和 `wrapper` 仍是 `#xedc-ai-seo-job`
- 状态路由 `/admin/xedc/ai/seo/status/{review}`、Queue Worker、重复任务保护未改

状态接口在等待阶段的 JSON 里仍有内部标签，页面不再把它画出来。

---

## 7. 内容 ID 预览

输入有效 ID 后，预览出现在输入框下面，不需要再点查询。数据来自 `ContentLookup::preview()`，经 `/admin/xedc/ai/seo/lookup` 返回。

内容 13 的实测结果：

- 标题：海边风景手机壁纸高清下载
- 内容类型：美图
- 内容状态：已撤下

改成 49 后立刻清掉上一条，随后显示「关于我们 / 基本页面 / 已发布」。不存在的 ID 显示「未找到对应内容」。请求失败时脚本只显示「暂时无法读取内容信息。」，不保留旧标题。

无权查看时接口不返回标题和状态。匿名用户查询内容 13 时响应里没有标题。预览不显示修订 ID，也不显示 `xedc_editorial`。

截图：`docs/tasks/screenshots/xedc-ai-seo-second/preview-1280.png`

---

## 8. 评分规则区域

详情源模板里已经没有这块。缓存重建后，分析 207 的页面正文不再包含「评分规则」或「扣分依据」。SEO 参考评分仍在。评分计算、`score_rules` 的存储和历史 JSON 都没有改。

Docker 里原有的评分检查仍然通过，见第 12 节。

---

## 9. 发布和状态的数据来源

| 字段 | 来源 | 没有数据时 |
| --- | --- | --- |
| 发布 | 节点字段 `field_published_at`。该字段按 UTC 存储，页面按站点时区 Asia/Shanghai 显示为 `Y年n月j日 H:i`。不使用修订创建时间，也不看当前是否已发布。 | 暂无 |
| 状态 | `NodeInterface::isPublished()` | 已发布或未发布。节点读不到时为「未能读取」 |
| 工作流 | Workflow 配置的 `label()`。机器名与标签相同时不拿机器名冒充中文。 | 未加入工作流：未启用。读取失败：未能读取 |
| 工作流状态 | 对应 State 配置的 `label()`，再经 `Labels::moderation()` | 未启用时为「无」。读不到状态时为「未能读取」 |

`field_published_at` 只装在文章上。美图、视频、页面没有这个字段，所以显示「暂无」，即使节点已发布。

实测：

| 内容 | 发布 | 状态 | 工作流 | 工作流状态 |
| --- | --- | --- | --- | --- |
| 文章 63 | 2026年10月24日 22:32（存储值 `2026-10-24T14:32:00` UTC） | 已发布 | DcLili 会员投稿审核 | 已发布 |
| 美图 804 | 暂无 | 已发布 | DcLili 会员投稿审核 | 已发布 |
| 美图 694、13 | 暂无 | 未发布 | DcLili 会员投稿审核 | 已撤下 |
| 美图 1004 | 暂无 | 未发布 | DcLili 会员投稿审核 | 草稿 |
| 美图 1010 | 暂无 | 未发布 | DcLili 会员投稿审核 | 待审核 |
| 页面 49 | 暂无 | 已发布 | 未启用 | 无 |

分析 207 的详情页现在显示：发布「暂无」、状态「未发布」、工作流「DcLili 会员投稿审核」、工作流状态「已撤下」。截图：`detail-1280.png`、`detail-390.png`、`detail-dark-1280.png`。

---

## 10. 安全更新警告的真实原因

Update Manager 已启用。最近一次成功检查是 2026-11-06 00:45（+08）。更新数据在 `key_value_expire`，不是空的，也不是检查失败。

项目状态统计：19 个当前版本，3 个普通更新，1 个安全更新。

安全更新只有 Scheduler：

| 项目 | 当前版本 | 建议版本 | 公告 |
| --- | --- | --- | --- |
| Scheduler (`drupal/scheduler`) | 2.3.0 | 2.3.1 | [SA-CONTRIB-2026-306](https://www.drupal.org/sa-contrib-2026-306)，CVE-2026-122075 |

公告等级为中等危急（13/25），类型是访问绕过。受影响范围包含 `>=2.3.0 <2.3.1`。模块在检查用户能否修改定时发布或撤下时间时不充分。攻击者需要已经具备编辑该内容的权限，并且内容能通过 JSON:API 或 REST 修改。本站 `jsonapi` 已启用，所以这条缓解条件里的接口部分是存在的。

`composer.json` 的约束是 `drupal/scheduler: ^2.3`，允许 2.3.1。`composer.lock` 仍锁定 2.3.0。本次没有执行 `composer update`，没有改 lock 文件。

普通更新，不是安全更新：

| 项目 | 当前 | 建议 |
| --- | --- | --- |
| Key | 8.x-1.22 | 8.x-1.23 |
| Metatag | 2.2.4 | 2.2.5 |
| Token | 8.x-1.17 | 8.x-1.18 |

---

## 11. 是否存在真实安全更新

存在。全站那句「您的一个或多个模块有可用的安全更新」对应 Scheduler 2.3.0 → 2.3.1，提示是对的。

没有隐藏这段提示，没有关闭 Update Manager，也没有在生产环境升级。升级需要单独执行 `composer update drupal/scheduler --with-dependencies`，并在实验环境确认 JSON:API 下的定时发布权限后再部署。

---

## 12. Docker 测试

- `docker compose -f web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.yml run --rm seo-test`
- 容器 `php:8.3-cli`，只读挂载模块，不连接生产库。
- 结果：`OK 0 failed`。评分、ALT 零 Token、建议选择和用量标签仍通过。

已有的实验库容器 `xedc-drupal-lab-db`（`127.0.0.1:13307`，库名 `xedcdrupal`）里 `node_field_data` 为 0 行，没有 `xedc_ai_*` 表，不能拿来打开 SEO 页面。没有把生产库导入这个容器。

页面渲染核对是在缓存重建后的本机站点上做的，使用无头 Chromium。没有点击生产环境的「开始 SEO 分析」。

---

## 13. 桌面端、移动端和深色模式

| 检查 | 结果 |
| --- | --- |
| 1280 列表网格、字段标签、无修订列 | 通过 |
| 390 列表卡片、无横向溢出 | 通过 |
| 深色列表，行背景与文字可读 | 通过 |
| 筛选 `bundle=photo&score=high` 后列表区域仍在 | 通过 |
| 详情无评分规则，发布和状态有值 | 通过 |
| 详情 390 无横向溢出 | 通过 |
| 内容 13 预览，以及改成 49、99999999 | 通过 |
| 打开页面没有等待说明 | 通过 |
| 安全更新提示仍在 | 通过，与 Scheduler 公告一致 |

---

## 14. 尚未解决的问题

1. Scheduler 2.3.1 安全更新还没有安装。提示会继续显示，直到升级后重新检查更新状态。
2. Key、Metatag、Token 有普通版本更新，不影响这条安全警告。
3. 没有在生产环境实际提交一次 SEO 分析，因此没有在浏览器里看完一次真实模型往返。入队、Worker 和轮询代码都还在。
4. 实验库没有内容数据，浏览器验收用的是缓存重建后的本机页面，不是一份导入后的数据库副本。
5. 美图、视频和页面没有 `field_published_at`，这些类型的「发布」会显示「暂无」。这是字段不存在，不是把创建时间填进去。
