# XEDC R2 前缀一致性审计

审计时间：2026-11-03 10:27 UTC。范围是只读核对。没有改 PHP、YAML、Twig、JS、CSS，没有改 Drupal 配置，没有改 `config/sync`，没有改文件 URI，没有上传、删除、移动或复制 R2 对象，没有做存储切换，没有 Git commit 或 push。

结论先说：

- 当前生产对象 Key 的公开前缀是 `public/`，私有前缀是 `private/`。`s3fs-public` 和 `s3fs-private` 不再是正在写入和正在生成 CDN 地址的前缀。
- `public/` 同时是配置里的文件夹名，也是 Bucket 里的真实 Key 前缀。它不是只存在于 Drupal 逻辑 URI 里的名字。`public://2026-11/a.webp` 的对象 Key 是 `public/2026-11/a.webp`。
- Bucket 里没有 `public/public/`、`s3fs-public/s3fs-public/` 这类重复前缀。现有 `file_managed` URI 也没有把前缀再写进路径。
- 代码里仍有两处把旧前缀写死。迁移目标探测和存储切换如果现在运行，会按 `s3fs-public/`、`s3fs-private/` 判断，和当前 Key 不一致。
- 图片原图走 R2，图片样式在本地生成。这个设计在线上页面和对象列表里都成立。旧前缀下还留着 1 个历史样式对象。

## 1. 生产实际配置

数值来自三处交叉：`private/xedc-storage-runtime/active.json`、`private/xedc-storage-keys/silly/runtime.json`、Drush 引导后的 `Settings` 和流包装器。Drush 进程没有 PHP-FPM 池里的那五个环境变量，但 `active.json` 的 `public_mode` 已是 `cloudflare_r2`，引导结果和线上页面一致。没有读取或输出 Access Key、Secret Key。

| 项 | 实际值 | 状态 |
| --- | --- | --- |
| Storage Profile | `silly` | PASS |
| Provider | `cloudflare_r2` | PASS |
| Bucket | `dclili` | PASS |
| Region | `auto` | PASS |
| Endpoint | `https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`，path style 开启 | PASS |
| 公开前缀 | `public` | PASS |
| 私有前缀 | `private` | PASS |
| 根前缀 | 空 | PASS |
| CDN 域名 | `https://cdn.gitxx.xyz` | PASS |
| 公开流包装器 | `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream` | PASS |
| 私有流包装器 | `Drupal\Core\StreamWrapper\PrivateStream` | PASS |
| 临时流包装器 | `Drupal\Core\StreamWrapper\TemporaryStream` | PASS |
| public takeover | 开。`s3fs.use_s3_for_public=true` | PASS |
| private takeover | 关。`s3fs.use_s3_for_private=false`，`BootGuard` 里私有接管被固定为 false | PASS |
| CDN 改写 | 开。`xedc_storage.cdn_enabled=true` | PASS |
| 文件系统装饰器 | `ImageStyleFileSystem` | PASS |

`runtime.json` 里 `cdn_enabled` 是 false，`cdn_base_url` 是空的。`active.json` 在公开模式为 `cloudflare_r2` 时把 CDN 开关覆盖成 true。公开域名来自 `public_base_url=https://cdn.gitxx.xyz`。S3FS 的 `use_cname` 仍是 false，`domain` 为空；公开地址不靠 S3FS 的 CNAME，而靠 `XedcPublicS3fsStream` 读取 `xedc_storage.public_base_url`。

活动配置和 `config/sync/s3fs.settings.yml` 都已经是 `public_folder: public`、`private_folder: private`、`region: auto`。数据库里的 S3FS 主机名是空的，运行时覆盖才写入 Endpoint。前缀两边一致。

PHP-FPM 池 `/etc/php/8.5/fpm/pool.d/www.conf` 只设置了这五个变量，没有 `STORAGE_PUBLIC_FOLDER` 或 `STORAGE_PRIVATE_FOLDER`：

- `XEDC_STORAGE_RUNTIME=1`
- `STORAGE_I_UNDERSTAND_TAKEOVER=1`
- `STORAGE_PUBLIC_TAKEOVER=1`
- `STORAGE_PRIVATE_TAKEOVER=0`
- `STORAGE_CDN_ENABLED=1`

工作进程的 `/proc/pid/environ` 是空的，不能用它证明请求里的 `getenv()`。线上 HTML 已经证明公开地址使用 `public/`。池配置没有覆盖文件夹名，文件夹来自 profile 的 `runtime.json`。

