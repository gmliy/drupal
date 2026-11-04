# XEDC 上传系统执行进度

日期：2026-11-04。主任务：`docs/tasks/XEDC_UPLOAD_SYSTEM_COMPREHENSIVE_TASK.md`。

状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。代码阅读不算浏览器 PASS。没有提交，没有推送。

## 阶段

| 阶段 | 状态 | 说明 |
| --- | --- | --- |
| 1 生产安全检查 | PASS | 工作区在 `main`。没有改 Nginx、PHP、R2、`config/sync`、核心或第三方模块。 |
| 2 HTTP 500 | FIXED | 已知原因是 Nginx 临时目录权限。本次访问日志没有新的 `Permission denied`。没有加大上传限制。本次没有再用大文件重现 500。 |
| 3 分块与断点续传 | PASS | `resumable-upload-self-test.php` 23 项通过。缺分块不能完成，补齐时只传缺失分块。 |
| 4 重复记录 | FIXED | 同一个 `client_upload_id` 只保留一条会话。浏览器一次选择只有一行。 |
| 5 SHA-256 与 R2 复用 | FIXED | 复用文件实体并新建媒体。对象不存在不算重复。删除一篇后，另一篇仍能读到同一文件。 |
| 6 容量单位 | PASS | `512 B`、`1.00 KiB`、`1.00 MiB`、`25.63 MiB`、`1.00 GiB`。上传页提示为 `200.00 MiB`。 |
| 7 中文状态 | PASS | 未知值显示「未知状态」。浏览器看到「排队等待处理」「文件已存在，已复用」「正在校验」。 |
| 8 上传中心 UI | FIXED | 使用现有 `xedc_admin` 变量。桌面和 390 宽手机已看过。系统文件选择框未测。 |
| 9 性能 | NOT TESTED | 没有新建 worker，没有改并发。只记录了现有进程内存，没有做大文件计时。 |
| 10 权限与安全 | PASS | 其他用户读取会话返回 404。无上传权限返回 403。分块目录在私有路径。 |
| 11 浏览器验收 | 部分 PASS | 见下方。操作系统文件对话框、7z、中断后续传、快速发布页面没有在浏览器里点过。 |
| 12 报告 | PASS | 本文件和 `docs/XEDC_UPLOAD_SYSTEM_COMPREHENSIVE_REPAIR_REPORT.md`。 |

## 已完成

- 数据库更新 `xedc_core_update_10016` 已执行。增加 `xedc_upload_session.client_upload_id`、`reimport`、索引 `uid_client` 和表 `xedc_blob_claim`。没有改历史文件内容。
- 上传前按 SHA-256 查询。健康对象才复用。客户端不能提交 fid。
- 相同图片：新的媒体，同一个文件。快速发布的重复断言已改成这个结果，自测通过。
- 相同压缩包：不再上传、不再解压、不再建第二个任务。
- 另一个用户的成功压缩包：能复用健康图片时，给当前用户新建任务和媒体；不能复用时继续上传。
- 上传中心、图片内容表单和快速发布共用中文状态与二进制容量。
- 浏览器验收产生的草稿、媒体、文件、会话、任务和两条测试 claim 已删除。

## 修改文件

- `web/modules/custom/xedc_core/`：上传会话、指纹、导入、进度、优化页容量、上传脚本和样式、schema、路由、服务。
- `web/modules/custom/xedc_quick/`：`QuickIntake.php`、快速发布路由、脚本和深色样式。
- `web/modules/custom/xedc_core/tests/manual/upload-dedup-self-test.php`
- `web/modules/custom/xedc_core/tests/manual/image-phase2-self-test.php`
- `web/modules/custom/xedc_core/tests/src/Unit/StorageSizeFormatterTest.php`
- 报告见 `docs/`。

## 测试

| 测试 | 结果 |
| --- | --- |
| PHP 语法 | PASS |
| 去重自测 12 项 | PASS |
| 断点续传自测 23 项 | PASS |
| 图片第二阶段自测 19 项 | PASS。重复图片媒体 849 与 850，文件同为 1169。 |
| 浏览器上传 733 B 图片 | PASS。一行，状态「排队等待处理」。 |
| 浏览器刷新已完成上传 | PASS。队列没有第二条。已完成会话不留在未完成列表。 |
| 浏览器再次选择已入库图片 | PASS。「文件已存在，已复用」，没有新建上传会话。 |
| 浏览器再次选择同一 ZIP | PASS。同上。 |
| 390 宽与深色背景 | PASS。背景 `rgb(32, 32, 36)`，没有横向溢出。 |
| 删除一篇后文件仍在 | PASS。随后测试数据已删除。 |
| 对象缺失不算重复 | PASS。探测行已删除。 |

## 尚未解决

- 操作系统弹出的文件选择框：NOT TESTED。浏览器验收用的是页面内构造的 `File`。
- 浏览器里的中断、刷新后只补缺失分块、7z、快速发布页面、平板宽度、任务详情点击：NOT TESTED。
- 两个用户同时上传同一新文件：NOT TESTED。已有 claim 锁和主键，没有做并行压测。
- 大文件耗时：NOT TESTED。不把 733 B 的约 7 KiB/s 当成吞吐。
- PHPUnit 未安装，容量断言改在 Drush 自测里执行。

## 下一阶段

可安全完成的开发项已经做完。剩下的是需要人工点系统文件框、两个真实账号并行，以及大文件计时的验收。

## 生产风险

- `10016` 已经写到当前数据库，只增加字段和空表。
- 缓存已重建，新的上传脚本已经生效。
- 重复图片不再共用同一个媒体，避免两篇内容绑在同一条 ALT 上。文件仍复用，所以不会为同一字节再写一份对象。
- 没有改 R2、Nginx、PHP 限制或导入 worker 并发。worker 仍是单个活动进程，内存约 47.8 MiB。
