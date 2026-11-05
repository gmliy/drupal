# XEDC 会员私信与通知系统 — 任务说明

**任务性质：** 在现有 Drupal 11 站点上完成会员私信与站内通知系统的开发，并完成上线前安全审计与必要修复。  
**项目路径：** `/var/www/drupal`  
**模块：** `web/modules/custom/xedc_notify/`  
**环境：** Drupal 11.4.11，PHP 8.5.12  
**日期：** 2026-11-04  
**Git：** 未擅自提交、未推送；未修改 `config/sync`、Nginx、PHP 系统配置。

相关详细文档：

- 开发交付：`docs/XEDC_NOTIFY_MESSAGING_DELIVERY_REPORT.md`
- 上线前安全审计：`docs/XEDC_NOTIFY_PRELAUNCH_SECURITY_AUDIT_REPORT.md`

---

## 一、任务目标

建立一套可上线的会员私信与站内通知能力：

1. 会员之间文字私信（非实时、无附件）
2. 社交通知（关注、点赞、评论、收藏等，由服务端真实事件触发）
3. VIP 权益通知（与现有 `xedc_core.membership` / `xedc_vip` 集成，不另建 VIP 体系）
4. 官方系统通知（后台创建、队列分批发送）
5. 会员通知中心、通知偏好、顶部未读角标
6. 上线前安全、并发、幂等与边界条件审计，并做最小必要修复

明确不做：语音/视频/群聊、私信附件、短信/邮件通知、第三方聊天、WebSocket、新会员等级体系、新公开主页地址体系。

---

## 二、审计结论（开发前）

| 项目 | 结论 |
|------|------|
| VIP 权威来源 | `xedc_core.membership` → `MembershipManager::isActive()`，表 `xedc_vip` |
| 点赞 | `xedc_core.like_manager`（photo/video/article） |
| 收藏 | `xedc_member.favorite_manager` |
| 评论 | 核心 Comment，目前仅 photo 挂 `field_comment` |
| 关注 | **原先不存在**，为粉丝通知新建 `xedc_follow` |
| 私信/站内通知 | **原先不存在**，新建 `xedc_notify` |
| 会员中心 / 公开主页 | 复用 `xedc_member`，公开主页继续用 `/r/{slug}` |

---

## 三、实现概要

### 3.1 新模块

`xedc_notify`：私信、关注、通知、偏好、VIP 提醒、官方队列、会员端与后台页面。

### 3.2 主要数据表

| 表 | 用途 |
|----|------|
| `xedc_pm_thread` | 双方私信会话 |
| `xedc_pm_thread_state` | 双方独立隐藏/未读/`hide_after_mid` |
| `xedc_pm_message` | 文字消息 |
| `xedc_follow` | 关注关系 |
| `xedc_notification` | 站内通知（含 dedupe） |
| `xedc_notification_pref` | 通知偏好 |
| `xedc_official_campaign` | 官方发送任务 |
| `xedc_vip_notify_sent` | VIP 通知幂等日志 |

配置：`xedc_notify.settings`（VIP 提醒天数、消息长度/频率、官方分批大小等）。

### 3.3 会员端入口

| 功能 | 路径 |
|------|------|
| 私信 | `/member/messages`、`/member/messages/{tid}` |
| 通知中心 | `/member/notifications`、`/member/notifications/{category}` |
| 通知设置 | `/member/notification-settings` |
| 关注切换 | `/xedc/follow/{user}` |
| 公开主页关注/私信 | `/r/{slug}` |
| 顶栏角标 | 登录后导航（私信与通知分开统计） |

### 3.4 后台入口

`/admin/people/xedc-notifications`  
含子页：官方通知、VIP 通知记录、VIP 提醒设置。

### 3.5 权限

- 会员：`access own xedc messages`、`access send xedc messages`、`access own xedc notifications`
- 管理：`administer xedc notifications`、`administer xedc vip notifications`、`view xedc notification records`

### 3.6 与现有系统的挂接（最小改动）

