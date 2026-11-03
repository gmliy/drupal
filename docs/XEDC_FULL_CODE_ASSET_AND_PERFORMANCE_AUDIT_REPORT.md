# XEDC 全站代码资产与性能审计报告

扫描开始：2026-11-03T15:22:04Z  
扫描结束：2026-11-03T15:29:23Z（容器内存采样），报告写入在此之后。  
范围：只读。没有改 PHP、JS、CSS、Twig、YAML、配置、数据库、缓存、Composer、npm，也没有提交 Git。

配套文件：

- `docs/XEDC_FULL_CODE_ASSET_INVENTORY.md`
- `docs/XEDC_FULL_DEPENDENCY_MAP.md`
- `docs/XEDC_FULL_PERFORMANCE_AUDIT.md`
- `docs/XEDC_SAFE_OPTIMIZATION_PLAN.md`

## 1. 审计基线

这些状态在扫描开始时已经存在。工作区里已有的未提交修改不是本次审计产生的。

| 项 | 实测 |
| --- | --- |
| 分支 | `main`，跟踪 `origin/main` |
| HEAD | `e72409eba38874aaaba4c48e16d085951d222f10` |
| HEAD 说明 | 2026-11-03 07:00:08 +0000，`备份 2026-11-03 15:00 \| 配置235项 内容29项` |
| 扫描前 `git status --porcelain` | 121 条。包含已修改的 `xedc_core`、主题、`docs/XEDC_PROGRESS.md`，以及大量未跟踪文档和测试脚本 |
| Drupal | 11.4.11，安装配置 `standard`，根目录 `/var/www/drupal/web` |
| Drush | 13.8.0.0 |
| PHP CLI | 8.5.12 |
| PHP-FPM | 8.5，`php8.5-fpm` active。`99-drupal.ini` 把 `memory_limit` 覆盖为 512M，`opcache.memory_consumption=192`，`opcache.max_accelerated_files=20000` |
| Composer | 2.10.3。`composer.lock` 119 个包，`require-dev` 为空 |
| Node / npm | v24.22.1 / 10.8.2。主题 lock 413 个包记录 |
| 默认主题 / 管理主题 | `xedc` / `xedc_admin`。来源：`system.theme`，Drush 与 `drush php:eval` 一致 |
| 维护模式 | 开启。`state` 中 `system.maintenance_mode = 1` |
| 数据库 | MariaDB，库 `drupal`，226 张表，约 149.9MB。连接成功。本报告不记录账号口令 |
| Redis | PhpRedis `127.0.0.1:6379`，`maxmemory` 64MB，策略 `allkeys-lfu`，采样时约 53.17MB、30474 个键，淘汰次数 0 |
| 站点 URL | `https://gitxx.xyz`。源站 Nginx 1.31.6，前面有 Cloudflare |

机器实测和任务说明里的“2 vCPU、约 2GB”不一致，以实测为准，优化建议仍按小内存机器来写：

- CPU：4 个逻辑处理器，AMD EPYC 7R13，`Thread(s) per core: 2`（2 核超线程）
- 内存：7.6GiB，采样时 used 4.9GiB，available 2.7GiB
- Swap：2.0GiB，已用 1.0GiB
- 负载：扫描开始时 `3.35 2.69 2.05`
- 磁盘：根分区 ext4 58G，已用 25G，可用 33G，44%
- 项目目录：1.6G

服务均为 active：Nginx、php8.5-fpm、MariaDB 13.0.2、Redis、Docker、Netdata、cron、`xedc-import-worker.service`。

Docker 在 15:29:23Z 的内存：

| 容器 | CPU | 内存 |
| --- | --- | --- |
| `xedc-recover-db` | 0.01% | 61.09MiB / 512MiB |
| `matomo-matomo-1` | 0.00% | 3.352MiB / 7.55GiB |
| `matomo-db-1` | 0.01% | 11.46MiB / 7.55GiB |

扫描开始时 `docker ps` 还能看到 `xedc-recover-drill`（`ubuntu:24.04`，命令含 `drill.sh`）。15:29 的 `docker stats` 已没有这个容器。本次没有停止或进入该容器。

