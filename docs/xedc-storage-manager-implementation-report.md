# XEDC Storage Manager 实现报告

日期：2026-11-02

## 新增和修改

新增：

- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/StorageProviderInterface.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/StorageProviderRegistry.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/LocalStorageProvider.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/CloudflareR2Provider.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/RuntimeConsistency.php`

修改：

- `StorageConfig::deleteProfile()` 在清除凭证之前拒绝删除当前选定配置，也拒绝删除运行时正在使用的配置。
- `StorageController` 增加运行模式页和健康检查页，概览列出可用适配器，策略页写明实际生效范围。
- `xedc_core.routing.yml` 增加 `/admin/config/media/object-storage/runtime`、`/policies` 和 `/admin/reports/xedc-storage-health`。`/policies` 与原来的 `/policy` 使用同一个控制器。
- 权限声明增加查看状态、管理供应商、管理策略、切换模式、迁移、写入测试和查看日志。路由仍要求原来的 `administer xedc object storage`，避免新权限尚未分配时把现有管理员关在外面。
- 供应商目录增加阿里云 OSS 和腾讯云 COS，并标明未实现。
- 对象存储模板补了运行模式、健康检查和策略实际行为。环境变量示例不再被写成当前生产开关。

没有修改 `settings.php`、Nginx、`config/sync`，也没有安装模块。五个生产开关的值没有改。

## 可用的供应商

| 供应商 | 状态 |
| --- | --- |
| 本地文件系统 | 可用。健康检查只读目录。 |
| Cloudflare R2，配置 ID `silly` | 可用。复用现有客户端，生产公开包装器仍是 `XedcPublicS3fsStream`。 |
| Amazon S3、Wasabi、Backblaze B2、阿里云 OSS、腾讯云 COS、DigitalOcean Spaces、Vultr、MinIO、自定义 Endpoint | 只有类型模板，不能连接。 |

## 已完成的后台页面

对象存储概览、供应商、新增模板、编辑、运行模式、存储策略、CDN、迁移、监控、健康检查都能用管理员会话打开。运行模式页没有切换按钮。健康检查默认只读。迁移仍是原来的复制流程，没有改成任意供应商之间的执行器。

## 策略

实际生效的是全局行为：公开原图和视频跟随当前公开包装器，样式、Private 和 Temporary 留在本地。按文件类型分到多个供应商未启用。

## 运行时保护

`deleteProfile('silly')` 会返回错误，配置和凭证文件都还在。

PHP-FPM 在 23:19 重新加载了已经写在 `www.conf` 里的五个开关。加载前，16:52 启动的 master 没有把这五个变量放进 worker。随后三次删掉了已经被写成 `PublicStream` 的那一个 Redis 容器键，由带开关的 PHP-FPM 重新编译。最后一次编译时间是 `1793662199.742`。编译后的公开包装器是 `XedcPublicS3fsStream`，私有包装器是 `PrivateStream`。

这不是一次计划中的存储切换。页面上保存配置仍然不会切换生产存储。

## 数据

没有执行 update hook，没有手工 SQL 修改文件实体。

验收期间管理员会话新上传了 6 个公开文件，`file_managed` 从接管时的 137 行变为 143 行。9 条 private ZIP 的 fid 和 `changed` 与检查开始时相同。R2 私有前缀仍是 0 个对象。

23:30 的只读列举：`s3fs-public/` 130 个对象，共 75,806,705 字节。其中 486 字节是 `s3fs-public/.htaccess`，628,587 字节是 23:27 上传的 `IMG_3026.jpeg`。其余 75,177,632 字节对应原来的 128 个正式对象。另外 5 个在包装器短暂回到本地时上传的 PNG 没有进入这次 R2 计数。这些对象都没有被删除。
