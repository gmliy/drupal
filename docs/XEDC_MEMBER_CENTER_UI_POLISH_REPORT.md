# XEDC 会员中心 UI 精修报告

日期：2026-11-05  
性质：二次视觉精修（间距统一、卡片减法、侧栏选中态）  
约束：未改业务逻辑 / 路由 / 权限 / 数据结构；未 commit / push；保护既有脏工作区。

---

## 1. 原有视觉问题

1. 会员中心 `.container` 被压成 `1180px`，全站前台为 `1280px`，左右边界不一致。
2. 侧栏做成重边框+阴影卡片，选中态用 inset 粗强调线，偏「后台」。
3. 首页欢迎区、统计、常用入口、最近收藏多层边框/阴影嵌套，视觉零碎。
4. 公开主页 `.xp` 自限 `72rem` 且再加水平 padding，落在站点 `.container` 内形成双重留白。
5. 通知外壳多余边框/阴影；空状态带装饰圆环与虚线框。

---

## 2. 全站容器和间距对比

| 页面 | 容器 max-width | 水平 gutter | 实测内容宽 (1440 viewport) |
| --- | --- | --- | --- |
| 全站首页 / 列表基准 | `1280px`（`.container`） | `16px` | `1280` |
| 会员中心（精修前） | `1180px` 覆盖 | `16px` | 更窄 |
| 会员中心（精修后） | `1280px`（与全站一致） | `16px` | `1248`（1280−32） |
| 公开主页（精修后） | 跟随 `.container` 100% | 不再二次水平 pad | `1248` |

侧栏宽度：`248px` → `220px`；主区间距 `gap: 32px`。移动端 gutter 保持 `16px`，无双重内容 padding。

---

## 3. 修改文件清单

| 文件 | 变更 |
| --- | --- |
| `web/modules/custom/xedc_member/css/member.css` | 容器 1280、侧栏去卡片化、选中态、区块/空状态/表格减法 |
| `web/modules/custom/xedc_member/templates/xedc-member-overview.html.twig` | 常用入口/最近收藏改为 `xm-section` |
| `web/modules/custom/xedc_member/templates/xedc-member-membership.html.twig` | 权益区去卡片 |
| `web/modules/custom/xedc_member/xedc_member.libraries.yml` | `member` → `2.2` |
| `web/themes/custom/xedc/css/public-profile.css` | 对齐容器、hero/空状态/卡片阴影减法 |
| `web/modules/custom/xedc_notify/css/notify.css` | 通知去外壳卡片；私信保留单层工作区框 |
| `docs/XEDC_MEMBER_CENTER_UI_POLISH_REPORT.md` | 本报告 |

---

## 4. 各页面间距整改结果

| 页面 | 结果 |
| --- | --- |
| 首页 / 收藏 / 点赞 / 下载 / VIP / 投稿 / 资料 / 私信 / 通知 | PASS — container `1280`，内容对齐线一致 |
| 通知分类 `/notifications/vip` | PASS |
| 投稿新增 `/node/add/photo` | PASS — 同容器宽 |
| 公开主页 `/r/aa` | PASS — 与会员内容同宽 `1248` |
| 移动端 | PASS — gutter `16px`，无横向溢出 |

---

## 5. 卡片和边框精简结果

- 侧栏：透明背景，无边框/阴影（抽屉打开时保留表面底）。
- 欢迎区 / 公开 hero：仅底部分割线，无卡片框。
- 统计与快捷入口：浅底无描边无阴影。
- 区块标题直接落在页面背景上（`xm-section`）。
- VIP 状态条、资料表单、安全表单、会员状态卡：保留必要单层容器。
- 评论/投稿列表：行底部分割，替代整块卡片。
- 下载表：上下分割线，去掉外框与表头色块。
- 空状态：去掉虚线框与装饰圆。
- 私信：保留一个轻量工作区边框（交互需要）。
- 内容卡片（收藏网格 / 公开作品）：保留媒体边框，去掉多余阴影。

概览页边框节点由约 23 降至约 9；阴影由约 8 降至 0。

---

## 6. 侧边导航选中样式整改结果

| 状态 | 样式 |
| --- | --- |
| 默认 | 正文字色；图标弱化 `--xm-mut` |
| Hover | 浅 `--xm-hover` 背景 |
| Active | `--xm-surface-muted` 浅底、字重 600、图标同色、**无** inset 粗线、**无**阴影 |
| 暗色 Active | `--xm-surface-muted`（`#27272a`），非浅色块硬套 |

实测 light active：`rgb(244,244,245)` + `shadow:none`；dark：`rgb(39,39,42)`。

---

## 7. 子路由高亮验收

沿用 `MemberController::shell($active, …)` / `renderShell()` 传入的 `active` key（非 URL 字符串匹配）：

| 路由 | 高亮项 | 结果 |
| --- | --- | --- |
| `/member/notifications/social` | 通知 | PASS |
| `/dcxe/favorites` | 我的收藏 | PASS |
| `/member/redeem` | VIP 卡密兑换 | PASS |
| 私信详情 / 投稿编辑 | 依赖既有 `active` 传参 | 本轮未改逻辑；列表/分类已验 |

投稿新增页无会员壳侧栏（`xm-submission-page`），不要求侧栏高亮。

---

## 8. 白色模式验收

PASS — 画布浅灰、侧栏透明、选中浅底、正文对比正常。截图：`/tmp/xedc-member-ui-polish/*.png`。

---

## 9. 暗色模式验收

PASS — `/member`、`/r/aa`、`/member/notifications`：`data-bs-theme=dark`，body `#09090b`，选中 `#27272a`，标题近白。

---

## 10. 桌面端和移动端验收

| 端 | 结果 |
| --- | --- |
| 桌面 1440 | 侧栏 220 + gap 32；与全站 1280 对齐 |
| 移动 390 | 抽屉可开；选中样式一致；`overflowX=false`；gutter 16px |

---

## 11. 测试结果

- `drush cr` 成功。
- Chromium 抽测：会员主路径、通知分类、兑换、公开主页、投稿新增、白/暗色、移动抽屉。
- 报告 JSON：`/tmp/xedc-member-ui-polish/report.json`。

---

## 12. 未解决的问题

1. `/photos` 非真实路径（图库为 `/meitu`）；对比改用首页 `/` 与会员 container。
2. 投稿编辑页、私信详情页未在本轮重复打开（无夹具 / 避免生产写操作）。
3. 内容网格卡片仍有必要边框（收藏/公开作品），属内容浏览组件，未再削到无边界。

---

## 结论

会员中心已与全站 `1280/16` 容器对齐，侧栏与首页区块完成视觉减法，选中态改为轻量浅底。整体更接近前台内容站，而不是独立后台壳。
