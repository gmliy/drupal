# XEDC 后台主题报告

## 给非技术人员的总结

后台仍用新皮肤 `xedc_admin`，没有换回 Gin。左侧改成三截：上面是站点名，中间是常用菜单，下面是「系统」。手机上把抽屉拉开后，不用再往下滚，就能看到并点到「扩展」。扩展、外观、配置、日志、系统状态、Drupal 管理首页都固定在抽屉最下面。

中间的菜单按组收起。第一次打开时，只展开你当前所在的那一组，「工作台」一直露在外面。收起或展开会记在这台浏览器里，刷新后还在。

管理首页、结构、配置不再是一长串裸链接。每一项是一张卡片，卡片上有标题和说明。手机一列，宽屏两到三列。

这次是用真实账号打开后台后看的页面。没有保存、没有新建、没有删除内容。

还不够好的地方：配置页顶部仍有 Drupal 自己的红色状态提示，以及「隐藏描述」；日志页的消息右边仍要在表格里横滑；Site Audit 的标题和网址仍粘在一起，那是模块自己的文字；分类表右上角的「显示行的权重」仍贴着表格边。媒体库弹窗没有打开，因为抽查的文章封面已经选满，没有去删图片。侧栏收成细条时，鼠标停在底部图标上会出现文字，这几个字会压到旁边卡片的一角。

想暂时换回原来的 Gin，在服务器上执行：

```
cd /var/www/drupal
vendor/bin/drush config:set system.theme admin gin -y
vendor/bin/drush cr
```

看完如果还要新皮肤，把上面命令里的 `gin` 改成 `xedc_admin` 再执行一次。

---

## 本轮（左侧导航）

只改了 `web/themes/custom/xedc_admin/`。收尾时 `system.theme` 的 admin 仍是 `xedc_admin`。

侧栏三截：品牌区固定在顶上；中间导航自己滚动（`overflow-y: auto`，`overscroll-behavior: contain`）；底部「系统」不跟着中间滚。抽屉和侧栏高度先写 `100vh`，再写 `100dvh`。系统区底部内边距是 `calc(12px + env(safe-area-inset-bottom, 0px))`。手机抽屉打开时给页面加上滚动锁，关掉后恢复。底部区域在侧栏里面，不是贴在屏幕底边的固定导航。

每条链接先确认路由存在，再用当前用户做访问检查。路由不存在或没有权限的不显示。超管这次六条系统入口都在。同一个地址不会在中间和底部各出现一次。系统状态、配置、日志、扩展、外观、Drupal 管理首页只在底部。

分组标题是按钮，带箭头和 `aria-expanded`，键盘能操作。默认只展开当前页所在的组。「工作台」没有箭头，不能收起。折叠记在 `localStorage` 的 `xedcAdmin.navGroups`，读写都有 try/catch，用 `Drupal.behaviors` 和 `once()`。已核对：在控制台展开「内容管理」后刷新，它仍展开；再收起后打开分类页，「内容管理」保持收起，「内容组织」展开，当前项是「分类管理」。行高至少 44 像素。桌面收成 72 像素时只留图标，分组箭头隐藏，鼠标停在底部图标上会看到文字。

### 中间导航

| 分组 | 名称 | 路由 | 路径 | 权限 |
| --- | --- | --- | --- | --- |
| 工作台 | XEDC 控制台 | `xedc_admin_ui.dashboard` | `/admin/xedc` | `access xedc admin dashboard` |
| 内容管理 | 全部内容 | `view.content.page_1` | `/admin/content/node` | `access content overview` |
| 内容管理 | 美图 | 同上，查询 `type=photo` | 同上 | 同上 |
| 内容管理 | 视频 | 同上，查询 `type=video` | 同上 | 同上 |
| 内容管理 | 文章 | 同上，查询 `type=article` | 同上 | 同上 |
| 内容管理 | 图片导入 | `xedc_core.import_list` | `/admin/content/image-imports` | `import photo archives` 或 `administer xedc image imports` |
| 内容管理 | 快速发布 | `xedc_quick.page` | `/admin/quick-gallery` | `administer nodes` |
| 内容组织 | 分类管理 | `entity.taxonomy_vocabulary.collection` | `/admin/structure/taxonomy` | `administer taxonomy` |
| 内容组织 | 图片栏目 | `entity.taxonomy_vocabulary.overview_form` | `/admin/structure/taxonomy/manage/photo_category/overview` | `access taxonomy overview` 或 `administer taxonomy` |
| 内容组织 | 视频栏目 | 同上 | `.../video_category/overview` | 同上 |
| 内容组织 | 文章栏目 | 同上 | `.../article_category/overview` | 同上 |
| 内容组织 | 标签管理 | 同上 | `.../tags/overview` | 同上 |
| 内容组织 | 人物管理 | 同上 | `.../models/overview` | 同上 |
| 投稿审核 | 审核中心 | `view.moderated_content.moderated_content` | `/admin/content/moderated` | `view any unpublished content` |
| 会员管理 | 会员列表 | `view.user_admin_people.page_1` | `/admin/people/list` | `administer users` |
| SEO 与网站健康 | SEO Audit | `audit.reports` | `/admin/reports/audit` | `view audit results` |
| SEO 与网站健康 | Site Audit | `site_audit.report` | `/admin/reports/site-audit` | `administer site configuration` |

