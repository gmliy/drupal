# XEDC 后台 VIP 管理与会员互动功能去重及 UI 统一整改报告

日期：2026-11-05  
环境：Drupal 11.4.11 / PHP 8.5.12 / https://gitxx.xyz  
性质：后台功能去重、菜单归属整理、VIP / 会员与互动 UI 优化  
约束：未 commit / 未 push；未改 Core / 第三方 / config/sync；保留全部既有脏工作区修改。

---

## 1. 重复功能的具体来源和根因

| 根因 | 说明 |
|------|------|
| **Local tasks `base_route` 错误** | `xedc_core.links.task.yml` 中互动相关 Tab（点赞/下载/分享等）的 `base_route` 仍为 `xedc_core.vip_admin`。旧 VIP 页与互动页共用同一组本地任务，打开「会员与互动」时会出现 VIP 入口，打开 VIP 时也会带上互动 Tab。 |
| **VIP Tab 挂在互动组** | `xedc_core.vip_tab` 把「VIP 会员 / VIP 管理」放进互动本地任务，与 `/admin/people/xedc-vip` 重复。 |
| **`vip_admin` 路由重定向后语义混淆** | `/admin/xedc/vip` 已重定向到 VIP hub 的 grant Tab，但互动组仍把它当「会员与互动」历史入口。 |
| **概览卡片混入 VIP 管理指标** | `EngagementAdminController::overview()` 展示「有效 VIP / 7 天内到期」管理向卡片，与 VIP 管理中心职责重叠。 |
| **会员管理高亮过宽** | 导航「会员管理」用 `/admin/people` 前缀匹配，导致 VIP/通知管理页也会被高亮为会员管理。 |

业务服务（`MembershipManager`、`VipCodeManager`、互动报表）本身未重复；重复的是**展示入口与本地任务归属**。

---

## 2. VIP 管理最终功能清单

入口：`/admin/people/xedc-vip`

| Tab | 功能 |
|-----|------|
| 概览 | 页头四项真实统计；最近开通/续期；即将到期；常用操作入口（不开完整表单） |
| 开通与续期 | `VipGrantForm` + VIP 会员列表（复用 `EngagementAdminController::vip()`，去掉统计卡片） |
| 卡密管理 | 复用 `VipCodeAdminController` |
| 提醒设置 | 复用 `VipNotifySettingsForm` → `xedc_notify.settings` |
| 通知记录 | 复用 `AdminNotificationController::vipList()` |

页头统计（真实 DB）：当前有效 VIP、累计 VIP 会员、近 7 日新增、近 7 日到期。

---

## 3. 会员与互动最终功能清单

入口：`/admin/xedc/engagement`（`xedc_core.engagement_hub`）

本地任务（`base_route: xedc_core.engagement_hub`）：

1. 互动总览  
2. 点赞管理  
3. 下载管理  
4. 分享管理  
5. 会员互动  
6. 互动数据  
7. ZIP 任务  
8. 下载资源  

**明确不包含：** VIP 开通表单、卡密管理、提醒设置、VIP 通知记录、VIP 管理 Tab、有效 VIP 管理统计卡片。  

仍保留的「VIP 下载」数字是下载侧写（按当时等级），不是 VIP 管理功能。

---

## 4. 最终左侧导航结构（用户管理）

1. 会员管理（排除 `/admin/people/xedc-*` 误高亮）  
2. 内容审核  
3. 会员与互动 → `xedc_core.engagement_hub`  
4. 通知管理（排除 `.../notifications/vip` 误高亮）  
5. VIP 管理 → `xedc_core.vip_hub`  

独立菜单不再出现：VIP 通知记录、VIP 提醒设置、VIP 卡密管理。

---

## 5. 修改文件清单

| 文件 | 变更 |
|------|------|
| `web/modules/custom/xedc_core/xedc_core.links.task.yml` | 互动 Tab 改 `base_route: engagement_hub`；移除 VIP local task |
| `web/modules/custom/xedc_core/src/Controller/VipHubController.php` | 页头统计、Tab 文案、概览增强、grant 去重卡片 |
| `web/modules/custom/xedc_core/css/vip-hub.css` | VIP Modern SaaS 样式刷新 |
| `web/modules/custom/xedc_core/src/Controller/EngagementAdminController.php` | 去掉 VIP 管理卡片；标题/文案；统一 header |
| `web/modules/custom/xedc_core/xedc_core.routing.yml` | engagement_hub 标题改为「会员与互动」 |
| `web/modules/custom/xedc_core/xedc_core.links.menu.yml` | 菜单归属与禁用说明整理 |
| `web/themes/custom/xedc_admin/includes/navigation.theme` | 排除前缀；职责分离确认 |
| `web/themes/custom/xedc_admin/css/engagement.css` | 与 VIP hub 统一的标题/留白 |
| `web/modules/custom/xedc_core/tests/manual/vip-engagement-dedup-acceptance.php` | 隔离验收 |
| `web/modules/custom/xedc_core/tests/manual/browser-vip-engagement-dedup.mjs` | 浏览器验收 |
| `docs/XEDC_VIP_ENGAGEMENT_UI_DEDUP_REPORT.md` | 本报告 |

