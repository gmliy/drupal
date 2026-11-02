# 对象存储

本文描述当前 Drupal 11 站点的 S3 兼容对象存储架构。实现位于自定义模块 `xedc_core`，底层使用已经安装的 S3 File System `8.x-3.12` 和 AWS SDK for PHP `3.402.5`。没有修改第三方模块代码，也没有另装一套 S3 模块。

后台入口：管理 → 配置 → 媒体 → 对象存储。

## 1. 整体架构

系统分成三层，不能混用：

| 层 | 作用 | 改动是否移动文件 |
| --- | --- | --- |
| 供应商配置 | 保存 Endpoint、Region、Bucket 名称和环境变量名 | 否 |
| 运行时连接 | `settings.php` 按环境变量覆盖 S3 File System | 否，除非另外打开接管开关 |
| 文件迁移 | 先做预检查；当前版本不复制文件、不改 URI | 否 |

现有文件管理中心继续负责 Drupal 文件实体、引用、重复文件、孤立文件和 14 天隔离。对象存储中心不扫描全桶，也不把对象列表当成可以删除的依据。

`s3://` 文件在文件管理中心里记为 `remote`。它们不会因为没有本地 `realpath()` 而被当成孤立文件或丢失文件。

## 2. 当前文件系统

当前生产文件仍在 Drupal 本地文件系统：

- `public://`：默认 `sites/default/files`。美图、视频媒体和图片样式都使用这个方案。
- `private://`：`/var/www/drupal/private`。导入包和隔离目录在这里。
- `temporary://`：PHP 临时目录。策略固定为本地。

S3 File System 已启用，但 `s3fs.settings` 里的 Bucket 仍为空。默认不设置 `s3fs.use_s3_for_public` 或 `s3fs.use_s3_for_private`。

图片样式衍生物由 Drupal 生成，多个样式包含 WebP 转换。视频原文件来自 Media 的 `field_media_video_file`，字段方案是 `public`。文章没有单独的私有附件字段。

`xedc_core` 的图片导入、WebP 处理和压缩包导入使用本地 `realpath()`。在这些代码改为流式处理之前，不能打开 `public://` 或 `private://` 接管，否则导入和图片处理会找不到文件。

## 3. S3 File System 配置方式

`8.x-3.12` 只维护一个活动连接。凭证来自 `settings.php`：

- `$settings['s3fs.access_key']`
- `$settings['s3fs.secret_key']`
- `$settings['s3fs.use_s3_for_public']`
- `$settings['s3fs.use_s3_for_private']`
- `$settings['s3fs.upload_as_private']`

Bucket、Region、自定义主机、Path-style、CNAME 和 Cache-Control 使用 `$config['s3fs.settings']` 覆盖。这些覆盖由 `web/modules/custom/xedc_core/xedc_storage.runtime.php` 写入内存，不写回配置数据库，也不写进 Git。

模块自己的配置页仍在 `/admin/config/media/s3fs`。对象存储中心不重复做它的 public-read ACL 校验，因为该校验不适合禁用 ACL 的 Bucket。

普通 Drupal 上传走 S3 File System 的流包装器。`3.12` 的 `stream_flush()` 调用 AWS SDK 的 `putObject()`，不是 `MultipartUploader`。大文件分片没有替换现有媒体上传。

## 4. 环境变量

示例文件是仓库根目录的 `.env.example`，只有占位符。真实值放在服务器环境或未提交的 `.env.local`。

只有 `XEDC_STORAGE_RUNTIME=1` 且 Bucket、Region、Access Key、Secret Key 都有效时，运行时连接才会启用。任一缺失，Drupal 继续使用本地文件，并在对象存储概览显示原因。

开关只认字符串 `1`。`true`、`yes` 都不会启用。

| 变量 | 含义 |
| --- | --- |
| `STORAGE_PROVIDER` | 当前运行时供应商标识 |
| `STORAGE_ACCESS_KEY` / `STORAGE_SECRET_KEY` | S3 API 凭证 |
| `STORAGE_BUCKET` / `STORAGE_REGION` / `STORAGE_ENDPOINT` | Bucket、区域、API 地址 |
| `STORAGE_PATH_STYLE` | `1` 为 Path-style，否则 Virtual-hosted |
| `STORAGE_PUBLIC_BASE_URL` | 公开访问地址，不作为 API Endpoint |
| `STORAGE_CDN_ENABLED` / `STORAGE_CDN_BASE_URL` | 只有启用时才把域名交给 S3 File System 的 CNAME |
| `STORAGE_PUBLIC_ENABLED` / `STORAGE_PRIVATE_ENABLED` | 策略标记，不接管文件系统 |
| `STORAGE_PUBLIC_TAKEOVER` / `STORAGE_PRIVATE_TAKEOVER` | 接管开关 |
| `STORAGE_I_UNDERSTAND_TAKEOVER` | 接管时必须同时为 `1` |
| `STORAGE_UPLOAD_AS_PRIVATE` | 默认私有 ACL；设为 `0` 才上传 public-read |
| `STORAGE_DISABLE_VERSION_SYNC` | 默认不调用 ListObjectVersions |
| `STORAGE_ALLOW_LOOPBACK` | 允许本机 HTTP Endpoint |
| `STORAGE_ALLOW_PRIVATE_ENDPOINT` | 允许内网 Endpoint |