内容、会员、审核这三条的权限来自视图的默认显示。它们的页面显示没有另写权限。

### 底部系统

| 名称 | 路由 | 路径 | 权限 |
| --- | --- | --- | --- |
| 扩展 | `system.modules_list` | `/admin/modules` | `administer modules` |
| 外观 | `system.themes_page` | `/admin/appearance` | `administer themes` |
| 配置 | `system.admin_config` | `/admin/config` | `access administration pages` |
| 日志与报告 | `dblog.overview` | `/admin/reports/dblog` | `access site reports` |
| 系统状态 | `system.status` | `/admin/reports/status` | `administer site configuration` |
| Drupal 管理首页 | `system.admin` | `/admin` | `access administration pages` |

### 管理索引页

`/admin`、`/admin/structure`、`/admin/config` 用卡片网格。手机一列，宽度到 720 像素起两列，到 1200 像素起三列。配置页原来左右各一半的栏目改成上下排开，卡片按整页宽度排。配置页上的红色状态提示和「隐藏描述」是 Drupal 自带的，没有改掉。

### 截图

手机视口 390×664，桌面 1366。文件在 `/root/xedc-admin-nav/`。

- `xedc-390-drawer.png`：控制台抽屉。分组收起，底部六条都在屏幕里，含「扩展」。最后一组「SEO 与网站健康」也完整露出。
- `xedc-390-drawer-scrolled.png`：中间已经滚到底，没有被系统区盖住。
- `content-390-drawer.png`、`content-390-drawer-scrolled.png`：内容页展开「内容管理」；滚到底后「快速发布」在系统区上面。
- `taxonomy-390-drawer.png`、`taxonomy-390-drawer-scrolled.png`：分类页展开「内容组织」，「分类管理」高亮；滚到底后「人物管理」在系统区上面。
- `modules-390-drawer-active.png`：点「扩展」后地址是 `/admin/modules`，这一项高亮。
- `config-390-drawer.png`：配置页抽屉。
- `xedc-1366-expanded.png`、`xedc-1366-collapsed.png`：桌面展开和收成 72 像素。
- `xedc-1366-collapsed-tip.png`：收起后鼠标停在「配置」上，旁边出现「配置」两个字。
- `admin-390-index.png`、`admin-1366-index.png`：管理首页，手机一列、桌面三列卡片。
- `structure-390-index.png`、`structure-1366-index.png`：结构页，同样是卡片。
- `config-390-index.png`、`config-1366-index.png`：配置页，分组标题加卡片，桌面三列。

### 看过截图后仍不够好的地方

- 分组展开后，中间最后几项要在中间区域里滚动才能看见。滚到底时它们在系统区上方，没有被挡住。
- 侧栏收成图标时，已经折叠的分组里的图标仍排在中间，所以中间图标列会比文字模式更长，需要滚动；底部系统图标保持可见。
- 收起后的悬停文字会压住主区域卡片的一角。
- 配置页的状态错误和「隐藏描述」仍在。
- 上一轮留下的日志横滑、Site Audit 标题、权重按钮、未打开的媒体库弹窗，这次没有改。

---

## 本轮（视觉修复）

只改了 `web/themes/custom/xedc_admin/`。业务模块、Gin、前台主题都没动。

核对过的截图在 `/root/xedc-admin-round2/`，清单在 `XEDC_ADMIN_ROUND2.md`。手机宽度 390，桌面宽度 1366。

收尾时 `system.theme` 的 admin 是 `xedc_admin`。检查过程中这个值曾经变成 gin，已设回 `xedc_admin`。

下面是上一轮的记录，其中「已经换回 gin」「只凭 HTTP 200 算通过」不再当作本轮结论。

---

## 1. 任务完成情况

| 阶段 | 状态 | 说明 |
| --- | --- | --- |
| 阶段 1 审查 | 已完成 | 只读检查、备份、记录主题和前台基线 |
| 阶段 2 创建主题 | 已完成 | 主题已安装，未设为后台主题 |
| 阶段 3 设计系统 | 部分完成 | 筛选、分页、账户菜单位置已在真实页面看到；对话框、加载中、提示条没有点 |
| 阶段 4 Dashboard | 已完成 | `/admin/xedc` 在新主题下 HTTP 200，390/768/1366 已截图 |
| 阶段 5 原生页面 | 部分完成 | 列表和编辑页已打开；媒体库弹窗和编辑器按钮未点 |
| 阶段 6 测试 | 部分完成 | 真实后台页面已截图；抽屉按钮没有在真实页面上点开 |

