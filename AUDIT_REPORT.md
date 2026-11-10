# DcLili（Drupal 11）只读审计报告

**审计时间：** 2026-11-10（UTC）  
**环境摘要：** Drupal 11.4.11 · PHP 8.5.12 · MySQL · 默认主题 `xedc`（Radix/Bootstrap 5）· 管理主题 `xedc_admin` · 站点 `https://gitxx.xyz`  
**审计方式：** 代码/配置静态分析 + 只读 Drush/Shell（未改代码、未改配置、未做浏览器视觉回归）  
**配置导出路径：** `config/sync/`（运行时另有 `sites/default/files/config_*/sync`）

---

## 1. 摘要：最重要的 10 个问题

| 优先级 | 编号 | 问题概要 | 影响 |
|--------|------|----------|------|
| P0 | S1 | Nginx 未检出显式 HSTS / CSP / X-Frame-Options / Permissions-Policy | 安全面扩大、Clickjacking / XSS 后利用空间增加 |
| P0 | S2 | 注册/登录/评论等表单**未配置** Honeypot / CAPTCHA / 限流（`config/sync` 无相关配置） | 垃圾注册、评论刷量、撞库风险 |
| P1 | U1 | `system.site` 的 403/404 系统路径为空；`fast_404.html` 为英文极简页，与前台品牌不一致 | 错误页体验差、SEO/品牌受损 |
| P1 | A1 | 启用约 **144** 个模块，含 `audit_*` 全家桶 + `site_audit` + 未使用的 `workspaces`（`workspace` 表 **0** 行） | Bootstrap、内存与维护成本偏高 |
| P1 | P1 | Nginx：`gzip` 有、`brotli`/`http2` 未在 `conf.d` 检出；**无** `fastcgi_cache` | 带宽与 TTFB 仍有优化空间 |
| P1 | P2 | 多处自定义控制器/`LazyBuilder` 使用 `max-age => 0`（成员、通知、后台工具等） | 动态区无法页缓存；高并发时 PHP-FPM 压力大（`pm.max_children = 5`） |
| P2 | SEO1 | 视频 `schema_video_object_description` 与标题相同，缺真实摘要 | 富媒体/SEO 质量偏低 |
| P2 | U2 | 视频节点 **Form display**（`config/sync`）字段权重 121–127 无序、无 field_group；编辑体验主要靠主题模板 | 配置与 UI 不一致，导出/克隆环境易混乱 |
| P2 | D1 | 主题目录内 **13 个 `.bak` 遗留文件**仍在仓库 | 噪音、误引用风险 |
| P3 | M1 | `gin` / `default_admin` / `olivero` 主题仍启用，且 `config/sync` 保留 Gin 区块配置，但 `system.theme.admin` = `xedc_admin` | 多余主题与配置漂移 |

---

## 2. 前台 UI 问题清单

