# 会员中心自定义 URL 恢复与安全修复

时间：2026-11-02。网站：https://gitxx.xyz。模块：`web/modules/custom/xedc_member`。

没有修改数据库结构，没有修改 `config/sync`、Drupal 核心、第三方模块、会员中心模板和 CSS。没有提交 Git。

## 结论

每个已设置 slug 的会员重新使用自己的前缀。UID 2 是 `/12shh`，UID 3 是 `/dcxe`。子页面走同一前缀。内部路由名称和 Controller 没有改。`/u/{slug}` 仍是公开资料，不是会员中心。

路径匹配缓存不再按当前用户保存不同结果。非主人访问别人的前缀得到 403，看不到对方的收藏、下载、资料表单和账户安全。

## 1. Git 证据

`aabf5a1`（备份：首页布局改版前当前状态）里已经有完整实现：

- slug 存在 `users_data`（`xedc_member` / `alias`），并同步一条用户 path alias，例如 `/12shh` → `/user/2`。
- 格式是单个路径段，2 到 32 个字符，字母、数字、下划线、连字符，不能含 `/`、空格或点。保留字包括 `member`、`user`、`admin`、`xedc-member`。
- 唯一性检查 path alias、`users_data` 和其他已有路由。
- `MemberAliasPathProcessor::processOutbound()` 把 `/member/...` 改写成 `/{slug}/...`，并带上 `user` 缓存上下文。
- `processInbound()` 把 `/{slug}/favorites` 改写成 `/member/favorites`。
- 同一方法在当前用户不是 slug 主人时改写成 `/xedc-member/alias-denied`。
- `MemberAliasSubscriber` 把 `/member` 和本人的 `/user` 转到 slug。登录成功钩子 `xedc_member_user_login_redirect()` 在没有有效 destination 时进入会员中心。

这次工作区里的未提交修改把入站和出站都改成原样返回，会员中心固定在 `/member`，公开页改到 `/u/{slug}`。这是为了躲开缓存污染，也把原来的 `/slug` 设计拿掉了。Git 历史只作依据，没有整文件恢复。

## 2. 缓存污染的根因

`Drupal\Core\Routing\RouteProvider::getRouteCollectionForRequest()` 用请求路径做缓存键，键里没有当前用户。它调用全部入站 PathProcessor，然后把处理后的系统路径写入 `cache.data`，标签是 `route_match`。

旧的 `processInbound()` 对同一个人的 URL 会返回两个结果：主人得到 `/member/favorites`，其他人得到 `/xedc-member/alias-denied`。先访问的那个人的结果被写进共享缓存，后面的人，包括主人，都复用这条结果。

修复不是关缓存，也不是清一次缓存。入站结果不再读取当前用户。`/12shh/favorites` 和 `/dcxe/favorites` 对所有人都解析成 `/member/favorites`。是否允许访问交给路由访问检查，检查的是浏览器原始路径和当前账号。`AccessResult::andIf()` 只有两边都是 allowed 才算通过，所以规范的 `/member` 路径必须返回 allowed，不能返回 neutral，否则已登录用户也会被拒绝。

实测缓存：

| 请求路径 | 缓存中的系统路径 |
| --- | --- |
| `/12shh` | `/member` |
| `/12shh/favorites` | `/member/favorites` |
| `/dcxe/favorites` | `/member/favorites` |
| `/dcxe/security` | `/member/security` |

没有一条被存成 `/xedc-member/alias-denied`。两条收藏地址共用同一个系统路径，访问结果仍然按人分开。

slug 保存和用户 path alias 变更会失效 `xedc_member:aliases`、`user:{uid}` 和 `route_match`。不需要手工清空全站缓存。这只清掉路径匹配缓存，不关闭页面缓存。

## 3. 恢复后的行为

出站：`Url::fromRoute('xedc_member.favorites')` 在 UID 2 下生成 `/12shh/favorites`，在 UID 3 下生成 `/dcxe/favorites`。导航、资料页表单 action、提交后的跳转都走生成器。匿名用户仍得到 `/member/...`，并带 `user` 与 `xedc_member:aliases` 缓存信息。

入站：已知 slug 加上已知子路径才改写。子路径包括 favorites、likes、downloads、membership、history、history/clear、comments、submissions、profile、security，以及 settings 到 security。`/u/{slug}`、保留字和其他路径不动。

访问：`MemberCenterAccessCheck` 只在原始路径属于某个会员的 slug 时核对主人。主人允许，其他人 403。规范的 `/member` 仍要求登录，随后由订阅器 301/302 到本人的 slug。实测 `/member` 和 `/member/favorites` 会到 `/12shh` 与 `/12shh/favorites`。

登录：`/user` 在 UID 2 登录后跳到 `/12shh`。没有 destination 时，登录钩子生成的会员中心地址经出站改写后也是本人的 slug。游客访问 `/12shh/favorites` 得到 `/user/login?destination=/12shh/favorites`，不会进 `alias-denied` 或 `alias-check`。退出后同一地址再次进入登录，不保留会员中心内容。

公开主页 `/u/12shh` 仍返回 200，和私有会员中心分开。

## 4. 修改的文件