`xedc_storage.public_enabled` 和 `xedc_storage.private_enabled` 是 false。这两个值只表示对应的环境变量没设置，不表示公开存储关闭。真正接管公开流的是 `public_takeover`。

### Bucket 抽样

只做了 `ListObjectsV2` 和 `HeadObject`。顶层前缀是 `_xedc_storage_test/`、`and/`、`public/`、`s3fs-public/`。没有 `private/` 或 `s3fs-private/` 的顶层目录。

| 前缀 | 对象数 | 字节 | 说明 |
| --- | ---: | ---: | --- |
| `public/` | 156 | 109506820 | 当前公开对象 |
| `public/public/` | 0 | 0 | 没有重复前缀 |
| `public/styles/` | 0 | 0 | 当前前缀下没有图片样式 |
| `s3fs-public/` | 133 | 79802162 | 旧副本仍在 |
| `s3fs-public/s3fs-public/` | 0 | 0 | 没有重复前缀 |
| `s3fs-public/public/` | 0 | 0 | 没有混成双前缀 |
| `s3fs-public/styles/` | 1 | 49354 | 历史样式 `.../IMG_3026.jpeg.webp` |
| `private/` | 0 | 0 | 没有私有对象 |
| `s3fs-private/` | 0 | 0 | 没有私有对象 |
| `_xedc_storage_test/` | 4 | 198 | 以前的前缀迁移测试对象，不在两个正式前缀下 |

`file_managed`：公开 URI 157 条，私有 URI 10 条，没有 `s3://`。没有 URI 形如 `public://public/`、`public://s3fs-public/`、`private://private/` 或 `private://s3fs-private/`。

抽样 5 条最新公开 URI。`StorageUriResolver::objectKey()`、S3FS `convertUriToKeyedPath()` 和 `getExternalUrl()` 三者一致，例如：

- URI `public://2026-11/webp-xedc-final-20261103-3a67.webp`
- 对象 Key `public/2026-11/webp-xedc-final-20261103-3a67.webp`
- CDN `https://cdn.gitxx.xyz/public/2026-11/webp-xedc-final-20261103-3a67.webp`
- 同一相对路径的 `s3fs-public/...` Key 不存在
- `public/public/...` 不存在

这个新对象的 CDN 返回 200，`image/webp`，1216 字节。旧视频 `public/2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` 和 `s3fs-public/` 下的同名对象都是 200，都是 4627386 字节。新文件只在 `public/`，迁移过的旧文件两边都还在。

健康检查写死的抽样相对路径 `2026-10/IMG_3009.jpeg` 在两个前缀下都存在，各 163198 字节。按当前配置，检查会访问 `public/2026-10/IMG_3009.jpeg`。

157 条公开 URI 里有 7 条在 `public/` 和 `s3fs-public/` 都不存在，文件还在本地 `sites/default/files`：fid 800–804、806、807，都是 `xedc-playback/` 或 `xedc-samples/` 下的 mp4。Key 算法没有算错，对象没有上传，所以 CDN 是 404。另外 7 个 `public/2026-11/` 下的 avif、jpg、png 没有对应的 `file_managed` URI，前缀仍然是单层 `public/`。

10 条 `private://` 记录在本地私有目录和 R2 都不存在。私有接管是关的，转换结果会是 `private/{相对路径}`，但没有对象可核对。

## 2. 前缀从哪里来，怎样变成 Key 和 URL

当前链路：

1. `BootGuard::apply()` 在 `settings.php` 引入 `xedc_storage.runtime.php` 时运行。公开模式来自 `active.json`。文件夹优先读环境变量，否则读 profile 的 `runtime.json`。现在没有文件夹环境变量，所以用到的是 `public` 和 `private`。
2. 同一组值写入 `xedc_storage.public_folder`、`xedc_storage.private_folder`，并覆盖 `s3fs.settings` 的 `public_folder`、`private_folder`。
3. `public://{相对路径}` 由 S3FS `convertUriToKeyedPath()` 变成 `{public_folder}/{相对路径}`。包装器读到的 `public_folder` 是 `public`。
4. `StorageUriResolver::objectKey()` 在 `settings_accepted` 为 true 时用同一组 Settings。抽样结果和 S3FS Key 相同。
5. `XedcPublicS3fsStream::getExternalUrl()` 调用 `PublicUrl::delivery()`。原图在 CDN 开启时变成 `https://cdn.gitxx.xyz/{public_folder}/{相对路径}`。目标路径已经以文件夹名加斜线开头时，`PublicUrl` 不再加一次。
6. 相对路径以 `styles/` 开头时，`PublicUrl` 不生成 CDN 地址。流包装器改走本地 `PublicStream`。`ImageStyleFileSystem` 装饰 `file_system`，样式写入本地公开目录，不上传到 Bucket。
7. `private://` 不接管。包装器是本地 `PrivateStream`。`publicCdnUrl()` 对私有 URI 返回空。