PHP-FPM 池 `www`：`pm=dynamic`，`pm.max_children=5`，`pm.start_servers=2`，`pm.max_requests=500`。服务 `MemoryCurrent` 约 217MB。单个 worker RSS 约 50MB 到 153MB。当天日志里 `server reached pm.max_children setting (5)` 在当前日志 16 次、轮转日志 4 次。

## 2. 实际检查了什么

| 对象 | 数量 | 做法 |
| --- | --- | --- |
| 遍历文件 | 61126 | 全目录 `os.walk`，跳过不了 `.git` 以外的内容；分类见资产清单 |
| 自定义与主题 PHP 文件（token 扫描） | 388 | PHP `token_get_all` |
| 类 / 接口 / trait / 枚举声明 | 409 / 4 / 0 / 含在类计数内 | 含 `phpqrcode.php` 和测试类。去掉二维码库和 `tests/` 后是 360 个类、2460 个方法、319 个文件 |
| 方法（含二维码库与测试） | 2685 | token 级，同一文件内的闭包已尽量排除 |
| Hook 形态函数 | 183 | 名字以模块前缀或 `hook`/`preprocess` 形态匹配。其中很多是 `_xedc_*` 助手，不是全部都注册为 Drupal hook |
| 服务 ID | 139 | `*.services.yml` 与 `drush.services.yml` 的顶格服务键。其中带 `class:` 的约 134 个，其余是 `parent: logger.channel_base` 这类继承服务 |
| YAML 路由 | 125 | 含测试夹具 `web/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample` 的 `ui_sample.page`。该模块没有启用 |
| 权限 YAML / 菜单与任务链接 / 库声明 | 44 / 70 / 47 | 静态解析 |
| PHP Attribute | 67 | 含 Drush `Command`/`Option`/`Usage`、10 个 `QueueWorker`、Views、Block、Action、Hook、`ContentEntityType` |
| Twig | 154 | 自定义主题与自定义模块，不含 `node_modules` |
| CSS、JS、SCSS（不含 bak、不含 node_modules） | 111 | 另有 66 个文件名含 `.bak` 的备份 |
| 活动配置 | 885 条 | `config` 表 |
| 仓库配置 | 883 个 yml | `config/sync` |
| 数据表 | 226 | `information_schema` |
| Composer 锁定包 | 119 | 直接依赖 29 个生产包，无 require-dev |
| npm lock 包记录 | 413 | 直接依赖 1 个（`bootstrap-icons`），devDependencies 18 个 |
| 启用扩展 | 136 个模块 + 7 个主题记录 | `pm:list --status=enabled` |
| 安全通告 | `composer audit --locked` 返回 0 条 | 只代表这次查询时的 Packagist 通告库，不代表业务逻辑安全 |

第一轮“类名没有出现在别的文件里”得到 58 个名字。随后用插件 Attribute、注解、`hook_views_plugins_field_alter`、服务容器和同文件引用复查，其中绝大多数是在用的。确认运行时没有注册的类只有 3 个，见第 4 节。

路由解析器曾把相邻路由的 `_controller` 误绑到上一条。抽查 `xedc_core.routing.yml`、`xedc_ai.routing.yml`、`xedc_member.routing.yml` 后，这些“错绑”不成立，没有写入缺陷清单。例如：

- `xedc_core.image_optimize` 指向 `ImageOptimizeForm`，不是 `VideoProgressController`
- `xedc_core.import_rollback` 指向 `TaskActionForm`
- `xedc_member.history_clear` 指向 `HistoryClearForm`
- `xedc_ai.model_delete` 指向 `ModelDeleteForm`

## 3. 已确认正常的部分

