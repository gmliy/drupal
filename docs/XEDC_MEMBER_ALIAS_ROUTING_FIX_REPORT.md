# XEDC 会员中心 URL 路由修复报告

日期：2026-11-02  
网站：https://gitxx.xyz  
范围：会员中心地址被改写到 `/xedc-member/alias-denied`。没有改数据库结构、字段、角色、收藏、点赞、下载、投稿审核、模板或样式。

## 1. 根本原因

两套逻辑把会员中心和公开别名绑在了一起。

1. `MemberAliasPathProcessor::processOutbound()` 把已登录用户的 `/member` 和 `/member/*` 链接改写成 `/{slug}`。测试账号的 slug 是 `12shh`，所以规范地址变成 `/12shh`。
2. Redirect 模块的路由规范化器（响应头 `X-Drupal-Route-Normalizer`）发现浏览器地址和这个“规范地址”不同，于是把 `/member` **301** 到 `/12shh`。
3. `MemberAliasPathProcessor::processInbound()` 再把 `/{slug}` 认成会员中心。只有当前用户 ID 等于别名主人时才改回 `/member`；否则改成 `/xedc-member/alias-denied`。
4. `MemberAliasSubscriber::onRequest()` 还会把 `/member`、`/user` 和用户自己的 `/user/{uid}` **302** 到 `/{slug}`。

真正把登录会员送进拒绝页的是第 3 步的缓存。Drupal 把入站路径处理结果放在 `cache_data`，缓存键是 `route:[language]=zh-hans:[query_parameters]=:/12shh`，**不含用户**。第一次非主人访问把路径永久记成 `/xedc-member/alias-denied`。之后主人再访问，路由缓存直接命中这条结果，不再重新判断身份。规范化器再 **301** 到 `/xedc-member/alias-denied`。此时用户已经登录，`aliasDenied()` 抛出拒绝访问，页面就是 403。

修复前用 uid 2 实测：

- `GET /member` → `301 https://gitxx.xyz/12shh`
- `GET /12shh` → `301 https://gitxx.xyz/xedc-member/alias-denied`
- 跟随重定向后：`403`，标题「拒绝访问」
- 当时会话 uid 是 2，slug `12shh` 的主人也是 2
- 缓存里的路径仍是 `/xedc-member/alias-denied`

所以这不是别名为空、重复、非法或未激活。别名映射是对的，错在把 `/member` 交给公开别名处理器，并把“不是主人”的结果缓存给了所有人。

## 2. 哪个处理器导致了错误跳转

| 步骤 | 代码 | 结果 |
| --- | --- | --- |
| 出站改写 | `MemberAliasPathProcessor::processOutbound()` | `/member` 变成 `/12shh` |
| 301 | `Drupal\redirect\EventSubscriber\RouteNormalizerRequestSubscriber` | 浏览器被送到 `/12shh` |
| 入站改写 | `MemberAliasPathProcessor::processInbound()` | 非主人，或命中旧缓存时，内部路径变成 `/xedc-member/alias-denied` |
| 再次 301 | 同一个路由规范化器 | 地址栏变成 `/xedc-member/alias-denied` |
| 额外 302 | `MemberAliasSubscriber::onRequest()` | `/user` 也会先到 `/12shh`，再掉进上面的链 |

`alias-denied` 路由没有删除。它只在有人直接打开 `/xedc-member/alias-denied` 时还在。游客会去登录页，登录用户仍是拒绝访问。会员中心不再把人送进去。

## 3. 修改的文件

- `web/modules/custom/xedc_member/src/PathProcessor/MemberAliasPathProcessor.php`
- `web/modules/custom/xedc_member/src/EventSubscriber/MemberAliasSubscriber.php`
- `web/modules/custom/xedc_member/src/Service/MemberAliasManager.php`
- `web/modules/custom/xedc_member/src/Controller/MemberController.php`
- `web/modules/custom/xedc_member/xedc_member.routing.yml`
- `web/modules/custom/xedc_member/xedc_member.services.yml`
- `web/modules/custom/xedc_member/xedc_member.module`
- `web/modules/custom/xedc_member/src/Form/ProfileForm.php`（只改了别名说明文字，避免再写成“留空则使用 /member”）

没有改模板、CSS、`config/sync`、核心和第三方模块。执行了 `drush cr`，清掉已经写坏的路由匹配缓存。

## 4. 恢复后的路由

会员中心仍是原来的路由，地址不再被别名改写：

