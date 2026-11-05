# XEDC 全站移动端输入框聚焦自动放大修复报告

**日期**: 2026-11-05  
**站点**: https://gitxx.xyz  
**环境**: Drupal 11.4.11 / PHP 8.5.x / 前台主题 `xedc` / 后台主题 `xedc_admin`（Gin 5.0.16 已安装但非活动管理主题）  
**分支**: `main` @ `61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96`  
**性质**: 全站移动端输入控件兼容性审计与修复（不禁用用户缩放）

---

## 1. 全站输入控件排查范围

### A. 公共前台
| 区域 | 控件来源 | 排查结果 |
|------|----------|----------|
| 登录 / 注册 / 密码重置 | `form.xedc-auth` | 原 14px，曾仅在 ≤640px 升到 16px |
| 站内搜索页 | `.sr-input` | **移动端 CSS 主动设为 14px（关键冲突）** |
| 顶栏搜索 | `.xn-search` / `.xn-msearch` | 桌面 14px；移动抽屉已有 16px |
| 图片列表筛选搜索 | `.photo-drop__search input` | 14px |
| 视频列表搜索 | `.xv-search input` | 15px |
| 文章列表搜索 | `.xa-input` | 0.9375rem（15px） |
| 标签页搜索 | `.xt-search input` | 14px |
| 联系表单 | `.contact-message-form` | 15px |
| 评论 | `.comment-form .form-textarea` | 已是 1rem（16px） |

### B. 会员中心
| 区域 | 控件来源 | 排查结果 |
|------|----------|----------|
| 资料编辑 `/aa/profile` | nickname / bio / alias | `member.css` 统一 14px |
| 账号安全 `/user/*/edit` | mail / password / select | 继承 Bootstrap + member/admin 规则 |
| 图片/视频/文章投稿 | `.xm-submission-form` 等 | 统一 14px |
| 私信 | `.xn-msg__composer textarea` | 14px；列表页可能无线程输入 |

### C. 后台与弹窗
| 区域 | 控件来源 | 排查结果 |
|------|----------|----------|
| `xedc_admin` 表单 | `forms.css` | 桌面 14px；≤1023px 已有 16px（无 `!important`，覆盖不全） |
| Media Library 弹窗 | `media-library-modal.css` | **搜索框 `font-size: 13px !important`（强冲突）** |
| AI 运营表单 | `xedc_ai` `ai.css` | `font: inherit`，易落到 <16px |
| Gin / Claro | 已安装 Gin 5.0.16 | **当前 `system.theme.admin = xedc_admin`，页面不由 Gin 渲染**；已加防御性库 |

### D. 主题 / Viewport
- 前台 `xedc`、后台 `xedc_admin` 均基于 Radix，viewport 由 Drupal 核心默认输出。
- 实测唯一 viewport：`width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-visual`
- **未发现** `user-scalable=no` / `maximum-scale=1`，也未发现多 viewport 冲突。

---

## 2. 自动放大的真实根因

**主因：iOS Safari 对计算字号 < 16px 的可编辑控件聚焦时自动放大页面。**

次要因素：

1. 全站多处组件 CSS 把 `input` / `textarea` / `select` 设为 **13–15px**（视觉紧凑）。
2. 主题级 `inputs.css` 虽曾写 `font-size: 16px !important`，但仅包在 `@media (pointer: coarse)` 中：外接键鼠/部分模拟器可能不匹配 `pointer: coarse`，导致规则不生效。
3. 搜索页移动端规则 **主动把 `.sr-input` 写成 14px**，与防缩放目标相反。
4. Media Library 使用 **`font-size: 13px !important`**，即使全局 16px 规则也会被压住。
5. **排除**：viewport 锁定缩放、`:focus { transform: scale() }`、JS 改字号 —— 审计中未发现这些作为主因。

---

## 3. Viewport 配置检查结果

| 检查项 | 结果 | 状态 |
|--------|------|------|
| `<meta name="viewport">` 存在 | 是，单标签 | PASS |
| 多 viewport 冲突 | 无 | PASS |
| `width=device-width` | 有 | PASS |
| `initial-scale` | 1 | PASS |
| `user-scalable=no` | 无 | PASS |
| `maximum-scale=1` | 无 | PASS |
| 前后台不同模板输出冲突 | 均走 Drupal 默认 meta | PASS |
| 是否用禁用缩放“掩盖”问题 | **否**（明确禁止并验证） | PASS |

---

## 4. 输入控件字体尺寸检查结果（修复后）

