# XEDC 会员私信与通知系统 — 生产上线前最终验收报告

**报告路径：** `docs/XEDC_NOTIFY_FINAL_ACCEPTANCE_REPORT.md`  
**验收日期：** 2026-11-04（本轮复验含真实 Chromium 目视）  
**验收性质：** 最终上线验收（非重新开发）  
**依据文档：**  
- `docs/XEDC_NOTIFY_MESSAGING_DELIVERY_REPORT.md`  
- `docs/XEDC_NOTIFY_PRELAUNCH_SECURITY_AUDIT_REPORT.md`  
- `docs/XEDC_NOTIFY_TASK_SUMMARY.md`  

**判定原则：** 以本轮实际代码、数据库状态、运行环境与浏览器验证为准；未执行项不得标 PASS。

---

## 1. 验收环境

| 项 | 值 |
|----|-----|
| 项目目录 | `/var/www/drupal` |
| 模块目录 | `web/modules/custom/xedc_notify/` |
| Drupal | 11.4.11（Bootstrap Successful） |
| PHP | 8.5.12 |
| 数据库 | MariaDB / mysql driver |
| OS | Ubuntu 24.04.4 LTS |
| 前端主题 | Radix 子主题 `xedc` |
| 后台 | Gin |
| 站点 URI（浏览器） | `https://gitxx.xyz` |
| 模块状态 | `xedc_notify` 已启用 |
| schema 版本 | `10001`（无待执行 update） |
| Git 分支 | `main` → `origin/main` @ `93da5e3` |
| 本轮 Git 操作 | **未 commit / 未 push / 未 reset / 未 checkout / 未 clean** |

### 工作区原有修改（保留未触碰）

验收开始时工作区已有未提交改动（upload 取消、`xedc_tags`、主题/会员挂接、`xedc_notify` 未跟踪模块等）。本轮**未覆盖、未撤销**这些改动。

### 本轮隔离数据

- 前缀 `fa2_*`（UID 337/338/339）及短暂别名 `fa2b3d6a40`
- 结束后已清理本轮用户、私信/通知/关注/VIP 测试行、本轮 campaign、孤儿队列项
- **未**删除既有历史 draft/campaign（如 cid=4 `vip only`）
- **未**向全体会员发送通知，**未**改真实会员 VIP/余额

---

## 2. 检查项目总表

图例：**PASS** · **FAIL** · **PARTIAL** · **NOT TESTED**

### 2.1 第一阶段：运行环境与模块状态

| 检查项 | 结果 | 说明 |
|--------|------|------|
| Drupal Bootstrap | PASS | |
| `xedc_notify` 启用 | PASS | |
| 数据库更新完成 | PASS | schema=10001；无 pending |
| 服务容器 | PASS | messages/notifications/follow/social/preferences/vip/official/lazy_builders |
| 路由注册 | PASS | 含 `admin_vip`、`admin_vip_settings`（路径 `/vip/settings`） |
| 菜单链接 | PASS | |
| 权限定义 | PASS | authenticated 有自有私信/发送/通知；管理权限仅 administrator |
| 模块配置可读 | PASS | offsets `[7,3,1]` 等 |
| 8 张业务表 + 索引/唯一约束 | PASS | 含 `hide_after_mid` |
| PHP/Nginx 日志 xedc_notify 错误 | PASS | 无匹配 |
| Drupal 严重日志（本模块） | PASS | 无 `xedc_notify` type 异常 |
| PHP lint / YAML | PASS | 修复后再次通过 |
| `drush cr` | PASS | 修复后执行成功 |

---

### 2.2 第二阶段：权限与访问控制

| 检查项 | 结果 | 说明 |
|--------|------|------|
| 普通会员自有私信/发送/通知/偏好 | PASS | |
| 不能访问他人私信/通知 | PASS | IDOR 实测 |
| 不能访问后台/官方管理 | PASS | AccessManager |
| 内容编辑等角色不获通知管理权 | PASS | 非 admin 角色无三项管理权限 |
| 管理员官方/VIP 记录/设置 | PASS | 浏览器 200 + 表单 |
| CSRF（follow/delete/read_all） | PASS | |
| 开放重定向 | PASS | `LocalRedirectResponse` |
| 匿名管理面 / 无发信权 | PASS | |

---

### 2.3 第三阶段：私信系统

