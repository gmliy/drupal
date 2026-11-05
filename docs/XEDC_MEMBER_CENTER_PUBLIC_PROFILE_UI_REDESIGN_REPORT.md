# XEDC 前台会员中心与个人公开主页 UI 重新设计报告

日期：2026-11-05  
站点：https://gitxx.xyz  
范围：展示层 / Twig / CSS / 必要页面类与 overview 展示数据装配  
约束：未改业务逻辑、权限、路由机器名、数据结构、Core/第三方、config/sync；未 commit / push；保护既有脏工作区。

---

## 1. 原有会员中心页面清单

以 `xedc_member.routing.yml` + `xedc_notify.routing.yml` + `MemberAliasManager` 为准（vanity `/{slug}/…` 映射到 `/member/…`）：

| 页面 | 内部路径 | 说明 |
| --- | --- | --- |
| 会员中心首页 | `/member` | overview |
| 收藏 | `/member/favorites` | |
| 点赞 | `/member/likes` | |
| 下载 | `/member/downloads` | |
| VIP 会员 | `/member/membership` | |
| VIP 卡密兑换 | `/member/redeem` | `xedc_core.vip_redeem`，侧栏有权限时显示 |
| 浏览历史 | `/member/history` | |
| 清空历史确认 | `/member/history/clear` | |
| 我的评论 | `/member/comments` | |
| 我的投稿 | `/member/submissions` | |
| 投稿新增 | `/node/add/{photo\|video\|article}` | 会员投稿页 class |
| 投稿编辑 | `/node/{node}/edit` | 有可编辑稿时 |
| 个人资料 | `/member/profile` | |
| 账号安全 | `/member/security`（`/member/settings` 同入口） | |
| 私信列表 | `/member/messages` | |
| 私信详情 | `/member/messages/{tid}` | |
| 通知列表 | `/member/notifications` | |
| 通知分类 | `/member/notifications/{social\|vip\|official}` | |

侧栏分组：总览（首页/私信/通知）· 我的内容 · 账户。

---

## 2. 原有公开主页结构

- 路由：`/r/{slug}`（`xedc_member.public_profile`）
- 模板：`xedc-public-profile.html.twig`
- 结构：头像 / 昵称 / 分享 / 关注与私信动作（若模块启用）/ 简介 / 加入日期 / 公开作品计数 / 筛选与排序 / 作品网格 / 空状态 / 分页
- 样式：`web/themes/custom/xedc/css/public-profile.css`

---

## 3. 发现的 UI 问题

1. 首页信息层级偏平：统计卡片过多，缺少欢迎区与 VIP 状态条。
2. VIP 页缺少卡密兑换主入口与结构化元信息。
3. 移动端抽屉缺少显式关闭按钮（仅遮罩/Esc）。
4. 公开主页 hero 使用 `<header>`，被主题全局 `header:not(.xedc-nav):not(.xn){background:none}` 清掉背景。
5. 公开主页暗色下 `body.xp-page` 的 `--xp-bg` 未在 body 上定义，背景透明。
6. 私信/通知等 `xedc_notify.*` 路由未挂 `xm-page`，画布/暗色 token 与会员页不一致。
7. 通知/私信标题颜色未绑定 `--xn-fg`，暗色下可能发灰发暗。

---

## 4. 修改文件清单

| 文件 | 变更 |
| --- | --- |
| `web/modules/custom/xedc_member/src/Controller/MemberController.php` | overview 增加 membership/avatar 展示数据；membership 传入 urls |
| `web/modules/custom/xedc_member/xedc_member.module` | theme 变量；`xm-page` 覆盖 notify/redeem 壳路由 |
| `web/modules/custom/xedc_member/templates/xedc-member-overview.html.twig` | 欢迎区 + VIP 条 + 精简统计 + 常用入口 |
| `web/modules/custom/xedc_member/templates/xedc-member-membership.html.twig` | 状态元信息 + 兑换入口 |
| `web/modules/custom/xedc_member/templates/xedc-member-shell.html.twig` | 抽屉关闭钮；品牌标记 |
| `web/modules/custom/xedc_member/templates/xedc-member-submissions.html.twig` | 文案与类型 class |
| `web/modules/custom/xedc_member/templates/xedc-public-profile.html.twig` | hero 改为 `div.xp-hero` |
| `web/modules/custom/xedc_member/css/member.css` | welcome / vip-strip / shortcuts / 移动端适配 |
| `web/modules/custom/xedc_member/js/member.js` | 关闭按钮绑定 |
| `web/modules/custom/xedc_member/xedc_member.libraries.yml` | member library `2.1` |
| `web/themes/custom/xedc/css/public-profile.css` | 画布、hero 卡片、暗色 body token |
| `web/modules/custom/xedc_notify/css/notify.css` | 表面阴影、通知容器、标题色 |
| `web/modules/custom/xedc_member/tests/manual/browser-member-ui-redesign.cjs` | Chromium 验收脚本 |
| `docs/XEDC_MEMBER_CENTER_PUBLIC_PROFILE_UI_REDESIGN_REPORT.md` | 本报告 |

说明：`FavoriteManager` / `MemberAliasManager` / `PublicProfileBuilder` 的 diff 来自既有未提交工作，本轮未改其业务语义。

