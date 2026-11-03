# 这次 R2 架构整改做了什么

时间：2026-11-02。站点是 https://gitxx.xyz，Drupal 11，模块 `xedc_core`。Bucket `dclili`，供应商 `silly`，CDN 是 https://cdn.gitxx.xyz。公开前缀 `s3fs-public/`，私有前缀 `s3fs-private/`。

这次不是重新迁移文件，也没有打开生产接管。做的是上一轮接入停住之后的架构整改：让状态不再误报成功，让将来的公开地址使用 CDN 而不是 R2 API，让图片样式继续走本地，并单独修好内容页 500。

总评：**PARTIAL**。代码和隔离测试已经完成。生产 Public 接管、Private 接管、CDN rewrite、PHP-FPM 重载都没有执行，不能标成 FULL PASS。

## 这次留下的文件

读这一份即可。下面这些是同期写出的分项报告，内容和本文一致。

| 文件 | 内容 |
| --- | --- |
| `docs/r2-architecture-remediation.md` | 本说明。 |
| `docs/r2-architecture-audit.md` | 四个架构问题的源码原因。 |
| `docs/r2-architecture-remediation-report.md` | 改了哪些文件，以及为什么总评是 PARTIAL。 |
| `docs/r2-isolated-test-report.md` | 隔离测试逐项 PASS / NOT TESTED。 |
| `docs/r2-production-readiness-report.md` | 当前生产状态，以及还不能接管的条件。 |
| `docs/r2-rollback-runbook.md` | 将来打开接管之后怎样退回。 |
| `docs/engagement-builder-fix-report.md` | 内容页 500，与 R2 接管是否成功无关。 |

更早的 128 个文件复制记录仍在 `docs/r2-public-migration-manifest.csv` 和 `docs/r2-production-integration.md`。这次没有重跑那次迁移。

## 现在网站是什么状态

五个生产变量都没有写进 PHP-FPM：`XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED`。抽查的 worker 在整改前后都是 pid 92746，说明没有重载 PHP-FPM。

`public://` 仍是 `Drupal\Core\StreamWrapper\PublicStream`。`private://` 仍是本地 `PrivateStream`。首页和内容页的文件地址仍是 `/sites/default/files/`，HTML 里没有 `cdn.gitxx.xyz`，也没有 R2 API 主机。

128 个已复制的公开对象还在 R2 上。HeadObject 128/128，大小和 `public, max-age=3600` 与清单一致，抽样 3 个 SHA-256 一致。没有重传，没有改元数据，没有删除。本地原文件还在。数据库 URI 没改。9 条缺失的 private ZIP 没删、没补空文件。图片样式仍在本地磁盘，由 Nginx 返回。

已接管的功能：没有。

## 为什么上一轮不能单独打开 Public 接管

有两件独立的事被混在一起了。

第一件是容器生命周期。`StreamWrapperManager` 使用的类名是在容器编译时定下来的。`S3fsServiceProvider` 只有在编译时看到 `s3fs.use_s3_for_public` 才会把 `public://` 换成 S3 包装器。容器缓存键不包含这个设置，所以事后给 PHP-FPM 加上环境变量，不会换掉已经编译好的类。`BootGuard` 跑在 `settings.php` 里，早于容器。它以前把“凭证和 Bucket 校验通过”写成 `runtime_applied=yes`。那只说明 Settings 里有配置，不说明这个进程的上传会进 R2。

第二件是公开地址。S3FS 在没有 CNAME 时调用 `getObjectUrl()`，path-style 地址会变成：

`https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com/dclili/s3fs-public/...`

匿名请求返回 400。即便打开 CNAME 并保留 path-style，路径里还会多出 Bucket 名 `dclili`。已经能读到的 CDN 地址是 `https://cdn.gitxx.xyz/s3fs-public/...`，没有这一段。CDN 域名不能填进 API Endpoint。

图片样式是第三件。大约 768 个衍生文件在 `sites/default/files/styles/`，R2 上是 0 个。Nginx 对这个目录使用 `try_files`。如果接管后页面改链到 CDN 或 `/s3/files/styles/`，这些现成文件就不会再被用到。

私有文件仍然不能接管：FID 166、219、272、326、379、481、491、505、520 只有数据库记录，本地和 R2 都没有字节。

## 代码现在怎样避免误报

`BootGuard` 把配置验收记成 `settings_accepted` 和 `runtime_eligible`。`runtime_applied` 在容器编译前固定为 false，同时写出 `wrapper_verified=false`、`takeover_verified=false`。

`WrapperStatus` 读取当前进程里 `public://` 和 `private://` 的真实类。只有类是 S3FS 包装器时，URI 才算远程。如果环境已经请求接管、类却仍是本地，这次请求继续用本地类，并记一条警告，不把接管算成功。

`RuntimeStatus` 把这些状态拆开：是否请求运行时、配置是否可接受、包装器是本地还是 R2、当前类名、公开 URL 是本地还是 CDN、CDN rewrite 标志、图片样式是否仍在本地。没有写入探针时，实际写入位置是“未验证”。启用检查页 `/admin/config/media/object-storage/readiness` 用这一组和一次只读 HeadBucket 显示结果。HeadBucket 成功只表示 API 凭证和 Bucket 可用。

凭证已装入 Settings 的判断改看 `settings_accepted`。涉及的类是 `StorageUriResolver`、`StorageManager`、`ProtectedDownloader`、`DownloadGate`、`MigrationPlanner`。

## 将来的公开地址和图片样式

