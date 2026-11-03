# XEDC 视频专区第三次 UI 精修与会员公开主页

日期：2026-11-03。站点：https://gitxx.xyz。没有提交 Git，没有推送。

截图目录：`docs/video-ui-refinement-round2/`。测量原文在同目录 `browser-report.json`。

## 1. 原始问题及根因

视频首页在展示推荐大卡时，用 CSS 把网格里的第一行隐藏掉，选择器是 `.xv-page--hero .xv-grid > .views-row:first-of-type { display: none }`。目的是不让推荐视频在下面再出现一次。这是按位置打的补丁：第一行被拿掉，网格第一格和后面的卡片不是同一套可见结构。

分类页和标签页不用这个选择器。它们的卡片本来就和列表卡片共用 `article.vcard`。分类 `/video/category/duanshipin` 只有 1 条视频，标签 `/video/tag/71` 也只有 1 条视频（同标签下还有一条图片，不进视频列表）。验收时这两页的唯一卡片与首页网格卡片的计算样式一致。

会员链接原先走用户实体的规范地址。uid 1 的规范地址是私有会员中心 `/aa`，不是公开主页 `/r/aa`。

## 2. 修改的文件

本轮为完成任务改了这些文件：

- `web/modules/custom/xedc_member/src/Service/MemberAliasManager.php`
- `web/modules/custom/xedc_core/src/Video/VideoCards.php`
- `web/themes/custom/xedc/xedc.theme`
- `web/themes/custom/xedc/templates/video/video-member.html.twig`（新建）
- `web/themes/custom/xedc/templates/video/video-card.html.twig`
- `web/themes/custom/xedc/templates/views/views-view--video-list.html.twig`
- `web/themes/custom/xedc/templates/node/node--video--full.html.twig`
- `web/themes/custom/xedc/css/video-card.css`
- `web/themes/custom/xedc/css/video-detail.css`
- `web/themes/custom/xedc/css/video-list.css`
- `web/themes/custom/xedc/xedc.libraries.yml`

工作区里还有更早的未提交修改（图片优化、上一轮视频间距）。那些文件没有被还原，也没有纳入本轮改动。

## 3. 第一个视频卡片

状态：PASS。

首页推荐仍然取视图结果的第一条，条件仍是最新、无搜索词、第一页。网格在预处理里去掉这一条已经渲染好的行，查询和排序没有改。分类视图和标签页控制器不走这个预处理。

隐藏第一行的 CSS 已删除。1440 和 375 上，首页第一张和第二张卡片都是 `display: flex`、`1px solid rgb(228, 228, 231)`、圆角 `8px`、白底。1440 上宽度都是 303px，高度都是 283px。推荐大卡地址是 `/videos/shipindianhuadejicunjiufujidejide`，网格第一张是 `/videos/mimimamamaimaimai`，没有重复。

分类和标签的唯一卡片同样是 1px 边框、8px 圆角、白底、303px 宽。

## 4. 相关推荐

状态：PASS。

仍用原来的相关查询：同标签，然后同分类，然后其他视频，排除当前视频，最多 8 条。详情页 `/videos/shipinlanmuziduandeceshineirong` 的 7 条推荐都不包含当前地址。

桌面端是左封面、右文字。封面 148×83，比例 16:9。卡片白底、1px 边框、8px 圆角，悬停只改边框颜色。标题沿用两行省略。有真实时长才显示时长角标。375 到 767 封面改为 112px 宽。375 上没有横向溢出，会员名称右缘没有超出卡片。

## 5. 会员头像和名称

四类页面都显示头像和名称：PASS。

| 页面 | 头像 | 名称 |
| --- | --- | --- |
| `/video` | PASS，真实图片 | PASS，昵称「管理员」 |
| `/video/category/duanshipin` | PASS | PASS |
| `/video/tag/71` | PASS | PASS |
| 详情相关推荐 | PASS | PASS |
| 详情发布者 | PASS | PASS |

名称优先用 `field_xedc_nickname`。没有昵称时用账户显示名。uid 1 的昵称是「管理员」，账户名是 admin。没有头像时用同一套圆形默认图形。验收时把 nid 9 临时改到 uid 3（aaaa，无头像，slug `dcxe`），把 nid 8 临时改到 uid 4（aaaad，无 slug）。测完已改回 uid 1，两张卡片的公开链接恢复为 `/r/aa`。

## 6. 公开主页跳转

头像和名称指向同一个 URL。URL 由 `Url::fromRoute('xedc_member.public_profile', ['slug' => $slug])` 生成。slug 来自 `MemberAliasManager::slugsFor()`：一次读出 `xedc_member` 的 alias，没有 alias 的用户再查一次 `path_alias`。没有用 UID 拼地址，没有写死 `/r/12shh`。

