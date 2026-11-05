# XEDC 标签 AI 真实小规模测试与运营中心用量接入报告

日期：2026-11-04  
模块：`xedc_tags`、`xedc_ai`  
原则：一次真实 API 调用；复用统一客户端与 `xedc_ai_usage`；不改密钥/模型配置/标签数据。

## 1. 修改文件清单

| 文件 | 变更 |
| --- | --- |
| `web/modules/custom/xedc_tags/src/TagAiAnalyzer.php` | 每次真实调用写入一条 `xedc_ai_usage`；`max_tokens=500`；收紧输入规模 |
| `web/modules/custom/xedc_ai/src/Labels.php` | `xedc_tags` 来源中文标签 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-usage.html.twig` | 用量页「来源」筛选增加标签管理 |
| `web/modules/custom/xedc_tags/tests/manual/tag-ai-live-usage-test.php` | 单次真实调用 + 用量/数据安全验收脚本 |

未改：Provider 密钥、模型单价配置、TagMerger、预算限流规则本体、Core、contrib、`config/sync`。未 commit / push。

## 2. 接入前结论

`TagAiAnalyzer` 已走 `xedc_ai.client` 与 `xedc_ai.budget`，**但未调用 `xedc_ai.usage`**，因此运营中心原先看不到标签 AI 请求。

本次按 `ProviderForm` 连接测试同一模式补齐：`usage->start` → `client->once` → `usage->finish`（异常则 `interrupt`）。不在 Analyzer 外再写第二套统计。

现有表无独立 `task` 列。约定：

- `source` 机器值 = `xedc_tags`（对应需求 Source）
- 任务语义固定为标签语义分析；运营中心展示文案：`标签管理（标签语义分析）`

## 3. 实际调用结果（真实 API）

| 项 | 值 | 性质 |
| --- | --- | --- |
| 分析标签 | tid=`15`，名称「美女」（已发布使用 11） | 真实 |
| 调用次数 | **1** | 真实 |
| Provider | `deepseek` / Deepseek | 真实 |
| Model | `deepseek-flash` | 真实 |
| 是否连通 | 成功（`status=success`） | 真实 |
| Input Tokens | **324**（`prompt_tokens`） | 真实 API usage |
| Output Tokens | **500**（`completion_tokens`，触达本次上限） | 真实 API usage |
| Total Tokens | **824** | 真实（324+500） |
| 费用 | **0.002006 元**（`cost_micro=2006`，`cost_known=1`） | 真实，按模型单价计算 |
| 耗时 | **3690 ms** | 真实 |
| 用量记录 ID | **165** | 真实 |
| 建议条数 | 0（模型输出触达 500 token 上限，校验未接受为合法建议） | 真实；**未伪造建议** |

费用公式沿用 `Money::costMicro(prompt, completion, inputPrice, outputPrice)`；当前模型单价已配置（input=`0.02` / output=`4`，单位与现有系统一致）。

## 4. AI 运营中心查询结果

- 路径：`/admin/xedc/ai/usage?source=xedc_tags`
- 页面可见：来源「标签管理（标签语义分析）」、供应商 `deepseek`、模型 `deepseek-flash`、输入 324、输出 500、费用 0.002006
- 概览页最近调用包含「标签管理」来源文案
- `UsageLogger::search(['source'=>'xedc_tags'])` 可命中 ID 165
- 本次仅新增 **1** 条用量；其他 source 计数未因本次调用重复增加

## 5. 原有测试结果

| 脚本 | 结果 |
| --- | --- |
| tag-center-self-test.php | SELF TEST PASS |
| tag-text-test.php | TAG TEXT PASS |
| tag-center-extended-test.php | EXTENDED TEST PASS |
| tag-phase2-security-audit-test.php | PHASE2 AUDIT PASS |
| tag-ai-live-usage-test.php | LIVE TEST PASS |

## 6. 数据安全检查

调用前后对比：

| 检查项 | 结果 |
| --- | --- |
| 标签总数 | 34 → 34 |
| Term ID 集合 | 不变 |
| `node__field_tags` 引用数 | 81 → 81 |
| 标签名称 | 不变 |
| 合并/回滚 | 未执行 |
| 密钥/模型配置 | 未修改 |

## 7. 尚未解决 / 说明

1. **建议内容为空**：本次输出 token 正好打满 500，响应可能被截断，校验未通过；用量与费用记录仍然有效。若需提高建议命中率，可在后续单独提高 `MAX_OUTPUT_TOKENS` 或进一步压缩 prompt（需另开任务，避免再次自动调用）。
2. **无独立 task 字段**：现有 `xedc_ai_usage` 仅有 `source`；任务名通过展示文案体现，未扩表。
3. **未做浏览器截图验收**：页面渲染与查询在服务端已验证。
4. **未再次发起第二/三次真实调用**（避免额外费用）。

## 结论

标签 AI 已通过统一 `xedc_ai` 客户端完成一次低成本真实调用；Provider、Model、输入/输出/总 Token、费用与状态已写入 `xedc_ai_usage` 并在运营中心可查、计入概览统计；生产标签数据未改动。