- 站点能完成 Drupal bootstrap。数据库连接成功。Cron 上次运行时间读数为 `2026-11-03T23:15:01+08:00`（相对扫描时的 UTC 15:22，约 7 分钟前）。
- Redis 是默认缓存后端。`settings.php` 941–976 行：`cache.default = cache.backend.redis`。注释写明 bootstrap、config、discovery 仍走 ChainedFast，lock 和 flood 留在数据库。
- 页面缓存最大年龄配置为 86400 秒。CSS、JS 预处理均为开启。
- JSON:API 的 `read_only` 为 `true`。
- 内容模型在用：节点类型 `article`、`page`、`photo`、`video`。实体计数：node 84、taxonomy_term 82、media 290、file 322、user 11、comment 13。`node` 表 84 行；`node_field_data` 按类型为 photo 197、article 49、video 32、page 16，这是翻译行，不是 294 个独立节点。语言：`zh-hans`、`en`、`ru`、`ja`。
- 工作流 `editorial` 与 `xedc_editorial` 都已安装。角色：`anonymous`、`authenticated`、`content_editor`、`administrator`、`vip`。
- 图片样式 17 个，含 `photo_card_640`、`photo_gallery_650`、`photo_gallery_1300`、`video_cover`、`xa_hero`。
- 自定义业务路由能对应到真实的 Form 或 Controller。导入、对象存储、监控、文件分析、会员、模特、标签、点赞、分享、下载都有服务或路由注册。依赖图见配套文档。
- 前台主题 `xedc` 的 `base theme` 是 `radix` 6.0.9。全局库 `xedc/style` 加载 `build/css/main.style.css`（189KB）和 `build/js/main.script.js`（41KB）。
- 视频页把 `hls.min.js` 和 `plyr` 放在 `xedc/video-player`，没有放进全局库。
- `xedc_core_views_plugins_field_alter()`（`xedc_core.module` 826–829 行）把内容列表的 `entity_operations` 换成 `ContentEntityOperations`。这个类没有字符串引用，但是 Views 插件替换，属于在用。
- `QrImage.php` 第 19–20 行加载 `assets/phpqrcode.php`。二维码类只在该文件内部互调，不是死代码。
- Views 缓存插件 `xedc_bundle_tag` 被 `views.view.image_gallery.yml`、`video_list.yml`、`article_list.yml`、`taxonomy_term.yml`、`xedc_home.yml` 使用。
- 区块 `xedc_home_photo_categories` 在 `xedc.theme` 第 298 行 `createInstance`。
- `composer audit --locked` 这次没有返回已知通告。
- 自定义表上的查询键大多有索引，例如 `xedc_import_item.task_status`、`xedc_file_ref.batch_fid`、`xedc_like.user_actor`。没有发现“明显的业务表完全没有二级索引”这一类问题。表很小，当前行数不足以单独构成慢查询。

## 4. 已确认未使用的代码

状态 `CONFIRMED_UNUSED` 只给同时满足这些条件的对象：不是服务、没有 PHP/YAML/Twig 调用、不在配置和 `key_value` 里、也不是插件发现类。

| ID | 位置 | 证据 | 风险 | 建议 |
| --- | --- | --- | --- | --- |
| CODE-1 | `web/modules/custom/xedc_admin_ui/src/ModerationGuard.php` 第 23 行 `final class ModerationGuard` | 全库只有类定义。`xedc_admin_ui.services.yml` 没有注册。`config` 与 `key_value` 中 `ModerationGuard` 计数为 0 | 删掉不会改变当前请求路径。类里写了审核状态迁移规则，可能是未接线的草稿 | 本阶段不删。若以后接线，先对 `xedc_editorial` 做一次审核操作验收 |
| CODE-2 | `StorageManager.php` 第 14 行，`StorageSwitchboard.php` 第 14 行 | 两个类都没有服务条目，也没有调用方。`config`/`key_value` 计数为 0。真正在容器里的是 `xedc_core.storage_switch` 对应的 `StorageSwitchManager`，以及 `storage_reader`、`storage_writer`、`storage_guard` 等 | 它们封装的是仍在使用的存储服务。删类不等于可以关 R2 | 本阶段不删。不要把它当成“存储子系统没用” |
| CODE-3 | 主题、模块、`web/sites/default`、`_server` 下文件名含 `.bak` 的文件，共 66 个 | 文件名本身不会被 Drupal 自动加载。例：`xedc.theme.bak`、`xedc.theme.bak2` 到 `bak5`、`auth.css.bak4` 到 `bak10`、`build/css/main.style.css.bak-sticky`（355KB）、`main.style.css.bak-xn`（355KB） | 普通 bak 只占磁盘和造成误编辑。`settings.php.bak` 例外，见 SEC-1 | 确认无用后再移出仓库。不要和正在使用的 `xedc.theme`、`main.style.css` 一起处理 |

