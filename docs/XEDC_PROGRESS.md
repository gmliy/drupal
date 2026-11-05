# XEDC 视频专区进度

第二轮结果写在文末「第二轮」。验收报告是 `docs/XEDC_VIDEO_SECOND_ROUND_ACCEPTANCE_REPORT.md`。第一轮清单仍保留在下面，不改原来的 `[x]` / `[!]`。

第二轮状态只用：PASS、FIXED、FAIL、NOT TESTED、BLOCKED。

状态只使用四种：

- `[ ]` 未做
- `[~]` 进行中
- `[x]` 已做，并且有实际运行过的验证
- `[!]` 受阻或无法验证，后面写明原因

没有实际运行过的检查不标 `[x]`。本文件与 `docs/XEDC_VIDEO_UI_AND_DURATION_ACCEPTANCE_REPORT.md` 的「已完成 / 已通过测试 / 未完成 / 未验证」使用同一套编号。

对照规则：

- `[x]` = 已完成，且已通过测试
- `[!]` 且代码已经落地 = 已完成，但未验证（原因写在该项）
- `[ ]` = 未完成
- 当前没有 `[~]`，也没有仍为 `[ ]` 的项

## 审查结论

审查基于当前代码、配置实体定义和线上页面，不猜测字段名。

- 内容类型 `video`。标题是节点标题。简介是 `field_body`（text_long）。视频文件是 `field_video_file`，引用媒体类型 `video` 的 `field_media_video_file`。封面是 `field_video_cover`。分类是 `field_categories`，词汇表 `video_category`。标签是 `field_tags`，与图片共用词汇表 `tags`。时长只有 `field_duration`，整数秒，不另建字段。节点上没有 `field_views`。播放量来自 Statistics 模块的 `node_counter`。点赞、收藏、下载、分享由 `xedc_core` 的 Engagement 惰性构建器提供，不重写。发布时间用 `created`。审核字段 `moderation_state` 存在，另有 Scheduler 的 `publish_on`。
- 已发布视频 8 个（翻译行更多）。补齐前时长：54=75、55=98、116=23、129=283，缺失 6、8、9、10。6、8、10 共用本地 `public://` mp4。9 没有文件也没有地址。55 是 YouTube 地址。当前样本没有私有视频，也没有 S3/R2 上的视频文件。`s3fs` 模块启用，但不代表这批视频在对象存储上。
- 列表是视图 `video_list` 的 `page_1`，路径 `/video`。分类页由 `video_category` 走分类视图，只筛 `video`。视频标签原先走 `xedc_tags` 的图片标签模板。本次不改图片标签控制器，另建路由 `xedc_core.video_tag`（`/video/tag/{taxonomy_term}`）和模板 `xedc-video-tag.html.twig`。
- 播放器是主题内的 Plyr（`plyr.min.js`，压缩文件里没有版本号，附带 `plyr.svg` 和 `plyr.css`）加 hls.js 1.7.3。没有 Video.js，没有 DASH 库，没有多清晰度文件。HLS 只在地址以 `.m3u8` 结尾时加载。YouTube 等外链是点击后才插入 iframe。底层保留 Plyr，只改皮肤和初始化。
- 播放量口径保持 Statistics 的页面访问计数。播放器初始化不另记一次。视频页在 `statistics-lite.js` 用 `sessionStorage` 做 30 分钟去重，避免刷新连续加一。不新建播放量字段。
- 时长检测用系统已有的 `/usr/bin/ffprobe` 6.1.1。不执行 apt 或 sudo。检测走队列 `xedc_video_duration`，不在前台请求里跑。历史补齐只写当前修订的 `field_duration`，用 SQL 加缓存失效，不走实体保存，因此不改标题、正文、审核、发布时间和文件字节。已有大于 0 的时长默认跳过。
- 进度记忆不建新表。会员用 core `user.data`（模块名 `xedc_video`），游客用 `localStorage`。这是为了避开数据库结构变更。
- 不新建模块，不改 `config/sync`，避免以后配置导入把代码路由和未导出配置打乱。

## 安全方案记录

- 不删除内容，不重建数据库，不重置 Drupal，不改贡献模块源码，不安装新模块，不引入新的前端框架。
- 不把私有文件复制到公开目录。当前没有私有视频样本，私有路径代码未在真实私有文件上跑过。
- 远程地址和 YouTube 不下载整文件。nid 55 刷新时记录失败原因 `remote address was not downloaded`，原时长 98 秒保留。
- 不执行不可逆的文件替换试验，也不为了测试上传新视频。
- 点赞、收藏、下载、分享、会员进度写入会改用户数据，验收时只核对匿名页面上的控件是否仍在，不点击提交。
- 开始时工作区已有大量未提交改动（互动、会员、对象存储文档等）。这些文件没有回滚，也没有提交。

## 检查清单

### 总目标

- [x] 1. 视频详情页重新设计。证据：`/videos/mimimamamaimaimai` HTTP 200，页面含 `vd-layout`、播放器和相关卡片。
- [x] 2. 视频列表页重新设计。证据：`/video` HTTP 200，含 `xv-page` 和统一卡片。
- [x] 3. 视频分类列表页重新设计。证据：`/video/category/duanshipin` HTTP 200，标题「短视频 - 视频分类 | DcLili」。
- [x] 4. 独立视频标签页，不再复用图片标签模板。证据：`/video/tag/71` 使用视频页标记；`/tags/haibian` 与 `/tags/biaoqian` 仍是 `xt-term`。
- [x] 5. 审查并重做播放器外观与交互代码；底层仍是 Plyr。Chromium 看到 Plyr 已初始化。
- [x] 6. 时长自动识别、保存、展示和历史补齐。证据：`drush xedc:video-duration-stats` 与格式化输出，见第十、十一阶段。

### 开发前规则

- [x] 1. 检查 Git 工作区。开始时分支 `main`，存在大量与本任务无关的未提交文件。
- [x] 2. 保留未提交修改。没有 `git checkout`、`reset` 或覆盖无关文件。
- [x] 3. 检查实际代码结构。结论见上文。
- [x] 4. 检查已有功能和数据。8 个已发布视频、字段和 `node_counter` 已核对。
- [x] 5. 最小必要方案。改动留在 `xedc_core` 和主题 `xedc`，不新建模块，不改 `config/sync`。

### 开发过程禁止项

- [x] 不删除内容和数据。
- [x] 不重建数据库。
- [x] 不重置 Drupal。
- [x] 不修改第三方模块源码。
- [x] 不安装新模块。
- [x] 不引入大型前端框架。继续 Radix 与 Bootstrap 5。
- [x] 不修改 `config/sync`。`git status -- config/sync` 无输出。
- [x] 不改无关的图片、文章、会员中心和管理后台业务。图片标签控制器未改。
- [x] 不覆盖其他正在开发的功能。
- [x] 不执行危险 Git 操作。没有 commit、push、reset。
- [x] 不破坏既有缓存策略。列表仍带 `url.query_args` 与 `user.node_grants` 上下文；时长写入后失效 `node:{nid}` 与 `node_list:video`。
- [x] 不破坏访问权限和审核工作流。时长写入不调用实体保存，不改 `moderation_state`。

### 第一阶段：完整审查现有视频架构

- [x] 1. 内容和字段：标题、视频文件、封面、简介、分类、标签、播放量、点赞、收藏、下载、发布时间、审核状态、`field_duration` 均已按实体定义核对。
- [x] 2. 页面架构：详情别名 `/videos/{标题}`，列表视图 `/video`，分类视图，图片标签模板，封面样式 `video_cover` 640×360 与 `xa_hero`，相关推荐由主题预处理改为标签、分类、其他。
- [x] 3. 播放器架构：Plyr + hls.js 1.7.3。文件 URL 来自媒体文件。样本为本地 `public://`。无私有样本。s3fs 已启用但本批视频不在 S3/R2。无 DASH。HLS 仅 `.m3u8`。
- [x] 必须根据当前代码得出实际结论。结论已写在本文件「审查结论」。

