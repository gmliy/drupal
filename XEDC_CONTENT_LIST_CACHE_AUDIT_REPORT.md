# XEDC 内容列表操作菜单缓存审查

审查对象是内容列表（`/admin/content`，Views `content`）操作菜单的渲染缓存。本轮没有改 Drupal 代码。原始输出在 `/root/xedc-content-list-cache-audit/`。

## 结论

操作菜单缓存可以保留。

- 缓存按用户分开。管理员菜单没有出现在内容编辑账号、普通认证账号或匿名账号的结果里。
- 缓存命中不再次调用实体访问检查。隔离靠缓存键里的用户 ID，以及角色配置标签、`access_policies`、`user:{uid}`、`node:{id}`。这些标签的失效已经按场景验证。
- 节点保存、发布、撤下、删除、翻译标题保存、角色权限变更、用户停用都会使对应缓存失效。
- 缓存内容是编辑、翻译、删除、查看链接，没有表单令牌。整页仍然因为批量操作表单而不能进入 Dynamic Page Cache。
- 热请求内核中位数约 595ms，路径别名查询稳定为 57 次。其中 50 次是标题链接，每个可见行一次。别名 SQL 大约 12ms。剩下的时间主要是 PHP：后台导航大约 115ms，视图渲染大约 274ms。
- 不需要为了安全再改插件。下一轮只有在接受单独缓存后台导航时才值得做；不值得为了这 57 次别名查询关闭全站路径别名。

## 1. Git 初始状态

审查开始时分支是 `main`，工作区有大量未提交修改。当时记录的 `git diff --stat` 是 73 个文件，+7005 / −1468。受保护文件都在这批未提交内容里：

- `web/modules/custom/xedc_core/src/Plugin/views/field/ContentEntityOperations.php` 当时未跟踪。
- `web/modules/custom/xedc_core/xedc_core.module` 在追加钩子之前就已经有未提交修改。
- `web/themes/custom/xedc_admin/css/admin-ui.css`
- `web/themes/custom/xedc_admin/xedc_admin.theme`

本轮没有对它们执行 `git checkout`、`git reset` 或 `git clean`，也没有改写这些文件。

审查结束前，仓库在 2026-11-02 19:00:11 UTC 产生了提交 `e2cd0e5`（提交说明为「备份 2026-11-03 03:00」）。该提交包含上述插件和钩子。本轮没有创建这个提交。结束时相对 HEAD 只剩与本轮无关的 `web/modules/custom/xedc_core/js/monitor.js`，以及未跟踪的 `docs/XEDC_AI_FULL_ACCEPTANCE_REPORT.md`。这两个文件都没有被本轮修改。插件相对 HEAD 的差异为 0。

## 2. 插件代码审查结果

插件只在视图 ID 为 `content` 时走缓存。其他实体操作字段仍调用核心 `EntityOperations::render()`。

未命中时调用父类生成链接，再把缓存键、上下文和标签合并进父类返回的可缓存元数据。命中时直接返回渲染缓存里的渲染数组，不再调用 `parent::render()`，因此也不会再次执行 `entity->access()`。

`xedc_core_views_plugins_field_alter()` 只替换 `entity_operations` 的插件类。函数里没有提前 `return`。`php -l` 在上一轮已通过，本轮没有再改这两个文件。

命中路径安全的前提是：缓存项只能被同一用户、同一返回地址、同一实体语言取回，并且权限或实体变化会使标签失效。下面的检查针对的是这个前提，不是「命中时再查一次权限」。

## 3. 缓存键审查

键为：

`xedc_content_operations`、实体类型、实体 ID、实体语言、`destination` 的 SHA-256。

实际存下的节点 314、简体中文、管理员条目：

`xedc_content_operations:node:314:zh-hans:02c11b9a0c07…:[languages:language_interface]=zh-hans:[languages:language_url]=zh-hans:[theme]=xedc_admin:[user]=1:[workspace]=live`

`02c11b9a0c07` 是 `/admin/content` 的哈希前缀。键里存的是完整 SHA-256，不是这段前缀。

不同列表状态会改变 `destination`，因此键不同。同一请求里重置 destination 服务后实测：