| 检查项 | 结果 |
|--------|------|
| A→B 发送 / B 收到 / B 回复 / 排序 / 未读 | PASS |
| 空消息 / 超长 / XSS strip | PASS |
| 未登录 / 停用账号不能发送 | PASS |
| tid IDOR | PASS |
| 单方删除互不影响 / 双方删除截断 / 再收恢复 | PASS |
| 限频 | PASS |
| last_mid 交替发送正确 | PASS |
| 真多进程并发压测 | NOT TESTED |

---

### 2.4 第四阶段：关注与社交通知

| 检查项 | 结果 | 说明 |
|--------|------|------|
| 关注/通知/去重/取消/再关注/禁止自己 | PASS | |
| 停用账号不能关注 | PASS | **本轮修复后复验** |
| 偏好关闭不通知 | PASS | |
| 开放重定向 | PASS | |
| Like / Favorite / Comment(photo) 真实事件 | PASS | |
| 去重、偏好、业务不被通知异常阻断 | PASS | |
| 扩大评论范围 | NOT TESTED | 按约束未扩大 |

---

### 2.5 第五阶段：VIP 通知

| 检查项 | 结果 |
|--------|------|
| 权威 `MembershipManager::isActive` + `xedc_vip` | PASS |
| 开通 / 续期 / 到期提醒配置 | PASS |
| pending reclaim / Cron 去重 | PASS |
| 过期权益 / 普通会员不误收 | PASS |
| 真多 Cron 进程并发 | NOT TESTED |

---

### 2.6 第六阶段：官方通知

| 检查项 | 结果 | 说明 |
|--------|------|------|
| 列表/指定 UID 发送/分批/取消/重跑去重/路由 | PASS | |
| VIP/member 计数 | PASS | （历史轮次；本轮逻辑未改） |
| sending↔cancel 双进程竞态 | PARTIAL | 单线程 cancel 验证通过；状态条件更新已在审计修复 |
| 全体会员广播 | NOT TESTED | **禁止** |
| 独立失败 UID 队列表 | — | 已知限制 |

---

### 2.7 第七阶段：通知中心与会员端 UI

| 检查项 | 结果 | 说明 |
|--------|------|------|
| `/member/messages` `/messages/{tid}` | PASS | Chromium 200，无 PHP/控制台错误 |
| `/member/notifications` `/notifications/social` | PASS | |
| `/member/notification-settings` | PASS | 表单存在，可保存路径可用 |
| `/r/{slug}` 关注+私信入口 | PASS | 隔离 slug |
| 角标独立（私信/通知两图标） | PASS | 桌面+ iPhone 均可见 |
| 未登录不显示会员入口 | PASS | 首页无 member messages/notifications 泄露 |
| 已读/删除/偏好（服务层） | PASS | |
| 分类筛选 | PASS | `/notifications/social` 浏览器 |

---

### 2.8 第八阶段：桌面 / 移动 / 暗色

| 检查项 | 结果 | 说明 |
|--------|------|------|
| 桌面布局 / 溢出 / 控制台 | PASS | 1280×800 Chromium |
| iPhone 窄屏布局 / 溢出 / 控制台 | PASS | 390×844 |
| 抽屉打开/关闭 | PASS | 关闭使用 sheet 内按钮 |
| 暗色（站点主题按钮） | PASS | `.xn-ntf` 对比度约 16.97:1 |
| 后台通知页 | PASS | overview/official/vip/vip/settings |
| 未引入第二套主题 | PASS | 复用 `data-bs-theme` / `--xm-*` |

说明：body 全局 `color` 在非 `xm-page` class 下可能保持站点默认值；会员通知面板自身暗色变量与对比度已实测通过。

---

### 2.9 第九阶段：Cron 与队列

| 检查项 | 结果 |
|--------|------|
| Cron hook / 本轮调用 | PASS |
| VIP 扫描 / Worker 定义 | PASS |
| 队列积压（结束时） | PASS（0） |
| VIP pending stuck | PASS（0） |
| 全体广播 / 清空生产队列 | NOT TESTED（禁止） |

---

### 2.10 第十阶段：代码质量

| 检查项 | 结果 |
|--------|------|
| PHP/YAML/路由/权限/schema/XSS/CSRF/IDOR/幂等 | PASS |
| 真多进程并发 | NOT TESTED |
| PHPStan/PHPCS/PHPUnit | NOT TESTED（未安装，按约束不装） |

---

## 3. 安全验收

