# Cloudflare R2 集成说明

日期：2026-11-02。

## 状态

| 能力 | 结论 |
| --- | --- |
| 活动供应商、Bucket、Endpoint、Region、Path-style、Key 引用、前后缀、CDN 主机 | 已完成。读取的是 `silly`，不是旧 ID `r2` |
| 读取、分段读取、上传、哈希、MIME、大小、删除、存在性、URL | 已完成，并在隔离前缀上做了真实 R2 测试 |
| 失败重试、大于阈值的分段上传、上传后校验、相同内容不覆盖 | 已完成。代码在 `RemoteFileWriter` |
| 上传进度条 | 尚未完成。有日志计数，没有面向用户的进度百分比 |
| 生产文件走 R2 | 尚未完成。Runtime 和 takeover 关闭 |
| 浏览器里上传一张生产图片并看到 R2 URL | 浏览器实际验证未做 |

## 连接从哪里来

集成测试和以后的迁移都调用 `CredentialVault::resolve()`，只解析活动配置 `silly`。不新建 Key，不改现有密钥，不把 Cloudflare Global API Key 当成 S3 Access Key。

`S3ClientFactory` 使用已安装的 AWS SDK。超时和重试由写入器传入。默认最多 3 次，每次失败后等待 200 毫秒。分段阈值默认 8MB，下限 256KB。视频和图片样本都按文件路径交给 SDK，不把整个文件读进一个 PHP 字符串再上传。哈希按 1MB 块更新。

上传成功的条件是：对象大小一致、SHA-256 一致、返回的 Content-Type 与请求一致。要求 Cache-Control 时，响应头也必须一致。校验失败会删除刚创建的对象，不删除本地源文件。相同哈希再次上传返回 `exists`，不覆盖。

2026-11-02 的一次缺陷：MIME 正则用 `#` 做分隔符，字符类里的 `#` 把表达式截断，所有类型都变成 `application/octet-stream`。现已改为 `BootGuard::contentType()`。复测后 JPEG 为 `image/jpeg`，视频为 `video/mp4`。

## 本次真实 R2 结果

脚本：`drush php:script web/modules/custom/xedc_core/tests/manual/r2-integration-self-test.php`。以 `www-data` 运行。前缀 `_xedc_integration_test/{8位}/`。

通过的步骤：JPEG、PNG、WebP、由 JPEG 转出的 WebP、AVIF、样式 `thumbnail`、样式 `photo_gallery_650`、256KB 视频样本、ZIP 内 PNG。重复上传返回 `exists`。Cache-Control 为 `public, max-age=60`。

清理：9 个本次键全部删除，集成前缀剩余 0，`_xedc_storage_test/` 剩余 0。`/tmp/xedc-storage` 没有残留。Bucket 总对象数在清理后是 1，该对象不在上述两个测试前缀里，未删除。

文件实体保持 137。Runtime 在运行前后都是关。`public://` 仍是本地流。

## 后台

管理中心仍是 `/admin/config/media/object-storage`。新增只读页 `/admin/config/media/object-storage/readiness`，权限 `administer xedc object storage`。匿名访问该 URL 返回 403。用用户 1 在 CLI 渲染出活动配置、Bucket、CDN 主机和两个前缀，输出中没有密钥或签名。这不是浏览器操作。

数据库更新 `xedc_core_update_10007` 创建了空表 `xedc_storage_variant`、`xedc_storage_download_log`、`xedc_storage_migration_item`。没有改已有表，没有复制文件。
