# 新上传文件为什么没有进入 Cloudflare R2

检查日期：2026-11-02。

结论先说：新上传的图片和视频仍写在本地磁盘。这是当前代码的既定结果，不是 R2 连接失败。连接测试已经能对 Bucket `dclili` 完成上传、读取、校验和删除。`public://` 和 `private://` 没有被 S3 File System 替换，所以 Drupal 文件实体不会进入 R2。

本次没有开启运行时，没有开启 public/private 接管，没有迁移或删除生产文件，没有修改 S3 File System 模块。

## 1. 新上传文件现在保存在哪里

文件表共 137 条：`public://` 128 条，`private://` 9 条，`s3://` 0 条。

今天最新的一条是 fid 686，`public://2026-11/IMG_2771.png`，3,920,503 字节。实际路径是：

`/var/www/drupal/web/sites/default/files/2026-11/IMG_2771.png`

流封装现在是：

| 协议 | 类 | 落点 |
| --- | --- | --- |
| `public://` | `Drupal\Core\StreamWrapper\PublicStream` | `/var/www/drupal/web/sites/default/files` |
| `private://` | `Drupal\Core\StreamWrapper\PrivateStream` | `/var/www/drupal/private` |
| `temporary://` | `Drupal\Core\StreamWrapper\TemporaryStream` | 本地临时目录 |
| `s3://` | `Drupal\s3fs\StreamWrapper\S3fsStream` | 已注册，但没有文件使用它 |

内容字段的保存协议：

| 字段 | 协议 |
| --- | --- |
| `media.field_media_image` | `public` |
| `media.field_media_video_file` | `public` |
| `comment.field_comment_image` | `public` |

后台上传、媒体库和节点表单都走这些字段。图片和视频因此都进入 `public://`。对象存储策略里把视频记为私有，只是一份尚未执行的策略，没有改字段协议。

直接访问最新图片返回 `200`，`Content-Type` 为 `image/png`。一条已有视频 `public://2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` 返回 `200`，`Content-Type` 为 `video/mp4`。同一张新图的 `thumbnail` 样式生成本地 AVIF，`photo_gallery_650` 生成本地 WebP，文件在 `sites/default/files/styles/` 下。

CDN `https://cdn.gitxx.xyz` 能连通，根路径和这张图片的三个可能路径都返回 `404`。这张图不在 CDN 上。

## 2. 为什么没有进入 R2

上传链路分成两条，现在只有测试那条会接触 R2。

普通上传：

1. 表单把文件交给 Drupal 文件字段。
2. 字段使用 `public://`。
3. `S3fsServiceProvider` 只有在 `$settings['s3fs.use_s3_for_public']` 为真时，才把 `stream_wrapper.public` 换成 `PublicS3fsStream`。私有协议同理，看 `s3fs.use_s3_for_private`。
4. `BootGuard::apply()` 每次启动都先把这两个开关写成 `false`。
5. 只有 `XEDC_STORAGE_RUNTIME=1`，并且同时有 `STORAGE_I_UNDERSTAND_TAKEOVER=1` 和对应的 `STORAGE_PUBLIC_TAKEOVER` 或 `STORAGE_PRIVATE_TAKEOVER`，开关才会变成 `true`。

当前站点：

- `XEDC_STORAGE_RUNTIME` 未设置，`xedc_storage.runtime_requested` 和 `runtime_applied` 都是 false。
- `s3fs.use_s3_for_public` 和 `s3fs.use_s3_for_private` 都是 false。
- 缓存重建之后仍然是 `PublicStream`。

所以新文件写入本地。S3 File System 已经启用，只表示 `s3://` 这个协议存在，不表示 `public://` 会改道。

连接测试走的是另一条路。`ConnectionTester` 读取供应商配置和 Key 文件，用 `S3ClientFactory` 直接请求 R2，对象键限制在 `_xedc_storage_test/`。它不经过文件实体，也不改 `public://`。

本次重新执行配置 `silly` 的连接测试：状态 `passed`，Bucket `dclili`，SHA-256、大小、MIME、元数据和 Cache-Control 都通过，测试对象已删除。耗时 2918 毫秒。没有改动 Drupal 文件。