| 位置 | 作用 |
|------|------|
| `LikeManager` | 点赞成功 → 社交通知 |
| `FavoriteManager` | 收藏首次成功 → 社交通知 |
| `MembershipManager` | 开通/续期/到期 → VIP 通知 |
| `xedc_member` 导航 / shell / alias | 私信、通知入口 |
| 公开主页 / 顶栏主题 | 关注私信按钮、未读角标 |

---

## 四、上线前审计与修复

第二轮专项审计后，已修复包括但不限于：

1. 关注回调开放重定向（改为 `LocalRedirectResponse`）
2. 官方任务取消与 `sending` 状态竞态
3. VIP `pending` 卡死导致无法补发（唯一约束 + reclaim）
4. 私信单方删除同秒截断错误（`hide_after_mid` + `update_10001`）
5. 并发 `last_mid` / `ensureState`
6. 停用账号发送/关注校验
7. 官方通知跳转路由、撤下通知 dedupe、评论通知异常保护等

**当前结论：** 无已知上线阻断项；建议上线前做人工 UI/暗色抽检，并确认 Cron 正常。

---

## 五、测试情况（摘要）

使用隔离测试用户（如 `notify_test_a/b/c`，UID 323–325），**未向全体会员发测试公告**，**未改无关生产 VIP 业务数据用途以外的隔离测试**。

已覆盖并通过（代码/服务层）：

- 私信收发、排序、单方删除、再显示、IDOR、XSS/空消息/超长/限频
- 关注/取消/再关注去重、自关注拦截
- 点赞/收藏/评论通知与偏好
- VIP 开通/续期/到期提醒幂等与 pending reclaim
- 官方指定会员发送、取消、重放不重复、VIP 筛选
- 通知中心跨用户已读/删除隔离
- PHP 语法、YAML、`drush cr`、`drush updb`

未虚报通过：

- 真实浏览器 / 暗色 / 移动实机目视
- PHPStan / PHPCS / PHPUnit（项目未安装）

---

## 六、上线步骤（建议）

1. 部署本任务相关代码（含 `xedc_notify` 与挂接改动）
2. `drush en xedc_notify -y`（若未启用）
3. `drush updb -y`（至少执行到 `xedc_notify_update_10001`）
4. `drush cr`
5. 确认角色权限
6. 人工验收：私信、通知、偏好、公开主页按钮、顶栏角标、后台管理页（桌面/窄屏/暗色）
7. 确认站点 Cron（VIP 提醒 + `xedc_official_notify` 队列）

---

## 七、回滚要点

1. 优先：`drush pmu xedc_notify -y`，并还原主题/会员中心/Like/Favorite/Membership 挂接
2. **不要**删除 `xedc_vip` / 会员业务数据
3. 通知相关自定义表仅在确认可丢弃数据后手工清理
4. 未自动执行破坏性回滚

---

## 八、已知限制

1. 关注为本次新建的最小能力，暂无完整粉丝列表产品页
2. 评论通知仅覆盖现有 photo 评论字段
3. VIP 权益文案仍来自现有 `MembershipManager::benefits()`
4. 官方失败收件人未单独建失败队列表（重跑 + dedupe 安全）
5. 未读角标非 WebSocket 实时，依赖页面请求/占位刷新

---

## 九、文件变更一览（高层）

**新增**

- `web/modules/custom/xedc_notify/`（整模块）
- `docs/XEDC_NOTIFY_MESSAGING_DELIVERY_REPORT.md`
- `docs/XEDC_NOTIFY_PRELAUNCH_SECURITY_AUDIT_REPORT.md`
- `docs/XEDC_NOTIFY_TASK_SUMMARY.md`（本文件）

**修改（挂接与展示）**

- `xedc_core`：`LikeManager`、`MembershipManager`
- `xedc_member`：会员中心导航、公开主页、FavoriteManager、alias 等
- `xedc` 主题：顶栏、公开主页样式

---

## 十、一句话结论

已在现有架构上落地可用的 `xedc_notify` 私信与通知系统，并完成上线前安全审计与关键缺陷修复；在 Cron 可用且完成人工 UI 抽检的前提下，具备上线条件。
