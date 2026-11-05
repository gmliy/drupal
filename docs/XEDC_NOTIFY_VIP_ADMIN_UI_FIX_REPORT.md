# XEDC 前台通知与私信修复、VIP 管理整合及通知后台 UI 改造报告

日期：2026-11-05  
环境：Drupal 11.4.11 / PHP 8.5.12 / https://gitxx.xyz  
性质：现有功能修复、后台菜单整合、UI 改造与专项验收  
约束：未 commit / 未 push；未改 Core / 第三方模块 / config/sync / Nginx / PHP-FPM；未新建第二套 VIP/通知/私信体系。

---

## 1. 修改文件清单

### 前台导航 / 通知中心 / 私信

| 文件 | 说明 |
|------|------|
| `web/themes/custom/xedc/templates/includes/xedc-header.html.twig` | 确认不渲染通知/私信角标入口（工作区相对 HEAD 无残留角标） |
| `web/modules/custom/xedc_member/src/Service/MemberAliasManager.php` | 别名后缀最长前缀匹配，修复 `/{slug}/messages/to/{uid}`、嵌套 notifications 路径 404 |
| `web/modules/custom/xedc_notify/src/Form/MarkAllReadForm.php` | 新增：通知中心「全部已读」POST 表单 |
| `web/modules/custom/xedc_notify/src/Controller/NotificationController.php` | 列表注入 MarkAllReadForm；旧 GET `markAllRead` 改为提示并回通知中心 |
| `web/modules/custom/xedc_notify/templates/xedc-notify-notifications.html.twig` | 使用 `read_all_form`，移除 GET 链接 |
| `web/modules/custom/xedc_notify/css/notify.css` | 全部已读按钮样式 |
| `web/modules/custom/xedc_notify/src/ProfileActionsBuilder.php` | 公开主页私信：登录用户直达会话；匿名用户跳转登录 |

### VIP 管理统一页

| 文件 | 说明 |
|------|------|
| `web/modules/custom/xedc_core/src/Controller/VipHubController.php` | 新增：`/admin/people/xedc-vip` Tab 入口，复用现有 Form/Controller |
| `web/modules/custom/xedc_core/css/vip-hub.css` | VIP 管理页样式 |
| `web/modules/custom/xedc_core/xedc_core.libraries.yml` | 注册 `vip_hub` |
| `web/modules/custom/xedc_core/xedc_core.routing.yml` | `vip_hub`；旧 `vip_admin` / `vip_codes` 重定向到对应 Tab |
| `web/modules/custom/xedc_core/src/Form/VipGrantForm.php` | 提交后回到 VIP 管理「手动开通」Tab；按钮「开通 VIP」 |
| `web/modules/custom/xedc_core/xedc_core.links.menu.yml` | 隐藏旧 VIP 菜单；新增 VIP 管理菜单项 |
| `web/modules/custom/xedc_core/xedc_core.links.task.yml` | 互动 Tab 中 VIP 入口指向 hub |

### 通知管理后台 UI / 导航

| 文件 | 说明 |
|------|------|
| `web/modules/custom/xedc_notify/src/Controller/AdminNotificationController.php` | 概览统计、列表与详情 UI |
| `web/modules/custom/xedc_notify/src/Form/OfficialCampaignForm.php` | 表单排版；默认接收对象改为「指定会员」防误发 |
| `web/modules/custom/xedc_notify/css/admin-notify.css` | Modern SaaS 样式（Gin 变量，支持暗色） |
| `web/modules/custom/xedc_notify/xedc_notify.libraries.yml` | 注册 `admin` |
| `web/modules/custom/xedc_notify/xedc_notify.routing.yml` | VIP 记录/设置路由改为 hub 重定向 |
| `web/modules/custom/xedc_notify/xedc_notify.links.menu.yml` | 禁用独立 VIP 通知/提醒菜单 |
| `web/themes/custom/xedc_admin/includes/navigation.theme` | 用户管理：通知管理 + VIP 管理；会员与互动改指 engagement_hub |

### 验收脚本

| 文件 | 说明 |
|------|------|
| `web/modules/custom/xedc_notify/tests/manual/notify-vip-admin-ui-acceptance.php` | Drush 隔离验收（44 项） |
| `web/modules/custom/xedc_notify/tests/manual/browser-notify-vip-ui-acceptance.mjs` | Chromium 浏览器验收（23 项） |

---

## 2. 前台导航图标移除情况

- 桌面端 `header.xn` 不再渲染通知/私信图标与未读角标。
- 移动端抽屉 `.xn-sheet` 亦不含 `/member/notifications`、`/member/messages` 公共入口。
- 会员中心侧栏仍保留「通知」「私信」入口；路由、服务、表、未读状态与触发机制均保留。
- 浏览器实测：匿名与会员登录后顶栏均无通知/私信角标（PASS）。

---

## 3. 通知「全部已读」根因与修复

**根因**

