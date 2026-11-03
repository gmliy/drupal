# XEDC 全站代码资产清单

扫描开始：2026-11-03T15:22:04Z。清单生成：2026-11-03T15:29:23Z。
方式：只读遍历文件系统，并用 `git ls-files` 与 `git status --porcelain` 标记跟踪状态。
本清单不删除、不修改任何既有文件。

## 1. 全库分类汇总

遍历到 **61126** 个文件，合计约 1.6GB（`du` 显示项目目录 1.6G）。下表按归属分类，而不是把 vendor、核心、上传和缓存当成待删垃圾。

| 分类 | 文件数 | 体积 | 归属 | 扫描结论 |
| --- | ---: | ---: | --- | --- |
| Drupal 核心 `web/core` | 21193 | 79.7MB | Drupal 核心 | 框架文件。禁止当无用代码删除。 |
| 主题 npm `web/themes/custom/xedc/node_modules` | 13273 | 148.1MB | npm 自动安装，已被 `.gitignore` 忽略，git 跟踪数 0 | 构建工具依赖。前台不直接加载 node_modules。 |
| Composer `vendor` | 9116 | 84.7MB | Composer 自动安装 | 含 Drupal vendor、AWS SDK、Drush。禁止手工删。 |
| 运行时 `web/sites/default/files` | 8770 | 790.9MB | 运行时生成 + 用户文件 | 其中 `js` 438MB（3972 个文件）、`css` 246MB（2626 个文件）是聚合缓存；其余含上传、样式衍生、配置散列目录。 |
| 第三方模块 `web/modules/contrib` | 3211 | 18.4MB | Composer 安装的 contrib | 与启用模块对应。禁止因“看起来没被自定义代码调用”而卸载。 |
| 自定义主题源码（不含 node_modules） | 2405 | 5.5MB | 项目自维护；其中 Bootstrap Icons SVG 2078 个、8.3MB | 前台 Radix 子主题 `xedc` 与后台主题 `xedc_admin`。 |
| 仓库 `config/sync` | 883 | 0.8MB | 项目维护的配置导出 | 活动配置 885 条。活动库多出 `xedc_core.image_optimize`。 |
| 第三方主题 `web/themes/contrib` | 867 | 4.4MB | Radix 6.0.9 与 Gin 5.0.16 | Gin 已启用但不是当前管理主题。 |
| 自定义模块 | 565 | 3.4MB | 项目自维护 | 见第 3 节逐文件表。 |
| 文档 `docs` | 318 | 44.0MB | 项目文档，含大量验收截图 | 不是运行时代码。 |
| 私有文件 `private` | 315 | 109.4MB | 私有上传、导入包、备份 | `xedc-import` 71MB，`xedc-zips` 40MB。禁止当无引用删除。 |
| 项目其他（含 `_server`、translations、根脚本） | 210 | 24.1MB | 部署与项目文件 | `_server` 22MB。含 git 跟踪的 `settings.php.bak`。 |

自定义主题目录 `web/themes/custom/xedc` 总占用 184MB，其中 `node_modules` 171MB。

## 2. 为什么不全文列出 vendor、core、node_modules

这三类加上运行时 files 共约 5.2 万个文件。它们的归属已经按目录判定。把每一条路径再抄进报告，不会改变“不能当普通无用文件清理”的结论，还会把用户上传和缓存混进待删清单。
下面的逐文件表覆盖项目自己维护的模块和主题源码（不含 `node_modules`，不含 2078 个 Bootstrap Icons SVG）。图标包单独计为一组。

Bootstrap Icons：`web/themes/custom/xedc/assets/icons/bootstrap-icons/`，2078 个文件，1.2MB。结论：静态图标包，与 `package.json` 的 `bootstrap-icons` 重复存放。本阶段不删除。

## 3. 自定义模块与主题源码逐文件

共 889 行。Git 列：`tracked` 表示已跟踪且工作区干净；两字母是 `git status --porcelain` 标记；`untracked/ignored` 表示未跟踪。这些脏文件在审计开始前已经存在，不是本次审计改出来的。