连接测试可以读取每份配置自己的变量名，例如 `STORAGE_PROFILE_CLOUDFLARE_R2_ACCESS_KEY`。测试变量不会自动变成运行时连接。

## 5. 供应商示例

下面的地址是各平台文档中的常见形式。保存前用该平台控制台里的实际值替换。系统没有把某一家的 Endpoint 写死。

### Cloudflare R2

- Endpoint：`https://<ACCOUNT_ID>.r2.cloudflarestorage.com`
- Region：`auto`
- 凭证：R2 的 S3 API Access Key 和 Secret Key
- Path-style：通常开启
- 版本列表：通常关闭
- 公开域名：自定义域名或 R2 公共访问域名，不能使用上面的 API Endpoint

### Amazon S3

- Endpoint：留空，使用所选 Region 的 AWS 默认地址
- Region：例如 `us-east-1`
- Path-style：通常关闭
- 版本列表：S3 支持时可以设 `STORAGE_DISABLE_VERSION_SYNC=0`

### Wasabi

- Endpoint：`https://s3.<region>.wasabisys.com`，以 Wasabi 控制台为准
- Region：例如 `us-east-1`

### Backblaze B2

- Endpoint：`https://s3.<region>.backblazeb2.com`
- Region：Bucket 详情里的 S3 Region，例如 `us-west-004`
- 凭证：B2 应用密钥，不是主账号密钥

### DigitalOcean Spaces

- Endpoint：`https://<region>.digitaloceanspaces.com`
- Region：例如 `nyc3`
- 公开域名和 API Endpoint 分开配置

### Vultr Object Storage

- Endpoint：`https://<region>.vultrobjects.com`
- Region：以 Vultr 控制台为准

### MinIO

- Endpoint：MinIO 的 HTTPS 地址
- Region：可填 `us-east-1`
- Path-style：通常开启
- 本机 `http://127.0.0.1:9000` 只有在 `STORAGE_ALLOW_LOOPBACK=1` 时才能通过检查

### 自定义 S3 Endpoint

选择“自定义 S3 Endpoint”，填写该服务官方给出的 Endpoint、Region 和路径风格。不要套用另一家的 Region 或 URL。

## 6. 新增供应商

1. 打开对象存储 → 供应商，选择对应平台并添加配置。
2. 填写 Region、API Endpoint 和 Bucket 名称。Amazon S3 的 Endpoint 可以留空。
3. 只填写环境变量名称。页面没有 Secret Key 输入框。
4. 把密钥放到服务器环境，不要写入配置或 Git。
5. 保存。此时当前运行时连接和已有文件位置都不变。

## 7. 测试连接

在供应商页面对某一份配置执行“测试连接”。测试只在 `_xedc_storage_test/` 前缀写入一个小对象，然后读取、核对 SHA-256、大小和 MIME，并删除该对象。

没有密钥时结果是“待凭证验证”，不会访问供应商。Endpoint 会拒绝元数据地址、内嵌账号密码、以及未明确允许的本机或内网地址。

“检测能力”额外检查列表、复制、分片创建后立即中止、预签名、元数据、Cache-Control、版本控制、生命周期、对象锁和 CORS。检测失败不会关闭基本读写，也不会修改 Bucket 策略。未授权被记为“未验证”，不会被当成“不支持”。

## 8. 更换供应商、Bucket 和文件位置

更换当前配置只改变对象存储中心里的选定记录。

要让 S3 File System 使用新连接，需要更新 `STORAGE_*` 环境变量。即使这样，只要接管开关不是同时打开，`public://` 和 `private://` 仍指向本地磁盘。已有 `s3://` URI 会随着运行时连接指向新的 Bucket，所以在已经存在 `s3://` 文件时，不能把切换环境变量当成无影响操作。

更换 Bucket 同样不移动对象。旧 Bucket 里的对象要靠迁移复制，而不是靠改配置。