插件类即使没有跨文件类名引用，也记为 `CONFIRMED_USED`：10 个 `#[QueueWorker]`、`GalleryStatus`、`GalleryState`、`PhotoCategoriesBlock`、`BundleTag`、`SetModerationState`、`DownloadResource` 的 `#[ContentEntityType]`、`xedc_site_audit` 里带 `@SiteAuditCheck` / `@SiteAuditChecklist` 的类。`site_audit` 模块已启用，这些类靠注解发现。本次没有打开 Site Audit 页面点运行，所以“检查项执行成功”是 `NOT_TESTED`，注册机制本身已核对。

## 5. 疑似未使用

| ID | 对象 | 证据 | 还缺什么 | 状态 |
| --- | --- | --- | --- | --- |
| CODE-5 | Gin 5.0.16 | 主题已启用。`system.theme` 的 admin 是 `xedc_admin`。`xedc_admin.info.yml` 的 `base theme` 是 `radix`，不是 `gin`。`gin_toolbar` 在 `composer.lock` 中为 3.0.3，模块未启用 | 没有检查是否有区块或配置仍写 `gin` 主题名 | `POTENTIALLY_UNUSED` 作为页面主题。不要卸载，除非再搜一遍 `config` 里的主题键 |
| CODE-6 | `workspaces` 与 `workspaces_ui` | 模块启用。`workspace` 表行数为 0 | 未做“关闭模块”的依赖模拟，本阶段也不允许卸载 | `POTENTIALLY_UNUSED` |
| CODE-8 | dev 分支包 | `drupal/mailsystem` `dev-4.x`，`drupal/matomo` `dev-2.0.x`，`drupal/redirect` `dev-1.x`，`drupal/smtp` `dev-1.x`，`drupal/twig_tweak` `4.0.0-alpha5` | 这些模块有配置行（matomo、redirect）或被邮件流程使用。不是未使用 | 稳定性风险，不是卸载候选 |
| DATA-2 | `file_usage` 为空的 8 个 fid：31、37、801、802、803、804、805、915 | 见第 8 节。当前媒体图片字段、视频字段、用户头像、修订媒体字段都没有指向它们。fid 31/37 在旧的 `xedc_file_ref` 批次里有历史记录。801–805 是 2026-11-03 05:31 写入的 `xedc-samples` 视频，其中 805 在 `private://`。915 的 `status=0`，并出现在 `xedc_image_optimize` | 没有列 R2 对象，没有打开这些 URL，没有查正文以外的序列化引用 | `POTENTIALLY_UNUSED`。禁止删除 |

`glossary`、`who_s_new`、`who_s_online` 等核心 Views 没有逐个对访问日志。它们属于核心默认视图，状态 `POTENTIALLY_UNUSED`，不进入删除清单。

## 6. 重复与分叉

相似不等于可以合并。下面只记录对照过输入输出差异的项。

| ID | 位置 | 对照结果 | 状态 |
| --- | --- | --- | --- |
| DUP-1 | `xedc_home.module` 450–452 行 `_xedc_home_sitemap_model_url()` 用正则替换 `/taxonomy/term/{id}`。`xedc_site_audit.module` 90 行起 `_xedc_site_audit_models_url()` 用 `parse_url`，失败时改走路由 `xedc_models.detail` | 目标都是模特 URL，失败行为不同。站点地图钩子两边都实现了 `hook_simple_sitemap_links_alter` | `DUPLICATE` 意图。禁止直接删掉其中一个 |
| DUP-2 | `video_list` 库同时包含 `video-list.css` 和 `video-card.css`；`video-card` 库再次包含 `video-card.css`（`xedc.libraries.yml` 85–96 行） | Drupal 聚合通常按文件路径去重。这是重复声明，不是已测得的双倍下载 | `DUPLICATE` 声明。运行时是否双份加载：`NOT_TESTED` |
| DUP-3 | 文件是否被引用：`file_usage`、`FileReferenceAnalyzer`、`StorageOperationGuard::referenced()`（`StorageOperationGuard.php` 第 23 行）、`ImageOptimizeApplier::referencedFiles()`（第 540 行） | 数据源和副作用不同。`file_usage` 已经证明会漏 | 不是可合并的重复函数 |
| DUP-4 | `drupal/audit`（含十多个 audit_* 子模块，1.1.0）和 `drupal/site_audit` 4.1.3 同时启用。`xedc_audit` 包装前者，`xedc_site_audit` 包装后者 | 两套检查都有自定义代码入口 | 功能重叠。两边都在用，不能卸 |
| DUP-5 | `assets/icons/bootstrap-icons` 2078 个 SVG、8.3MB；`package.json` 依赖 `bootstrap-icons` ^1.13.1，实体在 `node_modules` | 一个给构建，一个放在主题可访问目录 | 磁盘重复。先确认 Twig/CSS 是否用 `assets/icons` 的文件 URL，再考虑只留一份 |

