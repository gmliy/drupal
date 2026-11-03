# 图片上传、ZIP/7z 导入、异步处理与图集 SEO 验收

日期：2026-11-03

站点：https://gitxx.xyz

没有提交 Git，没有改 `config/sync`，没有改生产 R2 配置，没有切换存储模式，没有发布测试内容。测试节点标题均以 `XEDC上传验收` 开头，验收后已删除，对应公开 WebP 也已删除。

截图：`docs/image-upload-browser/`

## 1. ZIP/7z 没有真正上传的根因

导入页 `/admin/content/image-imports` 原来没有上传控件。「新建导入」进入图片内容表单，压缩包走 Drupal `managed_file`。选择文件后页面会先显示文件名，这不代表服务器已经收到文件。

真正的上传是把整张图片内容表单连同压缩包用 AJAX 发到 `POST /node/add/photo?element_parents=xedc_import/archive&ajax_form=1`。请求体一旦解析不出 `form_id`，Drupal 就当成损坏的 POST。2026-11-03 的访问日志里，同一功能出现过两种结果：

- HeadlessChrome 得到 HTTP 200，响应只有约 1.7 KB，符合 AJAX「文件过大」提示，不是已保存的压缩包。
- 手机 Safari 两次返回 HTTP 400，响应体为 0。私有收件箱当时没有新文件，数据库里也没有对应的新任务。

另外，PHP `max_file_uploads` 仍是 20。媒体库一次选择超过 20 张图片时，请求在 PHP 启动阶段就被拒绝，还没进入 Drupal。这是「几十张图片上传失败」的另一条原因，不是 ZIP 解压器缺失。

服务器上 `7z`、`7zr`、`unzip` 和 PHP `zip` 扩展都在，`www-data` 可以执行 `7z`。这次没有再安装解压工具。

## 2. 上传链路修复

新增只接收一个文件的接口，不再把整张节点表单放进同一次上传：

- `POST /admin/content/image-imports/archive`
- `POST /admin/content/image-imports/image`
- `POST /admin/content/image-imports/draft`
- `POST /admin/content/image-imports/close`

压缩包流程现在区分：请求里没有文件、PHP 上传错误、已收到但未保存、已写入私有目录、已创建任务、已解压、已优化、已关联。某一阶段成功不会被写成整个流程成功。

保存位置是 `private://xedc-import/inbox`，随后由原有 `ImportProcessor` 挪到该任务自己的私有目录。校验内容是实际文件大小、文件头（ZIP 或 7z）和 SHA-256。同一个 `upload_token` 或同一个压缩包哈希不会再建任务。

图片内容表单上的 `managed_file` 已去掉，避免继续走会失败的整表 AJAX。服务器收件箱里已有的压缩包仍可选择。新建内容如果走这条上传，会先保存未发布草稿，并带上 `xedc_keep_unpublished`，避免员工账号的「新建即发布」把还没处理完的图集公开。

`max_file_uploads` 已在 `/etc/php/8.5/fpm/conf.d/99-xedc-uploads.ini` 改为 100，并已重载 PHP-FPM。当前 FPM 是 `upload_max_filesize=200M`、`post_max_size=220M`、`max_file_uploads=100`。应用层压缩包上限仍是 `max_archive_bytes=209715200`。

## 3. ZIP 和 7z 解压

仍使用现有 `ArchiveManager`：只在该任务的私有目录解压，拒绝绝对路径、`..` 和符号链接逃逸，过滤非图片。7z 调用已安装的 `/usr/bin/7z`。

Drush 脚本 `web/modules/custom/xedc_core/tests/manual/asset-upload-self-test.php` 结果是 **27/27 PASS**，其中：

- 中文文件名 ZIP：2 张不同图片导入，文本被跳过，任务进入 `review`，节点未发布。
- 中文文件名 7z：1 张图片导入，同样未发布。
- 空包、损坏包、无图片包：失败，不标记成功。
- 超过临时调低的大小限制：已收到但未保存，测完已把上限恢复为 209715200。
- 相同 token 和相同 SHA-256：不重复建任务。
- PHP `UPLOAD_ERR_PARTIAL`：不显示成功。这是上传错误码，不是浏览器里拔网线。

浏览器里另一次真实上传：

- `中文相册.7z` 581 字节进入任务 155，处理后 `review`，节点 575 未发布，公开文件 `public://2026-11/n575-001.webp` 66 字节。
- `中文图集.zip` 738 字节进入任务 157。页面显示文件名、738 B 和上传速度。响应结束后原先没有开始解压，原因是 `ImportKick` 没有打上 Drupal 的 `needs_destruction` 标签，内核不会在响应后调用它。补上标签并重建缓存后，对任务 157 调用内核 `terminate`，状态变为 `review`，1 张图片，节点未发布。之后的新上传会在响应结束时自动开始处理；cron 仍会继续未完成的队列。

## 4. 统一图片处理

公开图片仍走现有优化器和 `public://`。当前公开包装器是 `XedcPublicS3fsStream`，没有在代码里写死 bucket，也没有拼接 `public/public/`。

