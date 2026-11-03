# R2 第二轮验收说明

时间：2026-11-02 21:15–21:25 UTC。网站：https://gitxx.xyz。项目：`/var/www/drupal`。

本轮只做容器生命周期、PHP-FPM 环境读取、Stream Wrapper 注册、CDN URL 开关语义，以及 Drupal 自己通过包装器读写 R2。没有执行生产接管。

总评：**PARTIAL**。CLI 里的容器编译和 Drupal 文件读写已经通过。独立 PHP-FPM 没有做成。生产站点仍使用本地包装器。

原始报告：

- `docs/r2-fpm-container-lifecycle-report.md`
- `docs/r2-drupal-wrapper-io-report.md`
- `docs/r2-cdn-switch-semantics-report.md`
- `docs/r2-second-round-acceptance-report.md`

## 十个问题的答案

1. Drupal 能在独立 CLI 进程里完成容器编译。该进程打开公开接管时，编译结果是 `XedcPublicS3fsStream`。无接管进程的编译结果是 `PublicStream`。两次编译都没有写入生产 `cache_container`。
2. PHP-FPM 能否在隔离环境使用目标包装器：**BLOCKED**。现有 pool 要看到接管变量，必须改配置并重载。生产 worker 仍使用 `PublicStream`。
3. Drupal 能通过包装器写入 R2。证据是 CLI 进程里的 `file_system->saveData()`，对象随后能被 Head 到。生产 PHP-FPM 没有做这次写入。
4. Drupal 能从 R2 读回该测试文件，SHA-256 与写入内容一致。
5. Drupal 的 `file_system->delete()` 能删掉该测试对象。前缀 `s3fs-public/xedc-r2-isolated-test/` 结束后为空。
6. CDN URL 规则正确，生产页面没有启用 CDN。开关关闭时原图留在本站。开关打开且包装器已是对象存储时，原图应为 `https://cdn.gitxx.xyz/s3fs-public/...`。现有样例返回 200。API 地址返回 400。
7. 图片样式保持本地。页面、WebP、AVIF 都走 `/sites/default/files/styles/`。同名 CDN 样式地址返回 404。
8. EngagementBuilder 已修复。构造函数必需参数是 5，服务定义仍是 6 个参数。`/cosplay/cos/315` 源站返回 200。
9. 生产环境保持原状。五个开关、PHP-FPM、Nginx、`config/sync` 和容器缓存都没有改。128 个 R2 对象还在，本地原文件还在，9 条缺失 ZIP 未改。
10. 距离 Public takeover 还差一次真实 PHP-FPM 请求。包装器必须已经是 `XedcPublicS3fsStream`，同一次请求的测试前缀写入必须进入 R2，样式仍留在本地，并且打开 `STORAGE_CDN_ENABLED` 后 HTML 使用 CDN、不出现 API 主机。Private takeover 继续关闭。CLI 通过不能代替这些条件。

## 分项结果

| 专项 | 结果 |
| --- | --- |
| CLI 内存编译，接管标志关闭 | PASS。公开包装器仍是 `PublicStream` |
| CLI 内存编译，仅该进程打开公开接管 | PASS。编译结果是 `XedcPublicS3fsStream` |
| 编译期间生产 `cache_container` | PASS。两行的 checksum 和 created 都没变 |
| 独立 PHP-FPM 容器编译 | BLOCKED |
| 生产 PHP-FPM 当前容器 | PASS（只读）。仍是本地包装器，五个变量不存在 |
| Drupal 文件 API 写入、读取、删除测试对象 | PASS，仅 CLI 进程 |
| PHP-FPM 使用目标包装器读写 | NOT TESTED |
| CDN 开关语义 | PASS |
| 生产 CDN rewrite | 未启用 |
| 128 个对象和本地原文件 | PASS，只读 |
| 图片样式仍在本地 | PASS |
| 后台状态不把配置说成接管 | PASS。未登录访问就绪页返回 403，状态行来自 CLI |
| EngagementBuilder 代码 | PASS |
| `/cosplay/cos/315` | PASS，源站 200 |
| 生产环境保持原状 | PASS |

## 容器生命周期

容器缓存键在 `web/core/lib/Drupal/Core/DrupalKernel.php` 的 `getContainerCacheKey()`。组成是环境名、Drupal 版本哈希、`deployment_identifier`、`PHP_OS` 和 `container_yamls`。`s3fs.use_s3_for_public` 和 `STORAGE_*` 都不在键里。改环境变量不会让旧缓存失效。