| 编号 | 位置 | 问题 | 建议 | 优先级 | 风险 |
|------|------|------|------|--------|------|
| F-01 | `config/sync/system.site.yml`（`page.403`/`page.404` 为空） | 未指定自定义 403/404 节点 | 创建品牌错误页节点并在配置中绑定；或为 `xedc` 增加 `page--404.html.twig` | P1 | 低 |
| F-02 | `config/sync/system.performance.yml` → `fast_404.html` | Fast 404 输出英文硬编码 HTML，非主题模板 | 改为简短品牌化 HTML 或关闭 fast_404 对 HTML 使用主题系统 | P2 | 低 |
| F-03 | `web/themes/custom/xedc/templates/page/page.html.twig` | 无专用 `page--403` / `page--404` 覆盖（glob 未命中） | 按需添加 403/404 模板，复用 `xedc-header` / `home-footer` | P2 | 低 |
| F-04 | `web/themes/custom/xedc/css/auth.css` + `includes/auth.inc` | 登录/注册样式完整，但部分控件 `:focus` 依赖边框变色、`inputs.css` 全局去掉 outline | 关键交互增加 `focus-visible` 环（与 `xedc-navbar.css` 一致） | P2 | 低 |
| F-05 | `web/themes/custom/xedc/templates/form/form-element-label.html.twig` | 完全委托 Radix，未见隐藏 label 的 a11y 补偿检查 | 对 `title_display= invisible` 的字段抽查（搜索框等已有 `aria-label`） | P3 | 低 |
| F-06 | `web/themes/custom/xedc/xedc.libraries.yml` → `comment-form` | 仍声明 `core/jquery` 依赖，与全站“去 jQuery”方向不一致 | 评估 Ajax 评论是否可迁 `drupal.ajax` + 原生 DOM；或仅该库保留 jQuery | P2 | 中 |
| F-07 | `web/themes/custom/xedc/templates/**`（抽样：首页/视频/搜索） | 图片普遍带 `alt`、`loading`、部分 `srcset`；轮播有 `aria-roledescription` | 保持；对 `item.title` 作 alt 的图集检查标题是否过泛 | P3 | 低 |
| F-08 | `web/themes/custom/xedc/css/tokens.css` + 各列表 CSS | 设计 token 与 shadcn 风格在文章/搜索/视频间基本统一 | 文档化主色/间距 scale，避免各 CSS 文件魔法数漂移 | P3 | 低 |
| F-09 | `web/themes/custom/xedc/build/css/main.style.css` | 全局样式聚合体积**未在本阶段 Lighthouse 实测** | 确认生产 `css.preprocess/compress` 已开（已开）；PageSpeed 抽检首页/视频详情 | P2 | 低 |
| F-10 | `web/themes/custom/xedc/css/inputs.css` | 移动端 16px 防缩放已做；桌面 `outline: none` 较多 | 列表/导航已部分用 `focus-visible`；表单区对齐同一模式 | P2 | 低 |
| F-11 | 多语言（zh-hans/en/ru/ja） | 界面翻译项目声明在 `xedc.info.yml`；**未做各语言页面人工走查** | 抽 404/登录/搜索各语言截图对比 | P2 | 低 |
| F-12 | `web/modules/custom/xedc_member` 公共主页 | 使用 `xedc/public_profile` 库，模板结构较完整 | 不确定：长 BIO/空封面场景的对比度需实机确认 | P3 | 低 |

---

## 3. 后台 UI 问题清单

| 编号 | 位置 | 问题 | 建议 | 优先级 | 风险 |
|------|------|------|------|--------|------|
| B-01 | `drush config:get system.theme` | 管理主题为 **`xedc_admin`**，非 Gin/Claro | 符合现状；勿再混用 Gin 区块配置 | — | — |
| B-02 | `config/sync/core.extension.yml` | `gin`、`claro`、`default_admin`、`olivero` 仍启用 | 若确认不用，禁用并 `config:export` 清理 Gin/default_admin 区块 | P3 | 中 |
| B-03 | `web/themes/custom/xedc_admin/templates/form/node-form.html.twig` | 文章/视频/美图编辑有卡片分组、字段顺序清晰 | 保持；将 **Config 表单显示** 权重与模板语义对齐，便于非主题环境维护 | P2 | 低 |
| B-04 | `config/sync/core.entity_form_display.node.video.default.yml` | 字段全在 `content` 区，权重 121–127 与标题 -5 混排，**无** field_group | 用 field_group 或调整权重；与 `node-form.html.twig` 文档化分工 | P2 | 低 |
| B-05 | 同上 + photo/article | **未在本阶段逐类型比对** 模板与 form display | 导出对比四类节点的 hidden/promote 与模板“更多选项”折叠 | P3 | 低 |
| B-06 | `xedc_admin` + `xedc_admin_nav` / `xedc_admin_ui` | 自定义仪表板与导航（模板 `xedc-admin-dashboard.html.twig`） | 为新编辑者增加字段说明 `#description`（视频 URL vs 文件） | P2 | 低 |
| B-07 | Views 后台列表 | 标准 `content` + 自定义视图（如 `xedc_home`）共存 | 为编辑角色固定常用视图书签；VBO 已装 | P3 | 低 |
| B-08 | 内容审核 | `content_moderation` + `workflows.workflow.xedc_editorial` 启用 | `workspaces` 已装但 **0** 个工作区——若不用工作台，考虑卸载 | P2 | 中 |
| B-09 | 媒体库 | `libraries-extend` 挂载 `xedc_member/media_library_modal` | 保持；大文件上传注意 `client_max_body_size 220m` 与 PHP `upload_max_filesize` 一致（**FPM 值未在本报告列出**） | P2 | 低 |
| B-10 | `web/modules/custom/xedc_ai` SEO/Review 后台 | 页面 `#cache max-age => 0`（预期） | 保持；注意仅授权角色可访问路由 | P2 | 低 |

