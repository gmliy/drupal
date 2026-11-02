# XEDC 后台主题报告

第七轮还没做完。清单在 `XEDC_ADMIN_ROUND7.md`，截图在 `/root/xedc-admin-round7/`。已经看过的是：表格里的「编辑 ▾」改成独立浮层（手机是从底部滑上来的面板，删除是红字），手机上的表格可以左右滑，机读名称不再被编辑按钮压住。弹窗、进度条、添加内容页和全站扫描还没做。下面仍是第六轮及更早的说明。

## 给非技术人员的总结

后台还是新皮肤，没有换回 Gin。

用管理员打开网站首页时，左边那条带 Drupal 水滴和绿色按钮的旧菜单已经拿掉。首页恢复成原来的全宽。左上方多了一个小圆按钮，点开后是和后台一样的抽屉菜单。没登录的访客看不到这个按钮，首页和改之前是同一份页面。

后台的进度条做成了白卡片：灰色细轨道、深色填充、百分比在右边。深色背景下也能看清。这次没有真的去跑「运行所有审核」，因为那个动作会写审核结果，所以进度条是在控制台页面上临时放了一条来看样式。

又看了一批后台页。视图编辑里原来发黄的行改成了浅灰。手机上的区块布局能看全区域名称和「配置」。管理员在前台打开一篇内容时，标题上面有查看、编辑这些标签。

还不够好的地方：手机首页上，小圆按钮会压住第一张大图的角。进度条的流动动画没法在一张静止截图里证明。编辑器、媒体库、权限大表和深色桌面还没逐页看完。

想暂时换回原来的 Gin，并卸掉首页这个新按钮，在服务器上执行：

```
cd /var/www/drupal
vendor/bin/drush pmu xedc_admin_nav -y
vendor/bin/drush config:set system.theme admin gin -y
vendor/bin/drush cr
```

看完如果还要新皮肤，把 `gin` 改成 `xedc_admin`，再执行 `vendor/bin/drush en xedc_admin_nav -y` 和 `vendor/bin/drush cr`。

这一轮的清单在 `XEDC_ADMIN_ROUND6.md`，截图在 `/root/xedc-admin-round6/`。再往下是更早几轮的记录。

## 第六轮：前台菜单和进度条

新模块 `xedc_admin_nav` 已启用。数据库备份在 `/var/backups/xedc_before_admin_nav_20261101_230614.sql.gz`。收尾时 `system.theme` 的 admin 仍是 `xedc_admin`。没有改前台主题 `web/themes/custom/xedc/`，也没有改其他旧模块。

### 前台菜单

首页那条侧栏来自核心模块 `navigation` 的 `hook_page_top()`。能看到它的人是拥有 `access navigation` 的用户：超级管理员，以及内容编辑角色。匿名和普通会员没有这条权限。前台主题没有输出它。

模块在前台主题 `xedc` 上拿掉 `page_top` 里的 `navigation` 和 `top_bar`，再渲染后台同一份菜单。菜单函数仍是 `web/themes/custom/xedc_admin/includes/navigation.theme` 里的 `_xedc_admin_navigation()`，没有复制一份菜单树。

涉及文件：

- `web/modules/custom/xedc_admin_nav/xedc_admin_nav.info.yml`
- `web/modules/custom/xedc_admin_nav/xedc_admin_nav.libraries.yml`
- `web/modules/custom/xedc_admin_nav/src/Hook/FrontNavHooks.php`
- `web/modules/custom/xedc_admin_nav/templates/xedc-admin-nav-front.html.twig`
- `web/modules/custom/xedc_admin_nav/css/front.css`
- `web/themes/custom/xedc_admin/xedc_admin.libraries.yml`（新增 `drawer` 库，只含抽屉样式和 `navigation.js`）
- `web/themes/custom/xedc_admin/css/layout.css`（前台外壳不再被当成整页，深色箭头在抽屉自己的深色标记下也生效）
- `web/themes/custom/xedc_admin/js/navigation.js`（前台不把输入方式写到整页 `html` 上；抽屉打开时锁住主内容）

前台只加载 `xedc_admin/drawer` 和 `xedc_admin_nav/front`。匿名首页启用前后字节数相同。

前台主题没有本地任务区块，标签页原先只出现在被拿掉的顶栏里。模块在内容区最前面补上同一组标签，样式在 `front.css` 的 `.xedc-front-tasks`。没有 `access navigation` 的访客不会得到这块。

### 进度条和加载动画

样式文件：`web/themes/custom/xedc_admin/css/progress.css`，挂在后台主题全局库上。包含 `.progress` 各部分、`.ajax-progress-throbber`、`.ajax-progress-fullscreen`，以及减弱动效。真实批处理页没打开，原因和夹具截图写在第六轮清单里。

### 这轮顺手改的后台样式

- 手机有面包屑时隐藏顶栏站名。
- 手机输入框字号 16px，避免聚焦时页面被放大。
- 表单标签字重 500。
- 手机主按钮最小高度 44px。人员页上第一个按钮量到仍是 40px，还没对上控件。
- 视图编辑的隔行底色不再用核心那条米黄，改成浅灰。
- 视图列表的机器名称放进卡片内部横滑，不再被裁成几个字母。
- 手机区块布局里，区域下拉和「配置」排在同一屏里。