阶段验证：阅读字段配置、路由、主题模板和播放器库；`drush` 实体与统计查询。`php -l` 本阶段无代码改动。后续阶段的 `drush cr` 已执行。本阶段不产生新的 watchdog 错误。

### 第二阶段：统一视频 UI

- [x] 白色极简、留白、层级、卡片、按钮、圆角、字体、间距、16:9 封面、时长徽标、断点变量已写入 `video-detail.css`、`video-list.css`、`video-card.css`，并沿用主题 CSS 变量。
- [x] 继续 Radix 与 Bootstrap 5，没有新的 UI 框架。
- [x] 桌面端：Chromium 1280 宽打开详情页，播放器与控件存在。
- [!] 平板端：没有在平板宽度下测量列数。代码有中间断点，但没有实测。
- [x] 手机端：Chromium 390×844，详情控制栏右缘 346，未横向溢出。
- [!] 暗黑模式：详情相关卡片在 `data-bs-theme=dark` 下文字变为 `rgb(228, 228, 231)`。列表页和分类页的暗色表面没有测完；第二次无头浏览器在等待媒体元数据时挂起，已终止。
- [x] 颜色优先使用已有 CSS 变量，时长徽标用深色半透明底和白字。

阶段验证：CSS 与模板已随页面输出。浏览器证据见第十五阶段。`php -l` 不适用。`drush cr` 在主题改动后执行过。

### 第三阶段：视频详情页

- [x] 1. 整体布局：播放器在上，标题与互动在下，相关推荐在侧栏或下方。模板 `node--video--full.html.twig`。
- [x] 移动端不横向溢出。证据见第二阶段手机测量。
- [!] 简介折叠：模板和脚本有展开/收起，没有在浏览器里点击验证。
- [x] 2. 标题区域显示标题、播放量、发布时间和时长。详情页正文含 `00:23`（nid 116，283 秒以下的短视频；116 的时长是 23 秒）。
- [x] 3. 点赞、收藏、分享控件仍由原 Engagement 构建器输出。匿名 HTML 含 `data-tier="anonymous"`。
- [!] 下载按钮只在节点有下载资源时出现。本次抽查的视频节点没有确认到下载按钮，没有改下载权限代码。
- [!] 游客点赞、会员身份、VIP、下载权限只核对了匿名标记，没有点击，避免写入互动数据。
- [x] 不重写互动系统。Engagement 模块逻辑未替换。
- [x] 4. 简介独立展示，仍走字段的过滤后文本，没有改过滤器。
- [x] 5. 分类链到视频分类，标签链到 `/video/tag/{id}`。nid 116 的标签指向 `/video/tag/71`。
- [x] 6. 相关推荐排除当前视频，卡片含封面、标题、播放量、日期和时长。排序代码为标签、再分类、再其他，最多 8 条，一次 `loadMultiple`。
- [!] 标签优先于分类的顺序没有用一组专门构造的对照数据在页面上逐步核对，只核对了实现和页面上确实有相关卡片。

阶段验证：curl 详情页 HTTP 200；Chromium 看到播放器、时长和卡片。改动文件：`node--video--full.html.twig`、`xedc.theme`、`video-detail.css`、`video-detail.js`。`php -l web/themes/custom/xedc/xedc.theme` 通过。

### 第四阶段：视频列表页

- [x] 1. 页面含标题、介绍、分类入口、排序、网格、分页区域。分类入口显示真实数量：短视频 1、美女视频 3。
- [x] 保留查询参数。`sort=hot` 与 `keys` 仍生效。
- [x] 2. 卡片含封面、右下角时长、标题、播放量、日期。时长样式为深色半透明、白字、内边距和小圆角。封面统一比例。标题截断。悬停动画保持轻微。
- [x] 桌面多列网格已输出。手机列数由 CSS 断点控制。
- [!] 平板列数和悬停视觉没有在浏览器里逐档测量。
- [x] 3. 最新排序为视图默认 `created DESC`。热门排序首页顺序与 `node_counter` 一致（54、55、6 在前）。分类筛选仍走分类路径。搜索「测试」返回 6 条。空关键词显示「共 0」和空状态。
- [x] 权限仍由视图的已发布过滤和 `user.node_grants` 负责，没有放宽。
- [!] 真实第二页：全站已发布视频 8 条，每页 12 条，`?page=1` 返回 200，但没有第二页数据可核对。

阶段验证：curl `/video`、热门、搜索和空搜索。文件：`views-view--video-list.html.twig`、`video-toolbar.html.twig`、`video-card.html.twig`、`video-list.css`、`video-card.css`、`VideoListing.php`。

### 第五阶段：视频分类列表页

- [x] 顶部有分类名、描述和真实数量。短视频页为「共 1 个视频」。
- [x] 1. 只显示该分类视频。视图过滤 `type=video`。
- [x] 2. 不显示图片或文章。
- [x] 3. 复用 `video-card.html.twig`。
- [x] 4. 显示时长。短视频分类卡片为 `01:15`（75 秒）。
- [x] 5. 排序控件与列表共用工具条。
- [!] 6. 分页控件在模板中。该分类只有 1 条，没有第二页数据。
- [!] 7. 空分类：没有一个视频数为 0 的分类。分类页空搜索会显示空网格「该分类下暂无视频」，数量仍是真实关联数。
- [!] 8. 暗黑模式未在分类页单独测量。
- [!] 9. 手机和桌面断点未在分类页单独测量。详情页手机宽度已测。
- [x] 10. SEO：标题「短视频 - 视频分类 | DcLili」，canonical `https://gitxx.xyz/video/category/duanshipin`，可见面包屑「首页 / 视频 / 短视频」。

阶段验证：curl 分类页。文件：`taxonomy-term--video-category.html.twig`、`views-view--taxonomy-term--page-1.html.twig`、`xedc.theme` 面包屑。

### 第六阶段：独立视频标签页

- [x] 1. 独立模板 `templates/video/xedc-video-tag.html.twig`，路由 `xedc_core.video_tag`。
- [x] 2. 视频标签页不再用图片标签模板。页面没有 `xt-term`。
- [x] 3. 图片标签仍用原模板。`/tags/haibian` 标题「海边 列表页 | DcLili」，含 `xt-term`。`/taxonomy/term/71` 跳到 `/tags/biaoqian`，标题「标签 列表页 | DcLili」，含 `xt-term` 和「组美图」。
- [x] 4. 未改 `xedc_tags` 控制器和图片标签业务逻辑。
- [x] 5. Theme hook 为 `xedc_video_tag`，由 `xedc_core_theme()` 注册。
- [x] 6. 模板名 `xedc-video-tag.html.twig` 对应 hook `xedc_video_tag`。
- [x] 顶部：标签名、数量。标签 71 关联 1 条视频。描述仅在术语有描述时显示。
- [x] 主体只列出视频，使用统一卡片和时长。
- [x] 排序链接指向 `xedc_core.video_tag`，不指向图片标签规范路由。
- [!] 分页：该标签只有 1 条视频，没有第二页结果。分页渲染元素存在。
- [!] 空状态：没有一个零视频标签页被打开。空结果分支在模板里。
- [x] SEO 标题「标签 视频 | DcLili」。
- [x] Meta description「浏览与“标签”相关的视频。」术语没有描述时使用这句。
- [x] canonical `https://gitxx.xyz/video/tag/71`。
- [x] 可见面包屑「首页 / 视频 / 标签」。JSON-LD 为首页、视频、标签。
- [x] 视频标签响应里没有指向 `/tags/` 的 alternate。图片标签标题仍是「列表页」，两边不重复同一套标题。
- [x] 必须验证：图片标签页面仍然正常。
- [x] 必须验证：视频标签页面只显示视频。
- [x] 必须验证：两者模板完全独立。
- [x] 必须验证：URL 正常。`/video/tag/71` 与 `/tags/haibian` 均为 HTTP 200。
- [!] 必须验证：分页正常。两边都没有足够条目形成第二页，只确认了分页区域的存在和单页 HTTP 200。

