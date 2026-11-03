# XEDC 公开会员主页验收报告

日期：2026-11-02

站点：https://gitxx.xyz

范围：公开主页 `/r/{slug}`。私有会员中心仍是 `/{slug}`。未提交 Git，未改 Drupal 核心、第三方模块、`config/sync` 和数据库结构。

## 1. 原有实现

公开主页原来是 `xedc_member.public_profile`，路径 `/u/{slug}`。控制器只输出头像、昵称、简介和加入日期，没有作品、筛选、排序、分页和分享。

私有会员中心已经走自定义别名：`MemberAliasPathProcessor` 把 `/{slug}` 和 `/{slug}/favorites` 等映射到 `/member` 路由，访问权由 `MemberCenterAccessCheck` 限制为本人。别名存在 `users_data`，并同步一条 `/user/{uid}` 的 path alias。这次没有重写这套机制。

作品类型是 `photo`、`video`、`article`，审核流是 `xedc_editorial`。已发布状态只有 `published`。库里存在 `status = 1` 但审核不是 `published` 的记录（例如 nid 38 的默认修订是 draft）。因此公开列表不能只看 `status`。

视频时长字段是 `field_duration`，单位秒，格式化沿用 `xedc_core.video_duration_formatter`。文章发布时间是 `field_published_at`。图片、视频没有单独的发布时间字段，使用节点 `created`。

## 2. 最终 URL

| 用途 | URL |
| --- | --- |
| 公开主页 | `/r/{slug}`，例如 `/r/12shh`、`/r/dcxe` |
| 分类 | `?type=photo`、`?type=video`、`?type=article`，不带参数是全部 |
| 排序 | 默认最新；`?sort=oldest` 为最早。切换分类时保留排序并去掉 `page` |
| 分页 | Drupal 习惯的 0 起页码，`?page=1` 是第二页 |
| 私有会员中心 | `/{slug}`、`/{slug}/favorites`、`/{slug}/profile` 等，未改 |

分享地址由路由生成绝对 URL，例如 `https://gitxx.xyz/r/12shh`。

`r` 被加入保留别名。否则会员如果占用别名 `r`，`/r/favorites` 会变成该会员的私有收藏页，和公开主页冲突。现有会员没有这个别名。

## 3. `/u/{slug}` 废弃

- 路由定义已从 `/u/{slug}` 改为 `/r/{slug}`，路由名仍是 `xedc_member.public_profile`。
- `MemberAliasManager::publicBase()` 改为返回 `/r/{slug}`。
- 个人资料说明和别名校验文案改为公开主页 `/r/别名`。
- 主题、模板和脚本里没有残留的公开主页 `/u/` 生成。
- 没有 301、302 或内部转发。
- 重建路由后，`router` 表中公开主页路径是 `/r/{slug}`，以 `/u/` 开头的路由数量为 0。
- 会员别名保存、`/{slug}` 入站映射没有删除。

实测 `https://gitxx.xyz/u/12shh` 和源站 `/u/12shh`、`/u/xedcpt92` 都是 404，响应没有 `Location`。

## 4. `/r/{slug}` 路由

路由要求 `access content`，slug 约束是 `[^/]+`。控制器继续拒绝空值、点号、斜杠、反斜杠和超过 32 个字符的 slug，未知会员返回 404。

源站结果：

| URL | 结果 |
| --- | --- |
| `/r/12shh` | 200，公开主页 |
| `/r/dcxe` | 200，公开主页 |
| `/r/no-such-member` | 404 |
| `/r/a.b` | 404 |
| `/r/%2e%2e` | 404 |
| `/r/%2e%2e%2fetc` | 404 |
| `/r/12shh%2Fprofile` | 404 |
| `/12shh` | 匿名 302 到登录；会员本人 200，会员中心 |
| `/12shh/favorites` | 会员本人 200，收藏页 |
| `/12shh/profile` | 会员本人 200，资料页，文案为 `/r/` |
| `/u/12shh` | 404 |

