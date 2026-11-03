# 缩略图修复验收

日期：2026-11-03  
判定：**PASS**

当前页面上的缩略图可以生成并显示。公开原图仍在 R2/CDN。派生文件在本地。生产存储模式仍是 `cloudflare_r2` version 1 active。

## 修复前后

| 项目 | 修复前 | 修复后 |
| --- | --- | --- |
| R2 原图 `file_exists()` | 元数据负缓存导致 false | HEAD 后为 true，`IMG_2714.png` 大小 4,391,633 |
| 源站样式请求 | 404，正文“缺少源文件” | 200，`image/webp` 或 `image/avif`，文件写到 `sites/default/files/styles/` |
| 只在本地的 4 张 PNG | 样式 404 | 只读回退本地原图后，样式 200 |
| Cloudflare 旧样式 URL | HTML 404，`cf-cache-status: HIT` | 旧 URL 仍可能 HIT 404；页面已改用 `xedc_style=1`，新 URL 为 200 |
| CDN 原图 | 200 | 抽查 `IMG_2771.png` 仍为 200 `image/png` |

## 浏览器

截图在无头 Chromium 中打开真实站点，不是只看 HTML。

| 视口 | 页面 | 结果 |
| --- | --- | --- |
| 桌面 1440 | `/` | 轮播和最新美图缩略图可见 |
| 手机 390 | `/cosplay` | 两列卡片缩略图可见，比例正常 |
| 桌面 | `/cosplay/cos/315` | 原图可见 |
| 桌面 | `/video` | 8 张视频封面可见 |
| 桌面 | `/articles` | 文章封面可见 |
| 桌面，管理员 | `/admin/content` | 列表打开。该视图没有缩略图列 |
| 桌面，管理员 | `/node/315/edit` | 封面和图片集预览可见 |
| 桌面，管理员 | `/member` | 会员中心打开。收藏为空 |

另外用真实 HTTP 检查了搜索、标签、人物、公共会员主页、媒体库、会员投稿和收藏。媒体库 50 个 AVIF 全部 200。没有发现 403 或 500。

没有单独记录浏览器 Console。页面截图里没有破图图标。没有为了掩盖错误而换占位图或改卡片尺寸。

## 身份

- 匿名：首页、图片、视频、文章、搜索、标签、人物、公共会员主页。
- 管理员：内容列表、编辑表单、媒体库、会员中心、投稿、收藏。
- 普通会员和内容编辑账号没有另行登录。他们的前台页面与匿名使用同一套 URL。

## 架构边界

- 公开原图：R2，CDN `https://cdn.gitxx.xyz`。
- 样式、WebP、AVIF：本地 `web/sites/default/files/styles/`。
- 私有文件包装器：`Drupal\Core\StreamWrapper\PrivateStream`。
- 公开包装器：`Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。
- 没有启用 Private Takeover，没有改供应商配置。

## 遗留

1. 不带 `xedc_style=1` 的 4 个旧样式 URL，Cloudflare 仍返回缓存的 HTML 404。源站已是 200。当前 HTML 不引用这些旧 URL。服务器上没有 Cloudflare API token，不能清除缓存。
2. `IMG_2398.png` 只在本地。本轮没有为它单独打开页面。读取回退对所有“桶中不存在但本地有原图”的文件生效，不限于已经打开过的 4 张。
3. 5 个本地独有文件和 2 个 R2 独有文件没有补齐。这是存储一致性问题，不是本轮允许做的正式复制。