## 2. 开始前状态

`git status --short`：

```
 M web/modules/custom/xedc_core/config/install/xedc_core.settings.yml
 M web/modules/custom/xedc_core/src/AltManager.php
 M web/modules/custom/xedc_core/src/Form/ImportListForm.php
 M web/modules/custom/xedc_core/src/ImagePipeline.php
 M web/modules/custom/xedc_core/src/ImportProcessor.php
 M web/modules/custom/xedc_core/src/TaskRepository.php
 M web/modules/custom/xedc_core/src/TempCleaner.php
 M web/modules/custom/xedc_core/tests/manual/import-self-test.php
 M web/modules/custom/xedc_core/xedc_core.install
 M web/modules/custom/xedc_core/xedc_core.libraries.yml
 M web/modules/custom/xedc_core/xedc_core.routing.yml
 M web/modules/custom/xedc_home/xedc_home.module
 M web/themes/custom/xedc/xedc.theme
?? AGENTS.md
?? XEDC_ADMIN_PROGRESS.md
?? XEDC_ADMIN_TASK.md
?? web/modules/custom/xedc_admin_ui/
?? web/modules/custom/xedc_core/css/dashboard.css
?? web/modules/custom/xedc_core/src/ImportAbortException.php
```

分支 `main`，与 `origin/main` 同步。没有已暂存的改动。上面 13 个已修改文件的 diff 是 1575 行增加、234 行删除。

`system.theme`：

- 真实在用的后台主题是 gin，前台是 xedc。上一份报告写成 radix，那是误读，这里更正。
- 本次验证开始时的命令输出就是 admin: gin，default: xedc。
- `/root/system_theme_before.txt`（2026-11-01 12:01:33）正文仍是 `admin: radix`。同一分钟的数据库备份 `/var/backups/xedc_before_admin_theme_20261101_120455.sql.gz` 里，`config` 表的 `system.theme` 也写着 `admin=radix`。这两处和「真实在用 gin」不一致，所以不再把 radix 写成恢复目标。

`config/sync/system.theme.yml` 里的 admin 是 `default_admin`，和线上不一致。没有做 config import。

数据库备份：`/var/backups/xedc_before_admin_theme_20261101_120455.sql.gz`（4.3MB，`gzip -t` 通过，库约 80MB）。

前台基线：

| URL | HTTP | sha256 |
| --- | --- | --- |
| https://gitxx.xyz/ | 200 | 22662c509501e3f42c79f69273c6487bcdac51b315536559e2d8d4283b9fcee8 |
| https://gitxx.xyz/bizhi/1 | 200 | 25437c9497adbffb982fc43fff83ed5ef422beade55f808355e7b38d7202e989 |

## 3. 新增文件清单

全部在 `web/themes/custom/xedc_admin/`，外加本报告和进度文件（进度、任务文件在开始前就已存在）。

| 路径 | 用途 |
| --- | --- |
| xedc_admin.info.yml | 主题信息，继承 radix |
| xedc_admin.libraries.yml | CSS/JS 库 |
| xedc_admin.theme | 页面预处理、账户菜单数据、仪表盘样式附加 |
| includes/navigation.theme | 按路由是否存在、当前用户是否有权限生成侧栏 |
| css/base.css | 设计令牌和基础字体 |
| css/layout.css | 侧栏、顶栏、抽屉，并隐藏核心工具栏 |
| css/components.css | 卡片、按钮、徽标、表格、分页、菜单、提示 |
| css/content.css | 内容页、编辑器外壳、媒体库外壳 |
| css/forms.css | 表单控件 |
| css/responsive.css | 390 / 768 / 1366 |
| css/dashboard.css | 控制台网格 |
| css/ckeditor.css | 只给编辑器内容区的字体和颜色 |
| js/navigation.js | 折叠、抽屉、Escape、焦点 |
| js/admin.js | 账户菜单 |
| templates/page/page.html.twig | 后台外壳 |
| templates/navigation/breadcrumb.html.twig | 面包屑 |
| templates/dataset/table.html.twig | 表格横向滚动 |
| templates/views/views-view-table.html.twig | Views 表格横向滚动 |
| templates/xedc-admin-dashboard.html.twig | 控制台视觉覆盖 |
| config/optional/block.block.xedc_admin_*.yml | 标题、标签页、操作、帮助、消息、主内容、面包屑 |

## 4. 修改文件清单

无。

没有改前台 `xedc` 主题、`xedc_core`、`xedc_quick`、`xedc_admin_ui`、`config/sync`、`composer.json`、`composer.lock`、`robots.txt`。

