# XEDC Search 实施说明

模块 `xedc_search` 已安装并启用。前台主题 `xedc` 仍负责 `/search` 页面和现有卡片。本模块负责统一查询、联想、统计、历史和后台。没有安装 Search API，也没有增加 Composer 依赖。

## 边界

前台只增加联想面板、搜索页的日期筛选和必要的局部样式。导航、容器、卡片、网格和详情模板没有改。

后台页面挂在现有 `xedc_admin` 布局下，地址是 `/admin/config/xedc-search`。样式只使用 `--xedc-admin-*` 变量，不改 Gin 或其他后台页面。

## 查询怎么接

现有列表继续用原来的地址和筛选：

| 页面 | 原查询 | 处理 |
| --- | --- | --- |
| `/search` | `q`、`type`、`sort`、`page` | 结果改由本模块查询；失败时退回原来的 `SearchQuery` |
| `/video`、视频分类 | `keys` | 仍由 Views 过滤，搜索框只增加联想 |
| `/articles`、`/articles?cat=` | `q`、`cat` | 仍由文章列表过滤，搜索框只增加联想 |
| `/meitu`、图片分类 | 分类下拉里的本地过滤 | 下拉仍过滤分类；联想面板固定在下拉下方 |
| `/tags` | `q` | 仍提交标签索引 |
| `/models` | 无提交 | 输入仍过滤当前页人物；回车进入 `/search?type=model` |

`/search` 没有新注册路由。核心搜索路由保持不变，主题函数 `_xedc_search_result_nids()` 在模块启用时调用 `xedc_search.engine`。

## 查询规则

`SearchEngine` 使用带参数的 `LIKE`，并加上 `node_access` 标签。只查 `status = 1`，语言为当前语言和默认语言。中文按子串匹配，这一阶段不做分词、拼音或同义词引擎之外的扩展。管理员配置的同义词、关联标签和关联分类会加入条件。

人物、分类、标签走术语查询。人物链接优先用 `xedc_models.detail`。

相关度：标题前缀 100，标题包含 60，其他命中 30，再按发布时间。最新按创建时间。热门在存在 `node_counter` 时按浏览量，否则按时间。

## 联想和补全

同一接口按组返回，每组 3 到 5 条，来自真实标题、术语、热门词和管理员关键词。空组不返回。补全只取一条真实前缀；没有前缀时不编造。

前台脚本 `js/suggest.js` 用 `once` 绑到现有输入框，面板挂在 `document.body`，`position: fixed`，不插入页面流。防抖默认 250 毫秒，范围 200 到 800。请求使用 `AbortController`，过期响应丢弃。中文输入在 `compositionend` 之后才请求。

## 统计和历史

日志表不存用户编号和 IP。独立用户用站点盐加登录编号或浏览器令牌的 SHA-256。同一关键词、同一 IP 60 秒只计一次。新的独立用户每小时最多记 20 次。热门分值是 `((次数 + 独立用户 × 2) × 时间衰减) + 权重`，衰减为 `1 / (1 + 天数 / 7)`。

登录用户的历史按 uid 隔离。游客只使用 `localStorage` 键 `xedc_search_history_v1`。

Cron 和 `drush xedc-search:purge` 按保留天数清理日志、日统计和历史。

## 缓存

缓存箱 `cache.xedc_search`。当前站点 Redis 可用，实际类是 `Drupal\redis\Cache\PhpRedis`。Redis 不可用时走 Drupal 默认缓存箱，查询逻辑不变。联想和搜索结果按查询、类型、语言和权限上下文缓存。写入统计或关键词后失效标签 `xedc_search`。

## 路由注意

Drupal 11 忽略 `requirements` 里的 `_method`，并把未声明方法的路由默认成 GET 和 POST。写接口必须在路由上写 `methods: [POST]` 或 `methods: [DELETE]`，否则匿名 POST 会落到只读接口。这一点已经按 Drupal 11 的写法改过。

## 安装与卸载

```bash
cd /var/www/drupal && drush en xedc_search -y && drush cr
```

表由 `hook_schema()` 创建：`xedc_search_keyword`、`xedc_search_synonym`、`xedc_search_log`、`xedc_search_daily`、`xedc_search_visitor`、`xedc_search_history`。卸载时 Drupal 按 schema 删表，并删除本模块配置。没有 `hook_update_N()`，因为这是首次安装。

权限 `administer xedc search`。管理员角色 `is_admin`，自动拥有。前台接口要求 `access content`。

## 改过的既有文件

- `web/themes/custom/xedc/xedc.search.inc`：把结果查询交给引擎，保留旧查询作为失败回退，并接收分类、标签、人物和日期。
- `web/themes/custom/xedc/xedc.theme`：把上述变量交给搜索页模板，并补上对应缓存上下文。
- `web/themes/custom/xedc/templates/search/search-page.html.twig`：增加日期字段和已选条件芯片。卡片、分类胶囊和分页未改。

导航、视频工具条、文章列表、标签页、人物页和图片下拉的模板都没有改。脚本按现有 class 绑定。