缓存后端是数据库。`cache.container` 是 `Drupal\Core\Cache\DatabaseBackend`，bin 名是 `container`。`sites/default/files/php` 只有 Twig，不是服务容器。

`initializeContainer()` 在没有强制重建时直接加载这条缓存。`compileContainer()` 只有在 `allowDumping` 为真时才标记需要写入。真正写入发生在 `cacheDrupalContainer()`。本轮两次编译都先把 `allowDumping` 设为假，然后丢弃编译结果。

`S3fsServiceProvider::alter()` 在编译时读取 `s3fs.use_s3_for_public`，为真才把公开包装器换成 `PublicS3fsStream`。`XedcCoreServiceProvider::alter()` 在其后执行，同样只在这个标志为真时换成 `XedcPublicS3fsStream`。包装器类因此只在编译时注册。

环境变量由 `BootGuard::env()` 和 `flag()` 在每个请求进入容器之前读取。`flag()` 只接受精确字符串 `1`。服务提供者在编译阶段再读已经写入的 Settings。请求后半段不能改写已经编译好的类。

由此得到：

1. PHP-FPM 重启不会自动重建容器。新 worker 仍然加载同一条缓存。本轮没有重启。FPM 日志里最后一次 reload 是 17:05:27，早于本轮。
2. 环境变量在请求阶段读取。包装器类在编译阶段决定。
3. `XedcPublicS3fsStream` 只在编译且公开接管标志为真时注册。生产缓存编译时该标志为假，所以生产类仍是 `PublicStream`。
4. 容器缓存存在时，新 worker 继续使用旧容器。
5. 本轮的模拟是单独 CLI 进程。进程环境变量只作用于该进程。Drupal 11 的容器缓存是数据库 bin，没有独立文件目录可以替换，也不能把生产 bin 指到别处。
6. 证明方式是读取编译结果里 `stream_wrapper.public` 的类名。接管进程是 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。无接管进程是 `Drupal\Core\StreamWrapper\PublicStream`。
7. 多 worker 混用本轮 **NOT TESTED**。源码上所有 worker 读取同一条 `cache_container`。只有重建写入之后，已经开始的请求才可能暂时持有旧容器。本轮没有写入，因此不存在两份定义。没有做并发实验。

三次测试不能混为一谈：

- CLI 容器编译已执行。脚本是 `web/modules/custom/xedc_core/tests/manual/r2-fpm-wrapper-io-test.php`，另有一次无环境变量的 `drush php:eval`。
- 独立 PHP-FPM 是 **BLOCKED**。现有 pool 是 `/etc/php/8.5/fpm/pool.d/www.conf`，socket 是 `/run/php/php8.5-fpm.sock`。要让 FPM 看到接管变量，必须改 pool 或另建 pool，然后 reload。没有启动第二个 FPM。
- 生产 PHP-FPM 只做了只读确认。master pid 32897，worker pid 73147、92746、103200 的环境里都没有 `XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED`。公开类是 `PublicStream`，私有类是 `PrivateStream`。

第一次带接管标志的编译在加载类时致命：`XedcPublicS3fsStream::dir_rewinddir()` 与 `S3fsStream::dir_rewinddir(): bool` 不兼容。这个错误如果留到正式接管编译，容器会直接编译失败。已补上 `: bool`。该次致命发生在 `allowDumping` 已设为假之后，并且发生在写入缓存之前。随后的成功编译再次核对缓存行未变。

## Drupal 包装器真实读写

写入只发生在一个 CLI 进程里，URI 前缀是 `public://xedc-r2-isolated-test/`。生产 PHP-FPM 没有注册这个包装器。

| 项目 | 结果 |
| --- | --- |
| CLI 中通过 Drupal 文件 API 写入 R2 | PASS |
| Drupal 文件 API 读回并核对 SHA-256 | PASS |
| stat、MIME、目录列表、rename | PASS |
| Drupal 删除后 R2 对象消失 | PASS |
| 测试前缀无残留 | PASS |
| 图片样式写入留在本地且没有上传 | PASS |
| PHP-FPM 进程内的同一测试 | NOT TESTED |

PHP 流在这个 CLI 进程里临时注册为 `XedcPublicS3fsStream`，进程结束即消失。随后把该进程的 `public://` 注册回 `PublicStream`。生产容器类没有变。

父类是 `Drupal\s3fs\StreamWrapper\PublicS3fsStream`。只覆盖会碰到路径的方法：`getExternalUrl`、`stream_open`、`stream_read`、`stream_write`、`stream_eof`、`stream_seek`、`stream_flush`、`stream_tell`、`stream_stat`、`stream_close`、`unlink`、`rename`、`mkdir`、`rmdir`、`url_stat`、`dir_opendir`、`dir_readdir`、`dir_rewinddir`、`dir_closedir`。目标以 `styles/` 开头时委托给新的 `PublicStream` 实例，其余目标调用父类并写入 R2。样式和非样式之间的 rename 直接返回失败。

