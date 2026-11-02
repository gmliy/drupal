# S3 File System 与 Key 模块配置说明

检查日期：2026-11-02。

检查对象是活动配置、模块代码和本次请求的运行时 Settings，不是仅凭模块文档推测。配置来源：

- `s3fs.settings` 活动配置，当前没有配置覆盖。
- `xedc_core.storage` 中的供应商配置 `r2`。
- S3 File System `8.x-3.12` 的 `SettingsForm`、`S3fsService::getAmazonS3Client()` 和 `S3fsStream`。
- Key `8.x-1.22` 的插件定义和 Key 配置实体。
- `xedc_core` 的 `BootGuard` 运行时注入。

本次只做检查，没有修改配置、数据库、`settings.php` 或 `config/sync`。

## 当前结论

S3 File System `8.x-3.12` 和 Key `8.x-1.22` 都已启用。站点仍使用本地 `public://` 和 `private://`。对象存储接管没有打开。

S3FS 页面里的 Bucket 已经写成 `dclili`，但 Custom Host 没有开启。这份配置指向 AWS 默认地址，不是 Cloudflare R2。

R2 的 API 地址已经保存在对象存储配置 `r2`：

`https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com/`

CDN `https://cdn.gitxx.xyz` 还没有写入任何配置。

`XEDC_STORAGE_RUNTIME` 未设置。运行时没有注入 S3FS 配置，本地文件存储保持不变。

## S3 File System 后台字段

页面是 `/admin/config/media/s3fs`。

| 后台字段 | 当前值 | 建议值 | 原因 |
| --- | --- | --- | --- |
| Amazon Web Services Access Key | 未选择 | 留空 | S3FS 只有一对 Key 名称。运行时凭证由对象存储中心按供应商注入到 `$settings['s3fs.access_key']`，这个设置优先于这里的 Key。这里选择后，所有供应商会共用同一把 Access Key。 |
| Amazon Web Services Secret Key | 未选择 | 留空 | 同上。Secret 不能回填到这个页面。当前也没有任何 Key 实体。 |
| Custom Credentials File Location | 空 | 留空 | 3.12 只在 settings 和 Key 都没有密钥时才读这个 ini。本站不使用 AWS 共享凭证文件。 |
| Enable Credential Caching | 未勾选 | 暂不启用 | 当前没有 Doctrine `FilesystemCache`。保存表单时模块会强制把它写回关闭。R2 使用固定密钥，不需要向 AWS 元数据服务要临时凭证。 |
| Cached Credentials Directory | 空，控件禁用 | 留空 | 依赖上面的缓存，当前类不存在，无法启用。 |
| S3 Bucket Name | `dclili` | `dclili` | 与本次 R2 Bucket 一致。只保存这个值不会迁移文件，也不会切换 `public://`。 |
| Bucket Region | `us-east-1`，控件禁用 | 页面上无法改成 `auto` | 3.12 的 Region 不能手填。勾选 Custom Host 后点保存，代码会把 Region 写成 `us-east-1`。R2 签名区域应是 `auto`，这个值要由 `XEDC_STORAGE_RUNTIME=1` 且 `STORAGE_REGION=auto` 在运行时覆盖。现在运行时是关闭的。 |
| Enable CNAME | 未勾选 | 暂不启用 | CNAME 是公开访问域名，不是 API。现在勾选后，将来即使没设置 `STORAGE_CDN_ENABLED`，数据库里的 CNAME 仍会保留。 |
| CDN Domain Name | 空 | 暂不启用时留空；以后只填 `cdn.gitxx.xyz` | 3.12 这里要主机名，不带 `https://`。不能填到 Hostname。当前 CDN 还没写入配置，接管也没开，填了不会改变现有图片地址。 |
| Map Domain Name to specific path | `none` | 保持默认 | `none` 表示域名对应整个 Bucket。只有 CDN 源站路径明确指向 `s3fs-public` 时才改成 Public folder。 |
| Use a Custom Host | 未勾选 | 勾选 | R2 不是 AWS。不勾选时，SDK 会按 Region 去连 AWS。 |
| Hostname | 空 | `https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com` | 这是已经保存在配置 `r2` 里的 R2 S3 API。末尾斜杠可以去掉。不要填 `https://cdn.gitxx.xyz`。3.12 把这个字段原样当作 SDK 的 `endpoint`。 |
| Use path-style endpoint | 未勾选 | 勾选 | 配置 `r2` 和供应商目录都按 Path-style 连接 R2。请求形式是 `https://<账号>.r2.cloudflarestorage.com/dclili/...`。 |
| Disable version sync | 未勾选 | 勾选 | R2 不按 AWS 的方式提供 `ListObjectVersions`。配置 `r2` 已经关闭版本同步。勾选只是跳过版本列表，不删除对象。 |
| S3 Object Cache-Control Header | 空 | 留空 | 对象存储配置里的默认值是 `public, max-age=31536000, immutable`，但只有运行时设置了 `STORAGE_CACHE_CONTROL` 才会写入。现在没有对象上传，留空不影响本地文件。 |
| Server-Side Encryption | None | 留空 | 没有配置 R2 服务端加密。`aws:kms` 是 AWS 专用，不能选。 |
| Always serve files from S3 via HTTPS | 未勾选 | 勾选 | 只影响以后 `s3://` 生成的 URL。运行时启用后代码也会强制 HTTPS。不改变当前公共文件。 |
| Ignore the file metadata cache | 未勾选 | 暂不启用 | 模块说明写明生产环境不能开启，否则每次读取都访问存储。 |
| Bucket is read-only | 未勾选 | 暂不启用 | 勾选后上传校验会被跳过，后续也无法写入。 |
| Disable SSL/TLS verification | 未勾选 | 暂不启用 | R2 使用公网证书。运行时也会强制保持关闭。 |
| Disable Shared Config Files | 未勾选 | 勾选 | 避免 SDK 读取服务器上的 `~/.aws/config`。运行时启用后会强制开启。 |
| The TTL of the redirect cache to the s3 styles | `0` | 保持默认 | 图片样式仍在本地生成。接管未开时，这个 TTL 不参与现有页面。 |
| Use S3 for public:// files | 未勾选，控件禁用 | 暂不启用 | 实际值来自 Settings，当前是 `false`。图片处理仍依赖本地路径，不能为了让页面显示完整而打开。 |
| Custom CSS/JS Asset Host | 未勾选 | 暂不启用 | 只有 public 接管打开后才可见。当前不使用。 |
| Custom CSS/JS Hostname | 空 | 留空 | 同上。 |
| Twig compiled storage folder | 只读显示 | 保持默认 | 这不是 `s3fs.settings` 字段，保存不会写入。当前 Twig 仍在本地。 |
| Upload all files as private in S3 | 未设置，控件禁用 | 暂不在此页启用 | 控件不能保存。R2 不接受 `public-read` ACL，配置 `r2` 已记录为上传时不设置该 ACL。运行时启用后由代码设为开启，除非环境变量明确写成 `0`。 |
| Use S3 for private:// files | 未勾选，控件禁用 | 暂不启用 | Settings 当前是 `false`。私有文件仍在本地私有目录。 |
| Root Folder | 空 | 留空 | Bucket 根目录即可。填写不会移动已经存在的对象。 |
| Public Folder | `s3fs-public` | 保持默认 | 与配置 `r2` 一致。将来 public 接管后，公共文件会放在这个前缀下。 |
| Private Folder | `s3fs-private` | 保持默认 | 与公共前缀分开，和配置 `r2` 一致。 |
| Presigned URLs | 空 | 留空 | 受保护下载由对象存储中心单独签发，不使用这里的路径规则。 |
| Force Save As | 空 | 留空 | 没有需要强制下载的路径规则。 |
| Torrents | 空 | 留空 | R2 不使用这组规则，私有文件也不能走 Torrent。 |

