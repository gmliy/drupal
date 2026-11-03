# XEDC 依赖与业务功能图

扫描时间：2026-11-03T15:22:04Z 至 2026-11-03T15:29:23Z。只读。

本图区分三种“在用”：

1. 容器或插件注册：服务、路由、Attribute、注解、hook。
2. 配置或数据库引用：Views、state、自定义表。
3. 这次审计里实际打开过页面。除登录页和若干被维护模式拦住的 URL 外，业务页面点击是 `NOT_TESTED`。

类扫描范围是自定义模块和自定义主题的 PHP，不含 contrib 与 core 的内部分类。自定义服务 ID 139 个。下面按功能列出入口，不把 139 个服务重复粘贴一遍。存储相关服务集中在对象存储一节。

## 注册机制复查

第一轮按“类名是否出现在其他文件”会把插件算成未使用。这些类靠 Drupal 发现，状态是 `CONFIRMED_USED`（注册）而页面点击 `NOT_TESTED`：

| 类 | 机制 | 位置 |
| --- | --- | --- |
| `ImageImportWorker`、`ImageOptimizeWorker`、`FileScanWorker`、`FileCleanupWorker`、`VideoTranscodeWorker`、`VideoDurationWorker`、`DownloadZipWorker` | `#[QueueWorker]` | `xedc_core/src/Plugin/QueueWorker/` |
| `AiReviewWorker`、`LocalScanWorker` | `#[QueueWorker]` | `xedc_ai/src/Plugin/QueueWorker/` |
| `UiLocaleQueueWorker` | `#[QueueWorker]` | `xedc_ui_locale` |
| `GalleryStatus` | `#[ViewsField('xedc_gallery_status')]`，并由 `xedc_core_views_pre_view()` 往审核视图上补 handler（`xedc_core.module` 785–804 行） | |
| `GalleryState` | `#[ViewsFilter('xedc_gallery_state')]` | 同文件 799 行附近 |
| `ContentEntityOperations` | `hook_views_plugins_field_alter()` 826–829 行替换 `entity_operations` | 不是死类 |
| `PhotoCategoriesBlock` | `#[Block(id: 'xedc_home_photo_categories')]`，`xedc.theme` 298 行创建实例 | |
| `BundleTag` | `#[ViewsCache(id: 'xedc_bundle_tag')]`，五个 Views 配置使用 | `config/sync/views.view.*.yml` |
| `SetModerationState` | `#[Action]` | `xedc_moderation`。动作插件是否出现在某个 Views 的已保存动作里：`NOT_TESTED` |
| `ImageAlt`、`UrlAlias`、`TranslationStatus`、`CachePerformance`、`MetaTags` 与 `Seo` checklist | `@SiteAuditCheck` / checklist 注解 | `xedc_site_audit/src/Plugin/` |
| `DownloadResource` | `#[ContentEntityType]` | `xedc_core/src/Entity/DownloadResource.php` 第 25 行 |
| `FrontNavHooks` | `#[Hook('theme')]`、`preprocess_html`、`preprocess_page` | `xedc_admin_nav` |
| Drush 命令类 | `#[CLI\Command]` | `xedc:import-run`、`xedc:translate`、`xedc:ui-translate`、`xedc:traffic-sample`、`xedc:video-duration-*` 等 |

`phpqrcode.php` 里的 `QRcode`、`QRinput` 等类只在该文件内互相调用，入口是 `QrImage.php` 19–20 行。状态 `CONFIRMED_USED`。

确认没有注册的类只有 `ModerationGuard`、`StorageManager`、`StorageSwitchboard`。见主报告 CODE-1、CODE-2。

## 前台

### 首页与推荐

