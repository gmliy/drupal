# XEDC 全站图片自动优化实施报告

日期：2026-11-03。状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。没有实际跑过的项不标 PASS。

## 架构结论

运行中的公开包装器是 `XedcPublicS3fsStream`。活动存储配置是 Provider `silly`、Cloudflare R2、Bucket `dclili`、公开前缀 `public/`、私有前缀 `private/`。`public://xedc-opt-test-…/probe.webp` 的对象 Key 是 `public/xedc-opt-test-…/probe.webp`，没有 `public/public/`。CDN 基址仍是 `https://cdn.gitxx.xyz`。私有文件仍走本地。图片样式仍由现有本地样式链路生成。`XedcPublicS3fsStream.php`、`ImageStyleFileSystem.php`、`ImageStyleRouteSubscriber.php` 和 `xedc_core.services.yml` 里的样式服务这次没有改。

服务器是 4 CPU、约 7.6 GB 内存。PHP-FPM `memory_limit` 512M，`upload_max_filesize` 200M，`post_max_size` 220M。GD 2.3.3 可以编码 JPEG、PNG、WebP、GIF、AVIF。没有 ImageMagick，没有新装系统包，没有新装贡献模块。

统一优化器是 `ImageOptimizer`。小图在请求里同步处理，超过同步阈值的公开图片进入队列 `xedc_image_optimize`。媒体库先把文件放在 `temporary://`，再由 `file.repository` 的装饰器在移入 `public://` 之前优化。直接保存到 `public://` 的新文件走 `hook_file_insert` 和 `hook_file_update`。ZIP/7z 仍走原来的 `ImagePipeline::officialImage()`，内部调用同一个优化器。视频、私有文件、`styles/`、`css/`、`js/`、播放副本和互动测试目录不会进入优化。历史图片没有回扫。

AVIF 编码器可用，默认关闭。默认允许 WebP，并在合格候选里选更小的文件。没有更小的合格结果时保留原编码。不主动缩小宽高。EXIF 方向 6 会交换宽高，那是方向纠正，实测 30×60 变成 60×30。

为了让新的 `hook_file_update` 和 `file.repository` 装饰器生效，执行了一次 `drush cache:rebuild`。没有改 PHP-FPM、Nginx 或系统环境变量。

## A. 实现概况

新增：

- `web/modules/custom/xedc_core/src/Image/OptimizingFileRepository.php`
- `web/modules/custom/xedc_core/tests/manual/image-optimize-self-test.php`

已有并在这次补齐的类：

- `ImageOptimizer.php`
- `ImageOptimizeApplier.php`
- `ImageOptimizeRepository.php`
- `ImageOptimizeResult.php`
- `ImageOptimizeWorker.php`
- `ImageOptimizeForm.php`
- `css/image-optimize.css`

修改：

- `xedc_core.module`：`hook_file_insert`、`hook_file_update`、`hook_entity_presave`，发布前拦截仍在排队、处理中或失败的新图片。
- `xedc_core.services.yml`：优化服务，以及装饰 `file.repository`。
- `xedc_core.install`：表 `xedc_image_optimize` 已存在，没有新增更新钩子，没有扫历史文件。
- `xedc_core.routing.yml`、`xedc_core.permissions.yml`、`xedc_core.links.menu.yml`、`xedc_core.libraries.yml`：后台页和权限沿用已有定义。
- `config/schema/xedc_core.schema.yml`：补了 `xedc_core.image_optimize` 的结构说明。没有改 `config/sync`。
- `ImagePipeline.php`：正式图继续调用统一优化器。
- `xedc_quick` 的 `QuickController::upload()`：小图先优化，再复制进 `public://gallery/`。

服务：`xedc_core.image_optimizer`、`xedc_core.image_optimize_repository`、`xedc_core.image_optimize`。`file.repository` 由 `OptimizingFileRepository` 装饰，实际移动仍交给原来的仓储。