| 请求 | destination | 哈希前缀 |
| --- | --- | --- |
| 默认列表 | `/admin/content` | `02c11b9a0c07` |
| 标题搜索 | `?title=储存测试` | `3051f04f47cc` |
| 类型筛选 | `?type=photo` | `2f3d2a18e9e1` |
| 状态筛选 | `?status=1` | `c4ff8c2e112f` |
| 语言筛选 | `?langcode=zh-hans` | `89d9acac5677` |
| 分页 | `?page=1` | `9628b9add12b` |
| 按标题升序 | `?order=title&sort=asc` | `3660de58106a` |
| 按更新时间降序 | `?order=changed&sort=desc` | `e0219f6e8026` |

哈希输入是完整 destination 字符串。搜索、筛选、分页、排序不会共用一条菜单缓存。SHA-256 碰撞不构成实际风险。

`RedirectDestination::get()` 会把第一次算出来的地址留在当前 PHP 进程里。PHP-FPM 每个请求都是新进程，第一次调用看到的就是当前页。Drush 长进程里如果先在别的请求上调用过它，后面的模拟请求会沿用旧地址。这是探针现象：有两次内核采样因此重建了操作链接（215 次别名查询）。把 destination 重新按已压入的 `/admin/content` 请求计算后，下一次内核回到 57 次别名，并且直接读取节点 314 的缓存项仍然命中。生产页面不存在这个跨请求残留。

## 4. Cache Context 审查

插件写入的上下文是 `renderer.config` 的必填上下文，再加上 `user` 和 `languages:language_url`。站点没有覆盖 `renderer.config`。必填上下文是 `languages:language_interface`、`theme`、`user.permissions`。

`CacheContextsManager::optimizeTokens()` 会把 `user.permissions` 从最终 CID 里去掉，因为 `user` 是它的祖先。优化后的上下文是：

`languages:language_interface`、`theme`、`user`、`languages:language_url`。

`user.permissions` 不再出现在 CID 里，并不等于权限元数据被丢掉。`AccountPermissionsCacheContext` 的元数据仍会合并：角色配置实体标签和 `access_policies`。管理员节点 314 的实存标签里有 `config:user.role.authenticated`、`config:user.role.administrator`、`access_policies`。

`UserCacheContext::getCacheableMetadata()` 返回空元数据。用户实体变化不会由这个上下文自动打上 `user:{uid}`。插件自己加了 `user:{uid}`，这一项是必要的。

渲染过程中工作区上下文会冒泡。最终 CID 含 `[workspace]=live`。早期 `render_cache->get()` 不带工作区上下文，由 Variation Cache 的重定向找到最终项。站点当前没有工作区实体。临时工作区 `xedc_cache_audit2` 下重新渲染是未命中，没有取回 live 条目；该工作区已删除。

没有发现缺失的、会把两个用户或两个返回地址合成一个 CID 的上下文。

## 5. Cache Tags 审查

节点 314 管理员条目的标签：

`access_policies`、`config:user.role.administrator`、`config:user.role.authenticated`、`node:314`、`rendered`、`user:1`。

覆盖关系：

| 变化 | 由哪一个标签失效 | 实测 |
| --- | --- | --- |
| 角色权限配置被修改 | `config:user.role.{id}` | 临时角色授权后未命中 |
| 访问策略变化 | `access_policies` | 标签存在；本轮没有单独制造策略变化 |
| 用户保存、停用、角色分配 | `user:{uid}` | 停用临时用户后未命中 |
| 节点保存、发布、撤下、删除、翻译保存 | `node:{id}` | 均未命中；删除后 `get()` 为 miss |
| 渲染缓存通用失效 | `rendered` | 核心在写入时追加 |

`user:{uid}` 不覆盖「只改角色权限、不保存用户」。那一类变化由角色配置标签覆盖。两种标签都在实存条目上，并分别做了失效试验。

同一 PHP 进程里，缓存标签校验和可能在事务提交前保持旧值。翻译试验在同一进程里曾经表现为校验和不变、渲染仍命中。这不能当成失效结论。第二个进程里的结果见第 8 节。

## 6. max-age 审查

实存条目 `expire=-1`，渲染数组 `max-age=-1`，即 `Cache::PERMANENT`。这与节点访问结果的 `cachePerPermissions()` 一致。

