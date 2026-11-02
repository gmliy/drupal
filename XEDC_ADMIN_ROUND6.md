# XEDC Admin 第六轮：前台菜单、进度条、抽查

状态：[ ] 未做 / [x] 已完成并看过真实页面截图 / [-] 没打开或没看截图 / [!] 仍有明显问题

后台主题仍是 `xedc_admin`。新模块 `xedc_admin_nav` 已启用。没有改 `web/themes/custom/xedc/`。

截图目录：`/root/xedc-admin-round6/`。没有看过截图的项不标 [x]。

数据库备份：`/var/backups/xedc_before_admin_nav_20261101_230614.sql.gz`。

## 调查：首页上的管理员侧栏

只读结论，写在改代码之前。改完后的截图在下面。

1. 输出者是 Drupal 核心模块 `navigation`（Navigation），不是 Gin，也不是前台主题。`Drupal\navigation\Hook\NavigationHooks::pageTop()` 使用 `hook_page_top()`，并且排在最后。它调用 `NavigationRenderer::buildNavigation()` 和 `buildTopBar()`，把侧栏和顶栏放进 `page_top`。
2. 谁能看见：权限 `access navigation`（界面标题 Access navigation bar）。匿名用户和普通登录用户的角色里没有这条权限。`administrator` 是超级用户角色，自动拥有。`content_editor` 明确勾了这条权限，所以编辑也能看见。
3. 前台主题 `xedc` 没有参与。它的模板里没有这个侧栏。`page.html.twig` 里的 `page_navigation` 是站点自己的导航区块，不是这条管理侧栏。
4. 改之前的样子：`front-before-1366-light.png`。桌面上一打开就是左侧白栏，顶部是 Drupal 水滴，绿色按钮「实际的」，下面是核心那套菜单，并把首页内容往右推。手机上同一套会收成顶部「展开侧边栏」占位条。
5. 后台页上，`xedc_admin` 原来就用 CSS 把这套核心栏藏掉，自己用抽屉。这次没有改后台这条行为。

## 前台替换

- [x] 管理员打开首页，核心侧栏没了，内容回到全宽：`front-closed-1366-light.png`、`front-closed-390-light.png`。左上角是 40px 圆形按钮，不占文档流。测过它不压住顶栏（顶栏高 60，按钮从 68 开始）。
- [!] 手机上这个圆按钮会压在第一张大图的左上角。顶栏已经占满最上边，再往下就只能压到图片。桌面它落在图片左边的空白里。
- [x] 点开后是后台同一套抽屉：`front-open-1366-light.png`、`front-open-390-light.png`。一级是控制台、快捷方式、内容管理、内容分类、用户管理、站点管理、扩展、结构、外观、网站配置、管理中心。
- [x] 网站配置的飞出面板：`front-config-1366-light.png`。分组和后台一致。
- [x] 后台页没有这个前台圆按钮，仍是原来的顶栏抽屉。`admin-still-1366-light.png` 是窄屏，`admin-header-1366-light.png` 是桌面。核心 Navigation 的 DOM 还在，样式继续隐藏，没有卸载模块。
- [x] 匿名首页在启用模块前后字节相同（各 40234 字节，对比 `/tmp/xedc-round6/anon-before.html` 与 `anon-after.html`）。匿名角色没有 `access navigation`，页面上不出现按钮，也不加载这套 CSS/JS。

菜单数据没有复制。模块 `require` 后台主题的 `includes/navigation.theme`，调用原来的 `_xedc_admin_navigation()`。样式用 `xedc_admin/drawer` 库里的 `layout.css` 和 `navigation.js`，另外只加了 `xedc_admin_nav/css/front.css`。规则都挂在 `.xedc-admin--front` 或 `.xedc-front-nav__button` 上。

前台主题 `xedc` 没有放置本地任务区块。核心顶栏被拿掉之后，内容页上的「查看 / 编辑」会消失。模块在 `preprocess_page` 里自己渲染 `local_tasks_block`，插到内容区最前面，不改 `xedc` 的模板。匿名没有 `access navigation`，不会插入。

- [x] 管理员打开一篇前台内容，标题上方有「查看、编辑、删除、修订版本、翻译」。`b2-front-tabs-1366-light.png`、`b2-front-tabs-390-light.png`。手机上折成两行，五个都在画面里。匿名打开同一地址得到 403，HTML 里没有 `xedc-front-tasks`。

## 进度条和加载

样式在 `web/themes/custom/xedc_admin/css/progress.css`，由后台主题全局库加载。轨道 8px、圆角、浅灰底，填充用主色，百分比在右侧，说明在下方。批处理那种独立进度块放进居中白卡片。表单里的进度条不加这张大卡片。AJAX 小转圈、全屏遮罩转圈写在同一文件。`prefers-reduced-motion` 会关掉动画。

- [!] 没有打开真实批处理页。站点审核页 `site_audit.report` 一打开就会跑审核，可能写报告，所以没访问。全站也没找到已经停在「45%」的批处理地址。`progress-fixture-1366-light.png` 和 `progress-fixture-1366-dark.png` 是在控制台页面里临时插入的进度条，看过图：白卡片、45% 在右侧、说明在下面，深色可读。动画在静止截图里看不出来。上传进度、Views 和 Media Library 的转圈这轮没打开。