1. 模板使用 CSRF GET 链接指向 `/member/notifications/read-all`。
2. 会员别名出站/入站把路径改写成 `/{slug}/notifications/read-all` 时，旧别名解析无法匹配嵌套后缀，导致「页面不存在」。
3. 即便路由命中，用 GET 修改状态也不符合安全要求。

**修复**

- 新增 `MarkAllReadForm`（Drupal Form API + CSRF POST），在当前通知中心渲染并提交。
- 成功后重定向回同一通知中心（按当前分类）。
- 仅对当前登录会员 `markAllRead($uid)`；匿名/无权限走表单错误提示。
- 旧 GET 路由保留为兼容：只提示并回通知中心，不再改状态。
- 别名 `internalPath()` 改为最长前缀匹配，嵌套路径可正确映射到 `/member/...`。

---

## 4. 公开主页私信根因与修复

**根因**

- 公开主页私信链接为 `/member/messages/to/{uid}`；在 vanity 出站场景下可能变成 `/{slug}/messages/to/{uid}`，旧别名解析失败 → 404。

**修复**

- `MemberAliasManager::internalPath()` 支持 `messages/to/{uid}` 等嵌套后缀。
- `MessageController::start()` 既有逻辑：拒绝自己/停用/不存在用户；`getOrCreateThread` 防重复；参与者校验防越权。
- 匿名访问公开主页「私信」改为登录页 + destination（实测 `/r/dcxe` 显示登录链接；登录会员点击进入 `/member/messages/{tid}`）。

---

## 5. VIP 管理统一页面结构

地址：`/admin/people/xedc-vip?tab=...`

| Tab | 内容 | 复用 |
|-----|------|------|
| overview | 真实 DB 统计：总数/有效/过期/近 7 日开通/近 7 日兑换/7 日内到期 | `xedc_vip` / `xedc_vip_log` / `xedc_vip_code` |
| grant | 手动开通 | `EngagementAdminController::vip()` + `VipGrantForm` → `MembershipManager` |
| codes | 卡密管理 | `VipCodeAdminController::overview()` |
| reminders | VIP 提醒设置 | `VipNotifySettingsForm`（`xedc_notify.settings`） |
| records | VIP 通知记录 | `AdminNotificationController::vipList()` |

权限按 Tab 过滤；无权限 Tab 不展示。未复制业务逻辑到巨型控制器。

---

## 6. 旧路由兼容

| 旧路径 | 行为 |
|--------|------|
| `/admin/xedc/vip` | → `/admin/people/xedc-vip?tab=grant` |
| `/admin/people/xedc-vip-codes` | → `?tab=codes` |
| `/admin/people/xedc-notifications/vip` | → `?tab=records` |
| `/admin/people/xedc-notifications/vip/settings` | → `?tab=reminders` |

卡密生成/明文揭示/CSV/作废等子路由仍独立可用。浏览器实测四条兼容跳转全部 PASS。

---

## 7. 后台导航最终结构（用户管理）

硬编码于 `xedc_admin` `navigation.theme`：

1. 会员管理  
2. 内容审核  
3. 会员与互动 → `xedc_core.engagement_hub`（**不再**把 `/admin/xedc/vip` 算作本分组）  
4. 通知管理 → `/admin/people/xedc-notifications`  
5. VIP 管理 → `/admin/people/xedc-vip`（含旧 VIP/卡密/VIP 通知路径高亮）

已移除独立菜单：VIP 通知记录、VIP 提醒设置、VIP 卡密管理。  
Drupal `links.menu.yml` 中对应项 `enabled: false`，避免系统菜单重复。

---

## 8. 通知管理 UI 改造情况

- 页面头：标题「通知管理」+ 副标题说明。  
- 真实统计：官方任务总数 / 已发送 / 待处理 / 失败或部分失败（来自 campaign 状态字段）。  
- 功能入口：官方通知列表、创建官方通知、VIP 管理。  
- 官方列表：表格 + 状态标签 + 空态。  
- 创建表单：Gin + `admin-notify.css`；**默认受众改为「指定会员」**；按钮「保存草稿」；打开页面不会发送。  
- 样式使用 Gin CSS 变量，暗色模式随 Gin 主题变量切换（浏览器暗色像素级对比见 NOT TESTED）。

---

## 9. 权限与安全检查

| 检查项 | 结果 |
|--------|------|
| 匿名访问 VIP/通知后台 | HTTP 403 PASS |
| 普通会员 `vip_hub` / `admin_overview` | DENY PASS |
| 内容编辑角色上述管理路由 | DENY PASS |
| 管理员 VIP hub | ALLOW PASS |
| 全部已读仅更新本人 | 隔离测试 PASS |
| 私信不能发给自己 / 停用账号 | PASS |
| 跨线程访问拒绝 | PASS |
| 卡密 HMAC、一次性明文、不可重复兑换 | PASS（复用既有 VipCodeManager） |
| 官方通知打开页不自动广播 | PASS（仅草稿表单） |
| CSRF：全部已读走 Form API POST | PASS |