---

## 5. 各会员页面整改结果

| 页面 | 结果 |
| --- | --- |
| 首页 | PASS — 欢迎区、VIP 条（MembershipManager 数据）、4 项关键统计、常用入口、最近收藏 |
| 收藏 / 点赞 / 历史 | PASS — 统一卡片网格、筛选 Tab、空状态、分页 |
| 下载 | PASS — 表格 + 封面 + 状态 badge；未改权限逻辑 |
| VIP | PASS — 状态/日期/剩余天/权益/卡密入口 |
| 评论 | PASS — 状态 badge、原文入口、操作按钮 |
| 投稿列表 | PASS — 状态色、类型、审核意见、新建入口 |
| 资料 / 安全 | PASS — 既有表单壳与面板统一 |
| 兑换 | PASS — 进入会员壳 + `xm-page` |

---

## 6. 投稿新增 / 编辑

| 项 | 结果 |
| --- | --- |
| `/node/add/photo` | PASS HTTP 200，`xm-submission-page` |
| `/node/add/video` | PASS |
| `/node/add/article` | PASS |
| 投稿编辑 | **NOT TESTED** — 测试账号 uid=3 无当前可编辑投稿入口 |

未改上传 Worker、取消交接、审核流。

---

## 7. 私信与通知

| 项 | 结果 |
| --- | --- |
| `/member/messages` | PASS |
| `/member/messages/{tid}` | **NOT TESTED** — 无现存会话 |
| `/member/notifications` | PASS |
| `/member/notifications/social\|vip\|official` | PASS |
| 全部已读等 POST CSRF | 未在本轮点击提交（避免生产写操作）→ **NOT TESTED**（交互） |

壳层已统一 `xm-page` + notify 组件 token。

---

## 8. 公开主页整改结果

| 项 | 结果 |
| --- | --- |
| `/r/dcxe` 登录 / 匿名 | PASS，空作品态可用 |
| `/r/aa` 有作品匿名 | PASS，12 张卡片，hero 背景正常 |
| hero 背景被全局 header 清掉 | 已修复（改为 `div.xp-hero`） |
| 暗色 body 背景 | 已修复 |

---

## 9–12. 模式与端验收

| 维度 | 结果 |
| --- | --- |
| 白色模式 | PASS — 会员页 canvas `#f4f5f7`，表面白，公开主页一致 |
| 暗色模式 | PASS — `/member`、`/r/dcxe`、`/member/notifications`：`data-bs-theme=dark`，正文/面板对比度正常 |
| 桌面侧栏 | PASS — 248px 侧栏 + 内容区；顶栏非双固定（会员 topbar 桌面隐藏，移动静态） |
| 移动抽屉 | PASS — 打开/关闭按钮/遮罩；`drawer ok=true` |

截图目录：`/tmp/xedc-member-ui-audit/`（overview、membership、favorites、messages、notifications、public、dark、mobile-drawer、redeem、public-with-works 等）。

---

## 13. 公开主页隐私边界

检查（匿名 `/r/aa`、登录 `/r/dcxe`）：

- 无邮箱字段 / 密码控件
- hero 内无「我的收藏 / 浏览历史 / 账号安全」
- 仅公开作品计数与已发布卡片  
后端过滤未改；UI 未引入私有数据块。

---

## 14. PHP lint

```
php -l MemberController.php  → No syntax errors
php -l xedc_member.module    → No syntax errors
```

---

## 15. Drupal 缓存

多次执行 `vendor/bin/drush cr` → `[success] Cache rebuild complete.`

---

## 16. 浏览器测试结果（摘要）

脚本：`web/modules/custom/xedc_member/tests/manual/browser-member-ui-redesign.cjs`  
账号：uid=3（uli），另测匿名公开页。

- 会员相关路径：全部 HTTP 200，`.xm` 壳存在，无横向溢出
- 通知三分类：200
- 投稿三类型新增：200
- 暗色三页：ok=true
- 移动抽屉：ok=true
- 完整 JSON：`/tmp/xedc-member-ui-audit/report.json`

---

## 17. 未测试项目（NOT TESTED）

1. 投稿编辑页（无可用编辑入口）
2. 私信会话详情（无会话）
3. 取消收藏 / 取消点赞 / 清空历史 / 发私信 / 全部已读 / 资料保存 / 改密 — 避免生产写操作
4. 无作品以外的更多公开作者分页边缘用例（仅抽测 `/r/aa` 与空态 `/r/dcxe`）

---

## 18. 遗留问题

1. CSS 聚合环境下审计脚本的 `styleSheets.href` 探测会显示 `cssMember=false`，属探测限制，不影响实际加载。
2. 通知页暗色下个别标题曾依赖全局色；已补 `--xn-fg`，建议线上硬刷新后再目视一次。
3. 投稿编辑与私信详情需在有夹具数据的隔离账号上补测。

---

## 结论

会员中心各现有页面与公开主页已按统一 Modern Minimal 语言完成展示层整改：侧栏 + 移动抽屉、欢迎/VIP 信息层级、列表/表单/通知壳一致，白/暗色与隐私边界通过 Chromium 抽测。业务逻辑、权限与数据结构保持不变。
