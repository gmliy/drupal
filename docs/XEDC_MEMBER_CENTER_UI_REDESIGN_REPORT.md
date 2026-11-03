# XEDC 会员中心 UI 重构报告

日期：2026-11-02  
范围：前台会员中心视觉与交互。路由、权限、表单提交、实体保存和数据库均未改。

## 1. 会员中心页面清单

路由定义在 `web/modules/custom/xedc_member/xedc_member.routing.yml`。已登录会员访问 `/member/*` 时，别名处理器改写到公开路径 `/{slug}`（测试账号为 `/12shh`）。内部路由不变。

| 页面 | 路由 | 公开路径 | 模板 |
| --- | --- | --- | --- |
| 会员概览 | `xedc_member.overview` | `/member` | `xedc-member-overview.html.twig`，外壳 `xedc-member-shell.html.twig` |
| 我的收藏 | `xedc_member.favorites` | `/member/favorites` | `xedc-member-favorites.html.twig`，卡片 `xedc-member-card.html.twig` |
| 我的点赞 | `xedc_member.likes` | `/member/likes` | `xedc-member-likes.html.twig` |
| 我的下载 | `xedc_member.downloads` | `/member/downloads` | `xedc-member-downloads.html.twig` |
| 会员等级 | `xedc_member.membership` | `/member/membership` | `xedc-member-membership.html.twig` |
| 浏览历史 | `xedc_member.history` | `/member/history` | `xedc-member-history.html.twig` |
| 清空浏览历史 | `xedc_member.history_clear` | `/member/history/clear` | Drupal 确认表单，类名 `xm-confirm-form` |
| 我的评论 | `xedc_member.comments` | `/member/comments` | `xedc-member-comments.html.twig` |
| 我的投稿 | `xedc_member.submissions` | `/member/submissions` | `xedc-member-submissions.html.twig` |
| 个人资料 | `xedc_member.profile` | `/member/profile` | `xedc-member-profile.html.twig`，`ProfileForm` |
| 账户安全 | `xedc_member.security` | `/member/security` | `xedc-member-security.html.twig` |
| 账号设置 | `xedc_member.settings` | `/member/settings` | 302 到账户安全。模板保留，当前路由不渲染它 |
| 别名检查 | `xedc_member.alias_check` | `/xedc-member/alias-check` | JSON，无页面 |
| 别名拒绝 | `xedc_member.alias_denied` | `/xedc-member/alias-denied` | 访问拒绝，无会员外壳 |
| 投稿美图 / 视频 / 文章 | `node.add` | `/node/add/photo`、`/video`、`/article` | 节点表单，类名 `xm-submission-form` |
| 投稿编辑 | `entity.node.edit_form` | `/node/{nid}/edit` | 同上 |

`MemberController::placeholder()` 仍在代码里，现有路由已改用正式页面，本次没有删除该方法。

样式源文件在主题 `web/themes/custom/xedc/src/scss/member/`，编译进 `web/modules/custom/xedc_member/css/member.css`，由库 `xedc_member/member` 加载。深色模式沿用导航脚本对 `html[data-bs-theme]` / `html[data-theme]` 的切换。

## 2. 双固定导航：原因和修复

手机端同时出现两层顶栏：

- 全站导航 `.xn`（`web/themes/custom/xedc/css/xedc-navbar.css`）是 `position: sticky; top: 0; z-index: 1030`。
- 会员中心工具栏 `.xm-topbar`（菜单按钮、标题「会员中心」、返回首页）在 `@media (max-width: 1023px)` 里也是 `position: sticky`，并且 `top: calc(72px + var(--drupal-displace-offset-top))`、`z-index: 20`。基础样式里同样写了 sticky。

两层都粘在视口顶部，手机首屏被两套导航占掉。面包屑本身不是 sticky。

修复只作用于会员工具栏：

- `.xm-topbar` 改为 `position: static`，并加 `!important`，清掉 `top` 和 `z-index`。
- 去掉为了贴住全站导航而加的负边距、模糊背景。
- 面包屑、`.page__header` 在 `body.xm-page` 下保持 `position: static`。
- 没有用大段 `padding-top` 去垫开第二层。
- 全站 `.xn` 没有改。首页检查：导航仍是 sticky，页面上没有 `.xm-topbar`。

仍然保留的定位：

- 桌面左侧会员导航 `.xm-side` 是栏内 `position: sticky`，随内容区停留，不是第二条顶栏。1024px 及以上 `.xm-topbar` 为 `display: none`。
- 手机抽屉 `.xm-side` 和遮罩 `.xm-backdrop` 仍是 `position: fixed`。抽屉是滑出层，不是顶栏。高度 `100dvh`，并加了 `env(safe-area-inset-top/bottom)`。

Chromium 实测，滚动 500px 后全站导航仍贴在 `top: 0`，会员工具栏跟着文档上移（390px 视口上移 148px）。两者不再叠成双固定条。

## 3. 实际修改的文件

表现层：