---

## 4. 性能与并发优化清单

| 编号 | 项 | 现状（证据） | 建议 | 预期收益 | 实施难度 | 优先级 | 风险 |
|------|----|--------------|------|----------|----------|--------|------|
| PF-01 | 页缓存 | `page_cache` + `dynamic_page_cache` + `big_pipe` 已启用；`system.performance` `page.max_age: 86400` | 匿名页确保无 session cookie；CDN 缓存 HTML 需谨慎（见 PF-08） | 高 | 中 | P1 | 中 |
| PF-02 | Redis | `settings.php` 配置 `cache.backend.redis`；`redis-cli ping` → PONG；运行时 `Drupal\redis\Cache\PhpRedis` | 监控内存与 key 数量；`redis_ttl_offset` 已设 | 高 | 低 | — | 低 |
| PF-03 | CSS/JS 聚合 | `css/js.preprocess + compress: true` | 生产保持；主题开发时临时关聚合 | 中 | 低 | P3 | 低 |
| PF-04 | 自定义 `max-age=0` | `web/modules/custom/**` 约 **145+** 处匹配（控制器、lazy builder、表单） | 逐块审查：仅用户私有数据用 `max-age 0` + `contexts`，避免整页 | 中–高 | 高 | P1 | 中 |
| PF-05 | PHP OPcache | CLI：`opcache.enable=On`，`memory_consumption=128` | FPM 池同步调高（**FPM php.ini 未逐项核对**） | 中 | 低 | P2 | 低 |
| PF-06 | PHP-FPM | `/etc/php/8.5/fpm/pool.d/www.conf`：`pm.max_children=5` | 结合 `pm.status` / 慢日志调优；高并发时 5 易排队 | 高 | 中 | P1 | 中 |
| PF-07 | Nginx 压缩 | `conf.d/gzip.conf` 启用 | 增加 `brotli`（需模块） | 中 | 中 | P2 | 低 |
| PF-08 | Nginx 缓存 | 静态文件 `Cache-Control` 30d/7d；**无** `fastcgi_cache` | Cloudflare 缓存 HTML 需 Bypass Cookie；对象存储 `cdn.gitxx.xyz` 已用于媒体 | 中–高 | 中 | P2 | 中 |
| PF-09 | HTTP/2/3 | `conf.d` 未检出 `http2 on` 字样（**可能与 Certbot 主配置合并，不确定**） | `nginx -T` 人工确认并启用 http2 | 低–中 | 低 | P3 | 低 |
| PF-10 | 图片 | 模板层 `loading=lazy`、部分 `srcset`；`responsive_image` 样式 `wide`/`narrow` | 确认 `xa_hero` 等自定义样式在图片样式中全覆盖 | 中 | 中 | P2 | 低 |
| PF-11 | 存储/CDN | `xedc_core.storage`：R2 + `public_base_url: https://cdn.gitxx.xyz`；`s3fs` 模块仍启用且有 `s3fs.settings.yml` | 厘清 s3fs 与 XEDC Storage 边界，避免双写/双配置 | 中 | 高 | P2 | 高 |
| PF-12 | Cron | `automated_cron` **未启用**；crontab `*/15` `drush cron` | 保持；监控 `xedc_core_cron` 分块清理日志 | 中 | 低 | — | 低 |
| PF-13 | 搜索 | 核心 `search` + `search_node`，索引 cron `cron_limit: 100` | 内容量大时评估 Search API（**当前未装**） | 中 | 高 | P3 | 中 |
| PF-14 | 数据库 | `node_field_data` 标准索引存在；**未跑慢查询日志分析** | 开启 performance_schema / 慢查询 ≥1s，重点 Views 列表 | 中 | 中 | P2 | 低 |
| PF-15 | 模块体量 | 144 启用模块 | 卸载 `workspaces*`、未用 `layout_builder`（视图显示无 LB）、冗余审计子模块（若非常态巡检） | 中 | 中 | P2 | 中 |
| PF-16 | Session/并发 | 标准 PHP session；Redis 作缓存非 session（**未配置 redis session，不确定需求**） | 高并发读写分离前先扩容 FPM +  Opcode | 中 | 中 | P2 | 中 |
| PF-17 | Matomo/Statistics | `matomo.js` → `matomo-lite.js`；`statistics.js` → `statistics-lite.js`；去 jQuery 依赖 | 保持；验证统计不失真 | 低 | 低 | P3 | 低 |
| PF-18 | BigPipe | 已启用 | 登录用户页受益；与 `max-age 0` 局部块配合 | 中 | 低 | P3 | 低 |

