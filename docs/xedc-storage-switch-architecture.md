# 存储切换架构

日期：2026-11-03  
项目：`/var/www/drupal`  
站点：https://gitxx.xyz

公开文件可以在本地文件系统和现有 Cloudflare R2 配置 `silly` 之间切换。切换依据是私有目录里的一份运行状态文件，不是五个 `STORAGE_*` 环境变量，也不是重新编译两个不同的包装器类。

## 统一运行状态

文件位置：

`private/xedc-storage-runtime/active.json`

`settings.php` 在容器存在之前就包含 `xedc_storage.runtime.php`，再调用 `BootGuard::apply()`。`BootGuard` 直接加载 `RuntimeState.php`，因为这时模块的自动加载还没有注册。文件不存在、校验失败或模式不是已知值时，行为退回原来的环境变量，不会误切到本地。

文件至少包含：

- `public_provider`：`silly` 或 `local`
- `public_mode`：`cloudflare_r2`、`local` 或 `env`
- `read_policy`：当前为 `active_provider`
- `cdn_enabled` 和 `cdn_base`
- `version`
- `switch_task`
- `previous_provider` 和 `previous_mode`
- `updated`
- `status`：`active`、`switching` 或 `rolled_back`
- `writes_paused`
- `summary`
- `checksum`：除校验字段外的 JSON 做 SHA-256

写入先写临时文件，再 `rename`。同一秒内 PHP-FPM 不会把旧模式留在静态变量里跨请求使用：缓存键带有本次请求的 `REQUEST_TIME_FLOAT`，`write()` 也会清掉缓存。

生产初始化写成版本 1、供应商 `silly`、模式 `cloudflare_r2`、CDN 开启。这是记录当前生产状态，不是一次切换。

私有接管在 `BootGuard` 里固定为关闭。运行文件不能打开它。

## Stream Wrapper

`XedcCoreServiceProvider::alter()` 始终把 `stream_wrapper.public` 设为 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。容器缓存丢失后，没有五个环境变量的进程也不会再编回 `PublicStream`。

这个类在构造时读运行文件：

- `cloudflare_r2`：调用现有 `PublicS3fsStream` 构造，原图和视频继续走现有 S3 客户端。
- `local`：不调用父类构造，读写都委托给 `PublicStream`。
- 路径以 `styles/` 开头：两种模式都委托本地 `PublicStream`。`getDirectoryPath()` 继续返回本地公开目录，图片样式路由不变。

`writes_paused` 为真时，非样式公开文件的写入和删除返回失败。样式仍可生成。切换结束或失败时会清掉这个标志。锁还在时，页面上的“恢复公开写入”不会强行恢复。

删除只作用于当前包装器正在使用的那一侧。R2 模式删除 R2 对象，本地模式删除本地文件。不自动删除另一侧副本。

`public://` 和 `private://` 不变。私有包装器保持 `Drupal\Core\StreamWrapper\PrivateStream`。

## 读写策略

新上传的公开原件进入运行文件里的当前供应商。历史文件不会因为目标变了就自动改读另一侧。切换前先把目标侧缺少的公开原件复制过去，所以切换之后读目标侧就能读到这些原件。源文件和已有 R2 对象都不删除。

只读检查比较 `file_managed` 里的公开原件、本地文件大小和 R2 对象大小。它不下载整个 Bucket 做 SHA-256。真正复制的文件在复制后比较本地和远端 SHA-256。测试前缀上的往返复制也会比较 SHA-256。

不复制 `styles/`，不复制私有文件。R2 上不属于 `file_managed` 的对象，例如 `.htaccess`、探测图和已经存在的样式对象，不在这次复制清单里，也不会被删除。

公开 URL 仍由 `PublicUrl` 生成。R2 模式使用 `https://cdn.gitxx.xyz`。本地模式不发 CDN 地址。`r2.cloudflarestorage.com` 不能作为公开图片地址。

## CLI、PHP-FPM 和 Cron

这三边都经过 `BootGuard`，读同一份 `active.json`。环境变量只在文件缺失或校验失败时才决定模式。

切换锁是 `xedc-storage-runtime/switch.lock` 上的非阻塞 `flock`。第二个进程拿不到锁。确认文字、15 分钟检查令牌、相同目标拒绝和缺失文件拒绝都在服务端。按钮禁用不是并发保护。

`StorageSwitchManager::execute()` 的顺序是：确认文字、目标、检查令牌、锁、拒绝相同目标、比较文件、暂停写入、复制缺失原件、校验 SHA-256、写入新版本、核对读写目标、记审计、释放锁。失败时把上一份状态写回去，状态改为 `rolled_back`，不删除刚复制的文件。`finally` 会清掉暂停标志并释放锁。

正常切换不执行 `drush cr`，不清 Redis，也不 reload PHP-FPM。