---

## 10. 实际测试结果

### A. Drush 隔离脚本

`vendor/bin/drush php:script web/modules/custom/xedc_notify/tests/manual/notify-vip-admin-ui-acceptance.php`

**SUMMARY pass=44 fail=0**

覆盖：顶栏无角标、全部已读 POST、别名嵌套、私信去重/自聊/停用/越权、VIP hub/重定向/导航、卡密生成兑换、通知管理 UI 接线。隔离用户与本轮消息/线程/卡密批次已清理。

### B. Chromium 浏览器

`browser-notify-vip-ui-acceptance.mjs`（真实站点 https://gitxx.xyz）

**SUMMARY pass=23 fail=0**

含：匿名/会员顶栏与移动抽屉、VIP 五 Tab、四条旧路由跳转、用户管理导航文案、通知管理 UI 与统计、全部已读无 GET 链接、会员中心仍有通知/私信入口、公开主页 `/r/dcxe` 私信进入会话。本轮浏览器产生的测试线程已删除。

### C. 技术检查

| 项 | 结果 |
|----|------|
| `drush cr` | PASS |
| PHP lint（相关文件） | PASS |
| `drush status` bootstrap | Successful |
| `updatedb:status` | No database updates required |
| Watchdog PHP Error（近期） | 可见上传取消 unlink 历史错误，与本任务无关（记录，未改） |
| config/sync | 无变更 PASS |
| git commit/push | 未执行 PASS |

---

## 11. PASS / FAIL / NOT TESTED 汇总

| 编号 | 项 | 结果 |
|------|----|------|
| A1 | 桌面端无通知图标 | PASS |
| A2 | 桌面端无私信图标 | PASS |
| A3 | 移动端无通知图标 | PASS |
| A4 | 移动端无私信图标 | PASS |
| A5 | 会员中心可进通知 | PASS |
| A6 | 会员中心可进私信 | PASS |
| B1 | 全部已读不跳独立错误页 | PASS |
| B2 | 无 `/notifications/read-all?token=` 错误链接 | PASS |
| B3 | 安全 POST + CSRF（Form API） | PASS |
| B4 | 只更新当前用户 | PASS |
| B5 | 未读角标/样式（中心页内） | PASS（有未读时表单提交后回中心；顶栏角标已移除故不适用） |
| B6 | 重复点击 | PASS（表单幂等 markAllRead） |
| B7 | 无权限拒绝 | PASS |
| C1–C8 | 公开主页发私信/回复/去重/自聊/停用/匿名登录/越权 | PASS（回复与收件在服务层隔离测试；浏览器验证发起会话） |
| C9 | 浏览器双向真实会话长时间联调 | NOT TESTED（服务层已测收发；浏览器仅测发起） |
| D1–D14 | VIP 菜单/概览/开通/卡密/提醒/记录/权限/旧路由 | PASS |
| D15 | 卡密 CSV 导出浏览器点击 | NOT TESTED（路由与控制器保留；未点导出按钮） |
| D16 | 卡密作废浏览器点击 | NOT TESTED（逻辑保留；本轮未点作废） |
| E1–E8 | 通知管理打开/表单/列表/权限/不自动广播 | PASS |
| E9 | 暗色模式像素对比 | NOT TESTED（CSS 使用 Gin 变量；未切 Gin 暗色截图） |
| E10 | 管理页窄屏横向溢出截图 | NOT TESTED（有移动端 media query；未截图） |

---

## 12. 剩余问题及建议

1. **互动本地任务**仍以 `xedc_core.vip_admin` 为 `base_route`（兼容）。点击其中的「VIP 管理」会进入 hub；若希望互动页完全与 VIP 解耦，可另开任务把 base_route 改为 `engagement_hub`。  
2. **头栏徽章能力**（`xedc_notify/header` 库与模板）仍保留但未挂载，便于以后产品再开；当前符合「只去入口、不删功能」。  
3. **官方通知默认受众**改为指定会员，降低误发；若运营习惯全体发送，需在表单显式改选。  
4. Watchdog 中既有上传取消 unlink 错误与本任务无关，建议在上传专项中单独处理。  
5. 未做 Gin 暗色与管理页手机截图验收；建议上线前人工快速扫一眼。

---

## 最后检查

```text
git status --short   # 工作区既有未提交修改均保留；本任务新增/修改已列入第 1 节
config/sync          # 无变更
commit/push          # 未执行
```

**验收结论：CONDITIONAL PASS**  
核心功能（导航移除、全部已读 POST、公开主页私信、VIP 统一页与旧路由、通知管理 UI、权限隔离）均已实测通过；标注 NOT TESTED 的仅为暗色截图、管理窄屏截图、卡密 CSV/作废按钮点击与浏览器级长时间双向私信联调，不影响主路径验收。
