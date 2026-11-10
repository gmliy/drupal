# 自定义代码备份覆盖

扫描位置：本机站点 `/var/www/drupal`。只读对照 `drush` 能读到的 `config/sync/core.extension.yml`、`composer.lock` 和修复前的 `exclude.list`。

## 1. 已启用、且不是 Composer 安装的扩展

`composer.lock` 里的 Drupal 项目会装到 `web/modules/contrib`、`web/themes/contrib` 等安装路径。这些目录都能对上 lock 里的包，没有手动复制进 contrib、却不在 lock 里的目录。`web/core` 属于 Drupal 核心。

下面这些不在 Composer 安装路径里，全部算自定义代码：

| 名称 | 类型 | 路径 | 启用 |
| --- | --- | --- | --- |
| xa_views | 模块 | web/modules/custom/xa_views | 是 |
| xedc_admin_nav | 模块 | web/modules/custom/xedc_admin_nav | 是 |
| xedc_admin_ui | 模块 | web/modules/custom/xedc_admin_ui | 是 |
| xedc_ai | 模块 | web/modules/custom/xedc_ai | 是 |
| xedc_audit | 模块 | web/modules/custom/xedc_audit | 是 |
| xedc_auto_translate | 模块 | web/modules/custom/xedc_auto_translate | 是 |
| xedc_core | 模块 | web/modules/custom/xedc_core | 是 |
| xedc_home | 模块 | web/modules/custom/xedc_home | 是 |
| xedc_mail | 模块 | web/modules/custom/xedc_mail | 是 |
| xedc_member | 模块 | web/modules/custom/xedc_member | 是 |
| xedc_models | 模块 | web/modules/custom/xedc_models | 是 |
| xedc_moderation | 模块 | web/modules/custom/xedc_moderation | 是 |
| xedc_notify | 模块 | web/modules/custom/xedc_notify | 是 |
| xedc_quick | 模块 | web/modules/custom/xedc_quick | 是 |
| xedc_scheduler | 模块 | web/modules/custom/xedc_scheduler | 是 |
| xedc_site_audit | 模块 | web/modules/custom/xedc_site_audit | 是 |
| xedc_tags | 模块 | web/modules/custom/xedc_tags | 是 |
| xedc_ui_locale | 模块 | web/modules/custom/xedc_ui_locale | 是 |
| xedc_search | 模块 | web/modules/custom/xedc_search | 否（目录仍备份） |
| xedc | 主题 | web/themes/custom/xedc | 是，默认主题 |
| xedc_admin | 主题 | web/themes/custom/xedc_admin | 是，管理主题 |

gin、radix 在 `web/themes/contrib`，由 Composer 安装，不算自定义代码。

## 2. 其他位置

| 位置 | 扫描结果 | 修复前 | 修复后 |
| --- | --- | --- | --- |
| web/modules/custom、web/themes/custom | 上表 19 个模块、2 个主题 | 代码树会 rsync，但报告类 md 和一部分 bak 被排除规则删掉 | 模块和主题整目录进入备份和 custom-code.list。只去掉 bak 类残留、node_modules、vendor、.git |
| web/profiles | 只有 README.txt，没有安装配置 | 随代码树备份 | 没有可登记的安装配置 |
| web/modules、web/themes 里不在 lock 中的目录 | 没有 | — | — |
| web/libraries | 目录不存在 | — | 以后出现且不在 lock 里会登记 |
| web/sites/default/services.yml | 存在，325 字节 | 随代码树备份，没有单独核对 | 写入自定义代码清单并核对哈希 |
| web/sites/default/settings.php | 存在 | 随代码树备份 | 写入清单并核对哈希 |
| settings 的 include | `xedc_storage.runtime.php` 用变量引入，文件在 `web/modules/custom/xedc_core/` | 随 xedc_core 备份 | 仍随该模块备份，不单独拆出 |
| settings.local.php | 引入行是注释，文件不必存在 | — | — |
| composer.json path 仓库 | 没有。唯一仓库是 packages.drupal.org | — | 以后有 path 仓库会连目录备份，站外目录会放进暂存的 path-packages/ 并改写暂存里的仓库地址 |
| 仓库根被 Composer 引用的目录、patches/、scripts/ | composer.json 没有 scripts，patches/ 和 scripts/ 不存在 | — | 目录出现时会登记 |
| 主题前端 | `web/themes/custom/xedc/package.json` 与 package-lock.json；`build/css`、`build/js`、`src/`、`css/` 都在。node_modules 不备份 | 编译产物会进代码树；`*.bak-*` 会被删 | 源码和 build/css、build/js 都备份。恢复时重新构建，失败则用备份里的编译产物 |
| 站点根 `xedc.theme` | 不是模块，Composer 也没引用 | 随代码树 rsync | 仍随代码树备份，不放进模块清单 |
| 数据库与 config/sync | 扫描时 `drush config:status` 无差异 | 两者都备份，但每次都 cex | 无差异时不 cex。有差异时默认 cex 一次，`--no-cex` 可关 |