`public/` 因此有两层意思，而且现在两层是对齐的：

- Drupal 逻辑 URI 仍然是 `public://`，里面不包含 `public/`。
- 配置文件夹 `public` 会加到对象 Key 和 CDN 路径上，成为 Bucket 里的真实前缀。

默认字符串 `s3fs-public`、`s3fs-private` 只在 Settings 或 profile 没有文件夹时使用。当前两边都有值，默认值没有落到生产请求上。

## 3. 旧前缀还要不要留

`s3fs-public/` 不再是生产写入和当前页面地址使用的前缀。它仍然有用，因为 133 个旧对象还在，而且旧 CDN 地址抽查仍然 200。新上传的 webp 只在 `public/`，旧地址是 404。回滚读取时还需要这些旧对象。这次没有删除它们。

`s3fs-private/` 下面没有对象。作为私有隔离前缀，它已经不是当前配置。当前私有文件夹名是 `private`，Bucket 里也没有这个前缀的对象。

代码里的默认值和若干工具仍把旧名字当作唯一前缀。配置一旦丢失，上传和 CDN 会退回 `s3fs-public/`。这和现在的 Bucket 布局不一致。旧对象可以留，硬编码不应该继续当成当前规则。

## 4. 关键文件

| 文件 | 结果 | 说明 |
| --- | --- | --- |
| `StorageUriResolver.php` | PASS | `settings_accepted` 时使用 Settings。当前 Key 为 `public/{路径}`、`private/{路径}`。默认值仍是旧前缀，当前没走到。 |
| `CloudflareR2Provider.php` | PASS | 摘要和健康检查使用 profile 的 `public_folder`。抽样对象在 `public/2026-10/IMG_3009.jpeg`。默认值仍是旧前缀。 |
| `PublicUrl.php` | WARNING | 线上原图 URL 正确，样式留在本地。见问题 3：路径若以配置的文件夹名开头，CDN 会少加一层，S3FS Key 仍会加一层。 |
| `StorageSwitchManager.php` | FAIL | `compare()`、`remoteSizes()`、`copyMissing()` 和测试前缀写死 `s3fs-public/`。当前对象在 `public/`，这个工具会看成缺失，并往旧前缀复制。 |
| `StorageConfig.php` | PASS | 保存 profile 时使用提交的文件夹。空值默认仍是旧前缀。活动 profile 已保存 `public` / `private`。 |
| `BootGuard.php` | PASS | 本次引导实际使用 `public` / `private`，并同时写给 Settings 和 S3FS。私有接管固定关闭。缺配置时的默认值仍是旧前缀。 |
| `XedcPublicS3fsStream.php` | PASS | 原图外部地址使用包装器配置里的 `public_folder`。样式走本地。线上视频和头像地址与此一致。 |
| `RuntimeState.php` | PASS | `active.json` 只决定模式、profile 和 CDN 开关，不含文件夹，也不含凭据。文件夹在 profile 运行时文件里。 |
| `StorageHealthChecker.php` | PASS | 不拼对象 Key。它报告的接管状态是公开开、私有关。 |
| `CdnStatus.php` | WARNING | 能显示当前文件夹名。风险说明和人工步骤仍只写 `s3fs-private`。 |
| `MigrationPlanner.php` | PASS | 预检查只统计 `file_managed`，不生成对象 Key，也不连接后写对象。 |
| `MigrationExecutor.php` | FAIL | 清单和复制用 `objectKey()`，会得到 `public/`。`probeOne()` 却要求 Key 以 `s3fs-public/` 或 `s3fs-private/` 开头，当前 Key 会被标成协议不一致。 |
| `CdnUrlPolicy.php` | WARNING | 按传入的文件夹判断，本身不写死前缀。它不排除 `styles/`。`publicCdnUrl()` 因此会给样式 URI 算出一条 CDN 地址。 |
| `ImageStyleFileSystem.php` | PASS | 样式路径留在本地磁盘。 |
| `DeliveryUrl.php` | WARNING | 生产图片标签不走这里。它调用 `publicCdnUrl()`，样式 URI 会被当成可上 CDN 的公开对象。 |

