# XEDC 上传取消、前台面板与 ALT SEO 统一修复

日期：2026-11-04。站点 https://gitxx.xyz。Drupal 11.4.11。

没有 commit，没有 push。没有改 Drupal Core、第三方模块、`config/sync`、Nginx、PHP-FPM、R2/CDN。没有新建 Worker，没有提高并发，没有放宽体积限制。未使用 `git reset --hard` / `checkout --` / `clean -fd`。

状态只用：PASS、FAIL、NOT TESTED、FIXED。

## 1. 问题根因

### A. 上传任务无法取消

1. `enqueue()` 在标题校验前就写入 IndexedDB，并进入 `pump()`。
2. 无标题时 `ensureDraft()` 抛错，任务停在 `failed` / `interrupted`，且**没有** `sessionId`。
3. 上传中心与全局面板的「取消」按钮原先要求 `job.sessionId`，无会话任务看不到取消。
4. `cancel()` 即使成功也只把状态写成 `cancelled` 并继续持久化，刷新后仍可出现；未中止进行中的 XHR，也没有墓碑阻止恢复。

### B. 前台透明「上传任务」按钮

1. `xedc_core_page_attachments()` 用过宽条件把面板挂到前台：`str_starts_with($route, 'entity.')` / `view.` / `xedc_`。
2. 登录会员角色 `authenticated` 拥有 `import photo archives`，因此前台也会加载 `upload_manager` + `upload_panel`。
3. 前台没有 `.xedc-admin-header`，面板被挂到 `document.body`，样式依赖后台变量，表现为透明/异常悬浮块；无任务时仍创建入口。

### C. ALT 规则

旧 `AltManager::format()` 生成 `标题_第N张图`（带序号）。快速发布、ZIP/7z、导入流水线都走该服务或同类拼接，与「模特名字 + 作品标题」不一致。

## 2. 修改文件

| 文件 | 目的 |
| --- | --- |
| `web/modules/custom/xedc_core/js/upload-manager.js` | `needs_title`、完整取消、XHR abort、墓碑、`applyTitle` |
| `web/modules/custom/xedc_core/js/upload-panel.js` | 仅挂后台 header；无任务隐藏；可取消无会话任务 |
| `web/modules/custom/xedc_core/js/asset-upload.js` | 标题校验 UX、取消按钮、标题填写后继续 |
| `web/modules/custom/xedc_core/css/upload-panel.css` | `[hidden]` 强制不占点击区域 |
| `web/modules/custom/xedc_core/xedc_core.module` | 面板仅 admin 路径；封面 ALT 用新规则 |
| `web/modules/custom/xedc_core/src/AltManager.php` | 统一 `compose` / `forNode`；人工 ALT 保护 |
| `web/modules/custom/xedc_core/src/ImagePipeline.php` | 导入媒体 ALT 改为 `forNode()` |
| `web/modules/custom/xedc_quick/src/QuickIntake.php` | 上传时用标题；创建后 refresh |
| `web/modules/custom/xedc_quick/src/Controller/QuickController.php` | 创建节点后 `alt->refresh`；封面 ALT |
| `tests/manual/image-phase2-self-test.php` 等 | 断言改为新 ALT 规则 |

## 3. 上传取消

- 无标题且需要草稿时：本地任务进入 `needs_title`，**不**创建服务端会话，不进入 `pump`。
- 填写标题后：`applyTitle()` 把 `needs_title` 改为 `pending` 并续传；文件无需重选。
- `cancel()`：加入墓碑 → abort XHR → 调服务端 `/cancel`（有会话时）→ 删除 IndexedDB → BroadcastChannel 通知其他标签 → 从内存移除。
- 已交给 Worker（有 `taskId` 且进入排队/处理）的任务**不允许**用上传取消误删导入任务。
- 服务端取消失败：本地仍停止上传，错误提示保留，不假装成功清理服务端。
- 刷新后墓碑 + 删除持久化，已取消任务不会恢复。

## 4. 全局面板

- 仅当 `router.admin_context` 为 admin 或路径以 `/admin` 开头时加载。
- 面板只插入 `.xedc-admin-header`；没有后台壳则不创建 DOM。
- 无活动/完成计数时 `hidden`，不留透明可点区域。
- 快速发布仍在 `/admin/quick-gallery`，继续可用管理器；普通前台（含会员）不加载。
- 任务接口仍按用户权限与会话归属隔离（既有服务端校验）。

