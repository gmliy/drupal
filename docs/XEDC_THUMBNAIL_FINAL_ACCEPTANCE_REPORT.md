# 缩略图修复最终验收报告

日期：2026-11-03  
站点：https://gitxx.xyz  
依据：`docs/XEDC_R2_IMAGE_STYLE_FIX_REPORT.md`  
本轮只做验收，没有改图片样式代码，没有重写模块。

## 结论

**PARTIAL**

已经上线的图片样式修复仍然有效。本轮实际请求到的 130 个图片样式地址，首次和再次请求都是图片响应，文件在本地，对应的新样式对象不在 R2。会员页、后台有缩略图的页面、编辑预览都按这个结果核对过。

不能记为 PASS 的原因：

- Cloudflare 上这条旧样式地址仍有边缘节点返回缓存的 404。站点没有已授权的按 URL 清理接口，本轮没有清理，也没有清空整个 CDN。
- 浏览器实际上传、替换和预览没有做。当前环境没有浏览器自动化工具，没有用 HTTP 冒充点击上传。
- 生产路由没有再次重建。重复重建只在内存里对现有路由跑了订阅器，没有在生产执行 `drush cr` 或重建容器。

## 1. 已修复的底层问题

本轮只读检查了这四个文件，没有改它们：

- `web/modules/custom/xedc_core/src/StreamWrapper/XedcPublicS3fsStream.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/ImageStyleFileSystem.php`
- `web/modules/custom/xedc_core/src/EventSubscriber/ImageStyleRouteSubscriber.php`
- `web/modules/custom/xedc_core/xedc_core.services.yml`

生产进程在接管开关下的当前状态：

| 项目 | 本轮读到的结果 |
| --- | --- |
| 公开包装器 | `XedcPublicS3fsStream`，`getDirectoryPath()` 为 `sites/default/files` |
| 私有包装器 | `Drupal\Core\StreamWrapper\PrivateStream` |
| 文件系统 | 外层 `ImageStyleFileSystem`，内层 `S3fsFileSystemD103` |
| 装饰优先级 | `xedc_core.image_style_files` 的 `decoration_priority` 仍是 `-10` |
| 公开接管 | 开 |
| 私有接管 | 关 |
| CDN | 开 |
| 图片样式路由 | `image.style_public` = `/sites/default/files/styles/{image_style}/{scheme}` |
| 公开样式封锁 | 当前路由表里没有 `image.style_public_s3fs_lockdown_public` |

公开原图仍在 R2。`s3fs-public/2026-11/IMG_3026.jpeg` 存在，628587 字节，`image/jpeg`。

样式继续在本地生成。`s3fs_file` 里 `public://styles/%` 的行数在整轮请求前后都是 0。抽查的新样式对象在 R2 上不存在：

- `s3fs-public/styles/thumbnail/public/2026-11/IMG_3026.jpeg.avif` 不存在
- `s3fs-public/styles/medium/public/2026-11/IMG_3026.jpeg.avif` 不存在
- `s3fs-public/styles/photo_card_640/public/2026-11/IMG_3026.jpeg.webp` 不存在
- `s3fs-public/styles/photo_card_640/public/2026-10/IMG_3224.webp` 不存在，而这个文件刚在本轮由页面请求写到本地

诊断阶段留下的旧对象还在，本轮没有删：

- `s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp`，49354 字节，`image/webp`

站点现在输出的样式地址带 `xedc_style=1`，仍指向本站 `/sites/default/files/styles/`，不指向 CDN，也不指向 R2 API。

## 2. Cloudflare 旧缓存

检查的地址：

`https://gitxx.xyz/sites/default/files/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp?itok=30XSRRlj`

源站（`127.0.0.1`，`Host: gitxx.xyz`）稳定返回 **200**，`Content-Type: image/webp`，正文是 480x800 的 WebP，49354 字节。

公网不稳定，按边缘节点分裂：

