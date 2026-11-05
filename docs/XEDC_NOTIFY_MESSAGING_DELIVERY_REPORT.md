# XEDC 会员私信与通知系统 — 交付报告

日期：2026-11-04  
模块：`xedc_notify`（已启用）  
Drupal：11.4.11 / PHP 8.5.12

---

## 1. 实际修改文件

### 新增

- 整个模块目录 `web/modules/custom/xedc_notify/`（41 个文件）
  - Schema / install / permissions / routing / services / menus / libraries
  - 服务：MessageManager、NotificationManager、FollowManager、SocialNotifier、VipNotifier、OfficialCampaignManager、PreferenceManager、MemberIdentity
  - 控制器、表单、QueueWorker、LazyBuilders、Twig、CSS/JS
- 本报告 `docs/XEDC_NOTIFY_MESSAGING_DELIVERY_REPORT.md`

### 修改（为挂接真实事件与入口，最小改动）

| 文件 | 原因 |
|------|------|
| `xedc_core/.../LikeManager.php` | 点赞成功后触发社交通知 |
| `xedc_core/.../MembershipManager.php` | VIP 开通/续期/到期后触发 VIP 通知 |
| `xedc_member/.../FavoriteManager.php` | 收藏成功后触发通知 |
| `xedc_member/.../MemberController.php` | 会员中心导航 + `renderShell()` |
| `xedc_member/.../MemberAliasManager.php` | vanity 路径支持 messages/notifications |
| `xedc_member/.../PublicProfileBuilder.php` | 公开主页关注/私信入口（lazy builder） |
| `xedc_member/templates/xedc-member-shell.html.twig` | 导航分组与图标 |
| `xedc_member/templates/xedc-public-profile.html.twig` | 渲染 profile_actions |
| `xedc_member/xedc_member.module` | theme 变量增加 profile_actions |
| `xedc/templates/includes/xedc-header.html.twig` | 顶部私信/通知图标 |
| `xedc/css/public-profile.css` | 关注/私信按钮样式 |

### 删除

无。

### 未修改

- `config/sync`（未改；模块默认配置通过 `config/install` 安装）
- Nginx / PHP / 系统配置
- 现有 VIP 表结构、公开主页 `/r/{slug}` 体系

---

## 2. 数据库结构

均由 `xedc_notify_schema()` 在安装时创建：

| 表 | 用途 | 关键字段 / 约束 |
|----|------|----------------|
| `xedc_pm_thread` | 双方会话 | `uid_low+uid_high` 唯一；`last_mid` / `last_created` |
| `xedc_pm_thread_state` | **双方独立**隐藏与未读 | PK `(tid,uid)`；`hidden` / `hidden_at` / `unread_count` |
| `xedc_pm_message` | 文字消息 | `sender_uid` / `recipient_uid` / `body` / `is_read` |
| `xedc_follow` | 关注关系（审计发现原无关注，为粉丝通知新建） | `(follower_uid,following_uid)` 唯一 |
| `xedc_notification` | 会员站内通知 | `(uid,dedupe_key)` 唯一；分类 social/vip/official |
| `xedc_notification_pref` | 通知偏好 | 按 UID；含收藏开关 |
| `xedc_official_campaign` | 官方通知任务 | 状态、进度、成功/失败计数 |
| `xedc_vip_notify_sent` | VIP 通知幂等日志 | `(uid,period_expires,notify_type,offset_days)` 唯一 |

配置对象：`xedc_notify.settings`（VIP 提醒开关与天数、收藏默认、消息长度与频率、官方分批大小）。

---

## 3. 功能实现情况

### 私信

- 文字发送/接收、历史、发送时间、按 `last_created DESC` 排序
- 单方隐藏会话；对方再发可重新出现；不删双方共用消息
- 未读统计；长度/空白/XSS/频率限制；发送者取当前登录用户
- 前端：会员中心左右栏会话+聊天；移动端列表/详情切换

### 社交通知

| 类型 | 触发点 | 去重 |
|------|--------|------|
| 新粉丝 | `FollowManager` + 公开主页按钮 | `follow:{fid}` |
| 图片/文章/视频点赞 | `LikeManager` 成功点赞 | `like:{bundle}:{nid}:{actor}` |
| 图片评论 / 评论回复 | `hook_comment_insert/update`（仅已发布） | `comment_*:{cid}` |
| 收藏 | `FavoriteManager::add` 首次插入 | `favorite:node:{nid}:{actor}` + 偏好开关 |

自身互动不通知；偏好可关闭对应类型（不影响业务功能）。

### VIP 通知

权威来源：`xedc_core.membership` / 表 `xedc_vip`（**未新建第二套 VIP**）。

- 开通 / 续期：挂在 `MembershipManager::write`
- 到期：挂在 `expireOne`；Cron 扫描到期提醒
- 幂等：`xedc_vip_notify_sent`；续期后旧 `period_expires` 不再匹配
- 后台：`/admin/people/xedc-notifications/vip` 与设置页（提醒天数可配，默认 7,3,1）