### 建议只读验收命令（实施前后对比）

```bash
cd /var/www/drupal && drush status
drush ev "echo get_class(\Drupal::service('cache.default'));"
drush config:get system.performance
redis-cli INFO memory | head -20
nginx -T 2>/dev/null | grep -E 'http2|brotli|gzip|fastcgi_cache' | head -30
```

---

## 5. 功能与 SEO / 安全建议

| 编号 | 位置 | 问题 | 建议 | 优先级 | 风险 |
|------|------|------|------|--------|------|
| SEO-01 | `simple_sitemap` + `base_url: https://gitxx.xyz` | 站点地图与 hreflang 已配置（多语言实体） | 定期 `drush simple-sitemap:generate`；Search Console 提交 | P2 | 低 |
| SEO-02 | `metatag.metatag_defaults.node__video.yml` | OG/Twitter 尚可；`schema_video_object_description` = `[node:title]` | 改为 `field_body` 摘要或 `field_summary` | P2 | 低 |
| SEO-03 | `metatag.metatag_defaults.404.yml` | 仅有 `shortlink: <none>` | 补充 `robots: noindex`、友好 `title` | P3 | 低 |
| SEO-04 | `easy_breadcrumb` 已启用 | **未逐路由验证** 面包屑与 canonical 一致性 | 抽检视频/模特/标签页 | P3 | 低 |
| SEO-05 | `pathauto` + `redirect` | 已装 | 保持 `redirect_404` 巡检 | P3 | 低 |
| SEC-01 | `web/sites/default/settings.php` | `trusted_host_patterns` 已限制 `gitxx.xyz` | 保持；CDN 回源 Host 一致 | P1 | 低 |
| SEC-02 | Nginx `drupal.conf` | 基础 PHP 执行限制、隐藏文件拒绝 | 增加安全头（见 S1） | P0 | 低 |
| SEC-03 | `jsonapi.settings` | `read_only: true` | 保持；限制暴露实体类型（**未逐项审计**） | P2 | 中 |
| SEC-04 | 表单防刷 | 无 honeypot/captcha 配置 | `honeypot` 或 `captcha` + `flood` 阈值 | P0 | 低 |
| SEC-05 | `user_registrationpassword` | 已启用 | 配合邮件 SMTP（`smtp` 模块已装）监控退信 | P2 | 低 |
| SEC-06 | `key` + `xedc_core.storage` | 凭据走 env/key，配置无明文密钥 | 勿将 `settings.php`/R2 密钥入 Git（符合 AGENTS.md） | P0 | 高 |
| SEC-07 | 更新 | `composer outdated` 显示 core `11.4.11` → `11.4.12` 等 | 维护窗口升级 core/contrib | P2 | 中 |
| FUNC-01 | `xedc_quick` / `xedc_member` 上传 | Quick 发布与分块上传并存 | 文档化编辑流程；失败重试监控 | P3 | 低 |
| FUNC-02 | `xedc_notify` / 消息 | Cron 轻量（watchdog 抽样） | 高并发时队列化（**当前不确定瓶颈**） | P3 | 低 |
| FUNC-03 | 备份 | crontab `backup.sh` + 仓库 `xedc-recovery/` | 定期演练恢复；R2 备份与 DB 分离验证 | P1 | 中 |
| FUNC-04 | 监控 | `dblog` 启用；**未检出** Netdata/Prometheus 配置 | 生产建议外部 uptime + 错误告警 | P2 | 低 |
| I18N-01 | 4 语言 + `content_translation` | 结构完整 | `xedc_ui_locale` 管理界面文案；AI 翻译与人工校对流程 | P2 | 低 |

---

## 6. 无用代码清单（三类，含证据）