## 3. 运行时配置实际读到了什么

活动供应商不是 `r2`。配置对象里现在只有一份 `silly`，标签是 Cloudflare R2。`active_profile` 和私有目录里的 `active` 文件都是 `silly`。旧的 `r2` 记录还留在测试日志里，那次失败原因是 Bucket 为空；这个配置已经不在活动配置中。Key 引用是 `xedc_storage_silly_access` 和 `xedc_storage_silly_secret`。不要把 ID 改回 `r2`，否则会和现有 Key 文件路径错开。

`silly` 的非密钥参数：

| 项目 | 值 |
| --- | --- |
| 供应商 | `cloudflare_r2` |
| Bucket | `dclili` |
| Region | `auto` |
| Endpoint | 已保存的 R2 S3 API 地址，末尾斜杠可以通过校验 |
| Path-style | 开启 |
| 公开访问域名 | `https://cdn.gitxx.xyz` |
| 供应商 CDN 字段 | 空 |
| 全站 CDN | 关闭，`base_url` 为空 |
| 公共前缀 | `s3fs-public` |
| 私有前缀 | `s3fs-private` |
| 上传 ACL | 不设置 public-read |

数据库里的 `s3fs.settings` 是另一套值：Bucket 也是 `dclili`，但 Region 是 `us-east-1`，自定义主机关闭，Path-style 关闭，两个 Key 名称都是空的。运行时关闭时，S3FS 客户端读的就是这套值，它指向 AWS 默认地址，不是 R2。`public://` 不读这套值。不要靠保存 S3FS 设置页来接 R2；那个页面不能保留 Region `auto`。

### 凭证

后台 Key 文件可读。`access_key` 32 字节，`secret_key` 64 字节，权限 `0600`，属主 `www-data`。在一次只存在于命令行内存中的启动演练里，凭证来源是 `admin`，配置是 `silly`。Access Key 与 Endpoint 主机里的账号 ID 不相同。演练没有打印密钥。

`BootGuard` 在 `XEDC_STORAGE_RUNTIME` 不是 `1` 时，读凭证之前就返回。所以运行时关闭不能说明密钥无效。

### 启动时的 Bucket、Region、Endpoint

`BootGuard` 在 Drupal 启动前执行，读不到数据库里的 `xedc_core.storage`。原来它只从环境变量读取 Bucket、Region、Endpoint 和 Path-style。PHP-FPM 里这些变量不存在，因此即便打开运行时，注入也会失败并继续使用本地文件。

这次补上了私有目录中的非密钥文件 `runtime.json`。保存供应商或把它设为活动配置时，`CredentialVault` 会写这个文件。环境变量仍然优先。文件里没有密钥；写入逻辑会拒绝看起来像密钥的内容。

已经为活动配置 `silly` 写好这份文件。隔离演练在进程内临时设置 `XEDC_STORAGE_RUNTIME=1`，不改 PHP-FPM，结果是：

- 运行时可以应用。
- 来源是 `profile`。
- Bucket `dclili`，Region `auto`，Path-style 开启，Endpoint 写入 S3FS 的 hostname。
- `use_cname` 仍是关闭。
- public 和 private 接管仍是关闭。

站点进程没有这个环境变量。演练结束后再次检查，`runtime_applied` 仍是 0。

### PHP-FPM

不需要为了这次测试去配 PHP-FPM 环境变量。

检查了 PHP-FPM master、`www` 工作进程、`www.conf` 和 `php8.5-fpm` 的 systemd 环境。没有 `STORAGE_*`、`XEDC_STORAGE_*` 或 `AWS_*`。池配置里 `clear_env = no` 仍是注释，默认是 `yes`：工作进程不继承系统环境，只接收池里显式写出的 `env[]`。当前没有这些 `env[]`。

以后如果打开运行时，密钥继续放在现有 Key 文件里，不要写进池配置。Bucket、Region、Endpoint 和 Path-style 可以由 `runtime.json` 提供。只有想用环境变量覆盖这份文件时，才把对应 `env[]` 写进池配置并重启 PHP-FPM。只在 shell 里 `export` 不会进入网站请求。Drush 也不会自动看到 FPM 的池变量。

