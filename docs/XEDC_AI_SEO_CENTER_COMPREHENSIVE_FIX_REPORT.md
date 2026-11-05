# XEDC 智能 SEO 分析中心：异步分析、页面显示修复、列表 UI 优化与全面中文化

日期：2026-11-05。增量修复，未重写 SEO 业务链路。未改 `config/sync`，未对生产库做结构变更或 `updb`，未调用真实 DeepSeek，未做 Git commit / push / reset。

生产库端口仍是 3306。`xedc_ai` schema 仍是 10002。内容 13 修订、标题、发布与审核状态与测试前一致。生产密钥文件仍在原位置。

---

## 1. 七项问题的根因

| # | 问题 | 根因 |
| --- | --- | --- |
| 1 | 开始分析整页刷新、等待慢 | 早期在表单请求里同步调用模型；本轮确认已改为 AJAX 入队 + `QueueTerminator` 后台消费，并补强提交反馈与轮询停表逻辑。 |
| 2 | 修订版本 ID 输入框 | 普通用户无需手选修订；表单字段已删除，后端自动取当前修订。 |
| 3 | 内容 ID 区混入工作流/状态 | 旧预览脚本把 lookup 结果拼到输入区；现表单只保留 ID 与说明，不再渲染工作流机器名。 |
| 4 | 详情基本信息不完整 | 发布/状态/工作流读取与展示字段错位；「部分已采纳」曾混入基本信息。 |
| 5 | 列表数字无标签 | 表格仅靠表头；移动端隐藏 `thead` 后单元格无 `data-label`，修订列也挤占信息。 |
| 6 | 详情展示评分规则 | Twig 直接渲染 `score_rules`，属于内部计分说明，不应给运营看。 |
| 7 | `high · description` 等英文枚举 | 展示层缺少统一字段映射；`description` 等机器值直接或错误落文案。 |

---

## 2. 实际修改文件

