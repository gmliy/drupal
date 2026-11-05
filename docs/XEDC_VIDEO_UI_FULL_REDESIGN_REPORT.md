# XEDC 视频专区整体 UI 重新设计报告

日期：2026-11-05。站点：https://gitxx.xyz。Drupal 11.4.11。主题 `web/themes/custom/xedc`。

没有提交 Git，没有推送，没有改 `config/sync`，没有改视频内容/数据库数据，没有新建模块。

截图目录：`docs/video-ui-redesign/`。浏览器测量：`browser-report.json`（41 PASS / 0 FAIL）。

## 1. 原有 UI 问题

1. **视觉不统一**：首页、标签、分类、详情多次局部精修后，间距、圆角、元数据层级仍不齐。
2. **推荐大卡过大**：旧 `.xv-hero` 横向铺满，首屏占比过高；去掉后又缺少推荐层次。
3. **首条异常反馈**：推荐区与网格卡片 DOM/尺寸不一致，或网格首行被特殊处理，用户看到「第一张卡」异样。
4. **用户名错误**：`VideoCards` 优先读 `field_xedc_nickname`，头像旁显示「管理员」而不是 Drupal 账户名 `admin`。
5. **卡片信息拥挤**：分类、播放量、日期挤在同一 flex 行，有分类时日期掉行，行间高度不齐。

## 2. 新设计方案

统一设计系统（白色极简，暗色走现有变量）：

| 元素 | 规则 |
| --- | --- |
| 容器 | 继续 `.page__content > .container`，左右 1rem，最大 1280px，视频页不加额外整页边距 |
| 圆角 | 卡片 / 推荐块 10px，封面 6–8px |
| 边框 | 1px `--xv-line` / `--vd-line` |
| 推荐区 | 紧凑 `.xv-lead`：封面上限约 400px 宽，16:9，右侧标题 / 用户名 / 播放量·日期 / 观看 |
| 网格卡 | 封面 → 标题（最多 2 行）→ 头像+Username → 播放量·日期 |
| 详情 | 播放器 → 标题 → 元数据 → 发布者 → 互动 → 分类/标签 → 正文；侧栏相关推荐 |
| Hover | 仅边框色与封面轻微缩放，无重阴影 |

推荐业务：仍取最新排序、无搜索、第一页的第一条；预处理 `array_shift` 从网格去掉同一行，避免重复。不用 `:first-child` 隐藏。

## 3. 修改文件

| 文件 | 作用 |
| --- | --- |
| `web/modules/custom/xedc_core/src/Video/VideoCards.php` | 作者名改为 `getAccountName()`（user.name） |
| `web/themes/custom/xedc/xedc.theme` | 恢复推荐区数据与网格去重预处理 |
| `web/themes/custom/xedc/templates/views/views-view--video-list.html.twig` | 首页结构：头图工具栏 + 紧凑推荐 + 网格 |
| `web/themes/custom/xedc/templates/video/video-card.html.twig` | 统一卡片脚：会员 + 播放量/日期 |
| `web/themes/custom/xedc/templates/video/xedc-video-tag.html.twig` | 标签页面包屑与统一工具栏 |
| `web/themes/custom/xedc/templates/taxonomy/taxonomy-term--video-category.html.twig` | 分类页面包屑与标题区 |
| `web/themes/custom/xedc/templates/node/node--video--full.html.twig` | 详情信息层级整理 |
| `web/themes/custom/xedc/css/video-list.css` | 列表/标签/分类/推荐区样式 |
| `web/themes/custom/xedc/css/video-card.css` | 统一卡片与会员芯片 |
| `web/themes/custom/xedc/css/video-detail.css` | 详情与相关推荐 |
| `web/themes/custom/xedc/xedc.libraries.yml` | `video_list` / `video-card` / `video-detail` → 4.0.x |

未改：播放器 JS、转码、CDN、续播、互动逻辑、推荐查询、图片/文章专区、`config/sync`。

## 4. 各页面改造

### 首页 `/video`

- 标题 + 说明 + 计数；分类 pill；最新/热门 + 搜索分行。
- 紧凑推荐区展示目标视频「视频电话的吉村久夫记得记得」；网格从下一条开始，7 张，结构一致。
- 1440 推荐区约 1248×251，封面 400×225。

### 标签 `/video/tag/71`

