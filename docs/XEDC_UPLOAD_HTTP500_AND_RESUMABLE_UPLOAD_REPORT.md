# XEDC 图片与 ZIP/7z 上传 HTTP 500 及断点续传

日期：2026-11-03。站点 https://gitxx.xyz。Drupal 11.4.11。模块 `xedc_core`。页面 `/admin/content/image-imports`。

先读了 `docs/XEDC_ARCHIVE_IMPORT_PERFORMANCE_REPORT.md` 和已有的图片上传专项记录。没有提交或推送 Git，没有改 `config/sync`、Drupal 核心、第三方模块、R2 Bucket、密钥或生产存储模式。测试草稿已删除。测试期间管理员自己上传并进入审核的任务 208、209、213 没有改动。

状态只用：PASS、FIXED、FAIL、NOT TESTED、BLOCKED。

## 结论

| 问题 | 状态 |
| --- | --- |
| HTTP 500 | **FIXED** |
| 普通图片上传 | **FIXED** |
| ZIP 上传 | **FIXED** |
| 7z 上传 | **FIXED** |
| 真正的断点续传 | **FIXED** |
| 刷新页面后从服务器已确认的进度继续 | **PASS** |
| 失败后只重传缺失分块 | **PASS** |
| 200 MiB 实际上传 | **PASS** |

## 1. HTTP 500 的根因

大请求体的 HTTP 500 发生在 PHP 之前。Nginx 工人进程用户是 `www-data`，请求体超过内存缓冲后要写入 `/var/cache/nginx/client_temp`。该目录曾是 `drwx------ nginx:www-data`：属组虽是 `www-data`，模式 700 不给组写权限。错误日志：

```
2026/11/03 16:53:50 [crit] 708#708: *17649 open() "/var/cache/nginx/client_temp/0000000190" failed (13: Permission denied)
request: "POST /admin/content/image-imports/upload/session/31ea97225e9b29798e17b4f9f79d6100/chunk/0"
```

同一类记录出现在 16:53:31 的分块，以及更早的图片和压缩包 POST。响应是 Nginx 的 HTML 500，PHP 没有执行。这不是 `client_max_body_size` 不够；体积超限会是 413。PHP-FPM 已是 `upload_max_filesize=200M`、`post_max_size=220M`、`memory_limit=512M`，本次没有再放宽这些限制。

约 695 字节的 JPEG 不落盘到 `client_temp`，所以小图可以成功，压缩包和 5 MiB 分块失败。`fastcgi_temp` 有同样的属主和模式问题。

处理：把这两个目录设为 `drwxrwx---`，组保持 `www-data`。之后属主一度回到 `nginx`，770 仍让 `www-data` 可写。复查时 `sudo -u www-data touch` 成功，16:53:50 之后的错误日志没有新的 `Permission denied`。没有递归修改 Drupal 目录。

另一类更早的图片 500 是 `DirectoryNotReadyException`（空目标目录，`FileSystem.php`）。当前 `ImageBatchIntake` 已捕获该异常。`AssetUploadController` 的图片和压缩包入口又加了外层捕获，未捕获错误返回 JSON，而不是 Drupal HTML 500。`/retry` 路径里任务号为 `undefined` 的 `TaskAccess` TypeError 已在工作区中按非数字拒绝。

## 2. 修改的文件

自定义模块：

- `src/Upload/UploadSchema.php`
- `src/Upload/UploadSessionManager.php`
- `src/Controller/UploadSessionController.php`
- `src/Controller/AssetUploadController.php`
- `src/Form/ImportListForm.php`
- `js/asset-upload.js`
- `js/progress.js`
- `css/asset-upload.css`
- `xedc_core.routing.yml`
- `xedc_core.services.yml`
- `xedc_core.install`（`xedc_core_update_10015`，可重复执行；当前 schema 为 10015）
- `xedc_core.module`（cron 清理）
- `config/install/xedc_core.settings.yml` 与 `config/schema/xedc_core.schema.yml`
- `tests/manual/resumable-upload-self-test.php`

系统目录（不在 Git 里）：`/var/cache/nginx/client_temp`、`/var/cache/nginx/fastcgi_temp` 的属组和模式。没有改 Nginx 配置文件，没有重载 PHP-FPM。