- `src/PathProcessor/MemberAliasPathProcessor.php`：入站不再看当前用户；出站恢复 slug。
- `src/Access/MemberCenterAccessCheck.php`：新增。按原始路径核对主人。
- `src/Service/MemberAliasManager.php`：恢复 `memberBase()`、`toPublicPath()`，增加与用户无关的路径解析。保存时失效 `route_match`。
- `src/EventSubscriber/MemberAliasSubscriber.php`：`/member` 和本人的 `/user` 回到自己的 slug。
- `xedc_member.routing.yml`：私有会员路由增加 `_xedc_member_center`。
- `xedc_member.services.yml`：接回别名管理器，注册访问检查。
- `xedc_member.module`：用户别名变化时失效路径匹配缓存。
- `src/Form/ProfileForm.php`：只改了别名说明文字，说明会员中心是 `/别名`，公开页是 `/u/别名`。

另外，主题里未提交的 `xedc_preprocess_html()` 和 `includes/auth.inc` 的同名函数重复声明，会员页和公开页都会 500。把主题里的函数改名为 `_xedc_preprocess_html_video()`，由 `auth.inc` 在原钩子末尾调用。视频标题逻辑还在，没有改会员模板和 CSS。

## 5. 两个账号的测试

页面缓存和 Dynamic Page Cache 都开着。会员页路由原来的 `no_cache` 没有动。测试走源站 HTTPS。

UID 2，`/12shh`：

| 地址 | 结果 |
| --- | --- |
| `/12shh` | 200，导航指向 `/12shh/*` |
| `/12shh/favorites` | 200 |
| `/12shh/likes` | 200 |
| `/12shh/downloads` | 200 |
| `/12shh/profile` | 200，表单 action 是 `/12shh/profile` |
| `/12shh/security` | 200 |
| `/12shh/membership`、`/history`、`/comments`、`/submissions` | 200 |
| `/12shh/settings` | 301 到 `/12shh/security` |
| 再次刷新 `/12shh/favorites` | 200 |
| `/12shh/favorites?page=1` | 200，链接仍在 `/12shh/favorites` |

UID 3，`/dcxe`：首页、收藏、点赞、下载、资料、安全都是 200，导航是 `/dcxe/*`。

交叉，缓存开着，并且两边反复刷新：

| 请求 | 结果 |
| --- | --- |
| UID 2 打开 `/dcxe/favorites` | 403，页面不是会员中心 |
| UID 3 打开 `/12shh/favorites` | 403 |
| UID 3 打开 `/12shh/profile` | 403 |
| UID 2 打开 `/dcxe/security` | 403 |
| 拒绝之后主人再打开自己的收藏 | 200 |
| 403 页上的会员链接 | 指向当前账号自己的 slug |

游客访问 `/12shh`、收藏、资料、安全，以及 `/dcxe/downloads`，都是 302 到登录页，destination 是原来的地址。响应带 `no-store`，所以 Internal Page Cache 不保存这次跳转。这避免把一次访问结果变成全站匿名缓存，不是关闭全站缓存。

## 6. slug 修改

UID 3 在测试前没有 slug。已通过现有的 `MemberAliasManager::save()` 写成 `dcxe`，没有新增字段。UID 2 原来就是 `12shh`。

UID 2 的资料表单实际提交：

- AJAX `/xedc-member/alias-check?q=dcxe` 返回“已被占用”。
- `q=a/b` 返回“格式不正确”。
- `q=12shh-ok` 返回“可用”。
- 表单把别名改成 `12shh2`，303 到 `/12shh2/profile`。
- `/12shh2` 和 `/12shh2/favorites` 为 200，导航已变成 `/12shh2/*`。
- UID 3 访问 `/12shh2/favorites` 为 403。
- 旧地址 `/12shh/favorites` 在改名期间是 404，没有落到 UID 3。
- 测试结束后已改回 `12shh`。`/12shh/favorites` 恢复 200，`/12shh2/favorites` 为 404。
- Redirect 模块曾为临时别名 `12shh2` 写了一条指向 `/user/2` 的记录，测试后已删除。

## 7. 登录和退出

- 已登录 UID 2 访问 `/user`，302 到 `/12shh`。
- `/member` 进入本人的 slug，不会进入另一个会员，也不会进入 `alias-denied`。
- 退出后访问 `/12shh/favorites`，302 到 `/user/login?destination=/12shh/favorites`。

密码重置链接会先到核心的 `/user/2/edit`。那是 Drupal 重置流程，不是会员别名。

## 8. 数据库和业务影响

没有改表结构。没有改角色、会员等级、收藏、点赞、下载或投稿审核。

数据变化只有别名：

- UID 3 新增 slug `dcxe`，因为库里原来没有，而验收要求这个前缀。
- UID 2 在表单测试中改成 `12shh2` 后已恢复为 `12shh`。path alias 仍是 `/user/2` → `/12shh`，`/user/3` → `/dcxe`。

## 9. 未完成

- 两个账号的收藏都没有第二页，分页控件没有画出来。`?page=1` 已确认仍留在本人的 slug 上。
- 没有在浏览器里逐个点击。验收使用源站 HTTP，核对状态码、跳转和页面里的链接。
- 没有提交修改密码，避免改掉真实账号。安全页本身返回 200。
- 私有会员页沿用路由上原来的 `no_cache`，Dynamic Page Cache 对已登录会员页显示不可缓存。共享的是路径匹配缓存，不是会员 HTML。
