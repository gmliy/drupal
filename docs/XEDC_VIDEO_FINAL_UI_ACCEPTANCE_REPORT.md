# XEDC 视频专区最终 UI 验收

日期：2026-11-03。状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。没有实际打开过的项不标 PASS。

截图在 `docs/video-final-ui/`。测量数据在同目录 `metrics.json`。浏览器是本机 Chromium headless。

## 1. 设计目标

视频首页、分类页、标签页和详情页使用同一套版式，封面、标题、作者和播放信息的层次清楚，桌面和手机、浅色和暗色都能读。播放、续播、转码、权限和图片专区保持原样。

## 2. 参考方向

只借内容组织方式：首页有一条真实推荐和下面的视频网格，详情是播放器加相关推荐。没有复制 YouTube、Vimeo、Netflix 或 Apple 的标志、品牌色或专有布局。强调色沿用站点现有的近黑 `#18181b` 和暗色文字 `#e4e4e7`，没有新造品牌色。

## 3. 统一设计系统

变量放在 `.xv-page` 和 `.vd-wrap`：前景、次要文字、分隔线和卡片底。字体沿用系统无衬线加苹方、微软雅黑。圆角 4px 到 6px。封面悬停只做轻微放大，并尊重 `prefers-reduced-motion`。暗色走现有 `data-bs-theme` / `data-theme`，不另建一套主题。

## 4. 页面结构

全站页头页脚没改。视频区最大宽度 1280px，左右留白一致。

- 首页 `/video`：标题、数量、分类、最新/热门、搜索、推荐、网格。
- 分类 `/video/category/{slug}`：分类标题、描述、数量、排序、搜索、网格。没有首页那条大推荐。
- 标签 `/video/tag/{id}`：独立模板，不走图片标签。
- 详情：1100px 以上是主栏加侧栏，更窄时改为单列。

## 5. 首页

PASS。推荐来自当前列表第一条，没有写死 nid。分类只显示有视频的真实分类，「全部」是当前项。1440px 网格四列，1280px 三列，768px 两列，375/390/430 一列。封面 16:9，时长在左下，标题两行截断，作者链接在卡片链接外面。8 个视频都没有横向溢出。

## 6. 分类页

PASS。`/video/category/duanshipin` 标题是「短视频」，有分类描述和「共 1 个视频」。没有图片内容标记。列数与首页相同。只有 1 条时卡片留在网格左侧，没有被拉满整行。

## 7. 标签页

PASS。`/video/tag/71` 使用视频页结构，标题是该术语的名称「标签」。`/tags/haibian` 仍是图片标签页，标题「#海边」，页面上没有 `.xv-page`。

## 8. 详情页

PASS。样本 `https://gitxx.xyz/videos/shipindianhuadejicunjiufujidejide`。播放器在标题上面。作者有头像和主页链接。收藏、点赞、分享按钮还在，匿名访问没有下载按钮，这是原来的权限，没有改下载逻辑。分类和标签可点。简介有内容才显示。相关推荐 7 条，横向小卡，不含当前视频。

竖屏封面在元数据到达前会先被放进 16:9 黑框。FIXED：封面高度大于宽度时，模板直接加上 `is-portrait`，播放器按 9:16、最宽 420px 居中。播放后实测视频是 1080×1920，没有被拉扁。

## 9. 播放器

PASS。点击播放后 `paused` 为 false，约 1 秒，比例 1080×1920。控制条能看到播放、进度、时间、音量、设置、画中画和全屏。Plyr 和 hls.js 没有替换。播放地址仍是现有副本 `xedc-playback/86.mp4`。

这条副本的真实时长是 5.17 秒，页面上的时长文字仍是字段里的 04:43。这是原有数据和播放文件不一致，本轮没有改时长字段，也没有改播放地址选择。

## 10. 浅色模式

PASS。首页和详情的页面背景是白色，标题 `rgb(28, 25, 23)`。截图：`video-home-desktop-light.png`、`video-detail-desktop-light.png`。

## 11. 暗黑模式

PASS。首页和详情标题都是 `rgb(228, 228, 231)`，页面背景 `rgb(24, 24, 27)`。封面没有加滤镜。截图：`video-home-desktop-dark.png`、`video-detail-desktop-dark.png`。

## 12. 响应式

七档都打开了首页、分类、标签和详情。`documentElement.scrollWidth` 没有大于视口。列数：