配置对象：`xedc_core.image_optimize`，只写活动配置。默认 JPEG 84、WebP 82、AVIF 70，允许 WebP，不允许 AVIF，自动选格式，清理元数据，同步上限 3,145,728 字节，单文件上限 20,971,520 字节，最大像素 12,000,000，超时 20 秒，重试 2 次，队列并发 1。测试里曾把同步上限临时改成 200 字节，结束后恢复为 3,145,728。

后台：`/admin/config/media/image-optimization`。权限 `administer xedc image optimization`。匿名请求返回 403。Chromium 以一次性登录打开后能看到「图片优化」「优化设置」「xedc-opt-summary」和「还没有优化记录」。重试和取消走 POST。

队列：`xedc_image_optimize`。超过同步上限的新公开图片先排队。并发为 1 时用同一把锁，避免多个进程同时解码。内容若要发布且引用的图片仍在排队、处理中或失败，会被压回草稿。

测试：没有 PHPUnit、PHPStan、PHPCS。验证用 `tests/manual/image-optimize-self-test.php` 和一次快速发布控制器调用。

## B. 上传入口

| 入口 | 代码 | 接入 | R2 | 结果 |
| --- | --- | --- | --- | --- |
| 直接保存公开图片 | `xedc_core_file_insert()` / `xedc_core_file_update()` → `ImageOptimizeApplier::accept()` | 是 | 是 | PASS。隔离 JPEG 保存后变成 `public://xedc-opt-test-t6495194bb398/direct-t6495194bb398.webp`，Key `public/xedc-opt-test-t6495194bb398/direct-t6495194bb398.webp` |
| 媒体库上传 | `OptimizingFileRepository::move()`，临时文件移入公开目录前优化 | 是 | 是 | PASS。`temporary://` 图片移动后为 `public://xedc-opt-test-…/move-….webp`，状态 success。浏览器里打开媒体库并点选文件 NOT TESTED |
| 会员投稿 photo/article/video | 节点表单使用 `media_library_widget`，上传走上面的媒体库移动 | 是 | 公开图片会 | 代码路径 PASS。浏览器投稿 NOT TESTED |
| 后台创建/编辑 photo、article、video | 封面和正文图片都是媒体库字段：`field_cover_image`、`field_images`、`field_cover`、`field_video_cover` | 是 | 公开图片会 | 与媒体库是同一条代码，移动测试 PASS。浏览器里新建或替换 NOT TESTED |
| 文章/页面正文里的媒体 | 仍是媒体库或已有媒体引用 | 已有媒体不重新压缩 | 不重复上传 | 再次保存已优化文件时 URI 不变。PASS。浏览器 NOT TESTED |
| 页面内容本身 | `page` 没有独立图片字段 | 无单独入口 | 否 | 没有发现绕过优化器的页面图片字段 |
| 会员头像 | `xedc_member` `ProfileForm` 用 `file_save_upload()` 写到 `public://pictures/`，保存时走 `file_insert` | 是 | 会 | 与直接公开保存是同一条代码。没有单独提交头像表单。NOT TESTED |
| 后台媒体实体表单 | 图片字段上传位置就是 `public://YYYY-MM`，走 `file_insert` | 是 | 会 | 直接公开保存 PASS。浏览器 NOT TESTED |
| 快速发布 | `xedc_quick` `QuickController::upload()` | 是 | 是 | PASS。控制器写入 `public://gallery/2026-11/qf5a0b8908-001.webp`，Key `public/gallery/2026-11/qf5a0b8908-001.webp`，原 JPEG Key 不存在。测完媒体、文件和对象已删。浏览器点击 NOT TESTED |
| ZIP 导入 | `ImagePipeline::officialImage()`，解压仍是 `ArchiveManager::extractZip()` | 是 | 正式入库时会 | 两张图解压并逐张优化 PASS。新建草稿节点的完整导入队列 NOT TESTED |
| 7z 导入 | 同一 `ImagePipeline`，`ArchiveManager::extractSevenZip()` | 是 | 正式入库时会 | `/usr/bin/7z` 解压两张图并优化 PASS。完整导入队列 NOT TESTED |
| 视频文件、私有文件、样式目录 | `consider()` 只接受公开或即将进入公开目录的图片 | 否 | 否 | PASS。私有文本和 `.mp4` 没有审计行 |
| 历史图片 | 不扫描、不迁移 | 否 | 保持原样 | PASS。测试清理后表为 0 行 |