- `/member`
- `/member/favorites`
- `/member/likes`
- `/member/downloads`
- `/member/membership`
- `/member/history`
- `/member/history/clear`
- `/member/comments`
- `/member/submissions`
- `/member/profile`
- `/member/security`
- `/member/settings` 仍 302 到 `/member/security`

项目里原来没有 `/u/{slug}` 这条正式路由。公开主页以前被做成 `/{slug}`，再由入站处理器抢去当会员中心。现已单独增加 `xedc_member.public_profile`：`/u/{slug}`。

两套地址分开：

- `/member/*` 只属于当前登录会员的会员中心，路径里没有 uid，也没有 slug。
- `/u/{slug}` 只解析公开资料：显示名、头像、简介、加入日期。不显示邮箱，不进入会员中心菜单。
- 已有的用户路径别名还在。`/12shh` 继续指向 `/user/2`，不再改写成会员中心或 `alias-denied`。
- `/user` 在登录后进入 `/member`。只有地址正好是自己的 `/user/{uid}` 时才同样进入 `/member`。

## 5. 登录跳转

`xedc_member_user_login_redirect()` 没有改逻辑，在 Drupal 里调用确认：

- 没有 destination，或 destination 只是 `/user`、`/user/{uid}`：登录后去 `/member`。
- destination 是 `/member/favorites`：保留原目标，不改成 slug。
- destination 已经是 `/xedc-member/alias-denied`：不另作改写。会员中心自己不会再制造这个目标。

游客访问受保护页面：

- `/member` → `302 /user/login?destination=/member`
- `/member/favorites` → `302 /user/login?destination=/member/favorites`

一次性登录链接是 Drupal 核心的密码重置流程，结束后仍停在 `/user/2/edit`。从那里再打开 `/member`，地址保持 `/member`，没有跳去 slug 或拒绝页。

## 6. 公开主页

uid 2，slug `12shh`：

| 地址 | 结果 |
| --- | --- |
| `/u/12shh` | 200，标题「Dc莉莉」，没有邮箱，没有 `xm-page`，没有会员导航 |
| `/u/no-such-member-zz` | 404 |
| `/u/bad.slug` | 404 |
| `/u/a%20b` | 404 |
| `/u/..` | 404 |
| `/u/member` | 404（没有这个别名） |
| 用 uid 1 检查 slug `12shh` | `taken`，没有写入 |
| 用主人 uid 2 检查自己的 `12shh` | `available` |
| 非法格式 `bad slug` | `invalid` |

登录状态下再打开 `/u/12shh` 仍是公开主页。随后打开 `/member` 仍是 `/member`。

匿名访问旧别名 `/12shh` 得到 **403**。匿名角色没有 `access user profiles`，这是用户主页原有权限，不是 `alias-denied`。

## 7. 游客和会员权限

uid 2 登录后，下面这些地址都是 200，重定向次数为 0，最终地址就是请求的 `/member...`，标题不是「拒绝访问」：

- `/member`、收藏、点赞、下载、资料、安全、历史、评论、投稿、会员等级

刷新 `/member` 仍是 200，页面里的收藏链接是 `/member/favorites`。

退出后再次访问 `/member`：`302 /user/login?destination=/member`。

会员中心路由没有 uid 参数，不能打开另一个会员的收藏、下载或资料编辑页。没有放宽 `alias-denied` 的权限。

## 8. 其他路由

- 修复后 `/12shh` 的路由缓存是 `/user/2`，不再是 `/xedc-member/alias-denied`。
- `/member` 与 `/u/12shh`、`/12shh` 互不改写，没有循环重定向。
- 首页 `https://gitxx.xyz/` 仍是 200。
- 最近的 Error 日志是 `xedc_core` 的 ffprobe，不是这次路由改动。

## 9. 数据和业务逻辑

没有修改数据库结构，没有改会员字段、角色、会员等级、收藏、点赞、下载或投稿审核。别名仍保存在原来的 `users_data` 和用户路径别名里。只清了 Drupal 缓存，用来去掉已经写错的路由匹配。

## 10. 界面

没有改会员中心模板和 CSS。Chromium 登录 uid 2 后看过：

- 390px：地址 `/member`，页面「会员中心」，全站导航 `sticky`，会员工具栏 `static`，收藏链接 `/member/favorites`。
- 1440px：地址仍是 `/member`，会员工具栏在桌面端不显示，全站导航仍是 `sticky`。
- `/member/favorites` 地址保持不变。
- 首页没有会员工具栏，全站导航仍是 `sticky`。