阶段验证：curl 上述 URL。补了视频标签缺失的 description meta，并改了可见面包屑。`php -l web/themes/custom/xedc/xedc.theme` 通过。`drush cr` 后重新请求。文件：`VideoTagController.php`、`xedc_core.routing.yml`、`xedc-video-tag.html.twig`、`xedc.theme`、`xedc_core.module`。

### 第七阶段：播放器

- [x] 审查后保留 Plyr。没有发现必须更换底层的缺陷。压缩库无版本字符串；hls.js 为 1.7.3。
- [x] 1. 外观：16:9、黑底、`object-fit: contain`、圆角、半透明控制栏、统一图标、醒目播放按钮。Plyr 自动隐藏控制栏。Chromium 确认播放器节点、poster、`preload=none`。
- [!] 控件重叠、图标错位、进度条拖动、全屏后比例、拉伸、移动端控制栏溢出：移动端控制栏未溢出已测。拖动、全屏比例、拉伸没有测成。无头浏览器加载本地 mp4 时 `currentTime` 停在 0，第二次等待 `loadedmetadata` 挂起。
- [x] 2. 控制栏按钮存在：播放/暂停、进度、设置（速度）、静音、全屏。Chromium 读到这些按钮。清晰度按钮不显示，因为没有多码率。没有字幕轨时不把字幕放进设置。
- [!] 当前时间随播放前进、缓冲条、拖动，未在媒体可播放后测到。`currentTime` 保持 0。
- [x] 3. 未播放时有 poster，`preload=none`，不预加载整文件。
- [!] 点击画面暂停、中央播放反馈，没有单独点击视频画面。`player.pause()` 调用后 `paused=true`。
- [!] 4. 速度菜单代码包含 0.5、0.75、1、1.25、1.5、2，并写入 `localStorage` 键 `xedcVideoRate`。设置 `player.speed=1.5` 后读回仍是 1，`xedcVideoRate` 为 null。不能标通过。
- [!] 5. 音量滑块和记忆：读到音量 0.4，但 `xedcVideoVolume` 为 null。滑块拖动和恢复声音未测。
- [x] 静音：键盘 `M` 后 `muted=true`。
- [!] 6. 拖动进度和拖动预览未测。Plyr 开启了 `tooltips.seek`。
- [!] 7. 全屏未测。无头环境在媒体就绪前被终止，没有进入全屏。
- [!] 8. 键盘：只确认 `M`。空格、左右 5 秒、上下音量、`F` 未观察到时间或全屏变化。输入框获焦时不触发的分支在代码里，没有测到。
- [x] 9. 文件不存在：nid 9 页面显示「视频文件不存在」，没有 PHP 堆栈。
- [!] 加载动画、缓冲、无法播放、网络异常、重试按钮没有在浏览器里触发。重试按钮在模板里，默认隐藏。
- [x] 10. 不生成额外清晰度文件，不为字幕加依赖。HLS 仍只处理 `.m3u8`。没有 DASH。
- [!] 画中画按钮是否按浏览器能力显示，这次读取没有单独断言。

阶段验证：Chromium 156 无头，页面 `https://gitxx.xyz/videos/mimimamamaimaimai`。播放、暂停、静音、poster、`preload=none`、移动端不溢出已记录。文件：`video-detail.js`、`video-detail.css`、`node--video--full.html.twig`、`xedc.libraries.yml`。未改 `plyr.min.js`。

### 第八阶段：播放进度记忆

- [x] 原系统没有独立播放进度。未建新表。
- [!] 会员定期保存、继续播放提示、从头播放、并发与权限：接口为 GET/POST `/video/progress/{node}`，POST 要登录和 CSRF，服务端 10 秒节流，写入 `user.data`。没有登录会话，没有发 POST，避免写入会员数据。
- [!] 游客 `localStorage` 键 `xedcVideoProgress:{nid}`。播放时间没有超过 5 秒，键保持 null。少于 5 秒不记、临近结尾清除的逻辑因此没有被页面走通。
- [x] 进度不写入点赞或收藏。使用单独的路由和 `user.data` 键。

阶段验证：代码审查加浏览器本地存储读取。文件：`VideoProgressController.php`、路由、`video-detail.js`。`php -l` 通过。

### 第九阶段：播放量

- [x] 播放器初始化不调用 Statistics。
- [!] 刷新不连续增加、短时间不无限增加：视频页脚本用 `sessionStorage` 键 `xedc-video-view-{nid}`，30 分钟内跳过。没有在刷新前后读取 `node_counter` 做对照，不能标通过。
- [x] 保留历史计数。没有清空 `node_counter`。nid 54 等原计数仍在。
- [x] 不新建播放量字段。详情和卡片读 `PlaybackStats` 的一次 `node_counter` 查询。
- [x] 不改变统计口径。仍是 Statistics 页面计数，不是「按下播放才计数」。

阶段验证：阅读 `statistics-lite.js` 与 Statistics 存储。文件：`js/statistics-lite.js`、`PlaybackStats.php`。

### 第十阶段：时长自动识别

- [x] 继续使用 `field_duration`，不新建时长字段。
- [x] 1. `command -v ffprobe` 为 `/usr/bin/ffprobe` 6.1.1。未安装或升级软件包。
- [x] 2. 检测不在前台请求执行，不把整个文件读入 PHP。参数限定 duration，超时 20 秒。`-nostdin` 在该版本会吞掉 `-v` 并刷出版本横幅，已去掉。失败日志截断到 240 字。
- [x] 3. 队列 `xedc_video_duration`。上传保存后入队，不阻塞保存。state `xedc_video.pending` 去重。失败写入 `xedc_video.failures`。替换文件且时长为空或文件变了会重新入队。文件删除只清待处理任务，不抹掉已有时长。
- [!] 真实上传和真实替换文件没有做。会新增或改写内容，已避开。队列对已有时长的条目会直接返回，`drush queue:run xedc_video_duration` 处理过 1 条这种任务。
- [x] 4. 统计：`total=8`，`with_duration=7`，`missing=1`，`file_missing=1`，`unreadable=1`，`recorded_failures=2`。
- [x] 补齐只更新时长。nid 6、8、10 写成 13 秒，标题、状态、`created`、`changed`、审核快照保持不变。
- [x] 已有大于 0 的时长跳过。nid 55 仍为 98。nid 54、116、129 的原时长保留，尽管共用文件的原始探测约 5 秒，也不覆盖。
- [x] 分批、断点、可重复。游标 `xedc_video.backfill_cursor`。游标越过 10 后再跑，`processed=0` 且 `done=true`。`--retry` 可再处理失败项。
- [x] 不把全部视频载入内存。按 nid 逐条加载并 `resetCache`。
- [x] 5. 公共本地文件已探测。nid 9 失败原因 `video file is missing`，时长保持空。nid 55 失败原因 `remote address was not downloaded`，不下载、不伪造成功。
- [!] 私有文件、S3、R2 没有对应视频样本，路径分支没有实跑。
- [x] 不把私有文件复制到公开目录。代码按 scheme 根目录解析，不写 public。