### 仍未解决

- 手机首页圆按钮压住首图左上角。
- 进度条动画、上传进度、Media Library 的加载提示没有真实操作截图。
- 深色和多数桌面宽度还没逐张看。编辑器、媒体库、权限表没开始。
- 人员页有一个按钮量到 40px，样式写的是最小 44px。

### 需要你手动检查

这些都会保存数据，我没有点。

1. 打开一篇已有内容的编辑页，改一个字，点保存。看是否保存成功，顶栏和侧边标签是否还在。
2. 在编辑页上传一张图片，看进度条是否出现，传完能否在正文里看到。
3. 点「添加媒体」，在弹窗里搜一张图，选中，插入，再保存内容。
4. 在内容列表勾两篇，选一个批量动作，但先不要确认执行。只看下拉和确认框是否完整、手机上是否点得到。
5. 在人员页改一个角色的权限，点保存，再打开看勾选是否还在。
6. 扩展页不要真的安装或卸载。只展开一个模块，看复选框和说明是否被裁切。
7. 站点审核里的「运行所有审核」会重跑并可能写入报告。需要的话你自己点一次，看进度是否是白卡片而不是一行裸百分比。
8. 用没有管理权限的账号打开首页，确认没有小圆按钮，页面和退出登录时一样。

## 第五轮：网站配置的二、三级

只改了 `web/themes/custom/xedc_admin/`。收尾时 `system.theme` 的 admin 仍是 `xedc_admin`。

### 数据来源

「网站配置」不再写死链接。主题用 Drupal 菜单树读取管理菜单 `admin` 里 `system.admin_config` 下面的链接，也就是 `/admin/config` 上的那些分组和它们的配置页。参数是：以该链接为根、不包含根自己、只取启用的链接、相对深度最多 2。这样只出现分组和分组下的配置页，第四级及更深的链接不加载，那些页面自己有标签页。

访问检查用菜单树的 `checkAccess` 处理器。没有权限的链接不显示；一个分组在过滤之后如果没有可见子项，整组不显示。排序用 `generateIndexAndSort`。上一轮已经从配置索引里隐藏 `site_audit_report_entity.settings_form`（`/admin/config/system/site-audit-report-entity`），所以这里用同一条规则把它排除。系统分组的截图里是 SMTP、网站基本设置、邮件系统、统计、马托莫分析、Cron，没有「现场审核报告实体」。

「马托莫分析」会出现在「系统」下面。它是配置菜单里真实的设置页，不是上一轮拒绝加入站点管理的分析报表。

面板顶部的「全部配置」指向 `/admin/config`。只有正好停在这个地址时，这一行才算当前页。地址在 `/admin/config` 下面任何地方时，一级的「网站配置」都高亮。打开面板时，当前页所在的分组自动展开，当前页高亮。已在 `/admin/config/people/accounts` 上看过：抽屉关闭后重新打开，「人员」是展开的，「帐户设置」是高亮的。

### 缓存

读菜单时把每条链接和每次访问检查结果上的缓存元数据收进页面。另外固定加上菜单标签 `config:system.menu.admin`，以及缓存上下文 `user.permissions`、`route`、`languages:language_interface`。这些在 `hook_preprocess_page()` 里合并进页面的 `#cache`，不绕过 Drupal 缓存，也不在 Twig 里查数据库。权限不同的用户不会共用同一份菜单。快捷方式仍用原来的用户上下文和快捷方式集标签。

### 截图

目录 `/root/xedc-admin-round5/`。看过的图和没通过的项写在 `XEDC_ADMIN_ROUND5.md`。手机抽屉宽 232，一级箭头距文字 10 像素、宽 14 像素、颜色 `#A1A1AA`。二级文字距面板左缘 46 像素，和一级文字对齐；标题和返回箭头距左缘 20 像素，和一级图标对齐。三级再往右 12 像素。「SMTP(邮件传输)认证支持」在手机上折成两行，没有被截断。

### 仍然不满意

电脑上「网站配置」面板最高是窗口高度减去顶栏（测到 `max-height: 744px`）。子项最多的「媒体」展开后面板高 733 像素，内容没有超出，滚动位置仍是 0，背景页面也没有被带动。所以「面板内部滚动」没有截图能证明它真的滚过，清单里不标完成。手机抽屉在展开「系统」后内容高 856、可视 664，滚了 160 像素，顶部返回和关闭留在原地，背后的页面没有动。

手机打开账户设置时，本地任务那一排标签在 390 像素宽里显示不全。文档本身的横向滚动宽度和可视宽度相同，没有整页横滑。这不是这次菜单改出来的，这轮没有改标签样式。

没有用真机 iPhone。配置页红色状态提示和 Site Audit 标题粘连没有重测。

## 第四轮菜单

只改了 `web/themes/custom/xedc_admin/`。收尾时 `system.theme` 的 admin 仍是 `xedc_admin`。不存在或当前用户打不开的入口不会显示。快捷方式只读取当前用户的快捷方式集，缓存带上用户上下文和该快捷方式集的缓存标签。

