# R2 接管后图片样式修复报告

日期：2026-11-02  
站点：https://gitxx.xyz  
范围：公开原图继续留在 Cloudflare R2，图片样式继续在本机生成。

## 1. 缩略图异常根因

失败发生在两个已经用 HTTP 和对象存储请求证实的环节。图片 URL 本身没有改成 CDN，也没有重复前缀。

### 已证实

1. **派生图片还不存在时，请求到不了 Drupal 图片样式控制器。**

   公开流包装器在接管后是 `XedcPublicS3fsStream`。它继承的 S3FS 包装器把 `getDirectoryPath()` 固定返回空字符串。Drupal 的 `PathProcessorImageStyles` 用这个目录判断哪些地址属于图片样式。空目录只会匹配 `//styles/`，不会匹配实际地址 `/sites/default/files/styles/...`。

   文件已经在磁盘上时，Nginx `try_files` 直接返回图片，所以接管前生成过的缩略图仍然是 200。文件不存在时，请求进入 Drupal，但路径没有被改写成图片样式路由，结果是整页 HTML 404，不是图片。

   证据：

   - 修复前 `getDirectoryPath()` 为 `[]`，路由仍是旧的 `/sites/default/files/styles/{image_style}/{scheme}`。
   - `https://gitxx.xyz/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj` 返回 **404**，`Content-Type: text/html`，响应头带 `X-Generator: Drupal 11`。
   - 同一页面上更早生成的样式，例如 `xa_hero` 的 WebP，返回 **200** `image/webp`。
   - 原图 `public://2026-11/IMG_3026.jpeg` 不在本地磁盘，但在生产凭证下可以读取，`getimagesize` 得到 `960x2080 image/jpeg`。itok 与 `ImageStyle::buildUrl()` 一致，不是令牌错误。

2. **图片样式一旦真正生成，会被 S3FS 的文件系统装饰器上传到 R2。**

   公开 scheme 不是本地流。GD 先把结果写到临时文件，再调用 `file_system->move()`。`S3fsFileSystemD103::move()` 看到目标是 `public://`，就执行 `putObject`，不会走包装器里“样式留在本地”的分支。

   证据：修复前对 `meitu_card_cover` 调用 `createDerivative()` 返回成功，本地文件不存在，`s3fs_file` 出现样式 URI，并且对象 `s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp` 已经在桶里（49354 字节）。修复之后重新生成本地文件时，这个对象的 ETag 没有变化，说明没有再次上传。

### 已排除

- 样式 URL 被改到 `cdn.gitxx.xyz`，或出现 `s3fs-public/styles` 重复前缀。列表页输出的样式地址仍是 `https://gitxx.xyz/sites/default/files/styles/...`。
- CSP 拦截。`img-src` 包含 `'self'` 和 `https://cdn.gitxx.xyz`。同页已有样式图片可以显示。
- GD、WebP、AVIF 不可用。`imagewebp()` 和 `imageavif()` 都存在，生成结果分别是合法 WebP 和 AVIF。
- Nginx 不能读 `styles` 目录。目录权限允许 `www-data` 读写；文件生成后由 Nginx 直接返回 200。
- 生产环境读不到 R2 原图。使用与 PHP-FPM 相同的接管开关后，原图可以打开。不带这些开关的 CLI 会落到实例元数据凭证，那只是诊断进程的环境差异，不是站点进程的失败原因。

### 推测但未当成根因

S3FS 还会在路由重建时给 `image.style_public` 加一条 `public` scheme 的拒绝路由。这次修复前，路由器里还没有这条路由，所以它不是当前 404 的原因。路由重建时如果留下它，图片样式会再次失败。修复里的路由订阅器会在重建时去掉这条 public 样式封锁，并已用一次路由重建验证封锁路由不存在。

## 2. 修复文件

| 文件 | 作用 |
| --- | --- |
| `web/modules/custom/xedc_core/src/StreamWrapper/XedcPublicS3fsStream.php` | `getDirectoryPath()` 返回本地 `sites/default/files`。样式 URI 的 `realpath()` 指向本地文件。原图外部地址逻辑不变。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ImageStyleFileSystem.php` | 包在 S3FS 文件系统外面。只有 `public://styles/` 的复制、移动、保存和删除走本地磁盘。其他公开文件仍交给 S3FS。 |
| `web/modules/custom/xedc_core/src/EventSubscriber/ImageStyleRouteSubscriber.php` | 路由重建时移除 S3FS 对 public 图片样式的封锁路由。 |
| `web/modules/custom/xedc_core/xedc_core.services.yml` | 注册上述文件系统装饰器和路由订阅器。 |

