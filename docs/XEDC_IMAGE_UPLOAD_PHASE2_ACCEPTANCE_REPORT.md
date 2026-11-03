# XEDC 图片系统第二阶段验收

日期：2026-11-03。站点 https://gitxx.xyz。Drupal 11.4.11。没有提交或推送 Git，没有改 `config/sync`，没有改 Drupal 核心或第三方模块，没有切换生产存储，没有改 R2 配置，没有删除正式内容。

当前后台主题是 `xedc_admin`，不是 Gin。深色模式按这个主题自己的开关验收。

## 1. 第一阶段实现核对

第一阶段服务仍在使用，没有另写一套上传系统：`AssetUploadController`、`ImageBatchIntake`、`GalleryMetrics`、`ImportKick`、`ImportProcessor`、`AltManager`、`ImportStatus`、`TaskRepository`，以及 `xedc_quick` 的原图上传。

图片导入仍在 `/admin/content/image-imports`。审核列表仍是 Views `moderated_content`（路径 `/admin/content/moderated`），没有新建审核中心。文件统计仍在 `/admin/reports/xedc-files`。

本轮复跑：

| 脚本 | 结果 |
| --- | --- |
| `web/modules/custom/xedc_core/tests/manual/asset-upload-self-test.php` | PASS 27/27 |
| `web/modules/custom/xedc_core/tests/manual/image-phase2-self-test.php` | PASS 19/19 |

工作区在开始时就有未提交修改。本轮没有 `git reset`、`git checkout` 或 `git clean`。

## 2. 快速发布实际操作

状态：**PASS**。浏览器打开 `/admin/quick-gallery`，填入标题「XEDC上传验收第二阶段 快速发布」，选择一张 JPEG 后点击「上传并创建草稿」。

页面结果：`处理完成。1 张，原图 41.02 KiB，优化后 4.10 KiB。未发布 / draft。`

数据库：节点 589，`published=0`，审核状态 `draft`，1 张媒体。公开 URI `public://gallery/2026-11/xedcshangchuanyanshoudierjieduan-kuaisufabu-001.webp`，MIME `image/webp`，优化后 4196 字节，原图记录 42009 字节。URI 以 `public://` 开头，没有 `public/public/`。ALT 为 `XEDC上传验收第二阶段 快速发布_第1张图`。

原图没有直接公开。优化后的 WebP 比原图小。

## 3. 10 张图片上传

状态：**PASS**。浏览器在图片导入页一次选择 10 张不同 JPEG（`batch-01.jpg` 到 `batch-10.jpg`，约 20–122 KiB）。

任务 166：`review`，成功 10，失败 0。节点 592 未发布，审核状态 `needs_review`，正好 10 张。ALT 从「十张_第1张图」到「第10张图」。每张都在 `public://2026-11/`，对象删除前可读。详情页显示 10 张、原图 395.28 KiB、优化后 34.79 KiB、节省 360.49 KiB、压缩率 91.2%。

墙钟时间 70.1 秒。离开页面时任务状态是 `queued`。

## 4. 30 张图片上传

状态：**PASS**。10 张成功且内存仍充足后，浏览器一次选择 30 张不同 JPEG（`wide-01.jpg` 到 `wide-30.jpg`，小图约 20 KiB，中图约 110–120 KiB，大图约 260–280 KiB）。没有用重复文件凑数。并发仍是普通图片 2、压缩包 1，没有提高。

任务 167：`review`，成功 30，失败 0。节点 593 未发布。原图合计 2,253,972 字节，优化后合计 227,554 字节。ALT 按 1 到 30。审核列表显示「已完成 · 30张，原图 2.15 MiB，优化后 222.22 KiB，缺 ALT 0」。

墙钟时间 185.2 秒。最低可用内存约 3.79 GiB，负载峰值 0.98。没有内存耗尽，也没有停掉 Matomo 或数据库。

## 5. 上传耗时和性能

实测机器不是 1.9 GiB 的描述：2 核 / 4 线程，`MemTotal` 7,917,120 KiB（约 7.55 GiB）。开始时 `MemAvailable` 约 4.3 GiB。

| 批次 | 张数 | 墙钟 | 原图字节 | 优化后字节 | 最低可用内存 | 负载峰值 |
| --- | --- | --- | --- | --- | --- | --- |
| 十张 | 10 | 70.1 秒 | 详情页 395.28 KiB | 34.79 KiB | 3,956 MiB | 1.13 |
| 三十张 | 30 | 185.2 秒 | 2,253,972 | 227,554 | 3,880 MiB | 0.98 |

PHP-FPM 日志在本轮没有新增 Fatal 或内存耗尽。11:25 有一条既有的 `pm.max_children`（5）警告，不是这次上传打出来的。

