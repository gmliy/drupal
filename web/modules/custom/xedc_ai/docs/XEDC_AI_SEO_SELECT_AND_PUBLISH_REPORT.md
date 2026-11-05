# XEDC AI SEO：全选、分析依据与已撤下内容重新发布

日期：2026-11-05  
环境：Drupal 11.4.11 / PHP 8.5 / 管理主题 xedc_admin（Radix）。验收在 Docker 隔离库中完成，没有调用模型，没有改生产内容或生产库。

## 1. 全选按钮失效的真实原因

页面上的「全选待处理建议」原先是 Drupal Form API 复选框，不是 `type="button"`。脚本只在 `change` 时把这个元素的 `checked` 抄到 `data-xedc-apply-state` 为 `pending` 或 `failed` 的复选框上。上一轮浏览器脚本只确认选择器存在，没有真正点击。

这次在隔离环境里看了真实 DOM：

- 复选框的 class 落在 `<input>` 上，不是包一层的 `div`。待确认和采纳失败的输入框也能被同一个选择器找到。
- 把按钮写成 `#markup` 里的 `<button>` 时，Drupal 的 `Xss::filterAdmin()` 会删掉它。`button` 不在后台允许的标签里。页面上还看得到「已选择 0 条建议」，但全选控件本身不在 DOM 里，点击没有目标。
- 正式修复改成渲染元素 `html_tag`，输出 `<button type="button">`。浏览器里 `Drupal.behaviors.xedcAiApply` 已执行，按钮类型是 `button`。

因此失效点是：控件不是不会提交的按钮，而且用 `#markup` 输出的按钮会被过滤掉。当前实现不再依赖复选框的 `change`。

## 2. 全选修了哪些代码

`SeoApplyForm` 用 `html_tag` 输出全选按钮和「已选择 N 条建议」。按钮带 `data-xedc-select-label` / `data-xedc-clear-label`。

`js/ai.js` 里 `xedcAiApply`：

- 第一次点击勾选「待确认」和「采纳失败」。
- 这两类已经全部勾选时，按钮文字变成「取消全选」，再点只取消这两类。
- 不碰「已采纳」「已忽略」「版本冲突」「不适用」。
- 手动勾选或取消一条时，只更新这一条和数量。
- `preventDefault()`，不提交表单，也不改建议状态。
- 页面打开时没有默认勾选。

资源库 `xedc_ai/admin` 版本改为 1.3，避免浏览器继续用旧脚本。

## 3. 浏览器真实点击

隔离站点 `http://127.0.0.1:8096`，Chromium 远程调试。详情页是实验室文章，状态已撤下。复选框为：标题待确认、简介待确认、标签采纳失败、ALT 已采纳。已忽略、版本冲突、不适用没有复选框。

| 操作 | 结果 |
| --- | --- |
| 打开页面 | 四条都未勾选。数量「已选择 0 条建议」 |
| 点击全选 | 待确认 2 条和采纳失败 1 条被勾选。已采纳的 ALT 仍未勾选。按钮变为「取消全选」。数量「已选择 3 条建议」 |
| 再点一次 | 上述三条全部取消。数量回到 0 |
| 手动勾选两条待确认 | 数量「已选择 2 条建议」 |
| 再点全选后，手动取消标签 | 标题和简介仍勾选，标签和 ALT 未勾选。数量仍是 2 |
| 点击「确认采纳所选」 | POST 里的建议值只有 `title` 和 `summary`，没有 `tags`，没有 `alt:99` |
| 刷新后查库 | 标题变为「全选撤下建议标题」，简介变为「全选简介建议」，标签仍空。工作流仍是已撤下，`published=0` |

桌面和 390×844 都点过。移动端 `scrollWidth` 与 `clientWidth` 都是 390，没有横向溢出。截图：

- `docs/tasks/screenshots/xedc-ai-seo-select/evidence-article-desktop.png`
- `docs/tasks/screenshots/xedc-ai-seo-select/select-mobile.png`

## 4. 四种内容类型实际有哪些字段

对照 `config/sync` 的字段配置，并在实验室新建节点后用 `TextExtractor` 读取。没有假设四种类型字段相同。

| 类型 | 标题 | 简介 `field_summary` | 正文 `field_body` | 标签 `field_tags` | 栏目 `field_categories` | 图片字段 | 时长 `field_duration` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 文章 article | 有 | 有 | 有 | 有 | 有，词汇 `article_category` | 有 `field_cover` | 无 |
| 美图 photo | 有 | 有 | 无 | 有 | 有 | 有 `field_images`、`field_cover_image` | 无 |
| 视频 video | 有 | 无 | 有 | 有 | 有 | 有 `field_video_cover` | 有，整数秒 |
| 基础页 page | 有 | 无 | 有 | 无 | 无 | 无 | 无 |