- `web/modules/custom/xedc_ai/src/Labels.php`
- `web/modules/custom/xedc_ai/src/Controller/SeoController.php`
- `web/modules/custom/xedc_ai/src/Form/SeoSuggestForm.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoResult.php`
- `web/modules/custom/xedc_ai/src/Seo/ContentLookup.php`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`
- `web/modules/custom/xedc_ai/js/ai.js`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `web/modules/custom/xedc_ai/tests/manual/seo-center/`（实验室验收脚本副本）
- `docs/tasks/screenshots/xedc-ai-seo-center/`
- `docs/XEDC_AI_SEO_CENTER_COMPREHENSIVE_FIX_REPORT.md`

未改：`xedc_auto_translate`、ALT 检查算法、`SeoAdvisor` 采纳门闩、评分权重算法、数据库结构、生产内容。

---

## 3. 异步任务流程

1. 用户提交内容 ID → Drupal Form AJAX（CSRF 由 Form API 处理）。
2. `ContentLookup` 校验存在性与查看权限；读取**当前默认修订**。
3. `ReviewEnqueuer::request(..., 'seo_optimization', 'seo')` 写入 `xedc_ai_review` 并入队 `xedc_ai_review`。同 fingerprint 的未完成任务复用，不重复建队。
4. HTTP 快速返回；页面替换任务区为「等待处理」。
5. `QueueTerminator` 在 `kernel.terminate` 领取一项，交给现有 `AiReviewWorker`。
6. 模型调用只在 Worker 中发生；实验室把 Key 指到不存在路径，验证失败路径，不读生产密钥、不发 DeepSeek。

---

## 4. 任务状态与轮询

- 路由：`/admin/xedc/ai/seo/status/{review}`，权限 `view xedc ai reviews`，并校验目标节点 `view` 权限。
- 仅在存在 `data-review-id` 的进行中任务时启动轮询；`once` + `data-xedc-seo-polling` 防止多定时器。
- 间隔：2s → 3s → 5s → 8s → 12s → 15s。
- `done` / `failed` / `cancelled` 或节点移出 DOM 后停止。
- 刷新后若当前用户仍有 open SEO 任务，表单恢复并继续轮询。
- 状态文案：等待处理 / 正在分析 / 正在生成建议 / 已完成 / 分析失败 / 已取消。

---

## 5. 修订 ID 输入框

界面已彻底删除修订输入、标签与相关普通入口（非 CSS 隐藏）。系统内部仍用 `revision_id` 关联分析记录；采纳冲突保护未删。

---

## 6. 内容 ID 区域

仅保留：内容 ID 标签、输入框、中文说明「系统将自动读取当前修订版本」。不再显示工作流机器名、审核状态、修订号预览。

新建分析移到列表上方固定区块，便于提交后看见任务状态。

---

## 7. 详情页内容基本信息数据来源

| 字段 | 来源 |
| --- | --- |
| 内容类型 | `node_type` 配置实体 label；缺失时回退 `Labels::bundle()` |
| 内容 ID | 分析记录 `entity_id` |
| 分析修订 | 分析记录 `revision_id` |
| 当前修订 | 当前默认修订 `getRevisionId()` |
| 发布 | `field_published_at`；未发布或无有效时间 →「暂无」 |
| 状态 | `isPublished()` → 已发布 / 未发布 |
| 工作流 | Workflow 配置实体 `label()`；未关联 →「未配置」；未启用 →「未启用」 |
| 工作流状态 | Workflow State `label()`，再经 `Labels::moderation()` |

「部分已采纳」只在建议统计区（`data-xedc-suggest-stats`），不在基本信息。

说明：站点 `page` 类型配置 label 为「基本页面」，详情按配置显示，不是机器名 `page`。

---

## 8. 列表页字段与真实数据来源

| 展示 | 来源 |
| --- | --- |
| 标题 / 内容类型 / 内容 ID | `xedc_ai_review.title`、bundle label、`entity_id` |
| SEO 评分 | `seo_score` |
| 问题数 | `result.issues` 数量 |
| 建议数 | `SeoResult::itemRows()` 数量 |
| 模型 | `provider_id / model_name` |
| Token | `UsageReport` 已记录总量；未返回则保留原状态文案 |
| 费用 | `UsageReport::cost_label`（不伪造；未核算不显示成 0 元） |
| 分析时间 | `completed` 或 `created` |
| 处理状态 | `SeoResult::opsStatus()` → `Labels::seoOps()` |
| 操作 | 详情路由 |

修订 ID 已从列表主展示移除，数据库字段保留。

桌面端为分组表格；移动端卡片用 `data-label` 显示字段名。

---

## 9. 评分规则移除

详情 Twig 已删除「评分规则」整块；控制器不再向模板传 `rules`。`result.score_rules` 仍可存在于历史 JSON，算法与权重未改。

---

## 10. SEO 总评分保留

详情卡片「SEO 参考评分」与列表「SEO 评分」继续显示规则计算分。实验室记录 68 分仍可见。

---

## 11. 英文枚举中文映射清单

| 内部值 | 中文 | 方法 |
| --- | --- | --- |
| high / medium / low / critical | 高 / 中 / 低 / 严重 | `Labels::level` |
| title, seo_title | SEO 标题 | `Labels::field` |
| description, meta_description, summary… | 页面描述 | `Labels::field` |
| keywords | 关键词 | `Labels::field` |
| tags | 标签 | `Labels::field` |
| alt… | 图片 ALT | `Labels::field` |
| content, body… | 内容 | `Labels::field` |
| internal_links | 内部链接 | `Labels::field` |
| pending / applied / adopted / ignored / rejected / failed / conflict / partially_applied | 待处理/已采纳/已忽略/失败/版本冲突/部分已采纳 等 | `Labels::itemState` / `SeoResult::stateLabel` / `Labels::seoOps` |
| queued / running / awaiting / partial / stale… | 等待分析 / 分析中 / 待人工确认 / 部分已采纳 / 需要重新分析… | `Labels::seoOps` |

未知值走安全兜底（原值或「未知/中」），不抛错、不空白吞掉。

模型名 `deepseek / deepseek-flash` 与 Token 单位保留英文。

实测：`high · description` → **高 · 页面描述**。

---

## 12. 是否改变内部机器值

否。数据库枚举、API 参数、DeepSeek 请求字段、CSS 类名、PHP 常量均未替换为中文。

---

## 13. 是否修改数据库结构

否。无 schema 变更、无生产 `updb`。

---

## 14. Docker 测试结果

- Compose：`web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.lab.yml`，项目名 `xedc-seo-center`，仅 `127.0.0.1:3307`。
- 只读导出生产库后导入实验室；结束后容器与卷删除。
- 生产端口回查：3306。
- Mock：供应商指 `127.0.0.1:9`，Key 文件指不存在路径；Worker 在发网前因 `no_key` 失败，不是空成功。
- 同内容两次入队同一任务 ID；队列消费后清空。
- `seo-ops-test.php`：OK 0 failed（含 ALT 零 Token、采纳选择等既有保护）。
- Labels 单测：`高 · 页面描述`。
- 实验室 `usage_delta = 1` 来自列表夹具写入的假用量行，不是真实 API。
- 内容 13：修订/标题/发布/审核未变；密钥文件仍在。

---

## 15. 真实浏览器测试结果

Chromium 无头登录实验室站点，22 步全部 PASS。截图与 `results.json`：`docs/tasks/screenshots/xedc-ai-seo-center/`。

已验证：无整页刷新、任务恢复与阶段推进、失败中文原因、列表标签、详情基本信息、部分已采纳位置、评分规则消失、中文枚举、建议统计。

---

## 16. 桌面端与移动端

- 1280：列表分组列对齐；详情信息完整。
- 390：列表卡片带 `data-label`；详情与列表均无横向溢出。

---

## 17. 白色与深色模式

白色主题全量截图；深色截了列表与详情（`list-dark-1280.png`、`detail-dark-1280.png`），使用现有 `--ai-*` / Gin 变量。

---

## 18. 对 ALT、自动翻译、建议采纳、工作流的影响

| 能力 | 影响 |
| --- | --- |
| ALT 零 Token | 未改算法；`seo-ops-test` 仍通过 |
| 自动翻译 | 实验室临时关闭，生产配置未改 |
| 建议采纳 / `#tree` / 人工确认 | 未改 `SeoApplyForm` / `SeoAdvisor` |
| 内容发布与工作流 | 采纳路径未改；展示只读标签 |