全站“可以安全合并”的重复实现数量是 0。确认存在分叉或重复声明的区域是上面 5 处。

## 7. 配置

- 活动配置 885，`config/sync` 883。只存在于数据库、不在 sync 的名字是 `xedc_core.image_optimize`。其键只有 `sync_bytes`。状态：`配置漂移`。本次没有执行 config:export。
- `xedc_core.settings` 的 `file_analysis_auto=1`，`file_analysis_interval=86400`。每天最多自动跑一轮文件分析，进行中的批次会继续入队（`FileAnalysisRunner::cron()` 205–228 行）。
- 图片样式、文本格式、语言、菜单与第 3 节一致。
- 前台视图路径来自 sync 里的 Views，而不是猜测：
  - 首页：`system.site` 的 `page.front` 为 `/node`，视图 `frontpage` 的路径也是 `node`
  - 图片列表：`image_gallery` 路径 `meitu`，另有 `meitu2`
  - 视频列表：`video`
  - 文章列表：`articles`
  - 搜索：别名 `/search` → `/search/node`
  - 标签：路由 `/tags`
  - 模特：路由 `/models` 与 `/models/{taxonomy_term}`
- 仓库 sync 与活动库没有成批的“只在 sync 里”的配置名。

## 8. 数据库与文件引用

`file_managed`：322 行。`public` 303 个、约 115.9MB；`private` 19 个、约 26.9MB。  
`file_usage` 中 distinct fid 320。`LEFT JOIN` 后没有 usage 的文件是 8 个。320+8 大于 322，说明 usage 里有指向已不在 `file_managed` 的 fid。因此 `file_usage` 既会多也会少，不能当作删除依据。

这 8 个文件当前字段检查结果：

- `media__field_media_image`、`media__field_media_video_file`、修订表、`user__user_picture`、`node__field_video_file`：0 行
- 之前在 `node__field_images` 里看到的 31、37 是媒体 ID 撞号。`field_images` 的 target 是 `media`，不是 file。媒体 21 的图片 fid 是 28，不是文件 31
- `xedc_file_ref` 里 fid 31 和 37 各有 65 行，来自较早批次，`source` 含 `file_usage` 和 `field`。那些行描述的媒体缩略图关系在当前表结构里已经对不上（没有 `media__thumbnail` 表）
- fid 915 在 `xedc_image_optimize` 有 1 行，文件 `status=0`

R2/CDN 对象没有列桶。状态 `NOT_TESTED`。在列出对象存储并对照 URI 之前，这 8 个文件保持 `POTENTIALLY_UNUSED`。

队列里残留 Drupal batch：

| name | 行数 | created（Unix） |
| --- | ---: | --- |
| `drupal_batch:166:0` 到 `172:0` | 各 20 | 1793583729–1793583731 |
| `drupal_batch:199:0` | 2 | 1793718227 |
| `update_fetch_tasks` | 1 | |

合计 batch 行 162。Batch 队列通常只由当时的批处理页面消费，cron 不会自动清掉。它们占队列表，也会让后台进度页继续轮询。状态 `OPTIMIZATION_CANDIDATE`。本次不删队列行。

`xedc_file_hash_work` 约 2.11MB / 2300 行，`xedc_file_ref` 约 1.78MB / 10153 行，`xedc_file_finding` 约 1.50MB / 1935 行。这是文件分析的工作表，不是缓存垃圾。

数据库缓存表在 2026-11-01 15:36 之后没有新的 `created`：

