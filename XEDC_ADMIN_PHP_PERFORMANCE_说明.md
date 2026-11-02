# XEDC 后台 PHP 性能说明

日期：2026-11-02  
站点：https://gitxx.xyz

这是说明，不是原始测试记录。数字、截图和回滚命令的完整版本在 `XEDC_ADMIN_PHP_PERFORMANCE_REPORT.md`。上一轮 CSS 审计在 `XEDC_ADMIN_PERFORMANCE_REPORT.md`，本轮没有再改那部分。

测试浏览器是本机 Playwright 的 Chromium，不是真实 Safari。手机结果是 390×664 视口。

## 后台慢在哪里

页面切换时的等待主要是服务器生成 HTML，不是 CSS。CSS 已经聚合，热缓存下样式传输是 0。登录后的管理页响应头一直是：

- `X-Drupal-Cache: UNCACHEABLE (request policy)`：登录用户不会进匿名页面缓存。
- `X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)`：整页也不进 Dynamic Page Cache。

所以每次打开后台，PHP 都要重新把页面画出来。

内容列表（`/admin/content`）比后台首页慢。视图 `content` 每页 50 行，带批量操作表单和每行的操作链接。热请求里 SQL 合计大约 11–47ms，页面墙钟大约 270–470ms。时间在 PHP 渲染，不在数据库。操作链接字段单独算，热渲染大约 80ms。

## Redis 有没有真正用上

有。不能只看模块已安装。

Redis 8.10.3 在 `127.0.0.1:6379`。Drupal Redis 8.x-1.11 已启用，前缀是 `gitxx`。

| 缓存 | 实际后端 |
| --- | --- |
| bootstrap、config、discovery | APCu 放前面，Redis 做一致存储 |
| render、dynamic page cache、page、entity、menu、data、default 等 | Redis |

当时大约 12MB / 64MB，命中率约 88%，没有驱逐。lock 和 flood 仍在数据库，这是有意留下的。

后台 HTML 本身没有放进 Redis 的页面缓存。render 缓存会用 Redis，整页因为下面的原因进不去。

## 为什么没有把后台页放进 Dynamic Page Cache

Dynamic Page Cache 拒绝这些页面，有两层原因：

1. 主题在每个后台页上加了 `user` 和 `session` 这两个缓存上下文。用户名和快捷方式是按当前用户画出来的。
2. 内容列表、分类术语页的 `max-age` 是 0，因为批量操作、术语表单的 token 已经写进 HTML。Views 编辑页的 `max-age` 虽是永久，HTML 里同样已经有表单 token。

如果去掉 `user` / `session`，Views 编辑页会变成可缓存，同一权限的用户可能共用同一份带 token 的 HTML。所以没有改缓存上下文，也没有开公共页面缓存。

## OPcache

网站请求走 php8.5-fpm，不是 PHP CLI。CLI 里 OPcache 对命令行是关的，不能拿 CLI 的配置判断网站。

修改前，FPM 的脚本缓存 192MB 还有空闲，命中率约 98%，没有因为内存不足重启。interned strings 只有 8MB，而且已经写满（`free=0`）。

已把 FPM 的 `opcache.interned_strings_buffer` 调到 32MB。这是整个 `www` 池共享的 24MB，不是每个 worker 一份。Matomo 不走这个池。调完并预热后，这块用了约 15.7MB，还没满。

没有加大脚本缓存，没有关闭 `validate_timestamps`。仓库里还有未提交代码，关掉时间戳校验会让 PHP 改动不能及时生效。

这项修改消除的是“字符串区已满”。浏览器里的 TTFB 没有因此稳定下降。

PHP-FPM 是 `max_children=5`，诊断时 3 个进程，队列是 0。没有改子进程数，也没有开 slowlog。

## 数据库

MariaDB 13.0.2。InnoDB buffer pool 128MB，命中率 99.87%，空闲页还多。连接数远低于上限。本次数据库进程启动后的慢查询计数是 0。

更早的慢日志里有几秒到几十秒的查询，但检查行数只有几行到二十几行，时间几乎都在锁等待，不是缺索引。

内容列表按 `changed` 倒序，这个字段上已经有索引。`path_alias` 也有路径索引。第一次渲染会打出大约 200 条别名查询，第二次就被别名缓存吃掉。没有根据一份不可靠的 EXPLAIN 去加索引。

