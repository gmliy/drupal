# XEDC 会员与互动中心验收报告

日期：2026-11-02  
环境：Drupal 11.4.11，PHP 8.5，站点 https://gitxx.xyz  
范围：游客点赞，以及会员与互动后台。ZIP、R2、收藏和 VIP 数据模型保持原样。

## 1. 原有系统审查结果

- 分支为 `main`。工作区里已有未提交修改（对象存储、监控、前台主题等）。这些文件没有被还原或格式化。
- 点赞表 `xedc_like` 已存在。会员记录继续用 `uid > 0` 且 `actor_hash` 为空。取消点赞仍是 `status = 0`，不删除历史。
- 点赞接口仍是 `POST /xedc/like/{node}`。Drupal 核心的 `X-CSRF-Token` 检查只对已登录且带会话的请求生效，匿名请求会直接放行，所以匿名请求在控制器里另行校验。
- 收藏仍在 `xedc_member`，没有另建收藏表。`xedc_member_favorite` 模块不存在。
- VIP 仍以 `xedc_vip` / `xedc_vip_log` 为准。下载日志仍是 `xedc_download_log`。分享仍是 `xedc_share`。ZIP 仍使用 `ZipArchive::CM_STORE`、7 天成功缓存、6 小时临时文件、2 小时半成品，以及队列 `xedc_download_zip`。
- 后台菜单定义在 `web/themes/custom/xedc_admin/includes/navigation.theme`。分组「用户管理」未改名。
- 当前后台主题使用 `html.xedc-admin-active[data-theme="dark"]` 的既有变量。没有另做一套主题切换。
- 生产点赞 `id=17`（uid 2，内容 148，有效）在全部操作后仍然存在。uid 2 的历史没有清理。

审查没有发现必须停工的不可逆迁移。采用了兼容更新，没有重建点赞表。

## 2. 游客点赞实现方案

采用方案 A：在现有 `xedc_like` 上增加 `actor_hash`，不新建第二套点赞表。

- 会员：`uid > 0`，`actor_hash` 为空字符串。
- 游客：`uid = 0`，`actor_hash` 为 Cookie 秘密的 SHA-256。
- 唯一键 `user_actor (uid, actor_hash, entity_type, entity_id)`。会员和游客不会互相覆盖。
- Cookie 名 `xedc_guest`。秘密是 32 字节随机数的 64 位十六进制。只在第一次点赞时签发，不在页面 HTML、JS、URL 或日志里出现。
- Cookie：HttpOnly，HTTPS 下 Secure，SameSite=Lax，路径 `/`，有效期 180 天（15552000 秒）。过期或格式不对时不当成任何已有游客。
- IP 只用于洪水限流，不写入点赞表，也不作为身份。
- 取消点赞把 `status` 改为 0。并发下重复插入会命中唯一键并恢复同一行，不会多计。
- 登录后不合并游客点赞。游客行保持 `uid = 0`，会员行使用自己的 uid。
- `/member/likes` 仍只查当前会员且 `status = 1`。

匿名 CSRF：`GET /xedc/like-token` 不缓存。它往会话里写入一个标记，使 Drupal 真正保存匿名会话，并返回核心头令牌。点赞按钮把该值放在 `X-CSRF-Token`。没有令牌返回 403。已登录会员走同一接口，因此核心对已登录写请求的校验也能通过。令牌不放进可缓存的页面 HTML。

## 3. 数据库 Schema 变化

`xedc_like` 新增：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| actor_hash | varchar(64)，NOT NULL，默认空字符串 | 游客秘密的 SHA-256。会员为空 |

唯一键 `user_entity` 若存在则删除，改为唯一键 `user_actor`。其它既有索引没有重建。没有清空点赞、下载、分享或 VIP 数据。

## 4. 更新钩子编号

`xedc_core_update_10010`。执行前最大编号是 10009。钩子可重复执行：字段或索引已存在时跳过。

当前 `system.schema` 中 `xedc_core` 为 **10010**。`drush updatedb:status` 显示没有待执行更新。

## 5. 修改文件

本次任务新增：

