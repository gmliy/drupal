# XEDC AI SEO 运营系统修复报告

日期：2026-11-05。站点文件已改，未提交 Git，未执行 `drush updb`，未改内容 13 的字段。

## 1. 原始问题

内容 13 的 SEO 页曾显示摘要为空、问题为空、建议为空、评分为空、Token 0、费用未核算。当时审核 196 还在队列里，cron 的 30 秒预算被更早的调用占满，页面把“尚未分析”画成了一次空的成功结果。旧解析器还会把模型没给的分数写成 85 或 55。

## 2. 原因

- 队列 worker 的 cron 时间是 30 秒，deepseek-flash 一次调用大约 12–25 秒，排在后面的任务还没调用就被页面当成完成。
- 解析失败或结果为空时仍可能落到空字段，模板把空字符串写成“没有摘要 / 没有列出问题 / 没有修改建议”。
- 没有用量行时，合计被显示成 Token 0 · 未核算。
- deepseek-flash 默认开启思考。思考和正文共用 `max_tokens`（当前模型配置 4096）。思考占满额度后 `finish_reason=length`，正文为空。审核 199 就是这种情况：输入 559、输出 4096、费用 0.016395 元，分析被标为失败，没有保存空成功。
- `LocalInspector` 仍用一个参数构造 `TextExtractor`，SEO 在调用模型前抛出异常。审核 198 因此失败，且没有用量行。

## 3. 修改文件

只改了 `web/modules/custom/xedc_ai/`。新增：

- `src/Seo/SeoResult.php`
- `src/Seo/ContentLookup.php`
- `src/Seo/SeoSchedule.php`
- `src/Usage/UsageReport.php`
- `src/Form/SeoApplyForm.php`
- `templates/xedc-ai-seo-detail.html.twig`
- `tests/manual/seo-ops-test.php`
- `tests/manual/docker/docker-compose.yml`

其余为该模块内的服务、表单、控制器、提示词、客户端、模板、样式和 `update_10003`。没有改 `config/sync`，没有提交密钥。

## 4. 数据库

`xedc_ai_update_10003` 已写入安装文件，**生产库尚未执行**。将增加：

- `xedc_ai_model.cache_price`
- `xedc_ai_usage.entity_id`、`revision_id`、`usage_reported`、`cache_read_tokens`、`http_status`、`finish_reason`、`retry_index`、`cost_status`

代码在列不存在时跳过这些字段。Token 合计和费用仍使用原有列，所以审核 202 在未更新结构时已经显示 Token 1473 和已核算费用。调用表里的 HTTP、结束原因、缓存列要等更新之后才有独立存储。结束原因目前另存在分析结果 JSON 的 `analysis_metadata.finish_reason`，详情页会显示。

本次真实调用新增的业务行（不是结构变更）：审核 198、199、202，用量 175、178。内容 13 的标题、简介、修订 215、已撤下、未发布均未改变。

## 5. SEO 功能

| 项 | 状态 |
| --- | --- |
| 严格 JSON 解析、Markdown 包裹、空结果失败、截断失败 | 已实现，Docker Mock 通过 |
| 按规则计算评分，忽略模型自报分数 | 已实现，真实调用验证（审核 202 为 46） |
| 美图 / 视频 / 文章 / 页面提示词，只要文字和元数据 | 已实现。真实调用只覆盖美图 13 |
| 列表、详情、运营状态 | 已实现。详情 202 已用 Drupal 渲染核对 |
| 逐项采纳写入 | 代码已实现。本次没有对任何线上内容执行采纳 |
| 定期检查 | 已实现，配置缺省为关闭，本次没有跑调度 |

审核 202（真实 DeepSeek，思考已关闭）：

- 摘要：该页面为壁纸类图片内容，标题与图片主题关联弱，简介和正文为空，ALT 文本与图片内容不匹配，栏目和标签信息不足，Meta 标题和描述直接复用标题，缺少关键词和页面 SEO 优化。
- 问题 6 条，建议 12 条，其中可采纳的包括标题、简介、标签和三张已关联图片的 ALT。
- 评分 46。规则：起始 100，简介为空 -18，问题严重程度合计上限 -36。
- 建议标题：海边风景手机壁纸高清下载。
- 结束原因 stop。输入 560，输出 913，合计 1473。费用 0.00366 元。
- 关键词和内部链接标记为不适用，不会写入。

审核 202 的标签建议是在拆词修复之前生成的，页面上会看到逗号串和顿号串叠在一起。采纳时会按逗号拆开，并且只关联词表里已有的术语。之后的新分析会先拆词再展示。

## 6. 内容 ID

已实现并通过 Drush 查询（uid 1）：

- 13：美图《很多很多很好经济学家的》，内容 ID 13，修订 215，当前修订 215，工作流 `xedc_editorial`，状态已撤下。运营状态为待人工确认。
- 999999：没有找到内容 ID 999999。
- 修订 999999：修订版本不存在，或不属于内容 13。
- 内容 8：视频，修订 2359。