验证时只改了未跟踪目录 `web/themes/custom/xedc_admin/` 里的三个文件：

| 文件 | 原因 |
| --- | --- |
| xedc_admin.theme | 主题不能用 `hook_page_attachments()`，手机抽屉需要的脚本没有输出。改成 `hook_page_attachments_alter()`，页面头部才会加上 `xedc-admin-js` |
| css/layout.css | 小于 992px 时用 Drupal 已有的 `html.js` 把侧栏收成抽屉；隐藏核心导航的 `.top-bar`；内容区过宽时在内部横向滚动，避免整页被撑出屏幕 |
| css/content.css | 编辑器工具栏允许换行，避免手机上把页面撑宽 |

当前 `git diff --stat` 是 16 个已跟踪文件、1643 增、237 删。比上一份报告多出来的是 `composer.json`、`composer.lock`、`web/robots.txt`。这三份在本次验证开始前就已经是修改状态，本次没有再改。`xedc_core` 等原有未提交修改也没有再改。

## 5. 主题结构

- base theme：`radix`（已安装版本 6.0.9）
- 机器名：`xedc_admin`
- 区域：navbar_branding、navbar_left、navbar_right、breadcrumb、header、content、footer、page_bottom
- libraries：`xedc_admin/global` 每个页面加载；`xedc_admin/dashboard` 只在路由 `xedc_admin_ui.dashboard` 附加，并依赖模块里的 `xedc_admin_ui/dashboard`
- JS：Drupal behaviors + `once()`。折叠状态存在 localStorage 键 `xedcAdmin.sidebarCollapsed`
- 模板覆盖：page、breadcrumb、table、views-view-table、xedc-admin-dashboard
- 没有引入 React、Vue、Tailwind，也没有把整份 Bootstrap CSS 铺到全局，避免 Reboot 破坏对话框和编辑器
- CKEditor 5 样式表：`css/ckeditor.css`

安装时写入数据库的区块：面包屑、页面标题、主标签、次标签、本地操作、帮助、状态消息、主内容。这些不在 `config/sync` 里。

## 6. 设计令牌

定义在 `css/base.css`：

```css
--xedc-admin-background: #F8F9FB;
--xedc-admin-surface: #FFFFFF;
--xedc-admin-surface-secondary: #F4F4F5;
--xedc-admin-foreground: #18181B;
--xedc-admin-muted: #71717A;
--xedc-admin-border: #E4E4E7;
--xedc-admin-primary: #2563EB;
--xedc-admin-primary-hover: #1D4ED8;
--xedc-admin-primary-active: #1E40AF;
--xedc-admin-primary-foreground: #FFFFFF;
--xedc-admin-success: #16A34A;
--xedc-admin-warning: #D97706;
--xedc-admin-danger: #DC2626;
--xedc-admin-radius-sm: 6px;
--xedc-admin-radius-md: 8px;
--xedc-admin-radius-lg: 12px;
--xedc-admin-sidebar-width: 240px;
--xedc-admin-sidebar-collapsed: 72px;
--xedc-admin-header-height: 64px;
--xedc-admin-shadow: 0 1px 2px rgba(24, 24, 27, 0.06);
```

同一选择器下还把 `--gin-color-text` 等变量指到上面的令牌，这样现有控制台 CSS 在新主题里颜色一致。这不是 Gin 的 DOM，也不加载 Gin 的库。没有实现深色模式。

## 7. 导航清单

uid 1 实际生成的菜单（`drush php:eval` 切换到该账号后输出）：

| 分组 | 标题 | 路由 |
| --- | --- | --- |
| 工作台 | XEDC 控制台 | /admin/xedc |
| 内容管理 | 全部内容 | /admin/content |
| 内容管理 | 美图 | /admin/content?type=photo |
| 内容管理 | 视频 | /admin/content?type=video |
| 内容管理 | 文章 | /admin/content?type=article |
| 内容管理 | 图片导入 | /admin/content/image-imports |
| 内容管理 | 快速发布 | /admin/quick-gallery |
| 内容组织 | 分类管理 | /admin/structure/taxonomy |
| 内容组织 | 图片栏目 | /admin/structure/taxonomy/manage/photo_category/overview |
| 内容组织 | 视频栏目 | /admin/structure/taxonomy/manage/video_category/overview |
| 内容组织 | 文章栏目 | /admin/structure/taxonomy/manage/article_category/overview |
| 内容组织 | 标签管理 | /admin/structure/taxonomy/manage/tags/overview |
| 内容组织 | 人物管理 | /admin/structure/taxonomy/manage/models/overview |
| 投稿审核 | 审核中心 | /admin/content/moderated |
| 会员管理 | 会员列表 | /admin/people |
| SEO 与网站健康 | SEO Audit | /admin/reports/audit |
| SEO 与网站健康 | Site Audit | /admin/reports/site-audit |
| SEO 与网站健康 | 系统状态 | /admin/reports/status |
| 系统管理 | Drupal 管理首页 | /admin |
| 系统管理 | 配置管理 | /admin/config |
| 系统管理 | 日志与报告 | /admin/reports/dblog |

