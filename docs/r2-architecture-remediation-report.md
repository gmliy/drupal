# R2 架构整改报告

总评：**PARTIAL**。代码整改和隔离测试已经完成。生产 Public/Private 接管、CDN rewrite、PHP-FPM 重载都没有执行，因此不能标成 FULL PASS。

## 修改了什么

没有改数据库结构，没有改 `config/sync`，没有改 Nginx，没有改 PHP-FPM，没有改第三方模块。

| 文件 | 原因 |
| --- | --- |
| `src/Ops/Storage/BootGuard.php` | 配置验收改记为 `settings_accepted`。`runtime_applied` 在编译前保持 false，避免把“设置已读入”显示成接管成功。 |
| `src/Ops/Storage/WrapperStatus.php` | 读取当前进程真实的包装器类。标志已请求但类仍是本地时，不把 URI 当成远程，并记一条警告。 |
| `src/Ops/Storage/RuntimeStatus.php` | 分开配置、包装器、URL、写入探针和 CDN 标志。写入位置没有探针时是“未验证”。 |
| `src/Ops/Storage/PublicUrl.php` | 原图公开地址使用 `https://cdn.gitxx.xyz/s3fs-public/...`。拒绝 API 主机。样式留在本站。私有 URI 不改写。 |
| `src/StreamWrapper/XedcPublicS3fsStream.php` | 将来容器编译启用接管时替换 `PublicS3fsStream`。样式读写委托本地 `PublicStream`，原图 URL 走 CDN，不回退到 API 主机。 |
| `src/XedcCoreServiceProvider.php` | 只在编译容器且 `s3fs.use_s3_for_public` 为真时安装上面的类。当前生产容器不会因此改变。 |
| `xedc_core.module` | `hook_file_url_alter()`。包装器仍是本地时什么都不做。 |
| `StorageUriResolver.php` | 是否远程改看包装器类。目录前缀在 `settings_accepted` 时读取。 |
| `StorageManager.php`、`ProtectedDownloader.php`、`DownloadGate.php`、`MigrationPlanner.php` | “凭证已装入 Settings”改为看 `settings_accepted`，不再看会被误读的 `runtime_applied`。 |
| `TakeoverReadiness.php`、`StorageController.php`、`templates/xedc-core-storage.html.twig` | 启用检查页列出真实包装器、HeadBucket 和未验证的写入位置。 |
| `tests/manual/storage-self-test.php` | 期望改为：配置可被接受，但包装器未验证。 |
| `tests/manual/r2-architecture-self-test.php` | 隔离测试。 |
| `src/Engagement/EngagementBuilder.php` | 单独修复，见 `docs/engagement-builder-fix-report.md`。 |

## 接管机制

本轮把它做成部署时机制，不是请求中途换包装器。

1. 预检查：HeadBucket、128 个对象仍在、样式仍由本地 Nginx 返回、私有前缀匿名 403、BootGuard 无错误。
2. PHP-FPM 环境变量：`XEDC_STORAGE_RUNTIME=1`、`STORAGE_I_UNDERSTAND_TAKEOVER=1`、`STORAGE_PUBLIC_TAKEOVER=1`。Private 保持关闭。CDN rewrite 标志可以保持关闭，因为公开 URL 由 `PublicUrl` 使用 `https://cdn.gitxx.xyz`，不依赖 `STORAGE_CDN_ENABLED`。
3. 重载 PHP-FPM 后，由一个带这些变量的 FPM 请求重建容器。不要只用没有这些变量的 CLI `drush cr`。
4. 在该 FPM 请求中确认 `public://` 的类是 `XedcPublicS3fsStream`，`private://` 仍是 `PrivateStream`。
5. 做一次测试前缀写入，确认对象出现在 R2，而不是只看环境变量。
6. 确认页面原图地址含 `cdn.gitxx.xyz` 且不含 API 主机；样式地址仍是 `/sites/default/files/styles/`。
7. 回滚见 `docs/r2-rollback-runbook.md`。

这些步骤本轮都没有对生产执行。

## 判定

| 验收项 | 结果 |
| --- | --- |
| BootGuard 不再把配置接受写成 runtime_applied | PASS |
| 当前进程能读出真实包装器类 | PASS，现在是本地 PublicStream |
| 容器生命周期有明确的编译期机制 | PASS，机制已实现；生产容器未重建 |
| 公开 URL 规则使用 CDN | PASS，隔离测试；生产页面仍是本地 URL |
| 图片样式设计为继续本地 | PASS，设计与本地探针；S3 子类未在生产容器中激活 |
| 上传目标可验证 | PARTIAL，隔离 R2 客户端写入已验证；Drupal 包装器写入未在 FPM 中验证 |
| 私有边界 | PASS，隔离对象匿名 403，私有 URI 不改写 |
| EngagementBuilder | PASS，单独记录 |
| 128 个对象和本地原文件 | PASS，只读核对 |
| 总体 | PARTIAL |
