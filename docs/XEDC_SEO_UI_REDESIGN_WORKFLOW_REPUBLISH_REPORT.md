# SEO 分析界面重做与已撤下内容重新发布

## 1. 原来的界面为什么不好看

列表和详情走的是 `/admin/xedc/ai/seo` 与 `/admin/xedc/ai/seo/{review}`，控制器 `SeoController`，模板 `xedc-ai-seo.html.twig`、`xedc-ai-seo-detail.html.twig`，样式库 `xedc_ai/admin`。

前几轮是在同一份 `ai.css` 上不断加选择器。旧的看板网格（`.xedc-ai-board*`）已经没人用，新的表格规则又叠在上面。后台主题还对 `.xedc-admin-content td` 使用 `white-space: nowrap`，表格一多就挤成一行。详情页把修订号、评分、问题和建议拆成多块卡片，建议的当前值和建议值写在同一句说明里。所以页面越改越碎，不像一套后台。

这次删掉了不再使用的看板样式，列表和详情改成同一套 `.xedc-seo` 规则，不再往旧选择器上打补丁。其他 AI 页面仍用原来的 `.xedc-ai` 样式。

## 2. 列表页现在的结构

页面标题仍是「SEO 分析」。说明改为一句：查看内容 SEO 表现、分析问题和优化建议。

筛选是一行轻量工具栏：搜索、内容类型、状态、评分、问题、模型、日期。原有条件都还在，没有统计卡。

表格七列：内容信息、SEO 评分、分析结果、AI 用量、分析状态、分析时间、操作。标题是主信息，类型和内容 ID 在下面。评分是数字。问题和建议是轻量标签。用量用较浅的文字。状态是中文标签。时间格式是 `Y年n月j日 H:i`。操作只有「查看详情」。

800px 以下表头隐藏，每一行按这些列名竖排，不把桌面表挤进屏幕。

## 3. 详情页现在的结构

顶部是标题、类型、内容 ID、发布状态、工作流状态，以及返回列表。修订号只在折叠的「分析依据与记录」里。

综合评分是一个数字加等级（良好、一般、需改进）。没有评分规则、扣分依据或维度说明。

问题按条分开，严重程度是高、中、低，字段名是中文。

建议是页面的主要区域。可处理的建议在表单里：字段名、状态、当前内容、建议内容、原因，以及勾选。当前内容和建议内容分成两列，建议内容字重更高。不可写入的建议用同样的只读行放在上面。单条采纳、批量勾选、全选、取消、忽略、恢复都还在原来的提交处理里。

模型、Token、费用和时间放在「AI 用量」，不占主要位置。未核算费用仍用原来的费用文案，不会被写成 0 元。

## 4. 桌面端和移动端

浏览器登录后看了真实页面。列表表头是上面的七列，单元格 `white-space` 为 `normal`，样式里能匹配到新的表格规则。美图筛选得到 12 行。内容 ID 搜索在上一轮已确认可用；这一轮结果集仍不足 20 条，所以没有分页条，分页器仍按每页 20 条挂着。

390px 下页面 `scrollWidth` 为 390，表头隐藏，没有横向溢出。移动端列表是深色背景下的分组信息。

截图：

- `docs/tasks/screenshots/xedc-seo-saas/list-desktop.png`
- `docs/tasks/screenshots/xedc-seo-saas/detail-desktop.png`
- `docs/tasks/screenshots/xedc-seo-saas/list-mobile.png`
- `docs/tasks/screenshots/xedc-seo-saas/detail-dark.png`
- `docs/tasks/screenshots/xedc-seo-saas/detail-mobile.png`

## 5. 深色模式

`data-theme` 设为 dark 后，文字颜色是 `rgb(228, 228, 231)`，背景使用后台的深色变量。移动端列表截图是深色页面，没有白卡片配黑字。

## 6. 工作流原来缺什么

`xedc_editorial`（DcLili 会员投稿审核）里，已撤下的 machine name 是 `archived`，已发布是 `published`。原来的转换里，`archive` 只从 `published` 到 `archived`。没有任何转换从 `archived` 出发。所以「从已撤下可以到达」是空的。

采纳代码如果直接 `$node->set('moderation_state', 'published')`，Content Moderation 会拒绝，因为不存在这条转换。

## 7. 新增的合法转换

`xedc_ai_update_10004()` 只在活动配置里增加转换：

- ID：`republish`
- 名称：重新发布
- 从：`archived`（已撤下）
- 到：`published`（已发布）

已有转换没有删、没有改。转换总数从 11 个变为 12 个。若已经存在从已撤下到已发布的转换，则不会再加一条。

`config/sync/workflows.workflow.xedc_editorial.yml` 没有改。活动配置里的这条转换，在下次从 `config/sync` 导入时会被旧配置覆盖。部署前需要把这条转换和对应权限导出，否则导入后重新发布会再次消失。

## 8. 谁可以使用这个转换

权限名是 `use xedc_editorial transition republish`。

只授予已经拥有 `use xedc_editorial transition publish` 的非会员角色。当前是 `content_editor`。`authenticated` 和 `anonymous` 明确不加。管理员角色 `is_admin`，本来就能通过权限检查。