没有修改 `config/sync`、环境变量、桶、密钥、第三方模块。没有执行 `drush cr`，也没有清空 Redis。为了让新服务生效，并确认路由重建后样式路由仍可用，只重建了容器和路由表。Redis 键数量从约 15980 变为 15983。

重建容器时使用了与 PHP-FPM 相同的公开接管开关。重建后的结果：

- 公开包装器仍是 `XedcPublicS3fsStream`
- 私有包装器仍是 `Drupal\Core\StreamWrapper\PrivateStream`
- `s3fs.use_s3_for_public = true`
- `s3fs.use_s3_for_private = false`
- CDN 开关仍开启

## 3. 修改前后的图片 URL

样式 URL 的格式没有改。变化在响应，不在地址。

| 场景 | URL | 修复前 | 修复后 |
| --- | --- | --- | --- |
| 列表缩略图 | `https://gitxx.xyz/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj` | 404，`text/html` | 源站 200，`image/webp`，49354 字节。边缘仍可能命中修复前缓存的 404，见第 8 节。 |
| 旧样式 | `https://gitxx.xyz/sites/default/files/styles/xa_hero/public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-038.jpg.webp?itok=_UZaFB58` | 200，`image/webp` | 200，`image/webp` |
| 原图 | `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` | 可访问 | 200，`image/jpeg` |

## 4. R2 原图读取

`public://2026-11/IMG_3026.jpeg`：

- 数据库记录存在，628587 字节。
- 本地 `sites/default/files/2026-11/IMG_3026.jpeg` 不存在。
- 生产凭证下读取到 JPEG 文件头 `ffd8ffe0`，`getimagesize` 为 960×2080，`image/jpeg`。
- 详情页原图地址是 `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg`，HTTP 200，`image/jpeg`。

新上传的 1×1 探针也只把原图放进 R2：

- 对象：`s3fs-public/_xedc_image_style_probe/pixel.png`
- CDN：`https://cdn.gitxx.xyz/s3fs-public/_xedc_image_style_probe/pixel.png`
- HTTP 200，`image/png`，70 字节。

## 5. 本地缩略图生成

| 派生文件 | 结果 |
| --- | --- |
| `sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp` | 本地 WebP，480×800。删除后再次请求源站，由 PHP 重新生成，属主 `www-data`，200 `image/webp`。 |
| `sites/default/files/styles/thumbnail/public/2026-11/IMG_3026.jpeg.avif` | 本地 AVIF，1450 字节。 |
| `sites/default/files/styles/video_cover/public/2026-11/IMG_3026.jpeg.avif` | 首次 HTTP 请求触发生成，200 `image/avif`。 |
| `sites/default/files/styles/medium/public/2026-11/IMG_3026.jpeg.avif` | 后台编辑表单使用的预览，本地 AVIF。对应 R2 键不存在。 |
| `sites/default/files/styles/thumbnail/public/_xedc_image_style_probe/pixel.png.avif` | 新上传原图的样式，只在本地。R2 上没有这个样式键。 |

`s3fs_file` 中 `public://styles/%` 的记录数是 0。修复后的样式没有写入对象缓存，也没有新的样式对象。

诊断阶段已经上传到 R2 的那一个样式对象没有删除，也没有被后续生成覆盖：

- 键：`s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp`
- ETag：`fb198d751577a0e2fb74d5de0e32d9a7`
- 大小：49354

站点实际读取的是本地同名文件。

## 6. 前台和后台验证

没有可用的浏览器自动化工具。下面是对源站 `https://127.0.0.1`（`Host: gitxx.xyz`）和公网 `https://gitxx.xyz` 的真实 HTTP 请求。后台编辑页使用一次性登录后在源站打开，页面 HTTP 200，并取出图片字段实际输出的预览地址再请求。

