# 视频专区第三次 UI 精修：输出说明

日期：2026-11-03  
站点：https://gitxx.xyz  
项目：/var/www/drupal

本文件说明这次精修交出了什么、页面上现在怎么跳转。验收细节在 `docs/XEDC_VIDEO_UI_REFINEMENT_ROUND2_REPORT.md`。进度在 `docs/XEDC_PROGRESS.md` 文末「视频专区第三次 UI 精修」。截图在 `docs/video-ui-refinement-round2/`。

没有新建模块，没有改 Drupal 核心、第三方模块、`config/sync` 和数据库结构。没有改播放、续播、转码、下载、点赞、收藏和分享的业务逻辑。没有改图片专区和文章专区的布局。没有提交 Git。

## 这次做了什么

1. 首页推荐视频不再靠 CSS 把网格第一张藏起来。最新、没有搜索词、第一页时，预处理去掉已经放进推荐大卡的那一行。分类页和标签页不走这段逻辑。
2. 详情页相关推荐改成左封面、右文字的横向卡片。封面统一 16:9，桌面 148px 宽，768px 以下 112px 宽。
3. 视频首页、分类页、标签页、详情发布者和相关推荐里的会员头像、名称，都进入公开主页 `/r/{slug}`。没有有效 slug 时只显示头像和名称，不生成链接。

## 第一张卡片

原先首页有这条规则：

`.xv-page--hero .xv-grid > .views-row:first-of-type { display: none }`

它按位置隐藏第一行，用来避免推荐视频在网格里再出现一次。这条规则已经删除。

现在由 `xedc_preprocess_views_view_unformatted__video_list` 处理，只在最新、搜索词为空、第一页时去掉那一行。视图查询和排序没有改。

1440px 上量到：首页第一张和第二张都是 `display: flex`、1px 边框、8px 圆角、宽 303px、高 283px。推荐大卡是 `/videos/shipindianhuadejicunjiufujidejide`，网格第一张是 `/videos/mimimamamaimaimai`。分类 `/video/category/duanshipin` 和标签 `/video/tag/71` 各只有 1 条视频，卡片样式与首页网格相同。

## 会员公开主页

原先作者链接用用户规范地址，uid 1 会进私有会员中心 `/aa`。

现在的地址来自路由 `xedc_member.public_profile`。slug 由 `MemberAliasManager::slugsFor()` 批量读取：先读会员 alias，没有 alias 的再查一次路径别名。头像和名称用同一个 URL。列表、推荐大卡、详情发布者和相关推荐共用 `templates/video/video-member.html.twig`。

名称优先用昵称。uid 1 的昵称是「管理员」，公开地址是 `/r/aa`。没有头像时显示圆形默认图形。

实测：

| 对象 | 结果 |
| --- | --- |
| 首页、分类、标签、详情发布者、相关推荐的头像和名称 | 进入 `/r/aa` |
| 无头像会员 uid 3 | 默认头像，进入 `/r/dcxe` |
| 无 slug 会员 uid 4 | 显示名称 aaaad，不是链接 |
| 登录会员 uid 81 打开视频页 | 链接仍是 `/r/aa`，不是 `/aa` |

nid 8 和 nid 9 的作者只在验收时临时改过，已经改回 uid 1。

## 相关推荐

查询规则没变：同标签，然后同分类，然后其他视频，排除正在看的视频，最多 8 条。详情 `/videos/shipinlanmuziduandeceshineirong` 的推荐里没有当前视频。

卡片白底、1px 边框、8px 圆角。悬停只改变边框颜色。有真实时长才显示时长。375px 到 1440px 都没有横向溢出。

## 查询

8 条视频一起渲染时，`users_data` 1 条，`users_field_data` 2 条。同一批再构建一次只有 1 条 SQL。Twig 里没有查询。

## 样式库版本

`video_list` 3.6.2，`video-card` 3.5，`video-detail` 3.7.1。

全站容器仍是 `.page__content > .container`，左右内边距 16px，最大宽度 1280px。本轮没有再给视频页加整页左右内边距。

## 截图

| 文件 | 内容 |
| --- | --- |
| `docs/video-ui-refinement-round2/home-desktop-1440.png` | 视频首页，1440px，浅色 |
| `docs/video-ui-refinement-round2/home-mobile-375.png` | 视频首页，375px |
| `docs/video-ui-refinement-round2/home-grid-1440.png` | 首页网格，浅色 |
| `docs/video-ui-refinement-round2/category-1440.png` | 分类页 |
| `docs/video-ui-refinement-round2/tag-1440.png` | 标签页 |
| `docs/video-ui-refinement-round2/detail-1440.png` | 视频详情 |
| `docs/video-ui-refinement-round2/related-1440.png` | 相关推荐，桌面 |
| `docs/video-ui-refinement-round2/related-375.png` | 相关推荐，375px |
| `docs/video-ui-refinement-round2/member-avatar-name.png` | 会员头像和名称 |
| `docs/video-ui-refinement-round2/dark-1440.png` | 暗色首页 |
| `docs/video-ui-refinement-round2/dark-grid-1440.png` | 暗色网格 |
| `docs/video-ui-refinement-round2/dark-related-1440.png` | 暗色相关推荐 |
| `docs/video-ui-refinement-round2/public-profile.png` | 点击后的公开主页 `/r/aa` |
| `docs/video-ui-refinement-round2/no-avatar-author.png` | 无头像会员 |
| `docs/video-ui-refinement-round2/no-avatar-profile.png` | 无头像会员的公开主页 |
| `docs/video-ui-refinement-round2/no-slug-author.png` | 无公开 slug 的会员 |

## 改过的文件

| 文件 | 作用 |
| --- | --- |
| `web/modules/custom/xedc_member/src/Service/MemberAliasManager.php` | 批量读取公开 slug |
| `web/modules/custom/xedc_core/src/Video/VideoCards.php` | 卡片上的头像、名称和公开主页地址 |
| `web/themes/custom/xedc/xedc.theme` | 详情发布者用同一套数据；首页去掉重复的第一行 |
| `web/themes/custom/xedc/templates/video/video-member.html.twig` | 头像和名称的统一模板 |
| `web/themes/custom/xedc/templates/video/video-card.html.twig` | 列表和相关推荐引用会员模板 |
| `web/themes/custom/xedc/templates/views/views-view--video-list.html.twig` | 首页推荐大卡引用会员模板 |
| `web/themes/custom/xedc/templates/node/node--video--full.html.twig` | 详情发布者引用会员模板 |
| `web/themes/custom/xedc/css/video-card.css` | 会员头像和名称 |
| `web/themes/custom/xedc/css/video-detail.css` | 相关推荐卡片 |
| `web/themes/custom/xedc/css/video-list.css` | 删除按第一行隐藏的规则 |
| `web/themes/custom/xedc/xedc.libraries.yml` | 样式库版本 |
| `docs/XEDC_VIDEO_UI_REFINEMENT_ROUND2_REPORT.md` | 验收报告 |
| `docs/XEDC_PROGRESS.md` | 进度 |
| `docs/XEDC_VIDEO_UI_REFINEMENT_ROUND2_输出说明.md` | 本文件 |

## 本轮没有测的项目

续播没有再点。现有样本不是 HLS。全屏在无头浏览器里仍然进不去。点赞、收藏、分享和下载没有点击。