阶段验证：`drush xedc:video-duration-stats`，补齐命令，队列运行，`php -l` 全部视频 PHP 文件通过。早期 watchdog 20680–20685 是去掉 `-nostdin` 之前的 ffprobe 横幅，修复后没有新的时长错误。文件：`DurationManager.php`、`VideoDurationWorker.php`、`VideoDurationCommands.php`、`xedc_core.module`、`drush.services.yml`。

### 第十一阶段：统一时长显示

- [x] 格式：5 → `00:05`，65 → `01:05`，542 → `09:02`，3661 → `01:01:01`。本次 `drush php:eval` 复测通过。0 秒格式化为空字符串。
- [x] 1. 详情页显示，例如 23 秒为 `00:23`。
- [x] 2. 列表页显示时长徽标。
- [x] 3. 分类页显示，75 秒为 `01:15`。
- [x] 4. 视频标签页卡片显示时长。
- [x] 5. 相关推荐显示时长。
- [x] 6. 缺失不显示 `00:00`。nid 9 页面没有 `00:00`。
- [x] 7. 详情、卡片、首页卡片都调用 `DurationFormatter`。
- [x] 8. 读 `field_duration`。
- [x] 9. 卡片批量加载节点，播放量一次查询，不在 Twig 里逐条探测。
- [x] 10. 前台不执行 ffprobe。
- [!] 超过一小时的真实视频页面没有样本。格式函数已用 3661 测过。

阶段验证：`DurationFormatter` 输出与页面抽查。文件：`DurationFormatter.php`、`VideoCards.php`、`HomeCards.php`。

### 第十二阶段：统一视频卡片

- [x] 列表、分类、标签、相关推荐都包含 `video-card.html.twig`，不在各模板里复制卡片 HTML。
- [x] 卡片含封面、时长徽标、标题、播放量、发布时间。
- [x] 桌面和手机页面能渲染该卡片。详情手机宽度已测。
- [!] 平板宽度和列表/分类暗黑模式没有测完。详情相关卡片暗色文字色有一次读取。

阶段验证：模板引用与页面 HTML 中的 `vcard`。文件：`templates/video/video-card.html.twig`、`node--video--teaser.html.twig`。

### 第十三阶段：SEO 和缓存

- [x] 详情 SEO 仍走 Metatag。页面有一条 VideoObject 和一条 BreadcrumbList，没有第二套冲突 JSON-LD。
- [x] 列表标题「视频 | DcLili」，描述「浏览已发布的视频，按分类、热度和关键词查找。」，canonical `https://gitxx.xyz/video`，面包屑「首页 / 视频」。
- [x] 分类标题、canonical、面包屑见第五阶段。
- [x] 视频标签标题、描述、canonical、面包屑见第六阶段。
- [x] 时长写入后失效节点和 `node_list:video` 缓存标签。
- [x] 列表与分类查询保留 URL 查询参数和节点权限缓存上下文。
- [!] 更新一条视频后不执行 `drush cr`、只靠缓存标签失效的对照没有做。验收请求都在 `drush cr` 之后。

阶段验证：curl 四个页面的 title、description、canonical、面包屑。文件：`xedc.theme`。

### 第十四阶段：性能

- [x] 1–4. 列表和分类仍用 Views；标签和相关推荐各用限定条件的实体查询，相关推荐一次 `loadMultiple`。
- [!] 没有开查询日志做 N+1 计数，不能把「查询次数已优化」标成测过。
- [x] 5–7. 卡片字段来自已加载节点；播放量静态缓存加一次查询；时长读字段，不重复探测。
- [x] 8. 卡片只渲染一个 Twig。
- [x] 9. 封面用已有 `video_cover` 与 `xa_hero`，不新建图片样式。
- [x] 10. 页面缓存仍由 Drupal 负责。匿名页在 `drush cr` 后重新生成。
- [x] `preload=none` 已在浏览器确认。
- [x] 播放器脚本沿用原来的 Plyr 与 hls.js，没有再加一套播放器库。
- [x] 时长检测不在页面请求里写库。进度保存有 10 秒节流，且未在验收时写入。

### 第十五阶段：浏览器实际验收

页面：

- [x] 视频详情页。Chromium 打开 nid 116，播放器就绪。
- [x] 视频列表页。HTTP 200，且第一次任务中的页面结构已核对；本轮再次 curl 标题与面包屑。
- [x] 视频分类页。HTTP 200，标题与面包屑已核对。
- [x] 视频标签页。HTTP 200，模板与 SEO 已核对。
- [x] 图片标签页。`/tags/haibian` 与 `/tags/biaoqian` 仍是原模板。
- [x] 移动端。详情 390 宽，控制栏不溢出。
- [x] 桌面端。1280 宽打开详情。
- [!] 暗黑模式。只读了详情相关卡片文字色。列表、分类、标签的暗色布局未测完。

播放器：

- [x] 播放。`play()` 后 `playing=true`。
- [x] 暂停。随后 `paused=true`。
- [!] 继续播放。暂停后没有再测一次从暂停恢复。
- [!] 拖动进度。时间停在 0。
- [!] 音量滑块。
- [x] 静音。`M` 生效。
- [!] 倍速。设置 1.5 未生效。
- [!] 全屏。
- [!] 键盘快捷键。仅 `M` 有结果。
- [!] 加载状态。
- [x] 错误状态。nid 9 文本「视频文件不存在」，无堆栈。网络错误和重试未触发。
- [!] 移动端播放。只测了布局不溢出，没有在 390 宽下执行播放。

时长：

- [x] 不足一分钟。23 秒显示 `00:23`，13 秒显示 `00:13`。
- [x] 超过一分钟。75 秒 `01:15`，98 秒 `01:38`，283 秒 `04:43`。
- [!] 超过一小时。没有这样的视频。函数对 3661 返回 `01:01:01`。
- [x] 缺少时长。nid 9 不显示 `00:00`。
- [x] 文件不存在。nid 9 失败原因已记录，时长仍空。
- [!] 视频替换。没有替换真实文件。
- [x] 历史补齐。nid 6、8、10 变为 13 秒且其他字段未变。
- [x] 重复执行补齐。游标完成后 `processed=0`。

业务回归：

- [!] 点赞。控件在，未点击。
- [!] 收藏。匿名页是登录链接，未点击。
- [!] 下载。抽查页未确认下载按钮，未点击。
- [!] 分享。控件在，未点击。
- [!] VIP 权限。未用 VIP 账号打开。
- [x] 游客权限。匿名详情页 HTTP 200，互动区为 anonymous，页面未被重定向到登录。
- [!] 内容审核。未走审核工作流。时长写入不改审核字段，这只证明写入路径，不证明审核界面。
- [!] 播放量统计。去重脚本在，刷新前后的计数没有对照。

阶段验证：Chromium 156 无头 CDP，加 curl。没有可用的 Cursor 浏览器工具。第二次 CDP 在 `loadedmetadata` 挂起，进程已结束，不采用那次结果。

### 第十六阶段：Git 和部署安全

- [x] 开始前记录 Git 状态。`main` 上已有未提交改动，见对话开始时的 `git status`。
- [x] 不覆盖已有未提交修改。
- [x] 检查 diff。`config/sync` 无改动。视频改动集中在 `xedc_core` 的 Video、两个控制器、队列、Drush，以及主题的视频模板、CSS、JS、`xedc.theme`。同目录里还有本任务之外的互动与存储改动，没有回滚。
- [x] 未写入密钥或对象存储凭据。
- [x] PHP 语法。本轮 `php -l` 通过：`xedc.theme`、`xedc_core.module`、`HomeCards.php`、`DurationFormatter.php`、`DurationManager.php`、`VideoCards.php`、`VideoListing.php`、`PlaybackStats.php`、`VideoTagController.php`、`VideoProgressController.php`、`VideoDurationWorker.php`、`VideoDurationCommands.php`。
- [x] `drush cr` 在面包屑和描述改动后执行成功。
- [x] 日志。修复 ffprobe 参数后没有新的时长错误。`/r/12shh` 上的 `PublicProfileBuilder::ownerLink` 不受信回调属于会员公开资料，不是视频页。`07:23` 的 `PhpStorageFactory` 错误来自 Drush `eval()`，不是前台请求。
- [x] 网站可访问。上述视频 URL 均为 HTTP 200。
- [x] 不自动提交或推送。
- [x] 不重置数据库。
- [x] 不执行不可逆操作。
- [x] 数据库结构：没有新表、没有新字段。进度放在 `user.data` 和 `localStorage`。若以后要改成独立表，需要单独的更新钩子和回滚说明；本次不需要。

