# Cloudflare R2 连接失败排查

检查日期：2026-11-02。

本次只排查配置 `r2` 为什么连不上 Cloudflare R2。没有开启 `XEDC_STORAGE_RUNTIME`，没有开启 public/private 接管，没有迁移生产文件，没有修改业务代码。

结论来自当前代码、PHP-FPM 进程环境、活动配置和一次实际连接测试。

## 根本原因

连接失败有两层，当前页面上的测试停在第一层。

1. **Bucket 没有被读到。** 配置 `r2` 的 Bucket 字段是空的。它指定的环境变量 `STORAGE_BUCKET` 在 CLI 和 PHP-FPM 里都不存在。现有连接测试因此在发请求之前结束，状态是 `incomplete`，信息是：`配置不完整：未读取到 STORAGE_BUCKET。网站仍使用本地文件。`
2. **已经保存的 Access Key 不是 R2 的 S3 Access Key。** 私钥文件能通过 Key 模块的 File Provider 读出。其中 Access Key 与 Endpoint 主机名里的 Cloudflare 账号 ID 完全相同。那是账号 ID，不是 R2 API 令牌。Secret 长度为 53，含一个非字母数字字符，也不是 R2 Secret Access Key 常见的 64 位十六进制。把 Bucket 仅在本次探测的内存中补成 `dclili` 后，同一个 `S3ClientFactory` 向 R2 发出了签名请求，服务端返回 HTTP 401，AWS 错误码 `Unauthorized`。没有写入对象。

DNS、TLS 和到 R2 的网络是通的。失败不是 Region、Path-style、签名算法崩溃或 Bucket 权限。

## 凭证从哪里来

代码同时支持环境变量和 Drupal Key 的 File Provider。当前实际用的是后台 Key 文件，不是环境变量。

读取顺序在 `CredentialVault::resolve()`：

1. 私有目录里这一份配置的 `access_key` 和 `secret_key` 都有效时，来源记为 `admin`。必须能通过 Key 实体 `xedc_storage_<配置 ID>_access` 和 `xedc_storage_<配置 ID>_secret` 读回。读不到就失败，**不会改用环境变量**。
2. 两个文件只存在一个，或文件内容无效时，同样失败，不改用环境变量。
3. 两个文件都不存在时，才按配置里的环境变量名读取。变量名是 `STORAGE_ACCESS_KEY` 和 `STORAGE_SECRET_KEY` 时，来源记为全局环境变量。

Bucket 的顺序在 `CredentialResolver`：先读配置里的 Bucket 环境变量，变量为空或不存在时，再用供应商表单里的 Bucket 字段。两个都空，就报这个环境变量名缺失。

S3 File System 另有一条运行时入口，只在 `XEDC_STORAGE_RUNTIME=1` 时由 `BootGuard::apply()` 写入 `$settings['s3fs.access_key']` 和 `$settings['s3fs.secret_key']`。S3FS 3.12 的 `S3fsService::getAmazonS3Client()` 优先用这两个 Settings。Settings 为空时，才看 `s3fs.settings` 里的 Key 名称。两个名称当前都是空的，所以 S3FS 不会去读 `xedc_storage_r2_access` 或 `xedc_storage_r2_secret`。

当前这次连接测试走的是第一条：Key 文件已经读到，Bucket 没读到，请求没有发出。

## 环境变量

检查了 CLI、PHP-FPM 主进程、PHP-FPM `www` 工作进程、`/etc/environment`、`/etc/php/8.5/fpm/pool.d/www.conf`、`php8.5-fpm.service`、Nginx `fastcgi_param` 和项目 `.env`。下面三个变量结果相同。

| 变量 | 是否存在 | 是否为空 | 来源 | PHP-FPM 是否继承 |
| --- | --- | --- | --- | --- |
| `STORAGE_ACCESS_KEY` | 不存在 | 没有值 | 没有定义。`/var/www/drupal/.env` 不存在。`.env.example` 只是空占位，`settings.php` 不加载它 | 否。主进程环境里没有，池配置也没有 `env[]` |
| `STORAGE_SECRET_KEY` | 不存在 | 没有值 | 同上 | 否 |
| `STORAGE_BUCKET` | 不存在 | 没有值 | 同上 | 否 |

`XEDC_STORAGE_RUNTIME`、`STORAGE_REGION`、`STORAGE_ENDPOINT` 同样不存在。

