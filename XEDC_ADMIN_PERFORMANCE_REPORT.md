# XEDC 后台样式加载性能报告

日期：2026-11-02  
站点：https://gitxx.xyz  
Drupal 11.4.11，PHP 8.5.12  
管理主题：`xedc_admin`（Radix 子主题）  
默认主题：`xedc`（本轮未改）

## 1. 问题背景

后台每次进入新页面时，使用者看到的是先空白、随后整页样式一起出现。需要查清这是不是 FOUC（先画出无样式 HTML，再套上 CSS），以及 CSS 慢在哪一层：主题、Gin、Gin Toolbar、Claro、核心 Navigation、聚合、还是 Nginx。

本轮在 Chromium 里对真实后台页面做了冷缓存、热缓存和限速采样。结论写在第 3 节，数字全部来自这次测量，没有估算。

## 2. 后台样式来源审计

管理页 HTML 的 `<head>` 里固定有 2 个 `rel="stylesheet"`，都在 `<body>` 之前。`/admin` 的聚合 `include` 解压后是这些库：

`audit_config_checks/block`、`contextual/drupal.contextual-links`、`core/components.navigation--toolbar-button`、`navigation/internal.navigation-workspaces`、`system/admin`、`xedc_admin/global`

聚合按组拆成两个文件：模块 CSS（约 68–114KB 解码）和主题 CSS（约 129–133KB 解码）。主题 CSS 含 `html.xedc-admin-active`。两个文件都没有 `@import`。前台主题 `xedc` 没有进入后台页面。

| 来源 | 是否启用/加载 | 实际作用 | 是否发现问题 | 处理结果 |
| --- | --- | --- | --- | --- |
| xedc_admin | 启用，且是当前管理主题。`xedc_admin/global` 在每个后台页的主题聚合里 | 后台布局、颜色、表单、对话框 | 样式本身在首屏 CSS 里，不是后注入。体积大，但是已聚合 | 保留。未拆文件，避免对话框和节点表单样式变晚 |
| Gin | 主题已安装，不是管理主题。后台 CSS 库列表里没有 Gin | 未向当前后台页输出 CSS | 未发现 Gin 样式进入 `xedc_admin` 页面 | 未禁用，未改 Gin |
| Gin Toolbar | 模块未启用（`gin_toolbar=off`，`gin_login=off`） | 不加载 | 未加载，所以不存在重复工具栏 CSS | 未删除模块 |
| Claro | 已安装，不是管理主题。Radix 已 override Claro 对话框 CSS。库列表里没有 Claro | 不参与当前后台页 | 未发现与 `xedc_admin` 重复加载 | 未删除 Claro |
| Drupal Core | 加载。`system/admin`、contextual、BigPipe、toolbar 的 `toolbar-loading` class、Navigation 组件 CSS | 模块聚合（delta 0） | Navigation 工具栏 CSS 仍下载，但主题用 `display:none` 隐藏该工具栏 | 未删核心 CSS |
| Navigation（核心） | 模块启用。每个后台页输出约 110KB 的 `.admin-toolbar` HTML，并预加载 Inter | 核心新后台工具栏。本主题不用它做可见导航 | 预加载 345,588 字节的 `inter-var.woff2`，和阻塞 CSS 同时开始。可见界面用系统字体 | 只关闭预加载，字体文件和工具栏 DOM 保留 |
| Views | `views`、`views_ui` 启用。视图编辑页的模块聚合更大（解码约 114KB，首帧 1693 条规则） | 视图管理界面 | 编辑页在加载后还会再请求一个很小的 Views 网格 CSS。布局跳动来自 Views UI 的 dropbutton 脚本，不是主 CSS | 未改 Views |
| Media Library | 模块启用。美图添加页打开「添加媒体」后对话框正常、有样式 | 媒体选择对话框 | 打开对话框时没有 CSS 404 | 未改 |
| 其他模块 | contextual、audit_config_checks、BigPipe、content_moderation、field_ui、taxonomy、layout_builder、toolbar 已启用。`admin_toolbar` 未启用 | contextual 与审计角标在模块聚合里。BigPipe 替换面包屑和本地任务占位符 | 主 CSS 不在 BigPipe 里。占位符替换不是整页 FOUC | 未改 |