### 官方通知

- 公告 / 维护 / 安全 / 违规 / 账号状态
- 收件人：全体 / 普通会员 / 有效 VIP / 指定 UID（服务器端筛选，VIP 用 `MembershipManager::isActive`）
- 预览人数、队列分批发送、取消、成功/失败/已读统计
- 真实事件：密码变更、账号停用/恢复、审核 rejected / 撤下

### 通知中心 / 偏好 / 顶部

- `/member/notifications` 分类：全部 / 社交 / VIP / 官方
- 单条已读、全部已读、软删除（仅自己）
- `/member/notification-settings` 偏好
- 顶栏独立私信角标与通知角标（0 不显示数字，>99 显示 99+）

---

## 4. 路由和权限

### 会员

- `/member/messages`、`/member/messages/{tid}`、`/member/messages/to/{user}`
- `/member/notifications`、`/member/notifications/{category}`
- `/member/notification-settings`
- `/xedc/follow/{user}`

### 后台

- `/admin/people/xedc-notifications`（及 official / vip / settings 子页）

### 权限

- `access own xedc messages` / `access send xedc messages` / `access own xedc notifications`
- `administer xedc notifications` / `administer xedc vip notifications` / `view xedc notification records`

authenticated 已授予前三项；administrator 已授予管理项。

---

## 5. VIP 集成

| 问题 | 答案 |
|------|------|
| 权威服务 | `xedc_core.membership` → `MembershipManager` |
| 是否有效 | `isActive()`：`status=active` 且 `expires > now` |
| 开通/续期 | `grant` / `extend` → `write()` 后回调 `xedc_notify_on_vip` |
| 到期 | `expireDue` / 懒过期 `expireOne` |
| 提醒去重 | `uid + period_expires + notify_type + offset_days` |
| 续期后误发 | 扫描与发送前重读 `xedc_vip.expires`，不匹配则 skip |
| 权益变化 | 设置页可选通知；文案来自 `MembershipManager::benefits()` |

---

## 6. 测试结果

隔离测试用户：`notify_test_a/b/c`（UID 323–325），未改生产 VIP 业务数据用途以外的测试用户自身 VIP 行（仅 C 用于 VIP 流程后已过期）。

| 项 | 结果 |
|----|------|
| 私信收发 / 排序 / 未读 / 单方删除 / 再显示 / IDOR | **通过** |
| 空消息 / 超长 / XSS 剥离 / 频率限制 | **通过** |
| 关注通知 / 自关注拒绝 / 去重 | **通过** |
| 点赞图片/文章（经 LikeManager）/ 自赞 / 去重 | **通过** |
| 收藏开关开/关 / 经 FavoriteManager | **通过** |
| 图片评论 / 回复通知 | **通过** |
| VIP 开通/续期/即将到期幂等/到期 | **通过** |
| 官方指定会员预览、队列发送、成功数 | **通过** |
| 不能删他人通知；会员无后台权限 | **通过** |
| 页面 render（私信/通知/偏好/后台） | **通过** |
| PHP `php -l`、YAML 解析、drush cr、schema 表存在 | **通过** |
| 真实浏览器 UI / 暗色目视 / 移动实机 | **未执行**（需人工） |
| PHPStan / PHPCS / PHPUnit | **未执行**（项目未装，按约束不安装） |
| 官方「全体会员」大规模压测 | **未执行**（逻辑已分批，建议低峰验证） |

---

## 7. 已知限制

1. **关注系统为本任务新建**（原站无关注）；仅服务粉丝通知与公开主页按钮，无粉丝列表页。
2. **评论仅 photo** 有 `field_comment`（与现网一致）；视频/文章评论通知未做。
3. VIP **权益文案**仍硬编码于 `MembershipManager::benefits()`；后台「权益变化」是管理员主动触发说明，不是配置 Diff 引擎。
4. 官方发送依赖 **Cron/Queue**；需保证站点 cron 正常。
5. 未读角标为请求时计算（lazy builder，`max-age=0`），非 WebSocket 实时。
6. 测试用户 `notify_test_*` 仍留在库中，可手动删除。

---

## 8. 回滚方案

**代码：** 禁用模块 `drush pmu xedc_notify -y`，并还原本报告所列对 `xedc_core` / `xedc_member` / `xedc` 主题的改动（或 `git checkout` 这些路径）。

**数据库：** 卸载模块**不会**自动 DROP 业务表（Drupal 默认）。若确认无需保留数据，可手工：

```sql
-- 仅在确认可丢弃私信/通知数据后执行
DROP TABLE IF EXISTS
  xedc_pm_message, xedc_pm_thread_state, xedc_pm_thread,
  xedc_follow, xedc_notification, xedc_notification_pref,
  xedc_official_campaign, xedc_vip_notify_sent;
```

**不要**删除 `xedc_vip` / `xedc_vip_log` / 用户表。  
配置：`drush config:delete xedc_notify.settings`（若仍存在）。

未自动执行破坏性回滚。未提交 / 未推送 Git（按任务要求）。