> **判定说明：** 下列 grep 均在 `/var/www/drupal` 内执行（PHP/Twig/YAML/主题库）。Drupal 隐式引用（插件 ID、主题建议、`hook_*`、路由 YAML）已尽量考虑；不确定项归入「疑似」。

### 6.1 【确定可删】（前台与后台均未引用运行时逻辑）

| 路径 | 证据 | 风险等级 | 可安全删除 |
|------|------|----------|------------|
| `web/themes/custom/xedc/css/legal.css.bak` | `grep legal.css.bak` 仅自身；活动库用 `css/legal.css` | 低 | 是 |
| `web/themes/custom/xedc/css/xedc-navbar.css.v1.bak` | 无引用；当前为 `xedc-navbar.css` | 低 | 是 |
| `web/themes/custom/xedc/css/xedc-navbar.css.v2.bak` | 同上 | 低 | 是 |
| `web/themes/custom/xedc/js/xedc-navbar.js.v1.bak` | 无引用 | 低 | 是 |
| `web/themes/custom/xedc/templates/includes/xedc-header.html.twig.v1.bak` | 无引用；活动文件为 `xedc-header.html.twig` | 低 | 是 |
| `web/themes/custom/xedc/templates/node/xedc-meta.html.twig.bak` | 无引用 | 低 | 是 |
| `web/themes/custom/xedc/templates/content/node--photo--full.html.twig.bak` | 无引用 | 低 | 是 |
| `web/themes/custom/xedc/xedc.theme.bak` | 无 PHP `include`；Drupal 仅加载 `xedc.theme` | 低 | 是 |
| `web/themes/custom/xedc/templates/page/page--front.html.twig.bak` | 无引用 | 低 | 是 |
| `web/themes/custom/xedc/templates/page/page.html.twig.bak` | 无引用 | 低 | 是 |
| `web/themes/custom/xedc/src/js/main.script.js.bak` | 构建入口为 `build.js`/现用 `main.script.js` | 低 | 是 |
| `web/themes/custom/xedc/templates/comment/comment.html.twig.bak` | 无引用 | 低 | 是 |
| `web/themes/custom/xedc/xedc.libraries.yml.bak` | 无引用 | 低 | 是 |

**命令示例：** `find web/themes/custom/xedc -name '*.bak'`

### 6.2 【疑似未使用，需人工确认】

| 路径 / 对象 | 证据 | 风险等级 | 删除前确认 |
|-------------|------|----------|------------|
| 主题 `gin` + `config/sync/block.block.gin_*.yml` | `system.theme.admin` = `xedc_admin`；无 `attach_library('gin')` | 中 | 是否计划切换 Gin；删前先 `pm:uninstall gin` 并导出配置 |
| 主题 `default_admin` + 对应 block 配置 | 仅配置 `theme: default_admin`，管理界面用 `xedc_admin` | 中 | Radix 子主题是否给第三方预览 |
| 主题 `olivero` | `core.extension` 启用，默认前台为 `xedc` | 低 | Drupal 标准配置，卸载前确认无障碍/核心更新要求 |
| 模块 `workspaces` / `workspaces_ui` | `SELECT COUNT(*) FROM workspace` → **0**；自定义代码无 `workspaces` 引用 | 中 | 是否与 moderation 路线图相关 |
| 模块 `layout_builder` | `core.entity_view_display.*` 无 `layout_builder` 字段 | 中 | `content_editor` 角色仍授 `layout_builder` 权限 |
| 模块 `s3fs` | 与 `xedc_core` Storage/R2 并存；`StorageSwitchManager` 仍读 `s3fs.use_s3_*` Settings | **高** | 对照运维文档与接管标记，勿盲目卸载 |
| `web/themes/custom/xedc/assets/icons/bootstrap-icons/`（约 **2078** SVG） | `.gitignore` 忽略；`xedc.icons.yml` Icon API；Twig 中无 `bi-` 引用 | 低（磁盘） | 是否仅 CKEditor/后台 Icon 插件使用；可改为按需子集 |
| `drupal/gin` composer 依赖 | 管理 UI 未用 Gin 主题 | 中 | 与上表 `gin` 主题一并决策 |
| `xedc-recovery/tests/fixture/**` | _recovery 测试夹具，非生产 webroot | 低 | 保留测试用途则勿删 |