原图规则在 `PublicUrl`：`public://2026-10/IMG_3009.jpeg` 对应 `https://cdn.gitxx.xyz/s3fs-public/2026-10/IMG_3009.jpeg`。前缀只加一次，子目录保留，空格编码为 `%20`。API 主机被拒绝。`private://` 不改写。

`hook_file_url_alter()` 只在 `public://` 的当前类已经是对象存储时才改地址。现在类是 `PublicStream`，所以生产页面地址不变。

图片样式选定继续放在本地。`XedcPublicS3fsStream` 会在将来的容器编译中替换 S3FS 的公开包装器：`styles/` 的读写委托给本地 `PublicStream`，原图 URL 走 CDN，生成不出 CDN 地址时也不回退到 API 主机。`XedcCoreServiceProvider` 只有在编译容器且 `s3fs.use_s3_for_public` 为真时才安装这个类。当前生产容器没有重建。

没有改数据库，没有改 `config/sync`，没有改 Nginx，没有改第三方模块。

## 隔离测试

脚本 `web/modules/custom/xedc_core/tests/manual/r2-architecture-self-test.php` 的结果是 `RESULT PASS failures=0`。`storage-self-test.php` 也通过，并确认完整配置只设置 `settings_accepted`。

通过的项目：

- 当前公开和私有包装器都是本地类，未接管不会报成功，写入位置保持未验证。
- 内存里的临时容器在接管关闭时不会换成 S3 类。接管开启时的编译目标是 `XedcPublicS3fsStream`。这不是生产切换。
- `public://test/example.jpg` 得到 `https://cdn.gitxx.xyz/s3fs-public/test/example.jpg`。没有 API 主机，没有 `/dclili/`，前缀不重复。
- 样式地址留在 `/sites/default/files/styles/`。私有 URI 不改写。真实的 `IMG_3009.jpeg` 仍是本站地址。
- 在 `public://styles/_xedc_architecture_probe/` 写入一个像素后已删除。用真实原图和 `max_325x325` 在 `temporary://` 生成过 AVIF，随后删除。
- HeadBucket 成功。
- 测试前缀下的 PNG 上传后 SHA-256、`image/png` 和 `public, max-age=3600` 一致。独立生成的 MP4 做了 CDN Range，返回 206。私有测试对象匿名访问返回 403，响应里没有测试标记。这三个测试键已经删除。
- 128 个历史对象仍在。清单前 3 个 SHA-256 一致。
- `EngagementBuilder` 能从当前容器创建，必填构造参数是 5 个。

没有测、也不能写成通过的项目：WebP 样式、样式并发、测试片的浏览器播放，以及构造 `S3fsStream` 去跑子类自己的 `stream_open`。隔离上传走的是 R2 API 客户端，不是 PHP-FPM 里的 Drupal 包装器。

## EngagementBuilder 500

`https://gitxx.xyz/cosplay/cos/315` 之前返回 500，是因为已编译容器给 `EngagementBuilder` 传了 5 个参数，而构造函数已经需要 6 个。第 6 个是 `GuestIdentity`，服务定义里已经有它，容器缓存还是旧的。Drupal 不会因为构造函数变化自动重建容器。这次没有执行 `drush cr`。

修改只在 `EngagementBuilder.php`。第 6 个参数改为可选。旧容器只传 5 个时，仍使用 `GuestIdentity`：优先取已注册服务，否则用现有的 `request_stack` 和 `Drupal::time()` 构造同一个类。点赞、收藏、下载、分享的规则没有改。

验证：服务可以实例化。该页现在 HTTP 200，大约 34 KB，里面有 24 处本地文件地址，不再显示“网站遇到意外错误”。首页也是 200。以后某次正常的容器重建会改成注入 6 个参数，两种方式都保留游客身份。

## 回滚

这次没有需要撤销的开关。新的 URL 钩子在包装器仍是本地时不会改地址。

如果将来已经打开 Public 接管，退回顺序是：去掉 `STORAGE_CDN_ENABLED`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`XEDC_STORAGE_RUNTIME`，检查 PHP-FPM 配置后重载，再由一个没有这些变量的 PHP-FPM 请求重新编译容器。不要只用 CLI 的 `drush cr` 代替 FPM。然后确认两个包装器回到本地类，首页图片仍是 `/sites/default/files/...` 且返回 200。数据库基线仍是 137 行、fid 合计 28272、URI CRC32 合计 300810938969。不删除那 128 个历史对象。测试前缀里当次新建的对象可以删。

## 还不能打开接管

正式 Public 接管前要同时满足：

1. 带接管环境变量的 PHP-FPM 请求里，`public://` 的类是 `XedcPublicS3fsStream`。只看到变量不算。
2. 一次测试前缀上传能在 R2 上 Head 到，而不是只写在本地。测完删除该测试对象。
3. 页面原图地址是 `https://cdn.gitxx.xyz/s3fs-public/...`，HTML 中没有 R2 API 主机。
4. 旧样式 URL 仍是 `/sites/default/files/styles/...` 且返回 200。新样式也写在本地样式目录。
5. 真实公开视频的 Range 仍是 206。不要覆盖两个历史 MP4。
6. 首页和图片详情里的旧图没有成片打不开。
7. 做过一次不删除 128 个历史对象的回滚演练。
8. Private 接管继续关闭，直到 9 个 ZIP 有真实文件，或管理员明确废弃。

启用时的变量是 `XEDC_STORAGE_RUNTIME=1`、`STORAGE_I_UNDERSTAND_TAKEOVER=1`、`STORAGE_PUBLIC_TAKEOVER=1`。Private 保持关闭。公开 URL 不依赖把 `STORAGE_CDN_ENABLED` 设为 1，因为地址由 `PublicUrl` 生成。这些步骤这次都没有对生产执行。