- 模块：`xedc_home`、`xedc` 主题、Views、node
- 配置：`system.site` `page.front=/node`；视图 `frontpage` 路径 `node`；视图 `xedc_home` 使用缓存插件 `xedc_bundle_tag`
- 主题：`xedc.theme` 的 `xedc_preprocess_page()`、`includes/home.theme` 的 `_xedc_home_items()`、模板 `templates/includes/home-sections.html.twig`
- CSS/JS：全局 `xedc/style`；首页另有 `css/home.css`、`js/home-hero.js`（库是否在首页模板附加：代码里有文件，页面网络请求 `NOT_TESTED`）
- 服务：`xedc_home` 的 `HomeCards`（`src/HomeCards.php` 有聚合 SQL）
- 数据：`node_field_data`、媒体字段、`node_counter`（statistics）
- 调用链：路由 `/` → 站点首页 `/node` → Views `frontpage` → 主题 preprocess → Twig。维护模式下匿名请求在控制器渲染前被 503 打断，后半段 `NOT_TESTED`
- 第三方：Radix、Bootstrap 构建进 `main.style.css`，metatag，simple_sitemap

### 图片列表和详情

- 视图 `image_gallery` 路径 `meitu` 和 `meitu2`，缓存 `xedc_bundle_tag`
- 主题建议模板：`templates/views/views-view--image-gallery.html.twig`，`xedc_preprocess_views_view__image_gallery()` 在 `xedc.theme` 1842 行
- 详情：`templates/content/node--photo--full.html.twig`，`_xedc_photo_gallery_items()` 1010 行，`_xedc_photo_related_cards()` 1266 行
- 列表预热：`xedc_views_pre_render()` 1195 行调用 `_xedc_warm_view_nodes()`，用来减少卡片上的逐条实体加载。这是已存在的防 N+1 措施，本次没有用查询日志验证命中次数
- 库：`css/photo-list.css`、`js/photo-list.js`、`css/photo-detail.css`、`js/photo-detail.js`
- 图片样式：`photo_card_640`、`photo_gallery_650`、`photo_gallery_1300`
- 页面实测：`NOT_TESTED`（维护模式）

### 视频列表和详情

- 视图 `video_list` 路径 `video`
- 路由 `/video/tag/{taxonomy_term}` → `VideoTagController::page`
- 路由 `/video/progress/{node}` 两个 route id：`video_progress_read` 与 `video_progress_save`（POST，要求登录和 CSRF 头）
- 模板：`templates/node/node--video--full.html.twig`、`templates/video/video-card.html.twig`、`video-toolbar.html.twig`、`xedc-video-tag.html.twig`
- 库：`xedc/video_list`、`xedc/video-card`、`xedc/video-detail`、`xedc/video-player`（hls + plyr）
- 服务：`xedc_core.video_duration`，队列 `VideoDurationWorker`、`VideoTranscodeWorker`
- 表：`xedc_video_transcode`，state `xedc_video.codec.*`、`xedc_video.failures`
- 暗色规则写在 `video-list.css`、`video-detail.css`、`video-card.css`，选择器同时覆盖 `[data-bs-theme=dark]` 和 `[data-theme=dark]`
- 页面实测：`NOT_TESTED`

### 文章列表和详情

- 视图 `article_list` 路径 `articles`，缓存 `xedc_bundle_tag`
- 库：`xedc/article-list`、`xedc/article-detail`，都依赖 `css/tokens.css`
- SEO：`xedc_home_metatags_alter()` 与 `_xedc_home_article_schema()`（`xedc_home.module` 193、251 行）
- 模板与字段渲染：`NOT_TESTED` 哪一个 Twig 最终胜出。主题里存在 article CSS，具体 suggestion 依赖 view mode

### 分类、标签、模特

- 分类页走视图 `taxonomy_term`，路径 `taxonomy/term/%`，缓存 `xedc_bundle_tag`
- 图片分类路径还出现在 `image_gallery` 的 display path `taxonomy/term/%`
- 标签：`xedc_tags` 路由 `/tags` → `TagsController::index`。服务 1 个。库 `xedc_tags`。模板侧有 `xedc.theme` 对 taxonomy 的 preprocess，以及 `xedc_core_preprocess_taxonomy_term()`（896 行）里对视频分类做 `entityQuery` 计数
- 模特：`xedc_models` 路由 `/models`、`/models/{taxonomy_term}`。`xedc_models_views_query_alter()`。库 `js/models.js` 只有 input 监听
- 站点地图改写：`xedc_home` 与 `xedc_site_audit` 各有一套，见主报告 DUP-1
- 页面实测：`NOT_TESTED`。`/tags`、`/models` 的匿名请求返回维护 503，不能用来判断控制器输出