### 6.3 【不能删（有隐式引用或生产依赖）】

| 路径 / 对象 | 证据 | 风险等级 | 说明 |
|-------------|------|----------|------|
| `web/themes/custom/xedc/xedc.theme`（3000+ 行） | 主题注册、库 alter、大量 `preprocess` | 高 | 核心前台逻辑 |
| `web/modules/custom/xedc_core` | `core.extension`、cron、存储、视频推荐等全站服务 | 高 | 站点主干 |
| `audit` + `audit_*` + `site_audit` + `xedc_audit` + `xedc_site_audit` | `xedc_audit.info.yml` 依赖 `audit`+`site_audit` | 中 | 运维/审计工具链 |
| `radix` 主题 | `xedc` / `xedc_admin` `base theme: radix` | 高 | 卸载即破坏主题 |
| `claro` | 核心/部分子系统默认回退（**隐式**） | 中 | 通常保留 |
| `web/modules/custom/xedc_ai/tests/manual/**` | 非自动路由，但开发 SEO/Review 用手册测试 | 中 | 非运行时，但属工程资产 |
| `config/sync/xedc_core.storage.yml` | 存储 profile 实体配置 | 高 | 删配置即破坏存储 |

---

## 7. 建议执行顺序与回滚方案

### 7.1 建议执行顺序

| 阶段 | 内容 | 依赖 |
|------|------|------|
| 1 | **安全：** Nginx 安全头、表单 Honeypot/CAPTCHA、确认 JSON:API 暴露面 | 无 |
| 2 | **低风险清理：** 删除 `web/themes/custom/xedc/**/*.bak`（13 个文件） | Git 分支 |
| 3 | **体验：** 403/404 品牌页 + Metatag 404 + 视频 schema 描述 | 配置导出 |
| 4 | **性能：** FPM `max_children` 调优、Redis 监控、评估 CDN 缓存规则 | 监控基线 |
| 5 | **架构减负：** 评审 `workspaces`/`gin`/`layout_builder`/`s3fs` 去留 | 运维决策 |
| 6 | **Core//contrib 升级：** 11.4.11 → 11.4.12 等 | 备份后 |

### 7.2 回滚方案（每阶段通用）

1. **代码：** 在功能分支工作；每阶段 `git commit`；回滚 `git revert` 或 `git checkout -- <paths>`。  
2. **配置：** 变更前 `drush config:export` 到临时分支或 tarball；回滚 `drush config:import`（先 `drush config:status` 核对）。  
3. **数据库：** 重大变更前 `drush sql:dump --gzip` 或现有 `backup.sh` 全量；恢复按 `xedc-recovery/` 文档。  
4. **Nginx/PHP：** 改前复制 `/etc/nginx/conf.d/drupal.conf` 与 FPM pool；`nginx -t && systemctl reload nginx`；失败还原文件 reload。  
5. **缓存：** 部署后 `drush cr`；Redis 异常可临时在 `settings.local.php` 切回 `cache.backend.database`（**需人工编写，不在本仓库默认启用**）。

---

## 附录 A：已核对的积极实践

- 前台自定义模板覆盖较完整：首页、视频列表/详情、文章、美图、搜索、评论。  
- 多处 a11y：`aria-label`、`aria-current`、轮播键盘控件、搜索 `role="search"`。  
- Redis 缓存后端已生效；Cron 由系统 crontab 每 15 分钟驱动。  
- `trusted_host_patterns` 已限制主机名。  
- 分析脚本轻量化（Matomo/Statistics jQuery 依赖已剥离）。  
- 备份 cron 与 `xedc-recovery` 工具同仓维护。

## 附录 B：本阶段未覆盖（明确标注不确定）

- 真实浏览器 Lighthouse / axe 可访问性分数。  
- 各语言页面视觉与翻译质量。  
- MySQL 慢查询与 Views EXPLAIN。  
- Cloudflare 控制台缓存规则与 WAF。  
- PHP-FPM `php.ini` 中 `upload_max_filesize` / `memory_limit` 与 Nginx 对齐。  
- 生产 peak 并发与队列积压实测。

---

**报告路径：** `/var/www/drupal/AUDIT_REPORT.md`  

请审阅本报告并确认优先级后，再进入代码/配置修改阶段。
