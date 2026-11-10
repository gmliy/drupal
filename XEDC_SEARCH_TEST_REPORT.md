# XEDC Search 测试报告

日期：2026-11-10。环境：Drupal 11.4.11，PHP 8.5.12，主题 `xedc` / `xedc_admin`，主机 `gitxx.xyz`。浏览器使用本机 Google Chrome headless。PHPUnit 使用 `/tmp/phpunit.phar` 11.5.46，项目 `vendor/bin/phpunit` 不存在。

状态只根据这次实际跑过的检查填写。

## A. 模块

| 项 | 状态 | 证据 |
| --- | --- | --- |
| 安装与启用 | PASS | `drush pm:list` 有 `xedc_search`，六张表存在 |
| 运行 | PASS | `drush cr` 成功；前台和后台页面 200 |
| API | PASS | 联想、补全、搜索、热门、历史、统计均返回预期状态码 |
| 数据库 | PASS | Schema 表可写、可查、验收后已清空 |
| 权限 | PASS | 匿名访问后台 403；普通会员 uid 359 访问后台 403；管理员可打开全部子页 |
| 缓存 | PASS | 缓存类 `Drupal\redis\Cache\PhpRedis`。同一次请求里第二次联想 0 次查询、0.2 ms |

验收脚本：

```bash
cd /var/www/drupal && drush php:script web/modules/custom/xedc_search/tests/manual/search-acceptance.php
```

结果 `RESULT PASS`。联想 183.7 ms，文章路径上的全站计时在另一次测量为搜索 7.8 ms。16 项全部 PASS，包括中文命中、已发布过滤、注入语句结果为 0、同义词、补全、历史隔离、最小长度、60 秒去重。

单元测试：

```bash
php /tmp/phpunit.phar -c web/modules/custom/xedc_search/phpunit.xml
```

6 个测试、12 个断言，OK。有 2 条 PHPUnit 弃用提示，不是失败。

## B. 搜索入口

桌面视口 1440。移动视口 390。输入「墨迹」或「墨」后等待约 1 秒。

| 页面 | 入口 | 联想 | 补全 | 统一搜索 | 状态 |
| --- | --- | --- | --- | --- | --- |
| 桌面导航 | `.xn-search` | 面板打开，分组来自真实标题和术语 | 显示「补全：墨迹2」，方向键能选中 | 表单仍提交 `/search` | PASS |
| 移动导航 | `.xn-msearch` | 面板打开 | 输入框内选中补全后缀 | 同上 | PASS |
| 视频列表 `/video` | `.xv-search` | 打开，框高 44、宽 280，前后不变 | 有补全行 | 仍提交原视频列表 | PASS |
| 视频分类 `/video/category/duanshipin` | `.xv-search` | 打开，尺寸不变 | 有补全行 | 仍提交该分类列表 | PASS |
| 文章列表桌面 | 侧栏 `.xa-search` | 打开，高 40、宽 256 | 有补全行 | 仍提交 `/articles` | PASS |
| 文章列表手机 | 工具条 `.xa-search` | 打开，高 40、宽 358 | 有补全行 | 同上 | PASS |
| 文章分类 | 同一文章列表上的 `?cat=` | 与文章列表同一表单和脚本 | 同一补全 | 隐藏字段会继续提交 `cat` | NOT TESTED。自动点击命中的是「全部」，没有打开具体分类地址。组件与已测的 `/articles` 相同 |
| 图片列表 `/meitu` | `.photo-drop__search` | 面板在下拉底部之下，`coversList` 为 false | 本地目标不做行内补全 | 下拉仍把 10 条筛成 1 条；回车去 `/search?type=photo` | PASS |
| 图片分类 `/cosplay` | 同一个下拉 | 面板打开 | 同上 | 同上 | PASS |
| 标签索引 `/tags` | `.xt-toolbar` | 打开，高 40、宽 420 | 有补全行 | 仍提交标签索引 | PASS |
| 人物 `/models` | `.xm-search` | 打开，高 40、宽 320 | 有补全行 | 回车去 `/search?type=model` | PASS |
| 搜索结果 `/search?q=墨迹` | `.sr-input` | 打开，高 50、宽 1122 | 有补全行 | 结果 21 条，沿用原卡片 | PASS |
| 标签详情、人物详情 | 无独立搜索框 | — | — | — | PASS，未新造输入框 |
| 后台 AI、文件、导入搜索 | 不是全站搜索 | — | — | — | PASS，未接管 |

桌面宽度大于等于 1024 时，文章工具条由原主题 `display:none`，可见的是侧栏搜索。手机相反。两个输入框都绑定了，隐藏的那个不会弹出零尺寸面板。

键盘：下方向选中「补全：墨迹2」，Esc 关闭。无结果文案为「没有匹配的建议」。阻断联想 URL 后文案为「网络异常，请直接搜索」。清空按钮把导航框值清成空字符串。控制台没有异常。

