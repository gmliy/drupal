# XEDC 公开个人主页全面重新设计报告

**日期：** 2026-11-05  
**站点：** https://gitxx.xyz  
**结论：** **PASS**

---

## 1. 原个人主页设计存在的问题

1. **布局偏“资料卡片拼装”**：头像、昵称、分享、操作、统计被挤在旧式网格里，作品区存在感不足。
2. **身份信息层级弱**：缺少公开标识（slug / 用户名）展示，昵称与辅助信息区分不清。
3. **文章被强制做成图片卡片**：标题优先的阅读型内容不适配 4:5 封面网格。
4. **私信主按钮对比度失效**：`.xp a { color: inherit }` 特异性高于 `.xp-action--primary`，主按钮文字色被继承成与背景同色，视觉上像“按钮坏了”。
5. **匿名私信文案不清晰**：匿名用户同样显示“私信”，虽可跳登录，但意图不够明确。
6. **空状态与媒体失败回退较弱**：缺封面时体验不统一。

---

## 2. 新设计方案

采用 **Modern Creator Profile（现代创作者主页）**，独立于会员中心布局：

- **上：身份区** — 头像 + 昵称 + `@slug`/用户名 + 简介 + 加入时间 + 公开统计 + 互动按钮
- **下：作品区** — 分类 Tab + 排序 + 作品网格/文章列表 + 分页
- 减少嵌套卡片、重阴影、大渐变；沿用站点 `--foreground / --muted / --border` 语义色
- 作品是视觉主体；资料区干净、有留白但不空洞

---

## 3. 页面结构与视觉变化

| 区域 | 旧 | 新 |
|------|----|----|
| 根结构 | `.xp-hero` 三列网格 | `.xp-profile` 弹性行 + `.xp-works` |
| 身份 | 昵称为主 | 昵称 + `@slug` + 公开用户名（如不同） |
| 操作 | 按钮对比度易失效 | 主/次按钮层级清晰，图标+文字 |
| 媒体 | 统一 4:5 卡片 | 图片 4:5；视频 16:10；文章全宽标题优先 |
| 空状态 | 简单居中文字 | 轻量文案，无“去投稿”（仅本人空主页） |

---

## 4. 头像、资料、统计、作品区域整改

### 头像
- 桌面 96px / 平板起 96px / 手机 72px 圆形
- 无夸张描边；`data-xp-avatar` 加载失败回退 SVG

### 资料
- 昵称 `h1.xp-name`
- 辅助 `.xp-handle`：`@slug`，必要时附加公开账户名
- 简介有则显示并限制行数；空简介不占位
- 加入时间弱化显示

### 统计
- 仍来自 `PublicProfileBuilder::stats()` 的**已公开**图片/视频/文章计数
- 未新增私人统计；未改查询条件

### 作品
- 分类 / 排序保留原 URL 与 slug
- 图片/视频网格；文章横向标题优先布局
- 封面失败 → placeholder；分页逻辑不变

---

## 5. 私信按钮异常原因与修复

### 根因
1. **CSS 特异性**：`.xp a { color: inherit }`（0,1,1）压过 `.xp-action--primary`（0,1,0），主按钮文字与背景同色。
2. **匿名体验**：可登录，但文案仍是“私信”，易误解为可直接发信。

### 修复
1. 选择器改为 `.xp a.xp-action--primary` / `.xp .xp-action--primary`，并引入 `--xp-accent` / `--xp-accent-fg`。
2. `ProfileActionsBuilder` 增加 `#message_guest`；模板显示「登录后私信」，链接仍为 `user.login?destination=当前主页`。
3. 登录用户：收件人始终为资料主人 `$target`；访问自己主页返回空 markup（不显示私信/关注）。
4. 按钮增加图标，保证图文垂直居中与足够触摸高度（移动端 min-height 44px）。

### 调用链确认（未改路由 machine name）
`Twig profile_actions` → lazy `xedc_notify.profile_actions:actions` → `message_start` `/member/messages/to/{user}`（vanity 下可显示为 `/{viewer-slug}/messages/to/{uid}`）→ 会话页。

---

## 6. 实际修改文件清单

| 文件 | 变更 |
|------|------|
| `web/modules/custom/xedc_member/templates/xedc-public-profile.html.twig` | 创作者主页结构重写 |
| `web/modules/custom/xedc_member/src/Service/PublicProfileBuilder.php` | 增加公开 `username` / `slug` / `works_total` 展示变量（无改查询权限） |
| `web/modules/custom/xedc_member/xedc_member.module` | theme 变量声明 |
| `web/modules/custom/xedc_notify/templates/xedc-notify-profile-actions.html.twig` | 图标 + 访客文案 |
| `web/modules/custom/xedc_notify/src/ProfileActionsBuilder.php` | `message_guest` + cache contexts |
| `web/modules/custom/xedc_notify/xedc_notify.module` | theme 变量 |
| `web/themes/custom/xedc/css/public-profile.css` | 整体重写 v2 |
| `web/themes/custom/xedc/js/public-profile.js` | 分享 + 头像/封面失败回退 |
| `web/themes/custom/xedc/xedc.libraries.yml` | `public_profile` → 2.0 |
| `web/modules/custom/xedc_member/tests/manual/browser-public-profile-redesign.mjs` | 浏览器验收脚本 |
| `docs/tasks/screenshots/xedc-public-profile/*` | 截图与 results.json |
| `docs/XEDC_PUBLIC_PROFILE_FULL_REDESIGN_REPORT.md` | 本报告 |

