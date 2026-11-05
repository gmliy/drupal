# XEDC 私信与通知系统 — 上线前专项安全审计报告

日期：2026-11-04  
范围：`web/modules/custom/xedc_notify/` 及与之挂接的最小相关代码  
环境：Drupal 11.4.11 / PHP 8.5.12  
Git：`main`，未提交、未推送；保留用户其他未提交修改（upload/tags 等未触碰）

---

## 1. 审计范围

1. 关注系统（`xedc_follow` / FollowController / FollowManager）
2. VIP 通知幂等与并发（VipNotifier / MembershipManager 挂接 / Cron）
3. 评论 Hook（insert/update）
4. 官方通知队列（OfficialCampaignManager / QueueWorker）
5. 私信（MessageManager / 单方删除 / IDOR）
6. 会员身份与 `/r/{slug}` 跳转
7. 通知中心 / 偏好 / 权限与 XSS/CSRF/开放重定向

---

## 2. 检查的具体文件

- `xedc_notify`：MessageManager、VipNotifier、OfficialCampaignManager、SocialNotifier、FollowManager、FollowController、NotificationController、MessageController、NotifyLazyBuilders、OfficialNotifyWorker、`xedc_notify.module`、`xedc_notify.install`、routing/permissions
- 挂接点（只读核对）：`LikeManager`、`FavoriteManager`、`MembershipManager`、会员中心/公开主页/顶栏相关改动

---

## 3. 发现的问题

| ID | 严重度 | 问题 | 证据位置 |
|----|--------|------|----------|
| P1 | 高 | 关注回调 `destination` 使用裸 `RedirectResponse`，可开放重定向到外站 | `FollowController::toggle` |
| P2 | 高 | 官方队列将 `status=sending` 无条件写回，可覆盖已 `cancelled` | `OfficialCampaignManager::processBatch` |
| P3 | 高 | VIP `xedc_vip_notify_sent` 插入 pending 后进程崩溃会永久卡死，后续 Cron 因唯一约束无法补发 | `VipNotifier::sendOnce` |
| P4 | 中 | 私信单方删除用 `created > hidden_at`，同秒新消息会被误过滤 | `MessageManager::listMessages` |
| P5 | 中 | 并发发送时 `last_mid` 后写覆盖，可能留下较小 mid | `MessageManager::send` |
| P6 | 中 | `ensureState` 先查后插，并发可抛唯一约束异常 | `MessageManager::ensureState` |
| P7 | 中 | 停用账号仍可能发私信/关注（仅校验对方 active） | MessageManager / FollowController |
| P8 | 中 | 官方通知 `route_name` 配错，分类参数无效 | `processBatch` 原 `xedc_notify.notifications` |
| P9 | 中 | 内容撤下通知 dedupe 含 `time()`，重复保存可刷通知 | `xedc_notify_node_update` |
| P10 | 低 | 官方发送过程中未再次校验收件人 VIP/账号状态 | `processBatch` |
| P11 | 低 | 权益变化通知未再走 `MembershipManager::isActive` | `notifyBenefitsChanged` |
| P12 | 低 | 通知打开跳转未强制本地 URL | `NotificationController::open` |
| P13 | 低 | 评论通知异常可能冒泡影响评论保存 | comment hooks |

---

## 4. 已修复的问题

均已做最小修复并缓存重建 / `updb`：

1. **P1** → `LocalRedirectResponse`，拒绝外链 destination  
2. **P2** → 状态更新带 `status IN (queued,sending)`；取消后不再复活  
3. **P3** → 唯一冲突时仅 reclaim `pending/failed`；已存在通知视为成功  
4. **P4** → 新增字段 `hide_after_mid`（`xedc_notify_update_10001`），按 mid 截断历史  
5. **P5** → `last_mid` 更新增加 `last_mid < :new`  
6. **P6** → `ensureState` 捕获唯一约束  
7. **P7** → 发送方/关注方必须 `MemberIdentity::load`（active）  
8. **P8** → 改为 `xedc_notify.notifications_category`  
9. **P9** → 稳定 dedupe key（去掉 time）  
10. **P10–P13** → 发送时重验资格；VIP 权益走 `isActive`；打开通知本地重定向；评论 notify try/catch  

未删除任何关注关系、测试用户或业务表数据。

---

## 5. 未修复的问题及原因

| 项 | 原因 |
|----|------|
| 官方失败重试只扫失败 UID | 当前失败无逐 UID 失败表；重跑全量 + dedupe 安全但非最优。非阻断。 |
| 私信频率限制存在 TOCTOU | 两请求可同时过检；已有长度/登录限制。可用 Flood 增强，非阻断。 |
| 点赞再赞同一作品不重复通知 | 按产品去重要求保留。 |
| 真实浏览器暗色/移动实机 | 本环境无浏览器自动化；见第 12 节人工清单。 |
| PHPStan/PHPCS/PHPUnit | 项目未安装，按约束不安装、不虚报。 |