未测：真实中文输入法的 `compositionend`。代码有监听，浏览器自动化没有使用 IME。状态 NOT TESTED。

## C. 前台样式

见 `XEDC_SEARCH_FRONTEND_COMPATIBILITY.md`。导航高度、搜索框尺寸、容器和字体与现有样式一致。

## D. 后台

管理员一次登录后实际操作：

| 页面 | 状态 | 证据 |
| --- | --- | --- |
| 概览 | PASS | 今日、周、月、无结果、14 天和类型分布来自当时的 4 条真实测试记录 |
| 热门 | PASS | 保存权重后提示「已保存热门词权重。」，权重变为 7 |
| 搜索词 | PASS | 新增「验收临时词」进入编辑页，列表可见，确认框弹出，删除后列表不再包含该词 |
| 历史分析 | PASS | 筛选「墨迹」生效；页面文本不含 uid 或 IP |
| 设置 | PASS | 防抖改为 260 并出现「配置选项已保存。」，随后改回 250。当前配置仍是 250 |
| 弹窗 | PASS | 删除确认框标题为「删除搜索词「验收临时词」？」 |
| 空状态 | PASS | 操作前搜索词页显示「还没有搜索词。」 |
| 暗色与 390 宽 | PASS | `data-theme=dark`，内容宽 358，表格区域 `overflow-x: auto` |
| 普通会员 | PASS | uid 359 打开概览返回 403，随后该用户已删除 |

后台截图在清空测试数据之前拍摄，所以概览里能看到 4 次搜索。清空后这些测试行已不在表里。

未单独制造接口故障来看后台错误卡片。错误分支在控制器里，页面级故障态 NOT TESTED。

## E. 性能

命令行，同一进程：

| 操作 | 时间 | 数据库查询 |
| --- | --- | --- |
| 联想未命中 | 33.9 ms | 22 |
| 联想命中 | 0.2 ms | 0 |
| 搜索未命中 | 8.7 ms | 8，total 21 |
| 搜索命中 | 0.2 ms | 0 |

HTTP，`Host: gitxx.xyz`：

| 接口 | 第一次 | 后续 |
| --- | --- | --- |
| suggest | 110 ms | 34 ms、50 ms |
| complete | 62 ms | 67 ms |
| search | 68 ms | 65 ms |
| hot | 44 ms | 39 ms |
| `/search?q=墨迹` 整页 | 554 ms | 13 ms |

8 个并发联想请求全部 200，耗时 73 ms 到 172 ms。没有做更大的压测。状态 PASS，范围限于这 8 个并发。

## F. 安全

| 项 | 状态 | 结果 |
| --- | --- | --- |
| 超长关键词 | PASS | 120 个「墨」返回 400 |
| SQL 注入样例 | PASS | `total` 0 |
| XSS | PASS | `<script>` 被清成文本 `alert(1)`，响应 `application/json` |
| 未发布 | PASS | 「确认后的标题」0 条 |
| 匿名写历史 | PASS | POST 和两种 DELETE 都是 403 |
| 登录无 CSRF | PASS | POST 历史 403 |
| 登录带 CSRF | PASS | 写入 id 5，GET 只看到自己的记录，删除自己的返回 200 |
| 删除他人历史 | PASS | 404，管理员那一行当时仍在 |
| 跨站统计 | PASS | `Sec-Fetch-Site: cross-site` 返回 403 |
| 异常页码 | PASS | `page=-5` 按 0 返回；`page=99999` 返回 200 |
| 私有节点 | PASS | `node_access` 只有 1 行；自定义模块没有 `hook_node_access` |
| VIP 会话 | NOT TESTED | 没有用 VIP 账号打开页面。现有授权不按 VIP 隐藏已发布节点 |
| 登录后的前台历史面板 | NOT TESTED | 登录历史 API 已测；浏览器里没有走登录用户的面板 |

验收用户 `xedc_search_probe` 已删除。测试写入的关键词、日志、日统计、访客和历史已清空，搜索缓存标签已失效。`min_length` 为 1，`debounce_ms` 为 250。

## G. Git

尚未提交。

新增：

- `XEDC_SEARCH_AUDIT.md`
- `XEDC_SEARCH_IMPLEMENTATION.md`
- `XEDC_SEARCH_API.md`
- `XEDC_SEARCH_TEST_REPORT.md`
- `XEDC_SEARCH_FRONTEND_COMPATIBILITY.md`
- `web/modules/custom/xedc_search/` 下全部模块文件

修改：

- `web/themes/custom/xedc/xedc.search.inc`：接入引擎并保留旧查询回退
- `web/themes/custom/xedc/xedc.theme`：搜索页新变量和缓存上下文
- `web/themes/custom/xedc/templates/search/search-page.html.twig`：日期和条件芯片

删除：无。

数据库：安装时 `hook_schema()` 建表，没有更新钩子。依赖：无 Composer 变化。配置：已启用的 `xedc_search.settings`。