未改：`MembershipManager`、卡密 HMAC/兑换逻辑、通知发送逻辑、数据库表。

---

## 6. UI 改造内容

**VIP 管理**

- 标题/副标题按产品文案更新。  
- 页头四格真实统计。  
- 水平 Tab：概览 / 开通与续期 / 卡密管理 / 提醒设置 / 通知记录；移动端横向滚动、不撑破页面。  
- 概览：常用操作 + 最近开通 + 即将到期（不重复塞满五个完整表单）。  
- 开通 Tab：去掉重复统计卡片，保留表单与列表。  

**会员与互动**

- 独立标题「会员与互动」与说明文案。  
- 继续使用 `.xedc-ops` + Gin/XEDC token；最大宽度与 VIP 对齐。  
- 本地任务仅为互动功能，无 VIP Tab。  

---

## 7. 路由及权限变更

| 项 | 状态 |
|----|------|
| `/admin/people/xedc-vip` | 保留，UI 增强 |
| `/admin/xedc/engagement` | 标题改为会员与互动；内容去 VIP 管理向卡片 |
| `/admin/xedc/vip` | 仍重定向 `?tab=grant` |
| 卡密/提醒/记录旧路由 | 仍重定向至对应 Tab |
| 权限机名 | 未改；内容编辑对 VIP/互动/通知管理均为 DENY（本环境） |
| 新库表 / update hook | 无 |

---

## 8. 实际测试结果

### 隔离脚本

`drush php:script .../vip-engagement-dedup-acceptance.php`  
**pass=22 fail=0**（另有 1 项 NOTE：普通会员是否具备互动统计权限取决于角色配置）

### 浏览器（Chromium / https://gitxx.xyz）

`browser-vip-engagement-dedup.mjs`  
**pass=14 fail=0**

含：VIP 五 Tab、页头统计、开通表单、互动页无「开通 VIP」、无 VIP hub Tab、本地任务无 VIP、旧路由跳转、左侧同时有两入口且无旧三菜单、移动端 VIP 页无横向溢出。

### 技术

| 项 | 结果 |
|----|------|
| PHP lint | PASS |
| `drush cr` | PASS |
| config/sync | 无变更 |
| commit/push | 未执行 |
| Watchdog | 仍有既有上传取消 unlink 错误，与本任务无关 |

---

## 9. PASS / FAIL / NOT TESTED

| 项 | 结果 |
|----|------|
| 导航两入口独立 | PASS |
| 无重复 VIP 菜单 / 无旧三独立菜单 | PASS |
| 互动 local tasks 无 VIP、base=engagement_hub | PASS |
| VIP 五 Tab 与开通表单 | PASS |
| 互动页无 VIP 开通/卡密/提醒/记录/管理卡片 | PASS |
| 旧 `/admin/xedc/vip` 兼容跳转 | PASS |
| 内容编辑拒绝 VIP/互动/通知管理 | PASS |
| 移动端 VIP 无横向溢出 | PASS |
| VIP 续期业务路径（MembershipManager） | PASS（表单仍走原逻辑；本轮未再造业务数据） |
| 卡密生成/兑换/作废/CSV 点击全路径 | NOT TESTED（入口在 VIP Tab；逻辑未改；未重复点导出/作废） |
| Gin 暗色模式截图 | NOT TESTED（CSS 使用 Gin 变量） |
| 互动各子页逐一截图 | NOT TESTED（总览与 Tab 列表已测；点赞等子页结构未改） |

---

## 10. 剩余问题

1. 普通会员默认可能无 `view xedc engagement statistics`，互动后台本就仅管理员使用；若需编辑角色只读互动，需单独授权（非本任务范围）。  
2. `EngagementAdminController::vip()` 仍保留供 VIP hub 复用，不再作为互动导航入口。  
3. 上传取消 unlink 的 watchdog 错误与本任务无关。  
4. 暗色模式与互动子页视觉建议上线前人工扫一眼。

---

## 最终检查

```text
分支：main...origin/main
脏文件数：约 102（含既有未提交工作 + 本任务改动）
config/sync：无变更
未 commit / 未 push
```

**验收结论：CONDITIONAL PASS**  
VIP 管理与会员与互动已职责分离、入口不重复、视觉统一；标注 NOT TESTED 的仅为暗色截图、卡密导出/作废按钮复测与互动子页逐页截图。
