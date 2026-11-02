# Cloudflare R2 架构

日期：2026-11-02。活动供应商是 `silly`，Bucket 是 `dclili`，Region 是 `auto`。公开域名配置为 `https://cdn.gitxx.xyz`。S3 API 主机是 `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`，它不是 CDN。

凭证继续由现有 Drupal Key File Provider 保存。本文不记录密钥。没有安装第二套 S3 模块，没有改 S3 File System、Drupal Key 或 AWS SDK 的源码。

## 状态

| 项 | 结论 |
| --- | --- |
| 统一存储服务和接管开关分离 | 已完成 |
| 隔离前缀上的图片、视频 Range、ZIP、CDN 取回 | 真实 R2 测试通过 |
| 自动化检查脚本 | 自动化测试通过。脚本是 `web/modules/custom/xedc_core/tests/manual/r2-integration-self-test.php` |
| 登录后的后台点击和前台页面 | 浏览器实际验证未做。匿名请求 `https://gitxx.xyz/admin/config/media/object-storage/readiness` 返回 403 |
| 生产 `public://` / `private://` 接管 | 尚未完成，也未开启 |
| 历史文件复制到 R2 | 需要人工确认。代码只做预检查，本次没有复制生产文件 |
| 打开 Runtime 或 takeover | 生产启用风险。当前四个开关都是关 |

## 当前真实开关

2026-11-02 检查结果：

- `xedc_storage.runtime_applied` = false
- `s3fs.use_s3_for_public` = false
- `s3fs.use_s3_for_private` = false
- CDN rewrite（`xedc_storage.cdn_enabled`）= false
- `public://` 仍是 Drupal `PublicStream`，文件落在本地
- 文件实体 137 个：公开 128、私有 9、`s3://` 0。图片 126，视频 2，其他 9
- 这些是文件实体，不是 Bucket 对象。清理测试对象后，Bucket 里还剩 1 个对象，它不在 `_xedc_integration_test/` 或 `_xedc_storage_test/`。本次没有列出它的键，也没有删除它

公开前缀是 `s3fs-public`，私有前缀是 `s3fs-private`。这是现有 S3 File System 映射，没有改成另一套 `public/images/` 键。

## 分层

业务代码通过 Drupal 服务取存储能力，不自己 new AWS 客户端。

| 服务 | 类 | 职责 |
| --- | --- | --- |
| `xedc_core.storage_uris` | `StorageUriResolver` | 活动配置、前缀、对象键、CDN 开关、地址种类 |
| `xedc_core.storage_reader` | `RemoteFileReader` | 头信息、流式哈希、Range、复制到临时文件 |
| `xedc_core.storage_writer` | `RemoteFileWriter` | 上传、重试、分段、校验、按用途删除 |
| `xedc_core.storage_temp` | `LocalTempFileManager` | `/tmp/xedc-storage`，目录 0700，文件 0600 |
| `xedc_core.storage_files` | `LocalFileBridge` | 给 GD 和 ZipArchive 本地路径 |
| `xedc_core.storage_guard` | `StorageOperationGuard` | 删除前核对实体、引用和供应商 |
| `xedc_core.storage_delivery` | `DeliveryUrl` | 公开 URL 与受保护下载入口 |
| `xedc_core.storage_readiness` | `TakeoverReadiness` | 只读检查，`public_ready` 和 `private_ready` 固定为 false |
| `xedc_core.storage_media_pipeline` | `MediaObjectPipeline` | 只写 `_xedc_integration_test/`，结束时删除本次对象 |
| `xedc_core.storage_download` | `ProtectedDownloader` | 权限通过后才签名 |
| `xedc_core.storage_migration` | `MigrationPlanner` | 预检查和取消。界面没有一键复制 |

`BootGuard` 在 `settings.php` 里执行。Runtime、公开接管、私有接管、CDN rewrite 是四项独立状态。Runtime 未开时，接管保持关闭。任一项不是精确的 `1` 都视为关。

## 对象键

`public://2026-11/a.jpg` 对应 `s3fs-public/2026-11/a.jpg`。`private://secret/a.jpg` 对应 `s3fs-private/secret/a.jpg`。键里不允许 `..`、空字节、双斜杠、控制字符，长度上限 900。

写入用途：

- `test`：只允许 `_xedc_storage_test/`
- `integration`：只允许 `_xedc_integration_test/`
- `migration` 和 `processed`：只允许当前配置的公开或私有前缀
- 私有 URI 不会被写成公开前缀

活动配置如果不是 `silly`，集成流水线在写入前停止。旧 ID `r2` 在就绪检查里记为阻塞项。

## 和生产数据的边界

新上传在接管关闭时仍进本地 `public://`。图片样式、ZIP 导入和会员下载的生产路径没有被这次测试改写。

R2 上的成功记录只覆盖本次生成、写入 `_xedc_integration_test/`、校验后删除的对象。这些对象没有进入 `file_managed`。测试前后文件实体都是 137。