限制保持原值：FPM `upload_max_filesize` 200M，`post_max_size` 220M，`max_file_uploads` 100，Nginx `client_max_body_size` 220m，应用 `max_image_bytes` 50 MiB，快速发布 25 MiB。单次请求仍是一个文件。cron 仍是每 15 分钟。`ImportKick` 继续在响应结束后处理。

30 张测试期间，10 张和 30 张任务在离开页面后若仍未结束，调用的是现有 `xedc_core.processor`，没有新开一套队列。

## 6. 页面刷新

| 场景 | 状态 | 证据 |
| --- | --- | --- |
| 只选择、还没上传 | PASS | 快速发布选中 1 张后刷新，变回「已选 0 张」，没有显示处理完成 |
| 正在上传时刷新 | PASS | 刷新批次选出 3 张。服务器只留下 2 条记录。最终节点 594 是 2 张，不是 3 张。没传到服务器的那张没有被写成完成 |
| 已上传、后台还在排队时离开 | PASS | 10 张离开时是 `queued`，之后是 `review` 且 10 张都在 |
| 单独卡在「正在优化」或「正在写 R2」时刷新 | NOT TESTED | 这两步在同一次后台处理里，没有单独截到这个瞬间再刷新 |

导入页原文写明：浏览器还没传完的文件，刷新后不能自动恢复。断网时选择图片，页面提示网络中断，刷新后没有创建节点。

## 7. 关闭页面后的后台处理

状态：**PASS**。10 张全部交给后台后导航离开。离开时任务 166 为 `queued`，随后变为 `review`，10 张媒体都在，内容未发布。30 张同样在离开页面后到达 `review`。

浏览器里还没上传成功的文件不能靠队列恢复。这一点页面和测试都分开了。

## 8. 失败重试

状态：**PASS**。

快速发布断网：图片上显示「网络中断，服务器没有确认收到文件。」和「重试」。恢复网络并点击重试后，再点创建，结果是 `处理完成。1 张，原图 256.69 KiB，优化后 19.65 KiB。未发布 / draft。` URI 为 `public://gallery/2026-11/xedcshangchuanyanshoudierjieduan-wangluohuifu-001.webp`。

第一次重试点击时，Nginx 无法写入 `/var/cache/nginx/client_temp`（属主是 `nginx`，工作进程是 `www-data`），上传返回 HTTP 500。已把 `client_temp` 和 `fastcgi_temp` 的属主改回 `www-data`。这不是 R2 配置变更。改完后上面的重试才成功。

导入重试：用测试开关让下一张处理失败。浏览器打开「重试这个导入任务？」并点击确认。任务 170 从 `failed` 变为 `review`，1 张图片，尝试次数 2，未发布。确认提交后由 `ImportKick` 继续处理，没有再手工跑处理器。更早的任务 169 也是页面确认后尝试次数从 1 到 2，最终 1 张，没有重复媒体。

人工 ALT：脚本在重试成功后把 ALT 改成「手工说明不要覆盖」，再改标题，ALT 保持不变。第一阶段脚本复跑同样通过。

## 9. 内容审核列表

状态：**PASS**。`/admin/content/moderated` 仍是原来的 Views。`hook_views_pre_view` 加上图片处理列和筛选，没有导出配置，没有新菜单。

图片行显示「已完成 · N张」以及原图、优化后、缺 ALT。文章和视频不在「图片处理完成」结果里。实测类型列只有「美图」。没有图片、也没有进行中任务的内容显示「—」，不会假装失败。

## 10. 审核过滤器

状态：**PASS**。筛选参与查询，不是只改外观。

| 筛选 | 结果 |
| --- | --- |
| 图片处理完成 | 三十张、十张、快速发布都在；类型只有美图 |
| 图片处理未完成 | 排队中的「重试」显示「处理中」 |
| 图片处理失败 | 已完成的十张和三十张不在结果里 |
| 缺失 ALT | 临时清空一张测试 ALT 后能筛到「十张」，随后把 ALT 写回 |

脚本另测：失败任务在失败筛选中，成功后退出失败筛选并进入完成筛选。

## 11. 发布保护

状态：**PASS**。只拦有未完成或失败导入任务的图片内容。

节点 595 的任务还在排队时，编辑页把审核状态选成「已发布」并保存，节点仍是草稿、未发布。批量动作在失败任务上返回「图片还在处理或有失败图片，未发布。」，不保存。任务进入 `review` 后，这个导入拦截不再挡住它。没有改员工账号对普通新内容的发布规则。

## 12. 桌面、移动端和深色模式

