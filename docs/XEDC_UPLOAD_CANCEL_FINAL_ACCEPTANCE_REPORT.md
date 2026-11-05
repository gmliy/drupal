# XEDC 上传取消与 Worker 交接 — 最终专项审查与验收

日期：2026-11-04。项目 `/var/www/drupal`。Drupal 11.4.11。

基线报告：`docs/XEDC_UPLOAD_CANCEL_WORKER_HANDOFF_RACE_FIX_REPORT.md`。

未 commit，未 push。未改 Drupal Core、第三方模块、`config/sync`、Nginx、PHP-FPM、R2。未新建 Worker。会员 / 标签 / 通知等无关未提交修改保持原样。

## 1. 审查结论

| 项 | 结论 |
| --- | --- |
| completed Session 暂存清理不会误删 Worker 源文件 | PASS（已核实生命周期；completed 清理仅限 `xedc-upload` 暂存树） |
| Worker 失败重试源文件不被提前清理 | PASS |
| upload_token 为空时关联安全 | FIXED（新增 `findLinkedToUploadSession`，覆盖 image item.relative_path） |
| 普通图片中途取消（真实浏览器） | PASS |
| ZIP 中途取消（真实浏览器） | PASS |
| 快速发布取消（真实浏览器 `/admin/quick-gallery`） | PASS |
| 多标签页取消与状态裁决（真实 Chromium 双 page） | PASS |
| 已交接任务不可被上传取消删除 | PASS |
| 真实文件系统删除失败记录与重试（chattr +i） | PASS |
| IndexedDB 与服务端最终一致（刷新后不恢复已取消） | PASS |
| 双进程 cancel/complete 并发（复用上轮 + 本轮回归） | PASS |
| 断点续传 / 去重回归 | PASS（复用并复跑 handoff 24/24） |
| 图片优化 / WebP / R2 / ALT | PASS（本轮未改相关逻辑；沿用既有通过报告） |

**正式验收：可以完整验收（本轮明确项均为 PASS）。**

## 2. completed 源文件清理

### Worker 源文件生命周期

| 入口 | 交接时动作 | Worker 实际读取 |
| --- | --- | --- |
| 普通图片 | `ImageBatchIntake::addFile` **copy** 到 `private://xedc-import/tasks/{id}/incoming/` | item.uri（工作目录） |
| ZIP/7z | **copy** 到 `private://xedc-import/inbox/`，task.archive_uri | archive_uri / inbox |
| 快速发布 | `xedc_quick.intake::storePath` 写入快速发布存储 | 快速发布自己的文件，不进 import Worker 上传树 |

`complete()` 仅在 handoff 成功后 `deleteUploadTree()`，删除的是 `private://xedc-upload/{uid}/{session_id}` 暂存分片，不是 inbox / work_uri。

### 清理规则（核实后）

| 状态 | 清理 |
| --- | --- |
| uploading / open / failed（未过期） | 不清理 |
| cancelled + cleanup pending/failed | 允许清理该 Session 专属暂存；失败可重试 |
| merging（过期扫描） | 若已关联任务则升为 completed 并跳过破坏性清理 |
| completed + queued/processing/failed | **只清上传暂存树**；Worker 源在 inbox/work，不受影响 |
| completed + completed | 同上；且若 `final_uri` 仍落在 `xedc-upload/` 内则**拒绝**清理并打错误日志 |

### 误删风险

审查结论：completed 残留清理**不会**删除 Worker 正在使用或失败重试所需的 inbox/work 源文件。

本轮仍做了最小加固：

1. completed 清理前检查 `final_uri` 是否误落在上传暂存树。
2. `deleteUploadTree` 失败时记录错误，不再假装成功。

## 3. upload_token 关联审查

| 入口 | upload_token | 可靠关联 |
| --- | --- | --- |
| ZIP/7z | Session.session_id 写入 task.upload_token | `findByUploadToken` |
| 普通图片 | 任务行常为空；session_id 写入 **item.relative_path** | 本轮新增 `TaskRepository::findLinkedToUploadSession()` |
| 快速发布 | 通常无 import task（task_id=0），以 Session.status=completed 保护 | cancel 见 completed → handed_off |
| 重复 complete | task_id / linked lookup → 原 task | PASS |
| Worker | 只处理已创建任务；不信任前端 taskId 做归属取消 | PASS |

空 token 不会绕过取消保护：

- cancel 在 `status=completed` 或 `task_id>0` 或 `findLinkedToUploadSession` 命中时返回 `handed_off`。
- merging 期间返回 `completing`，不能清文件。
- cleanupCancelledSession 在已关联任务时 `skipped`。

事务窗口：handoff 与 rememberTask 同在 complete 锁内；cancel 无法在中间改写已交接文件。