| 宽度 | 首页 / 分类 / 标签 |
| --- | --- |
| 375、390、430 | 1 列 |
| 768、1024 | 2 列 |
| 1280 | 3 列 |
| 1440 | 4 列 |

详情在 1100px 以上是主栏加侧栏，手机上相关推荐转到正文下面，仍是左图右文。手机搜索按钮高 44px，「搜索」没有拆成两行。

## 13. 交互

| 项 | 结果 |
| --- | --- |
| 搜索 `?keys=测试` | PASS，地址正确，返回 6 张卡片 |
| 热门排序 | PASS，选中文字是「热门」 |
| 分类跳转 | PASS，打开了短视频分类 |
| 标签页 | PASS，打开了 `/video/tag/71` |
| 卡片进详情 | PASS |
| 播放 | PASS，点击后在播 |
| 续播提示 | PASS。这条视频已有进度时出现「上次看到 00:06」「继续播放」「从头播放」。现有规则是少于 5 秒不提示；这条播放文件只有 5.17 秒，无法再造一个合格的新进度。本轮没有点击这两个按钮 |
| 作者主页点击 | NOT TESTED。作者链接在卡片链接之外，没有真正点进去 |
| 分页 | NOT TESTED。现有视频不足一页 |
| 长简介展开/收起 | NOT TESTED。这条简介短于展开阈值，按钮保持隐藏 |
| 点赞、收藏、分享点击 | NOT TESTED。按钮在页面上，没有点击，避免改正式计数 |
| 下载 | NOT TESTED。匿名详情没有下载按钮 |
| 全屏 | BLOCKED。无头浏览器返回 Permissions check failed |
| HLS | NOT TESTED。这条视频不是 m3u8 |

多次打开详情让原有播放计数从约 18 增到 20。没有点赞或收藏。

## 14. 性能

PASS（静态检查）。没有新框架，没有动画库，没有在 Twig 里查库，没有在页面请求里跑 FFmpeg 或 FFprobe。封面仍是懒加载和已有图片样式。详情多挂了已经存在的 `xedc/video-card` 样式，没有第二份播放器脚本。列表缓存标签和 `url.query_args` 上下文没有删。控制台没有记到 error。

## 15. SEO

PASS。首页标题「视频 | DcLili」，分类「短视频 - 视频分类 | DcLili」，详情标题是视频名。每页有一个 h1。路由没有改。

## 16. 修改文件

- `web/themes/custom/xedc/css/video-list.css`
- `web/themes/custom/xedc/css/video-card.css`
- `web/themes/custom/xedc/css/video-detail.css`
- `web/themes/custom/xedc/templates/views/views-view--video-list.html.twig`
- `web/themes/custom/xedc/templates/taxonomy/taxonomy-term--video-category.html.twig`
- `web/themes/custom/xedc/templates/video/xedc-video-tag.html.twig`
- `web/themes/custom/xedc/templates/video/video-toolbar.html.twig`
- `web/themes/custom/xedc/templates/node/node--video--full.html.twig`
- `web/themes/custom/xedc/xedc.libraries.yml`
- `web/themes/custom/xedc/xedc.theme`（列表和详情的原有预处理，本轮没有改播放和转码）

没有改 Drupal 核心、贡献模块、`config/sync`、播放器文件、转码队列或数据库。

## 17. 截图

`docs/video-final-ui/`：

- `video-home-desktop-light.png`
- `video-home-desktop-dark.png`
- `video-home-mobile.png`
- `video-category-desktop.png`
- `video-category-mobile.png`
- `video-tag-desktop.png`
- `video-tag-mobile.png`
- `video-detail-desktop-light.png`
- `video-detail-desktop-dark.png`
- `video-detail-mobile.png`
- `video-player-playing.png`
- `video-related.png`

## 18. Git

没有提交，没有推送。工作区里还有本轮之外已经存在的图片优化改动，没有回滚。

## 19. 未通过

- FAIL：无。
- FIXED：竖屏播放器不再停在整栏宽的 16:9 黑框里；手机搜索按钮不再把「搜索」拆成两行。
- BLOCKED：全屏。
- NOT TESTED：HLS、分页、长简介展开、作者主页的实际跳转、点赞、收藏、分享和下载的点击。

## 20. 后续

详情时长文字来自 `field_duration`，这条样本的播放文件只有 5.17 秒。要让两者一致，需要另查时长回写，不应该在界面里改播放地址。续播按钮的点击结果沿用上一轮已经测过的脚本，本轮只确认提示还在。