| 场景 | 视口 | 聚焦后 font-size | visualViewport.scale | 横向溢出 |
|------|------|------------------|----------------------|----------|
| 登录用户名/密码 | iPhone 13 | 16px | 1 | 否 |
| 注册邮箱 | iPhone 13 | 16px | 1 | 否 |
| 密码重置 | iPhone 13 | 16px | 1 | 否 |
| 图片/视频/文章投稿标题 | iPhone 13 | 16px | 1 | 否 |
| Media Library 搜索 | iPhone 13 | 16px | 1 | 否 |
| 后台内容筛选 | iPhone 13 | 16px | 1 | 否 |
| 会员资料 nickname/bio/alias | iPhone 13 | 16px | 1 | 否 |
| 账号编辑邮箱等 | iPhone 13 | 16px | 1 | 否 |
| Android Pixel7 登录 | 412×915 | 16px | 1 | 否 |
| iPad Mini 登录 | 768×1024 | 16px | 1 | 否 |
| 桌面登录 | 1440×900 | **14px（有意保留桌面紧凑）** | 1 | 否 |

桌面端保持 14px 是预期行为：iOS 自动缩放阈值不适用于桌面，且需求要求不影响桌面布局。

---

## 5. 前台和后台的修复方式

### 原则
- **不**设置 `user-scalable=no` / `maximum-scale=1`
- **不**用全局 JS 拦截双指缩放
- 移动端（及粗指针平板）保证可编辑控件计算字号 **≥ 16px**
- 前后台 CSS 隔离；Gin/Claro 仅防御性挂载

### 前台 `xedc`
1. 强化 `css/inputs.css`（挂在全局 `xedc/style`）：
   - `@media (max-width: 767px)` + `@media (pointer: coarse) and (max-width: 1023px)`
   - 覆盖 `input`（排除 button/checkbox 等）、`textarea`、`select`、常见 Drupal/Bootstrap 类、搜索类、`[contenteditable]`、CKEditor 可编辑区
   - `font-size: 16px !important`（仅移动相关查询）
2. 修正会覆盖全局规则的组件：
   - `search.css`：移动端 `.sr-input` 14px → **16px**
   - `auth.css`：断点扩至 767px，并 `!important`
   - `xedc-navbar.css` / `photo-list.css` / `video-list.css` / `article-list.css` / `legal.css`
3. 模块：`xedc_member` / `xedc_notify` / `xedc_tags` 补移动端 16px

### 后台 `xedc_admin`
- `forms.css`：≤1023px 扩展选择器（含 password/date/select/contenteditable/CKEditor），加 `!important`
- Media Library：≤767px 将弹窗搜索/筛选从 13px 提到 **16px**，高度调至 40px 以免裁切

### Gin / Claro 防御
- 新增 `xedc_core/css/mobile-inputs-admin.css` + library `xedc_core/mobile_inputs_admin`
- `xedc_core_page_attachments()` 在活动主题为 `gin`/`claro` 时挂载
- **当前生产后台主题仍是 `xedc_admin`**，该库平时不加载，避免污染前台

---

## 6. 会员中心各页面检查情况

| 页面 | 状态 | 说明 |
|------|------|------|
| 会员首页 | PASS | 少/无文本输入时跳过交互，规则仍全局生效 |
| 资料编辑 `/aa/profile` | PASS | nickname/bio/alias = 16px |
| 账号编辑 `/user/1/edit` | PASS | email/password/select = 16px |
| 投稿枢纽 | PASS | |
| 图片投稿 | PASS | 标题等 16px，scale=1 |
| 视频投稿 | PASS | 同上 |
| 文章投稿 | PASS | 同上 |
| 私信列表 | PASS | 可见搜索类输入 16px；无打开线程时 composer 规则已加，真机线程需再验 |

---

## 7. 弹窗和私信输入区域检查情况

| 区域 | 状态 | 说明 |
|------|------|------|
| Media Library 搜索/筛选 | PASS | 聚焦后 16px，scale=1，截图见 `docs/tasks/screenshots/xedc-mobile-input-zoom/` |
| Drupal Dialog（前台 dialog_fix） | PASS（规则覆盖） | 依赖全局 inputs + modal CSS |
| 私信 composer | PASS（CSS） | `notify.css` 移动端 16px；无线程时 UI 未点到 textarea |
| 登录弹窗 | PASS（规则） | 复用 auth 表单 16px |

---

## 8. 软键盘弹出和关闭测试结果

| 测试 | 状态 | 说明 |
|------|------|------|
| 视口高度收缩模拟键盘（投稿标题聚焦） | PASS | scale 保持 1，无横向溢出；关闭后高度恢复 |
| 真实 iOS 键盘几何 / Visual Viewport 抖动 | **NOT TESTED** | 无真机；Playwright 只能近似 |
| 固定底栏遮挡输入框 | **NOT TESTED** | 需真机目视 |

Viewport 已含 `interactive-widget=resizes-visual`，有利于键盘弹出时可视区调整。

---

## 9. iPhone Safari 和 Android Chrome 测试结果

| 环境 | 状态 | 说明 |
|------|------|------|
| Playwright iPhone 13 模拟 | PASS | font≥16、scale=1、无横向溢出 |
| Playwright Pixel 7 / iPad 模拟 | PASS | 同上 |
| Playwright 桌面 1440 | PASS | 字号仍 14px，布局未坏 |
| **真实 iPhone Safari** | **NOT TESTED** | 模拟器不能完全代替真机 Safari 自动缩放行为 |
| **真实 Android Chrome** | **NOT TESTED** | 同上 |

