# XEDC 上传取消、分片清理与 Worker 交接原子性修复

日期：2026-11-04。站点目录 `/var/www/drupal`。Drupal 11.4.11。

未 commit，未 push。未改 Drupal Core、第三方模块、`config/sync`、Nginx、PHP-FPM、R2/CDN。未新建 Worker，未提高并发，未放宽体积限制。未使用破坏性 git 命令。未覆盖无关未提交修改（会员/标签等既有改动保持原样）。

状态标记：PASS / FAIL / FIXED / NOT TESTED。

## 1. 原始问题

### 上传一半取消
用户在分片进行中点击取消时，前端可能已 abort XHR，但服务端仍可能接受后续分块；取消成功后临时分片目录清理与状态更新顺序不安全。

### 分片写入与取消竞态
`receive()` 先检查状态再写临时文件，再在事务中 `commitChunk`。取消若只改状态不挡住提交窗口，可能留下孤儿目录；取消若先删目录再改状态，complete 可能仍继续。

### complete 与 cancel 竞态
旧实现依赖 Drupal lock + 先读后改。缺少「条件 UPDATE 受影响行数 = 1」作为权威状态转换权，极端并发下存在双成功或卡死风险。

### Worker 交接后误取消
短文件可能在点击取消前已 `complete` 并得到 `taskId`。上传取消不得删除已交接导入任务或 Worker 源文件。

### 临时文件清理失败
旧 cancel 在清理失败时直接 500，且未把「取消成功」与「清理完成」分开；失败后缺少可重试的持久化状态。

### IndexedDB 与服务端不一致
旧前端在服务端结果未知时仍删除 IndexedDB 并返回本地成功，刷新后无法核对，也可能误报「取消成功」。

## 2. 修复后状态机

上传 Session（`xedc_upload_session.status`）：

```
open / uploading / failed
        │
        ├─ claimCancel 成功 ──► cancelled ──► cleanup_status: pending → completed|failed
        │                         │
        │                         └─ 拒绝 receive / complete；不创建 task；Worker 不可领取
        │
        └─ claimComplete 成功 ─► merging ─► handoff ─► completed(+task_id)
                                      │
                                      └─ 此后 cancel → handed_off（409）；上传取消永久失效
```

Worker 导入任务状态保持原有 `queued / processing / …`，与 Session 生命周期分离。上传 cancel 从不修改已交接 task。

`cleanup_status`：`none | pending | completed | failed`（独立于 cancel 是否已成立）。

## 3. 原子性实现

- 事务 + `SELECT … FOR UPDATE` 锁定 Session 行。
- **cancel 权**：`UPDATE … SET status='cancelled' WHERE status IN ('open','uploading','failed') AND task_id=0`，要求受影响行数 = 1。
- **complete 权**：`UPDATE … SET status='merging' WHERE status IN ('open','uploading','failed') AND task_id=0`，要求受影响行数 = 1。
- 同时竞争时只有一方 UPDATE 成功；另一方根据当前状态返回 `cancelled` / `handed_off` / `completing` / `already_cancelled` / `already_completed`。
- 另保留同名 Drupal lock `xedc_upload_complete:{session}`，避免合并读盘与清理并发删同一目录。
- `commitChunk` 在事务内再次校验状态仅允许 `open|uploading`；取消后的迟到分块得到 409，并触发一次安全清理。
- Worker：仅 complete 交接后创建 task；cancel 在 `task_id>0` / `completed` / 已有 upload_token 任务时返回 `handed_off`，不删任务、不删交接文件。

## 4. 文件清理

- 临时分片：`private://xedc-upload/{uid}/{session_id}/c-#####`
- 写入中临时：`p-#####-****`
- 合并临时：`merged.bin`
- 取消成功后顺序：原子改 `cancelled` → 删 `xedc_upload_chunk` → `deleteUploadTree`（仅 Session 专属目录，防路径穿越/符号链接）→ 写 `cleanup_status`。
- 清理失败：Session 保持 `cancelled`，`cleanup_status=failed` + `cleanup_error`；不可恢复上传，不可入 Worker。
- 重试：`purgeExpired()` 先跑 `retryPendingCleanups()`；过期扫描遇到 `cancelled` 也只做清理、不改回 uploading。
- 严禁清理：其他 Session、inbox/`xedc-import`、已交接 `final_uri`、R2 正式对象、正式 Drupal 文件。

## 5. 前端行为

- 立即 `abort` 该任务 XHR，停止调度与自动重试；广播 `cancel_pending`。
- 无 Session：直接本地清理 IndexedDB + 墓碑。
- 有 Session：进入 `cancel_pending`，**等待服务端结果**；仅 `cancelled` / `already_cancelled` 才删 IndexedDB 并广播 `cancelled`。
- `handed_off` / `already_completed`：撤销墓碑，恢复任务跟踪并轮询导入进度。
- `completing`：显示交接中，不宣称取消成功。
- 网络异常：保留 `cancel_pending` 与恢复信息，允许重试取消；不以本地推断成功。
- 多标签：`cancel_pending` 停传；确认后的 `cancelled` 才删本地；服务端状态优先。

## 6. 修改文件清单

