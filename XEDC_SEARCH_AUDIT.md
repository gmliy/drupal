# XEDC Search 项目审计

审计时间：2026-11-10  
范围：Drupal 11.4.11 / PHP 8.5.12 / 主题 `xedc`、`xedc_admin` / 自定义模块  
结论：可以独立新增 `xedc_search`。前台不改版，只给现有搜索框加联想、补全、热门和历史。结果页继续走主题里的 `/search` 卡片。

## 环境

| 项 | 结果 |
| --- | --- |
| Git | `main`，HEAD `fb20dbd`，与 `origin/main` 一致 |
| Drupal | 11.4.11 |
| PHP | 8.5.12 |
| 数据库 | MariaDB，表前缀默认 |
| Redis | 已安装 PhpRedis。`settings.php` 在扩展可用时把默认缓存切到 `127.0.0.1:6379`。模块只走 Drupal Cache API，不直接连 Redis |
| Search API | 未安装，也不安装。核心 `search`、`search_node` 已启用，`search_index` 约 14854 行 |
| 内容 | 已发布：photo 49、video 8、article 12、page 4。另有未发布节点 |
| 词汇表 | `photo_category` 40、`video_category` 8、`article_category` 64、`tags` 136、`models` 80 |
| 权限 | 匿名和登录用户都有 `search content`。节点访问表只有全局 `all/0`，没有自定义 `node_grants`。内容审核用 `status` |
| 管理员 | `administrator` 为 `is_admin`，新权限自动拥有 |

## 前台设计基准（开发前）

这些值来自主题 CSS，开发后必须保持不变。

| 项 | 基准 |
| --- | --- |
| 导航栏 | `.xn` sticky，`.xn-bar` 高度 60px |
| 导航搜索框 | `.xn-search input` 高度 38px，圆角 8px，不改变位置 |
| 版心 | `.page__content > .container` 最大宽度 1280px，左右 padding 1rem |
| 断点 | 575 / 576 / 768 / 992 / 1200 / 1400 |
| 颜色 | 白色简约；暗色由 `data-theme` / `data-bs-theme` 切换 `--xn-*`、`--bs-*` |
| 搜索结果 | 主题 `search-page.html.twig` + `search-card.html.twig`，12 条分页 |

首页截图：`/root/xedc-search-baseline/home-desktop.png`（导航、搜索框、英雄图、分类胶囊、美图网格）。

## 搜索入口

| 页面 | 入口 | 实现 | 接入方式 |
| --- | --- | --- | --- |
| 全站桌面 | `templates/includes/xedc-header.html.twig` `.xn-search` | GET `/search?q=` | 保留表单。浮动联想面板，不改输入框尺寸 |
| 全站移动 | 同文件 `.xn-msearch`，由 `data-xn-search` 展开 | 同上 | 同上 |
| 搜索结果 | `templates/search/search-page.html.twig` `.sr-form` | 主题 `xedc.search.inc` 在路由 `search.view_search` / `search.view_node_search` 上替换内容 | 结果改为调用模块引擎；卡片、标签、分页模板不动 |
| 视频列表 `/video` | `video-toolbar.html.twig` `.xv-search`，参数 `keys` | `xedc.theme` 读 `keys`，视图自己筛选 | 保留 `keys` 和排序。联想走统一 API |
| 视频分类 | `views-view--taxonomy-term--page-1.html.twig` 引入同一工具条 | 同上 | 同上 |
| 文章列表 `/articles` | `views-view--article-list.html.twig` `.xa-search`，参数 `q`、`cat` | 工具条和侧栏各一个表单 | 两个框都绑定一次。保留视图筛选和分页 |
| 美图 `/meitu` 与美图分类 | `xedc.theme` 内联模板 `.photo-drop__search` | `photo-list.js` 在下拉里过滤分类/模特，不是内容搜索 | 不改下拉布局。另浮一层建议；回车或点建议进入 `/search?type=photo` |
| 标签 `/tags` | `xedc_tags` `.xt-search` | 服务端 `q` + 前端再过滤 | 保留原表单。建议走统一 API |
| 模特 `/models` | `xedc_models` `.xm-search` | `data-xm-input` 前端过滤名字 | 保留本地过滤。点人物进入人物页 |
| 核心搜索块 | `form--search-block-form.html.twig` | 当前 `xedc` 未放置该区块 | 若以后出现，JS 按 `form.search-form` 接入 |
| 后台筛选 | AI、监控、导入、文件清理里的 search | 各自的管理查询 | 不是全站搜索，不接入 |

