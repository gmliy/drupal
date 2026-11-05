# XEDC 智能标签管理系统第二阶段：生产安全代码审计报告

审计时间：2026-11-04  
模块：`web/modules/custom/xedc_tags`  
环境：Drupal 11.4.11 / PHP 8.5.12 / MariaDB  

本轮只做独立审计与最小加固，不重做架构，不增加无关功能，未改生产标签数据。

---

## 1. 审计范围

- 标签合并 / 回滚
- 全实体类型标签引用完整性
- 并发与重复提交
- Queue Worker 可靠性与取消竞态
- AI 调用安全（不消耗大量额度）
- 缓存失效
- 权限边界
- 前台 `/tags` 与后台 `/admin/content/tags` 兼容性

---

## 2. 检查文件

| 类别 | 文件 |
|---|---|
| 核心服务 | `TagMerger.php` `TagGuard.php` `TagCatalog.php` `TagTaskManager.php` `TagAiAnalyzer.php` `TagAliasManager.php` `TagAudit.php` `TagRecommender.php` |
| 队列 | `Plugin/QueueWorker/TagScanWorker.php` |
| 表单/权限/路由 | `src/Form/*` `xedc_tags.routing.yml` `xedc_tags.permissions.yml` `xedc_tags.services.yml` `xedc_tags.install` |
| 测试 | `tests/manual/tag-center-self-test.php` `tag-text-test.php` `tag-center-extended-test.php` + 新增 phase2 脚本 |

数据库表：`xedc_tag_audit` `xedc_tag_decision` `xedc_tag_merge` `xedc_tag_alias` `xedc_tag_ai_suggestion` `xedc_tag_task`

---

## 3. 发现的问题

| ID | 问题 | 风险 | 状态 |
|---|---|---|---|
| P1 | 回滚用整字段快照覆盖当前值，会抹掉合并后新增的其他标签 | **高** | 已修复 |
| P2 | 合并缺少跨请求锁，并发/重复提交可能交错改写 | **高** | 已修复 |
| P3 | `TagGuard` 把 `field_categories`/`field_model` 等非 tags 字段标成 migratable（按 tid 查询虽难误伤，但范围不正确） | **中** | 已修复 |
| P4 | Worker 完成后无条件 `update`，可覆盖已取消状态 | **中** | 已修复 |
| P5 | 任务入队存在 TOCTOU；running 任务无超时回收 | **中** | 已修复 |
| P6 | 别名变更未失效 Catalog 缓存 | **中** | 已修复 |
| P7 | AI 调用未做本模块频率限制，也未先走 `xedc_ai.budget` | **中** | 已修复 |
| P8 | 合并创建的 301 在回滚时未清理（仅本合并创建的） | **中** | 已修复 |
| P9 | `TagCatalog` 统计只聚合 `node__field_tags`，与 Guard 扫描范围需明确文档化 | **低** | 已说明（有意设计） |
| P10 | Drupal Lock 同请求可重入；单进程无法模拟真并发 | **信息** | 测试改为异锁持有者模拟 |

未发现：SQL 直改实体引用、AI 输出直接执行、API Key 写入日志/前端。

---

## 4. 问题风险等级摘要

- 高：2（P1、P2）— 均已修
- 中：6（P3–P8）— 均已修
- 低/信息：2（P9、P10）— 文档/测试处理

---

## 5. 修复内容（最小改动）

1. **`TagMerger::restore` 改为手术式回滚**  
   - 恢复快照中的来源标签  
   - 仅当目标标签在合并前已存在时保留  
   - 保留合并后新增的其他标签  
   - 快照升级为 version 2（含 sources/target/entities/redirect_ids）；兼容旧扁平快照  

2. **合并/回滚使用 `lock` 服务**  
   - 锁名：`xedc_tags_merge:{sorted_sources}:{target}`  
   - 持锁后重新 preview，失败不写 Redirect  

