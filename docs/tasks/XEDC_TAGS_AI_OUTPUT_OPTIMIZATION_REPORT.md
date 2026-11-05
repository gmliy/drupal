# XEDC 标签 AI 输出优化报告

日期：2026-11-04  
模块：`xedc_tags`、`xedc_ai`  
原则：保留 `MAX_OUTPUT_TOKENS = 500`；不发起真实 API；不改密钥/模型/预算/合并逻辑；只做 Prompt 精简、JSON 压缩与截断识别。

## 1. 修改文件

| 文件 | 变更 |
| --- | --- |
| `web/modules/custom/xedc_tags/src/TagAiAnalyzer.php` | 精简 System/User Prompt；紧凑 `results` JSON；截断/空建议/非法 JSON 分流；兼容旧建议数组；保留用量写入与人工确认 |
| `web/modules/custom/xedc_ai/src/Client/ChatResponse.php` | 增加只读 `finishReason`（默认 `''`，兼容既有调用方） |
| `web/modules/custom/xedc_ai/src/Client/OpenAiChatClient.php` | 从 `choices[0].finish_reason` 读取并传入 `ChatResponse` |
| `web/modules/custom/xedc_tags/tests/manual/tag-ai-output-optimization-test.php` | 本地模拟解析/截断/用量保护测试（无真实 API） |
| `docs/tasks/XEDC_TAGS_AI_OUTPUT_OPTIMIZATION_REPORT.md` | 本报告 |

未改：Provider 密钥、模型配置与单价、预算规则本体、`TagMerger`、Core、contrib、`config/sync`。未 commit / push。

## 2. 原始 Prompt 的主要问题

上一轮真实调用（用量 ID 165，deepseek-flash）输入 324 / 输出 500 / 建议 0，根因是输出触达上限后 JSON 不完整。

优化前实现的主要浪费点：

1. **User payload 过重**：包含 `usage` / `photo` / `video` / `article`、`alias`、`has_description`、最多 5 条 `sample_titles`（含节点标题与类型）、相关标签还带 `shared` 计数。
2. **System Prompt 要求大字段输出**：每条建议强制 `source_name`、`target_name`、`confidence`、`affected_count`、`risk`、`recommended_action` 等，模型容易写长解释。
3. **缺少截断识别**：未读取 `finish_reason`；截断后的残缺 JSON 被当成普通校验失败，无法区分「正常空建议」与「输出被砍断」。
4. **空结果语义不清**：`suggestions=[]` 容易与 API/解析失败混在一起。

## 3. 优化后的 Prompt 结构

### System Prompt（约 239 字）

- 角色：标签语义分析助手。
- 只输出规定 JSON，禁止 Markdown 与其它文字。
- 紧凑格式：`{"results":[{"tid":数字,"similar":[相关tid],"suggestions":["短建议"],"reason":"一句话"}]}`
- 规则：`tid` 必须来自输入；`similar` 只能是输入 `related` 中的 tid；不确定用空数组；禁止编造 tid；禁止删除/自动合并/改 URL。

### User Prompt（单标签 JSON）

仅保留：

- `tid`、`name`
- 有描述时才加 `desc`（最多 80 字）
- 有相关标签时才加 `related`（最多 5 个 `{tid,name}`）

已移除：样例标题、用量拆分、别名、SEO、全站标签列表、节点正文。

单次 `analyzeTerm` 仍只分析 **1** 个标签；相关标签仅作相似候选上下文。

### 与现有建议表的兼容

紧凑结果映射为原有建议行：

- `similar[]` → `suggestion_type=near_duplicate`，`target_tid` 为相似 tid
- `suggestions[]` 文本 → `suggestion_type=keep`，写入 `recommended_action`
- 仍写入 `xedc_tag_ai_suggestion`，状态 `pending`，需人工 `accepted` / `rejected` / `ignored`
- 旧版数组字段格式仍可解析（本地测试已覆盖）

## 4. Token 上限

`MAX_OUTPUT_TOKENS = 500`，**未提高**。

## 5. JSON 输出模式

未启用 provider 专属 `response_format` / JSON Schema 模式。  
原因：不能假设所有 OpenAI Compatible API（含当前 Deepseek）参数一致。继续用提示词约束 + 服务端校验。

## 6. 截断识别逻辑