`RenderCache::isElementCacheable()` 和 `VariationCache::set()` 都不会保存 `max-age` 为 0 的元素。如果以后启用节点授权并且更新或删除访问变成 max-age 0，操作菜单不会被写入缓存，请求会退回每次重新检查访问。当前 `node_access` 只有一条 `all` 领域记录，没有 `hook_node_grants`，更新和删除不是这条路径。

## 7. 权限隔离验证

没有修改现有账号和现有角色。临时角色 `xedc_cache_audit`、用户 286 和 287、文章节点只在探针进程内存在，结束时 `left-users=0`、`left-nodes=0`、角色已删除。内容编辑角色的现有账号 uid 6 是停用状态，只做了账号切换，没有保存。

生产角色里和节点有关的权限：

- 匿名：`access content`。
- 认证：可创建、编辑、删除自己的文章、图片、视频，可查看自己的未发布内容。
- `content_editor`：`administer nodes`、翻译、删除任意对应类型内容、`access content overview`。不是管理员角色。
- 管理员角色：`isAdmin()`，没有逐条权限。

同一篇已发布图片节点 314（作者是管理员）在各自第一次渲染时都是未命中，菜单如下：

| 账号 | 命中 | 菜单 |
| --- | --- | --- |
| uid 1 管理员 | 第二次命中，HTML 与第一次相同 | 编辑、翻译、删除、查看 |
| 临时编辑（认证 + 仅用于审查的角色，无编辑别人图片的权限） | 未命中 | 翻译、查看 |
| 临时认证用户 | 未命中 | 查看 |
| 匿名 | 未命中 | 查看 |
| uid 2 认证用户 | 未命中 | 查看 |
| uid 6 停用的 content_editor，仅切换账号 | 未命中；`update` 允许 | 编辑、翻译、删除、查看 |

管理员第三次渲染仍然命中，HTML 与第一次相同。编辑、认证、匿名的 HTML 都与管理员不同。操作链接的 HTML 里没有 `form_token`。

路由 `system.admin_content`：管理员允许，临时编辑允许（有 `access content overview`），临时认证用户拒绝，匿名拒绝。匿名即使在字段被单独渲染时能得到「查看」，也不能打开内容列表。

临时编辑自己的文章第一次渲染未命中，菜单是翻译、删除、查看，没有编辑。删除来自认证角色的 `delete own article content`。没有编辑是这次访问检查的结果，不是管理员 HTML 被取回：该次渲染没有命中缓存。创建时请求的状态是未发布，保存后状态为已发布，探针在字段存在时写过 `moderation_state=draft`。本轮没有为追这个访问结果去改审核配置。

## 8. 缓存失效验证

临时数据用前缀 `xedc_cache_audit`，进程结束和关机回调都会删除。审查结束时按该前缀查询，节点 0、用户 0、角色不存在。

| 操作 | 结果 |
| --- | --- |
| 临时角色增加权限并保存 | 未命中 |
| 临时节点保存标题 | 未命中 |
| 发布 | 未命中 |
| 撤下 | 未命中 |
| 停用临时用户 | 未命中 |
| 删除临时节点后再 `get()` | miss |
| 同一进程内新增翻译 | 校验和仍为 1，渲染仍命中。这是进程内校验和滞后，不作为结论 |
| 进程 A 写入缓存后，进程 B 保存英文标题 | 校验和 1 变为 2，渲染未命中，节点已删除 |
| 激活临时工作区后再渲染 | 未命中，没有复用 live 条目；工作区已删除 |

翻译内容变化会失效。默认语言菜单上的四个链接在翻译标题变化后仍是同一类操作，失效后重建的是同一组链接，不会把别的用户的菜单留在缓存里。

## 9. 安全问题及风险等级

没有需要改代码的缺陷。