`xedc_admin/global` 的 JS 按 Drupal 11 默认在页脚，不挡首屏。`<head>` 里唯一的外部脚本是 233 字节的 `touchevents` class，在 CSS 之后执行。

## 3. 根本原因

Chromium 没有画出「无样式 HTML 再套皮肤」。

证据：

1. `/admin` 的两份样式表都在 `<head>`，`media="all"`，位于 `<body>` 之前。页面 class `xedc-admin-active` 由服务端 `hook_preprocess_html()` 输出，不依赖 JS。
2. 冷缓存首帧就已经有主题背景 `rgb(247, 247, 248)` 和 1070–1693 条 CSS 规则。`FCP` 不早于 CSS `responseEnd`（`fcpBeforeCss` 全部为 false）。
3. 限速（延迟 300ms，下行约 400kbps，禁用缓存）时，`/admin/content` 在样式到达前没有 body。样式一到，文字已经是「DcLili 控制台」。采样里不存在「有正文且规则数为 0」的帧。记录在 `/root/xedc-admin-performance/fouc-samples.json`。
4. 主 CSS 不是 JS 创建的 `<link>`，也没有 `@import`。搜索主题附件后，动态插入只剩 BigPipe 对面包屑、本地任务、操作按钮的替换。

使用者看到的「先没样式、突然正常」，在这套浏览器里对应的是：后台 HTML 不可缓存，先空白，等文档和阻塞 CSS 到齐后一次性绘出已套好的页面。

空白时间的主要部分是 HTML，不是 CSS 文件个数。

- 响应头：`x-drupal-cache: UNCACHEABLE`，`cache-control` 为 private / no-cache，`surrogate-control` 含 BigPipe。`system.performance` 的页面 `max_age` 是 86400，但登录后的管理页不会进页面缓存。
- 热缓存下 CSS 传输为 0，首页 FCP 仍有 272ms，其中 TTFB 125ms。样式已经在内存或磁盘缓存里，剩下的等待是 PHP 生成 HTML。
- `/admin` 文档约 192KB，其中从 `admin-toolbar-control-bar` 到主画布约 110KB 是被 `layout.css` 隐藏的核心 Navigation 标记。它增加下载和解析，但按约束没有去掉这块 DOM，也没有禁用 Navigation 模块。

同时确认了一笔多余的高优先级下载：核心 Navigation 库 `internal.navigation` 对 `inter-var.woff2`（345,588 字节）设置了 `preload: true`。主题可见文字用的是 `--xedc-admin-font` 系统字体栈，工具栏又是 `display:none`。修复前每个后台页都会预加载这个字体（冷测里 `transferSize` 345,888）。它不负责「无样式 HTML」，但会占带宽。

Views 编辑页的 CLS 0.863 发生在加载后约 3.5 秒：`#edit-displays` 和 `.dropbutton-widget` 被 Views UI 脚本重排（dropbutton 从约 314px 高变成 33px 的工具条）。当时主 CSS 早已应用。这不是样式表迟到。

## 4. 修改文件

| 文件路径 | 修改内容 | 修改原因 |
| --- | --- | --- |
| `web/themes/custom/xedc_admin/xedc_admin.theme` | 新增 `xedc_admin_library_info_alter()`。仅当扩展为 `navigation` 时，把 `internal.navigation` 字体的 `preload` 设为 false | 去掉每个后台页对未使用 Inter 字体的高优先级预加载。字体文件仍注册，`@font-face` 还在 |

没有改 CSS、JS、Twig、`libraries.yml`。对话框、节点表单、进度条样式仍在 `xedc_admin/global` 里，避免为了缩小文件而让它们改成打开后才加载。