## 9. 迁移和校验

迁移页面的预检查会统计所选来源的文件数量和大小，并抽样最多 20 个本地文件。它不复制、不删除、不改 URI，也不会把任务标成完成。

当前还没有开放批量复制、断点续传和 URI 切换。任务记录保留这些限制：`copied = 0`、`uri_rewritten = false`、`source_retained = true`、`verified = false`。

以后的复制必须满足：

1. 先有预检查。
2. 先做小批量。
3. 源和目标都计算 SHA-256。大小相同但哈希不同视为失败。
4. ETag 只作记录。带连字符的 ETag 是分片标记，不能当作 MD5。
5. 校验失败不删除源文件。
6. 源文件保留到人工确认之后。
7. 确认前不批量修改文件 URI。

## 10. 回滚

当前架构下的回滚是：

1. 把 `XEDC_STORAGE_RUNTIME` 设为 `0`，或移除凭证。
2. 确认 `STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER` 和 `STORAGE_I_UNDERSTAND_TAKEOVER` 都不是 `1`。
3. 重建 Drupal 容器缓存：`drush cr`。
4. 不需要改文件 URI，因为生产 URI 没有被替换。

如果将来已经复制并切换了 URI，回滚要使用迁移记录逐个改回，并在改回前重新做 SHA-256。当前版本还没有这个切换步骤。

## 11. CDN

CDN 页面保存图片域名、视频域名、基础地址和 Cache-Control。这些值不会自动替换已发布图片。

三个地址必须分开：

- API Endpoint：服务器上传和签名使用。
- Bucket：对象所在容器。
- 公开域名：浏览器访问使用，必须是 HTTPS。

只有 `STORAGE_CDN_ENABLED=1` 且 `STORAGE_CDN_BASE_URL` 是合法 HTTPS 地址时，运行时才把域名写入 S3 File System 的 CNAME。公开域名不包含 API 密钥。

缓存：

- 对象可以带 `Cache-Control`。不可变资源适合较长的 `max-age`，但文件内容变化后需要新的 URL 或主动刷新。
- 浏览器缓存和 CDN 缓存分别记录。
- 删除对象后，CDN 上的旧副本要按该 CDN 的方式失效。当前失效方式是手动。
- `Content-Type` 由上传时的 MIME 决定。ETag 由供应商生成，不作为跨平台校验。

规划中的 `img.gitxx.xyz` 和 `media.gitxx.xyz` 还没有接入，不能视为已经生效。

## 12. 公共文件和私有文件

存储策略页只记录意图：

| 内容 | 默认策略 |
| --- | --- |
| 美图封面、缩略图、视频封面 | 公共对象存储 |
| 美图原图、文章附件 | 保持字段当前方案 |
| 视频文件、ZIP 原图包、VIP 下载 | 私有对象存储 |
| 临时文件 | 本地，不能改成远程 |

现有字段仍是 `public` 或本地 `private`。策略保存不会移动它们。

私有对象以后使用 `private://` 接管，或使用位于私有文件夹下的 `s3://` 键。受保护下载入口是 `/xedc/storage/protected/{fid}`：

- 匿名用户直接拒绝。
- 已登录用户还需要 `download xedc protected files` 或对象存储管理权限。
- 只为受保护对象生成预签名地址，默认 120 秒，最长 300 秒。
- 本地 `private://` 在接管关闭时不会被这个入口读取，因此不会绕过 Drupal 原有私有下载。
- 公开文件不会被这个入口签成永久公开地址。

站点目前没有单独的 VIP 角色。VIP 权限要授予上述权限，不能只在前端隐藏按钮。该权限默认不授予普通登录用户。

## 13. 密钥

每份供应商配置有自己的凭证。解析顺序是：

1. 这份配置的后台凭证。
2. 这份配置专属的环境变量。
3. 全局 `STORAGE_ACCESS_KEY` 和 `STORAGE_SECRET_KEY`，只有在该配置明确使用全局变量名时才采用。

没有选中供应商配置时，运行时仍按原来的全局环境变量工作。某一份配置的后台凭证不完整时，不会悄悄改用环境变量或其他供应商的密钥。

后台凭证使用 Drupal Key 1.22 的 File Provider：

