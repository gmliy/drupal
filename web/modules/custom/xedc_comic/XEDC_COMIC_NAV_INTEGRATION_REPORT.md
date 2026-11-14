# XEDC Comic 后台导航与控制台集成 — 交付报告

**日期：** 2026-11-14  
**环境：** 生产 Drupal 11（`/var/www/drupal`）  
**范围：** 内容菜单、XEDC 控制台卡片、控制台快捷操作（Drupal Shortcut）；不涉及导入/PDF/阅读器/R2。

---

## 一、修改文件清单

### xedc_comic（导航与访问控制）

| 文件 | 说明 |
|------|------|
| `src/Access/ComicHubAccess.php` | **新增**：概览路由 Hub 级访问（任一漫画管理相关权限） |
| `xedc_comic.routing.yml` | `xedc_comic.admin` 使用 `_custom_access: ComicHubAccess::access` |
| `xedc_comic.services.yml` | 注册 `access_check.xedc_comic.hub` |
| `xedc_comic.links.menu.yml` | **未改**（已存在 `xedc_comic.admin` → `parent: system.admin_content`，weight 25） |

### xedc_admin_ui（控制台 — 任务要求集成 Shortcut 与业务中心）

| 文件 | 说明 |
|------|------|
| `src/DashboardShortcutLinks.php` | **新增**：读取当前用户 Shortcut Set，`$url->access()` 过滤 |
| `src/DashboardHubCards.php` | **新增**：漫画中心控制台卡片 |
| `src/Controller/DashboardController.php` | 快捷操作：Shortcut 优先 + legacy 去重合并；传入 hubs / 管理快捷方式 URL |
| `xedc_admin_ui.services.yml` | 注册上述服务 |
| `xedc_admin_ui.module` | 主题变量：`hubs`、`shortcut_manage_url`、`shortcut_settings_url` |
| `templates/xedc-admin-dashboard.html.twig` | 业务中心区块、管理快捷方式链接 |
| `css/dashboard.css` | Hub 卡片样式（模块侧） |

### xedc_admin 主题（与现有 XEDC UI 一致）

| 文件 | 说明 |
|------|------|
| `templates/xedc-admin-dashboard.html.twig` | 覆盖模块模板：业务中心 + 快捷方式管理入口 |
| `css/dashboard.css` | Hub 网格与移动端断点（含 `@media (max-width: 420px)` 等） |

**未修改：** Drupal Core/Contrib、`config/sync`、Nginx/PHP/DB/R2、其他模块业务逻辑。

---

## 二、内容管理菜单集成

| 项 | 结果 |
|----|------|
| 插件 ID | `xedc_comic.admin`（标准 `*.links.menu.yml` Menu Link Plugin） |
| 父级 | `system.admin_content`（已核实） |
| 标题 | 漫画中心 |
| 路由 | `/admin/xedc/comic`（`xedc_comic.admin`） |
| 重复链接 | `menu_count=1`（仅一条指向该路由的菜单定义） |
| 可见性/访问 | 跟随路由 `ComicHubAccess`（非仅隐藏菜单） |

**状态：PASS**（`drush cr` 后路由与菜单定义检查通过）

**XEDC 侧栏：** 生产后台使用 `xedc_admin` 自定义左侧导航（`navigation.theme` 硬编码「内容管理」子项），**不自动展示** `system.admin_content` 下的 Menu Link Plugin。已在「内容管理」子菜单增加「漫画中心」（路由 `xedc_comic.admin`，经 `$url->access()` 过滤）。

**状态：PASS**（Drush 初始化 `xedc_admin` 主题后侧栏含漫画中心链接）。

---

## 三、XEDC 控制台「漫画中心」入口

| 项 | 结果 |
|----|------|
| 控制台实现 | `xedc_admin_ui` → `DashboardController::page()` + 主题 `xedc-admin-dashboard.html.twig`（`xedc_admin` 主题覆盖） |
| 卡片 | `DashboardHubCards`：标题「漫画中心」，描述与需求一致，链至 `/admin/xedc/comic` |
| 权限 | `Url::fromRoute('xedc_comic.admin')->access($account)` |
| uid=1 管理员 | `hub_count=1` |
| content_editor 用户 `dc` (uid=6) | `hubs=1`，`route=Y` |

**状态：PASS**（服务层与路由访问检查）

**状态：NOT TESTED** — 真机/手机浏览器打开 `/admin/xedc` 目视排版（CSS 断点已存在）。

---

## 四、快捷操作：原有实现与根因

### 审计结论（非猜测）

| 区域 | 实现位置 | 行为 |
|------|----------|------|
| **左侧导航「快捷方式」** | `web/themes/custom/xedc_admin/includes/navigation.theme` → `_xedc_admin_shortcut_item()` | 已使用 `shortcut_set->getDisplayedToUser()` + 每条 `$url->access()` |
| **控制台「快捷操作」** | `xedc_admin_ui/src/Controller/DashboardController::operations()`（改前） | **硬编码**新建美图/视频/文章、快速发布、图片导入、内容列表、审核队列等；**未读取 Shortcut 实体** |

**根因：** 控制台快捷操作与主题侧栏快捷方式是两个独立实现。用户在 Drupal 原生快捷方式（`/admin/config/user-interface/shortcut` 或 customize 页）添加的链接只进入 Shortcut Set，侧栏会显示，但控制台仍只渲染硬编码列表，因此「漫画中心」等菜单路由即使用户已加入快捷方式集合，也不会出现在控制台。