- ZIP、7z 和这次的普通图片批次都进入原来的 `xedc_import_task` / `xedc_image_import` 队列，由 `ImagePipeline` 优化后再写入公开存储。
- 快速发布不再在浏览器里先压成 WebP。原文件上传后，服务器优化器必须先给出结果，才复制到 `public://gallery/...`。优化服务不可用或拒绝时，接口返回错误，不把原图公开。
- 正文图片、视频封面和媒体库仍走原来的文件插入优化。这次没有重写这些入口。
- 会员资料只有头像字段，没有图集投稿表单。

## 5. 异步上传

图片内容可以先保存草稿，再分批上传。浏览器并发是压缩包 1 个、图片 2 个。图片批次在 `xedc_import_task.source=images` 且 `stage=open` 时只接收文件，关闭批次或超过约 180 秒没有新文件后才收尾关联。页面刷新后，已经创建的任务编号留在 `sessionStorage`，可以继续显示进度。还没传到服务器的文件不能靠队列恢复，页面会这样说明。

脚本里 2 张图片先返回「已保存、尚未完成」，关闭批次后进入 `review`，节点未发布。浏览器里 2 张图片的请求在离开页面前只完成了 1 张，这 1 张进入了审核；中断的那张没有被记成成功。

## 6. 任务状态和重试

沿用 `xedc_import_task` 和 `xedc_import_item`。进度 JSON 现在带阶段、成功数、失败数和每张图片的状态、原始字节、错误，不返回私有路径。

相同上传标识不会新增行。失败任务原有的重试路由还在。浏览器上的「重试」按钮没有点击，记为未测。

## 7. 原图大小

`xedc_core_update_10013` 已执行。新增字段包括任务的 `archive_bytes`、`archive_sha256`、`upload_token`，导入项的 `original_bytes`，以及 `xedc_image_file` 的原始和优化后字节、MIME、扩展名、状态、处理时间。历史行保持 0，0 表示未知，没有按压缩率回填。

原始字节在优化前写入。优化后的 `bytes` 不再拿来冒充原图大小。

## 8. 优化后大小

详情和编辑页使用 `GalleryMetrics`。浏览器打开未发布的 7z 图集时，页面显示：

- 图片 1 张
- 原图 695 B
- 优化后 66 B
- 节省 629 B
- 压缩率 90.5%
- 状态已完成

脚本中的两张图片合计原图 1424 字节、优化后 162 字节。没有原始字节的图显示「原图大小未知」，测试断言 `original_bytes === NULL`。

文件统计中心 `/admin/reports/xedc-files` 增加了「图片优化」卡片：已知原图数量、未知数量、原图容量、优化后容量、节省、ZIP/7z/普通图片任务数和失败记录。没有新的一级菜单。

## 9. ALT 规则

`AltManager::format()` 现在是 `标题_第N张图`。标题会去掉控制字符和引号。封面不在图集里时使用 `标题_封面`。图集里的同一张图不改成封面文案。

脚本确认 ZIP 为 `标题_第1张图` 和 `标题_第2张图`，7z 为 `标题_第1张图`。浏览器中的 7z 图集 ALT 是 `XEDC上传验收 浏览器7z_第1张图`。

标题或图片顺序变化时，只刷新标记为自动的 ALT。快速发布在创建媒体时写入同一规则，并记为自动。

## 10. 人工 ALT

人工修改会把 `xedc_image_alt.source` 记为 `manual`。脚本把 ALT 改成「手工说明不要覆盖」后修改标题，再次读取仍是这句。

相同文件被复用时，保留第一次的 ALT，不用后来的标题覆盖。浏览器 ZIP 和普通图片是同一张图，第二篇内容复用了第一篇的媒体和 ALT。

## 11. 图集 SEO

没有安装新的 SEO 模块，没有改 URL，没有批量覆盖历史 Metatag。图集页原来的 JSON-LD 继续使用每张图自己的 ALT。自动 ALT 按序号不同，不会把整组写成同一句。

历史内容缺少 SEO 时，这次没有提供执行按钮，也没有跑回填。

## 12. 上传入口

| 入口 | 结果 |
| --- | --- |
| 图片导入页 ZIP/7z 和普通图片 | 已接到新接口，浏览器和脚本都测过 |
| 图片内容表单 | 去掉会失败的整表 AJAX，改用同一套上传 |
| 快速发布 | 改为上传原图并由服务器优化；本轮没有在浏览器里点快速发布 |
| 媒体库、正文图片 | 仍走原有文件优化，没有改编辑器 |
| 视频封面 | 保存时只给空的或自动的封面 ALT 填 `标题_封面`；本轮没有新建视频 |
| 会员头像 | 仅在 ALT 为空时写成 `昵称_头像`；没有图集投稿入口 |
| 文章正文里的图片 | 不改已有 HTML，避免误伤翻译和人工 ALT |

## 13. 后台菜单

没有新增一级菜单。