## 3. 原来的上传机制

旧的 `POST /archive` 和 `POST /image` 把整个文件放在一次请求里。失败后浏览器只能从头再传。队列 `xedc_image_import` 和 `xedc-import-worker.service` 恢复的是导入任务，不是已经传到一半的文件。这两个旧接口仍保留，新页面走分块会话。

## 4. 断点续传架构

浏览器选择文件后向服务器创建 `upload_session`。默认分块 5 MiB（配置 `upload_chunk_bytes`，限制在 1–8 MiB）。页面并发 2。每个分块单独 POST，带 `X-Chunk-Sha256`。服务器用锁 `xedc_upload_chunk:{id}:{index}` 写入，成功后把分块行写入 `xedc_upload_chunk`，已接收字节按分块表合计，不在重复请求上累加。

进度以 `GET` 会话返回的 `received_chunks` 和 `received_bytes` 为准。`localStorage` 只记会话号、文件名、大小和开头 64 KiB 的 SHA256，用来提示恢复，不当作服务器已经收到。对不上时页面要求重新选择原文件。

全部分块到齐后 `complete` 在锁 `xedc_upload_complete:{id}` 内按序号流式合并，核对大小和 SHA256，再按类型进入现有流程：

- 图片：校验真实格式和像素上限，然后 `ImageBatchIntake`、图片优化、R2、内容记录。
- ZIP/7z：`ArchiveManager` 识别，复制到私有收件箱，`ImportProcessor::start`。上传令牌是会话号。相同压缩包 SHA256 返回已有任务。

没有第二套图片队列，没有绕过审核。图片仍受 50 MiB、40,000,000 像素限制；压缩包仍受 200 MiB、解压 1 GiB、2000 个文件限制。

接口都要求登录、CSRF、`import photo archives` 或 `administer xedc image imports`。会话只属于创建它的用户，别人得到 404。管理员和会员的大小上限在服务器按权限选择。没有这两个权限的用户得到 403。

## 5. 会话和分块存储

表 `xedc_upload_session`、`xedc_upload_chunk`。分块文件在 `private://xedc-upload/{uid}/{session}/`，该目录在 `/var/www/drupal/private`，不在 web 根下。`https://gitxx.xyz/xedc-upload/1/secret` 返回 **404**。合并使用临时文件再改名。路径必须落在该用户的上传目录内。

活动会话默认每用户 4 个、全站 12 个，预留磁盘默认 2 GiB。会话有效期默认 86400 秒，最短 3600 秒。当前活动配置里这些键为空，代码使用上述默认值。

## 6. 幂等和完整性

同一分块、同一 SHA256 再次提交：HTTP 200，`duplicate=true`，已接收字节不变。不同 SHA256：409。分块未齐：完成请求 409，不合并。校验失败：422，不创建导入任务，会话可再完成或取消。完成请求加锁；已经有任务时再次完成返回同一个任务号。合并中途异常把会话留在 `uploading` 并保留分块，不把半截文件交给导入。

取消和过期清理只删除 `private://xedc-upload/{uid}/{32位hex}` 下的文件。不删除收件箱、正式媒体和 R2 对象。清理要拿到完成锁，拿不到就跳过。自测里过期清理没有删除收件箱哨兵文件。

浏览器发现的一个上限：缺失分块很多时，循环条件跟着剩余数量一起变小，200 MiB 从 60 MiB 续传时停在 170 MiB，并提示仍有分块未确认。已改为按开始时的缺失数量固定上限。修复后同一次会话只补了剩下的 6 块。

## 7. 图片、ZIP、7z 实测

自测 `resumable-upload-self-test.php`（Drush，不经过浏览器）：23 项全部通过。覆盖中文 JPEG、重复分块、错误校验、重复完成、空文件、错误扩展名、超限、损坏图片不建任务、ZIP 缺块后只补缺失字节、7z 建任务、他人会话 404、无权限 403、会员/管理员限额分开、取消、过期清理。

经 Nginx 的 HTTP（`curl --resolve gitxx.xyz:443:127.0.0.1`）：

