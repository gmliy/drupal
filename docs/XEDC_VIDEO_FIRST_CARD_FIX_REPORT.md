# XEDC 视频列表首页首卡异常修复

日期：2026-11-05。站点：https://gitxx.xyz。没有提交 Git，没有推送，没有改视频内容或数据库数据。

截图目录：`docs/video-first-card-fix/`。测量原文：`before-measure.json`、`after-measure.json`、`meta-fix-measure.json`。

## 1. 真正的异常原因

用户定位视频「视频电话的吉村久夫记得记得」（23 次播放，2026-10-30，管理员，「观看」）。这条视频在首页不是网格里的普通卡片，而是被做成独立推荐大卡 `.xv-hero`。

根因有两层：

1. **推荐大卡 DOM/CSS 与网格卡片不一致。**  
   `xedc_preprocess_views_view__video_list()` 在最新、无搜索、第一页时把视图第一条抽成 `video_hero`。模板 `views-view--video-list.html.twig` 用横向大卡渲染：独立封面区、更大标题、正文摘要、会员信息、播放量、日期，再加「观看」链接。网格卡片是 `article.vcard` → `a.vcard__hit`（封面+标题）+ `p.vcard__meta`。首条因此单独出现不同字体、宽度、高度和内部结构。

2. **网格故意丢掉同一条。**  
   `xedc_preprocess_views_view_unformatted__video_list()` 用 `array_shift` 去掉第一行，避免大卡和网格重复。这不是 `:first-child` 隐藏，但仍让「第一张可见视频」走另一套结构。分类页和标签页没有这段逻辑，所以只有首页首条异常。

另外，网格卡片元数据原先把会员、分类、播放量、日期塞进同一 flex 行。有分类时日期被挤到下一行，无分类时三列同一行，造成行间高度差（修复前第二行卡片高 261px，第一行 283px）。这不是首卡独有，但会放大「卡片内部空白/底部不齐」的观感。

修复前 1440 实测：

| 区块 | 外层 | 封面 | 说明 |
| --- | --- | --- | --- |
| `.xv-hero`（目标视频） | 1248×449 | 798×449 | 横向大卡，有摘要和「观看」 |
| 网格第 1 张（密密麻麻买买买） | 303×283 | 301×169 | 普通竖卡 |
| 网格第 2 张 | 303×283 | 301×169 | 与第 1 张 DOM 一致 |

## 2. 修改文件

| 文件 | 改动 |
| --- | --- |
| `web/themes/custom/xedc/xedc.theme` | 删除首行 `array_shift` 预处理；列表预处理不再组装 `video_hero` |
| `web/themes/custom/xedc/templates/views/views-view--video-list.html.twig` | 去掉推荐大卡和「最新视频」分段标题，只保留统一 `.xv-grid` |
| `web/themes/custom/xedc/templates/video/video-card.html.twig` | 元数据拆成两行：会员/分类；播放量/日期 |
| `web/themes/custom/xedc/css/video-list.css` | 删除 `.xv-hero*` / `.xv-section` 及对应暗色、移动端规则 |
| `web/themes/custom/xedc/css/video-card.css` | 删除 hero 悬停缩放；元数据改为 `.vcard__meta-line` 列布局 |
| `web/themes/custom/xedc/xedc.libraries.yml` | `video_list` → 3.7.1，`video-card` → 3.6.1 |

未改：播放/续播/转码/CDN/下载/点赞/收藏/分享、会员公开主页路由、推荐查询、图片/文章专区、`config/sync`、数据库。未新建模块。

## 3. 修复前后区别

| 项 | 修复前 | 修复后 |
| --- | --- | --- |
| 目标视频位置 | 首页顶部横向 `.xv-hero` | 网格第 1 张 `article.vcard` |
| 首条 DOM | 与后续卡片不同 | 与第 2、第 3 张相同：`vcard__hit` + `vcard__meta` |
| 「观看」单独入口 | 大卡有 | 无；整卡可点进详情（与其他卡一致） |
| 正文摘要 | 大卡显示「你都会回复记得记得」 | 列表卡不显示摘要（与其他卡一致；内容未改） |
| 网格条数 | 大卡抽走后 7 张 | 8 张，含目标视频 |
| 元数据 | 单行 flex，日期常换行 | 固定两行：会员·分类 / 播放量·日期 |
| 卡片高度（1440） | 行间 283 / 261 | 全部 283 |

## 4. 浏览器验收

同视口对比第 1 / 2 / 3 张（1440 浅色）：

| 卡 | 标题 | 外层 | 封面 | 标题行 | 元数据 | 会员对齐 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 视频电话的吉村久夫记得记得 | 303×283 | 301×169（16:9） | 2（min-height） | 管理员·美女视频 / 23 次播放·2026-10-30 | 头像与名称中心差 0 |
| 2 | 密密麻麻买买买 | 303×283 | 301×169 | 2 | 同结构 | 0 |
| 3 | 测试youtube视频标题 | 303×283 | 301×169 | 2 | 同结构 | 0 |

宽度 375 / 390 / 430 / 768 / 1024 / 1280 / 1440：无横向溢出；第 1–3 张高度两两相等；无 `.xv-hero`。  
浅色 / 暗色：暗色标题 `rgb(228, 228, 231)`。  
分类 `/video/category/duanshipin`、标签 `/video/tag/71`：仍是同一套 `vcard`，1440 外层 303×283，封面 16:9。  
详情相关推荐仍用同一卡片模板，侧栏布局可用。

截图（节选）：

- 修复前：`before-hero-1440.png`、`before-home-1440-light.png`、`before-card1-1440.png`
- 修复后：`after-home-1440-light.png`、`after-home-1440-dark.png`、`after-card1-1440.png`、`after-card2-1440.png`、`after-card3-1440.png`、`after-home-375-light.png`、`after-category-1440.png`、`after-tag-1440.png`

## 5. 验收对照

| 标准 | 结果 |
| --- | --- |
| 第一张与其他卡视觉统一 | PASS |
| 标题、会员信息不错位 | PASS |
| 播放量、发布时间排列正常 | PASS（第二行成对） |
| 无异常大块空白 / 无首卡特殊高度 | PASS |
| 无横向溢出 | PASS |
| 浅色 / 暗色 | PASS |
| 首页 / 分类 / 标签 | PASS |
| 视频内容与会员链接未改 | PASS（仍为 `/r/aa`，标题与计数未动） |
| 未用大量 `!important` / `:first-child` 补丁 / 隐藏首卡 | PASS |
