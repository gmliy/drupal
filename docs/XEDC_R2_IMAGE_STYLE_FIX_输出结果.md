# R2 接管后图片样式修复：任务输出

日期：2026-11-02  
站点：https://gitxx.xyz  
详细证据：`docs/XEDC_R2_IMAGE_STYLE_FIX_REPORT.md`

公开原图继续放在 Cloudflare R2，经 `cdn.gitxx.xyz` 访问。图片样式在本机 `sites/default/files/styles/` 生成。样式 URL 的格式没有改，变的是还没生成的派生图不再返回 HTML 404，也不再被上传到 R2。

## 根因

两段都已用 HTTP 和对象请求证实。

1. **还没有派生文件时，请求进不了图片样式控制器。**  
   接管后的公开包装器 `XedcPublicS3fsStream` 继承 S3FS，`getDirectoryPath()` 返回空字符串。Drupal 只用这个目录识别 `/sites/default/files/styles/`。目录为空时，路径不会被改写成样式路由。磁盘上已有的旧缩略图仍由 Nginx 直接返回 200；不存在的派生图变成整页 HTML 404。

2. **样式一旦生成，会被上传到 R2。**  
   公开 scheme 不是本地流。GD 先写临时文件，再由 `S3fsFileSystemD103::move()` 对 `public://` 执行 `putObject`。修复前对 `meitu_card_cover` 生成成功，但本地文件不存在，桶里出现了样式对象。

已排除：样式 URL 被改到 CDN、路径重复前缀、CSP 拦截、WebP/AVIF 不可用、Nginx 不能读样式目录、生产进程读不到 R2 原图。itok 与 `ImageStyle::buildUrl()` 一致。

S3FS 在路由重建时还会封锁 public 图片样式路由。修复前路由器里还没有这条路由，所以它不是当时 404 的原因。路由订阅器会在以后的重建里去掉它，并已验证重建后封锁路由不存在。

## 修复文件

| 文件 | 作用 |
| --- | --- |
| `web/modules/custom/xedc_core/src/StreamWrapper/XedcPublicS3fsStream.php` | 样式目录改回本地 `sites/default/files`，样式 `realpath()` 指向本地文件。原图外部地址不变。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ImageStyleFileSystem.php` | 只把 `public://styles/` 的复制、移动、保存和删除留在本地。其他公开文件仍交给 S3FS。 |
| `web/modules/custom/xedc_core/src/EventSubscriber/ImageStyleRouteSubscriber.php` | 路由重建时移除 public 图片样式封锁。 |
| `web/modules/custom/xedc_core/xedc_core.services.yml` | 注册上面两个服务。 |

没有改 `config/sync`、环境变量、桶、密钥或第三方模块。没有执行 `drush cr`，没有清空 Redis。只重建了容器和路由表。Redis 键数量从约 15980 变为 15983。

重建后：公开包装器仍是 `XedcPublicS3fsStream`，私有包装器仍是 `PrivateStream`，公开接管和 CDN 仍开启，私有接管仍关闭。

## 修改前后的 URL

地址没变，响应变了。

| 场景 | URL | 修复前 | 修复后 |
| --- | --- | --- | --- |
| 列表缩略图 | `https://gitxx.xyz/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj` | 404，`text/html` | 源站 200，`image/webp`，49354 字节。公网仍可能命中修复前缓存的 404。 |
| 旧样式 | `https://gitxx.xyz/sites/default/files/styles/xa_hero/public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-038.jpg.webp?itok=_UZaFB58` | 200，`image/webp` | 200，`image/webp` |
| 原图 | `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` | 可访问 | 200，`image/jpeg` |

## R2 原图读取

`public://2026-11/IMG_3026.jpeg`：

- 数据库记录 628587 字节。
- 本地 `sites/default/files/2026-11/IMG_3026.jpeg` 不存在。
- 生产凭证下读到 JPEG 头 `ffd8ffe0`，尺寸 960×2080，`image/jpeg`。
- 详情页地址 `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` 为 200 `image/jpeg`。

新上传探针只把原图放进 R2：`s3fs-public/_xedc_image_style_probe/pixel.png`，CDN 200 `image/png`，70 字节。

## 本地缩略图

| 本地文件 | 结果 |
| --- | --- |
| `styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp` | WebP 480×800。删除后源站再次请求可重新生成，属主 `www-data`。 |
| `styles/thumbnail/public/2026-11/IMG_3026.jpeg.avif` | AVIF，1450 字节。 |
| `styles/video_cover/public/2026-11/IMG_3026.jpeg.avif` | 首次 HTTP 请求生成，200 `image/avif`。 |
| `styles/medium/public/2026-11/IMG_3026.jpeg.avif` | 后台预览。对应 R2 键不存在。 |
| `styles/thumbnail/public/_xedc_image_style_probe/pixel.png.avif` | 新上传原图的样式，只在本地。 |