- `web/themes/custom/xedc/src/scss/member/member.scss`
- `web/themes/custom/xedc/src/scss/member/_member-tokens.scss`
- `web/themes/custom/xedc/src/scss/member/_member-layout.scss`
- `web/themes/custom/xedc/src/scss/member/_member-components.scss`
- `web/themes/custom/xedc/src/scss/member/_member-responsive.scss`
- `web/themes/custom/xedc/src/scss/member/_member-public.scss`
- `web/modules/custom/xedc_member/css/member.css`（SCSS 编译结果）
- `web/modules/custom/xedc_member/js/member.js`
- `web/modules/custom/xedc_member/xedc_member.libraries.yml`（库版本 2.0，CSS weight 200）
- `web/modules/custom/xedc_member/templates/xedc-member-shell.html.twig`
- `web/modules/custom/xedc_member/templates/xedc-member-overview.html.twig`
- `web/modules/custom/xedc_member/templates/xedc-member-downloads.html.twig`
- `web/modules/custom/xedc_member/templates/xedc-member-likes.html.twig`
- `web/modules/custom/xedc_member/templates/xedc-member-comments.html.twig`
- `web/modules/custom/xedc_member/templates/xedc-member-submissions.html.twig`

为了让样式能套上现有页面，有两处非业务改动：

- `MemberController::downloads()` 每一行增加展示字段 `status_id`，原有 `status` 文案不变。
- `xedc_member_preprocess_html()` 在会员投稿的新增/编辑页给 `body` 增加 `xm-submission-page`。

未改 `config/sync`、Drupal 核心、第三方模块，也没有改首页、图片、视频、文章列表模板。`xedc.libraries.yml`、`engagement.js` 和 `xedc_core` 上已有其他未提交修改，本次没有动它们。

## 4. 设计 Token

Token 写在 `_member-tokens.scss`，只挂在 `body.xm-page`、`body.xm-submission-page`、`.xm`、`.xm-submission-form`、`.xm-confirm-form`。深色覆盖使用现有的 `html[data-bs-theme="dark"]` / `html[data-theme="dark"]`。

包含：

- 颜色：画布、卡片、文字、次级文字、边框、主按钮、危险、成功、警告、信息。
- 间距：`--xm-space-1` 到 `--xm-space-5`。
- 圆角：12px / 8px。
- 阴影：卡片浅阴影和抽屉投影。
- 字体：`--xm-font`。
- 按钮高度：36px，列表内轻量按钮 30px。
- 表单：控件高度 36px，聚焦环。
- 卡片：内边距 token。
- 状态：成功 / 警告 / 危险徽章色。

浅色主色是锌色 `#18181b`。深色下主按钮反转为浅底深字。Gin 后台和首页、图片、视频、文章页不使用这些 token。内容页上的收藏按钮仍走 `_member-public.scss`，会员页内部会取消那套局部变量，改用会员 token。

## 5. 按钮

会员中心按钮统一高度、字号、字重、8px 圆角、无浏览器默认外观（`appearance: none`）。主提交使用深底白字；次要操作是白底描边；列表内轻操作是 Ghost；删除和清空是红色文字加淡红描边，不再铺大块实心红。

| 变体 | 用在 |
| --- | --- |
| Primary | 编辑资料、保存、投稿美图、文章「保存」 |
| Secondary / Outline | 投稿视频、投稿文章、返回、添加媒体、预览、上传区按钮 |
| Ghost | 评论列表里的「查看评论」「编辑」 |
| Danger outline | 清空历史、历史卡片上的「删除」 |
| Outline 轻量 | 「取消收藏」「取消点赞」，高 30px |
| Icon | 手机端打开抽屉、返回首页，36×36 |

主题的提交按钮模板会给全站 `input[type=submit]` 加上 `btn btn-primary`。会员页里用更高优先级把列表里的取消收藏、取消点赞、删除从这套主按钮里摘出来。投稿表单里，只有 `#edit-submit` 保持主按钮；媒体库和表格权重按钮用描边次按钮。CKEditor 工具栏按钮排除在这套覆盖之外，避免编辑器按钮被改成表单按钮。

「编辑资料」在手机上保持标题同一行的小按钮，不再拉成通栏。表单里的保存按钮在窄屏仍可以撑满操作区。

原有 `type=submit`、链接、Drupal Form API 和 AJAX 属性都还在，没有改提交处理。

## 6. 表单

资料、安全、清空历史、投稿表单共用同一套控件：标签、36px 输入框、聚焦边框、说明文字、错误态。文件选择按钮用 `::file-selector-button` 收成 32px 描边按钮。头像上传仍走原来的自动提交脚本。

没有删除 Form API 的 name、id、AJAX wrapper 或提交回调。

## 7. 卡片、列表和状态