| 次序 | 节点 | HTTP | Content-Type | cf-cache-status | 正文 |
| --- | --- | --- | --- | --- | --- |
| 1 | HKG | 200 | image/webp | HIT | WebP |
| 2 | NRT | 200 | image/webp | HIT | WebP |
| 3 | NRT | 200 | image/webp | HIT | WebP |
| 4 | SIN | 404 | text/html | HIT | HTML |
| 5 | SIN | 404 | text/html | HIT | HTML |
| 6 | NRT | 200 | image/webp | HIT | WebP |
| 7 | HKG | 200 | image/webp | HIT | WebP |
| 8 | NRT | 200 | image/webp | HIT | WebP |

SIN 仍在提供修复前缓存的 404。HKG 和 NRT 已经是图片。项目配置 `purge_mode: manual`，代码和本环境都没有已授权的 Cloudflare 清理接口。本轮没有调用 Cloudflare API，没有改 Cloudflare 配置，没有清空整个 CDN。

需要管理员在 Cloudflare 控制台只清理这一条 URL：

1. 打开站点所在 Zone。
2. 进入 Caching → Configuration → Purge Cache → Custom Purge。
3. 选择按 URL 清理，只填上面这一条完整地址，包含 `?itok=30XSRRlj`。
4. 不要使用 Purge Everything。
5. 清理后分别从原先返回 404 的节点再请求一次，确认状态码是 200，`Content-Type` 是 `image/webp`。

## 3. 前台会员页面

使用了两个真实会员，没有造数据。

- `aa`：公开作品的作者。公开主页有图片和视频。会员中心里收藏为空。投稿列表有 20 条，模板不输出封面。
- `12shh`：收藏 6 条（图片 4、视频 2）。没有投稿。公开主页没有公开作品。

匿名访问 `/member`、`/member/favorites`、`/member/submissions` 都是 **302** 到 `/user/login?destination=...`，随后是登录页。登录页没有会员缩略图。这是预期行为。

登录使用一次性登录会话。页面走公网 `https://gitxx.xyz`。下表“样式数”是该页 HTML 里去重后的 `/sites/default/files/styles/` 地址数量，这些地址全部带 `xedc_style=1`。

| 页面 | 身份 | 页面状态 | 样式数 | 实际内容 |
| --- | --- | --- | --- | --- |
| `/member` | 匿名 | 302 后登录页 | 0 | 未进入会员中心 |
| `/member/favorites` | 匿名 | 302 后登录页 | 0 | 未进入会员中心 |
| `/member/submissions` | 匿名 | 302 后登录页 | 0 | 未进入会员中心 |
| `/r/aa` | 匿名 | 200 | 25 | 公开作品封面 |
| `/r/aa?type=photo` | 匿名 | 200 | 25 | 图片作品 |
| `/r/aa?type=video` | 匿名 | 200 | 11 | 视频作品 |
| `/r/12shh` | 匿名 | 200 | 1 | 只有头像。页面写明“还没有公开作品” |
| `/r/12shh?type=photo` | 匿名 | 200 | 1 | 同上，没有图片作品 |
| `/r/12shh?type=video` | 匿名 | 200 | 1 | 同上，没有视频作品 |
| `/member` | `12shh` | 200 | 9 | 会员中心，含收藏封面 |
| `/member/favorites` | `12shh` | 200 | 13 | 全部收藏 |
| `/member/favorites?type=photo` | `12shh` | 200 | 9 | 图片收藏 |
| `/member/favorites?type=video` | `12shh` | 200 | 5 | 视频收藏 |
| `/member/submissions` | `12shh` | 200 | 1 | 文案是“还没有投稿”，只有头像 |
| `/member/profile` | `12shh` | 200 | 1 | 头像 |
| `/r/12shh` | `12shh` 登录 | 200 | 1 | 与匿名相同 |
| `/member` | `aa` | 200 | 1 | 文案是“还没有收藏”，只有头像 |
| `/member/favorites` | `aa` | 200 | 1 | 没有收藏 |
| `/member/submissions` | `aa` | 200 | 1 | 20 张投稿卡片，模板不渲染封面，只有头像 |
| `/member/profile` | `aa` | 200 | 1 | 头像 |
| `/r/aa`、`?type=photo`、`?type=video` | `aa` 登录 | 200 | 25 / 25 / 11 | 与匿名 URL 完全相同 |