`settings.php` 末尾引入 `xedc_storage.runtime.php`。`$settings` 和 `$config` 覆盖在每次启动时计算，不靠配置缓存记住接管开关。改池环境后需要重启 PHP-FPM，不需要为了这个开关单独重建 Drupal 缓存。

### CDN

`https://cdn.gitxx.xyz` 保存在供应商的「公开访问域名」。S3FS 只有在 CDN 开关打开时才把公开 URL 改写成这个域名。现在 `cdn_enabled` 是 false，演练也没有设置 `use_cname`。这是故意的：打开域名改写会改变图片和视频地址，而文件还在本地。

## 4. 还不能接管的业务代码

这些位置依赖本地 `realpath()`、本地目录或 `ZipArchive`。不能把它们换成 S3 HTTP 地址。GD、哈希和 ZIP 需要本地文件；远程对象应先流式复制到本地临时文件，处理完再写回。这次没有改这些业务代码。

| 功能 | 依赖 | 接管 public 之后 |
| --- | --- | --- |
| `ImagePipeline` | `realpath()`、`fopen()`、`hash_file()`、GD 的本地路径 | 正式发布前检查 `public://` 目录的真实路径。S3 流没有真实路径，ZIP 导入会在最后一步中止 |
| WebP 转换 | 导入任务目录里的本地文件，`imagewebp()` 写本地路径 | 转换本身可以留在本地临时目录。失败点在发布到 `public://` |
| Drupal 图片样式 | 核心 GD 用流读取源文件；S3FS 在接管后改走自己的样式控制器 | 本地样式今天可用。R2 上的样式没有测试，不能视为已通过 |
| `ArchiveManager` | `ZipArchive::open()` 和 `realpath()` 都要本地压缩包、本地解压目录 | 解压目录在 `private://xedc-import/`。私有接管会让 ZIP 无法打开 |
| 图片列表和详情 | 公开文件 URL | 接管前继续读本地文件。接管后 URL 生成方式会变 |
| 视频上传和播放 | 字段协议是 `public`，播放使用公开文件 URL | 公开接管会把新视频放进 R2 的公共前缀。当前策略里的「视频私有」还没有接到字段上 |
| 私有文件 | 9 条 `private://` 记录，下载仍走 Drupal 私有入口 | 私有接管才会改道 |
| `ProtectedDownloader` | 只为已接管的 `private://` 或 `s3://` 签发短时地址 | 运行时未启用时返回 503。本地文件不会被签成对象地址 |
| 会员下载 | `xedc_member` 没有单独的文件读取器 | 会员页使用普通文件 URL。没有单独的会员二进制下载被这次接管影响，也没有在 R2 上验证 |
| 清理、隔离、重复文件 | `FilePathGuard` 把 `s3://` 标为远程并跳过；`public://` 仍按本地内容处理，随后调用 `realpath()` | 接管后，留在磁盘上的旧文件可能不再匹配文件表里的真实路径，存在被当成未引用文件隔离的风险 |

`FilePathGuard`、`UnusedFileScanner` 和 `DuplicateFileScanner` 已经会放过 `s3://`。这不能保护被接管后的 `public://`。

## 5. 已经测试通过的部分

| 项目 | 结果 |
| --- | --- |
| R2 测试前缀上传、读取、校验、删除 | 通过。配置 `silly`，对象已删除 |
| 运行时从活动配置文件读取 Bucket、Region、Endpoint、Path-style 和后台凭证 | 通过。只在命令行内存中演练，接管保持关闭 |
| 运行时关闭时忽略凭证和 `runtime.json` | 通过 |
| 环境变量覆盖 `runtime.json`，不完整后台凭证不回退到环境变量 | 通过 |
| `runtime.json` 拒绝密钥样式内容，文件权限 0600 | 通过 |
| 最新公开图片、视频、AVIF 缩略图、WebP 画廊样式 | 通过，全部在本地 |
| 缓存重建后 `public://` 仍是本地流，接管仍关闭 | 通过 |
| PHP 语法检查 | 通过 |
| `storage-self-test.php` 的启动防护部分 | 通过 |
| 图片样式、视频、ZIP 导入、清理任务在 R2 上的行为 | 未测试 |
| 现有文件迁移 | 未执行 |