该进程的环境只有 `XEDC_STORAGE_RUNTIME=1`、`STORAGE_I_UNDERSTAND_TAKEOVER=1`、`STORAGE_PUBLIC_TAKEOVER=1`。私有接管和 CDN 都是 `0`。这些变量没有写入 PHP-FPM。`settings_accepted` 为真，`runtime_applied` 为假。

Drupal 调用是 `prepareDirectory()`、`saveData()`、`move()`、`delete()`。没有 `File::create()`。`file_managed` 前后都是 137 行。

对象键是 `s3fs-public/xedc-r2-isolated-test/<时间戳>/pixel-renamed.png`。Head 的字节数和 `image/png` 与本地内容一致，GetObject 的 SHA-256 与写入字节一致。删除后 Head 为空。`ListObjectsV2` 在测试前缀下没有剩余键。

样式探针 `public://styles/_xedc_r2_style_probe/pixel.png` 落在本地样式目录。对应 R2 键 Head 为空。探针文件和目录已删除。

S3FS 写入会向 `s3fs_file` 插入元数据。测试结束后只删除 `public://xedc-r2-isolated-test%` 和 `public://styles/_xedc_r2_style_probe%`。结束后前者行数是 0。没有改 9 条 private ZIP。

中间失败过两次。第一次是 `dir_rewinddir` 签名致命，没有发出对象写入。第二次 `saveData()` 因目录尚未创建而失败，没有对象。补上 `prepareDirectory()` 之后第三次 `RESULT PASS failures=0`。

这次证明的是 CLI 进程里替换 PHP 注册之后，Drupal 文件 API 可以创建、读取、重命名和删除 R2 对象，并把样式留在本地。它没有证明任何一个 PHP-FPM worker 正在使用这个类。

## CDN 开关

本轮没有打开生产 CDN rewrite。`STORAGE_CDN_ENABLED` 在 PHP-FPM 中不存在，当前进程的 `xedc_storage.cdn_enabled` 为假。

| 项目 | 结果 |
| --- | --- |
| 开关关闭时不生成 CDN URL | PASS |
| 开关打开时原图使用 `https://cdn.gitxx.xyz/s3fs-public/...` | PASS（函数测试，不是生产页面） |
| 图片样式在开关打开时仍是本地路径 | PASS |
| 私有 URI 不改写 | PASS |
| API 主机不进入公开 URL | PASS |
| 生产页面已经使用 CDN，而后台显示关闭 | PASS，未发现。页面仍是本地地址 |
| 生产 CDN rewrite 已启用 | 未启用 |

`STORAGE_CDN_ENABLED` 只决定公开原图的浏览器 URL 是否改写到 CDN。它不表示 Bucket 里有没有对象，也不切换 Stream Wrapper。`BootGuard` 仅在环境变量精确为 `1` 时把它设为真。未设置时使用供应商运行时文件里的 `cdn_enabled`，当前为假。

`PublicUrl::delivery()` 的规则：

- `private://` 和 `temporary://` 保持原样。
- 公开包装器还不是对象存储时，保持原样。生产现在就是这条，页面继续由 `PublicStream` 生成 `/sites/default/files/...`。
- 包装器已是对象存储，但开关关闭：原图使用本站 `/sites/default/files/<目标>`，不使用 CDN，也不回退到会生成 API 主机的父类地址。
- 包装器已是对象存储且开关打开：原图使用 `https://cdn.gitxx.xyz/s3fs-public/<目标>`。前缀只加一次。
- 目标以 `styles/` 开头：始终是本站 `/sites/default/files/styles/...`。

`XedcPublicS3fsStream::getExternalUrl()` 走同一函数。结果里如果出现 `r2.cloudflarestorage.com`，改回本站文件 URL。

后台“CDN URL 改写”同时看开关和当前样例 URL：

- 开关关且样例是本地：已关闭。
- 开关开且样例是 CDN：已开启。
- 开关开但页面仍是本地：显示“开关已开启，页面仍未改写”。
- 开关关但样例已经是 CDN：显示矛盾。

当前无接管进程的实测是：公开 URL 本地，CDN URL 改写已关闭，目标包装器未注册。实际 Drupal 写入 R2 显示为未验证。配置接受不会显示成接管成功。