`s3fs_file` 中 `public://styles/%` 记录数为 0。

诊断时已经写入 R2、且没有删除的样式对象：

- 键：`s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp`
- ETag：`fb198d751577a0e2fb74d5de0e32d9a7`
- 大小：49354

后续生成本地文件时 ETag 没变。站点读的是本地文件。

## 前台和后台 HTTP 验证

没有浏览器自动化。结果来自源站 `Host: gitxx.xyz` 和公网 `https://gitxx.xyz` 的真实请求。后台编辑页用一次性登录打开。

| 检查项 | 请求 | 状态 | Content-Type | 结果 |
| --- | --- | --- | --- | --- |
| 已有 R2 图片缩略图 | `/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj` | 源站 200 | `image/webp` | 通过。公网仍是缓存 404。 |
| 新上传原图 | `https://cdn.gitxx.xyz/s3fs-public/_xedc_image_style_probe/pixel.png` | 200 | `image/png` | 原图在 R2。 |
| 新上传缩略图 | `/styles/thumbnail/public/_xedc_image_style_probe/pixel.png.avif?itok=KSLdmYvm` | 源站 200，边缘 200 MISS | `image/avif` | 通过。样式不在 R2。 |
| 详情页原图 `/cosplay/cos/531` | `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` | 200 | `image/jpeg` | 通过。 |
| 详情页样式 | `/styles/photo_gallery_1300/public/2026-11/IMG_3026.jpeg.webp?itok=A10ugAbx` | 源站 200，边缘 200 MISS | `image/webp` | 通过。 |
| 列表 `/meitu` | 页面 200。`/styles/meitu_card_cover/public/2026-11/IMG_2771.png.webp?itok=b98V0ssF` | 源站 200，边缘 200 | `image/webp` | 通过。 |
| 分类 `/cosplay` | 页面 200。`...-041.jpg.webp?itok=Cva5YoTR` | 200 | `image/webp` | 通过。 |
| 标签 `/tags/luoli` | 页面 200。`...ceshidierciquzhong-003.jpg.webp?itok=bZ45nbjO` | 源站 200，边缘 200 | `image/webp` | 通过。 |
| 视频 `/video` | `/styles/video_cover/public/gallery/2026-10/piyiercishangchuandian-001.jpg.avif?itok=naE_ejPo` | 源站 200，边缘 200 | `image/avif` | 通过。 |
| 后台 `/node/531/edit` | 页面 200。`/styles/medium/public/2026-11/IMG_3026.jpeg.avif?itok=Vn7gGe-0` | 源站 200，边缘 200 MISS | `image/avif` | 通过。同页另两张 medium 预览也是 200 AVIF。 |
| 媒体库样式 | `/styles/media_library/public/2026-11/IMG_3026.jpeg.avif?itok=AWnrXbW4` | 源站 200，边缘 200 MISS | `image/avif` | 通过。 |
| 样式缓存失效后重生成 | 删除本地 `meitu_card_cover` 后再请求源站 | 200 | `image/webp` | 通过。只删了这一条 404 页面缓存和对应路由缓存。 |
| 样式不上传 R2 | `s3fs-public/styles/medium/public/2026-11/IMG_3026.jpeg.avif` 和探针样式键 | 对象不存在 |  | 通过。 |

## 对 R2 生产接管的影响

没有改变接管状态。公开原图继续进 R2，并由 `cdn.gitxx.xyz` 提供。私有 URI 不会改写成 CDN，私有包装器仍是本地 `PrivateStream`。

没有改桶、端点、密钥或环境变量，没有迁移或删除原图。诊断时写入的那个样式对象还在桶里。验证新上传时新增了 70 字节探针原图 `s3fs-public/_xedc_image_style_probe/pixel.png`，没有删除。

## 未解决问题

1. 公网仍缓存着修复前的 404：`https://gitxx.xyz/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj`。源站已是 200 `image/webp`，边缘是 `cf-cache-status: HIT` 的 HTML 404，`Cache-Control: max-age=86400`。没有 Cloudflare 清理凭证，所以没有清这条边缘缓存。没有被缓存成 404 的样式，公网已经是 200。
2. `file_managed` 有 9 条 `private://` 记录在流上不存在，S3 元数据里也没有；私有目录磁盘上另有 27 个文件。这不是本次改动造成的。私有包装器和私有 URL 策略没有改。
3. 没有在图形浏览器里点击上传控件。后台核对的是编辑表单实际输出的预览 URL，以及一次经公开流的新上传。