文章、美图、视频在工作流 `xedc_editorial` 里。基础页不在这个工作流里。

人物字段 `field_model` 只在美图上。作者、来源在文章的 `extra` 里，只在节点真有这些字段时进入提取结果。图片和视频文件本身不读取。

## 5. 本地评分读什么

`SeoResult::score()` 只用提取后的文本，不用模型自报分数。权重仍是标题 20、描述 20、完整性 20、关键词 15、标签 10、ALT 10、链接 5。不适用的项不进分母。

- 标题：节点标题。
- 描述：已解析的 Meta Description，不是简介字段本身。
- 完整性：文章和基础页看正文长度；美图看简介；视频看文字和 `field_duration`。
- 关键词和标签：标题、简介、正文、栏目、标签的文字重合。没有标签字段的基础页，标签项为不适用。
- ALT：`field_images`、`field_cover_image`、`field_cover`、`field_video_cover` 上读到的替代文本。没有图片且不是美图时为不适用。
- 内部链接：美图和视频直接不适用。文章和基础页看的是去掉标签后的正文。原始 HTML 里的 `href` 在去标签后通常已经不在，所以评分可能显示「正文里没有链接」，即使原始正文里有站内链接。

## 6. DeepSeek 实际会收到什么

没有调用接口。`PromptBuilder` 在实验室里对四个真实节点组了消息，只检查字符串。

系统提示要求建议必须能从本次给出的文字里找到依据，不要编造人物、地点、品牌、事件或图片里无法确认的属性，栏目名不是内容事实，信息不够时要说明信息不足。

用户消息包含：内容类型、标题、简介、去标签正文（最长 8000 字）、栏目、标签、人物、其他文字、当前 Meta 令牌结果、ALT 文字、时长秒数、本地程序检查摘要。

实验室文章正文原始值含 `<a href="/node/1">`。快照能看到 `/node/1`。组好的用户消息里没有 `href=`。模型看不到链接地址，也收不到图片或视频文件。

## 7. 详情页展示的依据

新的分析在完成时把 `source_snapshot` 放进现有 `result` JSON，不新增表，也不存正文全文和媒体二进制。快照含节点 ID、修订 ID、语言，以及每一项的状态：已读取、内容为空、字段不存在、不适用。

详情页「本次分析依据」使用这份快照，并显示分析记录 ID、语言、时间、模型。不另调模型。

实验室文章页实际显示：原始标题「文章依据标题」、简介「文章简介原文」、正文摘要、标签「实验室标签」、栏目「实验室栏目」、图片数量 0、ALT「内容为空」、内部链接 `/node/1` 并注明模型看不到链接地址、视频时长「不适用」。

美图：正文「字段不存在」，空简介「内容为空」。视频：简介「字段不存在」，时长「125 秒」。基础页：标签「字段不存在」，时长「不适用」。

历史记录没有快照时，页面写明「这次分析没有保存读取快照，不能把当前内容当成当时的依据。」浏览器打开了记录 202，这句话在页面上。没有把节点后来的内容填进去冒充当时的输入。

## 8. 标题、简介和标签怎么写入

只处理用户勾选且属于本次分析的建议。Form API 仍用 `items` 树，未勾选的值是 0，不会被当成要处理的 key。

| 操作 | 结果 |
| --- | --- |
| 不勾选就提交 | 提示至少选择一条。标题和简介不变 |
| 只采纳标题 | 只改标题。简介、标签、工作流不变 |
| 恢复标题 | 回到采纳前的标题 |
| 只采纳简介 | 只改 `field_summary` |
| 恢复简介 | 回到原简介 |
| 只采纳标签 | 只关联词汇 `tags` 里已有的「实验室标签」。缺失词「并不存在的实验室词」没有新建。标题不变 |
| 恢复标签 | 标签回到采纳前的空值 |
| 同时采纳标题、简介、标签 | 三项都写入。草稿仍是草稿 |
| 版本冲突 | 修订从 1 变为当前修订时不覆盖。标题和简介保持原值 |
| 基础页勾选简介 | 该类型没有简介字段，返回「这个内容类型没有简介字段，未写入。」标题不变 |

文章的 Meta Title / Description 仍走现有令牌，不在这里另写 Metatag 字段。视频和基础页没有 `field_summary`，采纳简介不会改它们的 Meta Description。

## 9. 已撤下内容的普通采纳

普通按钮是「确认采纳所选」。它保存勾选字段和新修订，然后把工作流状态设回保存前的值。

实验室文章从已撤下采纳标题后，状态仍是已撤下，未发布。浏览器那篇在采纳标题和简介后同样是已撤下，`published=0`。

其他状态各测了一篇，采纳标题后状态不变：