没有跑完整的 Drupal 集成自测。那一段会写入测试凭证，并假设 `s3fs.settings` 的 Bucket 为空。当前数据库里的 Bucket 是 `dclili`，和这个假设不一致。

## 6. 怎样安全地让新文件进入 R2

现在不具备接管条件。不要设置下面三个变量：

- `STORAGE_I_UNDERSTAND_TAKEOVER`
- `STORAGE_PUBLIC_TAKEOVER`
- `STORAGE_PRIVATE_TAKEOVER`

它们现在都未设置。`BootGuard` 会继续强制关闭接管。

建议顺序：

1. 继续用供应商页的连接测试做独立上传、读取和删除。只操作 `_xedc_storage_test/`。这一阶段已经通过。
2. 打开运行时之前，先改图片发布和 ZIP 解压：远程流复制到本地临时文件，处理完再写回。图片样式再用一张专用测试图在 R2 上生成 WebP 或 AVIF。不要用生产内容做第一次试验。
3. ZIP 批量导入的压缩包、解压目录和临时文件保持 `private://` 本地。确认原图处理和清理任务不会把本地旧文件当成未引用文件。
4. 只给新的公开文件打开接管。PHP-FPM 池里只增加 `env[XEDC_STORAGE_RUNTIME] = 1`，然后重启 PHP-FPM。不要把密钥放进池配置。先确认新的 `public://` 上传仍在本地，再用两个接管变量打开公开协议。上传一张新的测试图，核对 R2 对象、图片样式和 CDN，同时确认旧的本地 URL 还能打开。
5. 视频字段目前是公开协议。私有策略和会员下载要单独验证之后，再决定是否接管 `private://`。预签名入口只接受已经接管的私有对象。
6. 旧文件最后迁移。见下一节。

CDN 要在公开接管的同一窗口里单独打开。把 CDN 地址放到供应商的 CDN 字段并明确打开 CDN 开关后，S3FS 才会改写公开 URL。还要在 Cloudflare 确认自定义域名对应的路径包含 `s3fs-public` 前缀。现在三个候选路径都是 `404`，只能说明域名可达，不能说明文件映射已经配对。

打开运行时后，S3FS 的数据库配置会被启动覆盖成 R2 的 Region、Endpoint 和 Path-style。覆盖只存在于请求内存里，不会改写数据库。关闭运行时后，数据库里的 `us-east-1` 和空主机名会重新生效。

## 7. 旧文件怎样保留和迁移

现有 128 个公开文件和 9 个私有文件继续留在原来的磁盘路径。这次没有复制、没有改 URI，也没有删除。

接管只影响接管之后的新写入。已经存在的 `public://` 记录不会自动搬到 R2。接管之前生成的样式文件也留在 `sites/default/files/styles/`。

迁移要等新上传、图片样式和 ZIP 导入都在测试对象上通过之后再做：

- 先做迁移预检查。现有预检查不复制、不改 URI、不删除源文件。
- 复制时保留本地源文件。对象校验通过之前，文件表 URI 不变。
- 本地文件和 R2 对象同时存在的阶段，不要运行会按本地真实路径判断未引用文件的清理任务。
- 公开文件和私有文件分开迁移。视频是否改为私有，要在字段协议和会员权限确定后单独决定。
- 校验完成并确认页面读取的是新地址之后，再考虑清理本地副本。那一步不在这次范围内。

## 本次代码改动

`BootGuard` 现在能读取活动供应商的 `runtime.json`。`CredentialVault` 在保存供应商元数据时写这个文件。环境变量可以覆盖它。缺少运行时开关或接管开关时，网站行为与原来相同。

活动配置 `silly` 的 `runtime.json` 已经写好，权限 `0600`。站点运行时仍然关闭。