| 项 | 等级 | 说明 |
| --- | --- | --- |
| 跨用户读取管理员菜单 | 未发生 | CID 含 `[user]={uid}`。其他账号第一次渲染都是未命中，HTML 与管理员不同 |
| 命中后绕过访问检查 | 设计如此，当前可接受 | 命中不调用 `access()`。用户、角色、节点标签已验证会失效 |
| `user.permissions` 不在最终 CID | 无漏洞 | 优化后角色标签仍写入条目。改角色权限已验证未命中 |
| `user` 上下文本身不带 `user:{uid}` | 已由插件补上 | 停用用户已验证未命中 |
| 旧 destination 用到别的列表 | 未发生 | 八种列表状态哈希不同 |
| 缓存 CSRF 令牌 | 未发生 | 操作 HTML 的 token 标记为 0。批量操作令牌留在页面上，页面保持不可缓存 |
| 工作区串缓存 | 未观察到 | 生产环境 0 个工作区。临时工作区未命中 live 条目 |
| 同一进程里翻译看起来没有失效 | 探针限制 | 第二个进程已确认失效 |

## 10. 剩余 57 次路径别名查询来源

热内核三次都是 57 次：53 次 `AliasRepository::preloadPathAlias`，4 次 `lookupByAlias`。别名 SQL 约 12.5ms。Drupal 的查询日志只记录到仓库方法，调用栈是用仓库包装器在热请求里采的。

内容列表的一行是 `node_field_data` 的一个翻译。同一节点的四种语言会占四行。50 行正好对应 50 次标题别名查询。

| 次数 | 路径 | 调用位置 | 累计 SQL | 能否安全优化 |
| --- | --- | --- | --- | --- |
| 50 | `/node/{id}`，按该行语言各查一次 | `Link::preRenderLink` ← 标题字段 `EntityField::render_item` | 约 11ms，与下面几项合计 12.5ms | 可以在这一次请求里预加载这 50 个路径。收益大约就是这 12ms，还要处理四种语言。不值得为此关闭全站别名 |
| 1 | `/user/1` | `UserThemeHooks::preprocessUsername`，作者列 | 含在上面 | 同一作者后续行走静态缓存。当前页作者集中，没有重复查询 |
| 4 | 四个快捷方式别名 | `_xedc_admin_shortcut_item` → `Shortcut::getUrl()` | 约 0.9ms | 可以随导航一起缓存。单独优化收益很小 |
| 1 | `/node/add` | `_xedc_admin_link`，页面预处理里的导航 | 含在 11ms 内 | 同上，属于导航 |
| 1 | `/user/logout` | `xedc_admin_preprocess_page` | 含在 11ms 内 | 同上 |

操作菜单命中后不再产生 `/edit`、`/translations`、`/delete` 的别名查询。两次 215 次别名的采样是 Drush 子请求把 destination 冻结成了别的值，操作菜单整表未命中；直接按 `/admin/content` 读取时缓存项仍在。那两次不计入这 57 次。

## 11. 剩余 PHP 执行耗时分布

热内核，操作菜单命中，uid 1，`/admin/content`：

| 采样 | 内核 | SQL 查询 | SQL 时间 | 别名查询 |
| --- | --- | --- | --- | --- |
| 1 | 658.8ms | 88 | 26.2ms | 57 |
| 2 | 595.4ms | 94 | 37.6ms | 57 |
| 3 | 593.7ms | 85 | 21.8ms | 57 |
| 4 | 491.9ms | 88 | 20.5ms | 57 |

四次中位数约 595ms，查询数中位数 88，SQL 中位数约 24ms。上一轮热内核中位数 597.5ms、85 次查询、57 次别名，两边一致。另有一次首次冷请求 786.6ms、335 次查询，其中 `locales_source` 157 次、`locales_location` 52 次，不纳入中位数。

阶段计时是另一个新进程，导航最先跑，后面的阶段更热。不能把它们相加成 595ms。