## 布局跳动

Views 编辑页 CLS 0.833、分类列表 CLS 0.324，原因相同：操作下拉在第一帧是竖着的一列链接，`dropbutton.js` 跑完才收成大约 32px 高的横条。分类表每一行都有这个按钮，所以行高一起变。

主题原来把横条样式写在 `html.xedc-admin-active.js` 上。Drupal 的 `js` class 要等页脚脚本。页头那一段内联脚本其实早就加上了 `xedc-admin-js`，第一帧用的是它。

改动在 `web/themes/custom/xedc_admin/css/admin-ui.css`：闭合状态改由 `xedc-admin-js` 生效，并在脚本插入箭头之前只显示第一项，留出箭头的宽度。脚本加上 `dropbutton-multiple` 之后，菜单仍按原来的方式打开。

1440×900、三次中位数：

| 页面 | 修改前 CLS | 修改后 CLS |
| --- | ---: | ---: |
| Views 编辑 | 0.833 | 0.368 |
| 分类列表 | 0.324 | 0.022 |
| 标签管理 | 0.569 | 0.129 |

Views 上“从 314px 收到 33px”的那一段已经不在记录里。剩下的 0.368 是稍后设置区域再排一次，没有再改，避免把编辑器布局改坏。

标签页和图片栏目还剩大约 0.12。那是 Drupal 的 tabledrag 在表格前插入“显示行权重”，并收起权重列。这个 class 是脚本加上的，初始 HTML 里没有，没有改核心脚本。

## TTFB 前后

热缓存、1440×900、三次中位数。负的百分比是变快。

| 页面 | 修改前 | 修改后 | 变化 |
| --- | ---: | ---: | ---: |
| 后台首页 | 254ms | 185ms | −27% |
| 内容列表 | 459ms | 538ms | +17% |
| Views 编辑 | 252ms | 229ms | −9% |
| 分类列表 | 172ms | 194ms | +13% |

同一页三次之间常常相差 100ms 以上。内容列表这次的中位数是变慢。不能把这次 OPcache 调整写成 TTFB 优化成功。源站上、绕过 Cloudflare 之后，热的内容列表仍要大约 410–470ms。

## 改了哪些文件

| 文件 | 内容 |
| --- | --- |
| `/etc/php/8.5/fpm/conf.d/99-drupal.ini` | `opcache.interned_strings_buffer = 32` |
| 同目录 `99-drupal.ini.bak-2026-11-02-php-perf` | 修改前的备份 |
| `web/themes/custom/xedc_admin/css/admin-ui.css` | 操作按钮第一帧改成横条 |

改完 CSS 后执行过一次 `drush cr`，用来重建聚合样式。没有执行 `drush cim`，没有改 `config/sync`，没有改 contrib 和核心。

## 功能是否还在

修改后在 Chromium 里点过，没有提交、删除或发布：

- Views 编辑的下拉能打开：分析视图、重复的视图、对显示重新排序、删除视图。
- 分类操作能打开：编辑词汇、增加术语、管理字段、翻译、删除词汇。
- 美图添加能打开媒体库对话框。
- 视频、文章添加能看到标题框。
- 390 宽可以打开导航抽屉。

采样里没有新的 JavaScript 异常，也没有新的 CSS/JS 404。Nginx 日志里更早的 PHP Fatal（对象存储表单类名冲突、AI 审核表单缺方法）发生在本轮之前。

## 回滚

OPcache：

```bash
cp -a /etc/php/8.5/fpm/conf.d/99-drupal.ini.bak-2026-11-02-php-perf /etc/php/8.5/fpm/conf.d/99-drupal.ini
php-fpm8.5 -t && systemctl restart php8.5-fpm
```

CSS：把 `admin-ui.css` 里本轮的 `xedc-admin-active.xedc-admin-js` 操作按钮规则改回 `xedc-admin-active.js`，删掉“第一帧只留第一项和箭头空位”那几条，再 `drush cr`。不要 `git checkout` 这个文件，本轮之前它就有未提交修改。

## 原始记录

`/root/xedc-admin-php-performance/`

里面有修改前后的浏览器 JSON、汇总、截图，以及 OPcache、缓存元数据和视图计时的探针输出。