| 文件 | 原因 |
| --- | --- |
| `web/modules/custom/xedc_core/src/Upload/UploadSessionManager.php` | 原子 claimCancel/claimComplete、清理与重试、outcome |
| `web/modules/custom/xedc_core/src/Upload/UploadSchema.php` | `cleanup_status` / `cleanup_error` + 索引 |
| `web/modules/custom/xedc_core/xedc_core.install` | `xedc_core_update_10018` |
| `web/modules/custom/xedc_core/js/upload-manager.js` | `cancel_pending`、服务端裁决、墓碑修正 |
| `web/modules/custom/xedc_core/js/upload-panel.js` | 取消待确认展示 |
| `web/modules/custom/xedc_core/js/asset-upload.js` | 取消结果文案 |
| `web/modules/custom/xedc_core/tests/manual/upload-cancel-handoff-self-test.php` | 专项自测（新增） |
| `web/modules/custom/xedc_core/tests/manual/upload-race-one-shot.php` | 并发进程助手（新增） |

## 7. 数据库变更

- 新增字段：`xedc_upload_session.cleanup_status`（varchar 16，默认 `none`）
- 新增字段：`xedc_upload_session.cleanup_error`（varchar 255，默认空）
- 新增索引：`cleanup` (`status`, `cleanup_status`)
- 未新增 Outbox 表
- 未改唯一约束（Session PK / chunk 复合主键保持）
- 更新钩子：`xedc_core_update_10018`（已在本环境执行成功）
- 部署：跑 `drush updatedb` + `drush cr`；无需手工 SQL

## 8. 测试结果

| 项 | 结果 |
| --- | --- |
| PHP 语法 | PASS |
| JS 语法（manager/panel/asset-upload） | PASS |
| `drush updatedb` 10018 | PASS |
| `drush cr` | PASS |
| 分片上传中取消 + 目录/分块清理 | PASS |
| 重复 cancel 幂等 | PASS |
| 取消后拒绝 receive/complete | PASS |
| complete 后 cancel → handed_off | PASS |
| 重复 complete 不双任务 | PASS |
| cancel 先胜后 complete 失败 | PASS |
| **真实并发（两独立 drush 进程同时 cancel+complete）** | PASS |
| 清理失败记录 + purge 重试 | PASS |
| 权限隔离（他用户 cancel 404） | PASS |
| 断点续传自测 23 | PASS |
| 去重自测 12 | PASS |
| 浏览器手工取消 UX | NOT TESTED |
| 多标签页真人双开 | NOT TESTED |
| 移动端 / 深色模式 | NOT TESTED |
| 快速发布页面点取消 | NOT TESTED（服务端路径共用） |
| 人为 chmod 拒绝删除 | NOT TESTED（用失败标记 + 重建残留文件模拟重试 PASS） |

说明：真实并发使用两个独立 `drush php:script` 进程（独立 PDO），不是单线程先后调用。

## 9. Git 状态

- 分支：`main`
- HEAD：`93da5e3df281a1737ae4a8e805b7eec419b85582`
- 本修复相关未提交修改：见上文「修改文件清单」+ 两份新增测试脚本 + 本报告
- 工作区另有无关未提交改动（member/tags/notify 等），未触碰
- 是否 commit：否
- 是否 push：否

## 10. 残余风险

1. Drupal lock 与 DB 条件更新双轨：DB 为权威；若 lock 后端异常，条件 UPDATE 仍裁决胜负，但合并读盘与清理仍可能短暂争用同一目录。
2. 图片 handoff 的 `upload_token` 关联在部分路径可能为空，取消保护以 `task_id` + `status=completed` 为主；并发测试已按 `task_id` 校验。
3. 前端 `cancel_pending` 在长期离线时需用户重试取消；自动后台对账仅在页面可见时 `syncSessions`（且刻意不自动恢复 cancel_pending 上传）。
4. 浏览器/多标签真人验证、快速发布 UI 点击路径未在本轮做界面实测。
5. `purgeExpired` 会扫描历史 completed 残留目录（既有行为）；本轮未改其范围策略。

## 验收对照

1. 上传一半取消立即停后续上传 — FIXED（前端 abort + 服务端拒绝）
2. 分片写入与取消安全裁决 — FIXED（commitChunk FOR UPDATE）
3. 取消后拒收新分块 — PASS
4. 临时分片清理 — PASS
5. 清理失败可记录重试 — PASS
6. 取消 Session 不进 Worker — PASS
7. cancel/complete 原子转换 — PASS
8. 并发仅一胜 — PASS（双进程）
9. complete 后不能上传取消删任务 — PASS
10. Worker 领取后不受上传取消影响 — PASS（handed_off）
11. 重复 complete 不双任务 — PASS
12. 重复 cancel 安全 — PASS
13. 网络异常不误报取消成功 — FIXED（代码路径；浏览器 NOT TESTED）
14. IndexedDB 与服务端可恢复一致 — FIXED（cancel_pending）
15. 多标签不绕过服务端 — FIXED（广播 + 服务端权威；真人双开 NOT TESTED）
16. 图片/快速发布/ZIP/7z 服务端共用 Session — PASS（续传回归含 ZIP/7z）
17. Worker 单实例不变 — PASS（未改服务）
18. 优化/WebP/R2/ALT — 未改相关逻辑；续传/去重回归 PASS