可复用：

- `xedc.search.inc` 的参数清洗、高亮、封面、卡片和分页。
- 美图 / 视频 / 文章 / 人物现有卡片模板。
- `xedc_admin` 的颜色变量、表格、表单和暗色机制。
- 核心 `flood`、Cache API、`node_access` 查询标签。
- `statistics` 的 `node_counter`（热门排序；表不存在时退回发布时间）。

不复用 Search API 模块，也不新增 OpenSearch / Elasticsearch。

## 冲突与边界

- `/search` 已是核心搜索路由。模块不另注册同路径页面，避免路由冲突。
- 列表页必须继续用原来的 `q` / `keys` / `cat`。统一的是建议、补全、热门、历史和 `/search` 结果引擎。
- 中文不能只靠 `search_index`。标题和术语用参数化 `LIKE`，相关度用前缀、包含、术语命中和热度。这不是分词器。
- `photo-drop` 是分类过滤器。不能把它改成第二个页面搜索框，否则美图工具条布局会变。
- 文章列表有两个同结构表单，JS 必须按元素 `once`，避免重复请求。
- 管理后台的搜索框不属于本模块。
- 不改 Gin、Radix、核心和 `xedc_admin` 的全局样式。

## 数据库

新建表，不改核心表：

| 表 | 用途 |
| --- | --- |
| `xedc_search_keyword` | 管理员关键词、显示名、权重、启用、关联分类和标签 |
| `xedc_search_synonym` | 同义词，查询时最多展开 5 个 |
| `xedc_search_log` | 搜索日志。不存用户 ID、IP。按保留天数清理 |
| `xedc_search_daily` | 按天、关键词、类型汇总，供热门和概览 |
| `xedc_search_visitor` | 访客哈希去重。哈希用站点 salt，不存原始 IP |
| `xedc_search_history` | 仅登录用户自己的最近搜索 |

内容实体仍在 `node` / `taxonomy_term`，不复制正文。

## 前台方案

1. `hook_page_attachments` 只在 `xedc` 主题挂一套 JS/CSS。
2. 面板 `position: fixed`，挂到 `body`，不撑开导航和列表。
3. 颜色只用现有 `--xn-*`、`--bs-*`。
4. 联想和补全共用 `/api/xedc/search/suggest`。补全用 Tab / 方向键写入，点建议才搜索。
5. 中文输入法等 `compositionend` 后再请求。
6. 游客历史在 `localStorage`。登录用户走接口，查询带 `uid` 条件。

## 后台方案

路径 `/admin/config/xedc-search`。沿用 `xedc_admin` 侧栏、本地任务、表单和表格。样式只作用在 `.xedc-search-admin`，使用 `--xedc-admin-*`。概览、热门、关键词、历史分析、设置为真实表单和查询，不用 React。

## 需要改动的现有文件

只为把结果页接上引擎，并让筛选参数在搜索页里保留：

- `web/themes/custom/xedc/xedc.search.inc`
- `web/themes/custom/xedc/xedc.theme`（搜索页缓存上下文）
- `web/themes/custom/xedc/templates/search/search-page.html.twig`（隐藏筛选和日期，不改卡片）

其余搜索框靠现有 class 绑定，不改导航和列表模板。