- `xedc_core.storage` 只保存 Key 实体 ID，例如 `xedc_storage_profile_access`。
- Key 配置只保存文件路径，不保存密钥。
- 密钥文件在 `file_private_path/xedc-storage-keys/{配置 ID}/`，位于 Web 根目录之外，权限为 `0600`。
- 这是明文文件，不是加密存储。当前没有启用 Encrypt 模块，也不能把数据库或配置存储说成加密。
- Key 的 Configuration Provider 和 State Provider 会把密钥放进数据库，因此没有使用。
- 页面不回填 Secret Access Key。Access Key ID 只显示脱敏片段。
- 清除凭证需要勾选确认，并删除对应的 Key 实体和密钥文件。
- 日志只记录供应商 ID 和结果，不记录密钥、Authorization 或签名。

`XEDC_STORAGE_RUNTIME=0` 时不读取这些凭证，本地文件系统保持不变。`XEDC_STORAGE_RUNTIME=1` 时，S3 File System 3.12 仍通过 `$settings['s3fs.access_key']` 和 `$settings['s3fs.secret_key']` 接收当前配置的凭证。这两个值只在进程内存中，不写入 `s3fs.settings`。S3 File System 只有一对 Key 名称，所以这里不把某一份配置写进 `s3fs.settings` 的 `keymodule`，避免其他配置串用。

切换“当前配置”只改变以后运行时要使用的凭证归属，不移动文件，也不改 URI。Bucket、Region 和 Endpoint 的运行时值仍来自环境变量。

连接测试只在 `_xedc_storage_test/` 下创建临时文本对象，并尝试列表、上传、读取和删除。失败时不会删除业务文件。

- 凭证应限制为单一 Bucket 的 `ListBucket`、`GetObject`、`PutObject`、`DeleteObject`。版本、分片和复制权限按实际检测需要追加。
- 测试凭证和生产凭证分开。
- `.env`、`.env.local` 和 `/private/` 已在 `.gitignore` 中。

## 14. 删除保护和生命周期

对象存储中心不能删除业务对象。清理操作只接受 `_xedc_storage_test/` 前缀，并且未完成分片至少保留 24 小时。Cron 清理默认关闭。

文件管理中心的隔离期仍是 14 天。未知引用不能当作无引用。对象存储列表不能单独作为删除依据。

版本控制、生命周期规则和对象锁只在能力检测里读取。检测不会创建这些规则，因为不是每个 S3 兼容平台都支持它们。

## 15. 监控和成本

监控读取 Drupal 文件实体的数量、容量和 MIME 分类，并缓存 15 分钟。上传、读取和删除计数只包括对象存储中心自己的测试，不是全站流量，也不是供应商账单。

费用页使用自行填写的单价。下载流量显示“未采集”。估算金额永远不等于供应商账单。没有账单接口的平台不会显示实时账单。

## 16. 大文件

S3 File System `3.12` 的普通写入是单次 `PutObject`。AWS SDK 另有 `ObjectUploader`，超过阈值后会分片，但当前媒体上传没有改用它。

能力检测会创建并立即中止一次分片上传，用来确认 API。它不改变图片、视频或 ZIP 的上传流程。未完成分片不会被无限期保留；自动清理默认关闭，手动清理也只作用于测试前缀。

上传大小限制仍由 Drupal 和 PHP 的现有限制决定。

## 17. 兼容性

已经确认会受本地路径影响、因此本次没有改存储位置的代码：

- `ImagePipeline` 的 WebP 和图片校验。
- `ArchiveManager` 的导入包。
- 文件清理扫描中的本地 `realpath()`。

本次只把 `s3://` 标成远程文件，避免将来的对象被误判为丢失或可删除。Media、图片样式、Redis 缓存和现有公共 URL 都没有切换。

如果 Nginx 将来要接管 S3 图片样式，需要为 `/s3/files/styles/` 添加 S3 File System 文档中的 `try_files` 规则。当前没有这项需求，Nginx 配置没有改。

## 18. 常见问题和恢复

| 现象 | 处理 |
| --- | --- |
| 概览提示凭证缺失 | 补齐环境变量；网站会继续使用本地文件 |
| 保存供应商后文件仍在本地 | 这是预期结果 |
| R2 拒绝 public-read | 保持 `STORAGE_UPLOAD_AS_PRIVATE=1`，用 Bucket 策略或自定义域名公开读取 |
| ListObjectVersions 失败 | 保持 `STORAGE_DISABLE_VERSION_SYNC=1` |
| 预签名或 HeadBucket 返回 403 | 记为权限未验证，不判断为平台不支持 |
| 测试对象删除失败 | 只在 `_xedc_storage_test/` 下人工确认后删除 |
| 打开接管后图片消失 | 立即关闭三个接管变量并执行 `drush cr`；先确认本地文件还在 |

不要在迁移失败时删除源文件，也不要因为对象存储里没有某个键就删除 Drupal 文件实体。
