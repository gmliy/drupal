# XEDC Cloudflare R2 说明

日期：2026-11-02。

这份说明只记录当天已经核对过的状态。生产文件仍在本地。Runtime、公开接管、私有接管和 CDN 重写都没有打开。

更细的记录在同目录的专题文档里：架构、集成、图片、视频、ZIP、会员、CDN、迁移、测试、生产启用、回滚。

## 1. 现在是什么状态

网站是 Drupal 11，模块是 `xedc_core`。对象存储继续用已经安装的 S3 File System 8.x-3.12、Drupal Key 和 AWS SDK。没有改第三方模块，也没有再装一套 S3 模块。

活动供应商是 `silly`。Bucket 是 `dclili`，Region 是 `auto`。S3 API 主机是 `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`。公开域名配置为 `https://cdn.gitxx.xyz`。API 主机不是 CDN。凭证仍在现有 Drupal Key 里，本文不写密钥。

| 开关 | 状态 |
| --- | --- |
| `XEDC_STORAGE_RUNTIME` | 关 |
| `STORAGE_I_UNDERSTAND_TAKEOVER` | 关 |
| `STORAGE_PUBLIC_TAKEOVER` | 关 |
| `STORAGE_PRIVATE_TAKEOVER` | 关 |
| CDN 重写 `STORAGE_CDN_ENABLED` | 关 |

`public://` 仍是 Drupal 本地 `PublicStream`。新上传的图片和视频还在本地。历史文件没有复制到 R2，文件 URI 没有改，本地文件没有删。

文件实体共 137 个：公开 128 个（75,177,632 字节），私有 9 个（45,393 字节），`s3://` 为 0。其中图片 126 个，视频 2 个，其他 9 个。这是数据库里的文件记录，不是 Bucket 里的对象数量。

公开对象前缀是 `s3fs-public`，私有前缀是 `s3fs-private`。这是现有 S3 File System 的映射，没有改成另一套目录名。

## 2. 已经在 R2 上验证过的事

验证脚本是 `web/modules/custom/xedc_core/tests/manual/r2-integration-self-test.php`，用 `www-data` 跑过，结果是 `all passed`。写入只发生在 `_xedc_integration_test/`，跑完就删除。测试没有新增 `file_managed` 记录，前后都是 137。

真实 R2 上通过的项目：

- JPEG、PNG、WebP、AVIF 上传，大小、SHA-256 和 Content-Type 一致。
- JPEG 转成 WebP 后，原 JPEG 还在。
- Drupal 图片样式 `thumbnail` 和 `photo_gallery_650` 先在本地生成，再上传。
- 相同内容再次上传不会覆盖。
- 256KB 随机字节以 `video/mp4` 上传。S3 Range 和预签名 HTTP Range 都返回 206，`Accept-Ranges: bytes`。这不是可播放视频，也没有在浏览器里播放。
- ZIP 在 `/tmp/xedc-storage` 解压。含 `../` 的条目被拒绝。解出的 PNG 写入测试前缀，原 ZIP 没有被提前删掉。
- `https://cdn.gitxx.xyz` 按对象键取回了那个测试 JPEG，HTTP 200，类型 `image/jpeg`。测试对象随后已删除。这不表示站点现有图片已经走到 CDN。

本机 GD 能写 JPEG、PNG、WebP、AVIF。Imagick 没装，也不是必需组件。

测试前缀和 `_xedc_storage_test/` 清理后都是 0。临时目录 `/tmp/xedc-storage` 是空的。Bucket 里还剩 1 个对象，它不在这两个测试前缀中。没有打印它的键，也没有删除它。需要人工确认它是什么。

## 3. 代码已经具备、但生产还没走通的事

这些能力在自定义模块里，服务通过依赖注入使用。接管保持关闭，所以日常上传还不到 R2。

- 远程文件先复制到 Web 根目录外的临时文件，再交给 GD 或 ZipArchive。临时目录权限 0700，文件 0600。
- 上传有重试、大文件分段、上传后校验。失败时删除刚创建的对象，不删除本地源文件。
- 私有地址不会得到永久 CDN URL。私有接管没开时，下载入口不签发签名地址。
- 清理任务不会因为 `realpath()` 返回 false 就把远程对象当成不存在。
- 迁移页 `/admin/config/media/object-storage/migration` 只做预检查和取消。复制和回滚代码存在，但没有一键按钮，这次也没有执行。
- 就绪页 `/admin/config/media/object-storage/readiness` 只读。它把公开和私有接管都标成未就绪。匿名访问这个地址返回 403。

数据库更新 `xedc_core_update_10007` 建了三张空表：`xedc_storage_variant`、`xedc_storage_download_log`、`xedc_storage_migration_item`。没有改已有表，也没有覆盖现有配置。

