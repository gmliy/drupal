# XEDC 图片集投稿与导入任务弹窗 UI 专项修复报告

日期：2026-11-05  
分支：`main`  
HEAD：`61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96`  
范围：会员前台图片集导入「保存」、前后台预览弹窗关闭按钮与两列布局  
未 commit / 未 push

---

## 1. 前台「发布」按钮错误的根因

会员导入任务详情页 `ImportController::actionLinks()` 在任务状态为 `review` 时，**无条件**添加后台路由：

`xedc_core.import_publish` → `/admin/content/image-imports/{id}/publish`

该路由由 `TaskAccess::publish()` 守卫，要求 `publish xedc image imports` 或 `administer xedc image imports`。普通会员只有任务归属查看权，因此点击后 403「没有权限」。

列表页 `ImportListForm::operations()` 存在同样问题：非管理员也会看到「发布」链到后台发布路由。

**不是**权限配置错误；是把管理员发布动作误暴露给了会员前台。

---

## 2. 「保存」按钮的实际业务流程

会员侧不再走 `Publisher::publish()` / 后台发布路由。

正确路径：

1. Worker 完成导入后，任务状态为 `review`，并已关联草稿/未发布 `photo` 节点（既有导入机制）。
2. 会员打开任务详情，看到说明文案与 **「保存」**。
3. 「保存」指向该节点的 `edit-form`，并带查询参数：
   - `xedc_import_save=1`
   - `xedc_task={task_id}`
   - `destination=/member/submissions`
4. 进入会员投稿编辑页（既有 `xedc_member` 投稿壳 + `xedc_editorial` 工作流）。
5. 会员确认标题/描述/栏目/标签后，选择 **保存草稿** 或 **提交审核**（沿用现有 moderation 控件）。
6. 不会直接公开；管理员后台「发布」仍独立存在。

`xedc_member.module` 在 `xedc_import_save=1` 时追加状态提示：说明需确认字段后保存草稿或提交审核，不会直接公开发布。

---

## 3. 是否正确进入会员投稿和审核流程

是。保存入口复用节点编辑表单与 `xedc_editorial` 转换权限（`submit_for_review` / 草稿），不调用后台 `Publisher`。

幂等性：保存是编辑既有 `nid`，不会新建第二个图片集节点。PHP 验收中对同一节点重复 save，标题计数保持 `1`。

---

## 4. 会员与管理员权限隔离结果

| 能力 | 会员 | 管理员/有发布权员工 |
|------|------|---------------------|
| 任务详情「保存」→ 节点编辑 | 有（自有任务 + `update`） | 仍可用「编辑内容」 |
| `/admin/content/image-imports/{id}/publish` | 拒绝（路由 access） | 允许，「发布」按钮保留 |
| 处理日志 / 清理 / 重随机封面 / 回滚 | 隐藏 | 显示 |
| 返回链接 | 我的投稿 | 导入列表 |

未放宽后台发布权限，未修改 `TaskAccess::publish()` 门槛。

---

## 5. 关闭按钮英文文字的根因和修复

**根因：** Drupal `core/drupal.dialog`（jQuery UI）关闭按钮默认 `closeText: "Close"`。前台 Radix/Bootstrap 又把按钮映射成 `btn-close` 背景 X，导致英文 “Close” 与 X 图标叠在一起。

**前台修复：**

- 新增 `xedc/dialog_fix`（`css/dialog-fix.css` + `js/dialog-fix.js`）
- `xedc.info.yml` 通过 `libraries-extend: core/drupal.dialog` 自动挂载
- CSS：隐藏 `.ui-button-text` / icon，用 SVG mask 绘制 X；`font-size:0` + 透明字色兜底
- JS：`aria-label` / `title` 设为「关闭」，保留无障碍

**后台：** `xedc_admin/css/dialog.css` 原本已有同类处理；本轮补齐隐藏 `.ui-button-text`，并为媒体库预览加两列网格。`admin.js` 已有中文 aria 行为，未改逻辑。

前台自定义 `.img-sheet__close` 本身已是「×」+ `aria-label="关闭"`，无英文问题；本轮只优化网格。

---

## 6. 前后台弹窗 / 预览修改情况

### 响应式网格（最终）

| 断点 | 每行 |
|------|------|
| ≥1200px 桌面 | **锁定 2 列** |
| 520px～1199px（平板与较宽手机） | **3 列** |
| ＜520px 手机 | **2 列**（不再单列） |

小屏仍保证卡片可读；`object-fit: contain`；图片区独立滚动；标题/关闭固定。

### 前台

- `photo-detail.css`：`.img-sheet__grid` 响应式 2/3 列；桌面锁定 2；标题栏 sticky；`object-fit: contain`
- `dialog-fix.css`：jQuery UI 弹窗宽度/内容滚动；媒体库同断点网格；暗色关闭图标
- `member.css`：投稿页媒体库已选预览同断点

### 后台

- `xedc_core/css/admin.css`：`.xedc-import-grid` 同断点、`object-fit: contain`、预览区独立滚动
- `xedc_admin/css/dialog.css`：关闭文字隐藏补强 + 弹窗内媒体库同断点

前后台未共用一套全局覆盖；分别挂在主题/组件 class 下。

---

## 7. 桌面端和手机端实际测试结果