### 搜索

- 别名 `/search` → `/search/node`，模块 `search`、`search_node`
- 主题库 `xedc/search-page`：`css/search.css`、`js/search-page.js`，模板 `templates/search/search-page.html.twig`
- 表 `search_index` 约 2.94MB、14079 行；`search_dataset` 0.33MB
- 页面实测：`NOT_TESTED`

### 会员公开页与会员中心

- 公开：`/r/{slug}` → `MemberController::publicProfile`。模板 `themes/custom/xedc/templates/video/video-member.html.twig` 与 `css/public-profile.css`（含暗色规则）
- 中心：`/member`、`/member/comments`、`/member/downloads`、`/member/favorites`、`/member/history`、`/member/likes`、`/member/membership`、`/member/profile`、`/member/security`、`/member/settings`、`/member/submissions`
- `/member/history/clear` 是 `HistoryClearForm`，不是 comments 控制器
- 别名检查：`/xedc-member/alias-check`、`/xedc-member/alias-denied`
- 服务：`xedc_member` 9 个，含 `MemberAliasManager`（`MemberAliasManager.php` 442 行查 `router` 与 `path_alias`）
- Hook：`xedc_member.module` 中的登录表单、节点表单、`hook_cron`、`hook_entity_delete`、`hook_views_query_alter`、媒体库限制
- 表：`xedc_member_favorite`、`xedc_member_history`、`xedc_vip`（0 行）、`xedc_vip_log`（0 行）
- JS：`xedc_member/js/member.js` 在 document 上注册 click、submit、mousedown、keydown。使用 `once` 与否要看调用点；文件头部没有包进 `Drupal.behaviors` 的全部逻辑都走 `DOMContentLoaded`。重复绑定风险限于该文件被附件多次的情况，本次 `NOT_TESTED`
- 访问日志有 `GET /member/profile` 的 500 一次。没有对应的 PHP 栈，原因 `NOT_TESTED`

### 登录和注册

- 核心 `user`，contrib `user_registrationpassword` 2.0.4
- 主题：`xedc/xedc_auth`、`xedc/xedc_auth_form`，`includes/auth.inc` 第 42 行把 `#pre_render` 指到 `Drupal\xedc\AuthCallbacks::messages`
- 实测登录页：200，约 205ms，18845 字节，动态缓存 `UNCACHEABLE (poor cacheability)`，页面缓存 MISS
- 注册页、重置密码页：`NOT_TESTED`

### 收藏、点赞、下载、分享

- 路由：`/xedc/like/{node}`、`/xedc/like-token`、`/xedc/share/{node}`、`/xedc/share/{node}/qr`、`/xedc/download/{xedc_download}` 及 original/archive
- 控制器：`EngagementController`、`DownloadDeliveryController`
- 实体：`DownloadResource`
- 表：`xedc_like` 6 行，`xedc_share` 24 行，`xedc_download` 1 行，`xedc_download_log` 7 行，`xedc_member_favorite` 6 行，`xedc_zip_job` 5 行
- 队列：`DownloadZipWorker`
- JS：`themes/custom/xedc/js/engagement.js`，库 `xedc/engagement` 依赖 `core/once` 与 `drupalSettings`
- 二维码：`QrImage` → `phpqrcode.php`
- 后台只读页：`/admin/xedc/engagement/*`、`/admin/xedc/vip`。访问日志里 `/admin/xedc/vip` 有 3 次 500，栈未采集，`NOT_TESTED`
- 前台点击：`NOT_TESTED`

### 图片 CDN、优化、R2