`/r/aa` 匿名和登录的样式 URL 集合一致，图片列表和视频列表也一致。

投稿列表没有封面是模板事实：`xedc-member-submissions.html.twig` 只输出标题、状态和操作，不输出 `<img>`。这不是样式生成失败。

这些页面里的样式地址全部进入了第 4 节之后的逐张请求。没有失败。本轮有两张公开主页用的 `photo_card_640` 是第一次请求时现生成的，见第 6 节。其余大多是磁盘上已经存在的衍生物，再次请求仍是 200。

用到的样式：`photo_card_640`、`max_1300x1300`、`video_cover`、`max_650x650`、`xa_cover`、`xa_hero`、`max_325x325`。头像是 `max_325x325` 的 AVIF。

## 4. 后台列表

管理员会话是 uid 1 的一次性登录，页面同样走公网。

| 页面 | 状态 | HTML 里的图片样式 | 说明 |
| --- | --- | --- | --- |
| `/admin/content` | 200 | 0 | 内容表有标题单元格，`<img>` 为 0。这个视图不渲染封面 |
| `/admin/content?type=photo&status=All` | 200 | 0 | 类型已选中图片，有标题单元格，没有缩略图 |
| `/admin/content?type=video&status=All` | 200 | 0 | 类型已选中视频，没有缩略图 |
| `/admin/content?type=article&status=All` | 200 | 0 | 类型已选中文章，没有缩略图 |
| `/admin/content/node` 以及带 `type=photo` 的同一路径 | 404 | 主题自带 1 个样式，不是列表缩略图 | 同步配置里的视图路径是这条，线上路由返回“页面未找到”。实际列表在 `/admin/content`。本轮没有改路由 |
| `/admin/content/moderated` | 200 | 0 | 有审核行，视图没有缩略图字段 |
| `/admin/xedc/ai/submissions` | 200 | 0 | 页面原文是“当前没有待审核的会员投稿”。没有可核对的投稿缩略图 |
| `/admin/xedc` | 200 | 8 | 控制台封面，全部是 `thumbnail` AVIF |
| `/admin/content/media` | 200 | 50 | 媒体库列表缩略图 |
| `/admin/content/media-grid` | 200 | 24 | 媒体库网格缩略图 |
| `/node/531/edit` | 200 | 6 | 图片内容预览，`medium` AVIF |
| `/node/129/edit` | 200 | 2 | 视频内容预览 |
| `/node/351/edit` | 200 | 0 | 封面控件在，里面没有已选图片，所以没有预览地址 |
| `/node/146/edit` | 200 | 1 | 文章封面预览：`medium` 的 `IMG_3224.webp.avif` |

控制台、媒体库、图片编辑、视频编辑、有封面的文章编辑，这些样式地址都在下面的逐张结果里。文章 nid 146 的预览单独请求了两次：第一次 **200** `image/avif`，本地文件存在，`file` 识别为 AVIF；第二次 **200** `image/avif`，`cf-cache-status: MISS`。

内容列表、审核列表、会员投稿审核页本身没有封面组件或当前没有数据。只验证了页面状态和 HTML，没有虚构缩略图。

## 5. 逐张图片请求

会员页、控制台、媒体库和编辑预览里去重后 **129** 个样式地址，再加上文章 nid 146 的 1 个预览。

129 个地址的结果：

- 第一次请求全部 **200**，`Content-Type` 为 `image/avif`（115）或 `image/webp`（14）。
- 第二次请求全部 **200**，类型仍是图片。第二次的 `cf-cache-status`：HIT 77、MISS 48、REVALIDATED 4。
- 129 个本地文件全部存在，没有缺失。
- 失败数 0。

样式分布：`thumbnail` 60、`medium` 25、`max_1300x1300` 17、`photo_card_640` 12、`max_650x650` 6、`video_cover` 5、`max_325x325` 2、`xa_cover` 1、`xa_hero` 1。

