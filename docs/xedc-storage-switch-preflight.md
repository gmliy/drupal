# 存储切换审查

日期：2026-11-02

这是动手改代码之前的审查。后面的实现见 `docs/xedc-storage-switch-architecture.md`。下面描述的“只有编译时才换成 Xedc 类”已经被替换：容器现在始终注册同一个公开类，本地或 R2 由运行状态文件决定。

审查对象是当前工作区里的 `xedc_core`，不是旧报告里的推测。

## 现在如何决定本地还是 R2

公开包装器类写在已编译的 Drupal 容器里。`XedcCoreServiceProvider::alter()` 只有在编译时 `s3fs.use_s3_for_public` 为真时，才把 `stream_wrapper.public` 换成 `XedcPublicS3fsStream`。这个设置由 `BootGuard::apply()` 根据五个环境变量写入。容器缓存键不包含这些变量。

当前 Redis 容器创建时间是 `1793662199.742`，公开类是 `XedcPublicS3fsStream`，私有类是 `PrivateStream`。公开页原图走 `https://cdn.gitxx.xyz`，样式留在本地。

因此：

- 新的公开原图和视频在 R2 模式下走 `PublicS3fsStream` 父类，凭证来自编译进容器的 S3 配置。
- 读取已有 `public://` 文件也走同一个包装器。URI 没有改成供应商地址。
- `unlink()` 在 R2 模式下删除的是 R2 对象，不会同时删本地副本。本地模式下将只删本地文件。本阶段不做双边删除。
- 路径以 `styles/` 开头的操作已经委托给本地 `PublicStream`。WebP 和 AVIF 样式仍在本地生成。
- CDN 地址来自 `PublicUrl`。API 主机 `r2.cloudflarestorage.com` 会被拒绝。
- 私有文件不经过这个公开包装器。

`StorageSwitchboard` 只会拒绝从后台打开环境变量开关，不会改写实际读写。已有迁移执行器能把本地文件复制到 R2，并保留源文件和 URI。它按 `file_managed` 做清单，不会单独扫描目录和 Bucket 的差异。

## 容器问题

PHP-FPM、Drush 和 Cron 如果在缓存丢失时各自编译容器，没有五个环境变量的进程会把公开包装器编译回 `PublicStream`，并覆盖 Redis 里原来的定义。环境变量不能作为切换后的唯一事实来源。

可行的最小改法：

1. 容器继续注册同一个公开类 `XedcPublicS3fsStream`。本地和 R2 不再依赖编译出两个不同的类。
2. 运行状态放在私有目录的 `xedc-storage-runtime/active.json`。写入用临时文件加 `rename`。PHP-FPM、Drush 和 Cron 都读这个文件。`settings.php` 阶段就能读，不需要先启动容器。
3. 包装器每次操作按文件修改时间重新读取状态，不把供应商永久放进静态变量。
4. 状态为 R2 时，非样式文件走现有 S3 父类。状态为本地时，全部委托给 `PublicStream`，并且不调用父类构造。
5. 图片样式两种模式都留在本地。私有接管保持关闭。
6. 切换前先复制目标侧缺失的公开原件，校验大小和 SHA-256，不删除源文件，也不改 URI。
7. 不把 `drush cr`、清空 Redis 或 reload PHP-FPM 当作切换步骤。

没有运行状态文件时，仍按现有环境变量工作，避免代码刚部署就改变生产读写。初始化文件会写成当前生产值：供应商 `silly`，模式 `cloudflare_r2`，CDN 开启。这不是一次切换。

## 本阶段不执行的事

不删除 R2 对象和本地原图。不修改 `silly` 的 Bucket、Endpoint 和密钥。不启用 Private takeover。不在这次任务里把生产公开存储切到本地或再切回 R2。隔离测试只用 `_xedc_storage_test/`。
