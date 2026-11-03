# PHP-FPM 与 Drupal 容器生命周期

验收时间：2026-11-02 21:15–21:25 UTC。没有开启接管，没有重载 PHP-FPM，没有写入生产容器缓存。

## 结论

| 项目 | 结果 |
| --- | --- |
| CLI 内存编译，接管标志关闭 | PASS。公开包装器仍是 `PublicStream` |
| CLI 内存编译，仅该进程打开公开接管 | PASS。编译结果是 `XedcPublicS3fsStream` |
| 编译期间生产 `cache_container` | PASS。两行的 checksum 和 created 都没变 |
| 独立 PHP-FPM 容器编译 | BLOCKED |
| 生产 PHP-FPM 当前容器 | PASS（只读）。仍是本地包装器，五个变量不存在 |

CLI 编译不能代替 PHP-FPM 验收。下面三项分开记录。

## 源码依据

容器缓存键在 `web/core/lib/Drupal/Core/DrupalKernel.php` 的 `getContainerCacheKey()`。组成是环境名、Drupal 版本哈希、`deployment_identifier`、`PHP_OS` 和 `container_yamls`。`s3fs.use_s3_for_public` 和 `STORAGE_*` 都不在键里。改环境变量不会让旧缓存失效。

缓存后端是数据库。`defaultBootstrapContainerDefinition()` 把 `cache.container` 定义为 `Drupal\Core\Cache\DatabaseBackend`，bin 名是 `container`。`getCachedContainerDefinition()` 用上面的键读取。站点里 `sites/default/files/php` 只有 Twig，不是服务容器。

`initializeContainer()` 在模块列表为空且没有强制重建时直接加载这条缓存。没有缓存时才调用 `compileContainer()`。`compileContainer()` 只有在 `allowDumping` 为真时才把 `containerNeedsDumping` 设为真。真正写入发生在 `initializeContainer()` 末尾的 `cacheDrupalContainer()`。单独调用 `compileContainer()` 不会写缓存。本轮两次编译都先把 `allowDumping` 设为假。

`S3fsServiceProvider::alter()` 在编译时读取 `Settings::get('s3fs.use_s3_for_public')`，为真才把 `stream_wrapper.public` 换成 `PublicS3fsStream`。`XedcCoreServiceProvider::alter()` 在其后执行，同样只在这个标志为真时换成 `XedcPublicS3fsStream`。包装器类因此只在编译时注册。

环境变量由 `BootGuard::env()` 和 `flag()` 读取。`settings.php` 引入 `xedc_storage.runtime.php`，每个请求在容器加载前执行一次。`flag()` 只接受精确字符串 `1`。这是请求阶段读取。服务提供者在编译阶段再读已经写入的 Settings。请求后半段不能改写已经编译好的类。

## 七个问题

1. PHP-FPM 重启不会自动重建容器。新 worker 仍执行 `initializeContainer()`，缓存存在就加载同一行。本轮没有重启。FPM 日志里最后一次 reload 是 17:05:27，早于本轮。
2. 环境变量在请求阶段读取，然后进入 Settings。包装器类在编译阶段读取 Settings。两边不是同一次读取。
3. `XedcPublicS3fsStream` 只在容器编译且 `s3fs.use_s3_for_public` 为真时注册。生产缓存编译时该标志为假，所以生产类仍是 `PublicStream`。
4. 会。新 worker 使用数据库里的旧容器。本轮无环境变量的编译结果是 `PublicStream`，与当前进程 `stream_wrapper_manager` 的类一致。
5. 模拟方式是单独的 CLI 进程：进程环境变量只作用于该进程，`allowDumping` 设为假，调用 `compileContainer()` 后丢弃 builder。没有独立容器缓存目录，因为 Drupal 11 的容器缓存是数据库 bin，不是文件目录。不能把生产 bin 指到别处而不改生产设置。
6. 证明方式是读取编译结果里 `stream_wrapper.public` 的类名。接管进程的结果是 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。无接管进程的结果是 `Drupal\Core\StreamWrapper\PublicStream`。
7. 多 worker 混用本轮 NOT TESTED。源码上所有 worker 读取同一条 `cache_container`。只有一次重建写入之后，已经开始的请求仍持有旧容器，之后的请求才使用新容器。本轮没有写入，因此不存在两份定义。没有做“重建同时打并发请求”的实验。

## 三次测试的边界

CLI 容器编译：已执行。脚本是 `web/modules/custom/xedc_core/tests/manual/r2-fpm-wrapper-io-test.php`，以及一次无环境变量的 `drush php:eval`。

独立 PHP-FPM：BLOCKED。现有 pool 是 `/etc/php/8.5/fpm/pool.d/www.conf`，socket 是 `/run/php/php8.5-fpm.sock`。要让 FPM 看到接管变量，必须改 pool 或另建 pool，然后 reload。这两件事都在本轮禁止范围内。没有启动第二个 FPM。

生产 PHP-FPM：只读确认。master pid 32897，worker pid 73147、92746、103200 的环境里都没有 `XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED`。无环境变量的 Drupal 进程里公开类是 `PublicStream`，私有类是 `PrivateStream`。

## 编译中发现并修复的签名错误

第一次带接管标志的编译在加载类时致命：`XedcPublicS3fsStream::dir_rewinddir()` 与 `S3fsStream::dir_rewinddir(): bool` 不兼容。这个错误如果留到正式接管编译，容器会直接编译失败。已补上 `: bool`。该次致命发生在 `allowDumping` 已设为假之后，并且发生在 `cacheDrupalContainer()` 之前。随后的成功编译再次核对缓存行未变。