该文件里原有未提交改动（节点表单 bundle、`node_add_list` 预处理）保持原样。

## 5. Drupal 配置

| 项目 | 修改前 | 修改后 |
| --- | --- | --- |
| `system.performance` `css.preprocess` | true | 未修改 |
| `system.performance` `js.preprocess` | true | 未修改 |
| `system.performance` `cache.page.max_age` | 86400 | 未修改 |
| 管理主题 `system.theme` | admin=`xedc_admin`，default=`xedc` | 未修改 |
| 其他配置 | — | 未修改。未改 `config/sync`，未执行 `config:import` |

聚合本来就是开的。两个聚合文件都返回 200、`text/css`。关闭或再开一次聚合没有好处，所以没动。

执行过 `drush cr`，让库定义缓存按管理主题重建。之后请求 `/admin` 的 HTML 里已经没有 `inter-var.woff2` 的 preload。

## 6. Nginx

未修改。

配置文件：`/etc/nginx/conf.d/drupal.conf`。已有备份 `drupal.conf.bak-2026-11-01-1420` 和 `drupal.conf.bak-2026-11-01-1431`，不是本轮生成的。

CSS/JS 走 `location ~* \.(js|css|...)$`，`try_files $uri @rewrite`。文件存在时由 Nginx 直接返回，不进 PHP。聚合文件名带内容哈希，这条 location 使用 `expires max`，响应为 `cache-control: max-age=315360000`，带 ETag。抽查两份后台聚合：`content-type: text/css`，`cf-cache-status: HIT`。MIME 正确，没有必要再改缓存头，也没有把所有 CSS 设成另一套永久缓存。

`nginx -t`：语法通过，测试成功。未 reload。

## 7. 性能测试结果

浏览器：Chromium（`/snap/chromium/current`），Playwright。桌面视口 1440×900。  
冷缓存：`Network.setCacheDisabled=true`，每个 URL 只测一次。  
热缓存：先逐页打开一遍，再测，缓存保持开启。传输 0 表示内存或磁盘缓存，不是「禁用缓存」的结果。

FOUC 列：首帧是否出现「已有正文但 CSS 规则数为 0」。全部为否。JS 错误：这些页面为 0。

冷缓存（缓存禁用，CSS 走网络）：

| 页面 | TTFB | FCP | LCP | DOMContentLoaded | Load | CSS 数 | CSS 传输 / 解码 | 最慢 CSS | FOUC |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 后台首页 | 1324ms | 1932ms | 1932ms | 1988ms | 1998ms | 2 | 32,375 / 197,580 | 309ms | 否 |
| 内容列表 | 1761ms | 3120ms | 3120ms | 3886ms | 4012ms | 2 | 37,308 / 224,675 | 1141ms | 否 |
| 美图添加 | 1231ms | 1800ms | 2612ms | 2496ms | 2598ms | 2 | 37,890 / 226,959 | 274ms | 否 |
| Views 编辑 | 1072ms | 2488ms | 2488ms | 3015ms | 3091ms | 2 | 41,527 / 246,585 | 1116ms | 否 |
| 分类管理 | 1111ms | 2356ms | 2356ms | 2870ms | 2900ms | 2 | 37,634 / 226,313 | 1112ms | 否 |
| 标签管理 | 1528ms | 2788ms | 2788ms | 3745ms | 3835ms | 2 | 36,783 / 221,909 | 1125ms | 否 |

这次冷测紧挨着 `drush cr`，又强制绕过浏览器缓存，部分 CSS 耗时到 1.1s。同一批文件在允许缓存时 Cloudflare 为 HIT，耗时是几十毫秒。冷测数字只代表「缓存禁用」那一次，不能当成日常命中缓存的速度。

热缓存：

