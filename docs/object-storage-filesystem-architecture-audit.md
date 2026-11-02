# Drupal 文件系统与 S3FS 架构审计

审计时间：2026-11-02。只读检查，未修改文件系统配置、S3FS 配置、运行时开关、File Entity URI、文件或数据库。未输出 Access Key 或 Secret Key。

## 结论

当前管理文件存储的是 Drupal 原生本地文件系统。S3 File System 已安装并保存了桶和目录前缀，但没有接管 `public://` 或 `private://`。`xedc_core` 保存了供应商、策略和 CDN 规则，并提供迁移与受保护下载代码，但运行时未启用，所以它还没有成为实际的存储管理器。

## 1. Drupal 原生文件系统

| 项目 | 当前值 |
| --- | --- |
| Public file system path | 未在 `settings.php` 中覆盖，实际目录 `/var/www/drupal/web/sites/default/files` |
| Private file system path | `/var/www/drupal/private` |
| Temporary file system path | 配置为空，实际为 `/tmp` |
| Default download method | `system.file` 的 `default_scheme` 为 `public` |
| `public://` | `Drupal\Core\StreamWrapper\PublicStream` |
| `private://` | `Drupal\Core\StreamWrapper\PrivateStream` |
| `temporary://` | `Drupal\Core\StreamWrapper\TemporaryStream` |

File Entity 共 137 条：`public://` 128 条，本地文件全部存在；`private://` 9 条，本地文件全部缺失。没有 `s3://` 记录。

职责：保存站点文件、图片样式衍生文件、ZIP 导入工作目录和会员私有文件 URI。新上传仍写入本地 `public://` 或 `private://`。

## 2. S3 File System

模块已启用，版本 8.x-3.12（Composer `drupal/s3fs` 3.12.0）。

| 项目 | 当前值 |
| --- | --- |
| Bucket | `dclili` |
| Region | `us-east-1` |
| Endpoint | 空。`use_customhost` 为 false，`hostname` 为空，`use_https` 为 false |
| 公共文件接管 | 关闭。`s3fs.use_s3_for_public` 为 false |
| 私有文件接管 | 关闭。`s3fs.use_s3_for_private` 为 false |
| 公共目录 | `s3fs-public` |
| 私有目录 | `s3fs-private` |
| 根目录 | 空 |
| CNAME / 自定义域名 | `use_cname` 为 false，`domain` 为空 |
| 默认缓存头 | 空 |
| Key 模块中的 Access / Secret 名称 | 均为空 |

`s3://` 由 `Drupal\s3fs\StreamWrapper\S3fsStream` 注册，但没有任何业务文件使用它。S3FS 只有在对应 Settings 为 true 时才会把 `public://` 换成 `PublicS3fsStream`、把 `private://` 换成 `PrivateS3fsStream`。这两个 Settings 当前都是 false。

职责：提供 S3 兼容流包装器。它现在是已安装的底层能力，不是当前文件存储。

## 3. xedc_core

活动配置 `silly`，标签 Cloudflare R2，供应商 `cloudflare_r2`。

| 项目 | 当前值 |
| --- | --- |
| Bucket | `dclili` |
| Region | `auto` |
| Endpoint 主机 | `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com` |
| Path style | 是 |
| 公共 / 私有前缀 | `s3fs-public` / `s3fs-private` |
| 公开基址 | `https://cdn.gitxx.xyz` |
| CDN 记录 | `cdn.enabled` 为否，`cdn.base_url` 为空 |
| 运行时 | `XEDC_STORAGE_RUNTIME` 未启用，`runtime_applied` 为否，启动检查为 ok |
| 凭证 | 后台文件库已配置。来源是后台凭证，不是 S3FS 的 Key 字段 |

内容策略：封面、缩略图、视频海报为 public；视频文件、ZIP、VIP 下载为 private；原图和文章附件继承；临时文件保持本地。预签名有效期 120 秒，CDN 清理方式为 manual。

`BootGuard` 在运行时未请求时直接返回，并显式把两个 S3FS 接管开关设为 false。因此 xedc 的 endpoint、region 和凭证不会进入当前请求。

## 4. 功能状态

| 功能 | 状态 | 说明 |
| --- | --- | --- |
| Storage Provider | 已配置但未启用 | 活动供应商已保存，没有接管文件流 |
| Storage Runtime | 已配置但未启用 | 启动代码存在且检查通过，运行时未应用 |
| Storage Adapter | 部分实现 | 远程读写和本地桥接可用于测试、迁移和远程 URI；正常上传不经过它 |
| CdnUrlPolicy | 已实现，未启用 | 改写条件已编码，CDN 改写开关关闭 |
| DeliveryUrl | 已实现，未启用 | 当前仍返回本地 URL；私有和临时地址不会改成 CDN |
| CachePolicy | 已实现，未启用 | 规则存在，未写到生产对象；S3FS 缓存头也为空 |
| 文件上传 | 部分实现 | 图片导入写入本地 `public://`。远程写入只用于隔离测试和迁移执行器 |
| 图片样式 | 部分实现 | 15 个样式目录、衍生文件在本地 `public://styles`。远程样式构建器未进入线上路径 |
| 视频上传 | 部分实现 | 策略定为 private，线上视频仍是本地文件。远程视频验证只在测试前缀中做过 |
| ZIP 解压 | 部分实现 | 导入、解压和打包使用本地 `private://`。远程 ZIP 只在隔离测试中验证 |
| 会员私有文件访问 | 部分实现 | 权限门和预签名入口已实现。运行时关闭时入口拒绝生成地址，并且不读取本地私有文件 |
| 文件迁移中心 | 部分实现 | 预检查和复制代码存在。复制未执行，接管开关关闭 |