| 采纳前 | 采纳后 | 是否公开 |
| --- | --- | --- |
| 草稿 | 草稿 | 否 |
| 待审核 | 待审核 | 否 |
| 退回修改 | 退回修改 | 否 |
| 已发布 | 已发布 | 仍是已发布 |
| 已撤下 | 已撤下 | 否 |
| 已拒绝 | 已拒绝 | 否 |

已发布那篇先补了配置里的翻译语言，避免现有自动翻译保护拦住写入。测试前已把实验室里的自动翻译开关关掉，所以没有对外请求。`xedc_ai_usage` 行数前后相同。

## 10. 重新发布的权限和工作流

`xedc_editorial` 里，撤下只从已发布进入已撤下。没有任何转换从已撤下出发，包括不能到已发布、草稿或待审核。这次没有改 `config/sync`，也没有用 SQL 改 moderation state。

因此「采纳并重新发布」在当前站点不能把内容公开。详情页用独立的折叠区，和「确认采纳所选」分开，写明当前标题、当前状态已撤下、公开风险，以及「从已撤下可以到达：没有」。

浏览器：

- 点「取消」只收起折叠区，没有新的 POST。
- 不勾选确认就点「确认重新发布」：提示「已取消重新发布，内容没有修改。」标题仍是原文。
- 勾选确认再点：POST 带确认字段。页面提示工作流没有这条转换。标题不变，状态仍是已撤下。

程序侧还测了：

- 没有「采纳 SEO 建议」权限的用户：提示没有权限，内容不改。
- 有采纳和编辑权限、但工作流仍不允许的用户：同样拒绝，不写入、不公开。
- 修订号与分析时不一致的已撤下内容：在转换不存在时就拒绝，不公开。

若以后工作流里真有从已撤下到已发布的转换，代码会先检查 `use {工作流} transition {转换}`，先按普通采纳写入并保持已撤下，再通过实体 API 设置 `moderation_state`，失败则不把内容当成已公开。这条成功路径在当前配置里不存在，没有为了测试去加转换。

## 11. Docker 测试

`docker compose -f web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.yml run --rm seo-test`：`OK 0 failed`。其中包含四种内容的快照状态，以及不保存正文原文和媒体文件。

隔离库：`docker-compose.lab.yml`，项目名 `xedc-seo-apply`，只监听 `127.0.0.1:3307`。从生产做只读导出后导入。测试进程通过独立挂载使用实验室连接，并关掉 Redis 缓存配置。进入写操作前确认端口是 3307。实验室里 `xedc_ai` 结构版本仍是 10002，没有执行 `updb`。

该轮结果：`LAB OK`。结束后容器和临时库文件已删除。

## 12. 浏览器测试

同一轮隔离站点里，桌面 1280×900 和 390×844 都由 Chromium 实际点击。结果 `BROWSER OK`。覆盖全选、取消、手动勾选、POST、采纳后的已撤下状态、重新发布取消、重新发布被拒绝、四种内容的依据表、记录 202 的缺失快照提示、移动端不溢出。

## 13. 修改文件

- `web/modules/custom/xedc_ai/js/ai.js`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `web/modules/custom/xedc_ai/src/Form/SeoApplyForm.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoAdvisor.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoResult.php`
- `web/modules/custom/xedc_ai/src/Controller/SeoController.php`
- `web/modules/custom/xedc_ai/src/Review/PromptBuilder.php`
- `web/modules/custom/xedc_ai/src/Review/ReviewRunner.php`
- `web/modules/custom/xedc_ai/src/Review/TextExtractor.php`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`
- `web/modules/custom/xedc_ai/tests/manual/seo-ops-test.php`
- 本报告

没有改 AI 客户端、DeepSeek Provider、预算扣费、`xedc_auto_translate`、`config/sync`。没有 Git commit。

## 14. 数据库结构

没有新字段，没有新表，没有新索引。分析依据放在已有的 `xedc_ai_review.result` JSON 里，键名 `source_snapshot`。旧记录缺这个键时页面会说明没有快照。

生产库结构版本仍是 `10002`。没有在生产执行 `drush updb`。

## 15. 未验证

- 当前工作流不能从已撤下合法变成已发布，所以「采纳并重新发布」的成功公开路径没有执行，也没有为了让它成功而绕过工作流。
- 转换权限 `use xedc_editorial transition …` 只在存在合法转换时才会走到。这次已撤下内容在那之前就被拒绝。无采纳权限的用户已经测过，内容没有改。
- 没有调用 DeepSeek，没有产生模型费用。提示词是本地组装后检查的。
- 生产页面没有用浏览器点。生产节点 13 在测试前后仍是「海边风景手机壁纸高清下载」，修订 2383，已撤下，未发布。数据库端口仍是 3306。
- 实验室文章和美图没有挂实际媒体，所以图片数量是 0、ALT 是内容为空。带真实 ALT 的比例在不启动 Drupal 的快照测试里覆盖过。