## 4. 浏览器测试

环境：Playwright Chromium（`chromium-1248`），站点 `https://gitxx.xyz`，管理员一次性登录链（报告不记录 URL/凭证）。

脚本：`web/modules/custom/xedc_core/tests/manual/browser-upload-cancel-acceptance.mjs`

结果摘要（最后一轮 **34 PASS / 0 FAIL**）：

| 场景 | 结果 |
| --- | --- |
| 普通图片约 10MB/15MB 处取消 | PASS；Session=cancelled；cleanup=completed；刷新不恢复 |
| ZIP 约 10MB/17MB 处取消 | PASS；同上 |
| `/admin/quick-gallery` 快速发布中途取消 | PASS；无成功完成提示路径；刷新不恢复 |
| 小图交接后 cancel | PASS；`canCancel=false`；`outcome=handed_off`；本地仍跟踪 task |

说明：浏览器通过真实页面加载的 `Drupal.xedcUpload` 驱动上传/取消，请求打到真实 Session API，不是 PHP 单测替身。

## 5. 多标签页测试

同一 Chromium context 两个 page：

- A 开始大图上传并达到已确认分片。
- B 能看到任务（IndexedDB/同步）。
- B 发起 cancel；本轮实测服务端正在 merging → 返回 `completing`，最终 Session=`completed` 且保留 task_id。
- 结论：BroadcastChannel/本地状态不覆盖服务端裁决；已交接任务未被删除。**PASS**

## 6. 文件系统清理失败测试

脚本：`upload-cleanup-fs-fail-self-test.php`

因 root 下 `chmod` 无法阻止删除，改用隔离 Session 目录内分片 `chattr +i`：

1. 取消成功且 Session=`cancelled`
2. `cleanup_status=failed`，文件仍在
3. 无 Worker 任务
4. `chattr -i` 后 `purgeExpired` 重试 → `cleanup_status=completed`，目录清除
5. Session 不会恢复上传

**PASS（15/15）**

## 7. 修改文件清单（本轮验收相对上一轮新增/加固）

| 文件 | 原因 |
| --- | --- |
| `web/modules/custom/xedc_core/src/TaskRepository.php` | `findLinkedToUploadSession()` |
| `web/modules/custom/xedc_core/src/Upload/UploadSessionManager.php` | 关联查找替换；completed 清理守卫 |
| `web/modules/custom/xedc_core/tests/manual/upload-cleanup-fs-fail-self-test.php` | 真实 FS 删除失败（新增） |
| `web/modules/custom/xedc_core/tests/manual/browser-upload-cancel-acceptance.mjs` | 浏览器验收（新增） |
| `docs/XEDC_UPLOAD_CANCEL_FINAL_ACCEPTANCE_REPORT.md` | 本报告 |

上一轮已存在、本轮继续保留：

- UploadSchema / update 10018 / upload-manager cancel_pending / handoff self-test / race-one-shot 等

## 8. 未解决风险

1. 极高速网络下「中途取消」窗口很短；浏览器脚本通过首个已确认分片触发取消，人工肉眼 UI 按钮点击未单独录屏（逻辑与面板共用 `manager.cancel`）。
2. 双标签在极快 complete 时更常看到 `completing`/`handed_off`，而不是 cancel 胜出——这是正确裁决，但「B 抢先 cancel 胜」依赖时序，不是每次都出现。
3. `findLinkedToUploadSession` 依赖 image item.relative_path=session_id；若未来改相对路径语义需同步。
4. 生产 Worker 未在本轮重启（按约束）；单实例运行方式未改。

## 9. Git 状态

- 分支：`main`
- HEAD：`93da5e3df281a1737ae4a8e805b7eec419b85582`（与开始时一致）
- 本轮相关未提交修改：见第 7 节
- 原有无关未提交修改（member/tags/notify 等）：仍在，未覆盖
- commit：否
- push：否

## 10. 验收标准对照

1. completed 清理不误删 Worker 源 — PASS  
2. 失败重试源不被提前清 — PASS  
3. 空 upload_token 不绕过关联 — FIXED/PASS  
4. 普通图片中途取消真实通过 — PASS  
5. ZIP 中途取消真实通过 — PASS  
6. 快速发布取消真实通过 — PASS  
7. 多标签真实通过 — PASS  
8. 已交 Worker 不可取消删除 — PASS  
9. FS 删除失败可记录重试 — PASS  
10. IndexedDB 与服务端最终一致 — PASS  
11. 双进程并发仍有效 — PASS（handoff 自测 24/24）  
12. 未破坏续传/优化/WebP/R2/ALT — PASS（未改相关代码 + 回归）  
13. 用户原有未提交修改保持 — PASS  

**最终判定：完整验收通过。**