`templates/xedc-core-storage.html.twig` 仍写着完整清单会检查 `s3fs-public/` 或 `s3fs-private/`。这句话和 `probeOne()` 一致，和当前 Bucket 不一致。

## 5. 重点问题

### s3fs-public 还是生产对象 Key 的真实前缀吗

不是。当前写入、S3FS 转换、URI 解析和线上 CDN 使用 `public/`。`s3fs-public/` 是迁移前的副本。旧文件两边都有，2026-11-03 的新 webp 只有 `public/` 返回 200。状态：PASS，旧前缀已退出当前读写。

### public/ 只是逻辑 URI 或配置前缀吗

不。`public://` 是逻辑 scheme，URI 内部没有再加 `public/`。配置值 `public` 会变成对象 Key 和 CDN 路径的第一段，Bucket 里确实有 156 个这样的对象。状态：PASS。

### 会不会出现 public/public/ 或 s3fs-public/s3fs-public/

现有数据没有。Bucket 对应前缀计数为 0，URI 里也没有。

代码上有一个还没碰到数据的分歧。对假想 URI `public://public/foo.jpg`，在文件夹名为 `public` 时：

- `PublicUrl::cdnUrl()` 得到 `https://cdn.gitxx.xyz/public/foo.jpg`
- `objectKey()` 得到 `public/public/foo.jpg`

S3FS 也是总是再加一层。`PublicUrl` 看到目标已经以 `public/` 开头就不再加。这个判断是为了旧文件夹名 `s3fs-public` 防重复；文件夹改名成 `public` 之后，真实目录如果就叫 `public`，CDN 和对象 Key 会分开。当前没有这种 URI。状态：WARNING。

### s3fs-private 会不会经 CDN 泄露

现在没有可泄露的对象。`s3fs-private/` 和 `private/` 的对象数都是 0，10 条私有 URI 也没有本地文件。

匿名 GET，带普通浏览器标识：

- `https://cdn.gitxx.xyz/private/does-not-exist-audit` 返回 403，页面是 Cloudflare “Attention Required”。不存在的 Key 也被拦住，说明拦截发生在源站读对象之前。
- `https://cdn.gitxx.xyz/s3fs-private/does-not-exist-audit` 返回 404，响应形态和公开前缀上不存在的对象相同。请求到达了源站，边缘没有按这个前缀拒绝。

没有向 Bucket 写入探测对象，所以不能展示一次真实私有文件的读取结果。按状态码，当前配置使用的 `private/` 被边缘拒绝；旧前缀 `s3fs-private/` 没有被拒绝。Cloudflare 控制台里的规则原文没有查看。状态：WARNING。当前无私有对象，旧前缀的边缘隔离未证实。

### CDN URL 是否使用实际对象 Key

线上原图和视频是的。视频页 `/videos/mimimamamaimaimai` 的播放地址和头像都是 `https://cdn.gitxx.xyz/public/...`，没有 `s3fs-public`，没有 `public/public`。抽查播放地址和 `IMG_9619.jpeg` 为 200。首页、`/meitu`、`/node/146` 的封面是本站样式 URL，不是 CDN。

7 个只存在于本地的 mp4 也会被改写成 `https://cdn.gitxx.xyz/public/...`，对象不存在，CDN 404。前缀没有拼错，对象缺失。状态：PASS，另有对象缺失的 WARNING。

### 原图在 R2、样式在本地是否仍然成立

成立。

- `public/styles/` 为 0。
- 本地 `sites/default/files/styles` 有 854 个文件。
- 首页样式 URL 形如 `/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?xedc_style=1`，本站返回 200，`image/webp`，49354 字节。
- 同一条样式的 `public/styles/...` Key 不存在。
- 抽样样式的 `buildUrl()` 和 `getExternalUrl()` 都在 `gitxx.xyz`，不在 CDN。

例外有两个，都不是当前生成路径：

- `s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp` 仍在，CDN 200，49354 字节。这是旧副本。
- `StorageUriResolver::publicCdnUrl()` 对 `public://styles/...` 仍会算出 `https://cdn.gitxx.xyz/public/styles/...`。这条地址的对象不存在。生产图片标签不走这个方法，走的是流包装器。状态：当前页面 PASS，辅助 URL 方法 WARNING。