`r2-architecture-self-test.php` 的 `RESULT PASS failures=0` 包含：开关关闭时远程原图仍是本站 URL；开关打开时地址是 `https://cdn.gitxx.xyz/s3fs-public/test/example.jpg`；样式在开关打开时仍含 `/styles/` 且不含 `s3fs-public`；线上文件 URL 仍是本地。临时 AVIF 和 WebP 衍生物都生成成功并已删除。

只读请求：

- `https://cdn.gitxx.xyz/s3fs-public/2026-10/IMG_3009.jpeg` 返回 200，`image/jpeg`，163198 字节。URL 中没有桶名。
- 同一对象的 API 地址返回 400。不能用 S3FS 默认的 path-style CNAME。
- `https://cdn.gitxx.xyz/s3fs-public/styles/max_325x325/public/pictures/IMG_9619.jpeg.avif` 返回 404。
- 本地同名 AVIF 返回 200 `image/avif`。
- 本地 WebP `.../styles/xa_cover/public/2026-10/IMG_1204.png.webp` 返回 200 `image/webp`。

## 页面和后台

源站 `/cosplay/cos/315` 返回 200，34763 字节。24 处本地文件 URL，样式 URL 仍是 `/sites/default/files/styles/`。没有 `cdn.gitxx.xyz`，没有 R2 API 主机，没有“网站遇到意外错误”。

首页经 Cloudflare 返回 200，同样没有 CDN 主机和 API 主机。

`/admin/config/media/object-storage/readiness` 未登录返回 403。后台页面没有在浏览器里点开。CLI 的 `RuntimeStatus::observe()` 给出：配置未接受、容器是本地包装器、目标包装器未注册、当前进程没有使用目标包装器、CDN 已关闭、写入 R2 未验证、图片样式本地、Public 和 Private 都未接管。

EngagementBuilder 的必需参数是 5，总参数是 6。服务定义 `xedc_core.engagement_builder` 仍注入 6 个参数，其中包括 `xedc_core.guest_identity`。本轮曾看到 `xedc_preprocess_html()` 在 `xedc.theme` 和 `includes/auth.inc` 里重复声明，内容页因此返回空的 500。当前 `xedc.theme` 不再声明这个函数，页面已恢复。没有为它清理生产缓存或重启 PHP-FPM。点赞、收藏、下载、分享逻辑没有改。

## 生产保护

- `cache_container` 两行在两次内存编译前后 checksum 与 created 相同。
- `file_managed` 137。公开 128 条本地文件都在。私有 9 条仍然缺失。
- 架构自测再次 Head 全部 128 个对象：大小和 Cache-Control 一致，3 个样例 SHA-256 一致。没有改元数据，没有覆盖对象。
- 测试前缀结束后没有对象。`s3fs_file` 里该前缀行数为 0。
- 本地样式目录现有 774 个文件、15 个样式目录。上一轮记录是 768。本轮探针目录已不在。没有删除生产样式，也没有把样式迁移到 R2。
- 五个生产开关没有修改。PHP-FPM、Nginx、`config/sync` 没有修改。没有 `git commit`。

## 本轮改过的代码

- `PublicUrl` 把 `STORAGE_CDN_ENABLED` 定义为公开原图 URL 改写开关。关闭时不生成 CDN，也不回退到 API 主机。
- `XedcPublicS3fsStream::getExternalUrl()` 使用同一规则。`dir_rewinddir(): bool` 与 S3FS 父签名对齐。
- `RuntimeStatus` 分开显示配置、当前容器类、目标包装器、CDN 开关和实际写入。
- 新增 `web/modules/custom/xedc_core/tests/manual/r2-fpm-wrapper-io-test.php`。
- 架构自测增加开关矩阵，以及临时 WebP、AVIF 衍生物。

## 还不能开启 Public takeover

1. 一次真实 PHP-FPM 请求里，`public://` 必须是 `XedcPublicS3fsStream`。只看到环境变量不算。
2. 同一次 FPM 请求用测试前缀写入，对象必须出现在 R2 的 `s3fs-public/` 下，样式仍写在本地。测试对象用完删除。
3. 打开 `STORAGE_CDN_ENABLED` 后，浏览器 URL 是 `https://cdn.gitxx.xyz/s3fs-public/...`，HTML 中不出现 R2 API 主机。
4. 现有图片样式 URL 仍是 `/sites/default/files/styles/...` 且返回 200。
5. 维护窗口做过一次回滚：关掉变量，用 FPM 请求重建容器，确认回到 `PublicStream`，并且不删除已复制的 128 个对象。
6. Private takeover 继续关闭，直到 9 个 ZIP 有真实字节或被明确废弃。