美图、视频、文章不是独立路由，内容视图的类型筛选标识是 `type`，所以用查询参数。词汇表名称来自配置：图片栏目、视频栏目、文章栏目、标签、人物。

没有创建的菜单：

- 会员投稿。只有前台 `/member/submissions`，没有后台路由。

uid 6（角色 content_editor）只看到：全部内容、美图、视频、文章、图片导入、快速发布、审核中心、Drupal 管理首页、配置管理。看不到控制台，因为该角色没有 `access xedc admin dashboard`。本次没有改权限。

## 8. 验收清单

### A. 主题

| 项 | 结果 |
| --- | --- |
| xedc_admin 已创建 | 通过。目录已存在并已安装 |
| 正确继承 Radix | 通过。`base theme: radix`，安装成功 |
| 主题可以正常启用 | 通过。临时设为 admin 后页面正常，结束后已设回 gin |
| CSS 和 JS 正常加载 | 通过。后台 HTML 的样式和脚本地址带 `theme=xedc_admin`，对应文件 HTTP 200，CSS 里有 `--xedc-admin-primary`，JS 里有抽屉逻辑 |
| 没有影响前台 xedc | 通过。首页哈希与开始前相同。本次只改了后台主题，前台主题文件没有动 |
| 新主题没有 Gin 专属依赖 | 通过。不继承 Gin，不要求 gin 库。只在自己的选择器里提供同名颜色变量，给现有控制台 CSS 用 |
| 视觉符合设计规范 | 通过（抽查）。控制台是白底、蓝主色、1366 下左侧导航。390 下菜单收起、内容单列 |

### B. UI

| 项 | 结果 |
| --- | --- |
| 左侧导航正常 | 通过。1366 截图可见分组菜单，当前项高亮 |
| 导航可以折叠 | 未验证（真实点击）。桌面截图是展开状态。没有点折叠按钮 |
| 手机端抽屉正常 | 部分通过。390 和 768 截图里侧栏默认收起，顶栏有菜单按钮，内容能看全。没有在真实页面上点开这个按钮 |
| 顶部工具栏正常 | 通过。面包屑、查看网站、账户头像在截图里 |
| 面包屑正常 | 通过。控制台显示「首页 / 管理 / 控制台」 |
| Dashboard 卡片正常 | 通过。概览四张卡、内容概览、审核和最近发布都在 |
| 表格样式正常 | 通过。导入页和内容列表能看到表头和行。窄屏表格在内容区里向右延伸，整页没有被撑出去 |
| 表单样式正常 | 通过。编辑页、快速发布页的输入框和按钮在框内。390 的内容列表里「过滤」按钮贴在筛选框右边，看着被裁了一点 |
| 状态徽标正常 | 通过。控制台最近发布有绿色「已发布」 |
| 页面没有横向溢出 | 通过（抽查）。修正抽屉后，控制台、内容、两篇编辑、导入、快速发布、Site Audit 在 390/768/1366 的 scrollWidth 等于 clientWidth |

### C. 功能

| 项 | 结果 |
| --- | --- |
| Dashboard 正常 | 通过。GET `/admin/xedc` 200，卡片和最近发布可见。没有在这个页面里跑扫描 |
| 内容管理正常 | 通过（打开页面）。`/admin/content` 与 `?type=photo` 均为 200，有筛选和分页。没有批量操作 |
| 内容编辑正常 | 部分通过。`/node/1/edit`、`/node/7/edit` 均为 200。没有点保存 |
| 分类和标签管理正常 | 通过（打开页面）。分类总表、三个栏目、标签、人物都是 200。没有新建或删除术语 |
| 会员管理正常 | 通过（打开页面）。`/admin/people` 与 `/user/1/edit` 为 200。没有保存用户 |
| 内容审核正常 | 部分通过。审核列表 200。没有点发布、退回、拒绝 |
| Media Library 正常 | 未验证。编辑页能看到封面图和「移除」，弹窗没有打开 |
| CKEditor 5 正常 | 未验证。文章编辑页已经生成编辑器外壳，没有点工具栏，没有输入 |
| 图片导入正常 | 通过（打开页面）。导入列表 200，原有任务还在。没有新建导入 |
| 快速发布正常 | 通过（打开页面）。表单 200。没有上传，没有创建图集 |
| SEO Audit 正常 | 通过（打开页面）。`/admin/reports/audit` 200。没有打开「全部运行」 |
| Site Audit 正常 | 通过（打开页面）。页面按模块原逻辑显示检查结果。没有点保存或发送。报告实体仍是 5 条 |
| Drupal 原生管理页面正常 | 部分通过。上面这些管理页都能打开。没有把每个配置子页都点一遍 |

