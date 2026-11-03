# 缩略图修复最终验收：任务输出

日期：2026-11-03  
站点：https://gitxx.xyz  
详细证据：`docs/XEDC_THUMBNAIL_FINAL_ACCEPTANCE_REPORT.md`  
依据：`docs/XEDC_R2_IMAGE_STYLE_FIX_REPORT.md`

本轮没有改代码，没有重写模块。公开原图继续在 Cloudflare R2，图片样式继续在本机 `sites/default/files/styles/` 生成。

## 结论

**PARTIAL**

页面里实际输出的图片样式地址，请求结果都是图片，文件在本地，新样式没有进 R2。不能记为 PASS，因为旧 404 仍留在部分 Cloudflare 节点，浏览器上传没有做，生产路由也没有再重建一次。

## 底层修复仍在生效

只读确认了四个文件，没有改它们：

| 文件 | 当前作用 |
| --- | --- |
| `XedcPublicS3fsStream.php` | 公开原图走 R2。样式目录是本地 `sites/default/files`。 |
| `ImageStyleFileSystem.php` | `public://styles/` 留在本地。外层装饰器，内层仍是 `S3fsFileSystemD103`。 |
| `ImageStyleRouteSubscriber.php` | 去掉 public 图片样式封锁。优先级 -200。 |
| `xedc_core.services.yml` | `decoration_priority: -10`，路由订阅器已注册。 |

生产状态：公开接管开，私有接管关，CDN 开。私有包装器仍是 `PrivateStream`。`image.style_public` 仍是 `/sites/default/files/styles/{image_style}/{scheme}`。路由表里没有 `image.style_public_s3fs_lockdown_public`。

原图 `s3fs-public/2026-11/IMG_3026.jpeg` 在 R2，628587 字节，`image/jpeg`。`s3fs_file` 中 `public://styles/%` 为 0。抽查的 thumbnail、medium、photo_card_640 样式对象都不在 R2。诊断时留下的 `meitu_card_cover` 对象还在（49354 字节），本轮没有删。

## Cloudflare 旧 404

地址：

`https://gitxx.xyz/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj`

源站稳定 **200** `image/webp`，480×800，49354 字节。公网按节点分裂：HKG、NRT 为 200 `image/webp`；SIN 仍是缓存的 **404** `text/html`。

没有已授权的按 URL 清理接口。没有调用 Cloudflare API，没有清空整个 CDN。

管理员只需在 Cloudflare 控制台按这一条完整 URL 做 Custom Purge，不要使用 Purge Everything。清理后再确认状态码 200、`Content-Type: image/webp`。

## 前台会员页

真实会员，没有造数据。`aa` 有公开图片和视频，收藏为空，投稿 20 条但模板不输出封面。`12shh` 有收藏 6 条（图片 4、视频 2），没有投稿，公开主页没有作品。

匿名访问 `/member`、收藏和投稿会 302 到登录页。公开主页匿名和登录看到的样式 URL 相同。

| 页面 | 结果 |
| --- | --- |
| `/r/aa`、`?type=photo` | 200，25 个样式地址 |
| `/r/aa?type=video` | 200，11 个样式地址 |
| `/r/12shh` 及图片、视频筛选 | 200，只有头像。页面写明“还没有公开作品” |
| `/member`、收藏（`12shh`） | 200。全部收藏 13 个样式，图片 9 个，视频 5 个 |
| `/member/submissions`（`12shh`） | 200。“还没有投稿”，只有头像 |
| `/member`、收藏（`aa`） | 200。“还没有收藏”，只有头像 |
| `/member/submissions`（`aa`） | 200。20 张卡片，模板没有封面图 |

这些地址都带 `xedc_style=1`，指向本站 `/sites/default/files/styles/`。

## 后台列表

管理员一次性登录后走公网核对。HTML 里没有缩略图的页面，只记录页面本身，没有补造图片。

| 页面 | 结果 |
| --- | --- |
| `/admin/content` | 200。有内容行，0 张图。视图不渲染封面 |
| `?type=photo` / `video` / `article` | 200。类型筛选生效，0 张缩略图 |
| `/admin/content/node` | 404。线上列表在 `/admin/content`。未改路由 |
| `/admin/content/moderated` | 200。有审核行，0 张图 |
| `/admin/xedc/ai/submissions` | 200。“当前没有待审核的会员投稿” |
| `/admin/xedc` | 200。8 张 `thumbnail` |
| `/admin/content/media` | 200。50 张缩略图 |
| `/admin/content/media-grid` | 200。24 张缩略图 |
| `/node/531/edit` | 200。6 张 `medium` 预览 |
| `/node/129/edit` | 200。2 张预览 |
| `/node/351/edit` | 200。封面控件为空，没有预览地址 |
| `/node/146/edit` | 200。1 张文章封面，两次请求都是 200 `image/avif` |

## 逐张请求

会员页、控制台、媒体库和编辑预览去重后 129 个样式地址：

- 第一次全部 **200**，`image/avif` 115 个，`image/webp` 14 个。
- 第二次全部 **200**。缓存 HIT 77、MISS 48、REVALIDATED 4。
- 本地文件 129 个都在。失败数 0。

本轮首次生成的两张 `photo_card_640` 也是 200 `image/webp`，只写在本地。其中 `IMG_3224.webp` 的 R2 键不存在。

## 浏览器上传

**未完成，未验证。**

没有浏览器自动化工具。没有打开创建页，没有选择文件、上传、预览、保存或替换，也没有用 HTTP 表单代替点击。没有新建或删除内容。

## 路由

本轮没有重建生产路由，没有 `drush cr`，没有重建容器，没有清空 Redis。

内存里连续三轮：S3FS 会加上 public 封锁，`ImageStyleRouteSubscriber` 每次都去掉它。三轮后公开封锁不在，原路由路径不变，没有重复路由名。`s3` scheme 的封锁还在，它不拦截 `public`。

再做一次生产路由重建后的首次生成，本轮没有验证。

## 生产安全

没有改 Bucket、Endpoint、密钥、provider ID 和 Storage Switch。没有做 Local / R2 切换。没有删除正式 R2 对象、原图或私有文件。没有改第三方模块和 `config/sync`。没有清空 Redis 或整个 CDN。没有执行 Git 写操作，也没有覆盖已有未提交修改。登录 Cookie 核对后已从临时目录删除。