| 检查项 | URL | 状态 | Content-Type | 结果 |
| --- | --- | --- | --- | --- |
| 已有 R2 图片生成缩略图 | `/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj` | 源站 200 | `image/webp` | 通过。边缘仍是缓存的 404。 |
| 新上传图片 | 原图 CDN `/s3fs-public/_xedc_image_style_probe/pixel.png` | 200 | `image/png` | 原图在 R2。 |
| 新上传图片的缩略图 | `/sites/default/files/styles/thumbnail/public/_xedc_image_style_probe/pixel.png.avif?itok=KSLdmYvm` | 源站 200，边缘 200 MISS | `image/avif` | 通过，样式不在 R2。 |
| 详情页原图 | `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` | 200 HIT | `image/jpeg` | 通过。页面 `/cosplay/cos/531` 的原图使用 `cdn.gitxx.xyz`。 |
| 详情页样式 | `/sites/default/files/styles/photo_gallery_1300/public/2026-11/IMG_3026.jpeg.webp?itok=A10ugAbx` | 源站 200，边缘 200 MISS | `image/webp` | 通过。 |
| 图片列表 `/meitu` | 页面 200。示例 `/styles/meitu_card_cover/public/2026-11/IMG_2771.png.webp?itok=b98V0ssF` | 源站 200，边缘 200 | `image/webp` | 通过。 |
| 分类 `/cosplay` | 页面 200。示例 `.../cosplmojixiaolilihaoduohaoduohaoduo-041.jpg.webp?itok=Cva5YoTR` | 200 | `image/webp` | 通过。 |
| 标签 `/tags/luoli` | 页面 200。示例 `.../ceshidierciquzhong-003.jpg.webp?itok=bZ45nbjO` | 源站 200，边缘 200 | `image/webp` | 通过。 |
| 视频封面 `/video` | `.../styles/video_cover/public/gallery/2026-10/piyiercishangchuandian-001.jpg.avif?itok=naE_ejPo` | 源站 200，边缘 200 | `image/avif` | 通过。 |
| 后台编辑页预览 | `/node/531/edit` 页面 200。预览 `/styles/medium/public/2026-11/IMG_3026.jpeg.avif?itok=Vn7gGe-0` | 源站 200，边缘 200 MISS | `image/avif` | 通过。同页另外两张 medium 预览也是 200 AVIF。 |
| 媒体库样式 | `/styles/media_library/public/2026-11/IMG_3026.jpeg.avif?itok=AWnrXbW4` | 源站 200，边缘 200 MISS | `image/avif` | 通过。 |
| WebP | `meitu_card_cover`、`photo_gallery_1300` | 200 | `image/webp` | 通过。 |
| AVIF | `thumbnail`、`medium`、`video_cover` | 200 | `image/avif` | 通过。 |
| 删除本地样式后再请求 | 删除 `IMG_3026` 的 `meitu_card_cover` 后请求源站 | 200 | `image/webp` | 重新生成。请求前先删掉了这一条已被缓存的 404 页面缓存和对应路由缓存，没有清空 Redis。 |
| 样式不进入 R2 | `s3fs-public/styles/medium/public/2026-11/IMG_3026.jpeg.avif` 以及探针样式键 | 对象不存在 |  | 通过。`s3fs_file` 样式记录为 0。 |
| CDN 原图 | `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` | 200 | `image/jpeg` | 通过。 |

## 7. 对现有 R2 生产接管的影响

公开接管保持开启，私有接管保持关闭。公开原图继续写入并经由 `cdn.gitxx.xyz` 读取。私有 URI 不会被改写成 CDN 地址，私有包装器仍是本地 `PrivateStream`。

没有修改桶、端点、密钥、环境变量，没有迁移或删除原图。唯一一个在修复前诊断时写入 R2 的样式对象保留在桶中，后续本地生成没有覆盖它。验证新上传时新增了一个 70 字节探针原图 `s3fs-public/_xedc_image_style_probe/pixel.png`，没有删除。

## 8. 仍存在的问题

1. **Cloudflare 还缓存着修复前的 404。**  
   `https://gitxx.xyz/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj` 源站已经是 200 `image/webp`，公网仍是 `cf-cache-status: HIT` 的 404 HTML。这条 404 的 `Cache-Control` 是 `max-age=86400`。没有可用的 Cloudflare 清理凭证，所以没有清理边缘缓存。没有被缓存成 404 的样式地址，公网请求已经是 200。

2. **私有文件记录和磁盘不一致，但这不是本次改动引入的。**  
   `file_managed` 里有 9 条 `private://` 记录，流上都不存在，S3 元数据缓存里也没有。私有目录磁盘上另有 27 个文件。本次没有改私有包装器，也没有改这些文件。私有地址不会被改写到 CDN。

3. **没有在图形浏览器里点击上传控件。**  
   后台验证的是编辑表单实际渲染出的预览 URL，以及一次经过公开流的新上传。上传控件的点击过程没有在浏览器里操作。