## 本轮改动文件

视频功能：

- `web/modules/custom/xedc_core/src/Video/DurationFormatter.php`
- `web/modules/custom/xedc_core/src/Video/DurationManager.php`
- `web/modules/custom/xedc_core/src/Video/PlaybackStats.php`
- `web/modules/custom/xedc_core/src/Video/VideoCards.php`
- `web/modules/custom/xedc_core/src/Video/VideoListing.php`
- `web/modules/custom/xedc_core/src/Controller/VideoTagController.php`
- `web/modules/custom/xedc_core/src/Controller/VideoProgressController.php`
- `web/modules/custom/xedc_core/src/Plugin/QueueWorker/VideoDurationWorker.php`
- `web/modules/custom/xedc_core/src/Drush/Commands/VideoDurationCommands.php`
- `web/modules/custom/xedc_core/xedc_core.module`
- `web/modules/custom/xedc_core/xedc_core.routing.yml`
- `web/modules/custom/xedc_core/xedc_core.services.yml`
- `web/modules/custom/xedc_core/drush.services.yml`
- `web/modules/custom/xedc_home/src/HomeCards.php`
- `web/themes/custom/xedc/xedc.theme`
- `web/themes/custom/xedc/xedc.libraries.yml`
- `web/themes/custom/xedc/js/video-detail.js`
- `web/themes/custom/xedc/js/statistics-lite.js`
- `web/themes/custom/xedc/css/video-detail.css`
- `web/themes/custom/xedc/css/video-list.css`
- `web/themes/custom/xedc/css/video-card.css`
- `web/themes/custom/xedc/templates/node/node--video--full.html.twig`
- `web/themes/custom/xedc/templates/content/node--video--teaser.html.twig`
- `web/themes/custom/xedc/templates/video/video-card.html.twig`
- `web/themes/custom/xedc/templates/video/video-toolbar.html.twig`
- `web/themes/custom/xedc/templates/video/xedc-video-tag.html.twig`
- `web/themes/custom/xedc/templates/taxonomy/taxonomy-term--video-category.html.twig`
- `web/themes/custom/xedc/templates/views/views-view--video-list.html.twig`
- `web/themes/custom/xedc/templates/views/views-view--taxonomy-term--page-1.html.twig`

本轮最后一次主题改动：视频列表、视频标签、视频分类的可见面包屑改为「首页 / 视频 / 当前项」；视频标签在 Metatag 没有输出描述时补一条 description。图片标签面包屑仍是「首页 / 全部标签 / 海边」。

## 第二轮

工作区里原有的会员、互动和对象存储改动没有回滚，没有提交 Git，没有改 `config/sync`，没有新建模块，没有新建时长字段，没有新建进度表，没有替换 Plyr，没有升级 hls.js，没有改图片标签控制器，没有改 Nginx。

### 播放故障

- FIXED：nid 116 的源文件是 HEVC。Chromium 能读到时间轴，但 `videoWidth` 为 0，没有画面。原文件保留。另生成本地 H.264 副本 `/sites/default/files/xedc-playback/86.mp4`。页面改为使用这个副本后，画面为 1080×1920，时间从 0.31 增到 0.66。
- FIXED：第一轮把倍速和进度读在媒体就绪之前，所以时间停在 0、倍速仍是 1。媒体就绪后，同一条 H.264 视频时间会增加。2 倍速 1 秒墙钟约前进 1.9 秒。`playbackRate` 对 0.5、0.75、1、1.25、1.5、2 均与设置一致，并写入 `xedcVideoRate`。
- PASS：CDN 上的原视频 `curl -I` 为 200，`Range: bytes=0-1023` 为 206，`Content-Type: video/mp4`。本地 H.264 副本同样 206。Nginx 配置没有改。
- PASS：拖动结果 `currentTime` 落到 4.01。暂停后再播放，时间继续增加。
- PASS：音量设到 0.2 后 `video.volume` 为 0.2，并写入 `xedcVideoVolume`。音量滑块把 `video.volume` 设到 0.3。
- PASS：`M` 可以静音再取消。输入框获焦时方向键不改变时间。输入框失焦后，右方向键从 1 秒跳到 6 秒。
- BLOCKED：无头 Chromium 里 `document.fullscreenEnabled` 为 true，但进入全屏后 `active` 仍是 false。
- PASS：把地址改成不存在的文件后，错误码 4，页面显示「视频文件不存在，或当前无法播放。」和重试按钮。
- NOT TESTED：点击重试后的恢复、网络中断、播放结束回调。

### 续播和播放量

- PASS：游客本地进度 6 秒。刷新后提示「上次看到 00:06」。点击继续后 `currentTime` 为 6.06。提示不在播放器画面上。
- PASS：会员 uid 81 的进度接口保存 6 秒并读回。未登录访问该接口为 403。测试写入随后已删除。
- NOT TESTED：登录状态下的浏览器续播提示。一次性登录 Cookie 没有带进 Chromium。
- FIXED：播放量去重原先判断 `page-node-type-video`，实际 body 类名是 `node-type--video`，所以每次打开都计数。改为同时识别这个类名，并用 `localStorage` 记 30 分钟。nid 8 先打开再刷新，计数从 13 到 14，只增加一次。

### 时长和互动

- PASS：时长格式仍是 `00:05`、`01:05`、`09:02`、`01:01:01`。空时长不显示 `00:00`。本轮没有覆盖已有时长。
- NOT TESTED：新上传、文件替换、私有文件、S3/R2 视频、远程整文件探测。远程视频仍不下载。
- BLOCKED：点赞、收藏、分享记录、下载和 VIP。页面上能看到收藏、点赞、分享。这些操作会写正式计数，没有专用内容，没有点击。抽样详情没有下载按钮。活跃用户没有单独的 VIP 角色。

### 页面和响应式

- PASS：375、390、768、1280、1440 上列表、分类、标签都没有横向溢出。封面比例约 1.778。375 和 390 为 1 列，768 为 2 列，1280 和 1440 为 3 列。
- PASS：暗黑模式文字为 `rgb(228, 228, 231)`，列表背景成为深色。截图在 `docs/video-second-round/`。
- NOT TESTED：390 和 1280 只测了布局数字，没有单独存截图。已存截图是 375、768、1440 和 1440 暗色。

## R2 Prefix 统一

2026-11-03。正式复制已经执行。验收是 PARTIAL。报告是 `docs/XEDC_R2_PREFIX_MIGRATION_REPORT.md`，执行记录是 `docs/XEDC_R2_PREFIX_MIGRATION_EXECUTION_LOG.md`。

- [x] 130 个对象从 `s3fs-public/` 复制到 `public/`，79752252 字节。5 个本地正式原图上传到 `public/`，21616094 字节。135 个目标对象的 SHA256 与源一致。
- [x] 旧的 `s3fs-public/` 133 个对象仍在。fid 799、9 条私有 ZIP 记录、fid 805、824 个本地图片样式和 `_xedc_storage_test/` 都保留。File URI 没有改。
- [x] 新 CDN 地址 135 个全部 200，内容哈希一致。旧 `s3fs-public/` 地址抽查范围请求 130 个仍可访问。图片详情页的新地址来自 `public/`。
- [!] 没有真实浏览器验收。Cloudflare 控制台没有 API 凭据，规则、Workers 和 WAF 没有逐条核对，也没有修改。