| 表 | 行数 | 最后 created |
| --- | ---: | --- |
| `cache_page` | 380 | 2026-11-01 15:36:03 |
| `cache_render` | 1751 | 2026-11-01 15:36:52 |
| `cache_dynamic_page_cache` | 424 | 2026-11-01 15:36:03 |
| `cache_data` | 1299 | 2026-11-01 15:36:53 |
| `cache_discovery` | 300 | 2026-11-01 15:36:52 |
| `cache_bootstrap` | 13 | 2026-11-01 15:36:53 |
| `cache_config` | 1841 | 2026-11-01 15:36:02 |

这些表仍占数据库体积的一大半（`cache_page` 18.28MB，`cache_dynamic_page_cache` 15.47MB，`cache_render` 15.42MB，`cache_discovery` 8.61MB）。Redis 才是 11 月 3 日仍在命中的缓存。截断这些表属于缓存操作，本阶段 `BLOCKED`。

## 9. 安全

### SEC-1 公开且被 Git 跟踪的 settings 备份

`web/sites/default/settings.php.bak-2026-11-01-1537` 被 `git ls-files` 跟踪。

只读 HTTP 检查：`GET /sites/default/settings.php.bak-2026-11-01-1537` 返回 **200**，`Content-Type: application/octet-stream`，正文以 PHP 源码开头，并且含有数据库设置变量。同一次检查里，`/sites/default/settings.php` 返回 500，没有把源码正文交给客户端。Nginx 的 `drupal.conf` 只拦截 `.php` 和点文件，没有拦截 `.bak`。

本报告不复制该文件的任何值。因为这个 URL 在审计时可以被未登录请求读到，数据库口令和文件里的其他秘密应视为已经暴露。处理方式见优化方案 P0。本次没有改 Nginx，也没有轮换口令。

`_server/php/8.5/fpm/pool.d/www.conf.bak-2026-11-01-1431` 也被 Git 跟踪，但不在 web 根目录，本次没有对它做 HTTP 请求，也没有把它的内容写入报告。状态：需要人工确认里面有没有环境变量。`NOT_TESTED` 是否含密钥。

### SEC-2 翻译 API 密钥放在 State

`key_value` 集合 `state`、名字 `xedc_auto_translate.api_key.deepl` 的值是序列化字符串，长度 39。站点已经启用了 `key` 模块。密钥原文不写入本报告。拥有数据库读权限或能导出 state 的人可以读到它。状态：明文存放。建议迁到 Key 模块或环境变量，并在确认泄露面后轮换。本次没有改 state。

### SEC-3 其他观察

- 登录页响应带有内容安全策略，限制了脚本、图片和 frame 来源，并包含 `cdn.gitxx.xyz`、`x.gitxx.xyz`。这是策略存在的证据，不是渗透测试。
- JSON:API 只读，见第 3 节。
- `workspaces` 开启但没有工作区，不构成直接漏洞。
- 没有做认证后的权限绕过测试。管理页面的访问控制结论限于代码和路由定义，运行时 `NOT_TESTED`。

## 10. 功能风险

| ID | 现象 | 证据 | 状态 |
| --- | --- | --- | --- |
| OPS-1 | 全站维护模式开着，匿名访问内容页得到 Drupal 生成的 503 | `state` 为 1。源站 `https://gitxx.xyz/` 响应 `X-Generator: Drupal 11`，`X-Drupal-Dynamic-Cache: UNCACHEABLE (503)`，`X-Drupal-Cache: UNCACHEABLE (response policy)`。`/user/login` 仍是 200 | 运行时事实。是否故意开启：未知。本次没有关闭 |
| OPS-2 | 这条 503 的 `Cache-Control` 是 `max-age=3600, public` | 同一次响应。Cloudflare 这次是 `cf-cache-status: DYNAMIC`，没有缓存住 | 若某一层缓存遵守 `public`，维护页可能被缓存最多 1 小时。本次只测到 1 次 |
| OPS-4 | 上传写入 Nginx 临时文件失败 | `error.log` 多次 `open() "/var/cache/nginx/client_temp/..." failed (13: Permission denied)`，路径包括 `POST /admin/content/image-imports/image` 和 `POST /admin/quick-gallery/upload` | 当天访问日志里这两个 URL 分别有 5 次和 12 次 HTTP 500 |
| BUG-1 | 图片导入在目标目录为空时抛出未捕获异常 | 13:26:27 日志：`DirectoryNotReadyException`，目标目录 `''`，`FileSystem.php` 482 行，请求 `POST /admin/content/image-imports/image`。代码路径：`AssetUploadController::image()` 151 行调用 `ImageBatchIntake::addFile()`；54–77 行使用 `$task->work_uri`，为空时 `copy()` 的目录是空字符串。该方法只捕获 `ImportException`，核心异常会变成 500 | 日志与代码一致。本次没有重新上传 |
| BUG-2 | 重试链接打到字面量 `undefined` | 访问日志 3 次 `GET /admin/content/image-imports/undefined/retry`。13:37、13:43、13:50 的错误是 `TaskAccess::view()` 当时要求 `int` 却收到 `string`。当前工作区文件 39–42 行已改为 `int\|string`，非数字直接 `forbidden()`。这是审计前就有的未提交修改。`asset-upload.js` 92–94 行在 `job.taskId` 为真值时拼接 `/retry`，字符串 `"undefined"` 也是真值 | 历史 500 已确认。当前文件是否已让新请求变成 403：`NOT_TESTED`（没有再请求该 URL） |