- 菜单「图片批量发布」改为「图片导入」，仍在内容下面，路径仍是 `/admin/content/image-imports`。页面标题也改为「图片导入」。
- 上传、任务、进度和原导入记录都在这一页。
- 原图和优化统计加在现有文件统计中心。
- 图片是否处理完、原图大小和 ALT，显示在图片编辑页和详情页。编辑页同时是现有审核状态的操作处。`/admin/content/moderated` 能打开，没有另做一套审核，也没有改那张列表的列。

## 14. 新增和修改的文件

新增：

- `web/modules/custom/xedc_core/src/Controller/AssetUploadController.php`
- `web/modules/custom/xedc_core/src/ImageBatchIntake.php`
- `web/modules/custom/xedc_core/src/GalleryMetrics.php`
- `web/modules/custom/xedc_core/src/ImportKick.php`
- `web/modules/custom/xedc_core/js/asset-upload.js`
- `web/modules/custom/xedc_core/css/asset-upload.css`
- `web/modules/custom/xedc_core/tests/manual/asset-upload-self-test.php`

修改：

- `xedc_core.install`、`xedc_core.services.yml`、`xedc_core.routing.yml`、`xedc_core.libraries.yml`、`xedc_core.module`、`xedc_core.links.menu.yml`
- `ImportProcessor.php`、`ImportStatus.php`、`TaskRepository.php`、`AltManager.php`
- `ImportListForm.php`、`ImportController.php`、`FileStatsController.php`
- `templates/xedc-core-files.html.twig`
- `xedc_home.module`（只跳过明确标记为暂不公开的新建图集）
- `xedc_quick` 的上传控制器和 `js/app.js`
- 主题 `xedc.theme` 和 `node--photo--full.html.twig`
- `/etc/php/8.5/fpm/conf.d/99-xedc-uploads.ini`

## 15. 数据库

已执行 `xedc_core_update_10013`。只加字段和 `upload_token` 索引。历史原图大小保持未知。没有手工改表，没有批量改 ALT 或 SEO。

## 16. 自动化测试

相关 PHP 文件 `php -l` 通过。

`drush php:script web/modules/custom/xedc_core/tests/manual/asset-upload-self-test.php`：**ALL PASS 27**。

没有跑 PHPUnit。`drush updatedb` 和 `drush cr` 已执行。

## 17. 浏览器验收

使用无头 Chromium，以管理员会话打开后台。文件选择对话框受 snap 限制不能直接塞本地路径，因此由页面里的上传脚本接收真实的 `File` 对象并发送，这就是线上的那套 JavaScript。

| 项目 | 结果 |
| --- | --- |
| 图片导入页桌面 | PASS。上传区和原任务列表都在 |
| 图片导入页 390 宽 | PASS。ZIP 和普通图片上下排列 |
| 上传卡片暗色 | PASS。只强制了这张卡片的暗色，不是 Gin 的完整暗色开关 |
| ZIP 上传 | PASS。显示 `中文图集.zip · 738 B · 等待处理 100%` 和速度。响应后补上销毁标签，任务进入待审核 |
| 7z 上传 | PASS。任务进入待审核，详情页和编辑页数字如上 |
| 普通图片 | PASS 1 张。第二张在离开页面时还没传完，没有被记成成功 |
| 文件统计中心 | PASS。页面正文包含「图片优化」 |
| 内容审核列表 | PASS 只表示页面打开。处理状态看的是图片编辑页，不是这张列表 |
| 图片编辑页 | PASS。显示张数、原图、优化后、节省、压缩率和待审核 |
| 图片详情页 | PASS（管理员查看未发布内容）。匿名访问同一地址 HTTP 403 |
| 未公开 | PASS。测试节点 `status=0`，审核状态为草稿或待审核 |

## 18. 未测或阻塞

- 浏览器里一次选择 10 张、30 张。脚本只到 2 张。
- 真实断网。只测了 PHP 的部分上传错误码。
- 浏览器里点击单张重试。
- 快速发布页面的点击。
- 新建视频封面和会员头像的页面操作。
- 文章正文图片的自动 ALT。
- `/admin/content/moderated` 的列没有加处理状态。
- Gin 完整暗色模式没有从用户菜单切换，只验证了上传卡片的暗色样式。
- 历史 SEO 回填没有做，也没有预览页。

## 19. 已知限制

- 员工账号新建普通内容仍会按原规则直接发布。只有标记了 `xedc_keep_unpublished` 的导入草稿会留下来待审核。
- 相同文件会复用已有媒体，ALT 保持第一次的内容，不会改成新标题。
- 图片批次在浏览器还没传完时离开，服务器只处理已经收完的文件。
- 压缩包上限 200 MB。单张图片沿用现有 `max_image_bytes`，默认 50 MB。
- 响应后的自动处理每轮约 8 秒，其余交给队列和 cron。
- 正文里的图片 ALT 仍由编辑器里的人填写。

## 20. 后续建议

- 在内容审核列表给图片行加处理状态之前，先确认现有视图字段不会把未发布图片提前公开。
- 快速发布需要再做一次浏览器点击：原图上传、服务器优化、草稿和离开页面后的任务。
- 如果要补历史原图大小，只能从还存在的私有原件读取，不能用现在的公开文件大小估算。执行前应先出预览数量。