### 迁移预检查和执行器是否用同一套前缀

不是。

- `MigrationPlanner::precheck()` 不计算对象 Key。
- `MigrationExecutor::inventory()` 把 `objectKey()` 写入清单。按现在的 Settings，公开文件会得到 `public/...`。
- `probeOne()` 写死公开必须是 `s3fs-public/`，私有必须是 `s3fs-private/`。现在的 Key 会失败，原因是“对象键和来源协议不一致”，并且不会上传。
- `copyOne()` 又使用清单里的 Key，也就是当前的 `public/` 或 `private/`。

预检查、探测和复制不是同一条规则。状态：FAIL。这次没有创建迁移任务，也没有探测或复制。

### 测试是历史兼容，还是当前架构

两类都有。

仍然描述当前策略、应该保留的：

- `tests/src/Unit/CdnDeliveryPolicyTest.php` 把文件夹名作为参数传进 `CdnUrlPolicy`。用 `s3fs-public` 只是例子，不读取生产配置。策略测试可以留，但应该补上文件夹名为 `public` / `private` 的例子，包括“相对路径本身以 `public/` 开头”这一种。
- `PublicUrl` 的手动断言如果显式传入文件夹名，测的是函数，不是生产配置。防重复的断言目前期望 `public://s3fs-public/...` 不再加一层。这和 S3FS 总是加一层的行为相反，需要跟问题 3 一起改预期。

已经按当前前缀写的：

- `tests/manual/image-optimize-self-test.php` 断言对象 Key 以 `public/` 开头，并断言没有 `public/public/`。这和当前架构一致。它会写对象，这次没有运行。

仍按旧架构或旧开关写的，不能当作生产证明：

- `cdn-delivery-self-test.php` 先要求接管和 CDN 关闭。当前两者都开着。后半段又期望 CDN 以 `/s3fs-public/` 开头，并期望样式也上 CDN。这和当前前缀、也和样式留在本地的设计都不符。
- `r2-architecture-self-test.php`、`r2-fpm-wrapper-io-test.php`、`cdn-isolation-retest.php`、`cdn-security-self-test.php`、`cdn-migration-precheck.php` 把测试对象写到 `s3fs-public/` 或 `s3fs-private/`。按这次的 CDN 结果，写到 `s3fs-private/` 的对象不会被现在这条 403 规则挡住。
- `storage-self-test.php` 用旧文件夹名做引导夹具。作为默认值回归可以留，不能表示生产 profile。

状态：WARNING。单元策略测试保留并补当前文件夹；会写旧前缀、或假设接管关闭的手动测试，在下次对生产运行前要改。这次没有跑这些测试，因为其中几个会写日志或对象。

## 6. 问题

| 编号 | 问题 | 严重程度 | 状态 |
| --- | --- | --- | --- |
| 1 | `StorageSwitchManager` 只列出和复制 `s3fs-public/`。现在执行切换会漏掉 `public/` 上的对象，并把文件写回旧前缀。 | 高。只在有人运行切换时发生。当前页面不走这个类。 | FAIL |
| 2 | `MigrationExecutor::probeOne()` 拒绝不是旧前缀的 Key。清单和复制使用当前前缀。探测会把现有 Key 全部判失败。 | 高。只在有人跑迁移探测时发生。 | FAIL |
| 3 | 文件夹名是 `public` 时，`PublicUrl` 会把 `public://public/...` 收成一层，S3FS 和 `objectKey()` 会保留两层。 | 中。现有 URI 没有这种路径。 | WARNING |
| 4 | `publicCdnUrl()` / `DeliveryUrl` 不排除样式，会生成 `public/styles/...` 的 CDN 地址。对象并不存在。页面本身用的是本地样式 URL。 | 中。当前页面未使用这条路径。 | WARNING |
| 5 | `CdnStatus` 的人工步骤只要求拦截 `/s3fs-private`。实测 `/private/` 为 403，`/s3fs-private/` 为 404。 | 中。当前没有私有对象。旧前缀若再写入，边缘没有被证明会拒绝。 | WARNING |
| 6 | 旧前缀上还有 1 个样式对象，CDN 200。新样式不在 R2。 | 低。不影响当前生成路径。 | WARNING |
| 7 | 7 个本地 mp4 的 Key 正确，但 R2 没有对象，CDN 404。 | 低。不是前缀算错。 | WARNING |
| 8 | 默认值和部分测试仍把 `s3fs-public` 当成生产前缀。配置存在时请求不会用到默认值。 | 低到中。取决于有没有人跑旧手动测试。 | WARNING |