| 点击 | 结果 | 落地 |
| --- | --- | --- |
| 首页推荐头像 | PASS | `/r/aa` |
| 首页推荐名称 | PASS | `/r/aa` |
| 首页网格头像 | PASS | `/r/aa` |
| 首页网格名称 | PASS | `/r/aa` |
| 分类头像 | PASS | `/r/aa` |
| 分类名称 | PASS | `/r/aa` |
| 标签头像 | PASS | `/r/aa` |
| 标签名称 | PASS | `/r/aa` |
| 详情发布者头像 | PASS | `/r/aa` |
| 详情发布者名称 | PASS | `/r/aa` |
| 相关推荐头像 | PASS | `/r/aa` |
| 相关推荐名称 | PASS | `/r/aa` |
| 无头像会员头像 | PASS | `/r/dcxe` |
| 无 slug 会员名称 | PASS | 不是链接，点击后仍留在原页 |

公开主页实际打开的是 `/r/aa`，标题为「管理员 | DcLili」。没有进入 `/aa`。uid 4 的 slug 为空，页面上是 `<span>`，没有 `href`。批量结果：uid 1 `aa`，uid 2 `12shh`，uid 3 `dcxe`，uid 4 和 uid 81 为空。

游客点击发生在登录之前。登录后的浏览器会话是 uid 81，视频页会员链接仍是 `/r/aa`。

## 7. 查询

状态：PASS，没有按视频逐条查用户。

渲染 8 条视频列表时记录到 25 条 SQL。其中 `users_data` 1 条，`users_field_data` 2 条。同一批 8 个节点再构建一次卡片只有 1 条 SQL。用户实体和 slug 都在同一次请求里复用。Twig 里没有查询。

## 8. 浅色和暗色

| 模式 | 结果 |
| --- | --- |
| 浅色 | PASS。卡片白底，名称 `rgb(113, 113, 122)`，头像圆角 50% |
| 暗色 | PASS。卡片 `rgb(39, 39, 42)`，边框 `rgb(63, 63, 70)`，名称 `rgb(228, 228, 231)` |

相关推荐暗色底也是 `rgb(39, 39, 42)`。

## 9. 七档宽度

首页和详情都测了。没有横向溢出。容器左右内边距 16px，1440 时内容宽 1280。没有再给视频页加一层整页左右内边距。

| 宽度 | 首页第一张与第二张边框 | 相关推荐列 | 溢出 |
| --- | --- | --- | --- |
| 375 | 一致，均为 1px | `112px 201px` | 否 |
| 390 | 一致 | `112px 216px` | 否 |
| 430 | 一致 | `112px 256px` | 否 |
| 768 | 一致 | `148px 558px` | 否 |
| 1024 | 一致 | `148px 799px` | 否 |
| 1280 | 一致 | `148px 153.375px` | 否 |
| 1440 | 一致，宽 303px | `148px 157.562px` | 否 |

## 10. 播放

详情页 nid 6 的 H.264 地址仍指向 `https://cdn.gitxx.xyz/public/2026-10/…mp4`。无头 Chromium 静音播放后 `currentTime` 为 1.31，`readyState` 4，没有媒体错误。状态：PASS。

续播这一轮没有再点「继续播放」。状态：NOT TESTED。

这条样本不是 HLS。状态：NOT TESTED。

全屏沿用无头浏览器限制，本轮没有新的全屏结果。状态：BLOCKED。

## 11. 日志和临时数据

验收期间浏览器控制台没有新的 JavaScript 错误。

`watchdog` 里出现过 `MemberCenterAccessCheck::access` 缺少 `$request` 的 RuntimeException，发生在面包屑检查会员中心路由时。随后单独打开 `/video` 和 `/r/aa` 没有再新增这条错误。本轮没有改这个访问检查。

为了做无头像、无 slug 的浏览器验收，临时把 nid 9、nid 8 的作者改到 uid 3、uid 4，测完改回 uid 1。另建过两条没有视频文件的临时节点 533、534，搜索结果进不了现有视频列表，已删除。删除过程中有一条「源文件不存在、无法复制」的 file 日志，对应这两条临时节点。

点赞、收藏、分享、下载没有点击。

## 12. 尚未通过

| 项目 | 状态 |
| --- | --- |
| 游客与 uid 81 打开公开主页、头像和名称进入 `/r/{slug}` | PASS |
| 无头像、无 slug | PASS，测完已恢复原作者 |
| 七档宽度、浅色、暗色、第一张卡片、相关推荐 | PASS |
| 现有 MP4 能播放 | PASS |
| 续播 | NOT TESTED |
| HLS | NOT TESTED |
| 全屏 | BLOCKED |
| 点赞、收藏、分享、下载 | NOT TESTED |