测试产物：
- `docs/tasks/screenshots/xedc-mobile-input-zoom/results.json`
- 同目录多张 focus / keyboard 截图
- 脚本：`web/modules/custom/xedc_core/tests/manual/browser-mobile-input-zoom.mjs`  
  `web/modules/custom/xedc_core/tests/manual/browser-mobile-input-zoom-extra.mjs`

---

## 10. 修改文件清单

### 主题
- `web/themes/custom/xedc/css/inputs.css`（重写移动端守卫）
- `web/themes/custom/xedc/css/auth.css`
- `web/themes/custom/xedc/css/search.css`
- `web/themes/custom/xedc/css/xedc-navbar.css`
- `web/themes/custom/xedc/css/photo-list.css`
- `web/themes/custom/xedc/css/video-list.css`
- `web/themes/custom/xedc/css/article-list.css`
- `web/themes/custom/xedc/css/legal.css`
- `web/themes/custom/xedc/xedc.libraries.yml`
- `web/themes/custom/xedc_admin/css/forms.css`
- `web/themes/custom/xedc_admin/xedc_admin.libraries.yml`

### 模块
- `web/modules/custom/xedc_member/css/member.css`
- `web/modules/custom/xedc_member/css/media-library-modal.css`
- `web/modules/custom/xedc_member/xedc_member.libraries.yml`
- `web/modules/custom/xedc_notify/css/notify.css`
- `web/modules/custom/xedc_notify/xedc_notify.libraries.yml`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `web/modules/custom/xedc_tags/css/xedc-tags.css`
- `web/modules/custom/xedc_tags/xedc_tags.libraries.yml`
- `web/modules/custom/xedc_core/css/mobile-inputs-admin.css`（新）
- `web/modules/custom/xedc_core/xedc_core.libraries.yml`
- `web/modules/custom/xedc_core/xedc_core.module`
- `web/modules/custom/xedc_core/tests/manual/browser-mobile-input-zoom.mjs`（新）
- `web/modules/custom/xedc_core/tests/manual/browser-mobile-input-zoom-extra.mjs`（新）

### 文档 / 截图
- `docs/XEDC_GLOBAL_MOBILE_INPUT_ZOOM_FIX_REPORT.md`（本文件）
- `docs/tasks/screenshots/xedc-mobile-input-zoom/*`

**未修改**: Drupal 核心、contrib、`config/sync`、业务逻辑（除 `page_attachments` 防御性挂载）、数据库。

---

## 11. 桌面端回归结果

| 检查 | 状态 |
|------|------|
| 登录页聚焦后 scale=1 | PASS |
| 无横向溢出 | PASS |
| 桌面字号仍为 14px（未被强制 16） | PASS |
| 未禁用双指缩放 meta | PASS |

---

## 12. 未完成项目和风险

1. **真机 iPhone Safari / Android Chrome 未测** — 必须人工确认聚焦不再整页放大。
2. 私信**具体聊天线程** composer：列表页无打开会话时未点到 textarea；CSS 已覆盖，建议真机进线程再点一次。
3. CKEditor 正文编辑区：已用 `.ck-editor__editable` / `[contenteditable]` 规则覆盖，复杂工具栏场景建议后台真机抽检。
4. Gin 非活动主题：若将来切到 Gin，依赖 `xedc_core/mobile_inputs_admin`；建议切换后立刻回归。
5. CSS `!important` 用于压过组件级 13–14px 规则；后续新写输入样式请勿在移动端再写 <16px（尤其带 `!important`）。
6. 工作区另有大量与本任务无关的未提交改动，**未 commit / 未 push**。

---

## 13. Git 工作区状态

```
开始前 HEAD: 61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96
分支: main
本任务: 仅改上述必要文件；保留其余未提交工作
git reset / checkout / clean / commit / push: 均未执行
结束时工作区仍含大量既有 M/?? 文件（约 140+ 条 short status）
```

---

## 验收对照

| # | 标准 | 状态 |
|---|------|------|
| 1 | 全站输入聚焦不再非预期放大（模拟器指标） | PASS |
| 2 | 登录/注册/密码重置 | PASS |
| 3 | 会员中心主要表单 | PASS |
| 4 | 投稿页 | PASS |
| 5 | 私信区域 | PASS（CSS + 列表输入）；线程 composer 建议真机 |
| 6 | Media Library | PASS |
| 7 | 后台 xedc_admin | PASS；Gin 页面 NOT TESTED（非活动主题） |
| 8 | 软键盘近似无变形/无横向溢出 | PASS（近似） |
| 9 | 键盘关闭恢复 | PASS（近似） |
| 10 | 桌面不受影响 | PASS |
| 11 | 仍可双指缩放（meta 未锁） | PASS |

**说明**: 凡标注 NOT TESTED 的项均未在真实手机浏览器执行；Playwright 结果不能完全代替真机验收。