- `web/modules/custom/xedc_core/src/Engagement/GuestIdentity.php`
- `web/modules/custom/xedc_core/src/EventSubscriber/GuestCookieSubscriber.php`
- `web/modules/custom/xedc_core/src/Cache/Context/XedcGuestCacheContext.php`
- `web/modules/custom/xedc_core/src/Form/LikeStateForm.php`
- `web/themes/custom/xedc_admin/css/engagement.css`
- `docs/XEDC_ENGAGEMENT_ADMIN_UI_ACCEPTANCE_REPORT.md`

本次任务修改：

- `web/modules/custom/xedc_core/xedc_core.install`
- `web/modules/custom/xedc_core/xedc_core.routing.yml`
- `web/modules/custom/xedc_core/xedc_core.links.task.yml`
- `web/modules/custom/xedc_core/xedc_core.permissions.yml`
- `web/modules/custom/xedc_core/xedc_core.services.yml`
- `web/modules/custom/xedc_core/src/Controller/EngagementController.php`
- `web/modules/custom/xedc_core/src/Controller/EngagementAdminController.php`
- `web/modules/custom/xedc_core/src/Engagement/EngagementBuilder.php`
- `web/modules/custom/xedc_core/src/Engagement/EngagementReport.php`
- `web/modules/custom/xedc_core/src/Engagement/EngagementSchema.php`
- `web/modules/custom/xedc_core/src/Engagement/LikeManager.php`
- `web/modules/custom/xedc_core/src/Engagement/DownloadRecorder.php`
- `web/modules/custom/xedc_core/src/Engagement/ShareRecorder.php`
- `web/modules/custom/xedc_core/src/Engagement/MembershipManager.php`
- `web/modules/custom/xedc_core/tests/manual/engagement-self-test.php`
- `web/themes/custom/xedc/js/engagement.js`
- `web/themes/custom/xedc/xedc.libraries.yml`（互动库版本 1.7）
- `web/themes/custom/xedc_admin/includes/navigation.theme`
- `web/themes/custom/xedc_admin/xedc_admin.libraries.yml`
- `web/themes/custom/xedc/xedc.theme`（只删除了与 `includes/auth.inc` 重复的 `xedc_preprocess_html()`。该重复声明会让未命中页面缓存的前台页变成 500。文件里其余未提交修改保留）

对象存储、监控、视频时长和 R2 相关的未提交文件没有改动。

## 6. 新增后台页面和路由

旧地址保留。新的本地任务指向下面这些路径，权限均为 `view xedc engagement statistics`，且为管理路由、不缓存。

| 页面 | 路由 |
| --- | --- |
| 互动总览 | `/admin/xedc/engagement` |
| 点赞管理 | `/admin/xedc/engagement/likes` |
| 下载管理 | `/admin/xedc/engagement/downloads` |
| 分享管理 | `/admin/xedc/engagement/shares` |
| VIP 会员 | `/admin/xedc/vip`（原路由，未迁移） |
| 会员互动 | `/admin/xedc/engagement/members` |
| 会员详情 | `/admin/xedc/engagement/members/{user}` |
| 互动数据 | `/admin/xedc/engagement/statistics` |
| CSV | `/admin/xedc/engagement/statistics/export` |

点赞状态更正使用 Form API，权限 `administer xedc engagement`。只把记录标为有效或已取消，不删除。操作写入 `xedc_engagement` 日志，日志不含哈希。

分享页把打开面板记为意向。成功复制和系统分享才计入成功分享。页面没有把面板打开写成已经发到外部平台。

## 7. 后台菜单位置

左侧抽屉「用户管理」下的「会员与互动」。链接仍指向 `xedc_core.vip_admin`（`/admin/xedc/vip`）。匹配前缀增加了 `/admin/xedc/engagement`。没有新的一级菜单，也没有放进 Drupal「人员」。

浏览器读到该链接位于 `#xedc-nav-users`，分组标题为「用户管理」，地址为 `/admin/xedc/vip`，在互动页面上为当前项。

## 8. 权限变化

保留：

- `administer xedc vip`
- `administer xedc downloads`
- `view xedc engagement statistics`

新增：

- `administer xedc engagement`（限制权限）。用于更正点赞状态。管理员角色因 `is_admin` 自动拥有。自测确认 `content_editor` 没有上述四项权限。

匿名访问总览、会员详情和 CSV 导出返回 403。控制器渲染会员详情时不依赖菜单隐藏。

