# XEDC Storage Manager 报告

日期：2026-11-02  
项目：`/var/www/drupal`  
生产站点：https://gitxx.xyz  
结论：**PARTIAL**

多供应商适配层和后台观察页面已经接上真实数据。生产公开存储在报告核对时仍由 Cloudflare R2 提供。按文件类型分流、后台一键切换，以及其他云厂商的真实连接都没有实现，因此不能记为 PASS。

23:41 UTC 复核：首页返回 200。照片页原图走 `https://cdn.gitxx.xyz/s3fs-public/`，图片样式仍在本地。Redis 容器创建时间仍是 `1793662199.742`（23:29:59 UTC），公开包装器是 `XedcPublicS3fsStream`，不是 `PublicStream`。

## 1. 当前生产基线

Cloudflare R2 配置 ID 是 `silly`，Bucket 是 `dclili`，Region 是 `auto`。API Endpoint 主机是 `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`。公开域名是 `https://cdn.gitxx.xyz`。公开前缀是 `s3fs-public/`，私有前缀是 `s3fs-private/`。

五个进程开关的值没有改：

- `XEDC_STORAGE_RUNTIME=1`
- `STORAGE_I_UNDERSTAND_TAKEOVER=1`
- `STORAGE_PUBLIC_TAKEOVER=1`
- `STORAGE_PRIVATE_TAKEOVER=0`
- `STORAGE_CDN_ENABLED=1`

公开原图和视频使用 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。图片样式继续委托本地 `PublicStream`。私有文件继续使用 `Drupal\Core\StreamWrapper\PrivateStream`。Private takeover 保持关闭。

公开 URL 由 `PublicUrl` 和包装器的 `getExternalUrl()` 生成，不使用 R2 API 主机。凭证仍在私有目录的供应商文件里。`CredentialVault`、`S3ClientFactory`、`RemoteFileReader`、`RemoteFileWriter` 仍是唯一的 R2 客户端路径。

## 2. 架构

运行开关由 `BootGuard::apply()` 在 `settings.php` 引入 `xedc_storage.runtime.php` 时写入 Settings。容器编译时，`XedcCoreServiceProvider` 才把公开包装器换成 Xedc 类。后台保存的配置不等于这个进程已经切换成功。

这次新增的是描述和只读检查，没有第二套上传实现。

| 类 | 职责 |
| --- | --- |
| `StorageProviderInterface` | 编号、名称、类型、是否真能用、能力、不含凭证的摘要、只读健康检查 |
| `StorageProviderRegistry` | 列出可用适配器，按 ID 查询，未知 ID 返回空 |
| `LocalStorageProvider` | 检查本地公开目录和私有目录，不改变 `public://` 和 `private://` |
| `CloudflareR2Provider` | 包装已保存的 `silly` 配置。健康检查只做 `HeadBucket` 和一个已知对象的 `HeadObject` |
| `RuntimeConsistency` | 对比本进程开关、已编译包装器和可读取的维护配置 |

注册表只把本地存储和 `provider = cloudflare_r2` 的已保存配置当成可用适配器。它不放进 `services.yml`，控制器在请求里用现有服务组装，避免为了登记一个服务而重新编译容器。

已有文件 URI 保持 `public://` 或 `private://`。公开包装器决定原图和视频读哪一边，样式路径留在本地。没有新增供应商映射表，也没有按文件类型分流。策略页写明这项未启用。

## 3. 实现

新增：

- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/StorageProviderInterface.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/StorageProviderRegistry.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/LocalStorageProvider.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/CloudflareR2Provider.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/RuntimeConsistency.php`

修改：

- `StorageConfig::deleteProfile()` 在清除凭证之前拒绝删除当前选定配置，也拒绝删除运行时正在使用的配置。`deleteProfile('silly')` 返回错误，配置和凭证文件都还在。
- `StorageController` 增加运行模式页和健康检查页。概览列出可用适配器。策略页写明实际生效范围。
- 路由增加 `/admin/config/media/object-storage/runtime`、`/policies` 和 `/admin/reports/xedc-storage-health`。`/policies` 与原来的 `/policy` 使用同一个控制器。
- 权限声明增加查看状态、管理供应商、管理策略、切换模式、迁移、写入测试和查看日志。路由仍要求原来的 `administer xedc object storage`。
- 供应商目录增加阿里云 OSS 和腾讯云 COS，并标明未实现。
- 模板补了运行模式、健康检查和策略实际行为。环境变量示例不再被写成当前生产开关。

没有修改 `settings.php`、Nginx、`config/sync`，没有安装模块，没有新表，没有 update hook，没有手工 SQL 修改文件实体。操作记录继续用现有 State 日志。

| 供应商 | 状态 |
| --- | --- |
| 本地文件系统 | 可用。健康检查只读目录 |
| Cloudflare R2，配置 ID `silly` | 可用。复用现有客户端 |
| Amazon S3、Wasabi、Backblaze B2、阿里云 OSS、腾讯云 COS、DigitalOcean Spaces、Vultr、MinIO、自定义 Endpoint | 只有类型模板，不能连接 |

实际生效的策略是全局行为：公开原图和视频跟随当前公开包装器，样式、Private 和 Temporary 留在本地。迁移仍是原来的复制流程，没有改成任意供应商之间的执行器。健康检查默认只读，没有写入探针。

## 4. 容器缓存

Redis 容器键不包含五个 `STORAGE_*` 环境变量。缓存命中时，没有这些变量的命令也会用上一次编译好的包装器。缓存丢失时，没有这些变量、或者凭证没有解析成功的进程，会把公开包装器编译回 `PublicStream`，并覆盖同一个键。

验收期间这个键曾在 23:13、23:21 和 23:29 UTC 被写成 `PublicStream`，公开页面暂时改走本地文件。PHP-FPM 主进程从 16:52 起没有重新加载过池配置，22:41 写入 `www.conf` 的五个变量当时没有进入已经运行的 worker。

23:19 对 php-fpm 做了一次 reload，没有改五个变量的值。之后只删除了那个错误的容器键，由带开关的 worker 重新编译。最后一次编译是 `1793662199.742`（23:29:59 UTC）。编译结果是公开包装器 `XedcPublicS3fsStream`、私有包装器 `PrivateStream`。23:41 复核时创建时间未变。

这不是一次计划中的存储切换。运行模式页没有切换按钮，保存配置不会切换生产存储，也没有自动回滚动作。

清过的是路由、Twig 编译文件、页面缓存、动态页面缓存、渲染缓存和 discovery 缓存。没有清空全部 Drupal 缓存。

没有五个开关的 CLI 没有再跑。下一次容器缓存丢失时，那样的进程仍会把包装器编译回本地。

## 5. 验收

带五个生产开关的 Drush：

- 可用适配器是 `local` 和 `silly`。未知 ID 返回空。
- 本地健康检查为 `ok`。
- R2 只读检查：`HeadBucket` 成功，抽样对象 163,198 字节，没有写入。
- 类型目录 10 项，其中 9 项是“未实现”。
- 删除 `silly` 被拒绝。
- 带开关的 Drush 与 PHP-FPM 读到同一个 `XedcPublicS3fsStream`。

未登录访问概览、供应商、运行模式、策略、CDN、迁移和健康检查，全部返回 403。

管理员会话用 Chromium 打开这些页面。标题正常，没有 500，HTML 里没有 Access Key 和 Secret Key。

- 概览显示本地和 `silly`，Bucket `dclili`，CDN `https://cdn.gitxx.xyz`。
- 供应商页把阿里云 OSS、腾讯云 COS、Amazon S3、Backblaze B2 标成未实现。
- 新增和编辑页有真实的 `form_build_id`。编辑页没有提交。空白凭证沿用现有保存逻辑，不会清空原密钥。
- 运行模式页的本进程是 `fpm-fcgi`，五个开关为 `1 / 1 / 1 / 0 / 1`，包装器与开关一致，并写明不能在这里切换生产存储。
- 策略页写明按文件类型分流未启用。
- 健康检查对本地和 R2 给出 `ok`。
- 迁移和监控页仍是原来的表单。

桌面概览和 390 像素宽的运行模式页都能排开。窄屏导航换行。暗色模式沿用后台已有颜色变量，这次没有单独切换一次暗色主题。

数据：

- 9 条 private ZIP（fid 166、219、272、326、379、481、491、505、520）的 `changed` 未变。
- R2 私有前缀对象数是 0。
- 图片样式顶层目录仍是 15 个。样式文件从接管时的 774 个增加到 784 个，来自新上传原图的衍生图。
- `file_managed` 从接管时的 137 行变为 143 行。多出来的 6 行是 uid 1 在 23:21 到 23:27 上传的图片，不是本任务写的 SQL。
- 23:30 的只读列举：`s3fs-public/` 有 130 个对象，共 75,806,705 字节。相对原来的 128 个对象、75,177,632 字节，多出 `s3fs-public/.htaccess`（486 字节）和 `s3fs-public/2026-11/IMG_3026.jpeg`（628,587 字节）。另外 5 个在包装器回到本地时上传的 PNG 没有进入这次 R2 计数。这些对象都没有被删除，原来的 128 个对象也没有重传。
- 测试前缀 `xedc-r2-production-verify/` 和 `_xedc_storage_test/` 的对象数是 0。

## 6. 尚未完成

- 后台不能执行生产存储切换，也没有自动回滚。
- 不能按文件类型把新文件写到不同供应商。
- Amazon S3、阿里云 OSS、腾讯云 COS、Backblaze B2 不能连接。
- 迁移没有升级成任意已实现供应商之间的任务执行器。
- 没有做写入探针。
- 新权限还没有绑定到路由。

## 7. 后续供应商接入

新的供应商先做成适配器，再登记为可用。适配器完成之前不能显示成已连接。

1. 新增一个实现 `StorageProviderInterface` 的类。
2. 只在 `StorageProviderRegistry::operational()` 里登记已经能连接、能解释失败原因的适配器。
3. Endpoint、Bucket、Region、前缀和 CDN 域名放在现有 `xedc_core.storage` 配置里。密钥继续走 `CredentialVault`，不要写进配置导出、日志或页面。
4. 对象读写优先复用 `S3ClientFactory`、`RemoteFileReader`、`RemoteFileWriter`。S3 兼容服务不要再做一套客户端。
5. 公开 URL 使用 CDN 或自定义公开域名。API Endpoint 不能当成公开地址。私有 URI 不改写成 CDN。
6. 图片样式保持本地，除非单独验收过样式迁移。
7. 写入探针只能使用专用前缀，结束后删除探针对象。不能碰正式对象。

适配器不负责 Drupal 文件实体、引用统计、权限和迁移任务。`isOperational()` 只在当前进程真能解析连接时返回真。`healthReadOnly()` 默认不写对象，状态只用 `ok`、`warn`、`bad`、`unsupported`。

不要改已有 `public://` URI 来编码供应商。不要在适配器里打开 Private takeover。不要从后台保存动作里编译容器或修改 PHP-FPM 环境变量。容器要由已经带齐五个开关的 PHP-FPM 进程编译。