`xedc-import-worker.service` 在运行。队列里仍有上述 batch 行。导入是否还在消费，本次没有跟踪 worker 日志到“处理完一条”为止，记 `NOT_TESTED`。

## 11. 性能问题摘要

细节、原始数字和未测项在 `docs/XEDC_FULL_PERFORMANCE_AUDIT.md`。

前台真实内容页的 TTFB、LCP、CLS、INP 全部是 `NOT_TESTED`。原因是维护模式让匿名内容请求返回 503，而登录会写 session，本次不允许为测试改数据。唯一完成计时的公开 HTML 是登录页。

登录页一次测量：HTTP 200，约 205ms，正文 18845 字节，`X-Drupal-Cache: MISS`，`X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)`，`Cache-Control: max-age=86400, public`。这是登录表单，不能代表首页。

已确认的性能与资源问题：

1. `files/js` 438MB、`files/css` 246MB，文件时间从 2026-10-12 到 2026-11-03。聚合缓存没有回收。
2. 监控默认 3 秒刷新（`monitor.js` 169、195 行），快照缓存也只有成功时 3 秒（`ServerMonitor.php` 81 行）。缓存几乎挡不住下一轮请求。当天访问日志里 `/admin/reports/xedc-monitor/data` 251 次。
3. 每个前台页都加载约 189KB 的 Bootstrap CSS 和 41KB 的 `main.script.js`。
4. 视频库另有 `hls.min.js` 619692 字节、`plyr.min.js` 120871 字节、`plyr.css` 32612 字节。
5. PHP-FPM 子进程上限 5，已被打满。Swap 已用 1GB。再加大 `pm.max_children` 会直接增加内存。
6. MariaDB 慢查询日志开着，`long_query_time=1`，文件约 19KB、32 条。最慢 41.16 秒。形态里有语言表查询，也有 `SQL_NO_CACHE` 读取整个 `cache_*` 表（更像备份或转储，不是普通页面）。日志头时间是 `261101`，即 2026-11-01，不能当成 11 月 3 日页面的查询时间。
7. Redis 已用 53MB / 64MB。命中 1240639，未命中 127699，命中率约 90.7%。淘汰仍为 0。上限一旦顶满，`allkeys-lfu` 会淘汰热度低的键，包括页面缓存。

## 12. 第三方依赖

直接 Drupal 项目与锁定版本：core 11.4.11、gin 5.0.16、radix 6.0.9、audit 1.1.0、colorbox 2.2.1、ctools 4.1.1、easy_breadcrumb 2.0.11、key 1.22.0、metatag 2.2.4、pathauto 1.15.0、redis 1.11.0、s3fs 3.12.0、scheduler 2.3.0、schema_metatag 3.1.0、simple_sitemap 4.2.3、site_audit 4.1.3、statistics 1.0.1、token 1.17.0、views_bulk_operations 4.4.9、views_infinite_scroll 2.0.3，以及第 5 节的 dev/alpha 包。传递依赖含 `aws/aws-sdk-php`（`vendor/aws` 约 77MB），由 S3 兼容存储使用，不能因为业务代码很少写 `new S3Client` 就移除。