有一处已经修好的缺陷：MIME 检查的正则用 `#` 做分隔符，字符类里的 `#` 把表达式截断，文件会被存成 `application/octet-stream`。现已改为 `BootGuard::contentType()`。复测后视频类型是 `video/mp4`。

## 4. 还不能当作完成

| 事项 | 原因 |
| --- | --- |
| 新图片、新视频进入 R2 | 接管关闭 |
| 现有 128 个公开文件和 9 个私有文件在 R2 上 | 没有执行复制 |
| 浏览器里看图片、播放视频、上传 ZIP | 没有做浏览器操作 |
| 可播放视频和经 CDN 的视频 Range | 样本是随机字节；206 来自预签名 S3 地址，不是 CDN |
| 生产 ZIP 导入创建节点和文件 | 测试没有写内容 |
| 普通会员、VIP、签名过期 | 只确认了本地私有文件不会被签名 |
| 其余图片样式逐个生成到 R2 | 只测了 `thumbnail` 和 `photo_gallery_650` |
| 上传进度条 | 没有面向用户的百分比 |

现有两条视频的字段仍是 `public://`。配置里的“视频走私有”不会自动搬家。

打开公开接管之前，必须先把历史文件复制到 `s3fs-public/` 下的对应键并校验。接管之后 `public://` 指向对象存储。对象还没复制时，现有图片的 URL 会失效，虽然磁盘文件还在。这一步需要人工确认，说明里不提供现在就执行的命令。启用顺序和回滚写在 `docs/object-storage-r2-production-guide.md` 和 `docs/object-storage-r2-rollback.md`。

## 5. 2026-11-02 验收复查

这次重新统计，没有沿用上一份报告里的旧数字。

| 项 | 结果 |
| --- | --- |
| 开关 | Runtime、公开接管、私有接管、CDN 重写仍然全关。`public://` 仍是本地流 |
| 文件实体 | 137。公开 128 个都在磁盘上。私有 9 个在数据库里，磁盘上都不存在 |
| 公开文件 | 126 张图片、2 个视频，共 75,177,632 字节。SHA-256 有 4 组重复。R2 上对应的 `s3fs-public/` 对象是 0 |
| 私有文件 | 9 个 `member-*.zip`，数据库合计 45,393 字节。文件已缺失，不能迁移 |
| Bucket | 只有 1 个对象，键是 `and/`，0 字节，`application/octet-stream`，ETag 是空内容的 MD5，修改时间 2026-11-02T12:28:20Z。没有对应文件实体，也不在测试前缀里。没有删除，需要人工确认 |
| 清单 | 写在 `/var/www/drupal/private/xedc-storage-reports/`。没有复制，没有改 URI |

图片业务用一条未公开的测试图片内容做了本地验证：`ImagePipeline` 接受 PNG，`thumbnail` 和 `photo_gallery_650` 在本地生成，URL 不含 `cdn.gitxx.xyz`。WebP 和 AVIF 转换没有删原图。测试节点、媒体和文件已删除，图片内容数量回到 54。

视频样本能被 `file` 识别为 ISO MP4 容器，并在预签名地址和 `cdn.gitxx.xyz` 上返回 206、`video/mp4`。它没有可解码的画面轨。没有浏览器播放，也没有进度拖动。CDN 响应的 Cache-Control 是 `public, max-age=14400`，不是上传时写的 `max-age=60`。CDN 的 206 响应里没有读到 `Accept-Ranges`。

ZIP 生产解压器在私有目录解出了测试 PNG，并拒绝 `../`。没有创建导入任务，也没有留下解压目录。原图没有写入 R2，因为接管关闭。

会员：匿名 403；普通会员访问 VIP 资源得到“这是 VIP 专属资源”；有效 VIP 得到 503，没有签名；没有 VIP 记录的管理员同样被拒绝。下载日志有记录。测试用户和 VIP 记录已删除。`private://xedc-downloads` 原先属主是 root，网站用户写不进去，已改为 `www-data`。每日下载上限仍未配置，代码不额外拦截。

CDN 能按对象键读取公开测试对象。同一个自定义域名也能匿名读取 `s3fs-private/_xedc_integration_test/` 下的测试对象，HTTP 200。测试对象已删除。私有前缀目前不是私有的，这是 Cloudflare 或 Bucket 公开策略的问题，代码不能把它标成已隔离。

因此不能生产启用。公开文件还没复制；9 个私有文件本地已丢失；私有前缀可通过 CDN 读取；视频没有浏览器播放验证；PHPUnit 和 PHPCS 没有安装。

## 6. 后台入口

管理 → 配置 → 媒体 → 对象存储。

- `/admin/config/media/object-storage`
- `/admin/config/media/object-storage/readiness`
- `/admin/config/media/object-storage/migration`

权限是 `administer xedc object storage`。页面不显示密钥。