**本次 FIXED：** `DashboardShortcutLinks` 读取当前用户 displayed Shortcut Set；`operations()` 先输出 Shortcut（经 access 过滤），再追加 legacy 项并按 URL 去重，避免丢失原有按钮。

**管理入口：** 有权限时模板显示「管理快捷方式」→ `entity.shortcut_set.customize_form`（uid=1 实测 `/admin/config/user-interface/shortcut/manage/default/customize`）；集合列表 → `entity.shortcut_set.collection`（`/admin/config/user-interface/shortcut`）。

---

## 五、Drupal Shortcut Set 实际使用情况

| 项 | 实测值 |
|----|--------|
| 默认集合 | 存在：`shortcut_set:default` |
| uid=1 使用集合 | `getDisplayedToUser()` → `default` |
| 集合内快捷方式数量（测试时） | 4 条 → 添加漫画后 5 条 |
| 管理权限 | uid=1 可生成 `shortcutsManageUrl`、`shortcutsSettingsUrl` |
| 控制台读取 | `dashboard_shortcuts` 与 Shortcut 实体条数一致（access 过滤后） |

### 默认快捷方式添加/移除测试（实体层，同步控制台服务）

1. 向 `default` 添加「漫画中心」→ `internal:/admin/xedc/comic` → **PASS**（控制台 `shortcutLinks` 含 `/admin/xedc/comic`，标签「漫画中心」）
2. 删除该 Shortcut 实体 → **PASS**（控制台不再包含漫画 URL，`count=4`）
3. 交付前已 **恢复** 默认集合中的「漫画中心」快捷方式（shortcut id=6）

### 合并快捷操作（Shortcut + legacy）

uid=1 `merged_ops=9`：4 条 Shortcut + 5 条 legacy（新建美图/视频/文章、内容列表、审核队列等），**原有 legacy 未丢失 — PASS**。

---

## 六、权限测试

实际权限 ID（`xedc_comic.permissions.yml`，勿与需求文案混用）：

- `administer comics`
- `create comics`
- `edit own comics` / `edit any comics`
- `delete comics`
- `manage comic chapters`（非 `manage chapters`）
- `manage comic pages`
- `import comic archives`
- `view comic statistics`
- `view published comics`（前台）

| 账号 | 漫画路由 | 菜单 URL | 控制台 Hub |
|------|----------|----------|------------|
| uid=1 管理员 | PASS | PASS | PASS |
| uid=2 `a`（仅 authenticated） | PASS 拒绝 | PASS 拒绝 | PASS 0 卡片 |
| uid=6 `dc`（content_editor） | PASS 允许 | PASS 允许 | PASS 1 卡片 |

快捷方式条目经 `$shortcut->getUrl()->access($account)` — **不能绕过路由访问 — PASS**。

---

## 七、测试清单汇总

| # | 测试项 | 状态 |
|---|--------|------|
| 1 | `drush cr` | **PASS** |
| 2 | 路由 `xedc_comic.admin` 存在 | **PASS** |
| 3 | 内容菜单链接唯一、parent 正确 | **PASS** |
| 4 | `/admin/xedc` 漫画中心卡片（有权限用户） | **PASS**（服务层） |
| 5 | 默认 Shortcut Set 存在 | **PASS** |
| 6 | uid=1 使用集合为 default | **PASS** |
| 7 | 原生快捷方式添加漫画中心 | **PASS**（实体创建） |
| 8 | 控制台快捷操作自动出现 | **PASS** |
| 9 | 移除漫画快捷方式 | **PASS** |
| 10 | 移除后控制台同步消失 | **PASS** |
| 11 | 无权限用户不可访问路由/Hub | **PASS** |
| 12 | 手机后台布局 | **NOT TESTED**（仅 CSS 断点审计） |
| 13 | 原有 legacy 快捷操作未丢失 | **PASS** |
| 14 | 未覆盖/删除既有内容管理菜单 | **PASS**（仅新增既有插件，未重建菜单） |

---

## 八、是否修改其他模块

**是，受限修改：**

- **xedc_admin_ui**：控制台数据层与模板（Shortcut 集成、漫画 Hub 卡片）；原因：控制台由该模块 `DashboardController` 渲染，快捷操作原先硬编码于此。
- **xedc_admin 主题**：仪表盘 Twig/CSS 与模块对齐；原因：生产后台实际使用主题覆盖模板。

**未修改** xedc_comic 以外的业务模块逻辑。

---

## 九、遗留问题 / 建议

| 项 | 说明 | 状态 |
|----|------|------|
| 控制台页面 cache tags | `DashboardController` 未附加 `shortcut_set:{id}` 等 tag；Shortcut 变更后可能直至页面 cache 过期才刷新（侧栏主题已有 shortcut cache 辅助） | **BLOCKED** 低优先级，可按需加 tag |
| 浏览器/UI 抽检 | 左侧菜单点击、手机真机目视 | **NOT TESTED** |
| xedc_comic 模块 Git | 整个 `xedc_comic` 目录仍为未跟踪状态；导航相关仅为上述 3+1 个文件与其余模块未提交改动 | 运维需按发布流程 `git add`/部署 |

---

## 十、总体验收

**目标：** 漫画中心出现在内容管理菜单与 XEDC 控制台，并可通过 Drupal 原生快捷方式管理，同步显示在控制台快捷操作区域。

**结论：** **PASS**（核心行为已在生产站点通过 Drush/实体/访问检查验证）；UI 点击与手机目视建议管理员做一次登录确认。