# 存储切换实现报告

日期：2026-11-03  
项目：`/var/www/drupal`  
结论：切换功能已经接到现有对象存储后台，生产仍停在 Cloudflare R2。没有执行生产切换。

## 实际读写

公开包装器类固定为 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。它在每次请求里读 `private/xedc-storage-runtime/active.json`。

当前生产文件是版本 1，供应商 `silly`，模式 `cloudflare_r2`，CDN 开启，状态 `active`。因此现在的公开原图和视频仍写 R2，公开地址仍是 `https://cdn.gitxx.xyz`。图片样式仍在本地生成。私有文件仍是 `PrivateStream`。

本地模式已经在独立进程里验证：把运行目录指到临时文件夹并写成 `local` 后，同一个包装器把测试文件写进本地公开目录，没有调用 S3 父类构造。该测试文件已删除。这个覆盖目录只存在于那个 CLI 进程，PHP-FPM 没有使用它。

R2 读写仍走现有 `RemoteFileReader`、`RemoteFileWriter` 和 `S3ClientFactory`。没有新的上传客户端，没有改 `s3fs` 源码，没有改 Bucket、Endpoint、密钥或配置 ID `silly`。

## 后台

入口仍是 `/admin/config/media/object-storage`。运行模式页是 `/admin/config/media/object-storage/runtime`。

页面分开显示实际运行模式、已保存的供应商和包装器类。目标用单选按钮选择本地或 `silly`。当前已经是 R2 时，默认目标是本地。

“检查文件一致性”会列出公开原件在两边的数量，并保存 15 分钟检查令牌。两边都没有的文件大于 0 时不出现确认按钮。确认文字是“切换公开存储”。目标与当前模式相同时，服务端直接拒绝，不复制、不改版本。

2026-11-03 的管理员提交只点了检查。随后用正确确认文字请求切回 `silly`，服务端返回 `same`，版本保持 1，写入没有暂停。没有提交“确认并切换”。

## 权限

运行模式页允许“查看存储状态”“执行存储切换”或原有的“管理对象存储”。只有后两个权限会看到切换表单，提交时还会再查一次。

供应商编辑要求管理供应商权限。策略页要求管理策略权限。迁移页要求迁移权限。监控页上的单价表单只对管理策略或总管理权限渲染，提交时再查权限。查看权限可以打开概览、运行模式、CDN、健康检查和监控，不能打开供应商编辑和迁移。

匿名访问运行模式页返回 403。临时的只查看账号能打开运行模式页，页面上没有检查按钮和确认按钮，新增供应商和迁移页返回 403。该账号和角色已删除。

## 本任务改过的存储文件

- `src/Ops/Storage/RuntimeState.php`
- `src/Ops/Storage/StorageSwitchManager.php`
- `src/Ops/Storage/BootGuard.php`
- `src/Ops/Storage/PublicUrl.php`
- `src/Ops/Storage/WrapperStatus.php`
- `src/Ops/Storage/RuntimeConsistency.php`
- `src/Ops/Storage/RuntimeStatus.php`
- `src/StreamWrapper/XedcPublicS3fsStream.php`
- `src/XedcCoreServiceProvider.php`
- `src/Form/StorageSwitchForm.php`
- `src/Form/StoragePricingForm.php`
- `src/Form/StorageMigrationForm.php`
- `src/Controller/StorageController.php`
- `templates/xedc-core-storage.html.twig`
- `xedc_core.routing.yml`
- `tests/manual/r2-architecture-self-test.php`

工作区里还有其他未提交改动。这次没有回滚、提交或覆盖那些文件。

部署过程中，包装器曾经在自动加载注册前调用 `RuntimeState`，照片页出现过短时间 500。`BootGuard` 改为直接加载这个类后，照片页恢复 200，原图仍走 CDN。生产运行文件是在修复之后才写成 R2 的。