## 视频转码与兼容性

2026-11-03。验收报告是 `docs/XEDC_VIDEO_TRANSCODING_ACCEPTANCE_REPORT.md`。没有提交 Git，没有改 `config/sync`。

- [x] 编码判断集中在 `MediaProbe`。H.264 + AAC/无音轨 + yuv420p + MP4 直接播放。HEVC 进入播放副本队列。AV1/VP9 只标记复查，不自动转码。无视频流、损坏、私有和没有本地字节的远程文件不进入重试。
- [x] 任务表 `xedc_video_transcode`，更新 `xedc_core_update_10011`。一行对应一个源文件，不按 nid。队列 `xedc_video_transcode`，同一时刻只允许一个 FFmpeg。
- [x] nid 54、116、129 共用文件 86。已有副本保留，没有重新转码。文件 5 是 H.264，状态 skipped，没有新副本。
- [x] 两个短样本完成真实转码：横屏有声 HEVC、竖屏无声 HEVC。输出经 FFprobe 复查，Chromium 播放了横屏副本。
- [!] 全屏仍受阻。有头 Chromium 在 Xvfb 上 `fullscreenEnabled` 为 true，但没有进入全屏元素。
- [!] 文件 5 的 CDN 地址现在返回 404。本地原文件仍是 H.264，直接打开本地地址可以播放。播放器仍按原逻辑选择 CDN 地址。
- [!] 会员进度已经写入并出现续播提示。因为 CDN 地址 404，继续播放后的时间没有增加。测试写入已删除。

## 视频专区第三轮

2026-11-03。报告是 `docs/XEDC_VIDEO_THIRD_ROUND_ACCEPTANCE_REPORT.md`。截图在 `docs/video-third-round/`。

- [x] 文件 5 的 CDN 404 是公开前缀对象比测试晚 11 秒写完。地址本身指向 `public/2026-10/…mp4`。现在 HEAD 200、Range 206，SHA256 与本地一致。Chromium 从 CDN 播放，720×1280。
- [x] 游客和 uid 81 的续播提示出现后，`currentTime` 都落到 6 并继续播放。从头播放会清掉进度。nid 54 播放结束会清掉进度。
- [!] 全屏在 Xvfb 里仍然进不去。
- [x] 视频首页、分类、标签和详情按现有内容重排。暗色标题为 `rgb(228, 228, 231)`。图片标签 `/tags/haibian` 仍是美图列表。

## 图片自动优化

2026-11-03。报告是 `docs/XEDC_IMAGE_OPTIMIZATION_IMPLEMENTATION_REPORT.md`。

- [x] 公开图片共用 `ImageOptimizer`。新文件保存和从临时目录移入 `public://` 都会处理。隔离 JPEG 写入 `public/xedc-opt-test-…/*.webp`，SHA256 与回读一致，CDN 200。样式在本地 `sites/default/files/styles/`，对应 R2 key 不存在。测试对象已删除。
- [x] 媒体库式 `file.repository` 移动、快速发布控制器、队列、EXIF 方向 6、ZIP 路径穿越、ZIP/7z 逐张 `officialImage()` 已跑通。匿名访问后台页 403。Chromium 打开优化页能看到设置和空记录。
- [!] 会员投稿、后台创建和编辑、媒体库、快速发布没有在浏览器里点选文件。完整导入队列没有新建草稿节点。故意制造的 R2、数据库和磁盘失败没有做。

## 视频专区最终 UI

2026-11-03。报告是 `docs/XEDC_VIDEO_FINAL_UI_ACCEPTANCE_REPORT.md`。截图在 `docs/video-final-ui/`。

- [x] 首页、分类、标签、详情使用同一套视频样式。1440px 四列，1280px 三列，768px 和 1024px 两列，375/390/430 一列。浅色标题 `rgb(28, 25, 23)`，暗色标题 `rgb(228, 228, 231)`。七档都没有横向溢出。
- [x] 详情竖屏按封面比例显示，播放实测 1080×1920。相关推荐是左图右文。`/tags/haibian` 仍是图片标签页。搜索和热门排序可用。Chromium 点击后视频在播放。
- [!] 全屏被无头浏览器权限拦住。这条样本不是 HLS。分页、长简介、点赞、收藏、分享和下载没有点击。播放文件时长是 5.17 秒，页面时长字段仍是 04:43。

## 视频专区间距精修

2026-11-03。说明是 `docs/XEDC_VIDEO_UI_SPACING_输出说明.md`。截图在 `docs/video-ui-spacing/`。

- [x] 375 和 390 上，美图网格、文章列表、视频首页、分类和标签的内容左缘都是 16px，和全站容器一致。原先视频页自己再加 16px，卡片从 32px 开始。
- [x] 详情页标题和播放器也回到 16px。多出来的一圈来自窄屏正文样式 `article { padding: 24px 16px }`，只在视频详情里取消。
- [x] 列表卡片改为细边框和 8px 圆角，封面贴齐卡片上沿，标题和辅助信息放在卡片里面。

## 视频专区第三次 UI 精修

2026-11-03。报告是 `docs/XEDC_VIDEO_UI_REFINEMENT_ROUND2_REPORT.md`。说明是 `docs/XEDC_VIDEO_UI_REFINEMENT_ROUND2_输出说明.md`。截图在 `docs/video-ui-refinement-round2/`。没有提交 Git。

- [x] 首页推荐不再用 `:first-of-type` 把网格第一行藏起来。第一页最新列表只在预处理里去掉已经取作推荐的那一行。首页第一张和第二张卡片的边框、圆角、尺寸一致。分类和标签的卡片用同一套样式。
- [x] 相关推荐改成左封面右文字。封面 148×83，窄屏 112px 宽。当前视频不在推荐里。
- [x] 首页、分类、标签、详情发布者和相关推荐的头像、名称都链到 `/r/{slug}`。地址用 `xedc_member.public_profile` 生成。没有 slug 时不输出链接。uid 81 登录后看到的仍是 `/r/aa`。
- [x] 8 条视频一起渲染时，用户数据查询是 1 条，用户表查询是 2 条。
- [!] 续播本轮没有再测。HLS 没有样本。全屏仍受无头浏览器限制。

## 图片优化补齐

2026-11-03。同一份报告 `docs/XEDC_IMAGE_OPTIMIZATION_IMPLEMENTATION_REPORT.md`。

- [x] 媒体库上传不再漏优化。临时文件移入公开目录前先优化，正式 key 使用 `public/`，不保留未优化 JPEG。
- [x] 快速发布小图先优化再入库。控制器实测 WebP 在 `public/gallery/`，原 JPEG key 不存在，测完已删。
- [!] 浏览器里的上传点击、完整 ZIP 导入草稿和故意失败注入仍然没有做。

## 图片优化浏览器验收

2026-11-03。报告是 `docs/XEDC_IMAGE_OPTIMIZATION_BROWSER_ACCEPTANCE_REPORT.md`。截图在 `docs/image-optimize-browser/`。测试 ID `bacc7ad0f2`。没有提交 Git。

- [x] 用管理员和会员浏览器会话核过美图封面、媒体库、会员投稿、头像、快速发布和大图队列。优化结果在 R2，SHA256 与回读一致，CDN 200。样式在本地，对应 R2 Key 不存在。
- [x] ZIP 和 7z 走完导入任务。第一次因 `realpath()` 把 R2 对象判缺失而失败，已改 `ImportProcessor.php`。复测后两条都是待审核，没有发布。
- [!] 文章封面、视频封面、图集、GIF、正文图片和封面替换没有保存到节点。损坏图、空文件、SVG、失败重试和故障注入没有做。不能算全入口验收完成。
- [x] 测试节点、媒体、文件、导入任务、会员 uid 310 和对应 R2 对象已删。uid 1 的原头像还在。