| 项 | 状态 |
| --- | --- |
| 快速发布桌面 | PASS，见 `quick-success-desktop.png` |
| 快速发布手机 | PASS，横向溢出 0 |
| 审核列表桌面 | PASS，图片处理列没有把表格撑出横向滚动 |
| 图片导入手机 | PASS |
| 当前后台深色模式 | PASS。点击 `[data-xedc-admin-theme]` 后 `data-theme=dark`，背景 `rgb(24, 24, 27)`。快速发布、审核列表和手机导入页都截了图 |
| Gin 的 `gin--dark-mode` | NOT TESTED。当前管理主题是 `xedc_admin`，页面不加载 Gin 的暗色开关 |

没有新增一级菜单，没有第二套审核页，也没有第二套图片任务页。

## 13. 修改文件

本轮在既有模块上改动，不包含核心和 `config/sync`：

- `web/modules/custom/xedc_quick/src/Controller/QuickController.php`
- `web/modules/custom/xedc_quick/js/app.js`
- `web/modules/custom/xedc_quick/css/app.css`
- `web/modules/custom/xedc_core/js/asset-upload.js`
- `web/modules/custom/xedc_core/css/asset-upload.css`
- `web/modules/custom/xedc_core/src/GalleryMetrics.php`
- `web/modules/custom/xedc_core/src/Plugin/views/field/GalleryStatus.php`
- `web/modules/custom/xedc_core/src/Plugin/views/filter/GalleryState.php`
- `web/modules/custom/xedc_core/xedc_core.module`
- `web/modules/custom/xedc_core/src/ImportProcessor.php`
- `web/modules/custom/xedc_core/src/ImagePipeline.php`
- `web/modules/custom/xedc_core/src/Form/TaskActionForm.php`
- `web/modules/custom/xedc_core/src/Access/TaskAccess.php`
- `web/modules/custom/xedc_core/src/Controller/FileStatsController.php`
- `web/modules/custom/xedc_core/templates/xedc-core-files.html.twig`
- `web/modules/custom/xedc_moderation/src/Plugin/Action/SetModerationState.php`
- `web/modules/custom/xedc_core/tests/manual/image-phase2-self-test.php`

另外把 Nginx 临时目录 `/var/cache/nginx/client_temp` 和 `fastcgi_temp` 的属主改回 `www-data`，否则大请求体会 HTTP 500。

## 14. 自动化测试

- 第一阶段脚本复跑：**PASS 27/27**
- 第二阶段脚本：**PASS 19/19**。覆盖快速发布公开文件、损坏图、不支持格式、超限、模拟公开存储失败且不新建文件、重复媒体、失败时拒绝发布、失败筛选、重试后单张媒体且尝试次数增加、人工 ALT、完成筛选和审核字段。

模拟公开存储失败会在日志里留下一条预期错误，状态开关随后删除。

## 15. 浏览器截图

目录：`docs/image-upload-phase2/`。

包括 `quick-success-desktop.png`、`quick-selected-desktop.png`、`quick-mobile.png`、`quick-offline-error.png`、`quick-retry-success.png`、`quick-dark.png`、`moderated-complete-desktop.png`、`moderated-pending.png`、`moderated-missing-alt.png`、`moderated-dark.png`、`import-mobile.png`、`import-mobile-dark.png`、`import-browser-failed.png`、`retry-confirm.png`、`photo-detail.png`、`file-stats.png`。

`file-stats.png` 是节省计算修正前的页面，当时「节省」为 0 B，因为优化后合计把没有原图记录的文件也算进去了。修正后只比较有原图记录的文件。修正后的汇总由 `GalleryMetrics::site()` 读出，本轮没有再截一张新的统计图。

## 16. 未完成项目和阻塞

- Gin 暗色类名：**NOT TESTED**。站点实际用的是 `xedc_admin`。
- 单独在「正在优化」和「正在写 R2」这两个瞬间刷新：**NOT TESTED**。
- 30 张没有因为资源停止。测到的机器内存约 7.55 GiB，不是任务描述里的 1.9 GiB。
- 没有做真实的生产 R2 断连。公开写入失败只用状态开关模拟，测完已删除。

## 17. 测试数据清理

状态：**PASS**。只删除标题以「XEDC上传验收第二阶段」开头的节点。共 12 个节点、47 个媒体、47 个公开文件、任务 166–170。删除后这些 URI 不再存在。没有保留下来的被引用文件。剩余测试节点 0。

文件删除前核对了 `node__field_images`、`node__field_cover_image` 和 `file_usage`。没有引用才删。

## 18. 生产 R2 和正式内容

状态：**PASS**。清理前后 `xedc_core.storage`、`s3fs.settings`、`gin.settings` 的配置哈希一致。没有切换存储模式，没有改 Bucket。失败开关 `xedc_import_fail_next` 和 `xedc_quick_fail_public_copy` 都是空的。正式内容没有用这个标题前缀，不在删除范围内。