| 场景 | 结果 |
|------|------|
| 桌面 img-sheet 两列（≥1200 锁定 2） | PASS |
| 平板 img-sheet 三列（820px → 3） | PASS |
| 手机 img-sheet 两列（390px → 2，不再单列） | PASS |
| Escape 关闭 img-sheet | PASS |
| 关闭按钮仅「×」、aria=关闭 | PASS |
| 后台导入详情两列 CSS（661px 661px） | PASS |
| 后台预览区 overflow:auto | PASS |
| 前后台 dialog 关闭文案隐藏 + X 图标 | PASS |

浏览器脚本：`web/modules/custom/xedc_core/tests/manual/browser-image-preview-ui.mjs`  
结果：`SUMMARY PASS 27/27`（另 1 项 NOT_TESTED：uid=2 当前无自有导入任务可点「保存」UI；该路径由 PHP 验收覆盖）

---

## 8. 深色模式测试结果

| 场景 | 结果 |
|------|------|
| `data-bs-theme=dark` 下打开 img-sheet | PASS（两列 + 关闭清洁） |
| 前台 dialog-fix 暗色关闭图标规则 | 已写 CSS；探针覆盖文本隐藏/图标 |
| 后台 Gin 暗色导入网格 | 沿用既有 `:is(html[data-gin-dark-mode]…)` 规则，未破坏 |

---

## 9. 修改文件清单

### 本轮相关（应纳入本任务）

| 文件 | 说明 |
|------|------|
| `web/modules/custom/xedc_core/src/Controller/ImportController.php` | 会员「保存」vs 员工「发布」；帮助文案；日志仅员工 |
| `web/modules/custom/xedc_core/src/Form/ImportListForm.php` | 列表操作同样隔离 |
| `web/modules/custom/xedc_core/css/admin.css` | 导入预览两列 + 滚动 |
| `web/modules/custom/xedc_core/xedc_core.libraries.yml` | admin library version |
| `web/modules/custom/xedc_member/xedc_member.module` | 导入保存提示 |
| `web/modules/custom/xedc_member/css/member.css` | 投稿媒体预览两列 |
| `web/modules/custom/xedc_member/xedc_member.libraries.yml` | version bump |
| `web/themes/custom/xedc/css/dialog-fix.css` | **新增** 关闭按钮 + 弹窗网格 |
| `web/themes/custom/xedc/js/dialog-fix.js` | **新增** aria 中文化 |
| `web/themes/custom/xedc/xedc.libraries.yml` | `dialog_fix` + photo-detail version |
| `web/themes/custom/xedc/xedc.info.yml` | libraries-extend dialog |
| `web/themes/custom/xedc/css/photo-detail.css` | img-sheet 两列 |
| `web/themes/custom/xedc_admin/css/dialog.css` | 关闭文字 + 两列媒体库 |
| `web/modules/custom/xedc_core/tests/manual/image-submission-preview-ui-acceptance.php` | **新增** PHP 验收 |
| `web/modules/custom/xedc_core/tests/manual/browser-image-preview-ui.mjs` | **新增** 浏览器验收 |
| `docs/XEDC_IMAGE_SUBMISSION_PREVIEW_UI_FIX_REPORT.md` | 本报告 |

### 工作区中其他未提交修改（非本轮，已保护未覆盖）

公开资料页 / 通知相关等仍为本地脏文件（如 `PublicProfileBuilder.php`、`public-profile.css`、`xedc_notify/*`、`docs/video-first-card-fix/` 等）。本轮未改这些文件。

---

## 10. 测试结论 PASS / FAIL / NOT TESTED

| # | 项 | 结果 |
|---|----|------|
| A1 | 会员详情不再链到 `/publish` | **PASS**（PHP 渲染 + access） |
| A2 | 会员「保存」→ `/node/{nid}/edit?xedc_import_save=1` | **PASS** |
| A3 | 会员 publish 路由拒绝 | **PASS** |
| A4 | 重复保存不复制节点 | **PASS** |
| A5 | 真实 ZIP→Worker→前台点保存全链路 | **NOT TESTED**（无新 ZIP；用 review 夹具等价验证） |
| B1 | 管理员详情仍有「发布」 | **PASS**（浏览器 + PHP） |
| B2 | 管理员发布路由仍允许 | **PASS** |
| B3 | 员工日志可见 | **PASS** |
| C1 | img-sheet 桌面两列 / 手机一列 | **PASS** |
| C2 | 关闭无英文 Close | **PASS**（img-sheet + dialog CSS 探针） |
| C3 | Escape 关闭 | **PASS** |
| C4 | 深色模式 img-sheet | **PASS** |
| C5 | 后台导入预览两列 + 滚动 | **PASS** |
| D | 上传状态机 / Worker 交接 / R2 / WebP | **NOT TESTED**（本轮未改相关代码） |

PHP 验收：`vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/image-submission-preview-ui-acceptance.php` → **SUMMARY PASS 38/38**

浏览器验收：`SUMMARY PASS 27/27`（1 NOT_TESTED 见上）

`git diff --check`：无空白错误。

---

## 11. Git 状态与未提交修改保护

- 当前分支：`main` @ `61ccc79…`
- **未**执行 `reset` / `checkout --` / `clean` / commit / push
- 本轮仅编辑导入保存与预览弹窗相关文件；其他会员中心/标签/通知/公开资料等脏文件保持原样

---

## 最终核对

- 会员点击「保存」进入自己的投稿编辑与审核流程，**不会**被带到后台发布页。
- 前后台图片预览：桌面两列、可滚动、关闭按钮只显示 X（aria「关闭」），无英文 Close 叠字。