## 5. ALT SEO

最终规则：**ALT = 模特名字 + 作品标题**（直接拼接，无序号、无堆砌描述）。

| 模特 | 标题 | ALT |
| --- | --- | --- |
| 小雪 | 夏日海边写真 | 小雪夏日海边写真 |
| 莉莉 | 夏日海边写真 | 莉莉夏日海边写真（实测 `forNode`） |
| 有 | 空 | 仅模特名 |
| 空 | 有 | 仅作品标题 |
| 空 | 空 | 空字符串，不编造 |

- 模特来源：`node.field_model`（词汇 `models`），按字段顺序去重后拼接。
- 标题来源：内容标题，不是文件名/压缩包名。
- 多模特：名字依次拼接。
- 长度：生成 ALT 软上限 60 字；优先保留模特名，再截断标题部分；不改原标题字段。
- 人工 ALT：`xedc_image_alt.source=manual` 永不被 `refresh` / 导入覆盖；无来源且已有非空 ALT 视为人工。
- 接入：后台导入 `ImagePipeline`、ZIP/7z（同流水线）、快速发布（上传用标题，创建节点后 `refresh`）、封面 `xedc_core_cover_alt`。
- 历史图片：本次**未**批量改写已有 ALT。

## 6. 测试结果

| 项 | 结果 |
| --- | --- |
| PHP 语法 | PASS |
| JS 语法（manager/panel/asset-upload） | PASS |
| `drush cr` | PASS |
| Schema 更新 | 未新增（无需） |
| ALT compose 用例 | PASS |
| `forNode`（模特莉莉 + 标题） | PASS → `莉莉夏日海边写真` |
| 去重自测 12 | PASS |
| 断点续传自测 23 | PASS |
| 图片二阶段自测 19（含新 ALT、人工 ALT） | PASS |
| 浏览器：前台首页/详情无面板（管理员会话） | PASS |
| 浏览器：会员 uid=2 首页/详情无面板 | PASS |
| 浏览器：无标题 → `needs_title` 可取消、IDB 清除、刷新不回魂 | PASS |
| 浏览器：有标题可上传 | PASS |
| 浏览器：上传中取消（有 session，服务端 `cancelled`） | PASS |
| 浏览器：ZIP 无标题可取消 | PASS（取消成功；阶段断言写法有瑕疵，行为通过） |
| 浏览器：后台面板在 header 且有文字「上传」 | PASS |
| 移动端 / 深色模式面板 | NOT TESTED（CSS 保留深色规则） |
| Safari / iPhone | NOT TESTED |
| 会员投稿前台专用页 | NOT TESTED（当前投稿入口为 `/admin/quick-gallery`） |
| 人工改标题后 refresh 不覆盖 manual | PASS（二阶段自测） |
| 操作系统文件框 | NOT TESTED |

## 7. 最终状态问答

1. 未填标题就选文件，是否还会出现无法取消的任务？**否**（FIXED）。进入 `needs_title`，可取消。
2. 取消后刷新是否重现？**否**（PASS）。
3. 前台普通页是否去掉异常透明按钮？**是**（PASS，含会员）。
4. 会员投稿页是否正常？快速发布在后台路由，管理器仍可用；纯前台会员中心无面板（PASS）。专用前台投稿页 **NOT TESTED**（若存在需再验）。
5. 后台上传面板是否正常？**是**（PASS）。
6. ZIP/7z 与 Worker？未改 Worker；续传自测 PASS；取消不误删已交 Worker 任务。
7. 各入口是否同一 ALT 规则？**是**（统一 `AltManager`）。
8. 人工 ALT 是否永久保留？**是**（manual 标记 + 自测 PASS）。

## Git

- 分支：`main`
- HEAD：`93da5e3df281a1737ae4a8e805b7eec419b85582`（`备份 2026-11-05 03:00 | 配置93项 内容63项`，已包含本修复代码）
- 本报告文件：未跟踪（`docs/XEDC_UPLOAD_CANCEL_FRONTEND_PANEL_AND_ALT_SEO_FIX_REPORT.md`）；未单独 commit / 未 push。
- 工作区其余文件：干净。

## 残余风险

- 极短文件可能在取消前已 `complete` 并获得 `taskId`，此时只能走导入任务流程，不能用上传取消。
- 历史 ALT 仍为旧「_第N张图」格式，未批量迁移。
- 深色/窄屏面板仅代码层保留，未做真机验收。