创建时间按当前账号时区显示。站点默认时区是 Asia/Shanghai，uid 1 的时区是 UTC，所以预览为 2026-10-14 22:08，即上海时间 2026-10-15 06:08。

标题搜索、类型筛选、文章和基础页的页面点击没有在浏览器里走完。

## 7. Token

真实用量来自 API 的 `usage`，没有按字数估算。

- 审核 199：服务商返回了 usage，但正文被截断，记录为失败。输入 559，输出 4096，合计 4655。
- 审核 198：模型没有被调用，页面显示“尚未调用”，不是 Token 0。
- 审核 202：输入 560，输出 913，合计 1473，`usage_reported` 写在结果元数据里。
- Mock 覆盖了 usage 缺失、Token 0、重试累计。真实 API 没有再造 429、500 和超时。

## 8. 费用

单价来自模型配置：deepseek-flash 输入 0.02 元/百万、输出 4 元/百万，缓存单价为空。审核 202 为已核算 0.00366 元。没有把价格写死在 SEO 页面。

Mock 覆盖了单价未配置、计算失败、费用为零、缓存单价缺失。这些没有用真实 API 再打一遍。

## 9. Metatag

美图、文章、视频、基础页没有节点级 Metatag 字段。标题和简介走默认令牌：标题是 `[node:title] | [site:name]`，美图简介会进入 Meta Description。确认写入标题或 `field_summary` 后，前台 Meta 会跟着变。没有新建关键词 Meta 标签。本次没有写入，所以内容 13 的 Meta 仍是“很多很多很好经济学家的 | DcLili”和“很多很多很好经济学家的 - 壁纸”。

## 10. Docker

`web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.yml`，镜像 `php:8.3-cli`，只读挂载模块，不连接数据库。最后一次结果：`OK 0 failed`。这是 Mock，不是真实 API。

覆盖了合法 JSON、Markdown JSON、非法 JSON、空结果、类型错误、截断、429、500、超时、usage 缺失、费用几种状态、思考模式只在 JSON 请求里关闭。

## 11. 真实 DeepSeek

| 审核 | 内容 | 结果 | Token | 费用 |
| --- | --- | --- | --- | --- |
| 198 | 13 | 调用前异常，未请求模型 | 尚未调用 | 尚未调用 |
| 199 | 13 | 思考占满 4096，截断失败 | 4655 | 0.016395 元 |
| 202 | 13 | 成功，有摘要、问题和建议 | 1473 | 0.00366 元 |

同一时段 uid 1 还分析了内容 15（审核 200，同样被截断，0.016391 元）和内容 17（审核 201，成功，1365 Token，0.003402 元）。这两次不是内容 13 的验收调用。

内容 13 在三次操作后仍是修订 215、已撤下、未发布，标题未改。

## 12. 未解决

- 生产库还没有 `update_10003`。调用明细的 HTTP、缓存 Token、结束原因列在更新前是空的。
- 没有在浏览器里做桌面和手机点击。详情和列表用 Drupal 渲染器核对过。
- 没有对内容 13 执行采纳，因此没有产生新的内容修订，也没有改 ALT。
- 文章、基础页、版本冲突、拒绝建议没有做真实写入测试。
- 定期检查没有开，也没有跑队列扫描。
- 审核 202 的标签展示仍是修复前的逗号串。需要再分析一次才会用新的拆词结果。

## 13. 生产部署

代码已经在 `/var/www/drupal` 的工作副本里，缓存已重建。还没有 Git commit。

上线结构更新时，在确认备份之后执行：

```bash
./vendor/bin/drush updb -y
./vendor/bin/drush cr
```

不要打开“定期 SEO 检查”，除非准备好每日条数和预算。模型缓存单价可以在模型表单里填，不填时缓存命中会标成单价未配置，而不会按输入价计。

验收页：`/admin/xedc/ai/seo/202`。

## 14. 回滚

结构还没更新时，回滚就是恢复 `web/modules/custom/xedc_ai` 的文件并 `drush cr`。审核 198–202 和用量 175–178 可以留着，它们没有改内容。

如果已经执行了 `update_10003`，回滚代码前先删掉新增列（有数据也不会丢原有 Token 列）：

```sql
ALTER TABLE xedc_ai_usage
  DROP COLUMN entity_id,
  DROP COLUMN revision_id,
  DROP COLUMN usage_reported,
  DROP COLUMN cache_read_tokens,
  DROP COLUMN http_status,
  DROP COLUMN finish_reason,
  DROP COLUMN retry_index,
  DROP COLUMN cost_status;
ALTER TABLE xedc_ai_model DROP COLUMN cache_price;
```

不要用 SEO 采纳去改已撤下内容，除非人工确认当前修订仍是分析时的修订。写入会新建修订，并保持原来的发布状态和工作流；版本不一致时会拒绝覆盖。
