# XEDC 会员中心图片投稿与压缩包投稿功能分离报告

日期：2026-11-05  
分支：`main`  
HEAD：`61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96`  
未 commit / 未 push

---

## 1. 业务分流（最终）

会员中心投稿入口：

| 入口 | URL | 行为 |
|------|-----|------|
| **图片投稿** | `/node/add/photo` | 媒体库 + **多图批量上传**（`multiple`）。一张→普通图片逻辑；多张→同一图片集 |
| **压缩包投稿** | `/node/add/photo?upload=archive` | 仅 ZIP / 7z；Worker 解压/优化后预览保存 |
| ~~图片集投稿~~ | 已移除导航 | `?upload=gallery` 仍兼容，**折叠为图片投稿模式**，不删多图能力 |

视频 / 文章入口保留。

---

## 2. 实际修改摘要

### 导航与文案

- `MemberController::submissionCreateLinks()`：删除 `gallery` 卡片；图片投稿文案改为「单张或多张…组成同一个图片集」
- 投稿须知改为区分图片 vs 压缩包

### 会员上传面板（`xedc_core_attach_member_photo_upload`）

- 新增 `xedc_core_member_photo_upload_mode()`：`archive|zip` → archive，其余（含 legacy `gallery|images`）→ image
- **图片模式**：只渲染 `data-xedc-images`（multiple），`data-kinds="image"`
- **压缩包模式**：只渲染 `data-xedc-archive`，`data-kinds="archive"`
- 后台员工上传中心仍为双区（图片+压缩包），未改业务

### 任务队列隔离（`asset-upload.js`）

- 按 `data-kinds` / settings.kinds 过滤任务列表与统计
- 跨类型文件拒绝并提示（图片页不收 ZIP）
- `entry` 使用 `member`（不再误标 `admin`）

### 模板

- `xedc-member-node-form.html.twig`：按 `xm_upload_mode` 切换提示；压缩包页图片字段收入「高级」折叠，避免与 ZIP 主流程抢视线
- 预处理：标题「图片投稿」/「压缩包投稿」

### 样式

- `asset-upload.css`：会员单区布局；隐藏错位 drop（双保险）
- `member.css`：高级折叠样式

---

## 3. 图片集关联

- **未删除** photo 类型、`field_images`、详情页、审核与存储
- 多图仍走既有 `kind=image` 上传 + 节点 `field_images` / 导入关联
- 压缩包仍走 `kind=archive` + Worker → 同一 photo 节点图片集

---

## 4. 上传恢复兼容性

- 仍用 IndexedDB `xedc-upload-v2`、分片、BroadcastChannel、取消交接
- 页面只**展示**本模式 kinds 的任务，避免图片页出现 ZIP 任务卡/「文件已复用」等压缩包态
- 底层队列未拆库；同浏览器里另一类任务仍可在对应页面恢复

---

## 5. 安全

- 未放宽管理员发布权限
- 会员保存仍走节点编辑 + `xedc_editorial`
- 服务端 UploadSession 仍按 kind 校验类型/大小；本轮未改权限矩阵

---

## 6. 测试结果

### PHP：`member-image-submission-types-acceptance.php`

**SUMMARY PASS 25/25**

含：无 gallery 入口、模式折叠、图片表单无 ZIP、压缩包表单无多图 input、kinds 设置、hub keys。

### 浏览器：`browser-member-submission-ui.mjs`

| 项 | 结果 |
|----|------|
| Hub 4 卡、无「图片集投稿」 | PASS |
| 有图片投稿 + 压缩包投稿 | PASS |
| 图片页 multi input、无 ZIP | PASS |
| 压缩包页有 ZIP、无 batch image | PASS |
| 标题「压缩包投稿」 | PASS |
| 手机 Hub / 深色 | PASS |

### 验收清单（用户第 7 节）

| # | 项 | 结果 |
|---|----|------|
| 1 | 单图上传保存 | **NOT TESTED**（无端到端文件上传；UI/表单具备） |
| 2 | 多图批量上传 | **NOT TESTED**（控件 `multiple` 已验） |
| 3 | 多图形成一个图片集 | **NOT TESTED**（沿用既有 image→节点逻辑，未改 Worker） |
| 4 | 图片顺序 | **NOT TESTED** |
| 5 | 移除单张 | **NOT TESTED** |
| 6 | 失败重试 | **NOT TESTED**（管理器未改） |
| 7 | 刷新恢复多图任务 | **NOT TESTED**（kinds 过滤已验） |
| 8 | 图片页无 ZIP 入口 | **PASS** |
| 9 | 图片页无压缩包任务卡串显 | **PASS**（kinds 过滤；无实 ZIP 任务夹具） |
| 10 | 压缩包页 ZIP/7Z | **PASS**（控件）；实传 **NOT TESTED** |
| 11 | Worker 解压 | **NOT TESTED**（未改 Worker） |
| 12 | 压缩包预览保存 | **NOT TESTED**（既有保存流保留） |
| 13 | 不能操作他人任务 | **NOT TESTED**（本轮未改 TaskAccess） |
| 14 | 不能直接发布 | **NOT TESTED**（既有隔离保留） |
| 15 | 无独立图片集入口 | **PASS** |
| 16 | 后台导入/快发/展示 | **NOT TESTED**（员工双区模板未删） |
| 17 | 手机/桌面布局 | **PASS**（Hub 截图） |

---

## 7. Git 修改清单（本轮相关）

| 文件 | 说明 |
|------|------|
| `web/modules/custom/xedc_core/xedc_core.module` | 模式分流 + 会员上传模板拆分 |
| `web/modules/custom/xedc_core/js/asset-upload.js` | kinds 过滤 / 跨类型拦截 |
| `web/modules/custom/xedc_core/css/asset-upload.css` | 会员单区样式 |
| `web/modules/custom/xedc_core/xedc_core.libraries.yml` | version bump |
| `web/modules/custom/xedc_member/src/Controller/MemberController.php` | 入口与须知 |
| `web/modules/custom/xedc_member/xedc_member.module` | 标题/提示预处理 |
| `web/modules/custom/xedc_member/templates/xedc-member-node-form.html.twig` | 图片 vs 压缩包区块 |
| `web/modules/custom/xedc_member/css/member.css` | 高级折叠 |
| `web/modules/custom/xedc_member/xedc_member.libraries.yml` | version |
| `web/modules/custom/xedc_member/tests/manual/member-image-submission-types-acceptance.php` | 新增 |
| `web/modules/custom/xedc_member/tests/manual/browser-member-submission-ui.mjs` | 断言更新 |
| `docs/XEDC_MEMBER_IMAGE_SUBMISSION_TYPES_REPORT.md` | 本报告 |

未触碰 Drupal core、第三方模块、`config/sync`、Worker 服务与上传状态机核心。  
工作区其他未提交改动（公开资料、视频 UI、预览弹窗等）已保留。

---

## 8. 结论

会员中心仅保留「图片投稿」与「压缩包投稿」。图片投稿继续支持默认多图图片集上传，且页面上不再出现 ZIP/7Z 控件与压缩包任务统计；压缩包投稿独立入口。独立「图片集投稿」导航已取消，图片集实体与多图能力保留。
