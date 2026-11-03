# R2 接管架构审计

审计时间：2026-11-02。生产开关没有打开，PHP-FPM 没有重载。结论来自当前安装的 Drupal 11 和 S3 File System 8.x-3.12 源码，不是模块文档的转述。

## 问题 A：Stream Wrapper 和容器生命周期

`public://` 的实现类写在服务容器里，不在每次请求的环境变量里。

- `Drupal\Core\StreamWrapper\StreamWrapperManager` 从带 `stream_wrapper` 标签的服务创建包装器。类名由编译期的 `StreamWrapperClassesPass` 收集。
- `Drupal\s3fs\S3fsServiceProvider::alter()` 只在容器编译时读取 `Settings::get('s3fs.use_s3_for_public')`。为真时，把 `stream_wrapper.public` 的类从 `PublicStream` 换成 `PublicS3fsStream`。
- `DrupalKernel` 的容器缓存键包含模块列表、部署标识和 PHP 版本，不包含 `s3fs.use_s3_for_public`。环境变量变化不会使已编译容器失效。
- `BootGuard::apply()` 在 `settings.php` 里执行，早于容器启动。它以前把“配置数组验收通过”写成 `xedc_storage.runtime_applied=true`。那只能说明 Settings 里有凭证和标志，不能说明这个进程的 `public://` 已经是 S3。
- 因此会出现：PHP-FPM 读到了接管变量，BootGuard 不报错，生成的地址仍是 `/sites/default/files/...`，上传仍进本地磁盘。

运行时不能安全地替换已经编译进容器的包装器。可靠做法是部署时接管：先设 PHP-FPM 环境变量并重载，再由一个带这些变量的 FPM 请求重新编译容器，然后在同一次 FPM 请求里核对包装器类。只在 CLI 里执行 `drush cr` 会按 CLI 环境编译，不能代替 FPM。本轮没有执行这个切换。

## 问题 B：状态被误报为成功

`runtime_applied` 原先在凭证和 Bucket 校验通过时就为真，即使 `STORAGE_PUBLIC_TAKEOVER` 没开，也即使包装器仍是 `PublicStream`。管理页把这个值显示成“运行时连接已启用”。

需要分开的状态：

| 状态 | 能在 settings.php 知道吗 | 含义 |
| --- | --- | --- |
| runtime_requested | 能 | 环境变量 `XEDC_STORAGE_RUNTIME=1` |
| runtime_eligible / settings_accepted | 能 | 凭证、Bucket、Endpoint 通过了 BootGuard 校验 |
| stream_wrapper_active | 不能，要等容器 | 当前类是不是 S3FS |
| actual_write_destination | 不能，除非有写入探针 | 没有探针就是未验证 |
| generated_public_url | 不能，要等 URL 生成 | 本地、CDN，或意外的 API 主机 |
| cdn_rewrite_active | 能看标志，不能当成页面已改写 | `STORAGE_CDN_ENABLED` |
| runtime_applied | 只有包装器类已经是对象存储才为真 | 不再表示“配置已接受” |

## 问题 C：公开 URL 会落到 R2 API

`S3fsStream::getExternalUrl()` 在没有 `use_cname` 时调用 `getObjectUrl()`。当前 path-style 客户端会得到：

`https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com/dclili/s3fs-public/...`

匿名请求这个地址返回 400。打开 `use_cname` 且同时开启 path-style 时，S3FS 还会把 Bucket 名加进路径，变成 `https://cdn.gitxx.xyz/dclili/s3fs-public/...`。已经迁移的对象实际地址是 `https://cdn.gitxx.xyz/s3fs-public/...`，没有 Bucket 段。所以不能把 CDN 域名填进 API Endpoint，也不能单靠 S3FS 的 CNAME 开关。

`xedc_core` 已有 `CdnUrlPolicy`。它只在 CDN rewrite 和“URI 已被当成远程”同时成立时改写，而且以前的远程判断看的是 Settings 标志，不是包装器类。

## 问题 D：图片样式

约 768 个衍生文件在 `sites/default/files/styles/`。Nginx 用 `try_files` 直接返回这些本地文件。R2 上样式对象数量是 0。

接管后如果沿用 S3FS 默认地址：本地还不存在于 R2 的样式会变成 `/s3/files/styles/...`，已存在于 R2 的对象会变成 API 或 CDN 地址。现有页面上的 `/sites/default/files/styles/...` 链接会失效。

选定的兼容方案是 A/B：图片样式继续留在本地文件系统。原图在将来的接管中进入 R2，并用 CDN 地址。新生成的样式也写回本地 `styles/`，不把这 768 个文件改到 R2。

## 私有文件

9 条 private ZIP 只有数据库记录。Private takeover 必须继续关闭。私有 URI 不能变成 CDN 地址。