`ctools` 模块本身未启用，启用的是 `ctools_entity_mask`。不要删 `drupal/ctools` 包。

npm 的 `dependencies` 只有 `bootstrap-icons`。`bootstrap`、`sass`、`esbuild`、`browser-sync`、`stylelint`、`@biomejs/biome` 都在 `devDependencies`，由 `build.mjs` 构建使用。搜不到运行时 `import` 不等于可以卸载。`node_modules` 已被 gitignore。

没有建议卸载任何一个正在启用的 contrib 模块。

## 13. 优先级

| 级别 | 项 |
| --- | --- |
| P0 | SEC-1 备份设置文件可被下载并且已进 Git。SEC-2 密钥明文在 state。OPS-1 维护模式是否还要开着。OPS-4 上传临时目录权限导致 500 |
| P1 | BUG-1 空 `work_uri` 造成导入 500。BUG-2 `undefined` 重试 URL。PERF 聚合 CSS/JS 684MB。监控 3 秒刷新对齐 3 秒缓存。FPM 子进程打满。503 带 `public` 缓存头 |
| P2 | 数据库里 11 月 1 日之后不再写入的 cache 表。残留 batch 队列 162 行。全局 Bootstrap 资源。Redis 64MB 上限。dev 分支模块。配置漂移 `xedc_core.image_optimize`。Gin 未作为管理主题使用 |
| P3 | CODE-1、CODE-2 未接线类。66 个 bak。Bootstrap Icons 两份副本。DUP-1 两套模特 URL 重写。DUP-2 CSS 库重复声明 |

## 14. 无法完成的验证

记为 `NOT_TESTED` 或 `BLOCKED`，没有写成通过：

- 首页、图片、视频、文章、搜索、标签、模特、会员页的 TTFB、HTML 体积、LCP、CLS、INP、请求瀑布
- 管理页面在登录后的查询数、表单构建时间。登录或提交会写数据库
- 用浏览器逐页点选暗色、桌面、移动布局。CSS 里存在 `data-bs-theme=dark` 与 `data-theme=dark` 规则（视频列表、视频详情、视频卡片、公开会员页），但是否互相覆盖没有在浏览器里量
- R2 桶里的对象列表，以及 CDN 对图片的命中率
- `settings.php.bak` 是否已被外部收录。只确认了源站当前会返回正文
- `www.conf.bak` 的内容
- Site Audit、Matomo、AI 审核页面的实际点击
- 清空缓存、截断 cache 表、删除 batch 行、轮换密钥。这些都会改变系统，已跳过
- 安装新的静态扫描器或浏览器性能工具。任务禁止安装依赖
- `composer outdated` 没有跑，避免任何写 lock 的风险。安全通告只跑了 `composer audit --locked`

## 15. 不允许删除或修改的文件

在另一次明确授权之前：

- 所有用户上传、`file_managed`、私有文件、图片样式、R2 对象
- `vendor`、`web/core`、contrib 模块与主题、`node_modules`
- `xedc/build/css/main.style.css`、`xedc/build/js/main.script.js`
- `phpqrcode.php`、全部 `*.services.yml`、`*.routing.yml`、`config/sync`
- `settings.php`、`settings.php.bak`（后者应先下线再考虑从 Git 历史移除，不能直接当垃圾删而不轮换口令）
- 启用中的全部模块，包括看起来重叠的 `audit` 与 `site_audit`，以及行数为 0 的 `workspaces`
- 本次扫描前已存在的 121 条未提交改动

## 16. 扫描结束时的 Git

报告写完后的 `git status --porcelain` 共 126 条。其中 121 条是扫描开始前就有的修改和未跟踪文件，HEAD 仍是 `e72409eba38874aaaba4c48e16d085951d222f10`。本次只新增这 5 个未跟踪文件：

- `docs/XEDC_FULL_CODE_ASSET_AND_PERFORMANCE_AUDIT_REPORT.md`
- `docs/XEDC_FULL_CODE_ASSET_INVENTORY.md`
- `docs/XEDC_FULL_DEPENDENCY_MAP.md`
- `docs/XEDC_FULL_PERFORMANCE_AUDIT.md`
- `docs/XEDC_SAFE_OPTIMIZATION_PLAN.md`

没有执行 commit，也没有 push。