| 用例 | 结果 |
| --- | --- |
| 中文 JPEG 695 字节 | 分块 200，重复分块字节仍为 695，完成 200，再次完成同一个任务 |
| 200 MiB 全零、名为 zip | 见第 8 节。完成 422，任务数 0，SHA256 与本地一致 |

真实浏览器是 Headless Chrome 156，打开 `https://gitxx.xyz/admin/content/image-imports`，使用管理员会话。文件由页面里的 `File` / `DataTransfer` 送进现有的 `change` 处理，请求打到生产站点。操作系统文件选择框没有点开：沙箱里的 Chrome 读不到宿主机路径，输入框会得到 0 字节。这不是 PHP 脚本模拟。

| 用例 | HTTP 与结果 | 状态 |
| --- | --- | --- |
| `浏览器风景.jpg` 695 字节 | 分块 200，完成 200。页面：「图片已交给后台」。导入项 success，任务进入 `review` | PASS |
| `续传分块.zip` 5,300,921 字节，两块 | 分块 1 先被测例返回 500，分块 0 只上传一次，分块 1 重试 200，完成 200。页面确认 5.06 MiB / 5.06 MiB。包内 JPEG 导入 success，`pad.bin` 未当图片导入，任务 `review` | PASS |
| `续传分块.7z` 589 字节 | 分块 200，完成 200，包内 JPEG success，任务 `review` | PASS |
| 空文件 | 浏览器直接拒绝：「空文件不能上传。」 | PASS |
| 错误文件冒充未完成会话 | 「这个文件和服务器上的未完成上传对不上。」 | PASS |

测试草稿、对应媒体、文件和任务已删除。任务 208、209、213 是同一时段管理员自己的图片，留在审核里。

匿名访问会话列表：**403**。存储开关复查：公开接管 1，私有接管 0，CDN 1。与本次修改前的生产状态一致。

## 8. 断网恢复后实际重传的字节

200 MiB（209,715,200 字节），分块 5,242,880，共 40 块。文件是全零并命名为 `续传200m.zip`，用来测传输和合并，不让它进入导入或 R2。

经 Nginx 的第一次完整测试：

| 项 | 值 |
| --- | --- |
| 停在约 30% 时浏览器已发送 | 62,914,560 |
| 服务器确认 | 62,914,560 |
| 这一段耗时 | 1.074 秒，55.85 MB/s（本机回环） |
| 同一分块再提交 | HTTP 200，`duplicate=true`，确认字节仍是 62,914,560 |
| 恢复时重新上传 | 146,800,640（剩下的 70%，不是 200 MiB） |
| 恢复耗时 | 2.785 秒，50.26 MB/s |
| 合并 | 0.53 秒 |
| 整次 | 4.764 秒 |
| SHA256 | `72abf2ca8f36943ebe2e49ca3a51d409ca5f0bfcffab6c9d25643c17c32889da`，与本地一致 |
| 完成 | HTTP 422，「无法识别压缩包」，任务数 0 |
| 再次完成 | HTTP 422，任务数仍是 0 |
| 取消 | HTTP 200，私有目录已空 |

浏览器里同一文件：

1. 分块 0–11 各成功一次。分块 12 和 13 各返回模拟的 HTTP 500 三次后停止。页面和刷新后的「未完成的上传」都是服务器已确认 **60.00 MiB / 200.00 MiB**。已成功的分块没有重传。这一段到暂停约 16 秒，页面速度 3.77 MiB/s（含浏览器内 SHA256）。
2. 修复循环上限之前的一次续传把确认数推进到 170.00 MiB 后停下，没有从 0 重来。
3. 修复后刷新页面，列表仍是 **170.00 MiB / 200.00 MiB**。继续上传只发了分块 34、35、36、37、38、39，共 31,457,280 字节。没有分块 0。耗时 5.163 秒，页面速度 4.70 MiB/s，其中包含合并。完成后页面确认 200.00 / 200.00 MiB，HTTP 422，没有导入任务。取消的是这个会话，私有上传目录剩下 8 KB。

