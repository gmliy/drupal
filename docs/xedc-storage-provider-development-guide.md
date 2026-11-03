# 对象存储供应商适配器开发说明

新的供应商先做成适配器，再登记为可用。不要在适配器完成之前把它显示成已连接。

## 接入步骤

1. 新增一个实现 `Drupal\xedc_core\Ops\Storage\Provider\StorageProviderInterface` 的类。
2. 只在 `StorageProviderRegistry::operational()` 里登记已经能连接、能解释失败原因的适配器。
3. 供应商自己的 Endpoint、Bucket、Region、前缀和 CDN 域名放在现有 `xedc_core.storage` 配置里。密钥继续走 `CredentialVault`，不要写进配置导出、日志或页面。
4. 对象读写优先复用 `S3ClientFactory`、`RemoteFileReader`、`RemoteFileWriter`。S3 兼容服务不要再做一套客户端。
5. 公开 URL 使用 CDN 或自定义公开域名。API Endpoint 不能当成公开地址。私有 URI 不改写成 CDN。
6. 图片样式保持本地，除非单独验收过样式迁移。
7. 写入探针只能使用专用前缀，结束后删除探针对象。不能碰 `s3fs-public/` 里的正式对象。

## 接口边界

适配器描述对象存储。它不负责 Drupal 文件实体、引用统计、权限和迁移任务。那些仍由现有文件 API、`MigrationPlanner` 和下载控制处理。

`isOperational()` 只在当前进程真能解析连接时返回真。类型目录里的名称不是连接状态。

`healthReadOnly()` 默认不做写操作。返回状态只用 `ok`、`warn`、`bad`、`unsupported`。

## 还不能做的事

- 不要因为目录里有 Amazon S3、阿里云 OSS、腾讯云 COS 或 Backblaze B2，就在注册表里返回一个可用实例。
- 不要改已有 `public://` URI 来编码供应商。
- 不要在适配器里打开 Private takeover。
- 不要从后台保存动作里编译容器或修改 PHP-FPM 环境变量。容器要由已经带齐五个开关的 PHP-FPM 进程编译。
