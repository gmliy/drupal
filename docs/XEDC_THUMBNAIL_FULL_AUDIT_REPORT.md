# 全站缩略图排查报告

日期：2026-11-03  
站点：https://gitxx.xyz  
生产状态：Cloudflare R2，provider `silly`，`active.json` version 1，status active。本轮没有修改该文件。

## 结论

缩略图故障有两个叠在一起的原因，都已按现有架构修复。

1. 公开原图在 R2。s3fs 把元数据缓存未命中记成“文件不存在”，并一直记住这个结果。图片样式控制器用 `file_exists()` 判断原图，于是在读取 R2 之前返回 404，正文是“生成图像时出错，缺少源文件。”
2. 只存在于本地、尚未进入 R2 的公开原图，样式生成同样被判成缺少源文件。
3. 修复前，Cloudflare 把一部分样式 URL 的 HTML 404 缓存了 24 小时。源站修好后，这些旧地址在 Cloudflare 上仍是 404。页面现在输出带 `xedc_style=1` 的新地址，新地址返回图片。

图片样式仍写在服务器本地 `web/sites/default/files/styles/`。公开原图仍走 `https://cdn.gitxx.xyz`。没有把样式迁到 R2，没有切换存储，没有改 Bucket、Endpoint、密钥或 provider。

## 故障链路

```
图片字段 → file_managed URI public://
        → XedcPublicS3fsStream
        → s3fs url_stat 读到负缓存，file_exists() = false
        → ImageStyleDownloadController 返回 404（42 字节）
```

原图直链不走这条链路。例如 `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_2771.png` 为 200 `image/png`，3,920,503 字节。

Nginx 对 `/sites/default/files/styles/` 使用 `try_files`。派生文件不存在时才进入 Drupal。文件生成后，第二次请求由 Nginx 直接返回，状态 200。

## 代码修复