3. **`TagGuard` 仅将可指向 `tags` 的字段标为 migratable**  
   - `node.field_tags`：是  
   - `node.field_categories` / `node.field_model` / `taxonomy_term.parent`：否  
   - 新增 `referenceScope()` 供审计与测试  

4. **任务队列**  
   - 入队加锁  
   - `finishIfActive()` 仅在 pending/running 时收尾  
   - `expireStale()` 回收超时 running  
   - Worker 在执行前后检查 cancelled，且不覆盖取消状态  

5. **别名 / 推荐应用** 失效 `TagCatalog` cache tags  

6. **AI**  
   - 调用前检查 `xedc_ai.budget->authorize()`  
   - 3 秒软限流（State）  
   - 仍只记录脱敏 errorCode  

7. **Redirect**  
   - 合并成功后记录 `redirect_ids`  
   - 回滚只删除这些 ID，不碰无关 Redirect  

---

## 6. 新增测试

`web/modules/custom/xedc_tags/tests/manual/tag-phase2-security-audit-test.php`

覆盖：引用范围、10/20 合并后新增 3 再回滚、多值去重、并发锁、任务取消竞态、AI 无效 JSON/未知 TID/限流、缓存失效、权限、301 回滚清理、跨词汇表拒绝。

---

## 7. 测试通过与失败数量

| 套件 | 结果 |
|---|---|
| `tag-center-self-test.php` | **PASS**（21 项） |
| `tag-text-test.php` | **PASS**（8 项） |
| `tag-center-extended-test.php` | **PASS**（约 40 项） |
| `tag-phase2-security-audit-test.php` | **PASS**（约 40 项；首次因同请求锁重入失败 1 项，已改测试后全过） |

本轮失败：0（最终）  
未做：真实双 HTTP 进程压测、真实 AI 付费全量调用、浏览器多端视觉回归。

---

## 8. 标签引用完整性结果

扫描到的 taxonomy 引用字段：

| 字段 | 可含 tags | 合并可迁移 |
|---|---|---|
| `node.field_tags`（photo/video/article） | 是 | **是** |
| `node.field_categories` | 否 | 否 |
| `node.field_model` | 否 | 否 |
| `taxonomy_term.parent` | 仅同词汇结构 | 否（blocker 语义） |
| `taxonomy_term.field_alias_of`（models） | 否 | 否 |
| `taxonomy_term.field_tag_category`（tags→photo_category） | 否 | 否 |

额外 blocker：菜单链接、指向术语的 Redirect。

**统计（TagCatalog）** 仍只聚合 `node__field_tags`（仪表盘按内容类型设计）。  
**合并/回滚（TagGuard）** 覆盖所有可指向 tags 的可迁移字段。  
当前生产站点无其它自定义实体持有 tags；若未来新增，Guard 会按字段定义自动纳入，但 Catalog 统计需另行扩展。

无法自动识别、需人工知晓的关联：Views 参数、Twig 硬编码 tid、外部系统缓存的标签名——不在 Entity 引用图内，系统不声称覆盖。

---

## 9. 合并和回滚验证结果

场景：A 关联 10 + BOTH 1；B 关联 20 + BOTH 1 → 合并 A→B → 新增 3 个仅 B 节点 → 其中 1 个原 A 节点再加标签 C → 回滚。

| 断言 | 结果 |
|---|---|
| 合并后 A=0，B=31 | 通过 |
| 多值字段去重为单 B | 通过 |
| 回滚后 A=11 | 通过 |
| B 保留原 20 + BOTH + 新 3（=24） | 通过 |
| 新 3 个 B 节点未被改写 | 通过 |
| 后加的 C 与恢复的 A 共存 | 通过 |
| 跨词汇表拒绝 | 通过 |
| 失败路径不残留 301；成功 301 随回滚删除 | 通过 |

---

## 10. 并发安全结果

