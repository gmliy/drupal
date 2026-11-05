# XEDC 用户管理导航、通知设置移除、会员开通修复与 VIP 卡密兑换 — 交付报告

**报告路径：** `docs/XEDC_USER_MANAGEMENT_VIP_REDEEM_REPORT.md`  
**日期：** 2026-11-04  
**项目：** `/var/www/drupal`  
**环境：** Drupal 11.4.11 / PHP 8.5.12  

---

## 1. 修改前项目状态

| 项 | 状态 |
|----|------|
| Git 分支 | `main` @ `93da5e3` |
| 工作区 | 已有大量未提交改动（upload、tags、notify、主题等），本轮全部保留 |
| 后台左侧导航 | `web/themes/custom/xedc_admin/includes/navigation.theme` 硬编码「用户管理」下三项：会员管理 / 内容审核 / 会员与互动 |
| 通知设置 | `/member/notification-settings` + 会员导航入口 + `PreferenceManager` 读取 `xedc_notification_pref` |
| 手动开通 | `VipGrantForm`（`/admin/xedc/vip`） |
| VIP 体系 | `xedc_core.membership` / `MembershipManager` / `xedc_vip` |
| 卡密系统 | 不存在 |
| 无 `xedc_admin` 模块 | 后台 UI 在主题 `xedc_admin` + `xedc_admin_ui` / `xedc_admin_nav` |

依据文档已阅读：`XEDC_NOTIFY_*` 三份报告与 `xedc_notify` / `xedc_core` / `xedc_member` 代码。

---

## 2. 通知设置功能移除情况

### 已做

1. 会员中心导航去掉「通知设置」。
2. 通知中心去掉「通知设置」链接。
3. `PreferenceController` 改为重定向到通知中心（旧 URL 不可再打开设置表单）。
4. `PreferenceManager::isEnabled()` **不再读取**个人偏好表，一律按系统默认（社交类默认开启；收藏跟随 `xedc_notify.settings.favorite_notify_default`）。
5. **未删除** `xedc_notification_pref` 表与历史数据。
6. 保留私信、通知中心、角标、关注/赞/藏/评、VIP、官方通知。

### 默认通知行为（移除设置页后）

| 类型 | 行为 |
|------|------|
| 关注 / 点赞 / 收藏 / 评论 | 按系统默认产生（`isEnabled`→defaults） |
| 私信 | 不受偏好表影响（原逻辑） |
| VIP / 官方 | 不受个人偏好表影响（原逻辑） |

---

## 3. 用户管理左侧导航最终结构

来源：`navigation.theme` → `_xedc_admin_navigation_definitions()` 的 `users` 分组。

**用户管理**

- 会员管理（原有）
- 内容审核（原有）
- 会员与互动（原有）
- 通知管理（复用 `xedc_notify.admin_overview`）
- VIP 通知记录（复用 `xedc_notify.admin_vip`）
- VIP 提醒设置（复用 `xedc_notify.admin_vip_settings`）
- VIP 卡密管理（新：`xedc_core.vip_codes`）

**未添加「私信管理」**：当前无后台私信管理功能，避免虚构入口。  
菜单仍按权限过滤；非管理员看不到管理项。

---

## 4. 手动开通会员错误原因

Watchdog（2026-11-04）：

> `Call to undefined method Drupal\xedc_core\Form\VipGrantForm::entityTypeManager()`  
> 发生于 `VipGrantForm::validateForm()`

**根因：** `VipGrantForm` 继承 `FormBase`，却调用了仅在 `ControllerBase` 上常见的 `$this->entityTypeManager()`，未注入 `EntityTypeManagerInterface`，提交校验时直接 Fatal，表现为「网站报错、无法开通」。

次要问题：字段为 `entity_autocomplete`，与「输入用户名」操作习惯不完全一致；提交按钮文案为「保存」。

---

## 5. 手动开通会员修复方案

文件：`web/modules/custom/xedc_core/src/Form/VipGrantForm.php`

1. 正确注入 `entity_type.manager` 与 `xedc_core.membership`。
2. 字段改为「用户名」文本框，按 `name` 加载用户（拒绝把裸 UID 当用户名乱用）。
3. 停用账号拒绝开通。
4. 已是有效 VIP 时，预设天数的「开通」会明确要求改用「延长」或自定义日期重设，避免静默覆盖。
5. 提交按钮改为 **开通**。
6. `submitForm` 捕获异常并写日志，避免未处理异常变白屏。
7. `MembershipManager::extend()` 修正天数校验（允许卡密等任意正天数，上限 3650）；VIP 写入后通知失败 try/catch，不回滚 VIP。

---

## 6. VIP 卡密系统架构

| 层 | 说明 |
|----|------|
| 服务 | `xedc_core.vip_codes` → `VipCodeManager` |
| VIP 写入 | 仅调用 `MembershipManager::grant` / `extend` |
| 后台 | `/admin/people/xedc-vip-codes` 生成/列表/作废/一次性明文与 CSV |
| 前台 | `/member/redeem` + 会员中心「VIP 卡密兑换」 |
| 权限 | `administer xedc vip codes` / `redeem xedc vip codes` |

不接入支付；不新建第二套 VIP 主表。

---

## 7. 数据库表结构与 update hook

**Update：** `xedc_core_update_10019`

| 表 | 要点 |
|----|------|
| `xedc_vip_code_batch` | batch_id, batch_uuid(唯一), vip_days, quantity, expires_at, note, created_uid, created, status |
| `xedc_vip_code` | code_id, batch_id, **code_hash(唯一)**, code_prefix(脱敏), status, vip_days, expires_at, redeemed_uid, redeemed, created |