## 全站抽查

看过的图才打勾。目录 `/root/xedc-admin-round6/`。

### 已看过

- [x] `/admin/content` 手机浅色：`b1-admin_content-390-light.png`。有面包屑时品牌名隐藏。没有整页横滑。
- [x] `/admin/people` 手机浅色：`b1-admin_people-390-light.png`。
- [x] `/admin/modules` 手机浅色：`b1-admin_modules-390-light.png`。黄色块是「有可用更新」，说明文字换行。
- [x] `/node/add/article` 手机浅色：`b1-node_add_article-390-light.png`。保存按钮在更下面，这张图没滚到。
- [x] `/admin/config` 手机和桌面浅色：`b1-admin_config-390-light.png`、`b1-admin_config-1366-light.png`。分组卡片可读。页顶粉色条是 Drupal 自己的状态错误。
- [x] `/admin/reports/status` 手机和桌面浅色：`b1-admin_reports_status-390-light.png`、`b1-admin_reports_status-1366-light.png`。没有点「运行 Cron」。
- [x] `/admin/structure/taxonomy` 手机浅色：`b2-tax-390-light.png`。
- [x] `/admin/structure/views` 手机浅色：`b2-views-390-light.png`。机器名称整段在卡片内部横滑里，页面本身没有横滑。不再把名称裁成「sch」。
- [x] `/admin/structure/views/view/image_gallery` 手机浅色：`b2-viewedit-390-light.png`。原来的米黄行改成浅灰 `#F4F4F5`。加载转圈在请求结束后不留在页面上。
- [x] `/admin/structure/block` 手机浅色：`b2-block-390-light.png`。区域下拉能看全「导航栏品牌」，「配置」整颗在画面里。区块名在窄列里折成两行。
- [x] `/admin/structure/menu/manage/account` 手机浅色：`b2-menu-390-light.png`。链接和「编辑」都在。
- [x] `/admin/appearance` 桌面浅色：`b2-appear-1366-light.png`。黄色条是更新提示。
- [x] `/admin/content` 手机深色：`b2-content-390-dark.png`。
- [x] 一篇已有内容的编辑页手机浅色：`b2-nodeedit-390-light.png`。标题、语言、媒体按钮、正文框可见。没滚到保存，也没有保存。
- [x] 登录页、404、匿名打开日志地址：`b2-login-1366-light.png`、`b2-404-1366-light.png`、`b2-anon-dblog-1366-light.png`。这三页是前台主题，没有管理圆按钮。没有改 `xedc`。
- [!] `/admin/reports/dblog` 手机浅色：`b2-dblog-390-light.png`。筛选在，日志表在首屏下面，没把整表看完。
- [!] 人员页手机按钮最小高度。样式写了 44px，量到的第一个按钮仍是 40px，还没对上是哪一个控件。

### 还没看完

- [-] 上面多数页面的桌面宽度、深色，还没逐张看。
- [-] CKEditor 工具条、Media Library 打开和关闭、权限大表、角色、术语新增、菜单链接编辑、扩展页筛选、文件控件、真实批处理。这些没截或没看。
- [-] `/node/add/article` 的保存按钮要往下滚才知道能不能点到。不提交。

桌面这 6 页只记了测量（无横向滚动，标题 22px，手机输入框 16px），图还没逐张看过，所以桌面不标 [x]。

- [x] `/admin/content` 手机：`b1-admin_content-390-light.png`。有面包屑时品牌名隐藏。筛选和「过滤」可见。没有整页横滑。
- [x] `/admin/people` 手机：`b1-admin_people-390-light.png`。标签、筛选、添加用户可见。
- [x] `/admin/modules` 手机：`b1-admin_modules-390-light.png`。黄色块是「有可用更新」的警告，不是表格被染黄。说明文字在窄屏里换行，没有裁成省略号。
- [x] `/node/add/article` 手机：`b1-node_add_article-390-light.png`。标题框和定时发布可见。保存按钮在更下面，这张图没滚到，不把「底部按钮可点」算作已核对。
- [-] `/admin/config`、`/admin/reports/status` 的图已保存，还没看。
- [-] 深色、分类、视图编辑、区块布局、菜单、外观、日志、Media Library、CKEditor、403/404、登录页，还没开始。

## 已改样式（后台主题）

- 手机有面包屑时只显示面包屑，品牌名隐藏。桌面两个都在。`admin-header-390-light.png`、`admin-header-1366-light.png`。
- 手机输入框、下拉框、文本域 16px。上面几页的测量是 16px。
- 表单标签字重改为 500。
- 手机主按钮最小高度写成 44px。人员页上量到的第一个按钮仍是 40px，还没对上是哪一个控件，先不标完成。

## 下一批从这里继续

深色和 1366 宽度把上面已看过的手机页再过一遍。然后 CKEditor、Media Library（只打开再关闭）、权限表、术语和菜单的编辑页、扩展筛选。不要打开站点审核，不要点运行 Cron，不要保存表单。