- 异持有者 semaphore 占用时，合并返回「同一组合并正在执行」  
- 同类型任务重复入队被拒绝  
- 取消后 `finishIfActive` 不会把状态改回 completed  
- 同请求 Drupal Lock 可重入属平台行为；跨请求由 semaphore 隔离  

---

## 11. 队列可靠性结果

- 任务有唯一自增 ID；同类型 active 互斥 + 入队锁  
- 取消与 Worker 收尾竞态：以 DB 条件更新为准，取消优先  
- `expireStale(3600)` 回收卡死的 running  
- 分析类 Worker 只读，取消不会留下半写入内容数据  
- 队列项重复投递对终态任务为 no-op  

说明：当前扫描任务本身不做内容写；合并不走该队列。

---

## 12. AI 安全结果

- 复用 `xedc_ai` 模型与 SecretStore  
- 预算 `authorize` 前置；3 秒软限流  
- JSON / Term ID / 非法操作（如“删除”）校验  
- 建议只入库待人工确认，从不自动执行  
- 本轮未做真实付费批量调用；限流与校验路径已测  

---

## 13. 缓存一致性结果

合并、回滚、别名变更、推荐应用、队列扫描结束均 `invalidateTags(TagCatalog::cacheTags())`。  
前台 `/tags` HTTP 200；Catalog 在失效后计数立即刷新（测试断言通过）。

---

## 14. 数据安全结果

| 项 | 值 |
|---|---|
| 审计前/后标签数 | 34 / 34 |
| Term ID | `3,5,7,9,14,15,17,19,22,25,26,30,31,36,37,38,41,42,43,46,47,49,50,51,52,54,56,57,71,74,75,76,79,82`（不变） |
| `node__field_tags` 引用行 | 81（不变） |
| 临时测试术语/节点 | 已清理 |
| 生产内容 | 未批量修改 |
| Git | 未自动提交 |

Watchdog 近期错误与本模块无关（会员 AccessCheck、既有上传/会话问题等）。

---

## 15. 未解决问题

1. Catalog 仪表盘仍只统计节点 `field_tags`；若未来有自定义实体挂 tags，需扩展统计（合并已可发现字段）。  
2. 未做双浏览器真实并发压测。  
3. 未做真实 AI 超时网络故障注入（限流与校验已覆盖；超时依赖 `xedc_ai` client timeout）。  
4. 手术式回滚在「合并前无 B、合并后用户又明确只想保留 B」的极端人工编辑场景，会按回滚意图去掉合并引入的 B——符合回滚语义，但需操作者理解。  

---

## 16. 修改文件清单

### 修改

- `src/TagMerger.php` — 锁、手术式回滚、redirect_ids 快照  
- `src/TagGuard.php` — tags 词汇过滤 + `referenceScope()`  
- `src/TagTaskManager.php` — 入队锁、`finishIfActive`、`expireStale`  
- `src/Plugin/QueueWorker/TagScanWorker.php` — 取消竞态  
- `src/TagAliasManager.php` — 缓存失效  
- `src/TagAiAnalyzer.php` — 预算与限流  
- `src/TagRecommender.php` — 应用后缓存失效  
- `xedc_tags.services.yml` — 注入 `lock` / cache invalidator  

### 新增

- `tests/manual/tag-phase2-security-audit-test.php`  
- `docs/tasks/XEDC_TAGS_PHASE2_SECURITY_AUDIT.md`（本文件）  

### 未改

- 生产 Term / 内容关联  
- `config/sync`  
- 无关自定义模块  

---

## 维护命令

```bash
./vendor/bin/drush php:script web/modules/custom/xedc_tags/tests/manual/tag-phase2-security-audit-test.php
./vendor/bin/drush php:script web/modules/custom/xedc_tags/tests/manual/tag-center-self-test.php
drush queue:run xedc_tags_scan
```

结论：在完成上述加固后，现有智能标签管理系统在合并回滚、并发互斥、队列取消、AI 非执行、缓存失效方面达到可上线的安全基线；生产标签 Term ID 与引用数量经复核未变化。