临时会员先保存别名 `xedcpt91`，再改为 `xedcpt92`。`/r/xedcpt91` 为 404，`/r/xedcpt92` 为 200，`publicBase()` 为 `/r/xedcpt92`。测试用户随后删除。

## 5. 页面 UI

页面是极简创作者主页，不是会员后台，也不是 Drupal 用户资料页。

- Hero：72px 头像（手机 64px）、昵称、最多三行简介、加入日期、分享按钮。
- 统计在资料下方，链到对应分类。
- 分类是下划线标签：全部作品、图片、视频、文章，并带真实数量。
- 排序是「最新发布 / 最早发布」。
- 作品是统一 4:5 的网格。桌面 4 列，768px 起 3 列，手机 2 列。
- 视频封面中央有播放图标，右下角是真实时长。图片多于 1 张时显示张数。文章显示摘要和日期。
- 空状态文案是「这里还没有公开作品」。分类为空时分别是没有公开图片、视频或文章。
- 样式使用现有 Bootstrap 颜色变量，并在 `html[data-bs-theme=dark]` / `html[data-theme=dark]` 下切换到与会员中心相同的深色令牌。
- 默认页标题被隐藏，页面只有资料区的一个 `h1`。

视觉调整：第一版图片用 4:5、视频和文章用 16:9，同一行标题不齐。已改成全部 4:5。改完后在 1440px 实测第一行四张卡片的封面高度都是 335px，标题顶边都是 734px。

## 6. 会员资料字段

只读现有用户字段：

| 展示 | 来源 |
| --- | --- |
| 头像 | `user_picture`，图片样式 `max_325x325` |
| 无头像 | 模板里的统一人物剪影，不新增字段 |
| 昵称 | `field_xedc_nickname` |
| 没有昵称 | 显示「会员」，不回退到登录名 |
| 简介 | `field_xedc_bio`，纯文本，三行截断 |
| 加入时间 | 用户 `created` |

不输出邮箱、手机、登录名、收藏、点赞、下载、历史和安全信息。

uid 2 登录名是 `a`，昵称是「Dc莉莉」，页面只显示昵称。uid 3 登录名是 `aaaa`，没有昵称，页面显示「会员」，HTML 中没有 `aaaa` 和邮箱 `aiweixxz@gmail.com`。uid 2 的邮箱 `aaaa@a.a` 也不在页面中。uid 3 没有头像，显示默认剪影。

## 7. 作品查询

查询在 `PublicProfileBuilder`，不复用收藏、历史或投稿列表。

条件：

- `node_field_data.uid` 为该会员
- `default_langcode = 1`，避免翻译行重复
- `status = 1`
- 内连接 `content_moderation_state_field_data`，并且 `content_entity_revision_id = node.vid`
- 审核状态属于工作流里的已发布状态，当前只有 `published`
- 查询带 `node_access`，账户元数据是匿名用户

计数按类型一次 `GROUP BY`。列表另一次查询，每页 12 条。文章排序时间是 `field_published_at` 的 UTC 时间戳，缺失时用 `created`；图片和视频用 `created`。次序再加 `nid`，避免并列时翻页抖动。

封面按页批量加载 media 和 file，不逐条查询。图片样式沿用 `photo_card_640`、`video_cover`、`xa_cover` 和对应的大图。时长只读 `field_duration`。

## 8. 权限

SQL 条件之后，加载到的节点还要同时满足：

- 作者是当前主页会员
- `isPublished()`
- 匿名账户 `access('view')`
- `moderation_state` 属于已发布状态

因此管理员打开公开主页也不会看到未发布稿。会员中心的私有查询没有被拿到这个页面。

验收时为 uid 2 和 uid 3 创建了标题以 `XEDC验收` 开头的临时节点，测完已删除，剩余 0 条。其中：

- 已发布图片 13、视频 2、文章 2 会出现
- draft、needs_review、rejected、archived 不出现
- 一条把 `status` 改成 1 但审核仍是 draft 的「假发布」不出现
- uid 3 的图片只出现在 `/r/dcxe`