### D. 权限和安全

| 项 | 结果 |
| --- | --- |
| 管理员正常 | 通过（打开页面）。uid 1 的一次性登录后，上述后台页都是 200，不是 403 |
| 内容编辑角色正常 | 部分通过。uid 6 的菜单已按权限缩小。没有用浏览器登录 |
| 普通会员无法访问无权限后台 | 通过。匿名访问上述后台路径返回 403 |
| 菜单遵循权限 | 通过。同一套代码对 uid 1 和 uid 6 结果不同 |
| 不泄漏未授权内容 | 部分通过。匿名访问后台仍是 403。编辑角色的菜单过滤上次已测过。这次是用管理员打开新主题页面，没有再用编辑账号登录 |
| 多语言正常 | 未验证。站点语言含 zh-hans、en、ja、ru。导航标题走 Drupal 的 `t()`，SEO Audit / Site Audit 使用固定英文，避免被译成「现场审核」 |

### E. 项目保护

| 项 | 结果 |
| --- | --- |
| 没有覆盖原有未提交修改 | 通过。diff 统计与开始前相同 |
| 没有删除原有文件 | 通过 |
| 没有修改 config/sync | 通过。git status 没有这些文件 |
| 没有执行 config import | 通过 |
| 没有卸载 Gin | 通过。Gin 仍启用 |
| 没有重写图片导入模块 | 通过 |
| 没有重写快速发布模块 | 通过 |
| 没有擅自提交 Git | 通过 |
| 开始前数据库备份已完成 | 通过 |
| 开始前 system.theme 配置已记录 | 通过。真实后台是 gin。文件和备份里还留着 radix 字符串，见第 2 章 |
| 回滚命令已验证可用 | 通过。验证结束执行了切回 gin 并 `drush cr`，`config:get` 确认 admin 是 gin。输出见第 13 章 |

## 9. 测试记录表

| 测试项 | 命令或操作 | 实际结果 | 状态 |
| --- | --- | --- | --- |
| PHP 语法 | `php -l` theme 与 navigation.theme | No syntax errors | 通过 |
| YAML | Symfony Yaml::parseFile | 10 个文件 OK | 通过 |
| Twig | Twig parse | 5 个模板 OK | 通过 |
| JS 语法 | `node --check` | NODE_OK | 通过 |
| 主题安装 | `drush theme:install xedc_admin -y` | Successfully installed | 通过 |
| 安装后主题 | `drush config:get system.theme` | admin=gin，default=xedc | 通过（读数）。与开始前 radix 不同，见第 15 章 |
| 静态资源 | curl 主题 CSS/JS | 全部 200 | 通过 |
| 缓存与容器 | `drush cr`；`drush status` | Cache rebuild complete；bootstrap Successful | 通过 |
| 导航 uid 1 | `drush php:eval` 切换账号后生成链接 | 7 组 22 个链接 | 通过 |
| 导航 uid 6 | 同上 | 3 组，无控制台、分类、会员、Audit | 通过 |
| 匿名权限 | curl 后台路径 | 403；首页和登录 200 | 通过 |
| 折叠 / 抽屉 / 账户菜单 | Chrome 对静态夹具执行 JS | 折叠可开关；抽屉 Escape 关闭；菜单 Escape 关闭 | 通过（夹具） |
| 390/768/1366/1920 | Chrome 截图 | 无横向溢出 | 通过（夹具） |
| 前台首页 | curl + sha256 | 200，哈希与基线相同 | 通过 |
| 前台内容页 | curl + 对比资源 | 200；仅 form_build_id 变化；无 xedc_admin 资源 | 通过 |
| 临时切换 | `drush config:set system.theme admin xedc_admin -y` 然后 `drush cr` | 读到 admin: xedc_admin，default: xedc | 通过 |
| 登录 | `drush user:login --uri=https://gitxx.xyz --uid=1`，再用 curl 保存 cookie | 日志：Session opened for admin。后续页面 200。登录地址没有写入本报告 | 通过 |
| /admin/xedc | curl GET | 200，标题「控制台 \| DcLili」，有侧栏和设计令牌 CSS | 通过 |
| /admin/content 与 ?type=photo | curl GET | 200，有筛选表单和分页 | 通过 |
| /node/1/edit、/node/7/edit | curl GET | 200。美图页有媒体库小部件，文章页有 `.ck-editor`。未保存 | 通过（打开） |
| 分类、栏目、标签、人物 | curl GET 六个地址 | 全部 200 | 通过（打开） |
| /admin/people、/user/1/edit | curl GET | 200 | 通过（打开） |
| /admin/content/moderated | curl GET | 200，标题「等待审核的内容」 | 通过（打开） |
| 图片导入、快速发布 | curl GET | 200。未上传、未创建 | 通过（打开） |
| SEO Audit | curl GET `/admin/reports/audit` | 200。未访问 run-all | 通过（打开） |
| Site Audit | curl GET `/admin/reports/site-audit` | 200，页面显示出检查结果。`site_audit_report` 仍是 5 行 | 通过（打开） |
| 样式和脚本 | curl 带 `theme=xedc_admin` 的聚合文件 | 抽查全部 HTTP 200。CSS 含 `--xedc-admin-primary`，JS 含抽屉代码。没有 gin 主题路径 | 通过 |
| 报错日志 | `watchdog` 中大于切换后最大 wid 的记录 | 页面访问期间没有 severity≤4 的新错误。另有一条命令行通知，见第 15 章 | 通过 |
| 390/768/1366 | 本机 Chromium 156 headless 截图 | 修正抽屉后，抽查页面 scrollWidth 等于 clientWidth。截图在 `/root/xedc-admin-verify-shots/` | 通过 |
| Media Library 弹窗 | 未点击 | 见第 16 章 | 未验证 |
| CKEditor 按钮和输入 | 未点击 | 外壳已出现，见第 16 章 | 未验证 |
| 切回 gin | `drush config:set system.theme admin gin -y`；`drush cr`；`drush config:get system.theme` | admin: gin，default: xedc。见第 13 章 | 通过 |