索引：batch_status、status、redeemed_uid、prefix。  
schema 定义并入 `EngagementSchema::tables()`。  
卸载策略：本轮不删兑换记录；表由模块 schema 管理。

---

## 8. 卡密生成与安全存储

- 格式：`XXXX-XXXX-XXXX-XXXX`（高熵字母数字，排除易混字符）。
- `random_int` 生成；`hash_hmac('sha256', …, hash_salt\|xedc_vip_code)` 入库。
- **不存明文**；列表仅 `code_prefix` + 掩码。
- 明文仅生成后写入 Private TempStore，在 reveal 页展示一次，可复制/导出 CSV；列表不可还原。
- 不写明文到 watchdog / URL。
- 生成上限：单批 200；天数 1–3650。

---

## 9. 卡密兑换与并发控制

1. Flood 限频（UID / IP）。
2. 事务 + `SELECT … FOR UPDATE`。
3. 校验状态 / 作废 / 过期。
4. 调用 `grant` 或 `extend`。
5. `UPDATE … WHERE status=unused` 原子标记已兑换。
6. 任一步失败 rollback，避免「VIP 已开但卡仍可兑」或「卡已兑但无 VIP」。

双进程同码实测：仅 1 人成功，库中 `redeemed` 仅一条。

---

## 10. VIP 有效期规则

| 场景 | 处理 |
|------|------|
| 无 VIP / 已过期 | `grant`：从当前时间起算天数 |
| 有效 VIP | `extend`：在原 `expires` 上顺延，保留原 `starts` |
| 不得缩短原有效期 | 顺延逻辑保证 |

---

## 11. VIP 通知集成

`MembershipManager::write()` 成功后仍调用 `xedc_notify_on_vip`；异常捕获不影响 VIP/卡密事务。兑换失败不发通知。幂等仍由 `xedc_vip_notify_sent` 负责。

---

## 12. 权限与安全

| 角色 | 能力 |
|------|------|
| 管理员 | 生成/查看/作废/导出一次明文 |
| 认证会员 | 兑换 + 查看自己的兑换记录 |
| 匿名 | 不可兑换 |
| 普通会员 | 不可进后台卡密管理 |

路由权限字符串控制；非仅藏按钮。

---

## 13. 测试结果

隔离前缀 `vr_*`，测后已清理。

| 项 | 结果 |
|----|------|
| GRANT_form_button / valid / missing / blocked / dup / extend | PASS |
| PREF_ignored_follow / 旧路由仍在但重定向 | PASS |
| CODE_generate / hash / unique / redeem / reuse / revoked / expired / invalid / anon | PASS |
| CODE_extend_active | PASS |
| CODE_concurrent_serial | PASS |
| CODE_parallel_race（双进程） | PASS |
| NAV 三项保留 + 通知/卡密入口 + 无私信管理虚构 | PASS |
| 会员导航无通知设置、有兑换 | PASS |
| 关注通知在偏好表全关后仍产生（系统默认） | PASS |
| 物理 iPhone 目视 / CSV 浏览器下载手点 | NOT TESTED |
| 真实会员破坏性测试 | NOT TESTED（禁止） |

---

## 14. 修改文件清单

| 文件 | 作用 |
|------|------|
| `xedc_core/.../VipGrantForm.php` | 修复开通 Fatal；用户名开通；按钮「开通」 |
| `xedc_core/.../MembershipManager.php` | extend 天数；通知 try/catch |
| `xedc_core/.../EngagementSchema.php` | 卡密表 schema |
| `xedc_core/.../VipCodeManager.php` | 生成/兑换/作废/并发 |
| `xedc_core/.../VipCode*.php`（Form/Controller） | 后台与前台 UI |
| `xedc_core` routing/services/permissions/libraries/install/module/templates/css | 注册路由服务权限主题 |
| `xedc_core/tests/manual/vip-redeem-acceptance.php` | 隔离验收脚本 |
| `xedc_notify PreferenceManager/Controller/routing/twig/NotificationController` | 移除设置 UI；默认开启 |
| `xedc_member MemberController/shell/AliasManager` | 导航与 redeem 入口 |
| `xedc_admin/.../navigation.theme` | 用户管理菜单整合 |

**未修改：** Drupal Core、第三方模块、`config/sync`、Nginx/PHP-FPM。

---

## 15. 已知限制

1. 明文卡密无法从哈希还原；只能重新生成批次。  
2. 官方通知仍无独立失败 UID 队列表（既有限制）。  
3. 后台卡密筛选为简单 GET 表单，非 Views。  
4. `PreferenceForm` 类文件仍保留但前台不可达（重定向）。  

---

## 16. 尚未完成项目

- 物理设备上的窄屏/暗色手点抽检（代码复用会员中心变量）。  
- 未做 PHPStan/PHPCS（项目未安装，按约束不装）。  

---

## 17. 最终验收结论

**PASS（本任务范围内可交付）**

- 通知设置已从前台移除，通知默认仍产生。  
- 用户管理导航已统一扩展且保留原三项。  
- 手动开通 Fatal 已修复，按钮为「开通」，隔离用例通过。  
- 卡密一次性兑换、顺延、并发、权限与回归要点通过。  

**是否修改 config/sync：** 否  
**是否 Git commit / push：** 否  

---

**终端摘要**

```
PASS: grant fix, prefs removal defaults, admin nav, redeem generate/hash/once/revoke/expire/extend, parallel race
FAIL: none in isolation suite
NOT TESTED: physical device visual, PHPStan
config/sync: not modified
git commit/push: not performed
```