已经优化过的文件按 fid、结果 SHA256、体积和 MIME 识别。选择已有媒体不会新建文件。同一个 fid 的第二次保存不会改 URI。

## C. 格式测试

样本是 GD 生成的小图，不是会员相册原图。

| 样本 | 原始 | 结果 | 节省 | 输出 | 尺寸 | 透明 | 动画 | 解码 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| JPEG 120×80 | 954 | 100 | 854 | WebP | 保持 | 无 | 无 | PASS，约 2 ms |
| JPEG 64×48 公开上传 | 隔离上传前 | 84 | 更小 | WebP | 保持 | 无 | 无 | PASS |
| PNG 40×30 | 121 | 80 | 41 | WebP | 保持 | 源不透明 | 无 | PASS |
| 透明 PNG 32×32 | 135 | 134 | 1 | PNG | 保持 | 未转成 JPEG | 无 | PASS |
| WebP 24×24 | 78 | 78 | 0 | WebP | 保持 | 无 | 无 | PASS，第二次 optimized 且没有变大 |
| 两个图形控制块的 GIF | 原字节 | 原字节 | 0 | GIF | 保持 | 无 | 两个控制块仍在 | PASS。没有用播放器核对帧时长 |
| 安全 SVG | 原字节 | 原字节 | 0 | SVG | 非位图 | 不适用 | 不适用 | PASS，kept |
| 含 script 的 SVG | 拒绝 | 不替换 | 源文件仍在 | 无 | 无 | 无 | 无 | PASS |
| 空文件 | 0 | 拒绝 | 无 | 无 | 无 | 无 | 无 | PASS |
| 损坏内容 | 拒绝 | 源文件仍在 | 无 | 无 | 无 | 无 | 无 | PASS |
| 超过 `max_bytes` | 拒绝 | 源文件仍在 | 无 | 无 | 无 | 无 | 无 | PASS |
| 关闭自动选格式 | JPEG | JPEG | 不放大 | JPEG | 保持 | 无 | 无 | PASS |
| EXIF 方向 6，30×60 | 可被 `exif_read_data` 读到 6 | 方向纠正后 | 不适用 | 优化结果 | 60×30 | 无 | 无 | PASS |
| AVIF 编码 | GD `imageavif` 可以写出 | 比较后 WebP 更小 | 无 | 编码器 PASS。默认活动配置不把 AVIF 当正式原图 |

超过 12,000,000 像素或单边 12000 的图片会保留原文件，不缩小。这条没有用超大实图跑。标 NOT TESTED。

## D. R2 验收

隔离对象，测试后删除。删除后对下列 Key 做了 HEAD，结果都是不存在：

- `public/xedc-opt-test-t6495194bb398/direct-t6495194bb398.webp`
- `public/xedc-opt-test-t6495194bb398/direct-t6495194bb398.jpg`
- `public/styles/meitu_card_cover/public/xedc-opt-test-t6495194bb398/direct-t6495194bb398.webp`

删除前的公开上传：