## 5. 六个确认项

1. Drupal 原生文件系统仍使用本地存储。公开文件在站点 files 目录，私有目录已配置，临时文件在 `/tmp`。
2. S3FS 当前只是底层对象存储能力。模块启用了，桶和前缀已填写，但没有成为 `public://` 或 `private://` 的流包装器。
3. `xedc_core` 设计上统一管理供应商、前缀、CDN、缓存和迁移，但运行时关闭，所以它还没有统一管理实际存储。
4. 没有多个组件同时接管 `public://` 或 `private://`。两者都是 Drupal 核心流。`s3://` 单独存在，未被业务使用。
5. 将来在本地、Cloudflare R2 和其他 S3 兼容 OSS 之间切换时，不需要修改 Drupal 的 public path、private path、temporary path 或默认 scheme。切换应通过 xedc 活动供应商和 `BootGuard` 的运行时开关，让 S3FS 替换流包装器，同时保持现有 `public://`、`private://` URI。直接修改原生路径会使现有 URI 失去本地落点。
6. 存在配置重复和将来互相覆盖的风险，当前因为接管关闭而没有生效。

## 6. 配置冲突

以下值重复保存：

- Bucket `dclili` 同时在 `s3fs.settings` 和 xedc 活动配置中。
- 前缀 `s3fs-public`、`s3fs-private` 同时在两处。
- CDN 主机只写在 xedc 的 `public_base_url`。xedc 的 `cdn.base_url` 为空，S3FS 的 domain 也为空。

以下值不一致：

- Region：S3FS 是 `us-east-1`，xedc 是 `auto`。
- Endpoint：S3FS 没有自定义主机且未启用 HTTPS；xedc 保存了 R2 的 HTTPS endpoint。
- 凭证：xedc 后台文件库已配置；S3FS Key 字段为空。

如果绕过 xedc、直接在 `settings.php` 打开 S3FS 接管，S3FS 会使用自己的 region、空 endpoint 和空凭证，公开文件会离开本地目录。xedc 运行时开启时，`BootGuard` 会在内存中覆盖 S3FS 连接值，但数据库里的 `s3fs.settings` 仍保留现在这套不一致的值。

URI 风险：现有 URI 都是 `public://` 和 `private://`，与将来由 S3FS 接管后的 scheme 兼容。把文件改成 `s3://`，或把 CDN 域名写入 S3FS domain，都会和 xedc 的 DeliveryUrl 规则冲突。9 条私有 URI 在本地已经没有文件，迁移前不能视为可复制对象。

## 7. 应保持原样的配置

- `settings.php` 的 `file_private_path`。
- 未设置的 public path 和 temporary path。
- `system.file` 的 `default_scheme: public`。
- `s3fs.use_s3_for_public` 和 `s3fs.use_s3_for_private` 保持 false。
- S3FS 的 `use_cname`、domain、hostname 保持关闭和空白，不要把 `cdn.gitxx.xyz` 填进去。
- `XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED` 保持关闭。
- 全部 137 条 File Entity URI。

## 8. 未来存储管理中心应管理的配置

由 `xedc_core` 管理：

- 活动供应商、Bucket、Region、Endpoint、path style。
- 公共和私有对象前缀。
- 凭证引用，不把密钥写入 S3FS 配置或报告。
- CDN 基址、是否改写，以及公开、私有、临时地址的分流。
- 未版本化、版本化和私有对象的缓存策略。
- 内容策略：图片、视频、ZIP、会员下载分别进入公开、私有或本地临时区。
- 迁移预检查、复制、校验和回滚条件。
- 运行时与接管开关的启用条件。开关本身继续由环境变量控制，不由普通表单单独打开。

继续由 Drupal 原生文件系统管理：

- `public://`、`private://`、`temporary://` 这三个 scheme 名称。
- 本地 public、private、temporary 目录，作为未接管时的落点和回滚位置。
- 默认下载 scheme。

继续由 S3FS 管理：

- 在 xedc 明确打开接管之后，替换 `public://` 和 `private://` 的流包装器。
- `s3://` 底层客户端。

S3FS 数据库中的 region、endpoint、域名和凭证字段不应再作为第二套人工配置维护。

## 9. 推荐架构

```text
业务代码继续使用 public:// 和 private://
        |
        v
xedc_core 决定供应商、前缀、CDN、缓存和权限
        |
        +-- 运行时关闭：Drupal 本地流，文件留在站点目录
        |
        +-- 运行时打开：BootGuard 覆盖 S3FS 连接
                         |
                         v
                    S3FS 流包装器
                         |
                         v
                    S3 兼容桶（当前为 R2）
                         |
                         +-- 公共前缀 -> CDN
                         +-- 私有前缀 -> Drupal 权限检查后的预签名地址
```

这个结构下，更换 R2 或其他 S3 兼容服务只更换 xedc 的活动供应商。Drupal 路径和 File Entity URI 保持不变。S3FS 不单独保存另一套 endpoint、域名或凭证。