PHP-FPM 池里 `clear_env = no` 被注释，因此使用默认值 `yes`：工作进程不会继承系统环境，只会收到池配置里显式写出的 `env[]`。当前池配置没有这些变量。systemd 单元也没有 `Environment` 或 `EnvironmentFile`。

`AWS_ACCESS_KEY_ID`、`AWS_SECRET_ACCESS_KEY` 也不存在，没有 `~/.aws/credentials`。

## 配置 r2

活动配置 `xedc_core.storage` 里有一份 `r2`。`active_profile` 是空的，私有目录里也没有 `xedc-storage-keys/active`。**它不是当前活动供应商。** 这不影响单独对 `r2` 做连接测试。运行时关闭时，活动供应商也不会被 S3FS 使用。

| 项目 | 结果 |
| --- | --- |
| 配置 ID | `r2`，正确 |
| 供应商类型 | `cloudflare_r2`，正确 |
| 是否活动供应商 | 否。活动配置和 `active` 文件都是空的 |
| Bucket 字段 | **空** |
| Region | `auto`，正确 |
| API Endpoint | `https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com/`，正确。末尾斜杠可以通过校验 |
| Path-style | 开启，正确 |
| Access Key 环境变量名 | `STORAGE_ACCESS_KEY`，正确 |
| Secret Key 环境变量名 | `STORAGE_SECRET_KEY`，正确 |
| Bucket 环境变量名 | `STORAGE_BUCKET`，名称正确，但变量不存在，字段又是空的 |
| 公开访问域名 | **空** |
| CDN | **空**。`cdn_base_url` 和对象存储 CDN 配置里的 `base_url` 都没有 `https://cdn.gitxx.xyz` |
| Key 引用 | `xedc_storage_r2_access`、`xedc_storage_r2_secret`，都已保存 |

CDN 为空不会导致这次 API 连接失败。API 地址和 CDN 是分开的。

S3FS 活动配置是另一份值，连接测试不读它：Bucket 是 `dclili`，Region 是 `us-east-1`，Custom Host 关闭，Hostname 为空，Path-style 关闭，两个 Key 名称都为空。没有配置覆盖。这份值指向 AWS 默认地址，不是 R2。

## Key 模块

不需要再创建 Key。

| 检查 | 结果 |
| --- | --- |
| Key 模块 | 已启用，`8.x-1.22` |
| File Provider | 可用 |
| `xedc_storage_r2_access` | 已存在，Provider 为 `file`，指向私有目录中的 `access_key`，可读 |
| `xedc_storage_r2_secret` | 已存在，Provider 为 `file`，指向 `secret_key`，可读 |
| 文件权限 | 密钥文件 `0600`，目录 `0700`，属主 `www-data` |
| 后台入口 | `/admin/config/media/object-storage/providers/r2/edit` 下半部的「API 凭证」 |

这个表单会自己写入上述两个 Key，并把文件放在 Web 根目录外。不要到 `/admin/config/system/keys` 再手工建一套。那里再建同名 Key 会和现有引用冲突。

当前文件里的值不能用：Access Key 等于 Endpoint 里的账号 ID；Secret 不是 64 位十六进制。更新时两个输入框都要重新填写。留空表示保留现在这份错误凭证。

## 连接停在哪一步

现有 `ConnectionTester::test('r2')` 的结果：

- 状态：`incomplete`
- 信息：`配置不完整：未读取到 STORAGE_BUCKET。网站仍使用本地文件。`
- 阶段：凭证已从 Key 读出，Bucket 读取失败。Endpoint、签名、Region、Path-style、DNS、TLS、网络和 Bucket 权限都还没有执行。

同一套 `S3ClientFactory` 只在内存里把 Bucket 补为 `dclili` 之后：

| 阶段 | 结果 |
| --- | --- |
| 凭证读取 | 已读到。来源是后台 Key，不是环境变量 |
| Bucket 读取 | 配置和环境变量都为空。内存探测才补上 `dclili` |
| Endpoint DNS | 通过。主机解析到 Cloudflare 地址 |
| TLS | 通过。TLSv1.3，证书主机名与 Endpoint 一致 |
| 网络 | 通过。签名请求已经到达 R2 |
| Region `auto` | 客户端已按 `auto` 发送，服务端没有返回 Region 范围错误 |
| Path-style | 客户端已开启 |
| AWS Signature V4 | 请求已签名。服务端拒绝的是身份，不是本地签名过程抛错 |
| Bucket 权限 | 未到这一步 |
| 服务端结果 | HTTP 401，错误码 `Unauthorized` |