## 3. exclude.list 误伤

修复前，`docs`、`*REPORT*.md`、`*AUDIT*.md` 会按文件名命中模块内部。实际被删掉的自定义文件只有这两份，都在 `xedc_ai` 里：

- web/modules/custom/xedc_ai/docs/XEDC_AI_SEO_SELECT_AND_PUBLISH_REPORT.md
- web/modules/custom/xedc_ai/docs/XEDC_AI_SEO_APPLY_FIX_REPORT.md

修复后这两份保留。`docs`、`docs-archive`、`*REPORT*.md`、`*AUDIT*.md`、`_server/db.sql` 只作用于站点根或 `_server/db.sql` 这一条路径。模块、主题、安装配置、库目录内部不再套这些规则。

站点根上的报告和 `docs/`、`docs-archive/`、`_server/db.sql` 仍然排除，这是原来的预期。

## 4. 自定义代码里仍被排除的文件（请确认）

排除的是 bak 类残留，不是源码。`web/modules/custom` 里没有这类文件。全部 52 个都在 `web/themes/custom/xedc`：

修复前 `*.bak-*` 已经会删掉：

- src/scss/base/_shadcn.scss.bak-xn
- src/scss/base/_photos.scss.bak-sticky
- build/css/main.style.css.bak-sticky
- build/css/main.style.css.bak-xn
- css/xedc-navbar.css.bak-fix
- css/xedc-navbar.css.bak-toolbar

修复前 `*.bak`、`*.bak-*` 匹配不到、修复后会删掉的 `.bak2`、`.bakN`、`.bak.数字`：

- xedc.theme.bak2、bak3、bak4、bak5
- xedc.theme.bak.1792133742、.1792134857、.1792135877、.1792136460、.1792137133、.1792141706、.1792142272
- templates/comment/comment.html.twig.bak2、bak3
- templates/content/node--photo--full.html.twig.bak2 到 bak7
- templates/includes/home-sections.html.twig.bak3
- templates/page/page.html.twig.bak3、bak4
- templates/page/page--front.html.twig.bak2、bak3
- src/js/main.script.js.bak2、bak3
- includes/auth.inc.bak4、bak7、bak8、bak9、bak10
- js/auth.js.bak4、bak5、bak6、bak7、bak9
- js/usermenu.js.bak4
- css/xedc-meta.css.bak3、bak4、bak8
- css/auth.css.bak4、bak5、bak7、bak8、bak9、bak10

`web/sites/default/settings.php.bak-2026-11-01-1537` 不在模块或主题里，按站点配置残留排除。根目录 `opencode.json.bak` 同样排除。

正式源码（`xedc.theme`、`*.module`、模板、src、build/css、build/js）不在这份排除名单里。