没有发现现有文件被收成 `public/public/` 或 `s3fs-public/s3fs-public/`。没有发现线上页面把原图指到旧前缀。

## 7. 要不要修

要修，但不是为了当前页面读图。视频、头像和封面抽查使用的前缀是对的，样式也在本地。

要在下一次存储切换或迁移探测之前修问题 1 和 2。问题 3、4、5 应该同一批改掉，避免文件夹名 `public` 和旧测试夹具继续分叉。本轮按限制没有改代码。

## 8. 最小修复建议

不在本轮执行。

1. `StorageSwitchManager` 的列表前缀、比较 Key 和测试前缀改为 `StorageUriResolver::folders()` 的公开文件夹。不要再写死 `s3fs-public/`。复制前仍跳过 `styles/`。
2. `MigrationExecutor::probeOne()` 用清单里已经算好的 Key 所属文件夹做校验，文件夹来自同一次 `objectKey()` 的规则。公开校验 `public/`，私有校验 `private/`。不要和复制使用两套前缀。
3. 公开 URL 和对象 Key 走同一个函数。`public://` 和 `private://` 总是加一层当前文件夹，和 S3FS `convertUriToKeyedPath()` 相同。不要因为相对路径碰巧以 `public/` 开头就少加一层。
4. `publicCdnUrl()` 对 `styles/` 返回空，让 `DeliveryUrl` 和流包装器一样把样式留在本站。
5. `CdnStatus` 的风险说明改成当前私有文件夹 `private`。同时写明 `s3fs-private` 在这次匿名请求里没有被 403 拦住。不要在同一轮改 Cloudflare 规则或删对象。
6. 单元测试增加 `public` / `private` 夹具。手动测试若还要写对象，测试前缀放在当前公开文件夹下的专用测试目录，并单独断言私有测试对象不会从 CDN 读到。假设“接管关闭”的断言不要再当作生产架构测试。

旧的 `s3fs-public/` 对象先留着。新地址已经指向 `public/`，删旧对象会让尚未淘汰的旧 URL 变成 404。

## 9. 分项结论

| 检查 | 状态 |
| --- | --- |
| 活动 profile、provider、bucket、region、endpoint | PASS |
| 当前公开前缀是 `public/`，不是 `s3fs-public/` | PASS |
| 当前私有前缀是 `private/`，接管关闭，包装器是本地 PrivateStream | PASS |
| 现有 URI 与 S3FS Key、解析器 Key、CDN 原图 URL 一致 | PASS |
| Bucket 中无重复前缀 | PASS |
| 线上原图和视频 CDN 使用 `public/` 且抽查 200 | PASS |
| 图片样式在本地生成，`public/styles/` 为空 | PASS |
| 旧 `s3fs-public/` 仍可匿名读取 | PASS，这是旧副本，不是当前写入前缀 |
| `s3fs-private/` 边缘隔离 | WARNING |
| `private/` 匿名请求被 Cloudflare 403 | PASS。控制台规则原文 NOT TESTED |
| 迁移预检查与执行器前缀一致 | FAIL |
| 存储切换前缀与生产一致 | FAIL |
| `PublicUrl` 与 S3FS 在“路径已含文件夹名”时一致 | WARNING |
| 样式辅助 URL 与流包装器一致 | WARNING |
| 测试反映当前生产架构 | WARNING |
| PHP-FPM 请求内 `getenv()` 的原始值 | NOT TESTED。进程 environ 为空；线上 HTML 已证明前缀 |
| 用真实私有对象验证 CDN 读不到正文 | NOT TESTED。两个私有前缀都没有对象，审计没有上传 |

## 10. 写操作

没有业务写操作。

- 没有修改模块、主题、配置、`config/sync` 或数据库里的文件 URI。
- 没有 `PutObject`、`DeleteObject`、`CopyObject`，没有改对象元数据。
- R2 只调用了列表和 HEAD。CDN 和页面只做了 GET 或 HEAD。
- 没有运行会写入测试对象或存储日志的手动脚本。
- Drush 只执行了只读脚本和一条 SELECT。引导 Drupal 时框架有可能触碰缓存表，这次没有核对缓存表，也没有保存配置。
- 仓库里新增的只有本报告，并在 `docs/XEDC_PROGRESS.md` 末尾追加了一节。