| 页面 | TTFB | FCP | LCP | DOMContentLoaded | Load | CSS 数 | CSS 传输 | FOUC |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 后台首页 | 125ms | 272ms | 272ms | 309ms | 314ms | 2 | 0 | 否 |
| 内容列表 | 419ms | 652ms | 652ms | 842ms | 851ms | 2 | 0 | 否 |
| 美图添加 | 190ms | 336ms | 492ms | 414ms | 421ms | 2 | 0 | 否 |
| Views 编辑 | 217ms | 400ms | 400ms | 557ms | 583ms | 3 | 0 | 否 |
| 分类管理 | 147ms | 264ms | 264ms | 419ms | 423ms | 2 | 0 | 否 |
| 标签管理 | 206ms | 408ms | 408ms | 642ms | 655ms | 2 | 0 | 否 |

Views 热缓存多出的第 3 个样式表是 Views 网格规则（`css_diGOZLcIhmpsI-zoHqiiO2M9ai-mcuhwb8O7UQi19Js.css`），体积很小，来自视图界面而不是主题。CLS：首页 0；内容列表 0.043；美图 0；Views 0.863；分类 0.324；标签 0.048。Views 的跳动是 dropbutton 脚本，见第 3 节。

修复前同方法冷测首页还会下载 `inter-var.woff2`（传输约 345,888 字节）。修复后冷测和热测的字体列表都是空的，preload 列表也是空的。样式表请求数仍是 2（Views 热缓存为 3）。

限速复测（修复后，同样 300ms / 约 400kbps）：内容列表在 3011ms 解析到第 1 份样式表，3432ms 两份都在且背景已是主题色。修复前同条件第一次看到主题背景是 3587ms。差约 150ms，和当次 TTFB 波动同一量级，不能单独当成字体优化的收益。字体不再占用这 345KB，这是确定的。

## 8. 视觉回归测试

桌面 Chromium：1440×900、1280×800。  
手机：390×664，`hasTouch`、`isMobile`，Safari UA。环境没有可用的 WebKit（缺系统库），这是 Chromium 模拟，不是真机 Safari。

| 检查 | 结果 |
| --- | --- |
| 后台首页 | 通过。顶栏、任务/索引卡片正常。核心 `.admin-toolbar` 为 `display:none` |
| 内容列表 | 通过。筛选、表格、操作下拉在浅色和深色都正常 |
| 美图添加 | 通过。封面和图片集在卡片内。标签文案为「分类栏目」「人物名字」「标签」 |
| 视频添加、文章添加 | 打开为 200，截图正常，未保存 |
| Views 编辑 `image_gallery` | 通过。显示标签、三列设置区、新增按钮仍在。未改按钮高度和对话框 z-index |
| 分类、标签 | 通过 |
| 结构 | `/admin/structure` 列表正常。抽屉默认 `translateX(-105%)`，宽 256px，这是原有关闭状态，本轮未改 CSS |
| Media Library | 点击「添加媒体」后对话框出现，标题「添加或选择媒体」，未上传、未插入、未保存 |
| 深色模式 | 内容列表切换 `data-theme=dark` 后背景、表格、按钮正常，并切回浅色 |
| 手机首页和内容列表 | 390 宽下顶栏和卡片可叠放，汉堡按钮在 |
| Console / CSS 404 | 上述页面 `pageerror` 和 console error 为空，CSS/JS 无 4xx |

## 9. 遗留问题

- 登录后的管理 HTML 仍然不可缓存。热缓存 FCP 的大头仍是 TTFB（首页 125ms，内容列表 419ms）。本轮没有把页面提前画出来，也没有改 PHP 或 BigPipe。
- 核心 Navigation 仍输出约 110KB 隐藏工具栏 DOM，并带上它的 CSS。去掉 DOM 等于拆掉该模块的界面，和「不要靠隐藏模块来修」冲突，所以保留。
- 每个不同的库组合仍生成不同的聚合 URL。模块组（delta 0）几乎每页一个文件。主题组在内容、Views、分类之间可以复用同一份。这是 Drupal 按组聚合的行为，没有关聚合。
- Views 编辑页 CLS 0.863 仍在，来自 Views UI 脚本，不是本轮引入的。
- 没有真机 Safari / WebKit 数据。
- 冷缓存且刚执行 `drush cr` 时，个别 CSS 仍可能超过 1 秒。缓存命中后传输为 0。