| 文件 | 改动 |
| --- | --- |
| `web/modules/custom/xedc_core/src/StreamWrapper/XedcPublicS3fsStream.php` | `url_stat()` 在 s3fs 负缓存后对对象做 HEAD，并用 `writeCache()` 写回正确元数据。对象不存在但本地原图存在时，只读回退到本地文件，供样式生成。写操作仍走对象存储。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/PublicUrl.php` | 样式 URL 增加 `xedc_style=1`。ImageStyle 继续在后面附加 `itok`。样式 URL 仍是本站路径，不是 CDN。 |

`config/sync`、Nginx、PHP-FPM、Redis 容器键和 `private/xedc-storage-runtime/active.json` 都没有改。

## 图片样式

现有样式没有新增：`images_450x800`、`max_1300x1300`、`max_2600x2600`、`max_325x325`、`max_650x650`、`media_library`、`medium`、`meitu_card_cover`、`photo_card_640`、`photo_gallery_1300`、`photo_gallery_650`、`thumbnail`、`video_cover`、`wide`、`xa_cover`、`xa_hero`、`xa_hero_840`。

WebP 与 AVIF 都在本地生成。抽查结果：`photo_gallery_650` 为 `image/webp`，编辑表单 `medium` 和媒体库为 `image/avif`。

## 页面清单

下表是本轮实际请求过的页面。样式 URL 均带 `xedc_style=1`。HTTP 通过 Cloudflare。修复后抽查为 200，Content-Type 为 `image/webp` 或 `image/avif`。

| 页面 | 路由 | 图片字段 / 样式 | 修复后 |
| --- | --- | --- | --- |
| 首页推荐与最新美图 | `/` | `field_cover_image`：`xa_hero`、`xa_hero_840`；卡片 `meitu_card_cover` | 42 个样式引用全部带标记。桌面截图缩略图可见。 |
| 图片列表 | `/cosplay`、`/cosplay/cos` | `photo_card_640`、`meitu_card_cover` | 200。手机截图卡片可见。分类 `/cosplay/cos`、`/cosplay/110`、`/cosplay/48` 抽查 200。 |
| 图片详情与相关图 | `/cosplay/cos/315` 等 | 原图 CDN；`photo_gallery_1300`、`photo_gallery_650` | 原图 200 `image/png`。相关图 `IMG_2771`、`IMG_2714`、`IMG_2715`、`IMG_2716` 均为 200 `image/webp`。 |
| 仅本地原图的详情 | `/cosplay/cos/531` | `photo_gallery_650`：`IMG_2714_0`、`IMG_2715_0`、`IMG_2716_0`、`IMG_2717_0` | 修复前 404 HTML。修复后源站生成本地 WebP，Cloudflare MISS 200 `image/webp`。 |
| 视频列表 | `/video` | `field_video_cover`：`video_cover` | 24 个样式引用。桌面截图 8 张封面可见。 |
| 文章列表与详情 | `/articles` 及文章别名 | `xa_cover`、`xa_hero` | 列表 10 个样式，详情抽查 200。桌面截图封面可见。 |
| 搜索 | `/search?keys=cos` | 与内容卡片相同 | 11–13 个样式，抽查 200。 |
| 标签 | `/tags`、`/tags/mote` | 标签页列表无样式；标签详情 2 个样式 | 200。 |
| 人物 | `/models`、`/models/80` | 人物封面样式 | 14 与 1 个样式，抽查 200。 |
| 公共会员主页 | `/r/aa`、`/r/12shh` | `photo_card_640`、`video_cover`、`xa_cover` | 25 与 1 个样式，抽查 200。 |
| 会员中心 | `/member` | 无内容缩略图，头像为字母 | 页面 200。管理员登录后截图正常。收藏为空是数据为空，不是破图。 |
| 会员投稿 / 收藏 | `/member/submissions`、`/member/favorites` | `thumbnail`、`max_325x325` | 各 2 个 AVIF，200 `image/avif`。 |
| 内容管理 | `/admin/content` | 默认视图没有缩略图列 | 管理员登录后页面正常，无破图。 |
| 内容编辑 | `/node/315/edit` | `medium` AVIF 预览 | 4 张预览均为 200 `image/avif`。截图中封面和图片集可见。 |
| 媒体库 | `/admin/content/media` | 媒体缩略图 AVIF | 50 个样式 URL 全部 200 `image/avif`。 |

匿名访问了前台页面。管理员登录后访问了内容列表、编辑表单、媒体库、会员中心、投稿和收藏。没有单独再登录一个非管理员会员账号和一个内容编辑账号；这两类账号使用同一套图片 URL 生成代码。

## HTTP 证据

修复前，公开样式 URL 中有 6 个返回 HTML 404。源站正文为“缺少源文件”。对应原图在 CDN 上为 200。

修复并预热后，当时采集到的 62 个样式 URL 在源站全部为 200。Cloudflare 对其中 4 个旧地址（没有 `xedc_style=1`）仍返回缓存的 HTML 404：

- `photo_gallery_650`：`IMG_2714.png.webp`、`IMG_2715.png.webp`、`IMG_2716.png.webp`
- `meitu_card_cover`：`IMG_3026.jpeg.webp`

复查其中一个旧地址：

- Cloudflare：404 `text/html`
- 源站 `--resolve gitxx.xyz:443:127.0.0.1`：200 `image/webp`

当前页面不再输出这些旧地址。带 `xedc_style=1` 的同一张图为 200 `image/webp`。没有 Cloudflare API token，不能主动清理这 4 个旧缓存。TTL 到期后旧地址也会变成 200，因为本地文件已经存在。

## 样本

| 样本 | 原图 | 样式 | 结果 |
| --- | --- | --- | --- |
| JPEG，CDN 原图 | `2026-10/IMG_3009.jpeg`，163,198 字节 | 首页样式 | SHA-256 与 R2 一致。样式 200。 |
| PNG，CDN 原图 | `2026-11/IMG_2771.png`，3,920,503 字节 | `photo_gallery_1300` WebP | 原图与样式均为 200。 |
| 此前失败的 PNG | `2026-11/IMG_2714.png` | `photo_gallery_650` WebP，18,888 字节 | 本地文件已生成。新 URL 200，旧 URL 在 Cloudflare 仍为 404。 |
| 只在本地的 PNG | `IMG_2714_0.png` 等 4 张 | `photo_gallery_650` | 200 `image/webp`。`IMG_2714_0.png` 与 `IMG_2714.png` 源文件 SHA-256 相同，派生图相同是重复文件，不是串图。 |
| 编辑表单 | `IMG_2771.png` | `medium` AVIF | 200 `image/avif`。 |
| 会员列表 JPEG | `pictures/IMG_9619.jpeg` | `thumbnail`、`max_325x325` AVIF | 200 `image/avif`。 |

第二次请求同一派生文件仍是 200，由 Nginx 直接返回。样式目录现有 822 个文件。修复前约 792 个；增加的是这次请求实际生成的派生文件，没有删除任何原图。

## 未当作样式故障的情况

`/admin/content` 的默认内容表没有缩略图列。会员中心“最近收藏”为空，是该管理员没有收藏。`/tags` 词汇表页本身没有图片样式。