## R2 前缀一致性审计

2026-11-03 10:27 UTC。报告是 `docs/XEDC_R2_PREFIX_CONSISTENCY_AUDIT.md`。只读核对，没有改代码、配置、文件 URI 或 R2 对象，没有 commit。

- [x] 生产对象前缀是 `public/` 和 `private/`。公开包装器是 `XedcPublicS3fsStream`，私有包装器仍是本地 `PrivateStream`。线上视频和头像的 CDN 地址使用 `https://cdn.gitxx.xyz/public/...`，抽查 200。图片样式在本站 `/sites/default/files/styles/`，`public/styles/` 下没有对象。
- [x] `s3fs-public/` 仍有 133 个旧对象，旧地址抽查仍是 200。它不是当前写入前缀。Bucket 里没有 `public/public/` 或 `s3fs-public/s3fs-public/`。
- [!] `StorageSwitchManager` 和 `MigrationExecutor::probeOne()` 仍写死 `s3fs-public/`、`s3fs-private/`。当前 Key 和这两处不一致。本次没有改代码。
- [!] `/private/` 的匿名请求是 Cloudflare 403。`/s3fs-private/` 是 404，两个私有前缀都没有对象，没有上传探测文件。

## 图片优化最后一轮补测

2026-11-03。报告是 `docs/XEDC_IMAGE_OPTIMIZATION_FINAL_ACCEPTANCE_REPORT.md`。截图在 `docs/image-optimize-final-acceptance/`。测试 ID `xedc-final-20261103-3a67`。没有提交 Git。

- [x] 后台文章封面、后台视频封面、会员文章封面替换、会员视频封面和美图三张图集都在浏览器里保存到节点。优化结果在 R2，SHA256 与回读一致。透明 PNG 四角仍是全透明。图集前台按上传顺序显示。
- [x] 正文图片上传后编辑器曾写已删除的 JPEG 地址。`ImageOptimizeApplier::accept()` 现在把优化后的 URI 写回上传调用方。复测正文地址是 WebP，原 JPEG Key 为 404，CDN 200。
- [!] 文章页的 `filter_html_image_secure` 会去掉 `cdn.gitxx.xyz` 上的正文图片，前台只剩 16×16 的“已移除图像”。这次没有改文本格式。
- [!] 图集有“显示多媒体条目权重”，本轮没有改权重再保存。
- [x] 测试节点 549–556、媒体、14 个文件、会员 uid 311 和对应 R2 对象已删。uid 1 的原头像还在。

## 对象存储切换修复

2026-11-03。报告是 `docs/XEDC_STORAGE_SWITCH_FIX_REPORT.md`。截图在 `docs/storage-switch-fix/`。没有提交 Git，没有执行生产切换。

- [x] 本地一致性检查的白屏来自表单缓存反序列化后 `$switcher` 未初始化。切换管理器已注册为服务，属性改为 protected。
- [x] 一致性检查和复制计划改用当前前缀 `public/`。旧前缀 `s3fs-public/` 只作为只读来源，不会被移动或删除。
- [x] 管理员浏览器里选择本地存储后，检查表出现，并出现「确认并执行切换」。没有点击它。选择 Cloudflare R2 时因为已经是当前模式，显示禁止执行。
- [!] 全量 SHA-256 仍是未执行。生产切换、失败回滚和执行按钮的真实点击没有做。

## 正文 CDN 图片显示修复

2026-11-03。报告是 `docs/XEDC_IMAGE_OPTIMIZE_FINAL_FIX_REPORT.md`。截图在 `docs/image-optimize-cdn-fix/`。测试 ID `xedc-body-cdn-final-20261103-7c2e`。没有提交 Git。

- [x] `basic_html` 的 `filter_html_image_secure` 仍启用。自定义类只放行主机正好是 `cdn.gitxx.xyz`、路径在 `/public/` 下的 HTTPS 图片。其他域名、伪装域名、用户信息、非 HTTPS 和 `/private/` 仍换成 16×16 占位图。
- [x] 管理员浏览器在正文用「从电脑上传图片」插入 JPEG 并保存。编辑页保存的是 WebP CDN 地址。前台图片 720×420，请求 200 `image/webp`，没有占位图。
- [x] 美图三张图用「显示多媒体条目权重」改成 2、1、0 后保存。重新打开是 WebP、PNG、JPEG。前台顺序和尺寸一致，PNG 四角仍透明。
- [x] 测试节点 557、558、媒体 587–589、文件 870–873 和对应 R2 对象已删。四种语言都先取消发布。uid 1 的原头像还在。首页、美图、视频、文章栏目不含测试 ID。

## 对象存储接管检查与容量单位

2026-11-03。报告是 `docs/XEDC_STORAGE_TAKEOVER_AND_SIZE_FORMAT_FIX_REPORT.md`。截图在 `docs/storage-takeover-size/`。没有提交 Git，没有执行生产接管或存储切换。

- [x] 组合错误来自首次启用前的检查，被用在已经运行的 Cloudflare R2 上。公开接管和 CDN 改写打开是当前架构的正常状态。`runtime_applied` 固定为 false，不能当作运行时开关。
- [x] 运行模式页逐项显示运行时、公开接管、私有接管和 CDN Rewrite。接管检查通过，但本轮未执行，页面也不允许执行。
- [x] 容量改为 1024 进制的 KiB/MiB/GiB。163198 为 159.37 KiB。39380312064 按四舍五入是 36.68 GiB。原始字节仍保留。
- [!] 启用检查页仍保留 AVIF 和 CDN 私有前缀两项其他阻断。PHPUnit 未安装，断言由 Drush 脚本通过。会员下载页和服务器监控页没有打开。

## 图片上传、ZIP/7z 与图集 SEO

2026-11-03。报告是 `docs/XEDC_IMAGE_UPLOAD_IMPORT_ASYNC_SEO_ACCEPTANCE_REPORT.md`。截图在 `docs/image-upload-browser/`。没有提交 Git，没有发布测试内容。

- [x] ZIP/7z 先前失败是因为整张图片表单的 AJAX 上传。手机请求 HTTP 400 且响应为空，页面上的文件名不代表已保存。新接口只传压缩包，写入私有目录后再建任务。
- [x] Drush 脚本 27 项通过：中文 ZIP、中文 7z、空包、损坏包、超限、重复 token、重复哈希、两张图片批次、人工 ALT、未知原图大小。
- [x] 浏览器上传了 7z 和 ZIP。7z 图集详情显示原图 695 B、优化后 66 B、节省 629 B、压缩率 90.5%。匿名访问该未发布内容返回 403。
- [x] 响应后自动处理原先没启动，因为 ImportKick 缺少 `needs_destruction`。补标签后，已保存的 ZIP 任务进入待审核。
- [x] 数据库更新 10013 已执行。历史原图大小保持未知。测试节点和两个公开 WebP 已删除。
- [!] 浏览器没有一次选 30 张，没有点快速发布，没有从用户菜单切换完整暗色模式。正文图片 ALT 没有改写。

## 图片上传第二阶段

2026-11-03。报告是 `docs/XEDC_IMAGE_UPLOAD_PHASE2_ACCEPTANCE_REPORT.md`。截图在 `docs/image-upload-phase2/`。没有提交 Git，没有发布测试内容，没有改生产 R2。