1. `OpenAiChatClient` 读取 `choices[0].finish_reason` → `ChatResponse::$finishReason`。
2. `TagAiAnalyzer::parseModelOutput()`：
   - `finish_reason` ∈ `length` / `max_tokens` / `max_completion_tokens` → `status=truncated`，不保存建议。
   - 或括号未闭合 / 尾部 `,` `:` `{` `[` / 未闭合字符串 → `truncated`。
   - JSON 非法且非截断特征 → `invalid`。
   - 空响应 → `empty_response`。
   - 结构合法且 `similar=[]` + `suggestions=[]` → `empty`（**成功空结果**，不是 API 失败）。
3. 用量仍在解析前由 `usage->finish` 落库；截断不会丢已产生的真实用量，也不会重复写第二条。

## 7. 本地模拟测试结果

脚本：`tag-ai-output-optimization-test.php`  
结果：**OPTIMIZATION TEST PASS**（无真实 API）

覆盖：

| 场景 | 结果 |
| --- | --- |
| 正常完整 JSON / 正常空建议 | PASS |
| 存在相似标签 / 文本建议 | PASS |
| JSON 截断 / `finish_reason=length` | PASS → `truncated` |
| JSON 格式错误 / 空响应 | PASS → 安全失败 |
| 未知 / 外来 Term ID | PASS → 拒绝 |
| Markdown 代码围栏 | PASS → 剥离后按空建议处理 |
| 危险文案（自动合并等） | PASS → 过滤 |
| 1～2 标签 results、无描述 payload | PASS |
| client `finish_reason` + usage tokens | PASS |
| 限流短路（不打 API） | PASS |
| 用量表 / 标签数 / 引用数不变 | PASS |

## 8. 四套回归 + 数据安全

| 脚本 | 结果 |
| --- | --- |
| tag-center-self-test.php | SELF TEST PASS |
| tag-center-extended-test.php | EXTENDED TEST PASS |
| tag-text-test.php | TAG TEXT PASS（Drush 因脚本 `exit` 报 abnormal，属既有现象） |
| tag-phase2-security-audit-test.php | PHASE2 AUDIT PASS |

测试前后：

| 检查项 | 前 | 后 |
| --- | --- | --- |
| 标签总数 | 34 | 34 |
| Term ID 集合 | `3,5,7,…,82` | 不变 |
| `node__field_tags` 引用 | 81 | 81 |
| `xedc_ai_usage` 总行数 | 13 | 13 |
| `source=xedc_tags` | 1 | 1 |

未合并标签、未创建真实标签、未改别名、未新增生产用量。

## 9. AI 运营中心统计保护

保留：

- `xedc_ai.client` / `xedc_ai.budget` / `xedc_ai.usage`
- 单次真实调用单条 `xedc_ai_usage`（`start` → `once` → `finish`）
- `source=xedc_tags`、Provider、Model、Token、费用、耗时、成功/失败
- API 未返回 usage 时不伪造 Token / 费用

本次本地测试**未写入**新的用量行；未把模拟数据记成真实调用。

## 10. 真实 API / 费用

| 项 | 结果 |
| --- | --- |
| 本次真实 API 调用 | **否** |
| 新增真实费用 | **否** |
| 未运行 | `tag-ai-live-usage-test.php` |

真实效果需管理员日后手动再测一次；本任务禁止自动发起。

## 11. 人工确认与能力边界（未变）

AI 只生成建议。仍禁止：自动建标签、改名、建别名、合并、改内容引用、自动 301。所有生产变更需管理员确认。

## 12. 尚未解决的问题

1. **真实 API 命中率未在本任务复测**：需管理员手动触发一次小规模分析验证 500 token 下是否完整返回。
2. **未启用 JSON Object / Schema 模式**：待确认各 Provider 兼容后再评估。
3. **相关集合上限 5**：极相似但不在 related 候选中的 tid 会被拒绝（刻意收紧，防编造）。
4. **`tag-text-test.php` Drush abnormal exit**：脚本主动 `exit` 导致，非本次回归失败。

## 结论

在不提高 500 输出上限、不发起真实付费调用的前提下，标签 AI 已改为更短 Prompt + 更紧凑 JSON，并能识别输出截断与正常空建议；Term ID 校验、人工确认与运营中心用量统计路径保持完好；生产标签与用量表未变化。