有「采纳 SEO 建议」权限的人，如果没有这条转换权限，不能重新发布。

已核对：内容编辑有权限，普通会员没有。节点 13 当前是 `archived`，`status` 为未发布。管理员在这个节点上的合法下一步只有 `republish`。会员账号的合法下一步里没有它。这次没有保存该节点。

## 9. 采纳之后如何重新发布

已撤下内容的详情页单独给出「采纳建议并重新发布」。确认文案写明：会先写入选中的建议；成功后才执行正式转换；内容会重新公开；权限不足或转换失败时不会自动公开。普通「确认采纳所选」仍然只写字段，不改变工作流。

重新发布的顺序：

1. 先按原有规则写入选中的建议，并保留当前工作流状态。
2. 只有全部选中项都采纳成功，且没有失败或版本冲突，才继续。
3. `StateTransitionValidation` 确认当前用户可以使用 `republish`。
4. 把审核状态设为该转换的目标状态 `published`，保存为新修订。没有调用 `setPublished(TRUE)`，也没有改数据库表。
5. 重新读取默认修订，确认 `moderation_state` 是 `published` 且内容已发布，并且默认修订不旧于刚保存的修订。

操作日志记内容 ID、分析 ID、建议、原状态、目标状态、用户、时间和结果。日志会先去掉密钥类文本。

## 10. 失败时如何保持原状态

`SeoResult::republishDecision()` 的结果：

| 当前状态 | 情况 | 结果 |
| --- | --- | --- |
| 已发布、草稿、待审核、退回修改、已拒绝 | 建议采纳成功 | `keep`，不改状态 |
| 已撤下 | 采纳成功且有转换和权限 | `republish` |
| 已撤下 | 没有转换权限 | `denied`，保持已撤下，已写入的字段保留 |
| 已撤下 | 采纳失败或没有写入 | `apply_failed`，不发布 |
| 已撤下 | 版本冲突 | `conflict`，不发布 |
| 已撤下 | 没有合法转换，或保存时工作流拒绝 | 不发布 |

普通采纳仍把 `moderation_state` 和发布状态设回保存前的值，所以已发布、草稿、待审核、退回修改、已拒绝不会因为采纳而变成别的状态。

## 11. 自动翻译和修订

已经发布、并且还缺翻译目标语言的内容，原有的 `writeBlocked()` 仍会拒绝写入。

重新发布前会再核对修订号。若和刚采纳时的修订不一致，停止发布并记为冲突，避免盖住自动翻译刚生成的修订。

发布成功后不再保存第二次。`xedc_auto_translate` 仍会在内容变为已发布时按原逻辑翻译。这次没有在生产内容上执行采纳或重新发布，所以没有实际生成翻译修订。

## 12. Docker 测试

`docker compose -f web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.yml run --rm seo-test` 结果为 OK，0 failed。其中包含上面的状态决策。该容器只跑 PHP，不启动 Drupal，也不改生产内容。

现有实验室库没有可登录的内容站点。没有把生产库导入实验室，也没有为了测试去改生产节点。

## 13. 浏览器测试

在 Chromium 中登录运行中的站点。

已看到：

- 新的七列表格、新的说明文字、没有统计卡、没有评分规则。
- 详情评分 100、等级「良好」，问题按条分开，建议分成当前内容和建议内容。
- 「全选待处理建议」把已选数量从 0 改为 5，没有提交。
- 按钮包括：全选待处理建议、采纳建议并重新发布、确认采纳所选、拒绝所选、恢复所选原值。
- 已撤下内容不再显示「从已撤下可以到达：没有」。
- 深色模式和 390px 宽度没有横向溢出。

没有在生产内容上提交采纳、忽略、恢复或重新发布。因此没有在浏览器里改变任何一个节点的 `moderation_state`、`status` 或公开访问结果。

## 14. 修改的文件

- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`
- `web/modules/custom/xedc_ai/src/Form/SeoApplyForm.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoAdvisor.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoResult.php`
- `web/modules/custom/xedc_ai/xedc_ai.install`
- `web/modules/custom/xedc_ai/xedc_ai.services.yml`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `web/modules/custom/xedc_ai/tests/manual/seo-ops-test.php`

## 15. 还没解决的问题

- `config/sync` 里还没有 `republish`。配置导入会把它和内容编辑角色上的新权限冲掉。
- `xedc_ai` 的 schema 仍是 10002。`10003`（用量表字段）和 `10004` 都还显示为待执行。`10004` 的函数已经对活动配置执行过一次，再次执行不会重复添加转换。这次没有跑 `10003`，因为它会改生产表结构。
- 采纳、忽略、恢复和真正保存「重新发布」没有在生产节点上点过。节点 13 仍是已撤下、未发布。
- 隔离 Docker 不能打开带内容的后台，所以界面是在运行中的站点上用浏览器看的。

## 没有做的事

- 没有修改生产内容节点。活动配置中的工作流和内容编辑角色权限已更新，并重建了缓存。
- 没有修改 `config/sync`。
- 没有执行生产数据库结构升级，没有运行 `drush updatedb`。
- 没有修改 Composer 依赖或 `composer.lock`。
- 没有执行 Git commit、push、reset 或 checkout。
