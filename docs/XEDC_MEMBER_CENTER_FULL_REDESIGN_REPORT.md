# XEDC 会员中心全站 UI 重新设计报告

日期：2026-11-05  
站点：https://gitxx.xyz  
性质：整体视觉重新设计（非补丁精修）  
约束：未改业务逻辑 / VIP / 上传 Worker / 权限 / 路由 / 库表；未 commit / push；保护既有脏工作区。

---

## 1. 原设计问题

1. 会员中心使用独立灰底画布（`#f4f5f7`）与 `ui-sans-serif` 字体栈，与全站白底 + Inter/系统字体脱节，像另一套后台。
2. `member.css` 经两轮叠加至 ~2700 行，选择器互相覆盖，视觉语言不稳定。
3. 侧栏被做成卡片或再被删边框后选中态仍显生硬；信息层级靠大量矩形堆叠。
4. 公开主页与会员页色板、圆角、字重不统一。
5. 侧栏展示邮箱，偏管理后台观感。

---

## 2. 新设计理念

**Modern Minimal Editorial / 现代极简内容社区**

- 白底默认，完整暗色（对齐站点 Bootstrap zinc 暗色，而非另造色板）。
- 用字号、字重、留白与细分割线建立层级，而不是给每个模块套大卡片。
- 侧栏安静、像站点导航延伸，而不是仪表盘。
- 媒体内容优先（收藏/公开作品卡片），管理信息克制。
- 参考 Linear / Vercel / Notion 的克制语言，不复制布局。

---

## 3. 统一设计系统

以全站实测为基准（首页 / 美图列表）：

| Token | Light | Dark（对齐站点） |
| --- | --- | --- |
| Canvas / Surface | `#fff` | `#18181b` |
| Soft / Active / Hover | `#f5f5f4` | `#27272a` |
| Foreground | `#1c1917` | `#fafafa` |
| Muted | `#78716c` | `#a1a1aa` |
| Border | `#e7e5e4` | `#3f3f46` |
| Primary button | `#1c1917` on `#fafaf9` | 反相 |
| Radius | 10 / 8 | 同 |
| Control / Button height | 36px | 同 |
| Container | `1280px` + `16px` gutter | 同 |
| Font | 继承全站 `-apple-system…Inter…PingFang SC` | 同 |

实现位置：`web/modules/custom/xedc_member/css/member.css`（v3.0 整文件重写）、`public-profile.css`、`notify.css`。

---

## 4. 修改文件清单

| 文件 | 说明 |
| --- | --- |
| `web/modules/custom/xedc_member/css/member.css` | **完整重写**（约 1112 行，替代原 ~2700 行补丁堆） |
| `web/modules/custom/xedc_member/xedc_member.libraries.yml` | `member` → `3.0` |
| `web/modules/custom/xedc_member/templates/xedc-member-shell.html.twig` | 侧栏去掉邮箱展示 |
| `web/modules/custom/xedc_member/templates/xedc-member-overview.html.twig` | 编辑式首页结构 |
| `web/modules/custom/xedc_member/templates/xedc-member-membership.html.twig` | VIP 状态 + 权益双栏 |
| `web/themes/custom/xedc/css/public-profile.css` | 公开主页对齐同色板 |
| `web/modules/custom/xedc_notify/css/notify.css` | 私信/通知展示层重写 |
| `docs/XEDC_MEMBER_CENTER_FULL_REDESIGN_REPORT.md` | 本报告 |

业务 PHP（MembershipManager、Favorite/Like/Download、Upload Worker、Notify services）**未改**。Controller 仅沿用既有展示数据装配。

---

## 5. 各页面完成情况

| 页面 | 浏览器打开 | 视觉结果 |
| --- | --- | --- |
| 首页 `/member` | PASS | 白底、欢迎区、VIP 条、细线统计、常用入口、最近收藏 |
| 收藏 / 点赞 / 历史 | PASS | 统一媒体卡片 + Tab |
| 下载 | PASS | 简洁表行 |
| VIP | PASS | 状态块 + 权益列表 |
| 投稿列表 | PASS | Hub 卡片 + 列表行分隔 |
| 投稿新增 `/node/add/photo` | PASS | 分组表单壳（`xm-submit`） |
| 投稿编辑 | **NOT TESTED** | 测试账号无当前可编辑入口 |
| 资料 / 安全 | PASS | 表面表单区，非堆叠字段卡片 |
| 私信列表 | PASS | 单层工作区 |
| 私信详情 | **NOT TESTED** | 无现成会话 |
| 通知 / 分类 | PASS | 编辑式列表；分类高亮「通知」 |
| 卡密兑换 | PASS | 侧栏 Redeem 高亮 |
| 公开主页有作品 `/r/aa` | PASS | 白底 hero + 作品网格 |
| 公开主页空态 `/r/dcxe` | PASS | |
| 匿名公开主页 | PASS | |

---

## 6. 桌面 / 平板 / 移动端测试

| 视口 | 结果 |
| --- | --- |
| 1440 桌面 | PASS — 侧栏 212 + gap 40；与全站 1280 对齐；侧栏无边框卡片 |
| 768 平板 | PASS — 顶栏抽屉入口；无横向溢出 |
| 390 移动 | PASS — 抽屉开/关；选中浅底；无溢出 |

截图目录：`/tmp/xedc-member-full-redesign/`  
含：`d-overview*.png`、`d-favorites.png`、`d-membership.png`、`d-messages.png`、`d-notifications.png`、`d-public.png`、`dark-*.png`、`tablet-overview.png`、`mobile-drawer.png`、`d-meitu.png`（对照）等。

---

## 7. 白色 / 暗色模式测试

| 模式 | 结果 |
| --- | --- |
| 白色 | PASS — 会员背景 `rgb(255,255,255)` 与首页一致 |
| 暗色 | PASS — `data-bs-theme=dark`；画布 `rgb(24,24,27)`；标题近白；选中 `#27272a`；私信/通知可读 |

---

## 8. 真实浏览器截图位置

`/tmp/xedc-member-full-redesign/`

对照：`d-home.png`、`d-meitu.png` 与 `d-overview-final.png` / `d-favorites.png`。

---

## 9. 业务逻辑保护结果

- 未修改 MembershipManager、点赞/收藏/下载服务、审核流、Upload Session/Worker/R2。
- 未修改路由 machine name、权限、config/sync。
- 导航仍由 `shell($active)` / `renderShell()` 传 `active` key（通知分类、兑换、私信均验证高亮）。
- 抽屉 JS（`data-xm-*`、once）保留。

---

## 10. 隐私与权限检查结果

- 侧栏不再展示邮箱。
- 公开主页 hero 无邮箱 / 账号安全 / 浏览历史 / 私有收藏文案。
- 匿名可访问 `/r/aa`；私有会员路由需登录（暗色未登录曾 403，属预期）。

---

## 11. 未测试事项

1. 投稿编辑页（无可用编辑稿）。
2. 私信会话详情（无会话夹具）。
3. 取消收藏 / 发信 / 改密 / 全部已读等写操作（避免生产写）。
4. 上传取消与 Worker 交接（业务未改；UI 仅展示层）。

---

## 12. 最终结论

会员中心已从「灰底后台补丁」改为与全站一致的白底编辑式内容社区界面：统一 token、整文件 CSS 重写、侧栏与主区分层清晰，暗色对齐站点 zinc。桌面/平板/移动与主要页面已用 Chromium 实机截图验收。

**验收标准对照：** 不再像仪表盘卡片堆砌，也不再像独立后台；与 `/`、`/meitu` 同白底同容器；功能与隐私边界保持不变。