---

## 19. 未解决问题及原因

1. 未对真实 DeepSeek 做成功态验收（任务禁止）；成功轮询用实验室阶段夹具模拟。
2. 未故意制造 >180s 挂起，未在浏览器看到「任务超时」句；代码路径仍记失败。
3. 可重试错误的二次执行仍依赖现有 cron；实验室未跑完整 cron 重试。
4. 生产默认修订中本就缺少的「退回修改 / 已拒绝」样例，继续用实验室新建文章验证。
5. `page` 类型配置中文名为「基本页面」，与文案习惯「页面」略有差别；按配置 label 展示，符合「不用机器名」要求。

---

## 完成标准核对

- [x] 开始分析不再不必要整页刷新  
- [x] AI 在后台队列执行  
- [x] 前端自动拉状态并停止轮询  
- [x] 内容 ID 区无工作流/状态噪音  
- [x] 修订输入框已删，系统自动管理修订  
- [x] 详情基本信息完整且中文正确  
- [x] 工作流/状态读配置 label  
- [x] 部分已采纳在建议统计区  
- [x] 列表数字均有字段名；桌面/移动正常  
- [x] 评分规则 UI 移除，总分保留  
- [x] `high · description` → `高 · 页面描述`；可翻译枚举统一中文化  
- [x] 内部机器值与历史数据未改  
- [x] ALT / 翻译 / 采纳 / 工作流保护未破坏  
- [x] Docker + 真实浏览器验收通过  