## 9. 游客点赞安全措施

- 秘密只存在 HttpOnly Cookie。`document.cookie` 读不到 `xedc_guest`。接口 JSON 不含秘密。
- 数据库和后台只保存或显示哈希。后台标识为「访客 · 」加哈希前 8 位。游客筛选页正文没有 64 位十六进制秘密。
- 匿名与会员都要带服务器签发的 CSRF 头。缺失时 403。
- 每个身份每小时 40 次，同一 IP 每 10 分钟 80 次。超出返回 429。IP 不是身份。
- 不支持的或未发布内容返回 404。并发由唯一键和事务处理。重复点赞不增加计数，重复取消不会变成负数。
- 不自动合并游客记录。

## 10. 统计数据来源

全部来自数据库，没有写死的百分比。

- 点赞：`xedc_like`，有效为 `status = 1`。游客为 `uid = 0`，会员为 `uid > 0`。
- 下载：`xedc_download_log`。数字是已授权交付，不是浏览器下载完成。
- 分享：`xedc_share`。成功分享只计 `outcome = success`。面板打开单独显示。
- VIP：`xedc_vip` 的状态和到期时间。即将到期为 7 天内仍有效。
- ZIP：`xedc_zip_job` 按状态计数。
- CSV 按日聚合，最多 90 天，使用流式响应，不把全部历史载入内存。实测 7 日导出为 `text/csv`，表头 `日期,有效点赞,已授权下载,成功分享`，2026-11-02 为 `2,4,11`，与当日库内计数一致。文件中没有 64 位哈希。

## 11. 缓存策略

- 总览缓存项 `xedc_engagement:dashboard`，60 秒，标签 `xedc_engagement`。
- 点赞、VIP 变更、下载记录和分享记录写入后使该标签失效。
- 互动条缓存上下文包含 `user`、`xedc_guest`、语言和 URL。未登录且未点赞时不签发 Cookie，匿名页仍可被页面缓存。点赞后浏览器持有会话 Cookie，页面缓存按 Drupal 的无会话策略跳过，刷新能读到该游客自己的状态。
- 缓存上下文的值是「member」「none」或哈希前 12 位，不是 Cookie 秘密。
- 管理页 `max-age` 为 0。

## 12. 自动化测试

命令：`vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/engagement-self-test.php`

结果：**PASS 57，FAIL 0，NOT TESTED 1**。脚本摘要 `passed=57 failed=0 skipped=1`。

未测试项是 R2：`file_managed` 中没有 `s3://` 或 `s3fs://` 记录，没有改对象存储配置。

脚本覆盖并通过：游客首次、重复、取消、重复取消、同一游客再次识别、两个游客、会员与游客不互相覆盖、会员列表不含游客、非法哈希、每小时 40 次限流、Cookie 只映射哈希、后台列表不含秘密、内容编辑无管理权限、匿名不能打开总览和导出、VIP 授予/延长/过期/取消、下载权限、ZIP STORE 与缓存命中、超过 10 张或 32MB 进入队列、分享意向不等于成功。

两条 `InvalidArgumentException` 日志是脚本故意提交的非法外链，预期出现。

测试用户和测试内容由脚本自己创建并删除。没有删除 uid 2 或点赞 `id=17`。

额外接口验证（不计入上面的 57）：

| 项 | 结果 |
| --- | --- |
| 无令牌匿名点赞 | PASS，403 |
| 带页面令牌的匿名点赞与取消 | PASS，200。Cookie 为 HttpOnly、Secure、SameSite=Lax。响应体没有秘密 |
| 带 Cookie 再次打开页面 | PASS，按钮为已赞；取消后为未赞 |
| 匿名访问总览、会员详情、CSV | PASS，403 |

## 13. 浏览器实际验收

使用本机 headless Chromium，通过调试协议操作，不是只看截图。

前台，匿名（`drupalSettings.user.uid` 为 0），内容 315：

- 点赞后 `aria-pressed=true`，计数 1。没有登录框，地址没有进入登录页。
- 刷新后仍是已赞，计数 1。
- 再次点击后为未赞，计数 0。
- 数据库留下 `uid=0`、`status=0`、哈希长度 64 的软删除行，随后只删除了这条探针。
- `document.cookie` 不含 `xedc_guest`。
- 另一篇内容 314 的原有计数为 1。匿名点赞变为 2，取消后回到 1。
- 1280、1024、430、390、375 下按钮存在，`scrollWidth - clientWidth` 为 0。
- 没有登录弹窗。控制台没有记录到异常。