## 10. Gin 专属选择器清单

切换稳定后可以清理，本次没有改这些文件。

| 位置 | 选择器或用法 |
| --- | --- |
| web/modules/custom/xedc_admin_ui/css/tokens.css | `body[data-gin-accent]`、`html.gin--dark-mode body[data-gin-accent]`，以及一整组 `--gin-*` 变量 |
| web/modules/custom/xedc_admin_ui/css/dashboard.css | `var(--gin-color-text)`、`--gin-bg-layer`、`--gin-border-color`、`--gin-color-primary` 等，都带了同色回退 |
| web/modules/custom/xedc_admin_ui/xedc_admin_ui.module | 只在活动主题或其父主题是 gin 时附加 tokens 库 |
| web/modules/custom/xedc_admin_ui/xedc_admin_ui.info.yml | 描述文字写的是 Gin |

其他自定义模块里没有 `data-gin` 或 `gin--dark-mode`。

## 11. Gin 配套模块处理

- `gin_toolbar`：已安装，状态 Disabled。不会输出工具栏。没有启用，也没有卸载。
- 没有 gin_login。
- Gin 主题 5.0.16 仍启用。当前读到的 admin 主题是 gin，所以线上后台如果正在用 Gin，Gin 自己的工具栏会显示。这与新主题无关，因为新主题的 CSS 只有在 xedc_admin 成为活动主题时才加载。
- 核心 `navigation` 和 `toolbar` 仍启用。xedc_admin 生效后，`layout.css` 只在 `html.xedc-admin-active` 下隐藏 `#toolbar-administration`、`.admin-toolbar`、`.admin-toolbar-control-bar` 和节点页上的 `.top-bar`，避免和自带侧栏叠在一起。模块没有卸载。
- 在 gin 为后台主题时，这段 CSS 不加载，原有工具栏不受影响。

## 12. Git 状态

结束后的 `git status --short` 与开始前相比，唯一多出来的未跟踪路径是：

- `XEDC_ADMIN_REPORT.md`
- `web/themes/custom/xedc_admin/`

本次验证没有把任何文件加入暂存，也没有提交。`xedc_admin` 仍是未跟踪目录。已跟踪文件的 diff 见第 4 章：原有业务修改还在，composer 和 robots.txt 本次没有再改。

## 13. 当前主题状态

验证结束时执行：

```bash
cd /var/www/drupal
vendor/bin/drush config:set system.theme admin gin -y
vendor/bin/drush cr
vendor/bin/drush config:get system.theme
```

`config:set` 的提示是 “Do you want to update admin key in system.theme config?: yes.”，接着是 `[success] Cache rebuild complete.`

`config:get system.theme` 的输出：

```
_core:
  default_config_hash: eJ529VM1gSIA_vgTy2PdiDvJuG0xhSxfQjCyl5WKlv4
admin: gin
default: xedc
```

- default：xedc
- admin：gin
- xedc_admin：已安装，不是 admin，也不是 default
- Gin：仍安装并启用
- 数据库备份仍在：`/var/backups/xedc_before_admin_theme_20261101_120455.sql.gz`（本次再次 `gzip -t` 通过）

## 14. 启用步骤与回滚步骤