- [x] 浏览器快速发布一张图。页面显示原图 41.02 KiB、优化后 4.10 KiB、未发布 / draft。公开文件是 WebP，没有 `public/public/`。
- [x] 浏览器一次上传 10 张和 30 张不同图片。任务分别是 10/10 和 30/30，内容未发布。30 张原图 2,253,972 字节，优化后 227,554 字节。最低可用内存约 3.79 GiB。
- [x] 离开页面时 10 张任务还是排队，之后进入待审核。刷新只保留已经到达服务器的图片。
- [x] 浏览器打开「重试这个导入任务？」并确认。任务从失败回到待审核，尝试次数 2，只有 1 张媒体。断网后的快速发布重试也创建了未发布草稿。
- [x] 审核列表增加图片处理列。完成、未完成、失败、缺失 ALT 四个筛选都参与查询。排队中的图片不能从编辑页直接发布。
- [x] 第一阶段脚本复跑 27/27，第二阶段脚本 19/19。标题前缀「XEDC上传验收第二阶段」的 12 个节点和 47 个公开文件已删。
- [!] 当前后台主题是 xedc_admin。它的深色开关已点过，背景为 #18181B。Gin 的暗色类没有单独测。优化中和写入 R2 这两个瞬间没有单独刷新。

## 服务器监控故障诊断与修复

2026-11-03。报告是 `docs/XEDC_MONITOR_DIAGNOSIS_AND_REPAIR_REPORT.md`。没有提交 Git，没有清空流量表或 Netdata 历史，没有打开健康检查。

- [x] 大量「暂无数据」是因为字节格式化拒绝了 Netdata 的小数采样。显示前先四舍五入到字节。存储格式化仍只接受整数。
- [x] Active / Inactive 和磁盘聚合吞吐按维度显示名重新对上。Buffer Pool 从约 106.77 TiB 改回约 106.8 MiB。
- [x] 累计流量日汇总与已计入增量一致。报表本身不采样。3 次历史间隔偏长仍显示为缺口。
- [x] Chromium 打开总览、内存、磁盘、网络、MariaDB、Redis、Docker、告警和完整指标。页面没有「暂无数据」。匿名访问监控页面和 JSON 都是 403。
- [!] 浏览器里没有等到下一次自动刷新，也没有点「重新获取」。Nginx 请求数和 PHP-FPM 队列仍然未采集。

## 服务器监控独立验收

2026-11-03。报告是 `docs/XEDC_MONITOR_INDEPENDENT_ACCEPTANCE_REPORT.md`。截图在 `docs/monitor-independent-acceptance/`。没有提交 Git，没有清空流量表或 Netdata 历史。

- [x] 第一阶段的小数格式化、维度显示名、Buffer Pool 字节解释和告警说明都对得上原始接口。内存、Swap、Redis、容器和 Buffer Pool 不再显示成「暂无数据」或 106 TiB。
- [x] 自动刷新在 9 秒内又请求了 4 次数据，CPU 从 29.5% 变为 20.4%。「重新获取」会更新数字，并回到总览。
- [x] 分类历史图的写入和发送原先是负数。缓存把另一个时区的时间文字发给了 UTC 账号。点击「网络」会把日流量图盖成「暂无数据」。这三处已修并复测。
- [!] Nginx 请求数、PHP-FPM 队列、带宽使用率、温度和告警定义仍然没有采集器。流量日表仍是 partial。MariaDB 虚拟内存约 24 TiB 是三份 VSZ 合计。

## 压缩包导入性能

2026-11-03。报告是 `docs/XEDC_ARCHIVE_IMPORT_PERFORMANCE_REPORT.md`。没有提交 Git，没有改生产 R2，没有改任务 158 的记录。

- [x] 任务 158（`gachig129.zip`，28,182,743 字节，137 张）从 13:08:20 到 15:07:04。解压和扫描在同一秒。之后每次 cron 15 张，再等 15 分钟。最后 15 张平均 4545 ms，其中写入 3180 ms，优化 148 ms。
- [x] 常驻服务 `xedc-import-worker.service` 连续消费 `xedc_image_import`。Drupal cron 不再认领这个队列。心跳中断超过 45 秒时，15 分钟 cron 只兜底 20 秒。
- [x] 隔离脚本 16/16。同一 ZIP 重解 97 ms。小 ZIP 后台完成 8002 ms。SIGKILL 后约 2 分钟恢复为 2/2。超限包在写出前拒绝。
- [!] 没有在浏览器里看未完成任务的逐阶段刷新，没有把 137 张整包再导入一次，没有做 200 MB 实包。200 MB 只有在 1 GiB、2000 个文件、单张 50 MB、4000 万像素、压缩比 40 和磁盘余量之内才解压。

## 视频列表首页首卡异常修复

2026-11-05。报告是 `docs/XEDC_VIDEO_FIRST_CARD_FIX_REPORT.md`。截图在 `docs/video-first-card-fix/`。没有提交 Git，没有推送，没有改视频内容或数据库。

- [x] 根因：首页把最新一条抽成 `.xv-hero` 推荐大卡，并用预处理 `array_shift` 从网格去掉同一行。目标视频「视频电话的吉村久夫记得记得」因此和后续 `article.vcard` 不是同一套 DOM/尺寸。
- [x] 去掉推荐大卡与首行剔除。首条回到网格，与第 2、第 3 张同结构。卡片元数据改为两行（会员·分类 / 播放量·日期），1440 上 8 张高度均为 283。
- [x] Chromium 验收 375–1440、浅色/暗色、分类页、标签页：无 hero、无横向溢出，暗色标题 `rgb(228, 228, 231)`。会员链接仍是 `/r/aa`。

## 视频专区整体 UI 重新设计

2026-11-05。报告是 `docs/XEDC_VIDEO_UI_FULL_REDESIGN_REPORT.md`。截图在 `docs/video-ui-redesign/`。没有提交 Git，没有推送，没有改 config/sync 或视频内容。

- [x] 首页/标签/分类/详情统一视觉：紧凑推荐 `.xv-lead`（封面上限约 400px）、统一 `.vcard`、详情信息层级整理。浏览器 41 项 PASS。
- [x] 会员显示改为 Drupal Username（`User::getAccountName()`）。uid 1 由昵称「管理员」改为 `admin`。头像与用户名指向 `/r/aa`。
- [x] 推荐业务保留：最新无搜索第一页抽第一条进推荐区，预处理去掉网格同行，不用 `:first-child` 隐藏。目标视频不在网格重复。
- [x] 七档宽度 375–1440、浅色/暗色、分类、标签、详情相关推荐实测无横向溢出。图片标签 `/tags/haibian` 仍为 `xt-term`。

## 视频双推荐机制（首页 + 分类栏目）

2026-11-05。报告是 `docs/XEDC_VIDEO_DUAL_RECOMMEND_REPORT.md`。截图在 `docs/tasks/screenshots/xedc-video-dual-recommend/`。没有提交 Git，没有推送，没有改 `config/sync`。

- [x] 复用图片 `field_slideshow`（首页推荐）与 `field_featured`（分类栏目推荐）storage，经 `xedc_core_update_10020` 挂到 video；两旗独立保存，默认 0。
- [x] 后台创建/编辑表单「推荐设置」可见「设为首页推荐」「设为当前分类栏目推荐」。浏览器可分别勾选、回显、互不影响。
- [x] 站点首页与 `/video` 读首页旗；分类页读分类旗 + 当前分类。草稿不公开。列表 SQL 去重，分页条数正常。
- [x] 热门按播放量、最新按时间，与推荐逻辑独立。缓存 tag：`xedc_video_home_recommend` / `xedc_video_category_recommend:{tid}` / hot 15 分钟 TTL。
- [x] 会员看不到推荐项；field access forbidden + presave 锁定防构造写入。图片轮播回归仍见 slides。浏览器 27 PASS / 0 FAIL。