- Provider：`silly` / Cloudflare R2。
- Bucket：`dclili`。
- Public Prefix：`public/`。
- 对象 Key：`public/xedc-opt-test-t6495194bb398/direct-t6495194bb398.webp`。
- 上传：删除前对象可读。PASS。
- MIME：`image/webp`。
- 大小：84 字节，与文件实体一致。
- SHA256：`386017c7a6f0c9053338d340cbb3681e3e57b51e19d0bd839547c1f8f413df05`，流式回读一致。PASS。
- CDN：`https://cdn.gitxx.xyz/public/xedc-opt-test-t6495194bb398/direct-t6495194bb398.webp` 返回 200。PASS。
- File URI：`public://xedc-opt-test-t6495194bb398/direct-t6495194bb398.webp`。没有 `public/public/`。
- 样式：`meitu_card_cover` 生成在 `/var/www/drupal/web/sites/default/files/styles/meitu_card_cover/public/xedc-opt-test-t6495194bb398/`。该样式 Key 在 R2 上不存在。PASS。
- 原 JPEG Key 在优化后的检查里不存在。快速发布同样只留下 WebP。PASS。
- 清理：File 实体、媒体、审计行、本地样式和该测试前缀对象都已删除。没有删除其他对象。

快速发布隔离对象 `public/gallery/2026-11/qf5a0b8908-001.webp` 删除后 HEAD 为不存在。对应 JPEG Key 从一开始就不存在。

队列样本先记为 queued，worker 跑完后为 success。

## E. 性能

120×80 JPEG 优化约 2 ms，内存增量 7,344,128 字节。整段自测脚本约 15 秒，其中包含两次 R2 往返和 CDN HEAD。队列一次只处理测试中的那一张。并发设置是 1。没有同时压多张大图，没有把 FPM 打满。

## F. 安全

- 空文件、损坏文件、脚本 SVG、超限字节：拒绝，不替换源文件。PASS。
- 优化结果更大：第二次优化没有继续缩小。PASS。
- ZIP 路径 `../evil.jpg`：解压抛出不安全路径，目标目录没有写出该文件。PASS。
- 正常 ZIP 两张图、7z 两张图：都优化，尺寸保持。PASS。
- 匿名访问后台页：HTTP 403。PASS。
- 排队或失败的图片：发布拦截把节点压回草稿。用未保存的 photo 节点调用了 `xedc_core_node_presave()`，没有写入正式节点。PASS。
- 私有文件和视频扩展名没有进入优化。PASS。
- R2 上传失败、回读失败、File 保存失败、临时目录写满、解压炸弹实图：代码有拒绝、回滚或保留原文件的路径。没有故意弄坏生产凭据或填满磁盘。NOT TESTED。
- 日志不写 Access Key 或 Secret。本次检查没有打印连接密钥。

## G. 遗留

- PASS：统一优化器、公开 File 入库、临时文件移入公开目录、快速发布控制器、R2 `public/` 隔离上传、SHA256、CDN 200、本地样式且样式对象不在 R2、队列一张图、ZIP/7z 解压后逐张优化、路径穿越拒绝、匿名 403、Chromium 打开后台页、格式比较、EXIF 方向、幂等、私有和视频跳过。
- FIXED：媒体库以前只在 `file_insert` 上优化，临时文件移入 `public://` 时会漏掉。现在移动前优化，并修正了临时 URI 被拼成 `temporary:///` 的问题。快速发布小图不再先把未优化 JPEG 传上 R2。
- FAIL：无。
- NOT TESTED：浏览器里的会员投稿、后台创建和编辑、媒体库点选、快速发布点击。完整 ZIP/7z 导入队列和草稿节点。PHPUnit、PHPStan、PHPCS。超大像素实图。动画 GIF 的帧时长播放。故意制造的 R2、数据库和磁盘失败。前台图片列表、详情和会员主页没有重新打开。
- BLOCKED：无。R2 凭据可用，隔离写入已完成并删除。

测试对象和审计行已清掉。`xedc_image_optimize` 当前 0 行。同步阈值是 3,145,728。队列里没有残留任务。没有提交，没有推送，没有改图片样式修复文件，没有批量处理历史图片，没有改 `config/sync`。
