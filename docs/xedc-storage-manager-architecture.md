# XEDC Storage Manager 架构

日期：2026-11-02

这份说明基于已经接管的 Cloudflare R2 公开存储，不替换现有读写路径。

## 当前架构

公开原图和视频仍走 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。图片样式继续委托本地 `PublicStream`。私有文件继续使用 `Drupal\Core\StreamWrapper\PrivateStream`。

运行开关在 PHP 进程环境里，由 `BootGuard::apply()` 在 `settings.php` 引入 `xedc_storage.runtime.php` 时写入 Settings。容器编译时 `XedcCoreServiceProvider` 才把公开包装器换成 Xedc 类。CDN 地址由 `PublicUrl` 和包装器的 `getExternalUrl()` 生成，不使用 R2 API 主机。

凭证在私有目录的供应商文件里。现有 `CredentialVault`、`S3ClientFactory`、`RemoteFileReader`、`RemoteFileWriter` 仍是唯一的 R2 客户端路径。

后台原来已经有供应商配置、CDN、迁移、监控和操作记录。这次没有重写这些页面。

## 新架构

新增一层只读描述和健康检查，不再增加第二套上传实现。

| 类 | 职责 |
| --- | --- |
| `StorageProviderInterface` | 编号、名称、类型、是否真能用、能力、不含凭证的摘要、只读健康检查 |
| `StorageProviderRegistry` | 列出可用适配器，按 ID 查询，未知 ID 返回空 |
| `LocalStorageProvider` | 检查本地公开目录和私有目录 |
| `CloudflareR2Provider` | 包装已保存的 R2 配置，连接测试调用现有 `S3ClientFactory` 和 `RemoteFileReader` |
| `RuntimeConsistency` | 对比本进程开关、已编译包装器和可读取的维护配置 |

注册表只把本地存储和 `provider = cloudflare_r2` 的已保存配置当成可用适配器。`ProviderCatalog` 里的其他类型保持“未实现”。

注册表不放进 `services.yml`。容器缓存键不包含模块服务定义的内容变化，但新增服务会在下次编译时改变容器。这次为了不主动触发编译，控制器在请求里用现有服务组装注册表。

## 现有 R2 适配

适配器 ID 使用已保存配置 `silly`，不新建供应商编号。Bucket、Endpoint、CDN 域名、`s3fs-public/` 和 `s3fs-private/` 都读现有配置。健康检查只做 `HeadBucket`，再对一个已知公开对象做 `HeadObject`。没有 `writeObject`，也不会删除正式对象。

## 本地适配

本地适配器只检查目录是否存在、是否可读可写，以及磁盘剩余空间。它不改变 Drupal 的 `public://` 和 `private://` 行为。

## 未来供应商

AWS S3、阿里云 OSS、腾讯云 COS、Backblaze B2 以及其他 S3 兼容存储只出现在类型目录里，状态是“未实现”。添加一个适配器的步骤写在 `docs/xedc-storage-provider-development-guide.md`。目录里的“填写模板”只保存配置，不会建立连接。

## 文件 URI

第一阶段不按文件类型分流。已有文件的 URI 保持 `public://` 或 `private://`。公开包装器决定原图和视频读哪一边，样式路径仍留在本地。没有新增文件到供应商的映射表，也没有改 `file_managed`。

按类型把图片放到 R2、视频放到 S3 这类策略，要先有稳定的供应商编号和历史 URI 的读取规则。现在没有这层路由，所以策略页明确写着未启用。

## 容器缓存

Redis 容器键不包含五个 `STORAGE_*` 环境变量。缓存命中时，没有这些变量的命令也会用上一次编译好的包装器。缓存丢失时，没有这些变量、或者凭证没有解析成功的进程，会把公开包装器编译回 `PublicStream`，并覆盖同一个键。

这次验收里，这个键曾在 23:13、23:21 和 23:29 被写成 `PublicStream`。PHP-FPM 主进程从 16:52 起没有重新加载过池配置，22:41 写入的环境变量当时没有进入已经运行的 worker。23:19 对 php-fpm 做了一次 reload，没有改五个变量的值。之后由带变量的 worker 重新编译，公开包装器回到 `XedcPublicS3fsStream`。

后台运行模式页只展示本进程事实，不能保存一个配置值就当成切换成功。切换按钮没有做。

## 数据库

没有新表，没有 update hook，没有直接修改 `file_managed`。操作记录继续用现有 State 日志。凭证继续放在现有私有文件里，页面和异常不输出密钥。