- 运行时文件：`xedc_storage.runtime.php` 由 `settings.php` 983–986 行包含。FPM 环境有 `STORAGE_CDN_ENABLED=1`、`STORAGE_PUBLIC_TAKEOVER=1`、`STORAGE_PRIVATE_TAKEOVER=0`、`XEDC_STORAGE_RUNTIME=1`（来自 php-fpm 启动日志里的 `env[]`，不是从密钥文件抄出来的口令）
- URL 改写：`xedc_core_file_url_alter()` 917–921 行调用 `PublicUrl::alter()`
- 服务（均在 `xedc_core.services.yml`）：`storage_config`、`storage_uris`、`storage_temp`、`storage_reader`、`storage_writer`、`storage_guard`、`storage_files`、`storage_delivery`、`storage_readiness`（`TakeoverReadiness`）、`storage_cdn_inspector`、`storage_cdn_status`、`storage_media_pipeline`、`storage_variants`，以及同文件后部的 switch / journal / credentials（文件继续到 354 行以后）
- 优化：`xedc_core.image_optimizer`、`image_optimize_repository`、`image_optimize`（`ImageOptimizeApplier`）、`optimizing_file_repository` 装饰 `file.repository`
- 路由：`/admin/config/media/image-optimization` → `ImageOptimizeForm`；对象存储一组 `/admin/config/media/object-storage/*` → `StorageController`
- 表：`xedc_image_file` 452 行，`xedc_image_optimize` 243 行，`xedc_image_alt` 438 行，`xedc_storage_variant` 405 行，`xedc_storage_migration_item` 634 行
- 队列：`ImageOptimizeWorker`
- 第三方：`drupal/s3fs` 3.12.0，`aws/aws-sdk-php`
- `file_managed` 里没有 `s3://` 行（本次 SQL 只按 scheme 聚合到 public/private）。接管之后新文件是否仍写 `public://` 再由运行时包装器转到 R2：代码路径存在，对象是否已在桶里 `NOT_TESTED`
- 未接线的门面：`StorageManager`、`StorageSwitchboard`（CODE-2）。在用的切换类是 `StorageSwitchManager`

## 后台

### 管理主题

- 活动管理主题 `xedc_admin`，基主题 Radix，不是 Gin
- Gin 5.0.16 已安装并启用，`gin_toolbar` 未启用（CODE-5）
- `xedc_admin.theme` 提供 `preprocess_html`、`preprocess_page`、表单 alter、导航 include `includes/navigation.theme`
- `xedc_admin_ui` 路由 `/admin/xedc` → `DashboardController::page`，并有 `hook_page_attachments`、`hook_css_alter`
- `xedc_admin_nav` 用 PHP Attribute 注册 theme 与 preprocess
- 核心 `navigation`、`toolbar` 也启用

### 内容、投稿、审核

- Views：`content` 路径 `admin/content/node`，`moderated_content` 路径 `admin/content/moderated`
- 工作流：`xedc_editorial` 与核心 `editorial`
- 会员投稿表单改动在 `xedc_member_form_node_form_alter()`
- 编辑保存：`xedc_home_node_presave()`、`xedc_core_node_presave()`、`xedc_member_node_presave()` 都会跑。这是钩子链，不是重复函数
- 定时发布：`scheduler` 2.3.0 + `xedc_scheduler`（`hook_scheduler_publish_process`、`hook_entity_presave`）
- `SetModerationState` 动作插件已注册。`ModerationGuard` 没有注册
- 审核列表的画廊列由 `xedc_gallery_status` 动态补上

### 文件资产、重复、引用、未使用、导入、ZIP、优化