---

## 6. 数据库并发和幂等性结论

| 子系统 | 结论 |
|--------|------|
| 关注 | DB 唯一 `(follower,following)`；再关注新 `fid` → 新通知；同 fid 去重 |
| VIP | 唯一 `(uid,period_expires,type,offset_days)` + reclaim pending；周期变更后旧键失效 |
| 官方 | `official:{cid}:{uid}` 去重；取消与 sending 状态条件更新；崩溃后续跑安全 |
| 私信 | 线程 pair 唯一；state 双方独立；`hide_after_mid`；`last_mid` 条件更新 |
| 评论 | `comment_*:{cid}`；仅 unpublished→published 触发 |

---

## 7. 权限与安全结论

- 会话/通知读写均校验当前 UID；跨用户 markRead/delete 实测无效  
- 非参与者 `assertParticipant` 拒绝  
- 发送者 UID 来自 `AccountInterface`，不可前端伪造  
- VIP 判断统一 `MembershipManager::isActive`  
- 消息/通知正文 `strip_tags`；Twig 默认转义 + `nl2br`  
- 关注/通知删除路由含 CSRF；关注 destination 已防开放重定向  
- 普通会员无 `administer xedc notifications`  
- **未发现上线级 SQL 注入**（Database API）

---

## 8–11. 测试结果摘要

隔离用户 UID 323/324/325。**不向全体会员发送。**

| 类别 | 结果 |
|------|------|
| 私信双方删除独立、再显示、IDOR、last_mid、历史 mid 截断 | **通过**（截断修复后复测通过） |
| 关注/取消/再关注/自关注/去重/开放重定向 | **通过** |
| 评论未发布/发布一次/编辑不重复/取消发布/自己 | **通过** |
| VIP 开通一次、pending reclaim、到期提醒唯一、续期换周期 | **通过** |
| 官方取消零投递、发送、重放不重复、路由、VIP 筛选 | **通过** |
| 通知中心跨用户已读/删除 | **通过** |
| 控制器 render（私信/通知/偏好/后台） | **通过** |
| PHP `php -l`、YAML、`drush cr`、`drush updb 10001` | **通过** |
| 浏览器实机 / 暗色目视 | **未执行** |

---

## 12. 页面及暗色模式验收情况

**未完成实机浏览器测试。** 建议人工按下列路径验收（桌面 + 窄屏 + 切换暗色）：

1. 登录会员 → 顶栏私信/通知角标  
2. `/member/messages`、打开会话、发送、删除会话  
3. `/member/notifications`、分类、全部已读、删除  
4. `/member/notification-settings`  
5. `/r/{slug}` 关注/私信  
6. 管理：`/admin/people/xedc-notifications` 及 official/vip 子页  

检查：无横向溢出、气泡/卡片暗色变量、角标 0 不显示数字。

---

## 13. PHP / YAML / 缓存 / Schema

| 检查 | 结果 |
|------|------|
| `php -l`（xedc_notify） | 通过 |
| YAML 解析 | 通过 |
| `drush cr` | 通过 |
| `drush updb` → `xedc_notify_update_10001` | 通过（`hide_after_mid`） |
| 表存在性 | 通过 |

---

## 14. 是否存在上线阻断问题

**当前无已知阻断项**（P1–P13 已修；剩余为非阻断限制）。  

上线前仍建议完成第 12 节人工 UI 抽检，并确认生产 Cron 正常跑（VIP 提醒 + 官方队列）。

---

## 15. 建议上线步骤与回滚

### 上线

1. 确认本报告修复已部署到目标环境  
2. `drush en xedc_notify -y`（若未启用）  
3. `drush updb -y`（执行到 10001）  
4. `drush cr`  
5. 确认 `authenticated` 私信/通知权限、`administrator` 管理权限  
6. 人工走查第 12 节  
7. 观察 cron / `xedc_official_notify` 队列  

### 回滚（非破坏性优先）

1. `drush pmu xedc_notify -y`  
2. 还原主题/会员中心/Like/Favorite/Membership 挂接改动  
3. **不要** DROP `xedc_vip`；通知业务表仅在确认可丢弃数据后手工删除  
4. `hide_after_mid` 列为可保留的无害列  

未执行破坏性回滚；未提交 Git。

---

## 结论

在保留现有会员体系与 VIP 权威数据源的前提下，本轮审计修复了开放重定向、官方取消竞态、VIP pending 卡死、私信历史截断同秒缺陷等实际上线风险。模块**具备上线条件**，前提是完成人工 UI/暗色抽检并保证 Cron 可用。
