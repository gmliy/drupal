# XEDC Search 前台兼容

对比时间：2026-11-10。桌面 1440×900，手机 390×844。数据来自 Chrome 计算样式和截图，目录 `/root/xedc-search-after/`。开发前首页截图在 `/root/xedc-search-baseline/home-desktop.png`。

新增样式只作用于 `.xs-*`、搜索页的 `.xs-adv` / `.xs-chips`，以及为清空按钮准备的 `position: relative` 和输入时的右内边距。没有改导航、卡片、网格、详情模板。

## 测量

| 项 | 开发前基准 | 测到的值 | 状态 |
| --- | --- | --- | --- |
| 导航高度 `.xn-bar` | 60px，sticky | 首页、美图、视频、文章、搜索、标签、人物、详情 `/804` 都是 60 | PASS |
| 导航位置 | 顶部条 | 截图仍是同一条，登录、主题、语言都在 | PASS |
| 桌面导航搜索框 | 高 38px | 打开联想前后都是 38×346 | PASS |
| 手机导航搜索框 | 展开后高 38px | 展开后 38×350，条高度仍是 60 | PASS |
| 容器宽度 | 最大 1280px | 美图、视频、文章、搜索、标签、人物为 1280px | PASS |
| 左右内边距 | 1rem | 上述页面为 16px | PASS |
| 字体 | 系统字体栈 | `-apple-system, BlinkMacSystemFont, Inter, "PingFang SC", "Noto Sans SC", sans-serif` | PASS |
| 浅色背景 | 白色页面 | 首页和列表 `rgb(255, 255, 255)` | PASS |
| 暗色背景 | 现有暗色变量 | 手机暗色首页 `rgb(24, 24, 27)`，主题属性为 dark | PASS |
| 图片网格 | 现有美图卡片 | 首页和 `/meitu` 截图仍是原卡片 | PASS |
| 视频网格 | 现有视频卡片 | `/video` 截图仍是原卡片、时长和作者行 | PASS |
| 文章列表 | 现有文章行和侧栏 | 截图仍是原标题、摘要、封面和分类计数 | PASS |
| 详情页 | 未改模板 | `/804` 导航 60px，字体与全站一致 | PASS |
| 搜索结果卡片 | 原搜索卡片 | `/search?q=墨迹` 仍用原人物卡和图片网格 | PASS |

首页那次测量没有取到 `.page__content > .container`，因为首页结构本来就不是这个选择器。列表页都是 1280px。

搜索页正文背景测到 `rgb(250, 250, 248)`。模块没有设置 `body` 背景，这个值来自原页面。

## 搜索框尺寸

联想打开后，这些输入框的宽高没有变化：

- 导航：38×346
- 视频和视频分类：44×280
- 文章侧栏：40×256
- 文章手机工具条：40×358
- 标签：40×420
- 人物：40×320
- 搜索页：50×1122

图片分类下拉仍是原来的浮层。联想面板顶边在下拉底边之下，分类链接仍可过滤。

## 允许出现的新增界面

- 联想面板：白底或跟随 `--xn-bg`，圆角 10px，分组标题 12px。暗色走现有 `data-theme`。
- 导航、文章和人物输入框有值时出现清空按钮。按钮绝对定位，不改变框的宽高。
- 搜索页在原搜索框下增加「从 / 至」日期框和可删除的条件芯片。日期框使用现有边框和圆角变量。

## 没有改的模板

`xedc-header.html.twig`、视频工具条、文章列表、标签索引、人物页、图片下拉、美图卡片、视频卡片、文章卡片都没有为这次搜索改标记。绑定依靠原来的 class。

## 截图

- `home-desktop.png`、`suggest-desktop.png`：导航和首页网格，联想盖在轮播上，不推开布局。
- `home-mobile.png`、`home-mobile-dark.png`、`mobile-suggest.png`：手机导航和暗色。
- `video-desktop.png`、`search-desktop.png`、`meitu-desktop.png`、`articles-suggest.png`、`photo-suggest.png`。
- `detail-desktop.png`：内容详情。

这些文件在服务器的 `/root/xedc-search-after/`，不在 Git 仓库里。