- 统计页：`/admin/reports/xedc-files` → `FileStatsController`
- 分析状态、重复、引用、未使用、清理任务：`FileCleanupController` 与 `CleanupTaskForm`
- 服务链：`file_analysis` → `duplicate_scanner`、`reference_analyzer`、`unused_scanner`、`cleanup_manager`、`quarantine_manager`、`reference_merger`
- 表：`xedc_file_batch`（16 个批次，状态都是 `done`）、`xedc_file_dup_group`、`xedc_file_dup_member`、`xedc_file_ref`、`xedc_file_finding`、`xedc_file_hash_work`、`xedc_file_cleanup_*`
- 导入 UI：`/admin/content/image-imports` → `ImportListForm`；详情 `ImportController::detail`；进度 `ImportController::progress`
- 上传：`AssetUploadController::image|archive|draft|close`
- 处理：`xedc_core.processor`（`ImportProcessor`）、`xedc_core.archive`、`xedc_core.images`、`xedc_core.image_batch`、队列 `ImageImportWorker`
- Drush：`xedc:import-run`、`xedc:import-work`、`xedc:import-status`
- systemd：`xedc-import-worker.service` 处于 running
- 快速画廊：`xedc_quick` 路由 `/admin/quick-gallery`、`upload`、`create`、`suggest`。表 `xedc_quick_orig`、`xedc_quick_hash`
- ZIP：`ArchiveManager`、`ZipBuilder`、`xedc_zip_job`、队列 `DownloadZipWorker`
- 已知故障：BUG-1、BUG-2、OPS-4。见主报告

### SEO、Site Audit、Matomo

- SEO 模块：`metatag` 及 open graph、twitter、views、verification、routes；`schema_metatag` 的 article、image、organization、video、web_page、web_site；`simple_sitemap` 及 engines、views；`pathauto`；`easy_breadcrumb`；`redirect` 及 404、domain
- 自定义：`xedc_home_metatags_alter`、`xedc_home_simple_sitemap_links_alter`、`xedc_site_audit_simple_sitemap_links_alter`、主题 `xedc_metatags_alter()`（`xedc.theme` 112 行）
- Site Audit：`site_audit` 4.1.3 与 `xedc_site_audit` 插件。下载路由在 `xedc_audit`：`/admin/reports/site-audit/download/{format}`
- 另一套：`audit` 1.1.0 的十几个子模块，全部启用。`xedc_audit` 的 Drush 用 `#[Hook(REPLACE_COMMAND_HOOK)]` 替换 `audit:run`，服务注入 `audit.runner`
- Matomo：模块启用，存在 `matomo.settings`。主题 `xedc.info.yml` 25–26 行把 `matomo/matomo` 的 JS 换成 `js/matomo-lite.js`。统计模块同样换成 `js/statistics-lite.js`
- 这些后台页本次没有登录打开。`NOT_TESTED`

### 自动翻译与界面翻译

- `xedc_auto_translate`：`hook_entity_insert` / `hook_entity_update`，路由设置表单，Drush `xedc:translate`、`xedc:translate-missing`
- 密钥在 state，见 SEC-2。`key` 模块已启用，但这把密钥没有放进 Key 的证据（没有读 key 配置正文，避免抄录秘密）
- `xedc_ui_locale`：路由管理表单，Drush `xedc:ui-translate` 等，队列 `UiLocaleQueueWorker`
- 表：`xedc_ui_locale_item` 552 行，`xedc_ui_locale_job` 7 行，`xedc_ui_locale_log` 4 行
- 核心 `locale`：`locales_target` 7.02MB / 42991 行，`locales_source` 4.03MB / 17740 行。慢查询日志里有语言表查询，见性能报告
- 测试夹具 `ui_sample` 的路由文件在磁盘上，模块未启用，不会注册到路由器

### AI 审核

- 模块 `xedc_ai`：21 个服务，19 条路由，队列两个 worker，`hook_cron`、`hook_node_insert`、节点表单 alter
- 页面：`/admin/xedc/ai` 总览、reviews、duplicates、logs、models、providers、rules、seo、submissions、translate、usage
- 表：`xedc_ai_review` 2 行，`xedc_ai_audit` 29 行，`xedc_ai_usage` 12 行，`xedc_ai_provider` 1 行，`xedc_ai_model` 1 行
- state：`xedc_ai.paused = 0`
- 外部模型 HTTP 是否在 cron 中被调用：代码有 cron 钩子，本次没有尾随日志，`NOT_TESTED`