保存 `/admin/config/media/s3fs` 时，Region 会被写成 `us-east-1`，无法在页面上保留 `auto`。不要靠保存 S3FS 页面来完成 R2 连接。Bucket、Endpoint、Path-style 和 Region 的有效值，应在运行时由对象存储配置覆盖。

## 对象存储配置 r2

这条配置还不是当前活动配置。

| 项目 | 当前值 |
| --- | --- |
| 配置 ID | `r2` |
| 供应商 | Cloudflare R2 |
| Bucket | 空。运行时需要 `STORAGE_BUCKET=dclili`，或在该配置中填写 `dclili` |
| Region | `auto` |
| API Endpoint | `https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com/` |
| Path-style | 开启 |
| CDN | 空 |
| 公共文件夹 | `s3fs-public` |
| 私有文件夹 | `s3fs-private` |
| Cache-Control | `public, max-age=31536000, immutable` |
| 关闭版本列表 | 是 |
| 上传时不设置 public-read | 是 |
| 环境变量名 | `STORAGE_ACCESS_KEY`、`STORAGE_SECRET_KEY`、`STORAGE_BUCKET` |
| Key 引用 | `access_key_id` 和 `secret_key_id` 都为空 |

这些环境变量当前都没有设置。

## Key 模块

| 检查项 | 结果 |
| --- | --- |
| 模块 | 已启用，`8.x-1.22`，兼容 Drupal 11 |
| File Provider | 已注册。它是 Key 内置插件，ID 为 `file`，没有单独的启用开关。另外还有 config、state、env 三种 Provider |
| 实际加密 | 没有。Encrypt 模块未安装。File Provider 只保存文件路径，文件内容是明文，权限 `0600`，目录在 Web 根目录之外 |
| 当前 Key 实体 | 0 个 |
| 配置 `r2` 的 `access_key_id` | 空 |
| 配置 `r2` 的 `secret_key_id` | 空 |
| Access Key ID 对应的 Key | 还没有。凭证保存后才会创建 `xedc_storage_r2_access`，Provider 为 file |
| Secret 对应的 Key | 同样还没有。保存后 ID 为 `xedc_storage_r2_secret` |
| 密钥文件 | `xedc-storage-keys/r2/` 里只有 `meta.json`，没有 access 或 secret 文件 |

`meta.json` 指定这套配置使用全局环境变量名 `STORAGE_ACCESS_KEY` 和 `STORAGE_SECRET_KEY`。

Access Key ID 和 Secret Access Key 应填写在对象存储中心该供应商的「API 凭证」，不要填进 S3FS 的 Key 下拉框，也不要写进 `s3fs.settings`。S3FS 的两个 Key 字段继续留空。

## 运行时状态

| 项目 | 当前值 |
| --- | --- |
| `XEDC_STORAGE_RUNTIME` | 未设置 |
| `s3fs.use_s3_for_public` | `false` |
| `s3fs.use_s3_for_private` | `false` |
| `s3fs.upload_as_private` | 未设置 |
| `s3fs.access_key` / `s3fs.secret_key` | 未注入 |
| `public://` | Drupal 本地公共文件 |
| `private://` | Drupal 本地私有文件 |
| `s3://` | S3FS 流包装器已注册，但没有被 public 或 private 接管 |

三个接管开关继续保持关闭。不要为了让 S3FS 页面显示配置完整而开启生产文件接管。