撤下「XEDC验收图13」后，图片数从 13 变为 12，标题从列表消失。

## 9. 缓存

渲染数组使用 Drupal 缓存：

- keys：`xedc_public_profile`、会员 uid、分类、排序、页码
- contexts：`url.path`、`url.query_args:type`、`url.query_args:sort`、`url.query_args:page`、界面语言、内容语言、时区
- max-age：permanent
- tags：`user:{uid}`、`node_list`、三个 bundle 的 `node_list`、本页节点和封面文件、工作流配置、`xedc_member:aliases`

没有关闭全站缓存。匿名第二次访问同一 URL 时 `X-Drupal-Cache: HIT`。把昵称改成「Dc莉莉验收」后，同一 URL 变成 `MISS` 并显示新昵称；改回「Dc莉莉」后再次 `MISS` 并恢复。撤下作品同样使页面 `MISS` 且列表更新。

匿名缓存里没有投稿链接。会员本人访问空主页时，BigPipe 替换结果里有指向 `/12shh/submissions` 的「去投稿」。其他访客的空主页没有这个回调。

动态缓存响应头在被内部页面缓存存下的那一次响应里是 `MISS`，之后匿名请求直接由页面缓存返回，因此会继续看到当时记下的 `MISS`。这不表示第二次没有命中缓存；命中标志是 `X-Drupal-Cache: HIT`。

Cloudflare 对这次 HTML 返回 `cf-cache-status: DYNAMIC`，没有把测试作品留在边缘缓存。清理后公网 `/r/12shh` 已是空状态。

## 10. 修改文件

新增：

- `web/modules/custom/xedc_member/src/Service/PublicProfileBuilder.php`
- `web/modules/custom/xedc_member/templates/xedc-public-profile.html.twig`
- `web/themes/custom/xedc/css/public-profile.css`
- `web/themes/custom/xedc/js/public-profile.js`
- `docs/XEDC_PUBLIC_MEMBER_PROFILE_UI_REPORT.md`

修改：

- `web/modules/custom/xedc_member/xedc_member.routing.yml`
- `web/modules/custom/xedc_member/xedc_member.services.yml`
- `web/modules/custom/xedc_member/xedc_member.module`
- `web/modules/custom/xedc_member/src/Controller/MemberController.php`
- `web/modules/custom/xedc_member/src/Service/MemberAliasManager.php`
- `web/modules/custom/xedc_member/src/Form/ProfileForm.php`
- `web/modules/custom/xedc_member/src/EventSubscriber/MemberAliasSubscriber.php`
- `web/themes/custom/xedc/xedc.libraries.yml`

`MemberAliasSubscriber` 只让公开主页的 403 不再被送去登录。公开主页不再输出 `noindex`。`xm-page` 仍只加在私有会员中心。

## 11. 手机端

Headless Chromium 156，设备宽度模拟，源站 `https://gitxx.xyz/r/12shh`。

| 宽度 | 横向溢出 | 列数 |
| --- | --- | --- |
| 390 | 无，`scrollWidth` 等于 `clientWidth` | 2 |
| 430 | 无 | 2 |

390px 下头像和昵称同一行，简介和统计在下一行，分享按钮在右侧，没有把昵称挤出屏幕。分类可以换行。分页、视频筛选通过点击走到了对应 URL。

没有用真机。

## 12. 桌面端

| 宽度 | 横向溢出 | 列数 |
| --- | --- | --- |
| 768 | 无 | 3 |
| 1024 | 无 | 4 |
| 1440 | 无 | 4 |

1440px 第一行四张卡片封面高度一致，标题起始位置一致。点击「下一页」进入 `/r/12shh?page=1`，作品与第一页不重复。点击「视频」进入 `?type=video`。

## 13. 深色模式

站点开关写的是 `html` 的 `data-bs-theme` 和 `data-theme`。测试时设置这两个属性，不另做一套主题。

1440 和 390 的计算结果：