## 10. 最终结论

- 找到了现象的原因：不是 CSS 在绘制之后才插入。Chromium 会等 `<head>` 里的两份阻塞样式表，再一次性画出已有主题的页面。空白来自不可缓存的后台 HTML，再加上这两份 CSS。
- 完成了一项有测量依据的修复：去掉未使用的 Inter 预加载（345,588 字节）。没有为了掩盖 FOUC 去隐藏页面或使用 `setTimeout`。
- 页面仍会先空白再显示。空白之后的第一帧已经是后台主题，不是无样式 HTML。
- 样式表请求没有从 2 减到 1。少掉的是每个后台页的字体预加载。
- 热缓存下两份聚合传输为 0，可以复用。切换还是要等各自的 HTML TTFB，所以不会变成即时切换。
- 没有改 Views、内容表单、Media Library、深色模式、抽屉规则，也没有动 Gin Toolbar（它本来就是关的）。

## 11. 截图和日志路径

目录：`/root/xedc-admin-performance/`

- `after-home-1440.png`、`after-home-1280.png`：修复后后台首页
- `after-content.png`：内容列表
- `after-photo.png`：美图添加
- `after-video.png`、`after-article.png`
- `after-views.png`：Views 编辑
- `after-taxonomy.png`、`after-tags.png`、`after-structure.png`
- `after-dark-content.png`：深色模式
- `after-mobile-home.png`、`after-mobile-content.png`：390×664 Chromium 模拟
- `after-media-library.png`：媒体库对话框
- `after.json`：冷/热缓存性能
- `baseline.json`：修复前对照（含 Inter 下载）
- `fouc-samples.json`、`fouc-frame-*.png`：限速下的早期采样
- `admin-warm.har`：热缓存 HAR（不含响应体）
- `after-console.json`：console 与 4xx 记录（均为空）

## 12. 验收清单

- [x] 已检查当前管理主题（`xedc_admin`，默认主题 `xedc`）
- [x] 已检查 Gin（已安装，未向后台页输出 CSS）
- [x] 已检查 Gin Toolbar（未启用，未加载）
- [x] 已检查 Claro（已安装，当前后台聚合未包含）
- [x] 已检查 Drupal Core（`system/admin`、Navigation、toolbar class、BigPipe）
- [x] 已检查后台模块 CSS（Views、Media Library、contextual 等）
- [x] 已检查 Drupal Library 依赖（聚合 include 已解压）
- [x] 已检查 CSS 动态注入（主 CSS 不由 JS 插入）
- [x] 已找到空白后突然出现样式的原因（阻塞 CSS + 不可缓存 HTML；不是绘制后注入）
- [x] 已去掉与首屏 CSS 抢带宽的 Inter 预加载
- [x] 已检查 CSS 聚合（原本开启，未改）
- [x] 已检查浏览器缓存（热缓存 CSS 传输 0）
- [x] 已检查 Nginx 静态资源响应（未改配置）
- [x] 已完成冷缓存测试
- [x] 已完成热缓存测试
- [x] 已完成桌面端回归（1440 与 1280）
- [x] 已完成手机端回归（Chromium 模拟，不是 WebKit）
- [x] 已完成浅色模式回归
- [x] 已完成深色模式回归
- [x] Views 编辑正常
- [x] 内容表单正常（未提交）
- [x] Gin Toolbar 未被删除；模块本来就是关闭的
- [x] Media Library 正常（只打开，未上传）
- [x] 无新增 CSS 404
- [x] 无新增 JS 错误
- [x] 未破坏现有未提交修改
- [x] 未修改前台主题
- [x] 未修改第三方模块源代码
- [x] 已生成本报告