| 阶段 | 调用位置 | 耗时 | SQL 次数 | SQL 时间 | 别名 | 能否安全优化 |
| --- | --- | --- | --- | --- | --- | --- |
| 后台导航 | `_xedc_admin_navigation()`，由 `xedc_admin_preprocess_page` 调用 | 115.2ms | 26 | 9.6ms | 5 | 主要是 PHP 和菜单树，不是别名。缓存整段导航有收益，但要单独做用户和权限隔离，本轮未改 |
| 面包屑 | 当前路由的 breadcrumb build | 19.8ms | 5 | 1.2ms | 2 | 收益小 |
| 本地任务 | `system.admin_content` 的 local tasks | 4.4ms | 0 | 0 | 0 | 不值得动 |
| 页面顶部操作 | local actions | 1.2ms | 0 | 0 | 0 | 不值得动 |
| 管理菜单树 | menu tree load | 5.9ms | 0 | 0 | 0 | 导航已经加载过，这次是热的 |
| 视图初始化 | `setDisplay` + `preExecute` | 29.9ms | 1 | 2.5ms | 0 | 核心初始化成本，没有单独的安全切分点 |
| 视图执行 | `ViewExecutable::execute` | 39.7ms | 0 | 0 | 0 | 这次 0 次 SQL 是因为同一进程里结果已经热。新鲜内核仍有 `node_field_data` 查询。执行本身不是大头 |
| 视图渲染 | `render` + `renderRoot`，含标题、批量表单、字段主题 | 274.1ms | 51 | 11.3ms | 51 | 操作菜单已命中。51 次别名是标题。剩下约 260ms 是字段格式化、主题和批量表单。整页不能缓存，因为批量表单带 `form_token`，响应头仍是 Dynamic Page Cache `UNCACHEABLE` |
| 实体完整渲染 | 本视图按字段行输出，不走节点完整模板 | 包含在标题字段的 50 次链接里 |  |  |  | 没有另一层 `node.html.twig` 可拆 |

热内核里 `menu_tree` 约 16 次，`path_alias` 57 次。SQL 总共大约 21–38ms，不到内核时间的十分之一。

## 12. 是否需要下一轮代码修改

安全方面不需要。

性能方面，下一轮不优先做路径别名。57 次查询里 50 次是必须保留的标题链接，SQL 大约 12ms。关闭别名会改变前台和后台的 URL。

如果还要做一轮，唯一和耗时相称的目标是后台导航那大约 115ms 的 PHP。那需要一套独立的渲染缓存，并且要重复这一轮的用户、权限和失效验证。视图渲染的大约 274ms 分散在核心字段、主题和批量表单里，没有一个可以安全删掉的单点。批量操作和每页 50 行都不应该为了这个数字去掉。

## 13. 实际修改文件

本轮没有修改 Drupal 模块、主题、配置、核心或贡献模块。

新增报告：

- `/var/www/drupal/XEDC_CONTENT_LIST_CACHE_AUDIT_REPORT.md`

探针和原始输出只在 `/root/xedc-content-list-cache-audit/`，不在 Git 工作区。

## 14. 测试结果

| 测试 | 结果 |
| --- | --- |
| 八种列表 destination 哈希 | 八个值互不相同 |
| 管理员第二次渲染 | 命中，HTML 相同，无表单令牌 |
| 编辑、认证、匿名、uid 2 | 均未命中管理员条目，菜单符合各自权限 |
| uid 6 仅切换、不保存 | 未命中，菜单为内容编辑权限下的四项 |
| 角色权限、节点保存、发布、撤下、停用用户、删除节点 | 均未命中或删除后 miss |
| 跨进程保存翻译 | 校验和 1→2，未命中，临时节点已删除 |
| 临时工作区 | 未命中 live 条目，已删除 |
| 路由访问 | 管理员和临时编辑允许；认证用户和匿名拒绝 |
| 热内核 | 491.9 / 593.7 / 595.4 / 658.8ms，别名都是 57 |
| 直接读取节点 314 缓存项 | 命中，有 markup，max-age -1 |
| 清理 | 前缀 `xedc_cache_audit` 的用户、角色、节点、工作区均为 0 |

限制：没有登录生产账号做浏览器点击。uid 6 保持停用。没有改生产内容、生产角色和权限。文章在现有保存逻辑下会变成已发布，所以临时文章在断言后立即删除，没有留在站上。Drush 子请求有两次因 destination 残留重建了操作菜单，已单独标出，不作为生产未命中。

## 15. 未解决问题

- 临时编辑对自己那篇已发布文章没有看到「编辑」。第一次渲染是未命中，所以这是访问结果，不是串缓存。本轮没有展开内容审核配置。
- 后台导航大约 115ms、视图渲染大约 274ms 仍然在。本轮按只读要求没有做这两处缓存。
- 热请求的别名查询不会再降到 0，除非改变标题链接的生成方式。那部分 SQL 大约 12ms。