| 名称 | 路由 | 路径 | 权限 |
| --- | --- | --- | --- |
| 控制台 | `xedc_admin_ui.dashboard` | `/admin/xedc` | `access xedc admin dashboard` |
| 快捷方式 | 当前用户的快捷方式集；没有条目时直接去 `entity.shortcut_set.customize_form` | 各快捷方式自己的地址；管理页 `/admin/config/user-interface/shortcut/manage/{set}/customize` | 每条快捷方式用它自己的访问检查。管理链接要能更新这个快捷方式集（`customize shortcut links` 或 `administer shortcuts`）。既没有快捷方式又不能管理时，整项不显示 |
| 内容管理 → 内容列表 | `system.admin_content` | `/admin/content` | `access content overview` |
| 内容管理 → 创建内容 | `entity.node.add_page` | `/node/add` | 可以创建任意内容类型 |
| 内容管理 → 图片导入 | `xedc_core.import_list` | `/admin/content/image-imports` | `import photo archives` 或 `administer xedc image imports` |
| 内容管理 → 快速发布 | `xedc_quick.page` | `/admin/quick-gallery` | `administer nodes` |
| 内容分类 → 分类管理 | `entity.taxonomy_vocabulary.collection` | `/admin/structure/taxonomy` | `administer taxonomy` 或 `access taxonomy overview` |
| 内容分类 → 图片栏目、视频栏目、文章栏目、标签管理、人物管理 | `entity.taxonomy_vocabulary.overview_form` | `/admin/structure/taxonomy/manage/{词汇}` | 该词汇的分类访问检查。词汇不存在或无权则不显示 |
| 用户管理 → 会员管理 | `view.user_admin_people.page_1` | `/admin/people` | `administer users` |
| 用户管理 → 内容审核 | `view.moderated_content.moderated_content` | `/admin/content/moderated` | `view any unpublished content` |
| 站点管理 → 系统状态 | `system.status` | `/admin/reports/status` | `administer site configuration` |
| 站点管理 → 服务器监控 | `xedc_core.monitor` | `/admin/reports/xedc-monitor` | `access xedc server monitor` |
| 站点管理 → 文件统计 | `xedc_core.files` | `/admin/reports/xedc-files` | `access xedc file statistics` |
| 站点管理 → SEO Audit | `audit.reports` | `/admin/reports/audit` | `view audit results` |
| 站点管理 → Site Audit | `site_audit.report` | `/admin/reports/site-audit` | `administer site configuration` |
| 站点管理 → 日志与报告 | `dblog.overview` | `/admin/reports/dblog` | `access site reports` |
| 扩展 | `system.modules_list` | `/admin/modules` | `administer modules` |
| 结构 | `system.admin_structure` | `/admin/structure` | `access administration pages`。分类页仍算「内容分类」，不算这里 |
| 外观 | `system.themes_page` | `/admin/appearance` | `administer themes` |
| 网站配置 | `system.admin_config` | `/admin/config` | `access administration pages` |
| 管理中心 | `system.admin` | `/admin` | `access administration pages` |

没有做成菜单的项：Matomo 分析。原来的运维中心没有这一页。全站只有 Matomo 的跟踪代码设置页 `matomo.admin_settings_form`（`/admin/config/system/matomo`，权限 `administer matomo`）。配置索引里它显示为「马托莫分析」，那是设置，不是分析报表，所以没有放进抽屉。

### 旧名称到新名称

| 原来 | 现在 |
| --- | --- |
| XEDC 控制台 | 控制台 |
| 全部内容 | 内容列表 |
| 美图、视频、文章 | 从菜单删除，页面还在 |
| 图片导入、快速发布 | 仍在内容管理里 |
| 内容组织及其子项 | 内容分类，子项保留 |
| 投稿审核 / 审核中心 | 用户管理里的内容审核 |
| 会员管理 / 会员列表 | 用户管理里的会员管理 |
| SEO 与网站健康 | 组删除。SEO Audit、Site Audit 改到站点管理 |
| 运维中心 | 组删除。服务器监控、文件统计改到站点管理 |
| 扩展、外观 | 仍是一级，名称不变 |
| 配置 | 网站配置 |
| 系统状态、日志与报告 | 改到站点管理 |
| Drupal 管理首页 | 管理中心 |

### 现场审核报告实体

模块 `site_audit_report_entity`，路由 `site_audit_report_entity.settings_form`，表单 `SettingsForm`。唯一的输入框叫「示例」，写进配置 `site_audit_report_entity.settings` 的 `example`。除了这个表单自己，没有代码读取它。审计报告是实体 `site_audit_report`，列表在 `/admin/reports/site-audit/reports`，这次没动。

因为没有人读这个值，而且它不影响报告的生成、查看和保存，所以只在主题里把这一条从 `/admin/config` 的索引中滤掉。网址 `/admin/config/system/site-audit-report-entity` 仍能打开。配置页系统分组的截图里已经没有这项。Site Audit 页面和原来的 5 条报告都还在。

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
