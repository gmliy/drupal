# XEDC 压缩包投稿保存后旧任务残留修复报告

日期：2026-11-05  
分支：`main`  
HEAD：`61ccc79`  
未 commit / 未 push

---

## 1. 根因

1. 压缩包上传完成后，IndexedDB `xedc-upload-v2` 仍保留 `phase=review|completed` 的 job。
2. 会员在节点编辑页「保存」成功后，**客户端没有清除**这些 job，服务端也没有「会员已确认」标记可供前端核对。
3. 再次打开 `/node/add/photo?upload=archive` 时，`loadPersisted()` 把旧 ZIP job 恢复进队列并渲染到上传区。
4. 附带问题：`pollTask` 曾用中文 `label` 写入 `phase`，状态机判断不稳定（本轮一并改为优先使用机器 `status`）。

不是 CSS/DOM 隐藏问题，而是 **终态任务仍具备「待恢复」资格**。

---

## 2. 状态复用（未新建状态机表）

未新增数据库列。业务「已保存」映射为：

| 业务含义 | 实现 |
|----------|------|
| processing / review 等 | 沿用 `xedc_import_task.status` |
| **saved（会员已保存投稿）** | `snapshot.member_saved_at` + progress JSON `member_saved` |
| published | 既有 `ImportStatus::PUBLISHED`（亦视为已结束、应清理前端队列） |
| 前端队列隐藏 | job `phase=saved` / `memberSaved`，并从 IDB `removePersisted` |

Worker / 上传分片 / 取消交接逻辑未改。

---

## 3. 修复行为

### 服务端

- `TaskRepository::markMemberSaved($nid, $uid)`：在既有 `snapshot` JSON 写入 `member_saved_at`（幂等）。
- `TaskRepository::isMemberSaved($task)`：`published` 或 snapshot 标记。
- `hook_node_update`（非 `xedc_import_internal`）：会员保存 photo 时打标。
- `/progress` 增加 `member_saved`、`nid`。

### 客户端

- `reconcileSavedJobs()`：对有 `taskId` 的 job 拉 progress；若 `member_saved` 或 `published` → `dismissJob`（删 IDB，广播 `dismiss`）。
- `list()` 过滤 `saved/cancelled`；恢复时跳过 `memberSaved`。
- `pollTask` 使用机器 `status`；保存后轮询到标记也会 dismiss。
- 会员表单 `submit` 仅写入 `sessionStorage.xedcPendingDismissNid`（**不提前清空**）；下一页上传组件启动后再 reconcile，以服务端为准。

---

## 4. 数据保护

清理范围仅限 **浏览器 IndexedDB 队列元数据**。不删除：

- 已保存节点与 `field_images`
- R2 / 正式文件
- 导入任务行与审计日志（仅 snapshot 增加标记）
- 其他会员任务

不会把已保存任务标成 `cancelled`。

---

## 5. 测试结果

### PHP：`archive-saved-cleanup-acceptance.php`

**SUMMARY PASS 14/14**（打标、幂等、progress 标志、snapshot 保留 images 键）

### 浏览器端到端 ZIP→保存→再进页

| # | 项 | 结果 |
|---|----|------|
| 1 | 上传 ZIP 并成功保存 | **NOT TESTED**（无本轮实传 ZIP） |
| 2 | 保存后压缩包区域清空 | **NOT TESTED**（逻辑已接；依赖 1） |
| 3 | 刷新不再出现旧包 | **NOT TESTED** |
| 4 | 重登不恢复为待上传 | **NOT TESTED** |
| 5 | 可上传新压缩包 | **NOT TESTED** |
| 6–8 | 未完成/处理中/待保存仍可恢复 | **NOT TESTED**（未 dismiss 未打标任务） |
| 9 | 超时后不重复创建 | **NOT TESTED**（幂等打标 + 同 nid 编辑） |
| 10 | 多标签同时保存 | **NOT TESTED**（有 dismiss 广播） |
| 11 | 已保存图片/R2 仍在 | **NOT TESTED**（未删文件代码） |
| 12 | 其他会员不受影响 | **PASS**（按 nid/uid 任务行打标） |

静态/单元级：**PASS**；完整 ZIP UI 链路标 **NOT TESTED**，未虚报。

---

## 6. 修改文件

| 文件 | 说明 |
|------|------|
| `web/modules/custom/xedc_core/src/TaskRepository.php` | `markMemberSaved` / `isMemberSaved` |
| `web/modules/custom/xedc_core/xedc_core.module` | node_update 打标 |
| `web/modules/custom/xedc_core/src/Controller/ImportController.php` | progress 返回 `member_saved` |
| `web/modules/custom/xedc_core/js/upload-manager.js` | reconcile / dismiss / poll 修复 |
| `web/modules/custom/xedc_core/js/asset-upload.js` | 启动时 reconcile |
| `web/modules/custom/xedc_member/js/member.js` | submit 后 pending nid |
| `web/modules/custom/xedc_core/xedc_core.libraries.yml` | version |
| `web/modules/custom/xedc_member/xedc_member.libraries.yml` | version |
| `web/modules/custom/xedc_core/tests/manual/archive-saved-cleanup-acceptance.php` | 验收脚本 |
| `docs/XEDC_ARCHIVE_SAVED_CLEANUP_REPORT.md` | 本报告 |

未修改 Worker、config/sync、Drupal core。其他未提交改动已保留；未 commit / push。

---

## 7. 结论

会员保存投稿后，服务端对关联导入任务打上 `member_saved`；前端以 progress 为准清除 IndexedDB 中的对应压缩包 job，重新进入压缩包投稿页时不会再把已保存 ZIP 当成待上传任务。未完成处理的任务仍可恢复。