前台会员：探针账号完成点赞、刷新仍为已赞、取消。数据库为 `status=0` 的会员行，不是覆盖游客行。探针用户和这些行已删除。uid 2 在内容 314 上的原有有效点赞保留。

后台已登录管理员，页面均出现 `.xedc-ops`，标题分别为互动总览、点赞管理、下载管理、分享管理、VIP 会员、会员互动、互动数据。1280、1024、430、390、375，浅色与 `data-theme=dark`：

- 文档和主内容没有横向溢出。
- 浅色页面背景 `rgb(247, 247, 248)`，卡片 `rgb(255, 255, 255)`。暗色页面背景 `rgb(24, 24, 27)`，卡片 `rgb(32, 32, 36)`，文字 `rgb(228, 228, 231)`。
- 点赞页有 10 个筛选标签。游客筛选提交后选中值保持为 guest，正文没有 64 位秘密。
- 统计页浏览器内请求 CSV 返回 200，`text/csv`，9 行，无 64 位秘密。
- 这些页面没有 JavaScript 异常，也没有 Drupal 意外错误页。
- 菜单位于「用户管理」。

会员详情路由由控制器对 uid 2 渲染成功，匿名请求为 403。浏览器没有逐项点击该详情链接。

## 14. 桌面和移动端

桌面 1280 与平板 1024：后台卡片为 4 列或 2 列，表格在卡片内横向滚动，页面本身不溢出。手机 430、390、375：卡片变为单列，页面不溢出。前台操作条在这五个宽度都没有横向溢出。

浅色为默认。暗色沿用现有 `xedcAdmin.theme` / `data-theme=dark`，没有第二套切换器。

## 15. 生产环境回归

- 点赞 `id=17` 仍为 uid 2、内容 148、有效。
- uid 2 在内容 314 的有效点赞保留。
- 探针游客行、探针会员行和探针用户 `xedc_engage_probe` 已删除。
- 更新钩子没有待执行项。
- 匿名总览、导出、会员详情为 403，不是 500。
- 前台图片页在删除重复的 `xedc_preprocess_html()` 后恢复为 200。该致命错误来自前台主题里后写入的同名函数，与 `includes/auth.inc` 冲突；首页因页面缓存仍可能显示旧的 200，未命中缓存的图片页曾经是 500。
- ZIP 阈值、缓存目录、R2 配置和收藏模块没有改。
- 自测中的 VIP 授予、取消、到期、下载权限、ZIP 缓存命中和队列分支通过。

## 16. 未完成项目

- R2 对象读取：NOT TESTED。库里没有 `s3://` 或 `s3fs://` 文件。
- 系统原生分享成功：NOT TESTED。headless 浏览器没有 `navigator.share`。
- 统计页的自定义日期没有在浏览器里单独提交。7 日、今日/7/30/90 的控件已渲染，90 天上限在代码中。CSV 验证的是 7 日导出。
- 会员详情页没有在浏览器里点开，只验证了控制器渲染和匿名 403。
- 下载、分享的每一种筛选组合没有逐个点击。点赞的游客筛选已提交。
- 游客点赞不会在登录后自动并入会员。这是有意不做。
- 大包（约 256MB）和真实 R2 首包耗时不在本次重测范围内。

## 17. 风险和后续建议

- 游客第一次点赞会获得 Drupal 匿名会话，之后该浏览器不再走匿名页面缓存。未点赞的访客不受影响。
- 不要把 `xedc_guest` 或 CSRF 令牌打进日志、HTML 或可缓存页面。
- 后台更正点赞只应使用「有效 / 已取消」。不要直接 `DELETE` 生产点赞。
- 前台 `xedc.theme` 仍有大量未提交修改。不要再声明 `xedc_preprocess_html()`，这个钩子在 `includes/auth.inc`。
- 若以后要合并游客点赞，必须限制为当前浏览器的 Cookie，并且同一内容已有会员点赞时不能重复计数。当前没有这条路径。