启用新后台（会改变线上后台，请你自己执行）：

```bash
cd /var/www/drupal
vendor/bin/drush config:set system.theme admin xedc_admin -y
vendor/bin/drush cr
vendor/bin/drush config:get system.theme
```

然后按第 16 章点媒体库和编辑器。

看完切回 gin（这是应该保持的后台主题）：

```bash
cd /var/www/drupal
vendor/bin/drush config:set system.theme admin gin -y
vendor/bin/drush cr
vendor/bin/drush config:get system.theme
```

不要执行 `drush config:import`，不要改 `config/sync`，不要卸载 Gin，不要把后台改成 radix。

## 15. 已知问题与风险

- 上一份报告把恢复目标写成 radix。更正后，恢复目标只写 gin。`/root/system_theme_before.txt` 和 12:04 的数据库备份里仍然能看到 `admin: radix` 这几个字，打开文件时不要被它带去执行切到 radix。
- `config/sync/system.theme.yml` 仍是 `default_admin`。不要用 config import 对齐。
- 内容编辑角色没有 `access xedc admin dashboard`，侧栏不显示控制台。若要给他们看，需要在权限页勾选，本次没有改。
- 媒体库弹窗和编辑器按钮还没点。图片导入、快速发布、审核只确认了页面能打开，没有提交。
- 390px 的内容列表里，「过滤」按钮贴在筛选框右边缘，看着被裁掉一点。整页没有横向滚动条。
- Site Audit 标题在页面上连成「Site Auditgitxx.xyz」，中间少一个空格。这是该模块自己的输出，本次没有改那个模块。
- 手机抽屉在真实页面上只看到了收起状态和菜单按钮，没有点开。
- 验证窗口里 `users_field_data` 从 10 行变成 9 行。watchdog wid 16555 是通知（severity 5），内容是删除用户 `perf-test-r3 <perf-test-r3@example.com>`，location 为 `http://default/`，主机 127.0.0.1，uid 0。这是命令行产生的，不是本次对 `https://gitxx.xyz` 的 GET。本次没有执行删除用户。节点数一直是 272，`site_audit_report` 一直是 5。
- 核心导航是用 CSS 隐藏的，只在新主题页面上生效。若再看到双层侧栏，用第 14 章命令回到 gin。

## 16. 未完成事项与后续建议

需要你亲手点的只有媒体库弹窗和编辑器。程序只能把页面打开，不能代替这两下点击。看的时候后台要临时换成 xedc_admin，看完按第 14 章切回 gin。

### 媒体库

1. 在服务器执行第 14 章的启用命令，然后刷新后台。左上角应变成带 X 标志的 DcLili，而不是原来的 Gin。
2. 用管理员登录。
3. 打开「内容」，找到美图「海边风景手机壁纸 测试」，点「编辑」。地址是 `/node/1/edit`。
4. 找到「图片封面」。现在下面已经有一张海边的图，旁边有「移除」。先不要点移除。
5. 向下找到「图片集」。点打开媒体库的按钮（文字一般是「添加媒体」）。
6. 弹窗出来以后看这几件事：窗口是否盖住页面而不是挤在角落；搜索框能不能打字；图片能不能翻页看到；点一张图，它是否出现选中状态；点确定或插入后，弹窗是否关掉，编辑页是否还在，选中的图是否出现在「图片集」里。
7. 点弹窗外的暗处，或者关闭按钮，看窗口是否关掉。
8. 不要点页面底部的「保存」。
9. 执行第 14 章的切回 gin 命令。再打开同一页，确认后台已回到原来的样子。

### 编辑器

1. 同样先启用 xedc_admin。
2. 打开文章「小墨迹文章图片简介图」的编辑页，地址是 `/node/7/edit`。美图编辑页没有这个编辑器，不要在美图页里找。
3. 页面向下滚，找到「文章内容正文」。
4. 看正文框上面有没有一排按钮（加粗、列表、链接这类）。按钮应换行，不要伸出屏幕右边。
5. 在正文里点一下，打两个字，看字是否出现在框里。
6. 点链接按钮，看小窗口是否完整，能否关掉。有图片或表格按钮的话，也点开看一眼。
7. 不要点保存。看完切回 gin。

其余建议：

1. 手机上再点一次左上角菜单按钮，看侧栏是否从左边滑出，点暗处或按 Esc 是否关掉。这次只看到了收起后的样子。
2. 有问题立刻切回 gin，不要卸载主题。
3. 稳定之后，再决定是否删除 `xedc_admin_ui` 里的 Gin 选择器。现在不要删，因为 gin 仍是当前后台。
4. 若内容编辑也要看控制台，给他们 `access xedc admin dashboard`。这是权限变更，需要你点头后再做。
5. 不要把业务逻辑搬进主题。统计、导入、快速发布仍留在各自模块。