5 MiB 级 ZIP 的刷新测试：三次 HTTP 500 后服务器确认 5.00 MiB / 5.06 MiB。刷新后仍是这个数。选错文件被拒绝。选回原文件只上传了分块 1。完成 HTTP 200。该文件与前一个 ZIP 字节相同，完成时按压缩包 SHA256 命中已有任务，没有新建第二个任务。

## 9. 速度和合并

本机回环上传约 50 MB/s。浏览器因为每块要先算 SHA256，200 MiB 页面显示约 3.8–4.7 MiB/s。200 MiB 合并 0.53 秒（HTTP 测试，单独计时）。浏览器最后 30 MiB 的上传加合并合计 5.163 秒，没有单独拆出合并时间。

## 10. 服务器资源

磁盘 `/`：58G，测试结束后可用约 33G，inode 使用约 5%。`/tmp` 可写。私有收件箱可写。PHP-FPM `pm.max_children` 仍是 5，单请求内存上限 512M。分块 5 MiB 流式写入，不合进一个字符串。页面并发 2，服务器对同一会话同时落地的半成品分块有上限。没有提高 FPM 子进程数，也没有提高 PHP 或 Nginx 的体积上限。

200 MiB 测试文件没有变成公开 R2 对象。完成后取消，上传目录已清。

## 11. 安全测试

| 项 | 状态 |
| --- | --- |
| 无上传权限的用户调用接口 | PASS，403（自测） |
| 其他用户读取会话 | PASS，404（自测） |
| 会员和管理员限额分开 | PASS（自测把会员上限临时设为 1000 字节后已恢复为空，代码回到 50 MiB / 200 MiB） |
| 私有分块不能从站点 URL 读取 | PASS，404 |
| 未完成或校验失败不建导入任务 | PASS，200 MiB 假 ZIP 完成两次都是 422，任务数 0 |
| 损坏图片不建任务 | PASS（自测） |
| 完成后进入原导入和审核 | PASS，JPEG、ZIP、7z 的导入项 success，任务状态 `review` |
| R2 开关 | PASS，公开接管仍开，私有接管仍关，CDN 仍开。没有改密钥和 Bucket |

## 12. 尚未验证

| 项 | 状态 |
| --- | --- |
| 人手点击操作系统文件选择框 | NOT TESTED。浏览器测试用的是页面内的 `File` 对象 |
| 合并过程中杀掉 PHP 进程后再恢复 | NOT TESTED。代码会在校验未完成时重建合并文件，这次没有实际杀进程 |
| 两个完成请求同一瞬间竞争 | NOT TESTED。顺序的第二次完成已验证不新建任务，完成锁已加上 |
| 上传到恰好 80% 再刷新 | 浏览器实际停在 30%（60 MiB）和 85%（170 MiB）后刷新并续传。没有单独做 80% 这个整数 |
| 会员账号走完整浏览器上传 | NOT TESTED。会员限额和 403 只在 Drush 自测里验证 |

## 13. 回滚

1. 用 Git 还原本报告第 2 节列出的自定义模块文件。不要 `git reset --hard` 或 `git clean`，工作区里还有本次任务以外的修改。
2. `xedc_upload_session` 和 `xedc_upload_chunk` 可以留着。回滚代码后它们不再被调用。不要删除正式节点、媒体、收件箱或 R2 对象。
3. Nginx 临时目录保持组 `www-data` 可写。改回 `700` 会让大于缓冲的上传再次 HTTP 500。
4. 不需要还原 PHP 或 Nginx 配置，这次没有改它们。

## 直接回答

- HTTP 500 已经解决。根因是 Nginx 临时目录对 `www-data` 工人不可写，不是 PHP 体积上限。
- 图片上传已经解决。浏览器 JPEG 和同一时段的管理员图片都进入了原有审核。
- ZIP 和 7z 上传已经解决。两者都合并、导入并进入审核。
- 已经实现真正的断点续传。进度在数据库和私有分块文件里，不在浏览器内存里。
- 刷新页面后可以继续。刷新后的数字来自服务器。
- 失败后只上传缺失分块。200 MiB 在确认 170 MiB 后续传只发了最后 31,457,280 字节。
- 200 MiB 实际上传通过。经 Nginx 和真实浏览器都跑过；假 ZIP 被拒绝，没有导入任务，会话已取消。