### 监控、文件统计、Cron、Queue、Batch

- 监控：`/admin/reports/xedc-monitor` 与 `/data`。服务 `xedc_core.monitor`、`xedc_core.netdata`、`xedc_core.traffic`
- JS `monitor.js` 默认 3 秒，隐藏标签页时至少 30 秒（195–196 行）
- Netdata 服务在主机上 active
- 流量表：`xedc_traffic_sample` 1276 行，`xedc_traffic_day` 1 行
- Cron 入口：`xedc_core_cron()` 451–471 行依次调用导入恢复、临时文件过期、文件分析、流量采样、分片上传 cron、会员过期与 ZIP 清理。`xedc_ai_cron`、`xedc_member_cron` 另有实现
- 文件分析是否空转：`file_analysis_auto=1`，间隔 86400 秒，上次 state `xedc_file_analysis_last=1793635491`。与最新 batch 16 的 created 相同量级
- Queue：见主报告残留 batch。另外 `update_fetch_tasks` 1 行
- 核心 `update` 模块启用。没有执行更新

## 主题注册

`xedc_theme()`、`xedc_core_theme()`、`xedc_home_theme()`、`xedc_member_theme()`、`xedc_models_theme()`、`xedc_tags_theme()`、`xedc_admin_ui_theme()`、`xedc_ai_theme()`、`xedc_mail_theme()` 以及 `FrontNavHooks` 的 `#[Hook('theme')]` 都会向主题注册表加 hook。

本次没有重建主题注册表（那会写缓存）。因此“某个 Twig 文件名是否仍在注册表里”对新增未跟踪模板是 `NOT_TESTED`。已跟踪且符合 `node--photo--full`、`views-view--image-gallery`、`views-view--video-list` 这类命名的模板，和对应的 preprocess 函数同时存在，按 Drupal 建议名规则判定为会被用到，而不是靠“选择器有没有出现在这个 Twig 里”。

`xedc.theme` 有 2200 行以上，集中了导航、图片画廊、JSON-LD、面包屑、评论 AJAX（`_xedc_comment_ajax_callback()` 843 行）。它是前台渲染的主干，不是可删文件。

## 动态调用

不能只靠 grep 的例子，这次实际碰到的：

- 服务 ID 字符串：`xedc_core.module` 与各控制器里的 `\Drupal::service()`
- 插件 ID：`xedc_gallery_status`、`xedc_bundle_tag`、`xedc_home_photo_categories`
- 视图 handler 在运行时 `setHandler()`，配置 YAML 里本来没有这个字段
- `hook_file_url_alter` 由核心对每个文件 URL 调用
- 队列 worker 由队列名发现，不需要别的 PHP 文件写类名
- `ContentEntityOperations` 通过改插件定义数组生效

## 第三方对自定义代码的调用

- simple_sitemap 调用 `hook_simple_sitemap_links_alter`（home 与 site_audit）
- metatag 调用 `hook_metatags_alter`（home 与主题）
- scheduler 调用 `hook_scheduler_publish_process`
- site_audit 扫描 `Plugin\SiteAuditCheck`
- audit 的命令被 `xedc_audit` 的 Drush hook 替换
- Views 调用各模块的 `hook_views_query_alter`（core、home、member、models）
- Redis 模块提供缓存后端，不调用业务类
- s3fs / AWS SDK 由存储运行时包装器使用

反向：自定义代码调用这些模块的服务（`content_moderation.moderation_information`、`file.usage`、`plugin.manager.block`、sitemap 与 metatag 的钩子数据）。没有发现 contrib 模块的 PHP 文件被改写。contrib 目录不在本次未提交修改的主列表中心；具体是否有人改过 contrib，没有做 `git diff vendor` 以外的全量比对，记为未逐文件审阅 contrib 补丁。
