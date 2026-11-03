# 视频专区间距精修：输出说明

日期：2026-11-03  
站点：https://gitxx.xyz  
项目：/var/www/drupal

本文件说明这次精修交出了什么、量到了什么。进度在 `docs/XEDC_PROGRESS.md` 文末「视频专区间距精修」。截图在 `docs/video-ui-spacing/`。

没有新建模块，没有改播放、续播、转码和下载逻辑，没有改 `config/sync`，没有改图片专区和文章专区的布局，没有提交 Git。

## 这次做了什么

视频页原先在全站容器外面又加了一圈左右内边距，手机上卡片比美图和文章更窄。这次去掉这圈内边距，让视频首页、分类、标签和详情沿用全站容器。列表卡片改成独立卡片：细边框、8px 圆角，封面贴齐上沿，标题和辅助信息放在卡片里面。

## 结论

全站内容容器是 `.page__content > .container`，左右 `padding` 都是 `1rem`（16px），`max-width` 是 1280px。美图和文章页面自己不再加左右内边距。

改之前，`.xv-page` 和 `.vd-wrap` 在容器里再加 16px。390px 宽时，视频卡片左缘是 32px，美图网格和文章卡片左缘是 16px。详情标题更靠里，因为窄屏样式 `body:has(.field--name-field-body) article` 给带正文的 `article` 加了 `padding: 24px 16px 56px`。这条只在视频详情里取消，文章页没有改。

改完以后，375px 和 390px 上这些内容的左缘都是 16px，右缘也是 16px，没有横向溢出。

| 宽度 | 页面 | 内容左缘 | 内容宽度 |
| --- | --- | ---: | ---: |
| 375 | `/meitu` 美图网格 | 16 | 343 |
| 375 | `/articles` 文章卡片 | 16 | 343 |
| 375 | `/video`、分类、标签 | 16 | 343 |
| 390 | `/meitu` 美图网格 | 16 | 358 |
| 390 | `/articles` 文章卡片 | 16 | 358 |
| 390 | `/video`、分类、标签 | 16 | 358 |
| 390 | 视频详情标题和播放器 | 16 | 与容器内容同宽 |

详情页 `article.vd` 的计算内边距是 `0px`。桌面 1440px 仍走全站 1280px 容器，视频页不再在里面缩一圈。

## 卡片

列表里的 `.xv-grid .vcard` 现在有 1px 边框和 8px 圆角。封面不再单独圆角，标题和播放信息有 12px 内边距。暗色下卡片背景是 `#27272a`。悬停只改边框色，不移动布局。相关推荐仍是左图右文，没有套这层大卡片。

## 截图

| 文件 | 内容 |
| --- | --- |
| `docs/video-ui-spacing/meitu-390.png` | 美图列表，390px |
| `docs/video-ui-spacing/articles-390.png` | 文章列表，390px |
| `docs/video-ui-spacing/video-390.png` | 视频首页，390px |
| `docs/video-ui-spacing/video-cards-390.png` | 视频卡片，390px |
| `docs/video-ui-spacing/detail-390.png` | 视频详情，390px |
| `docs/video-ui-spacing/video-1440.png` | 视频首页，1440px |

## 改过的文件

| 文件 | 作用 |
| --- | --- |
| `web/themes/custom/xedc/css/video-list.css` | 去掉视频页自己的左右内边距 |
| `web/themes/custom/xedc/css/video-card.css` | 列表卡片的边框、圆角和内部间距 |
| `web/themes/custom/xedc/css/video-detail.css` | 详情对齐容器，并取消视频文章的额外内边距 |
| `web/themes/custom/xedc/xedc.libraries.yml` | 样式库版本改为 `video_list` 3.5、`video-card` 3.4、`video-detail` 3.6 |
| `docs/XEDC_PROGRESS.md` | 文末记下这次精修 |
| `docs/XEDC_VIDEO_UI_SPACING_输出说明.md` | 本文件 |