本轮请求期间新写入本地、时间在 2 分钟内的两张：

- `/sites/default/files/styles/photo_card_640/public/2026-10/IMG_3224.webp`
- `/sites/default/files/styles/photo_card_640/public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-039.jpg.webp`

两张都是第一次公网 **200** `image/webp`、`cf-cache-status: MISS`，第二次 **200**。`IMG_3224.webp` 这条在 R2 上不存在。这说明当前路由可以在首次访问时生成本地衍生物，并且没有把新样式上传到 R2。

其余衍生物在请求前就已经在磁盘上。本轮没有删除衍生物去强行重放“文件不存在”的路径。

## 6. 浏览器上传流程

**未完成。**

本环境没有浏览器自动化工具，也没有 Playwright 一类接口。没有打开内容创建页，没有选择文件，没有点击上传、保存或替换。没有用表单 HTTP 请求代替这次交互，也没有新建或删除内容。

因此以下各项都是 **未验证**：

- 浏览器里的上传控件和预览
- 保存前是否会误发布
- 通过浏览器上传后原图进入 R2、缩略图进入本地
- 再次进入编辑页的预览
- 替换图片

只读抽查已经说明现有原图在 R2、现有样式在本地。这不能代替浏览器上传。

## 7. 图片样式路由稳定性

本轮没有重建生产路由，没有执行 `drush cr`，没有重建容器，没有清空 Redis。

当前线上路由表：

- `image.style_public` 路径未变，控制器是 `ImageStyleDownloadController::deliver`。
- 不存在 `*_s3fs_lockdown_public`。
- 仍有两条 `s3` scheme 封锁：`image.style_public_s3fs_lockdown_s3` 和 `image.style_private_s3fs_lockdown_s3`。它们锁的是字面 scheme `s3`，不是 `public`。

在内存里用当前的 `image.style_public` 连续跑了三轮，没有写回路由表：

1. S3FS 订阅器会加上 `image.style_public_s3fs_lockdown_public`，路径是 `/sites/default/files/styles/{image_style}/public`，`_access` 为 FALSE。同时保留 `s3` 封锁。
2. `ImageStyleRouteSubscriber`（优先级 -200）每次都删掉路径里带 `/styles/` 且名称以 `_s3fs_lockdown_public` 结尾的路由。
3. 三轮之后公开封锁仍是 absent，`image.style_public` 路径不变，没有重复路由名。集合里只剩下原路由和 `s3` 封锁。

**未在本轮验证：** 再对生产执行一次真实路由重建之后的第一次图片生成。上一轮修复时做过一次生产重建；本轮按安全约束没有再做。当前线上路由已经能完成第 5 节里的首次生成。

## 8. 未完成事项

1. Cloudflare SIN 等节点上的旧 404 需要管理员按第 2 节清理单条 URL。
2. 浏览器上传、预览、替换没有执行。
3. 生产环境没有再做一次路由重建。
4. 诊断时写入 R2 的 `meitu_card_cover` 对象还在。它不是当前页面使用的本地文件。本轮没有删除任何 R2 对象。
5. `/admin/content/node` 线上 404。内容列表实际在 `/admin/content`，且该视图没有缩略图。未改。
6. 会员投稿列表模板不输出封面。会员投稿审核页当前没有待审数据。内容审核列表视图没有缩略图字段。

## 9. 生产安全检查

本轮没有做这些操作：

- 没有改 R2 Bucket、Endpoint、密钥、provider ID
- 没有改 Storage Switch 的正式运行状态
- 没有做 Local / R2 切换
- 没有删除正式 R2 对象、正式原图或私有文件
- 没有改第三方模块，没有改 `config/sync`
- 没有清空 Redis，没有执行 `drush cr`
- 没有清空整个 CDN
- 没有 `git reset`、`git checkout`、`git clean`、`git stash`、`git commit`、`git push`
- 没有覆盖工作区里已有的未提交修改
- 没有新建测试内容，没有删除业务图片

验收用的登录 Cookie 只放在临时目录，核对结束后已删除。