未带签名的探测请求返回 HTTP 400，只说明匿名请求被拒绝。签名请求的 401 才是凭证被拒绝。

## S3FS 与运行时

运行时当前是关闭的：

- `XEDC_STORAGE_RUNTIME` 未设置
- `xedc_storage.runtime_requested` 为 false
- `xedc_storage.runtime_applied` 为 false
- `s3fs.access_key` 和 `s3fs.secret_key` 都没有注入
- `s3fs.use_s3_for_public` 和 `s3fs.use_s3_for_private` 都是 false

运行时关闭时，`BootGuard::apply()` 在读取凭证之前就返回。S3FS 仍使用数据库里的 `us-east-1`、空 Hostname、关闭的 Path-style，并且没有密钥。所以运行时关闭不能证明 S3FS 已经连上 R2，只能证明 S3FS 还没有收到 R2 的地址和凭证。

可以不开启运行时，直接测试 `r2`。页面上的「测试连接」和 `ConnectionTester` 使用供应商配置与 Key 文件，不改 `public://` 或 `private://`。

这次连接测试不必先配置 PHP-FPM 环境变量。Bucket 写进 `r2` 的 Bucket 字段后，测试会使用字段值。Access Key 和 Secret 已经由 Key 文件提供；环境变量里的密钥在文件存在时不会被采用。

以后如果要让 S3FS 使用 R2，运行时才会要求 PHP-FPM 能读到 `STORAGE_BUCKET`、`STORAGE_REGION`、`STORAGE_ENDPOINT` 和 `STORAGE_PATH_STYLE`。那条路径读的是环境变量，不会自动采用表单里的 Endpoint 和 Region。另外还要把 `r2` 设为当前配置，运行时才会使用这份后台凭证。现在不要开启运行时，也不要开启接管。

当前没有运行时覆盖。数据库里的 S3FS 配置保持原样。

## 需要手动完成的步骤

不需要改代码，不需要新建 Key，不需要重启 PHP-FPM，不需要重建 Drupal 缓存。

1. 打开 `/admin/config/media/object-storage/providers/r2/edit`。
2. 在供应商表单把 Bucket 填成 `dclili`。Region、Endpoint、Path-style 和环境变量名保持现在的值。CDN 可填 `https://cdn.gitxx.xyz`，这只保存域名，不修复 401，也不会切换现有文件地址。点「保存配置」。
3. 在同一页的 API 凭证里，用 Cloudflare R2 的 S3 API 令牌重新填写 Access Key ID 和 Secret Access Key。不要填写账号 ID，不要沿用现在这两个输入框里的空值。点「更新凭证」。Key 模块会更新已有的 `xedc_storage_r2_access` 和 `xedc_storage_r2_secret`。
4. 不要在 Key 管理页手工创建这两个 Key。
5. 不要修改 PHP-FPM、systemd 或 `settings.php` 来完成这次连接测试。现在的错误凭证放在 Key 文件里，只往环境变量写正确密钥也不会被这次测试使用。
6. 回到该页或 `/admin/config/media/object-storage/providers`，点「测试连接」。成功时信息是测试对象已上传、读取、校验并删除。不要点接管开关，不要把 `XEDC_STORAGE_RUNTIME` 设为 `1`。

在 Cloudflare 创建令牌时，权限至少要能对 Bucket `dclili` 写入和删除测试前缀 `_xedc_storage_test/`。令牌只显示一次 Secret，保存后再打开页面不会回填 Secret。

## 修复后怎么确认

- 供应商页或编辑页的连接测试状态变为成功。
- 失败时如果仍是「未读取到 STORAGE_BUCKET」，说明 Bucket 字段没有保存，或者 PHP 进程里出现了一个空的 `STORAGE_BUCKET` 把字段盖掉。
- 失败时如果是 401、`Unauthorized` 或「凭证错误」，说明新的 Access Key 仍未被 R2 接受。
- `SignatureDoesNotMatch` 表示 Access Key 被接受，但 Secret 与它不配对。
- 测试期间 `s3fs.use_s3_for_public` 和 `s3fs.use_s3_for_private` 应保持 false。