---

## 7. 私信功能测试结果

| 场景 | 结果 |
|------|------|
| 匿名访问 `/r/aa` | 显示「登录后私信」→ `/user/login?destination=/r/aa`；文字对比正常 |
| uid=3 访问 `/r/aa` | 显示「关注」+「私信」；href=`/dcxe/messages/to/1`（收件人=资料主人 uid 1） |
| 点击私信 | 200 → `https://gitxx.xyz/dcxe/messages/9`；会话头为「管理员」 |
| 未发送消息正文 | 是（只验证进入会话，不给真实用户灌测试内容） |
| uid=1 访问自己的 `/r/aa` | **无**关注/私信按钮 |
| 计算样式 | `color: rgb(250,250,250)` on `background: rgb(24,24,27)`（亮色）；暗色模式反转后仍对比正常 |

---

## 8. 匿名与登录访问测试

| 测试 | 结果 |
|------|------|
| 匿名 `/r/aa`（有作品） | 200，12 卡片，无 JS 异常 |
| 匿名 `/r/12shh`（空） | 200，空状态「这里还没有公开作品」 |
| 匿名分类 photo/video/article | 均 200，active filter 正确 |
| 登录访问他人主页 | 200，互动按钮正常 |
| 登录访问自己主页 | 200，无自私信 |
| 控制台 | 验收脚本未捕获 pageerror |

---

## 9. 公开数据隐私检查

公开页仅输出 builder 允许字段：昵称、公开简介、加入日、头像、公开作品计数、已发布作品卡片、分享 URL、关注/私信入口。

**未在服务端输出：** 邮箱、密码、收藏、点赞记录、下载、浏览历史、私信内容、通知、未审核投稿、VIP 私有数据。

HTML 扫描未命中私人标签；未用 CSS 隐藏敏感块。

发现并保持：公开账户名随 slug 展示属既有公开身份信息；未扩大权限范围。

---

## 10. 桌面 / 平板 / 移动端测试

| 视口 | 结果 |
|------|------|
| 1440 desktop | 无横向滚动；4 列网格；按钮对比正常 |
| 768 tablet | 无横向滚动；3 列网格 |
| 390 mobile | 无横向滚动；分享仅图标；操作按钮可点；分页为 `当前/总页` |

截图：`docs/tasks/screenshots/xedc-public-profile/anon-aa-light-{desktop-1440,tablet-768,mobile-390}.png`

---

## 11. 白色 / 暗色模式测试

| 模式 | 结果 |
|------|------|
| Light | 白底、主按钮深色底浅色字 |
| Dark (`data-bs-theme=dark`) | 无白底残留；主按钮浅底深字；网格/Tab/空状态正常 |

截图：`anon-aa-dark-1440.png`、`auth-own-aa-dark-1440.png`

---

## 12. 浏览器截图位置

目录：`docs/tasks/screenshots/xedc-public-profile/`

主要文件：

- `anon-aa-light-1440.png` — 有作品匿名
- `anon-empty-12shh-1440.png` — 空主页
- `anon-aa-{photo,video,article}-1440.png` — 分类
- `anon-aa-dark-1440.png` — 暗色
- `anon-aa-light-mobile-390.png` / `...-tablet-768.png`
- `auth-uid3-on-aa-1440.png` — 登录访他人
- `auth-uid3-pm-after-click.png` — 私信进入会话
- `auth-own-aa-1440.png` — 自己的主页（无自私信）
- `results.json` — 机器验收摘要

---

## 13. 未测试事项

1. **超长昵称（>40 字）**：现有数据最长昵称较短；仅测了约 70 字英文简介（`/r/aa`）。
2. **实际发送私信正文**：刻意未发，避免污染真实会话；仅验证进入正确会话。
3. **notify_test_* 无 slug**：未用于公开主页；使用既有 `/r/aa`、`/r/12shh`、`/r/dcxe`。
4. **CSS 聚合文件名探测**：脚本里 `cssPublic` 对聚合 URL 可能假阴性；实测样式与对比度已生效。

---

## 14. 是否修改业务逻辑

| 项 | 是否修改 |
|----|----------|
| VIP / MembershipManager / 收藏点赞 / 下载 / 上传 / R2 / 通知投递 | **否** |
| 公开主页路由 / URL | **否** |
| DB schema / config/sync | **否** |
| 公开作品查询条件与权限 | **否** |
| ProfileActions 展示层（访客文案 + cache context） | **是（展示/缓存上下文，非权限放大）** |
| PublicProfileBuilder 展示变量 | **是（仅增加已公开身份字段输出）** |

未 commit / 未 push；未执行 git reset/checkout/clean。

---

## 15. 最终结论

**PASS**

公开个人主页已按 Modern Creator Profile 整体重做：身份区与作品区层次清晰，文章/图片/视频展示分化合理，白/暗色与 1440/768/390 验收通过。私信按钮对比度根因已修，匿名登录引导、他人私信收件人、本人隐藏自私信均按业务正确工作。