- 页面背景 `rgb(9, 9, 11)`
- 正文 `rgb(250, 250, 250)`
- 封面底 `rgb(39, 39, 42)`
- 边框 `rgb(63, 63, 70)`

浅色页面背景是白色，正文 `rgb(28, 25, 23)`。组件本身不用写死的白底，深色下没有黑字贴在深底上。

## 14. 分类、排序和分页

临时数据的排序是刻意错开的：文章「XEDC验收文章新」的 `created` 更早，但 `field_published_at` 是 2026-11-01；「XEDC验收文章早」的 `created` 更晚，发布时间是 2020-01-01。

| 请求 | 第一条 | 结果 |
| --- | --- | --- |
| `/r/12shh` | 文章新 | 通过。最新按发布时间，不是按错误的 created |
| `?sort=oldest` | 文章早，其次时长 01:23 的短视频，再是图01 | 通过 |
| `?type=photo` | 图13 到图02，共 12 条 | 通过，没有视频和文章 |
| `?type=video` | 视频长、视频短 | 时长 `01:13:16`、`01:23` |
| `?type=article` | 文章新、文章早 | 摘要出现 |
| `?page=1` | 图03、图02、图01、短视频、文章早 | 与第一页不重复 |
| `?type=photo&sort=oldest&page=1` | 只有图13 | 分类、排序、页码都保留 |
| 分页链接 | 最新为 `?page=1`，最早为 `?sort=oldest&page=1` | 查询参数没有丢 |

视频秒数分别是字段里的 83 和 4396，不是从标题猜的。

## 15. 分享

分享按钮的 `data-xp-share` 是 `https://gitxx.xyz/r/12shh`。

Headless Chromium 里：

- 注入 `navigator.share` 后点击，回调收到标题「Dc莉莉」和上述 URL，没有误弹复制提示。
- 浏览器拒绝剪贴板、以及关掉 secure context 后走 `execCommand` 时，页面显示「复制失败」。
- 剪贴板成功提示「链接已复制」没有在这个无权限的浏览器里做成。代码在 `clipboard.writeText` 成功后会显示该提示。

## 16. 两个会员的数据隔离

同一时段：

- `/r/12shh` 有 uid 2 的 13 张图、2 个视频、2 篇文章，没有 uid 3 的「XEDC验收乙图片」。
- `/r/dcxe` 只有这 1 张图，图片 1、视频 0、文章 0，没有 uid 2 的图01。
- 撤下和删除之后，公网两个主页都不再包含 `XEDC验收`。

## 17. 旧 URL 404

`/u/12shh` 与 `/u/xedcpt92` 均为 404，无 `Location`。路由表没有 `/u/` 前缀。公开主页路由路径是 `/r/{slug}`。

## 18. 未完成项和风险

- 无头浏览器拿不到剪贴板权限，「链接已复制」的成功提示没有在真实点击里看到。失败提示和原生分享参数已经看到。
- 临时作品没有封面，也没有多图。封面样式和无图占位已在页面上出现；「N 张」徽章只在 `field_images` 数量大于 1 时输出，这次没有一条多图作品把它点亮。
- 响应式使用 Chromium 设备宽度，不是物理手机。
- 没有昵称的会员显示「会员」。这是为了不露出登录名。如果业务希望改显示别名，需要另定规则。
- 别名 `r` 不能再注册。
- 匿名页面缓存会把第一次响应上的 `X-Drupal-Dynamic-Cache: MISS` 一起存下来。判断匿名缓存是否生效要看 `X-Drupal-Cache`。
- 测试曾短暂把 uid 2 的昵称改成「Dc莉莉验收」，已改回「Dc莉莉」。临时节点和临时用户已删除。

公网当前状态：`https://gitxx.xyz/r/12shh` 是 Dc莉莉 的空公开主页，`https://gitxx.xyz/r/dcxe` 是没有昵称会员的公开主页，`https://gitxx.xyz/u/12shh` 为 404，`https://gitxx.xyz/12shh` 仍是私有会员中心。