| 面 | 结论 |
|----|------|
| 权限 | PASS |
| IDOR | PASS |
| XSS | PASS |
| CSRF | PASS |
| 开放重定向 | PASS |
| 停用账号关注（服务层） | PASS（本轮修复） |
| 并发/幂等逻辑 | PASS；真多进程 NOT TESTED |

**无 P0 上线阻断项。**

---

## 4. 业务验收

私信、关注、点赞、收藏、评论(photo)、VIP、官方指定 UID：**PASS**（隔离数据）。

---

## 5. UI 验收

桌面、iPhone 窄屏、暗色面板、后台管理页、公开主页动作：**PASS**（Chromium 实机自动化，非 iPhone 物理机）。  
物理 iPhone 实机手势未测 → 记为可接受残余风险，不阻断小范围试运行。

---

## 6. Cron 和队列

注册与隔离运行正常；结束时 queue=0、vip_pending=0。  
已知限制：无独立失败收件人队列表（全量重跑 + dedupe）。

---

## 7. 本轮代码修改

| 文件 | 原因 | 影响 |
|------|------|------|
| `web/modules/custom/xedc_notify/src/Service/FollowManager.php` | 验收发现 `follow()` 只校验被关注方 active，停用账号仍可在服务层建立关注（控制器已拦，服务层缺口） | `follow()` 增加 `identity->load($followerUid)`；停用/无效关注方抛「当前账号不可用」 |

除此以外：**未修改其他业务代码**；未改 Core/Contrib/`config/sync`/Nginx/PHP-FPM。

修复后回归：`FL_blocked_actor` PASS；active 用户关注仍可用；`drush cr` + PHP lint 通过。

---

## 8. 已知限制

### 已验证通过

环境、schema、权限、私信、关注（含停用修复）、赞/藏/评(photo)、VIP 隔离、官方指定 UID、Cron/队列、Chromium 桌面/窄屏/暗色/后台/公开主页。

### 已知但可接受

1. 官方无逐 UID 失败队列表  
2. 私信限频理论 TOCTOU  
3. 评论通知仅 photo  
4. 历史审计 draft campaign 仍保留  
5. 非物理 iPhone 实机（Chromium 模拟）

### 尚未验证

1. 真多进程并发发送 / 双 Cron 抢跑  
2. PHPStan/PHPCS/PHPUnit  

### 上线阻断项

**无。**

---

## 9. 最终上线结论

### PASS：允许进入小范围生产试运行

依据：

1. 隔离环境下安全与核心业务验收通过  
2. 本轮修复停用关注服务层缺口并回归通过  
3. 真实 Chromium 完成桌面/窄屏/暗色/后台/公开主页验收  
4. 无 P0 阻断项  

试运行建议：

- 官方通知仍仅用指定内部/测试收件人，暂不做全体广播  
- 监控 `xedc_official_notify` 积压、`xedc_vip_notify_sent` pending、相关 PHP/watchdog  

---

## 10. 终端汇总

### PASS（本轮关键项）

ENV_* · ACC_* · SEC_csrf_* · SEC_open_redirect ·  
PM_*（除多进程） · FL_*（含 FL_blocked_actor） · SOC_* · NTF_* · VIP_*（除多进程） · OFF_cancel/send/replay/route · CRON_run · QUEUE_* ·  
UI_anon_* · UI_desktop_* · UI_iphone_*（含 drawer） · UI_admin_* · UI_public_profile_* · THEME_no_second_system ·  
CODE_php_lint · CODE_yaml · CODE_cr

### FAIL

（无，修复后）

### PARTIAL

- OFF_sending_cancel_race（无双进程竞态实锤）

### NOT TESTED

- PM_multiprocess_concurrency  
- VIP_multiprocess_cron  
- OFF_broadcast_all_members（禁止）  
- TOOLING_phpstan_phpcs_phpunit（禁止安装）  
- REAL_MEMBER_destructive（禁止）  
- Physical_iPhone_device（Chromium 模拟已覆盖布局）

---

## 11. 约束合规

保留原有未提交修改；未改 Core/Contrib/config/sync/系统服务；未 git commit/push；未卸载模块；未删生产业务数据；未全体广播；未放宽权限；未装新依赖；隔离测试已清理。

---

**报告结束。**  
**结论：PASS（允许进入小范围生产试运行）。**  
**是否修改代码：是（仅 `FollowManager.php` 最小修复）。**