- 面包屑回视频专区；标签名、说明、数量；排序；同一套 `.vcard`。
- 图片标签 `/tags/haibian` 仍为 `xt-term`，未改。

### 分类 `/video/category/duanshipin`

- 面包屑 + 分类标题/描述/数量；工具栏排序搜索；同一套网格卡片。

### 详情

- 播放器区域与初始化未改。
- 标题下元数据用间隔点；发布者用同一 `video-member`；互动区保留；相关推荐复用卡片组件。

## 5. Username 字段

| 概念 | 字段 | 本次用途 |
| --- | --- | --- |
| Username | `user.name` / `User::getAccountName()` | 头像旁显示 |
| Nickname | `field_xedc_nickname` | 不再用于视频专区展示 |
| UID | `uid` | 不显示 |
| Public slug | `MemberAliasManager::slugsFor()` | 仅生成 `/r/{slug}` |

uid 1 实测：账户名 `admin`，昵称「管理员」。改造后各页显示 `admin`。

链接：有 slug 时头像与用户名同链 `/r/aa`；无 slug 时组件不输出 `<a>`（既有 `video-member.html.twig` 逻辑）。

## 6. 第一张卡片

| 检查 | 结果 |
| --- | --- |
| 推荐区是否为目标视频 | PASS |
| 网格是否重复该视频 | PASS（网格首张为「密密麻麻买买买」） |
| 网格第 1/2/3 张 DOM | PASS（均为 `vcard__hit` + `vcard__foot`） |
| 网格第 1/2/3 张高度 | PASS（1440 均为 291） |
| 是否用 `:first-child` / 隐藏首卡 | 否（预处理去掉推荐行） |

对比图：`first-card-before-hero-1440.png`（旧大卡）、`first-card-after-lead-1440.png`、`first-card-after-grid1-1440.png`。

## 7. 公开主页跳转

| 入口 | 结果 |
| --- | --- |
| 首页推荐头像/用户名 | PASS → `https://gitxx.xyz/r/aa` |
| 详情发布者 | PASS → `/r/aa` |
| 网格/标签/分类/相关推荐 | 同为 `/r/aa`，显示名 `admin` |

截图：`public-profile-from-lead.png`、`public-profile-from-detail.png`、`member-username-lead.png`、`member-username-card.png`。

## 8. 查询与性能

- 作者仍由 `VideoCards::owners()` 一次 `loadMultiple` + 一次 `slugsFor()`，Twig 不查库。
- 未新增按卡查询用户。
- 列表/详情缓存标签策略保留。
- 抽测 `build([6,8,9,10])` 四条作者均为 `admin`。

## 9. 响应式与主题

| 宽度 | 首页溢出 | Username |
| --- | --- | --- |
| 375 / 390 / 430 / 768 / 1024 / 1280 / 1440 | PASS | `admin` |

浅色 / 暗色：首页与详情暗色标题 `rgb(228, 228, 231)`，无白卡片异常。分类、标签 375/1440 无溢出。

## 10. 截图清单

1. `home-desktop-1440.png`
2. `home-375-light.png`
3. `tag-desktop-1440.png` / `tag-mobile-375.png`
4. `category-desktop-1440.png` / `category-mobile-375.png`
5. `detail-desktop-1440.png` / `detail-mobile-375.png`
6. `related-desktop-1440.png`
7. `member-username-lead.png` / `member-username-card.png`
8. `home-dark-1440.png` / `detail-dark-1440.png`
9. `first-card-before-hero-1440.png` / `first-card-after-lead-1440.png` / `home-card1-1440.png`
10. `public-profile-from-lead.png` / `public-profile-from-detail.png`

## 11. 未完成 / 未测

| 项 | 状态 |
| --- | --- |
| 点赞/收藏/分享/下载真实点击提交 | NOT TESTED（避免改正式计数） |
| 全屏播放 | NOT TESTED（沿用既有限制） |
| 非 admin 多作者样本在前台同屏展示 | NOT TESTED（当前已发布视频所有者均为 uid 1；代码路径已切到 `getAccountName()`） |
| 日志中 `MemberCenterAccessCheck::$request` 警告 | 既有问题，与本次视频 UI 改动无关 |

## 12. 验收结论

视频专区四类页面共用同一卡片与会员组件；推荐区恢复且不重复进网格；头像旁统一显示 Drupal Username；七档宽度与浅/暗色浏览器实测通过。