- 会员首页统计卡：数字在上，标签用次级色，统一最小高度，原有链接保留，并加上轻量图标。没有给不能跳转的块加点击。
- 收藏、点赞、历史卡片：图片区 `aspect-ratio: 3 / 4`，`object-fit: cover`，不改图片字段的输出方式。标题两行截断，日期弱化，操作收成 30px 轻按钮。
- 下载记录：桌面表格，767px 以下按 `data-label` 堆成卡片。状态徽章按原有状态机上色。
- 投稿列表：状态徽章、审核说明、编辑和查看入口沿用原链接。
- 空状态、分页、Drupal 对话框和错误消息使用同一套边框、圆角和表面色。成功提示仍按原有会员页规则隐藏。

## 8. 桌面端验收

Chromium headless，1440×900，登录 uid 2 的 `/12shh`：

- 全站导航 `position: sticky`。
- 会员工具栏 `display: none`，计算位置 `static`。
- 布局为 `248px` 侧栏 + 内容列。侧栏 `position: sticky`。
- 面包屑 `static`。
- `scrollWidth - clientWidth` 为 -15，来自 `scrollbar-gutter: stable`，不是横向溢出。

1024px 同样隐藏会员工具栏，全站导航保持 sticky。

## 9. 手机端验收

Chromium headless，高度 844，宽度 390、393、430、768：

- 全站导航 sticky，滚动后仍在 `top: 0`。
- 会员工具栏 `position: static`，`z-index: auto`，滚动后离开视口。
- 面包屑 static。
- 页面标题「会员中心」可见。
- 无横向溢出（差值 -15，同上）。
- 390px 抽屉打开后 `position: fixed`，位于 x=0，宽 320，高 844。Esc 和点击遮罩都能关闭。

没有真机 iPhone Safari。安全区用了 `env(safe-area-inset-*)` 和 `100dvh`，但没有在 iOS 设备上确认底部工具栏和刘海。Footer 仍在文档流末尾，没有被第二层固定栏盖住。

## 10. 深色模式验收

沿用导航的 `localStorage.theme` 与 `data-bs-theme`。资料页实测：

- 页面背景 `#09090b`，文字 `#fafafa`。
- 侧栏 / 卡片 `#18181b`。
- 输入框同色底，边框 `#52525b`。
- 主按钮浅底 `#fafafa`、深字 `#18181b`。

收藏页在深色下「取消收藏」为深色悬停底、浅色字、8px 圆角，不是浏览器默认按钮。首页、图片、视频、文章页没有套这套深色会员底。

## 11. 功能回归

uid 2 打开以下地址均为 200，且带会员页 class：

- `/12shh`、收藏、点赞、下载、会员等级、浏览历史、评论、投稿、资料、安全
- `/12shh/history/clear`
- `/node/add/photo`、`/node/add/article`

浏览器里看到的控件仍是原来的表单和链接：保存、取消、清空历史、取消收藏、取消点赞、删除、投稿入口、评论编辑。没有提交资料、改密码、清空历史、取消收藏或取消点赞，避免改测试账号数据。

首页 `https://gitxx.xyz/` 没有会员工具栏，全站导航仍是 sticky。

## 12. 构建和检查

- 主题目录执行 `npm run build`，成功。构建写到主题 `build/` 和组件 CSS，没有改 `engagement.js` 或 `xedc.libraries.yml`。
- 会员 SCSS 用主题自带的 Dart Sass 单独编译到 `xedc_member/css/member.css`。`@import` 有弃用提示，产物可用。
- `php -l`：`xedc_member` 下全部 PHP 文件通过。
- `node --check`：`member.js` 通过。
- 上述会员页返回 200，Twig 已由 Drupal 编译。
- `drush cr` 在样式编译后成功。聚合开启，会员规则随聚合 CSS 生效（浏览器读到的是新的按钮和定位）。
- 最近 Error 日志来自 `xedc_core`：`EngagementReport::memberRows()`、`GuestIdentity` 构造参数、`DownloadResource` 校验，以及更早的 `EngagementAdminController` 解析错误。这些不是会员样式改动，本次没有改那些文件。

验收结束后再次调用 `drush uli` 时，CLI 报缺少参数 `xedc_core.video_duration`。当时首页仍返回 200。这是 `xedc_core` 容器定义问题，没有在本次任务里修。

## 13. 未完成项

- 没有真机 iPhone Safari，只在 Chromium 里测了 390 / 393 / 430 / 768 / 1024 / 1440。
- 没有打开媒体库弹窗，没有保存投稿，没有切换审核状态。对话框样式写在 `body.xm-page .ui-dialog` 和 `body.xm-submission-page .ui-dialog`。
- 没有点击会写数据的收藏、点赞、删除、清空历史、资料保存和改密码。
- `/member/settings` 仍跳到账户安全。别名检查仍是 JSON。别名拒绝仍是访问拒绝。
- 全站语言切换按钮（页面上的「中文」）不属于会员中心，没有改。
- `xedc_core` 日志里的业务错误单独记录，没有修。

## 14. 业务逻辑

没有修改数据库、字段、内容类型、角色、权限、路由、实体保存、投稿工作流、收藏、点赞、下载、分享、AJAX 回调或 API 结构。

控制器只为下载状态多输出一个已有状态机读名，供徽章上色。预处理只增加 body 类名。表单提交处理函数没有改。