| 路径 | 类型 | 字节 | 模块或主题 | Git | UTC 修改时间 | 归属 | 结论 |
| --- | --- | ---: | --- | --- | --- | --- | --- |
| `web/modules/custom/xa_views/src/Controller/Hit.php` | .php | 2426 | xa_views | tracked | 2026-10-30 18:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xa_views/xa_views.info.yml` | .yml | 116 | xa_views | tracked | 2026-10-24 11:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xa_views/xa_views.routing.yml` | .yml | 219 | xa_views | tracked | 2026-10-30 18:56 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_nav/css/front.css` | .css | 3175 | xedc_admin_nav | tracked | 2026-11-02 10:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_nav/src/Hook/FrontNavHooks.php` | .php | 4393 | xedc_admin_nav | tracked | 2026-11-02 04:40 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_nav/templates/xedc-admin-nav-front.html.twig` | .twig | 6519 | xedc_admin_nav | tracked | 2026-11-01 23:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_nav/xedc_admin_nav.info.yml` | .yml | 213 | xedc_admin_nav | tracked | 2026-11-01 23:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_nav/xedc_admin_nav.libraries.yml` | .yml | 89 | xedc_admin_nav | tracked | 2026-11-01 23:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/css/dashboard.css` | .css | 4100 | xedc_admin_ui | tracked | 2026-11-01 10:41 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/css/tokens.css` | .css | 1152 | xedc_admin_ui | tracked | 2026-11-01 10:10 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/src/ContentOps.php` | .php | 26130 | xedc_admin_ui | tracked | 2026-11-01 11:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/src/Controller/DashboardController.php` | .php | 5567 | xedc_admin_ui | tracked | 2026-11-01 10:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/src/DashboardStats.php` | .php | 11567 | xedc_admin_ui | tracked | 2026-11-01 10:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/src/ModerationGuard.php` | .php | 3994 | xedc_admin_ui | tracked | 2026-11-01 11:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/templates/xedc-admin-dashboard.html.twig` | .twig | 7019 | xedc_admin_ui | tracked | 2026-11-01 10:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/xedc_admin_ui.info.yml` | .yml | 174 | xedc_admin_ui | tracked | 2026-11-01 09:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/xedc_admin_ui.libraries.yml` | .yml | 109 | xedc_admin_ui | tracked | 2026-11-01 10:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/xedc_admin_ui.links.menu.yml` | .yml | 180 | xedc_admin_ui | tracked | 2026-11-01 10:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/xedc_admin_ui.module` | .module | 1555 | xedc_admin_ui | tracked | 2026-11-01 10:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/xedc_admin_ui.permissions.yml` | .yml | 185 | xedc_admin_ui | tracked | 2026-11-01 10:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/xedc_admin_ui.routing.yml` | .yml | 258 | xedc_admin_ui | tracked | 2026-11-01 10:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_admin_ui/xedc_admin_ui.services.yml` | .yml | 239 | xedc_admin_ui | tracked | 2026-11-01 10:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/config/install/xedc_ai.rules.yml` | .yml | 150 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/config/install/xedc_ai.settings.yml` | .yml | 447 | xedc_ai | tracked | 2026-11-02 18:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/config/schema/xedc_ai.schema.yml` | .yml | 2742 | xedc_ai | tracked | 2026-11-02 15:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/css/ai.css` | .css | 4897 | xedc_ai | tracked | 2026-11-02 15:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/js/ai.js` | .js | 639 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Access/AiAccess.php` | .php | 1057 | xedc_ai | tracked | 2026-11-02 15:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Budget/BudgetService.php` | .php | 8568 | xedc_ai | tracked | 2026-11-02 13:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Budget/Money.php` | .php | 1419 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Client/ChatResponse.php` | .php | 1024 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Client/ChatTransportInterface.php` | .php | 407 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Client/GuzzleChatTransport.php` | .php | 1102 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Client/OpenAiChatClient.php` | .php | 7604 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Client/Redactor.php` | .php | 1227 | xedc_ai | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Client/TransportResult.php` | .php | 345 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/DuplicateController.php` | .php | 1511 | xedc_ai | tracked | 2026-11-02 15:41 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/LogController.php` | .php | 2280 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/ModelController.php` | .php | 2702 | xedc_ai | tracked | 2026-11-02 15:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/OverviewController.php` | .php | 4693 | xedc_ai | tracked | 2026-11-02 15:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/ProviderController.php` | .php | 1874 | xedc_ai | tracked | 2026-11-02 14:03 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/ReviewController.php` | .php | 14133 | xedc_ai | tracked | 2026-11-02 15:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/SeoController.php` | .php | 2316 | xedc_ai | tracked | 2026-11-02 14:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/SubmissionController.php` | .php | 2358 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/TranslateController.php` | .php | 2419 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Controller/UsageController.php` | .php | 3367 | xedc_ai | tracked | 2026-11-02 14:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/DuplicateScanForm.php` | .php | 2799 | xedc_ai | tracked | 2026-11-02 15:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/EnqueueForm.php` | .php | 3701 | xedc_ai | tracked | 2026-11-02 14:07 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/ModelAssignForm.php` | .php | 2627 | xedc_ai | tracked | 2026-11-02 13:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/ModelDeleteForm.php` | .php | 2831 | xedc_ai | tracked | 2026-11-02 14:07 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/ModelForm.php` | .php | 6544 | xedc_ai | tracked | 2026-11-02 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/ProviderDeleteForm.php` | .php | 4351 | xedc_ai | tracked | 2026-11-02 13:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/ProviderForm.php` | .php | 12162 | xedc_ai | tracked | 2026-11-02 18:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/QueueControlForm.php` | .php | 3117 | xedc_ai | tracked | 2026-11-02 15:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/ReviewActionForm.php` | .php | 7598 | xedc_ai | tracked | 2026-11-02 15:47 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/ReviewBatchForm.php` | .php | 5644 | xedc_ai | tracked | 2026-11-02 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/RulesForm.php` | .php | 3511 | xedc_ai | tracked | 2026-11-02 15:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/SeoSuggestForm.php` | .php | 2896 | xedc_ai | tracked | 2026-11-02 14:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/SettingsForm.php` | .php | 9468 | xedc_ai | tracked | 2026-11-02 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/SubmissionActionForm.php` | .php | 5762 | xedc_ai | tracked | 2026-11-02 15:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Form/TranslateEnqueueForm.php` | .php | 2749 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Labels.php` | .php | 3318 | xedc_ai | tracked | 2026-11-02 15:31 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Plugin/QueueWorker/AiReviewWorker.php` | .php | 1710 | xedc_ai | tracked | 2026-11-02 13:50 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Plugin/QueueWorker/LocalScanWorker.php` | .php | 1683 | xedc_ai | tracked | 2026-11-02 15:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Provider/ModelProfile.php` | .php | 1144 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Provider/ModelRepository.php` | .php | 5669 | xedc_ai | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Provider/ProviderCatalog.php` | .php | 2709 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Provider/ProviderRepository.php` | .php | 2427 | xedc_ai | tracked | 2026-11-02 13:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Provider/SecretStore.php` | .php | 11103 | xedc_ai | tracked | 2026-11-02 16:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/DisabledVisionAudit.php` | .php | 496 | xedc_ai | tracked | 2026-11-02 15:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/DuplicateFinder.php` | .php | 5589 | xedc_ai | tracked | 2026-11-02 15:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/Fingerprint.php` | .php | 1608 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/LocalInspector.php` | .php | 11907 | xedc_ai | tracked | 2026-11-02 15:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/PerceptualHash.php` | .php | 3343 | xedc_ai | tracked | 2026-11-02 13:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/PromptBuilder.php` | .php | 5135 | xedc_ai | tracked | 2026-11-02 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/ReviewEnqueuer.php` | .php | 8064 | xedc_ai | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/ReviewParser.php` | .php | 9558 | xedc_ai | tracked | 2026-11-02 15:31 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/ReviewRepository.php` | .php | 10157 | xedc_ai | tracked | 2026-11-02 15:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/ReviewRunner.php` | .php | 13233 | xedc_ai | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/RuleLimits.php` | .php | 1241 | xedc_ai | tracked | 2026-11-02 15:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/SubmissionModerator.php` | .php | 7632 | xedc_ai | tracked | 2026-11-02 18:49 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/TextExtractor.php` | .php | 3288 | xedc_ai | tracked | 2026-11-02 13:38 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/TextRules.php` | .php | 4892 | xedc_ai | tracked | 2026-11-02 15:31 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Review/VisionAuditInterface.php` | .php | 775 | xedc_ai | tracked | 2026-11-02 15:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Seo/SeoAdvisor.php` | .php | 6469 | xedc_ai | tracked | 2026-11-02 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Usage/AuditLog.php` | .php | 1658 | xedc_ai | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Usage/Maintenance.php` | .php | 1215 | xedc_ai | tracked | 2026-11-02 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Usage/OperationsStats.php` | .php | 6821 | xedc_ai | tracked | 2026-11-02 15:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/src/Usage/UsageLogger.php` | .php | 5861 | xedc_ai | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-bars.html.twig` | .twig | 318 | xedc_ai | tracked | 2026-11-02 15:39 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-duplicates.html.twig` | .twig | 2208 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-logs.html.twig` | .twig | 1471 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-models.html.twig` | .twig | 1630 | xedc_ai | tracked | 2026-11-02 15:39 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-overview.html.twig` | .twig | 5578 | xedc_ai | tracked | 2026-11-02 15:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-providers.html.twig` | .twig | 1178 | xedc_ai | tracked | 2026-11-02 14:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-review.html.twig` | .twig | 5452 | xedc_ai | tracked | 2026-11-02 15:40 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-reviews.html.twig` | .twig | 4238 | xedc_ai | tracked | 2026-11-02 15:39 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig` | .twig | 1808 | xedc_ai | tracked | 2026-11-02 14:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-submissions.html.twig` | .twig | 1484 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-translate.html.twig` | .twig | 1626 | xedc_ai | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/templates/xedc-ai-usage.html.twig` | .twig | 2788 | xedc_ai | tracked | 2026-11-02 14:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/tests/manual/ai-self-test.php` | .php | 52403 | xedc_ai | tracked | 2026-11-02 18:42 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ai/xedc_ai.info.yml` | .yml | 424 | xedc_ai | tracked | 2026-11-02 13:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.install` | .install | 13859 | xedc_ai | tracked | 2026-11-02 15:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.libraries.yml` | .yml | 119 | xedc_ai | tracked | 2026-11-02 15:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.links.menu.yml` | .yml | 1524 | xedc_ai | tracked | 2026-11-02 15:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.links.task.yml` | .yml | 1669 | xedc_ai | tracked | 2026-11-02 15:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.module` | .module | 5195 | xedc_ai | tracked | 2026-11-02 15:38 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.permissions.yml` | .yml | 1830 | xedc_ai | tracked | 2026-11-02 15:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.routing.yml` | .yml | 4967 | xedc_ai | tracked | 2026-11-02 15:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ai/xedc_ai.services.yml` | .yml | 3272 | xedc_ai | tracked | 2026-11-02 16:17 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/src/AuditReportExporter.php` | .php | 13074 | xedc_audit | tracked | 2026-11-01 17:54 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/src/Controller/AuditDownloadController.php` | .php | 2742 | xedc_audit | tracked | 2026-11-01 17:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/src/Drush/Commands/AuditFormatCommands.php` | .php | 4850 | xedc_audit | tracked | 2026-11-01 17:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/src/EventSubscriber/AuditOverviewSubscriber.php` | .php | 3193 | xedc_audit | tracked | 2026-11-01 18:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/src/SiteAuditReportExporter.php` | .php | 9118 | xedc_audit | tracked | 2026-11-01 17:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/xedc_audit.info.yml` | .yml | 299 | xedc_audit | tracked | 2026-11-01 17:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/xedc_audit.routing.yml` | .yml | 741 | xedc_audit | tracked | 2026-11-01 17:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_audit/xedc_audit.services.yml` | .yml | 587 | xedc_audit | tracked | 2026-11-01 17:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/config/install/xedc_auto_translate.settings.yml` | .yml | 505 | xedc_auto_translate | tracked | 2026-10-30 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/config/schema/xedc_auto_translate.schema.yml` | .yml | 1084 | xedc_auto_translate | tracked | 2026-10-30 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/drush.services.yml` | .yml | 234 | xedc_auto_translate | tracked | 2026-10-30 08:16 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/src/Drush/Commands/AutoTranslateCommands.php` | .php | 4246 | xedc_auto_translate | tracked | 2026-10-30 08:18 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/src/Form/SettingsForm.php` | .php | 10834 | xedc_auto_translate | tracked | 2026-10-30 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/src/Translator.php` | .php | 28920 | xedc_auto_translate | tracked | 2026-10-30 17:16 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/tests/src/Unit/ContentTranslationContractTest.php` | .php | 1797 | xedc_auto_translate | tracked | 2026-11-02 07:57 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_auto_translate/xedc_auto_translate.info.yml` | .yml | 327 | xedc_auto_translate | tracked | 2026-10-30 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/xedc_auto_translate.install` | .install | 4041 | xedc_auto_translate | tracked | 2026-10-30 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/xedc_auto_translate.links.menu.yml` | .yml | 230 | xedc_auto_translate | tracked | 2026-10-30 08:16 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/xedc_auto_translate.module` | .module | 1006 | xedc_auto_translate | tracked | 2026-10-30 08:18 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/xedc_auto_translate.permissions.yml` | .yml | 176 | xedc_auto_translate | tracked | 2026-10-30 11:27 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/xedc_auto_translate.routing.yml` | .yml | 249 | xedc_auto_translate | tracked | 2026-10-30 08:16 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_auto_translate/xedc_auto_translate.services.yml` | .yml | 557 | xedc_auto_translate | tracked | 2026-10-30 08:17 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/assets/phpqrcode.php` | .php | 133410 | xedc_core | tracked | 2026-11-02 15:29 | 项目自维护模块 | 被 QrImage.php require。二维码分享在用。 |
| `web/modules/custom/xedc_core/config/install/xedc_core.settings.yml` | .yml | 645 | xedc_core |  M | 2026-11-03 14:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/config/install/xedc_core.storage.yml` | .yml | 709 | xedc_core | tracked | 2026-11-02 09:31 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/config/optional/user.role.vip.yml` | .yml | 159 | xedc_core | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/config/schema/xedc_core.schema.yml` | .yml | 7663 | xedc_core |  M | 2026-11-03 14:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/css/admin.css` | .css | 2034 | xedc_core |  M | 2026-11-03 15:01 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/css/asset-upload.css` | .css | 1403 | xedc_core | ?? | 2026-11-03 13:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/css/dashboard.css` | .css | 11671 | xedc_core | tracked | 2026-11-01 07:41 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/css/image-optimize.css` | .css | 819 | xedc_core |  M | 2026-11-03 08:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/css/ops.css` | .css | 17166 | xedc_core |  M | 2026-11-03 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/drush.services.yml` | .yml | 596 | xedc_core | tracked | 2026-11-02 21:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/js/asset-upload.js` | .js | 13551 | xedc_core | ?? | 2026-11-03 15:01 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/js/files.js` | .js | 3710 | xedc_core | tracked | 2026-11-02 05:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/js/monitor.js` | .js | 37852 | xedc_core |  M | 2026-11-03 15:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/js/progress.js` | .js | 1543 | xedc_core |  M | 2026-11-03 15:01 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/js/storage-secret.js` | .js | 661 | xedc_core | tracked | 2026-11-02 10:25 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/js/storage-switch.js` | .js | 455 | xedc_core | ?? | 2026-11-03 10:39 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/phpunit.xml` | .xml | 357 | xedc_core | tracked | 2026-11-02 16:51 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/scripts/xedc-import-worker.service` | .service | 768 | xedc_core | untracked/ignored | 2026-11-03 15:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Access/CleanupAccess.php` | .php | 2812 | xedc_core | tracked | 2026-11-02 07:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Access/StorageAccess.php` | .php | 574 | xedc_core | tracked | 2026-11-02 16:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Access/TaskAccess.php` | .php | 2495 | xedc_core |  M | 2026-11-03 13:54 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/AltManager.php` | .php | 4510 | xedc_core |  M | 2026-11-03 12:18 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ArchiveManager.php` | .php | 30178 | xedc_core |  M | 2026-11-03 15:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Cache/Context/XedcGuestCacheContext.php` | .php | 1183 | xedc_core | tracked | 2026-11-02 20:13 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/AssetUploadController.php` | .php | 17470 | xedc_core | ?? | 2026-11-03 12:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/DownloadDeliveryController.php` | .php | 10915 | xedc_core | tracked | 2026-11-02 19:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/EngagementAdminController.php` | .php | 30046 | xedc_core | tracked | 2026-11-02 20:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/EngagementController.php` | .php | 4153 | xedc_core | tracked | 2026-11-02 21:06 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/FileCleanupController.php` | .php | 23890 | xedc_core |  M | 2026-11-03 11:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/FileStatsController.php` | .php | 5732 | xedc_core |  M | 2026-11-03 13:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/ImportController.php` | .php | 15104 | xedc_core |  M | 2026-11-03 15:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/MonitorController.php` | .php | 5236 | xedc_core | tracked | 2026-11-02 17:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/ProtectedDownloadController.php` | .php | 1460 | xedc_core | tracked | 2026-11-02 09:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/StorageController.php` | .php | 17508 | xedc_core |  M | 2026-11-03 11:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/VideoProgressController.php` | .php | 2794 | xedc_core | tracked | 2026-11-03 06:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/VideoTagController.php` | .php | 4291 | xedc_core | tracked | 2026-11-02 21:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Controller/VideoTranscodeController.php` | .php | 6015 | xedc_core | tracked | 2026-11-03 05:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Drush/Commands/ImportCommands.php` | .php | 6362 | xedc_core |  M | 2026-11-03 15:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Drush/Commands/TrafficCommands.php` | .php | 1088 | xedc_core | tracked | 2026-11-02 17:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Drush/Commands/VideoDurationCommands.php` | .php | 1780 | xedc_core | tracked | 2026-11-02 21:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/DownloadAccess.php` | .php | 4333 | xedc_core | tracked | 2026-11-02 15:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/DownloadRecorder.php` | .php | 4434 | xedc_core | tracked | 2026-11-02 20:14 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/EngagementBuilder.php` | .php | 4932 | xedc_core | tracked | 2026-11-02 20:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/EngagementReport.php` | .php | 26868 | xedc_core | tracked | 2026-11-02 20:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/EngagementSchema.php` | .php | 8246 | xedc_core | tracked | 2026-11-02 20:13 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/ExternalUrlGuard.php` | .php | 1906 | xedc_core | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/GuestIdentity.php` | .php | 2058 | xedc_core | tracked | 2026-11-02 20:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/LikeManager.php` | .php | 10878 | xedc_core | tracked | 2026-11-02 20:14 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/MembershipManager.php` | .php | 9062 | xedc_core | tracked | 2026-11-02 20:14 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/OriginalFiles.php` | .php | 2581 | xedc_core | tracked | 2026-11-02 19:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/QrImage.php` | .php | 1642 | xedc_core | tracked | 2026-11-02 15:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/ResourcePresenter.php` | .php | 5499 | xedc_core | tracked | 2026-11-02 17:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/ShareRecorder.php` | .php | 4259 | xedc_core | tracked | 2026-11-02 20:14 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/ZipBuilder.php` | .php | 18634 | xedc_core | tracked | 2026-11-02 19:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Engagement/ZipPendingException.php` | .php | 208 | xedc_core | tracked | 2026-11-02 15:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Entity/DownloadResource.php` | .php | 10237 | xedc_core | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Entity/DownloadResourceAccessControlHandler.php` | .php | 938 | xedc_core | tracked | 2026-11-02 15:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Entity/DownloadResourceListBuilder.php` | .php | 6246 | xedc_core | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Entity/DownloadResourceStorageSchema.php` | .php | 769 | xedc_core | tracked | 2026-11-02 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/EventSubscriber/GuestCookieSubscriber.php` | .php | 800 | xedc_core | tracked | 2026-11-02 20:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/EventSubscriber/ImageStyleRouteSubscriber.php` | .php | 1160 | xedc_core | tracked | 2026-11-02 23:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Filter/TrustedCdnImageUrl.php` | .php | 2735 | xedc_core | untracked/ignored | 2026-11-03 10:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Filter/TrustedHtmlImageSecure.php` | .php | 1658 | xedc_core | untracked/ignored | 2026-11-03 10:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/AnalysisScanForm.php` | .php | 2838 | xedc_core | tracked | 2026-11-02 05:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/CleanupTaskForm.php` | .php | 4305 | xedc_core | tracked | 2026-11-02 05:26 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/DownloadResourceForm.php` | .php | 2560 | xedc_core | tracked | 2026-11-02 15:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/FileScanForm.php` | .php | 3155 | xedc_core | tracked | 2026-11-01 18:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/ImageOptimizeForm.php` | .php | 12768 | xedc_core |  M | 2026-11-03 08:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/ImportListForm.php` | .php | 23357 | xedc_core |  M | 2026-11-03 12:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/LikeRemoveForm.php` | .php | 1553 | xedc_core | tracked | 2026-11-02 18:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/LikeStateForm.php` | .php | 2413 | xedc_core | tracked | 2026-11-02 20:13 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/MergePlanForm.php` | .php | 2367 | xedc_core | tracked | 2026-11-02 05:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StorageCdnForm.php` | .php | 3495 | xedc_core | tracked | 2026-11-02 16:50 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StorageCredentialForm.php` | .php | 7590 | xedc_core | tracked | 2026-11-02 10:14 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StorageMigrationForm.php` | .php | 4477 | xedc_core | tracked | 2026-11-03 00:18 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StoragePolicyForm.php` | .php | 3072 | xedc_core | tracked | 2026-11-02 09:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StoragePricingForm.php` | .php | 2973 | xedc_core | tracked | 2026-11-03 00:18 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StorageProfileForm.php` | .php | 8905 | xedc_core | tracked | 2026-11-02 16:49 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StorageProviderOpsForm.php` | .php | 4747 | xedc_core | tracked | 2026-11-02 10:20 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StorageSecurityForm.php` | .php | 3097 | xedc_core | tracked | 2026-11-02 09:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/StorageSwitchForm.php` | .php | 13289 | xedc_core |  M | 2026-11-03 11:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/TaskActionForm.php` | .php | 4750 | xedc_core |  M | 2026-11-03 13:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/UnusedPlanForm.php` | .php | 1992 | xedc_core | tracked | 2026-11-02 05:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/VideoTranscodeConfirmForm.php` | .php | 2557 | xedc_core | tracked | 2026-11-03 05:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/VideoTranscodeEnqueueForm.php` | .php | 1501 | xedc_core | tracked | 2026-11-03 05:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/VideoTranscodeFilterForm.php` | .php | 2026 | xedc_core | tracked | 2026-11-03 05:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/VipGrantForm.php` | .php | 5537 | xedc_core | tracked | 2026-11-02 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Form/ZipRetryForm.php` | .php | 2436 | xedc_core | tracked | 2026-11-02 17:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/GalleryMetrics.php` | .php | 14898 | xedc_core | ?? | 2026-11-03 13:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Image/ImageOptimizeApplier.php` | .php | 22280 | xedc_core |  M | 2026-11-03 10:25 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Image/ImageOptimizeRepository.php` | .php | 7282 | xedc_core |  M | 2026-11-03 08:31 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Image/ImageOptimizeResult.php` | .php | 1068 | xedc_core | tracked | 2026-11-03 06:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Image/ImageOptimizer.php` | .php | 19039 | xedc_core |  M | 2026-11-03 08:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Image/OptimizingFileRepository.php` | .php | 1788 | xedc_core | ?? | 2026-11-03 08:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ImageBatchIntake.php` | .php | 9706 | xedc_core | ?? | 2026-11-03 12:20 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ImagePipeline.php` | .php | 41657 | xedc_core |  M | 2026-11-03 15:01 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ImportAbortException.php` | .php | 210 | xedc_core | tracked | 2026-11-01 08:26 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ImportException.php` | .php | 166 | xedc_core | tracked | 2026-10-31 23:18 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ImportKick.php` | .php | 822 | xedc_core | ?? | 2026-11-03 12:20 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ImportProcessor.php` | .php | 32786 | xedc_core |  M | 2026-11-03 15:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/ImportStatus.php` | .php | 4124 | xedc_core |  M | 2026-11-03 14:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/CleanupRepository.php` | .php | 23522 | xedc_core | tracked | 2026-11-02 07:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/DuplicateFileScanner.php` | .php | 6792 | xedc_core | tracked | 2026-11-02 15:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/DuplicateMath.php` | .php | 2631 | xedc_core | tracked | 2026-11-02 05:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/FileAnalysisRunner.php` | .php | 9740 | xedc_core | tracked | 2026-11-02 05:50 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/FileCleanupManager.php` | .php | 31294 | xedc_core | tracked | 2026-11-02 15:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/FilePathGuard.php` | .php | 8663 | xedc_core | tracked | 2026-11-02 15:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/FileQuarantineManager.php` | .php | 13934 | xedc_core | tracked | 2026-11-02 15:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/FileReferenceAnalyzer.php` | .php | 29206 | xedc_core | tracked | 2026-11-02 15:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/FileReferenceMerger.php` | .php | 14008 | xedc_core | tracked | 2026-11-02 08:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/FileReferencePresenter.php` | .php | 9496 | xedc_core | tracked | 2026-11-02 08:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/ManualMergeException.php` | .php | 200 | xedc_core | tracked | 2026-11-02 08:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/MergeIntegrityChecker.php` | .php | 8218 | xedc_core | tracked | 2026-11-02 08:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/RecoverableMergeException.php` | .php | 222 | xedc_core | tracked | 2026-11-02 08:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Cleanup/UnusedFileScanner.php` | .php | 12402 | xedc_core | tracked | 2026-11-02 15:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/DiskOverview.php` | .php | 4142 | xedc_core | tracked | 2026-11-01 18:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/FileScanner.php` | .php | 36754 | xedc_core | tracked | 2026-11-01 19:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/MediaCensus.php` | .php | 15696 | xedc_core | tracked | 2026-11-01 18:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/MonitorBoard.php` | .php | 63897 | xedc_core |  M | 2026-11-03 14:38 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/NetdataCatalog.php` | .php | 9864 | xedc_core | tracked | 2026-11-02 16:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/NetdataClient.php` | .php | 6452 | xedc_core | tracked | 2026-11-02 16:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/NetdataReader.php` | .php | 6057 | xedc_core | tracked | 2026-11-02 16:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/OpsFormat.php` | .php | 2139 | xedc_core |  M | 2026-11-03 14:38 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/ServerMonitor.php` | .php | 53132 | xedc_core |  M | 2026-11-03 15:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/BootGuard.php` | .php | 38802 | xedc_core | tracked | 2026-11-03 00:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CachePolicy.php` | .php | 1800 | xedc_core | tracked | 2026-11-02 16:47 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CapabilityProbe.php` | .php | 16342 | xedc_core | tracked | 2026-11-02 10:11 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CdnInspector.php` | .php | 17584 | xedc_core | tracked | 2026-11-02 17:48 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CdnStatus.php` | .php | 12693 | xedc_core | tracked | 2026-11-02 16:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CdnUrlPolicy.php` | .php | 2765 | xedc_core | tracked | 2026-11-02 16:47 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ConnectionTester.php` | .php | 8967 | xedc_core | tracked | 2026-11-02 10:10 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CostEstimator.php` | .php | 2449 | xedc_core |  M | 2026-11-03 11:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CredentialResolver.php` | .php | 3215 | xedc_core | tracked | 2026-11-02 10:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/CredentialVault.php` | .php | 17184 | xedc_core | tracked | 2026-11-02 13:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/DeliveryUrl.php` | .php | 2065 | xedc_core | tracked | 2026-11-02 16:49 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/DownloadGate.php` | .php | 9113 | xedc_core | tracked | 2026-11-02 20:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ImageStyleFileSystem.php` | .php | 10423 | xedc_core | tracked | 2026-11-02 23:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ImageVariantBuilder.php` | .php | 5076 | xedc_core | tracked | 2026-11-02 14:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/IntegrityChecker.php` | .php | 1861 | xedc_core | tracked | 2026-11-02 09:19 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/LocalFileBridge.php` | .php | 2443 | xedc_core | tracked | 2026-11-02 15:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/LocalTempFileManager.php` | .php | 9132 | xedc_core | tracked | 2026-11-02 15:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/MediaObjectPipeline.php` | .php | 20725 | xedc_core | tracked | 2026-11-02 15:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/MigrationExecutor.php` | .php | 24268 | xedc_core |  M | 2026-11-03 10:39 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/MigrationPlanner.php` | .php | 8728 | xedc_core | tracked | 2026-11-02 20:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/MigrationRepository.php` | .php | 4030 | xedc_core | tracked | 2026-11-02 14:25 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/MultipartCleaner.php` | .php | 4120 | xedc_core | tracked | 2026-11-02 10:11 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ObjectMonitor.php` | .php | 10733 | xedc_core | tracked | 2026-11-02 10:11 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/PlayableMp4.php` | .php | 2398 | xedc_core | tracked | 2026-11-02 16:48 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ProtectedDownloader.php` | .php | 4948 | xedc_core | tracked | 2026-11-02 20:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/Provider/CloudflareR2Provider.php` | .php | 3898 | xedc_core |  M | 2026-11-03 11:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/Provider/LocalStorageProvider.php` | .php | 3201 | xedc_core |  M | 2026-11-03 11:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/Provider/StorageProviderInterface.php` | .php | 1088 | xedc_core | tracked | 2026-11-02 23:13 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/Provider/StorageProviderRegistry.php` | .php | 2640 | xedc_core | tracked | 2026-11-02 23:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/ProviderCatalog.php` | .php | 3117 | xedc_core | tracked | 2026-11-02 23:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/PublicUrl.php` | .php | 5566 | xedc_core | tracked | 2026-11-03 04:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/RemoteFileReader.php` | .php | 6419 | xedc_core | tracked | 2026-11-02 15:25 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/RemoteFileWriter.php` | .php | 9222 | xedc_core | tracked | 2026-11-02 15:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/RuntimeConsistency.php` | .php | 2997 | xedc_core | tracked | 2026-11-03 00:10 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/RuntimeState.php` | .php | 8467 | xedc_core | tracked | 2026-11-03 00:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/RuntimeStatus.php` | .php | 7735 | xedc_core | tracked | 2026-11-03 00:16 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/S3ClientFactory.php` | .php | 2456 | xedc_core | tracked | 2026-11-02 14:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageConfig.php` | .php | 16926 | xedc_core | tracked | 2026-11-02 23:14 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageHealthChecker.php` | .php | 2003 | xedc_core | tracked | 2026-11-02 14:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageJournal.php` | .php | 2384 | xedc_core | tracked | 2026-11-02 09:20 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageManager.php` | .php | 2771 | xedc_core | tracked | 2026-11-02 20:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageOperationGuard.php` | .php | 4385 | xedc_core | tracked | 2026-11-02 15:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageSizeFormatter.php` | .php | 2139 | xedc_core | ?? | 2026-11-03 11:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchManager.php` | .php | 41859 | xedc_core |  M | 2026-11-03 11:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchboard.php` | .php | 5292 | xedc_core | tracked | 2026-11-02 14:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageUriResolver.php` | .php | 8938 | xedc_core | tracked | 2026-11-02 20:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/TakeoverReadiness.php` | .php | 26289 | xedc_core |  M | 2026-11-03 11:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/WrapperStatus.php` | .php | 2280 | xedc_core | tracked | 2026-11-03 00:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Traffic/CounterSource.php` | .php | 355 | xedc_core | tracked | 2026-11-02 17:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Traffic/NicGuard.php` | .php | 4451 | xedc_core | tracked | 2026-11-02 17:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Traffic/SysfsCounterReader.php` | .php | 1166 | xedc_core | tracked | 2026-11-02 17:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficCollector.php` | .php | 14009 | xedc_core | tracked | 2026-11-02 17:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficDelta.php` | .php | 1896 | xedc_core | tracked | 2026-11-02 17:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficReport.php` | .php | 16910 | xedc_core |  M | 2026-11-03 15:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Ops/Traffic/TrafficSchema.php` | .php | 3739 | xedc_core | tracked | 2026-11-02 17:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/QueueWorker/DownloadZipWorker.php` | .php | 1388 | xedc_core | tracked | 2026-11-02 15:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/QueueWorker/FileCleanupWorker.php` | .php | 1933 | xedc_core | tracked | 2026-11-02 05:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/QueueWorker/FileScanWorker.php` | .php | 1539 | xedc_core | tracked | 2026-11-01 18:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/QueueWorker/ImageImportWorker.php` | .php | 1619 | xedc_core |  M | 2026-11-03 14:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/QueueWorker/ImageOptimizeWorker.php` | .php | 2033 | xedc_core |  M | 2026-11-03 08:31 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/QueueWorker/VideoDurationWorker.php` | .php | 1382 | xedc_core | tracked | 2026-11-02 21:01 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/QueueWorker/VideoTranscodeWorker.php` | .php | 1426 | xedc_core | tracked | 2026-11-03 05:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/views/field/ContentEntityOperations.php` | .php | 2190 | xedc_core | tracked | 2026-11-02 17:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/views/field/GalleryStatus.php` | .php | 2213 | xedc_core | ?? | 2026-11-03 13:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Plugin/views/filter/GalleryState.php` | .php | 1912 | xedc_core | untracked/ignored | 2026-11-03 13:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Publisher.php` | .php | 8039 | xedc_core | tracked | 2026-10-31 23:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/StreamWrapper/XedcPublicS3fsStream.php` | .php | 10128 | xedc_core | tracked | 2026-11-03 04:41 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/TaskRepository.php` | .php | 16286 | xedc_core |  M | 2026-11-03 15:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/TempCleaner.php` | .php | 5999 | xedc_core | tracked | 2026-11-01 08:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/DurationFormatter.php` | .php | 607 | xedc_core | tracked | 2026-11-02 21:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/DurationManager.php` | .php | 16616 | xedc_core | tracked | 2026-11-03 05:26 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/MediaProbe.php` | .php | 4031 | xedc_core | tracked | 2026-11-03 05:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/PlaybackStats.php` | .php | 1285 | xedc_core | tracked | 2026-11-02 21:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/PlaybackTranscoder.php` | .php | 7799 | xedc_core | tracked | 2026-11-03 05:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/TranscodeManager.php` | .php | 18242 | xedc_core | tracked | 2026-11-03 05:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/TranscodeSchema.php` | .php | 2718 | xedc_core | tracked | 2026-11-03 05:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/VideoCards.php` | .php | 7013 | xedc_core |  M | 2026-11-03 08:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/Video/VideoListing.php` | .php | 3579 | xedc_core | tracked | 2026-11-02 21:01 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/src/XedcCoreServiceProvider.php` | .php | 1193 | xedc_core | tracked | 2026-11-03 00:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/storage-takeover-status.html.twig` | .twig | 2534 | xedc_core | ?? | 2026-11-03 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-cleanup-log.html.twig` | .twig | 2602 | xedc_core | tracked | 2026-11-02 05:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-cleanup-task.html.twig` | .twig | 5084 | xedc_core |  M | 2026-11-03 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-cleanup-tasks.html.twig` | .twig | 2319 | xedc_core | tracked | 2026-11-02 05:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-duplicate.html.twig` | .twig | 4489 | xedc_core | tracked | 2026-11-02 07:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-duplicates.html.twig` | .twig | 3281 | xedc_core | tracked | 2026-11-02 07:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-files.html.twig` | .twig | 19556 | xedc_core |  M | 2026-11-03 13:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-monitor-detail.html.twig` | .twig | 6296 | xedc_core | tracked | 2026-11-02 17:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-monitor.html.twig` | .twig | 9546 | xedc_core | tracked | 2026-11-02 18:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-reference.html.twig` | .twig | 3187 | xedc_core | tracked | 2026-11-02 07:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-storage.html.twig` | .twig | 29165 | xedc_core |  M | 2026-11-03 11:24 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-traffic.html.twig` | .twig | 3800 | xedc_core | tracked | 2026-11-02 18:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-unused-item.html.twig` | .twig | 2066 | xedc_core | tracked | 2026-11-02 08:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-core-unused.html.twig` | .twig | 4244 | xedc_core | tracked | 2026-11-02 05:36 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/templates/xedc-engagement-bar.html.twig` | .twig | 3744 | xedc_core | tracked | 2026-11-02 17:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/tests/bootstrap.php` | .php | 440 | xedc_core | tracked | 2026-11-02 16:51 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/archive-perf-self-test.php` | .php | 16797 | xedc_core | ?? | 2026-11-03 15:18 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/asset-upload-self-test.php` | .php | 17787 | xedc_core | ?? | 2026-11-03 12:32 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/cdn-delivery-self-test.php` | .php | 5630 | xedc_core | tracked | 2026-11-02 17:00 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/cdn-isolation-retest.php` | .php | 20461 | xedc_core | tracked | 2026-11-02 17:49 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/cdn-migration-precheck.php` | .php | 6523 | xedc_core | tracked | 2026-11-02 17:02 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/cdn-security-self-test.php` | .php | 7725 | xedc_core | tracked | 2026-11-02 17:01 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/engagement-self-test.php` | .php | 22955 | xedc_core | tracked | 2026-11-02 20:34 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/file-cleanup-self-test.php` | .php | 42892 | xedc_core | tracked | 2026-11-02 09:27 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/image-optimize-self-test.php` | .php | 21101 | xedc_core | ?? | 2026-11-03 08:39 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/image-phase2-self-test.php` | .php | 12421 | xedc_core | ?? | 2026-11-03 13:12 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/import-self-test.php` | .php | 28975 | xedc_core | tracked | 2026-11-01 08:47 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/r2-acceptance-business.php` | .php | 17174 | xedc_core | tracked | 2026-11-02 16:12 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/r2-acceptance-precheck.php` | .php | 8703 | xedc_core | tracked | 2026-11-02 16:05 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/r2-architecture-self-test.php` | .php | 13500 | xedc_core | tracked | 2026-11-03 00:02 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/r2-fpm-wrapper-io-test.php` | .php | 8762 | xedc_core | tracked | 2026-11-02 21:22 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/r2-integration-self-test.php` | .php | 5561 | xedc_core | tracked | 2026-11-02 15:42 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/storage-adapter-self-test.php` | .php | 5606 | xedc_core | tracked | 2026-11-02 15:27 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/storage-self-test.php` | .php | 22087 | xedc_core | tracked | 2026-11-02 20:52 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/storage-switch-self-test.php` | .php | 13757 | xedc_core | ?? | 2026-11-03 10:42 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/storage-takeover-size-self-test.php` | .php | 9197 | xedc_core | ?? | 2026-11-03 11:28 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/manual/traffic-self-test.php` | .php | 12146 | xedc_core | tracked | 2026-11-02 17:57 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/src/Unit/CdnDeliveryPolicyTest.php` | .php | 3987 | xedc_core | tracked | 2026-11-02 16:53 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/src/Unit/StorageSizeFormatterTest.php` | .php | 2238 | xedc_core | ?? | 2026-11-03 11:27 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/src/Unit/TakeoverReadinessTest.php` | .php | 9486 | xedc_core | ?? | 2026-11-03 11:25 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/src/Unit/TrafficDeltaTest.php` | .php | 2910 | xedc_core | tracked | 2026-11-02 17:46 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/tests/src/Unit/TrustedCdnImageUrlTest.php` | .php | 2173 | xedc_core | ?? | 2026-11-03 10:43 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_core/xedc_core.info.yml` | .yml | 340 | xedc_core | tracked | 2026-11-02 09:27 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.install` | .install | 42911 | xedc_core |  M | 2026-11-03 14:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.libraries.yml` | .yml | 844 | xedc_core |  M | 2026-11-03 12:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.links.menu.yml` | .yml | 3044 | xedc_core |  M | 2026-11-03 12:26 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.links.task.yml` | .yml | 3076 | xedc_core | tracked | 2026-11-03 05:28 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.module` | .module | 31678 | xedc_core |  M | 2026-11-03 15:15 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.permissions.yml` | .yml | 5397 | xedc_core | tracked | 2026-11-03 06:49 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.routing.yml` | .yml | 24007 | xedc_core |  M | 2026-11-03 12:51 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_core.services.yml` | .yml | 16738 | xedc_core |  M | 2026-11-03 12:49 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_core/xedc_storage.runtime.php` | .php | 1194 | xedc_core | tracked | 2026-11-02 09:27 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/src/Controller/SitemapController.php` | .php | 2707 | xedc_home | tracked | 2026-10-31 12:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/src/EventSubscriber/HomeCacheSubscriber.php` | .php | 1634 | xedc_home | tracked | 2026-10-29 05:50 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/src/EventSubscriber/SecurityHeaderSubscriber.php` | .php | 2713 | xedc_home | tracked | 2026-11-02 22:51 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/src/EventSubscriber/SitemapXslSubscriber.php` | .php | 1524 | xedc_home | tracked | 2026-10-30 09:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/src/HomeCards.php` | .php | 12406 | xedc_home | tracked | 2026-11-02 21:06 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/src/Plugin/Block/PhotoCategoriesBlock.php` | .php | 2113 | xedc_home | tracked | 2026-10-30 11:39 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/src/Plugin/views/cache/BundleTag.php` | .php | 1518 | xedc_home | tracked | 2026-10-30 08:19 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/templates/xedc-home-categories.html.twig` | .twig | 668 | xedc_home | tracked | 2026-10-30 08:41 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/xedc_home.info.yml` | .yml | 243 | xedc_home | tracked | 2026-10-29 05:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/xedc_home.module` | .module | 20798 | xedc_home |  M | 2026-11-03 12:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/xedc_home.routing.yml` | .yml | 199 | xedc_home | tracked | 2026-10-30 08:18 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_home/xedc_home.services.yml` | .yml | 741 | xedc_home | tracked | 2026-10-31 09:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/js/preview.js` | .js | 1134 | xedc_mail | tracked | 2026-11-02 10:06 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/src/Controller/PreviewController.php` | .php | 1063 | xedc_mail | tracked | 2026-11-02 11:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/src/Form/EmailPreviewForm.php` | .php | 4021 | xedc_mail | tracked | 2026-11-02 11:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/src/MailWrapper.php` | .php | 9116 | xedc_mail | tracked | 2026-11-02 11:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/templates/xedc-mail.html.twig` | .twig | 3918 | xedc_mail | tracked | 2026-11-02 10:30 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/xedc_mail.info.yml` | .yml | 205 | xedc_mail | tracked | 2026-11-02 10:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/xedc_mail.libraries.yml` | .yml | 87 | xedc_mail | tracked | 2026-11-02 10:06 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/xedc_mail.module` | .module | 1623 | xedc_mail | tracked | 2026-11-02 11:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/xedc_mail.routing.yml` | .yml | 499 | xedc_mail | tracked | 2026-11-02 10:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_mail/xedc_mail.services.yml` | .yml | 119 | xedc_mail | tracked | 2026-11-02 10:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/css/member.css` | .css | 53747 | xedc_member | tracked | 2026-11-02 21:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/css/quiet.css` | .css | 364 | xedc_member | tracked | 2026-10-28 20:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/js/member.js` | .js | 8471 | xedc_member | tracked | 2026-11-02 20:22 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Access/MemberCenterAccessCheck.php` | .php | 1628 | xedc_member | tracked | 2026-11-02 21:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Controller/MemberController.php` | .php | 35000 | xedc_member | tracked | 2026-11-02 23:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/EventSubscriber/HistorySubscriber.php` | .php | 2010 | xedc_member | tracked | 2026-10-24 14:41 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/EventSubscriber/MemberAliasSubscriber.php` | .php | 4478 | xedc_member | tracked | 2026-11-02 23:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Form/FavoriteToggleForm.php` | .php | 5261 | xedc_member | tracked | 2026-10-28 22:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Form/HistoryClearForm.php` | .php | 2146 | xedc_member | tracked | 2026-10-28 20:56 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Form/HistoryDeleteForm.php` | .php | 3205 | xedc_member | tracked | 2026-10-28 22:00 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Form/HistorySettingsForm.php` | .php | 2097 | xedc_member | tracked | 2026-10-29 04:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Form/ProfileForm.php` | .php | 8366 | xedc_member | tracked | 2026-11-02 23:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/MemberLazyBuilders.php` | .php | 3221 | xedc_member | tracked | 2026-10-30 14:48 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/PathProcessor/MemberAliasPathProcessor.php` | .php | 1943 | xedc_member | tracked | 2026-11-02 21:54 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Service/FavoriteManager.php` | .php | 4827 | xedc_member | tracked | 2026-10-28 20:56 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Service/HistoryManager.php` | .php | 6410 | xedc_member | tracked | 2026-10-28 20:56 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Service/MemberAliasManager.php` | .php | 17913 | xedc_member |  M | 2026-11-03 08:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/src/Service/PublicProfileBuilder.php` | .php | 24961 | xedc_member | tracked | 2026-11-02 23:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-card.html.twig` | .twig | 1020 | xedc_member | tracked | 2026-10-29 03:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-comments.html.twig` | .twig | 1736 | xedc_member | tracked | 2026-11-02 20:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-downloads.html.twig` | .twig | 2192 | xedc_member | tracked | 2026-11-02 20:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-favorites.html.twig` | .twig | 1184 | xedc_member | tracked | 2026-10-30 13:01 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-history.html.twig` | .twig | 1660 | xedc_member | tracked | 2026-10-30 13:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-likes.html.twig` | .twig | 1163 | xedc_member | tracked | 2026-11-02 20:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-membership.html.twig` | .twig | 1373 | xedc_member | tracked | 2026-11-02 15:37 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-overview.html.twig` | .twig | 3470 | xedc_member | tracked | 2026-11-02 20:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-placeholder.html.twig` | .twig | 252 | xedc_member | tracked | 2026-10-30 13:03 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-profile.html.twig` | .twig | 759 | xedc_member | tracked | 2026-10-30 13:03 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-security.html.twig` | .twig | 1226 | xedc_member | tracked | 2026-10-30 13:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-settings.html.twig` | .twig | 1588 | xedc_member | tracked | 2026-10-30 13:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-shell.html.twig` | .twig | 6034 | xedc_member | tracked | 2026-11-02 20:40 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-member-submissions.html.twig` | .twig | 1990 | xedc_member | tracked | 2026-11-02 20:21 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/templates/xedc-public-profile.html.twig` | .twig | 4959 | xedc_member | tracked | 2026-11-02 23:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/xedc_member.info.yml` | .yml | 380 | xedc_member | tracked | 2026-11-02 15:40 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/xedc_member.install` | .install | 15108 | xedc_member | tracked | 2026-10-28 15:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/xedc_member.libraries.yml` | .yml | 239 | xedc_member | tracked | 2026-11-02 20:32 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/xedc_member.module` | .module | 32357 | xedc_member | tracked | 2026-11-02 23:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/xedc_member.routing.yml` | .yml | 4175 | xedc_member | tracked | 2026-11-02 23:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_member/xedc_member.services.yml` | .yml | 2124 | xedc_member | tracked | 2026-11-02 23:09 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/css/models.css` | .css | 4146 | xedc_models | tracked | 2026-10-17 03:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/js/models.js` | .js | 884 | xedc_models | tracked | 2026-10-30 13:10 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/src/Controller/ModelsController.php` | .php | 8857 | xedc_models | tracked | 2026-10-30 18:58 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/templates/xedc-models-detail.html.twig` | .twig | 1176 | xedc_models | tracked | 2026-10-30 13:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/templates/xedc-models-page.html.twig` | .twig | 1034 | xedc_models | tracked | 2026-10-30 13:11 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/xedc_models.info.yml` | .yml | 164 | xedc_models | tracked | 2026-10-17 02:35 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/xedc_models.libraries.yml` | .yml | 143 | xedc_models | tracked | 2026-10-30 13:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/xedc_models.module` | .module | 1301 | xedc_models | tracked | 2026-10-30 07:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_models/xedc_models.routing.yml` | .yml | 576 | xedc_models | tracked | 2026-10-30 07:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_moderation/src/Plugin/Action/SetModerationState.php` | .php | 5222 | xedc_moderation |  M | 2026-11-03 13:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_moderation/src/Plugin/Action/SetModerationStateDeriver.php` | .php | 875 | xedc_moderation | tracked | 2026-10-30 04:50 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_moderation/xedc_moderation.info.yml` | .yml | 279 | xedc_moderation | tracked | 2026-10-30 04:50 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_moderation/xedc_moderation.module` | .module | 437 | xedc_moderation | tracked | 2026-10-30 04:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/css/app.css` | .css | 2651 | xedc_quick |  M | 2026-11-03 13:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/js/app.js` | .js | 15544 | xedc_quick |  M | 2026-11-03 13:54 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/src/Controller/QuickController.php` | .php | 14637 | xedc_quick |  M | 2026-11-03 13:07 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/src/QuickStore.php` | .php | 2752 | xedc_quick | tracked | 2026-10-30 18:56 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/xedc_quick.info.yml` | .yml | 172 | xedc_quick | tracked | 2026-10-17 06:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/xedc_quick.install` | .install | 1169 | xedc_quick | tracked | 2026-10-30 16:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/xedc_quick.libraries.yml` | .yml | 119 | xedc_quick | tracked | 2026-10-30 16:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/xedc_quick.module` | .module | 634 | xedc_quick | tracked | 2026-10-30 18:56 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/xedc_quick.routing.yml` | .yml | 936 | xedc_quick | tracked | 2026-10-30 16:23 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_quick/xedc_quick.services.yml` | .yml | 109 | xedc_quick | tracked | 2026-10-30 18:56 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_scheduler/js/tomorrow.js` | .js | 1119 | xedc_scheduler | tracked | 2026-10-30 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_scheduler/xedc_scheduler.info.yml` | .yml | 311 | xedc_scheduler | tracked | 2026-10-30 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_scheduler/xedc_scheduler.install` | .install | 1918 | xedc_scheduler | tracked | 2026-10-30 15:34 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_scheduler/xedc_scheduler.libraries.yml` | .yml | 115 | xedc_scheduler | tracked | 2026-10-30 15:33 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_scheduler/xedc_scheduler.module` | .module | 11362 | xedc_scheduler | tracked | 2026-10-30 17:16 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/core.entity_form_display.site_audit_report.site_audit_report.default.yml` | .yml | 3934 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/core.entity_view_display.site_audit_report.site_audit_report.default.yml` | .yml | 4189 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_cache_detail.yml` | .yml | 596 | xedc_site_audit | tracked | 2026-10-30 13:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_cache_status.yml` | .yml | 626 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_content_bundles.yml` | .yml | 682 | xedc_site_audit | tracked | 2026-10-30 15:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_image_alt.yml` | .yml | 631 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_image_alt_detail.yml` | .yml | 609 | xedc_site_audit | tracked | 2026-10-30 13:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_meta_detail.yml` | .yml | 596 | xedc_site_audit | tracked | 2026-10-30 13:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_meta_tags.yml` | .yml | 646 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_sitemap.yml` | .yml | 615 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_sitemap_detail.yml` | .yml | 601 | xedc_site_audit | tracked | 2026-10-30 13:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_translation.yml` | .yml | 682 | xedc_site_audit | tracked | 2026-10-30 15:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_translation_detail.yml` | .yml | 614 | xedc_site_audit | tracked | 2026-10-30 13:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_url_alias.yml` | .yml | 638 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.field.site_audit_report.site_audit_report.field_url_alias_detail.yml` | .yml | 603 | xedc_site_audit | tracked | 2026-10-30 13:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_cache_detail.yml` | .yml | 412 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_cache_status.yml` | .yml | 629 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_content_bundles.yml` | .yml | 449 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_image_alt.yml` | .yml | 623 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_image_alt_detail.yml` | .yml | 420 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_meta_detail.yml` | .yml | 410 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_meta_tags.yml` | .yml | 623 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_sitemap.yml` | .yml | 619 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_sitemap_detail.yml` | .yml | 416 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_translation.yml` | .yml | 627 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_translation_detail.yml` | .yml | 424 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_url_alias.yml` | .yml | 623 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/config/install/field.storage.site_audit_report.field_url_alias_detail.yml` | .yml | 420 | xedc_site_audit | tracked | 2026-10-30 13:44 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/AuditChecker.php` | .php | 15233 | xedc_site_audit | tracked | 2026-10-30 15:12 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditCheck/CachePerformance.php` | .php | 554 | xedc_site_audit | tracked | 2026-10-30 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditCheck/ImageAlt.php` | .php | 515 | xedc_site_audit | tracked | 2026-10-30 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditCheck/MetaTags.php` | .php | 541 | xedc_site_audit | tracked | 2026-10-30 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditCheck/Sitemap.php` | .php | 518 | xedc_site_audit | tracked | 2026-10-30 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditCheck/TranslationStatus.php` | .php | 558 | xedc_site_audit | tracked | 2026-10-30 15:08 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditCheck/UrlAlias.php` | .php | 536 | xedc_site_audit | tracked | 2026-10-30 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditCheck/XedcCheckBase.php` | .php | 1387 | xedc_site_audit | tracked | 2026-10-30 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/src/Plugin/SiteAuditChecklist/Seo.php` | .php | 396 | xedc_site_audit | tracked | 2026-10-30 13:43 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/xedc_site_audit.info.yml` | .yml | 391 | xedc_site_audit | tracked | 2026-10-30 13:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/xedc_site_audit.install` | .install | 1707 | xedc_site_audit | tracked | 2026-10-30 14:48 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/xedc_site_audit.module` | .module | 3801 | xedc_site_audit | tracked | 2026-10-30 18:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_site_audit/xedc_site_audit.services.yml` | .yml | 230 | xedc_site_audit | tracked | 2026-10-30 13:42 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/css/xedc-tags.css` | .css | 7146 | xedc_tags | tracked | 2026-10-31 12:40 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/js/xedc-tags.js` | .js | 2480 | xedc_tags | tracked | 2026-10-30 13:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/src/Controller/TagsController.php` | .php | 9390 | xedc_tags | tracked | 2026-10-30 18:57 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/src/Routing/RouteSubscriber.php` | .php | 1233 | xedc_tags | tracked | 2026-10-29 19:39 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/templates/xedc-tags-index.html.twig` | .twig | 1884 | xedc_tags | tracked | 2026-10-30 13:07 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/templates/xedc-tags-term.html.twig` | .twig | 1425 | xedc_tags | tracked | 2026-10-30 13:07 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/xedc_tags.info.yml` | .yml | 183 | xedc_tags | tracked | 2026-10-18 01:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/xedc_tags.libraries.yml` | .yml | 152 | xedc_tags | tracked | 2026-10-30 13:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/xedc_tags.module` | .module | 1116 | xedc_tags | tracked | 2026-10-18 01:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/xedc_tags.routing.yml` | .yml | 192 | xedc_tags | tracked | 2026-10-18 01:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_tags/xedc_tags.services.yml` | .yml | 137 | xedc_tags | tracked | 2026-10-18 01:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/config/install/xedc_ui_locale.settings.yml` | .yml | 63 | xedc_ui_locale | tracked | 2026-11-02 08:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/config/schema/xedc_ui_locale.schema.yml` | .yml | 290 | xedc_ui_locale | tracked | 2026-11-02 07:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/drush.services.yml` | .yml | 210 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/Drush/Commands/UiTranslateCommands.php` | .php | 7229 | xedc_ui_locale | tracked | 2026-11-02 07:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/Form/ManageForm.php` | .php | 11880 | xedc_ui_locale | tracked | 2026-11-02 08:05 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/InterfaceStringExtractor.php` | .php | 9407 | xedc_ui_locale | tracked | 2026-11-02 08:02 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/LocaleTranslationWriter.php` | .php | 2848 | xedc_ui_locale | tracked | 2026-11-02 07:51 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/OfficialPackImporter.php` | .php | 10104 | xedc_ui_locale | tracked | 2026-11-02 08:29 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/Plugin/QueueWorker/UiLocaleQueueWorker.php` | .php | 1445 | xedc_ui_locale | tracked | 2026-11-02 07:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/ProtectedTermGuard.php` | .php | 5429 | xedc_ui_locale | tracked | 2026-11-02 08:26 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/QueueKick.php` | .php | 1299 | xedc_ui_locale | tracked | 2026-11-02 07:52 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/StringCollector.php` | .php | 2183 | xedc_ui_locale | tracked | 2026-11-02 07:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/TranslationTaskRepository.php` | .php | 9542 | xedc_ui_locale | tracked | 2026-11-02 07:55 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/TranslationValidator.php` | .php | 3327 | xedc_ui_locale | tracked | 2026-11-02 07:46 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/UiLocale.php` | .php | 328 | xedc_ui_locale | tracked | 2026-11-02 07:45 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/src/UiTranslationManager.php` | .php | 26013 | xedc_ui_locale | tracked | 2026-11-02 08:04 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample/composer.json` | .json | 35 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample/templates/sample.html.twig` | .twig | 33 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample/ui_sample.info.yml` | .yml | 133 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample/ui_sample.links.menu.yml` | .yml | 106 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample/ui_sample.module` | .module | 438 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample/ui_sample.permissions.yml` | .yml | 134 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample/ui_sample.routing.yml` | .yml | 208 | xedc_ui_locale | tracked | 2026-11-02 07:54 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/manual/ui-locale-self-test.php` | .php | 8414 | xedc_ui_locale | tracked | 2026-11-02 07:58 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/tests/src/Unit/UiLocaleRulesTest.php` | .php | 2913 | xedc_ui_locale | tracked | 2026-11-02 07:57 | 项目自维护模块 | 测试或夹具。生产请求不加载，测试需要。 |
| `web/modules/custom/xedc_ui_locale/xedc_ui_locale.info.yml` | .yml | 339 | xedc_ui_locale | tracked | 2026-11-02 07:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/xedc_ui_locale.install` | .install | 4902 | xedc_ui_locale | tracked | 2026-11-02 07:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/xedc_ui_locale.links.menu.yml` | .yml | 261 | xedc_ui_locale | tracked | 2026-11-02 07:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/xedc_ui_locale.module` | .module | 1177 | xedc_ui_locale | tracked | 2026-11-02 07:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/xedc_ui_locale.permissions.yml` | .yml | 189 | xedc_ui_locale | tracked | 2026-11-02 07:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/xedc_ui_locale.routing.yml` | .yml | 223 | xedc_ui_locale | tracked | 2026-11-02 07:53 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/modules/custom/xedc_ui_locale/xedc_ui_locale.services.yml` | .yml | 969 | xedc_ui_locale | tracked | 2026-11-02 07:59 | 项目自维护模块 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/.browserslistrc` | (none) | 240 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/.env.example` | .example | 623 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/.gitignore` | (none) | 218 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/.npmrc` | (none) | 27 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/.nvmrc` | (none) | 7 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/.stylelintignore` | (none) | 70 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/.stylelintrc.json` | .json | 5020 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/README.mdx` | .mdx | 2860 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/README_DIALOG.mdx` | .mdx | 2342 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/biome.json` | .json | 611 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/build-dev.mjs` | .mjs | 1281 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/build.mjs` | .mjs | 1319 | xedc | tracked | 2026-10-30 08:58 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/build/css/main.style.css` | .css | 193440 | xedc | tracked | 2026-11-02 20:59 | 主题构建产物 | Radix 构建输出，前台 library 会引用 main.style.css / main.script.js。 |
| `web/themes/custom/xedc/build/css/main.style.css.bak-sticky` | .bak-sticky | 362702 | xedc | tracked | 2026-10-18 06:54 | 主题构建产物 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/build/css/main.style.css.bak-xn` | .bak-xn | 362657 | xedc | tracked | 2026-10-18 07:18 | 主题构建产物 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/build/css/main.style.css.map` | .map | 582816 | xedc | tracked | 2026-10-15 10:29 | 主题构建产物 | Radix 构建输出，前台 library 会引用 main.style.css / main.script.js。 |
| `web/themes/custom/xedc/build/js/main.script.js` | .js | 41592 | xedc | tracked | 2026-11-02 20:59 | 主题构建产物 | Radix 构建输出，前台 library 会引用 main.style.css / main.script.js。 |
| `web/themes/custom/xedc/build/js/main.script.js.map` | .map | 399430 | xedc | tracked | 2026-10-15 10:29 | 主题构建产物 | Radix 构建输出，前台 library 会引用 main.style.css / main.script.js。 |
| `web/themes/custom/xedc/components/README.mdx` | .mdx | 780 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_branding.yml` | .yml | 381 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_breadcrumbs.yml` | .yml | 305 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_content.yml` | .yml | 298 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_footer.yml` | .yml | 391 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_local_actions.yml` | .yml | 286 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_main_menu.yml` | .yml | 397 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_messages.yml` | .yml | 304 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/optional/block.block.xedc_page_title.yml` | .yml | 266 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/config/schema/xedc.schema.yml` | .yml | 120 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/article-detail.css` | .css | 8202 | xedc | tracked | 2026-10-31 12:40 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/article-list.css` | .css | 6697 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/auth.css` | .css | 10889 | xedc | tracked | 2026-10-31 13:54 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/auth.css.bak10` | .bak10 | 11336 | xedc | tracked | 2026-10-16 04:29 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/auth.css.bak4` | .bak4 | 9423 | xedc | tracked | 2026-10-15 23:34 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/auth.css.bak5` | .bak5 | 9784 | xedc | tracked | 2026-10-15 23:49 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/auth.css.bak7` | .bak7 | 10783 | xedc | tracked | 2026-10-16 00:18 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/auth.css.bak8` | .bak8 | 11009 | xedc | tracked | 2026-10-16 00:53 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/auth.css.bak9` | .bak9 | 11182 | xedc | tracked | 2026-10-16 01:24 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/comment-form.css` | .css | 5666 | xedc | tracked | 2026-10-29 18:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/engagement.css` | .css | 6913 | xedc | tracked | 2026-11-02 17:31 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/home.css` | .css | 13696 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/inputs.css` | .css | 1528 | xedc | tracked | 2026-10-29 03:37 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/legal.css` | .css | 9785 | xedc | tracked | 2026-10-31 12:41 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/legal.css.bak` | .bak | 8622 | xedc | tracked | 2026-10-19 06:04 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/login-dialog.css` | .css | 2955 | xedc | tracked | 2026-10-31 09:44 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/meitu-card.css` | .css | 3381 | xedc | tracked | 2026-10-30 14:14 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/photo-detail.css` | .css | 24971 | xedc | tracked | 2026-10-31 13:26 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/photo-list.css` | .css | 9779 | xedc | tracked | 2026-10-31 15:03 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/plyr.css` | .css | 32612 | xedc | tracked | 2026-10-20 19:04 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/public-profile.css` | .css | 8760 | xedc | tracked | 2026-11-02 23:17 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/search.css` | .css | 17849 | xedc | tracked | 2026-10-30 12:17 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/sitemap.css` | .css | 1658 | xedc | tracked | 2026-10-31 12:39 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/term-intro.css` | .css | 729 | xedc | tracked | 2026-10-25 12:09 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/tokens.css` | .css | 899 | xedc | tracked | 2026-10-23 14:32 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/usermenu.css` | .css | 635 | xedc | tracked | 2026-10-15 23:24 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/video-card.css` | .css | 3175 | xedc |  M | 2026-11-03 08:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/video-detail.css` | .css | 8579 | xedc |  M | 2026-11-03 08:28 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/video-list.css` | .css | 7087 | xedc |  M | 2026-11-03 08:27 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/xedc-layout.css` | .css | 2728 | xedc | tracked | 2026-10-31 12:37 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/xedc-meta.css` | .css | 3574 | xedc | tracked | 2026-10-25 05:42 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/xedc-meta.css.bak3` | .bak3 | 1836 | xedc | tracked | 2026-10-16 11:13 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/xedc-meta.css.bak4` | .bak4 | 2139 | xedc | tracked | 2026-10-17 04:12 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/xedc-meta.css.bak8` | .bak8 | 2925 | xedc | tracked | 2026-10-17 05:36 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/xedc-navbar.css` | .css | 13809 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/css/xedc-navbar.css.bak-fix` | .bak-fix | 9331 | xedc | tracked | 2026-10-18 07:07 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/xedc-navbar.css.bak-toolbar` | .bak-toolbar | 9331 | xedc | tracked | 2026-10-19 03:52 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/xedc-navbar.css.v1.bak` | .bak | 6295 | xedc | tracked | 2026-10-17 05:08 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/xedc-navbar.css.v2.bak` | .bak | 8854 | xedc | tracked | 2026-10-17 05:23 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/css/xedc-pill.css` | .css | 3710 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/AuthCallbacks.php` | .php | 829 | xedc | tracked | 2026-10-30 08:32 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/auth.inc` | .inc | 15803 | xedc | tracked | 2026-11-02 21:56 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/auth.inc.bak10` | .bak10 | 11316 | xedc | tracked | 2026-10-16 04:29 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/includes/auth.inc.bak4` | .bak4 | 6078 | xedc | tracked | 2026-10-15 23:34 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/includes/auth.inc.bak7` | .bak7 | 6391 | xedc | tracked | 2026-10-16 00:18 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/includes/auth.inc.bak8` | .bak8 | 7455 | xedc | tracked | 2026-10-16 00:53 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/includes/auth.inc.bak9` | .bak9 | 9123 | xedc | tracked | 2026-10-16 01:24 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/includes/block.theme` | .theme | 70 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/comment.theme` | .theme | 72 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/content.theme` | .theme | 69 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/field.theme` | .theme | 70 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/form-element.theme` | .theme | 77 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/form.theme` | .theme | 69 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/home.theme` | .theme | 2637 | xedc | tracked | 2026-10-30 16:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/menu.theme` | .theme | 69 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/theme.theme` | .theme | 47 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/user.theme` | .theme | 69 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/includes/view.theme` | .theme | 69 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/article-detail.js` | .js | 5150 | xedc | tracked | 2026-10-30 08:35 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/auth.js` | .js | 11052 | xedc | tracked | 2026-10-30 08:36 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/auth.js.bak4` | .bak4 | 3940 | xedc | tracked | 2026-10-15 23:34 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/js/auth.js.bak5` | .bak5 | 4580 | xedc | tracked | 2026-10-15 23:49 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/js/auth.js.bak6` | .bak6 | 5272 | xedc | tracked | 2026-10-15 23:54 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/js/auth.js.bak7` | .bak7 | 5639 | xedc | tracked | 2026-10-16 00:18 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/js/auth.js.bak9` | .bak9 | 6965 | xedc | tracked | 2026-10-16 01:24 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/js/comment-form.js` | .js | 8111 | xedc | tracked | 2026-10-30 16:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/engagement.js` | .js | 12282 | xedc | tracked | 2026-11-02 20:45 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/hls.min.js` | .js | 619692 | xedc | tracked | 2026-10-20 19:04 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/home-hero.js` | .js | 8664 | xedc | tracked | 2026-10-30 08:35 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/matomo-lite.js` | .js | 1427 | xedc | tracked | 2026-10-30 17:02 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/photo-detail.js` | .js | 17398 | xedc | tracked | 2026-10-30 08:36 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/photo-list.js` | .js | 6794 | xedc | tracked | 2026-10-31 15:02 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/plyr.min.js` | .js | 120871 | xedc | tracked | 2026-10-20 19:04 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/plyr.svg` | .svg | 5679 | xedc | tracked | 2026-10-20 19:04 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/public-profile.js` | .js | 2094 | xedc | tracked | 2026-11-02 23:08 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/search-page.js` | .js | 1077 | xedc | tracked | 2026-10-25 13:08 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/statistics-lite.js` | .js | 1126 | xedc | tracked | 2026-11-03 04:53 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/term-intro.js` | .js | 792 | xedc | tracked | 2026-10-30 08:35 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/usermenu.js` | .js | 1284 | xedc | tracked | 2026-10-15 23:34 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/usermenu.js.bak4` | .bak4 | 920 | xedc | tracked | 2026-10-15 23:34 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/js/video-detail.js` | .js | 16365 | xedc | tracked | 2026-11-03 06:48 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/xedc-navbar.js` | .js | 4541 | xedc | tracked | 2026-10-30 16:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/js/xedc-navbar.js.v1.bak` | .bak | 2168 | xedc | tracked | 2026-10-17 05:08 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/logo.svg` | .svg | 25698 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/package-lock.json` | .json | 175436 | xedc | tracked | 2026-10-12 08:26 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/package.json` | .json | 1263 | xedc | tracked | 2026-10-12 08:23 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/plugins.mjs` | .mjs | 3518 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/screenshot.png` | .png | 154602 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/assets/fonts/.gitkeep` | (none) | 0 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/assets/icons/.gitkeep` | (none) | 0 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/assets/images/.gitkeep` | (none) | 0 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/assets/videos/.gitkeep` | (none) | 0 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/js/_bootstrap.js` | .js | 338 | xedc | tracked | 2026-10-30 16:21 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/js/main.script.js` | .js | 200 | xedc | tracked | 2026-10-30 16:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/js/main.script.js.bak` | .bak | 7478 | xedc | tracked | 2026-10-15 08:16 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/src/js/main.script.js.bak2` | .bak2 | 6669 | xedc | tracked | 2026-10-15 09:59 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/src/js/main.script.js.bak3` | .bak3 | 7676 | xedc | tracked | 2026-10-15 10:13 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/src/scss/_bootstrap.scss` | .scss | 2102 | xedc | tracked | 2026-10-30 17:02 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/_init.scss` | .scss | 902 | xedc | tracked | 2026-10-12 08:32 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_drupal-overrides.scss` | .scss | 178 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_elements.scss` | .scss | 149 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_functions.scss` | .scss | 119 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_helpers.scss` | .scss | 124 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_home.scss` | .scss | 1479 | xedc | tracked | 2026-10-31 12:40 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_img-detail.scss` | .scss | 45220 | xedc | tracked | 2026-10-29 19:03 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_mixins.scss` | .scss | 116 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_photos.scss` | .scss | 10348 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_photos.scss.bak-sticky` | .bak-sticky | 10379 | xedc | tracked | 2026-10-18 06:54 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/src/scss/base/_shadcn.scss` | .scss | 10071 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_shadcn.scss.bak-xn` | .bak-xn | 10062 | xedc | tracked | 2026-10-18 07:18 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/src/scss/base/_typography.scss` | .scss | 771 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_utilities.scss` | .scss | 647 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/base/_variables.scss` | .scss | 1100 | xedc | tracked | 2026-10-31 12:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/main.style.scss` | .scss | 481 | xedc | tracked | 2026-10-15 03:56 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/member/_member-components.scss` | .scss | 30109 | xedc | tracked | 2026-11-02 21:02 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/member/_member-layout.scss` | .scss | 6326 | xedc | tracked | 2026-11-02 20:59 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/member/_member-public.scss` | .scss | 3803 | xedc | tracked | 2026-11-02 20:36 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/member/_member-responsive.scss` | .scss | 4968 | xedc | tracked | 2026-11-02 20:58 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/member/_member-tokens.scss` | .scss | 2597 | xedc | tracked | 2026-11-02 20:58 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/src/scss/member/member.scss` | .scss | 133 | xedc | tracked | 2026-11-02 20:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/README.mdx` | .mdx | 55 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/block/block--local-tasks-block.html.twig` | .twig | 170 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/block/block--system-branding-block.html.twig` | .twig | 981 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/block/block--system-main-block.html.twig` | .twig | 1362 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/block/block--system-menu-block.html.twig` | .twig | 1809 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/block/block.html.twig` | .twig | 1285 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/comment/comment.html.twig` | .twig | 814 | xedc | tracked | 2026-11-02 15:41 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/comment/comment.html.twig.bak` | .bak | 3504 | xedc | tracked | 2026-10-15 06:42 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/comment/comment.html.twig.bak2` | .bak2 | 567 | xedc | tracked | 2026-10-15 08:36 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/comment/comment.html.twig.bak3` | .bak3 | 685 | xedc | tracked | 2026-10-15 10:13 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/media.html.twig` | .twig | 244 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig` | .twig | 6521 | xedc |  M | 2026-11-03 12:26 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak` | .bak | 1785 | xedc | tracked | 2026-10-16 10:42 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak2` | .bak2 | 1698 | xedc | tracked | 2026-10-17 04:08 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak3` | .bak3 | 1242 | xedc | tracked | 2026-10-15 09:20 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak4` | .bak4 | 1280 | xedc | tracked | 2026-10-15 09:42 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak5` | .bak5 | 1281 | xedc | tracked | 2026-10-15 09:46 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak6` | .bak6 | 1776 | xedc | tracked | 2026-10-15 09:56 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak7` | .bak7 | 1535 | xedc | tracked | 2026-10-17 05:33 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/content/node--video--teaser.html.twig` | .twig | 183 | xedc | tracked | 2026-11-02 21:04 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/content/node.html.twig` | .twig | 3676 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/content/page-title.html.twig` | .twig | 538 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/dataset/item-list.html.twig` | .twig | 1487 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/dataset/table.html.twig` | .twig | 2042 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/field/field--comment.html.twig` | .twig | 1148 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/field/field.html.twig` | .twig | 1508 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/field/filter-caption.html.twig` | .twig | 540 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/field/image.html.twig` | .twig | 332 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/field/time.html.twig` | .twig | 1145 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/details.html.twig` | .twig | 660 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/fieldset.html.twig` | .twig | 1098 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/form--search-block-form.html.twig` | .twig | 304 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/form-element--checkbox.html.twig` | .twig | 2529 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/form-element--radio.html.twig` | .twig | 2529 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/form-element-label.html.twig` | .twig | 448 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/form-element.html.twig` | .twig | 2638 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/form.html.twig` | .twig | 269 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/input--checkbox.html.twig` | .twig | 382 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/input--radio.html.twig` | .twig | 382 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/input--submit.html.twig` | .twig | 435 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/input.html.twig` | .twig | 290 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/radios.html.twig` | .twig | 275 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/select.html.twig` | .twig | 259 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/form/textarea.html.twig` | .twig | 492 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/includes/home-footer.html.twig` | .twig | 419 | xedc | tracked | 2026-10-30 12:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/includes/home-sections.html.twig` | .twig | 4942 | xedc | tracked | 2026-10-30 17:02 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/includes/home-sections.html.twig.bak3` | .bak3 | 1550 | xedc | tracked | 2026-10-14 08:23 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/includes/meitu-card.html.twig` | .twig | 1514 | xedc | tracked | 2026-10-30 08:32 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/includes/photo-all-sheet.html.twig` | .twig | 1049 | xedc | tracked | 2026-10-30 08:32 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/includes/search-card.html.twig` | .twig | 1691 | xedc | tracked | 2026-10-30 08:32 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/includes/xedc-header.html.twig` | .twig | 7584 | xedc | tracked | 2026-10-30 14:48 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/includes/xedc-header.html.twig.v1.bak` | .bak | 5048 | xedc | tracked | 2026-10-17 05:08 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/menu/menu--account.html.twig` | .twig | 141 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/menu/menu--main.html.twig` | .twig | 823 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/menu/menu-local-task.html.twig` | .twig | 420 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/menu/menu-local-tasks.html.twig` | .twig | 387 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/menu/menu.html.twig` | .twig | 749 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/misc/progress-bar.html.twig` | .twig | 723 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/misc/status-messages.html.twig` | .twig | 1469 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/navigation/breadcrumb.html.twig` | .twig | 195 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/navigation/links--dropbutton.html.twig` | .twig | 2109 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/navigation/links.html.twig` | .twig | 2063 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/navigation/menu-local-action.html.twig` | .twig | 284 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/navigation/pager.html.twig` | .twig | 1469 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/node/node--article--full.html.twig` | .twig | 3850 | xedc | tracked | 2026-11-02 15:41 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/node/node--article--teaser.html.twig` | .twig | 1021 | xedc | tracked | 2026-10-30 08:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/node/node--video--full.html.twig` | .twig | 4798 | xedc |  M | 2026-11-03 08:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/node/xedc-meta.html.twig` | .twig | 3672 | xedc | tracked | 2026-10-30 08:34 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/node/xedc-meta.html.twig.bak` | .bak | 1851 | xedc | tracked | 2026-10-17 04:12 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/page/html-sitemap.html.twig` | .twig | 586 | xedc | tracked | 2026-10-30 08:57 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/page/page--front.html.twig` | .twig | 361 | xedc | tracked | 2026-10-17 04:53 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/page/page--front.html.twig.bak` | .bak | 4781 | xedc | tracked | 2026-10-15 23:24 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/page/page--front.html.twig.bak2` | .bak2 | 4123 | xedc | tracked | 2026-10-13 03:20 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/page/page--front.html.twig.bak3` | .bak3 | 4348 | xedc | tracked | 2026-10-15 23:24 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/page/page--xedc-embed.html.twig` | .twig | 124 | xedc | tracked | 2026-10-16 00:18 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/page/page.html.twig` | .twig | 654 | xedc | tracked | 2026-10-31 12:37 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/page/page.html.twig.bak` | .bak | 4574 | xedc | tracked | 2026-10-15 23:24 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/page/page.html.twig.bak3` | .bak3 | 4141 | xedc | tracked | 2026-10-15 23:24 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/page/page.html.twig.bak4` | .bak4 | 154 | xedc | tracked | 2026-10-19 05:46 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/templates/region/region.html.twig` | .twig | 366 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/search/search-page.html.twig` | .twig | 4723 | xedc | tracked | 2026-10-30 13:51 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/search/search-result.html.twig` | .twig | 605 | xedc | tracked | 2026-10-30 13:46 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/system/container.html.twig` | .twig | 927 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/system/html.html.twig` | .twig | 986 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/taxonomy/taxonomy-term--video-category.html.twig` | .twig | 369 | xedc |  M | 2026-11-03 07:12 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/taxonomy/taxonomy-term.html.twig` | .twig | 896 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/user/user.html.twig` | .twig | 601 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/video/video-card.html.twig` | .twig | 1155 | xedc |  M | 2026-11-03 08:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/video/video-member.html.twig` | .twig | 1124 | xedc | ?? | 2026-11-03 08:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/video/video-toolbar.html.twig` | .twig | 1127 | xedc |  M | 2026-11-03 07:25 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/video/xedc-video-tag.html.twig` | .twig | 1110 | xedc |  M | 2026-11-03 07:26 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-mini-pager.html.twig` | .twig | 346 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view--article-list.html.twig` | .twig | 1984 | xedc | tracked | 2026-10-30 12:16 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view--image-gallery.html.twig` | .twig | 5782 | xedc | tracked | 2026-10-30 08:32 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view--taxonomy-term--page-1.html.twig` | .twig | 490 | xedc | tracked | 2026-11-02 21:04 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view--video-list.html.twig` | .twig | 2245 | xedc |  M | 2026-11-03 08:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view-fields--video-list.html.twig` | .twig | 790 | xedc | tracked | 2026-10-29 19:43 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view-grid.html.twig` | .twig | 1026 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view-table.html.twig` | .twig | 1540 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view-unformatted.html.twig` | .twig | 515 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/views-view.html.twig` | .twig | 1327 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/templates/views/xa-pager.html.twig` | .twig | 833 | xedc | tracked | 2026-10-30 08:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/translations/README.mdx` | .mdx | 832 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/translations/en.po` | .po | 29323 | xedc | tracked | 2026-10-30 14:16 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/translations/ja.po` | .po | 33700 | xedc | tracked | 2026-10-30 14:16 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/translations/nb.po` | .po | 43 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/translations/ru.po` | .po | 39209 | xedc | tracked | 2026-10-30 14:17 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/translations/xedc.pot` | .pot | 6403 | xedc | tracked | 2026-10-30 08:57 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/watch.mjs` | .mjs | 8678 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xedc.breakpoints.yml` | .yml | 698 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xedc.icons.yml` | .yml | 1240 | xedc | tracked | 2026-09-17 13:33 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xedc.info.yml` | .yml | 1050 | xedc | tracked | 2026-10-30 17:05 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xedc.libraries.yml` | .yml | 2946 | xedc |  M | 2026-11-03 08:28 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xedc.libraries.yml.bak` | .bak | 155 | xedc | tracked | 2026-10-13 03:05 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.search.inc` | .inc | 22494 | xedc | tracked | 2026-10-30 18:57 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xedc.theme` | .theme | 116262 | xedc |  M | 2026-11-03 12:26 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xedc.theme.bak` | .bak | 9895 | xedc | tracked | 2026-10-16 10:36 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak.1792133742` | .1792133742 | 5958 | xedc | tracked | 2026-10-16 06:55 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak.1792134857` | .1792134857 | 8829 | xedc | tracked | 2026-10-16 07:14 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak.1792135877` | .1792135877 | 9133 | xedc | tracked | 2026-10-16 07:31 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak.1792136460` | .1792136460 | 10203 | xedc | tracked | 2026-10-16 07:41 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak.1792137133` | .1792137133 | 5958 | xedc | tracked | 2026-10-16 07:52 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak.1792141706` | .1792141706 | 8993 | xedc | tracked | 2026-10-16 09:08 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak.1792142272` | .1792142272 | 9926 | xedc | tracked | 2026-10-16 09:17 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak2` | .bak2 | 11136 | xedc | tracked | 2026-10-16 10:53 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak3` | .bak3 | 11700 | xedc | tracked | 2026-10-17 04:08 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak4` | .bak4 | 4014 | xedc | tracked | 2026-10-15 08:55 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.bak5` | .bak5 | 5282 | xedc | tracked | 2026-10-15 09:46 | 项目自维护主题 | 备份副本，运行时不加载。本阶段不删除。 |
| `web/themes/custom/xedc/xedc.theme.broken` | .broken | 10749 | xedc | tracked | 2026-10-16 07:44 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc/xsl/sitemap.xsl` | .xsl | 3913 | xedc | tracked | 2026-10-30 09:52 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_breadcrumbs.yml` | .yml | 328 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_content.yml` | .yml | 317 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_help.yml` | .yml | 280 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_local_actions.yml` | .yml | 306 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_messages.yml` | .yml | 326 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_page_title.yml` | .yml | 287 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_primary_local_tasks.yml` | .yml | 334 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/config/optional/block.block.xedc_admin_secondary_local_tasks.yml` | .yml | 338 | xedc_admin | tracked | 2026-11-01 12:22 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/admin-ui.css` | .css | 48147 | xedc_admin | tracked | 2026-11-02 16:51 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/base.css` | .css | 5147 | xedc_admin | tracked | 2026-11-01 22:02 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/ckeditor.css` | .css | 317 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/components.css` | .css | 11259 | xedc_admin | tracked | 2026-11-02 05:36 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/content.css` | .css | 3431 | xedc_admin | tracked | 2026-11-01 22:02 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/dashboard.css` | .css | 2858 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/dialog.css` | .css | 22972 | xedc_admin | tracked | 2026-11-02 13:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/editor.css` | .css | 1996 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/engagement.css` | .css | 3576 | xedc_admin | tracked | 2026-11-02 20:28 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/forms.css` | .css | 7804 | xedc_admin | tracked | 2026-11-02 10:48 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/layout.css` | .css | 10442 | xedc_admin | tracked | 2026-11-02 15:38 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/node-form.css` | .css | 24802 | xedc_admin | tracked | 2026-11-02 15:34 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/progress.css` | .css | 6026 | xedc_admin | tracked | 2026-11-02 10:26 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/css/responsive.css` | .css | 1459 | xedc_admin | tracked | 2026-11-01 20:54 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/includes/navigation.theme` | .theme | 29746 | xedc_admin | tracked | 2026-11-02 20:28 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/js/admin.js` | .js | 20292 | xedc_admin | tracked | 2026-11-02 15:29 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/js/navigation.js` | .js | 14416 | xedc_admin | tracked | 2026-11-02 10:42 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/admin/admin-block-content.html.twig` | .twig | 850 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/admin/admin-block.html.twig` | .twig | 421 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/admin/admin-page.html.twig` | .twig | 282 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/admin/system-admin-index.html.twig` | .twig | 258 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/block/block--local-actions-block.html.twig` | .twig | 227 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/dataset/table.html.twig` | .twig | 1738 | xedc_admin | tracked | 2026-11-01 12:20 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/form/input--submit.html.twig` | .twig | 283 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/form/node-form.html.twig` | .twig | 3566 | xedc_admin | tracked | 2026-11-02 09:50 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/misc/status-messages.html.twig` | .twig | 1004 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/navigation/breadcrumb.html.twig` | .twig | 528 | xedc_admin | tracked | 2026-11-01 12:20 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/navigation/links--dropbutton.html.twig` | .twig | 936 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/navigation/links.html.twig` | .twig | 723 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/navigation/menu-local-action.html.twig` | .twig | 155 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/navigation/menu-local-task.html.twig` | .twig | 165 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/navigation/menu-local-tasks.html.twig` | .twig | 523 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/node/node-add-list.html.twig` | .twig | 2405 | xedc_admin | tracked | 2026-11-02 09:47 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/page/page.html.twig` | .twig | 9553 | xedc_admin | tracked | 2026-11-01 22:27 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/system/html.html.twig` | .twig | 1237 | xedc_admin | tracked | 2026-11-01 22:00 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/system/system-modules-details.html.twig` | .twig | 2737 | xedc_admin | tracked | 2026-11-01 16:01 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/views/views-view-table.html.twig` | .twig | 3082 | xedc_admin | tracked | 2026-11-01 12:20 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/templates/xedc-admin-dashboard.html.twig` | .twig | 8757 | xedc_admin | tracked | 2026-11-01 12:20 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/xedc_admin.info.yml` | .yml | 677 | xedc_admin | tracked | 2026-11-01 20:11 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/xedc_admin.libraries.yml` | .yml | 880 | xedc_admin | tracked | 2026-11-02 20:28 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |
| `web/themes/custom/xedc_admin/xedc_admin.theme` | .theme | 10978 | xedc_admin | tracked | 2026-11-02 16:08 | 项目自维护主题 | 项目维护文件。见依赖报告，不因无直接 grep 命中而删除。 |

## 4. 明确不能删除的范围

- `vendor/`、`web/core/`、`web/modules/contrib/`、`web/themes/contrib/`
- `web/themes/custom/xedc/node_modules/`
- `web/themes/custom/xedc/build/css/main.style.css` 与 `build/js/main.script.js`（`xedc.libraries.yml` 的 `xedc/style` 在引用）
- `web/sites/default/files` 里的用户图片、`styles/` 衍生图、私有文件、R2 上的对象
- `private/xedc-import`、`private/xedc-zips`
- `web/modules/custom/xedc_core/assets/phpqrcode.php`（`src/Engagement/QrImage.php` 第 19 行 `require_once`）
- 全部启用中的自定义模块和 contrib 模块
- `config/sync` 与数据库中的活动配置

备份文件（文件名含 `.bak`）是清理候选，但本阶段不删除。`web/sites/default/settings.php.bak-2026-11-01-1537` 同时是安全问题，见主报告 SEC-1。

