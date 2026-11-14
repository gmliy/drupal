# XEDC Comic — PDF 章节导入说明

本文说明 **PDF 转漫画页** 功能用法、依赖、配置及模块内相关文档索引。  
模块路径：`web/modules/custom/xedc_comic`  
后台入口：`/admin/xedc/comic/import` → 标签 **「PDF 转漫画页」**。

---

## 1. 功能概述

管理员为指定 **漫画 + 章节** 上传 **private** 区 PDF，系统：

1. 校验 PDF（类型、大小、页数、是否加密）
2. 队列 **逐页** 调用 Poppler `pdftoppm` 转为 JPG
3. 每页经既有链路：**ImagePipeline → ImageOptimizer → Storage Hub → R2**
4. 创建 **ComicPage**，更新章节页数

PDF **不会** 作为阅读器中的页面类型，仅作导入源；成品为优化后的漫画页图片。

与 ZIP / 多图 / 单图 **共用** 导入任务表与进度页：`/admin/xedc/comic/import/status`。

---

## 2. 服务器依赖（必装）

| 组件 | 用途 |
|------|------|
| `poppler-utils` | 提供 `pdftoppm`、`pdfinfo` |

**不要** 为漫画 PDF 功能单独安装 Ghostscript、ImageMagick、PHP imagick（当前实现不依赖）。

安装与验证（运维）：

```bash
sudo apt update && sudo apt install -y poppler-utils
pdftoppm -v && pdfinfo -v
sudo -u www-data pdftoppm -v
```

Drupal 侧（导入页「转换环境」或 Drush）：

```bash
cd /var/www/drupal && ./vendor/bin/drush ev 'var_export(Drupal::service("xedc_comic.pdf_converter")->environmentReport());'
```

当 `pdftoppm` 与 `pdfinfo` 均可用时，表单 PDF 标签 **可提交**；否则提交按钮禁用。

---

## 3. 操作流程

1. 选择漫画、章节（或新建章节）
2. 上传 PDF（仅 `private://xedc-comic-import/pdf/`）
3. 选择 DPI（96 / 150 / 200）、输出格式（JPG / WebP 经优化器）、质量 50–95
4. 是否 **保留原始 PDF**（默认建议保留，便于失败重试）
5. 提交后跳转 **导入进度**，可 **取消** / **重试失败项**

任务阶段（队列 `xedc_comic_import`）：

`pdf_prepare` → `pdf_convert`（分批）→ `process`（与 ZIP 相同的图片入库批处理）

---

## 4. 配置项

位置：`/admin/xedc/comic/settings` → **PDF 导入**（或 `xedc_comic.settings` 的 `pdf.*`）。

| 键 | 默认 | 含义 |
|----|------|------|
| `pdf.max_bytes` | 104857600 | PDF 最大 100MB |
| `pdf.max_pages` | 500 | 最大页数 |
| `pdf.max_page_pixels` | 25000000 | 单页像素上限（转换前预估 + 转换后校验） |
| `pdf.max_concurrent` | 2 | 同时进行的 PDF 任务数 |
| `pdf.convert_batch` | 2 | 每队列批转换页数 |
| `pdf.page_timeout` | 120 | 单页 `pdftoppm` 超时（秒） |
| `pdf.keep_source_default` | 1 | 默认勾选保留源 PDF |

图片批大小仍用 `import.batch_size`（入库阶段）。

---

## 5. 安全与限制摘要

- MIME + 文件头 `%PDF-` 双重校验
- `pdfinfo` 检测 **Encrypted: yes** → 拒绝
- 进程调用使用 **Symfony Process 参数数组**（无 shell 拼接）
- 临时渲染目录：`private://xedc-comic-import/pdf/{task_id}/`，任务结束后清理
- 成品路径：`public://comics/{comic_id}/{chapter_id}/`（经 Storage Hub 至 R2）
- **同章 + 相同 PDF SHA-256** 且任务未失败时，禁止重复创建（防重复导入）

---

## 6. 核心服务与代码

| 服务 ID | 类 | 职责 |
|---------|-----|------|
| `xedc_comic.pdf_converter` | `ComicPdfConverter` | 环境检测、校验、页数、逐页渲染 |
| `xedc_comic.pdf_import` | `ComicPdfImportManager` | 创建任务、`pdf_prepare` / `pdf_convert` |
| `xedc_comic.import` | `ComicImportManager` | 通用任务、取消、重试、`process` 批处理 |
| `xedc_comic.storage` | `ComicStorageManager` | ImagePipeline + Optimizer + ComicPage |

数据库扩展见 `xedc_comic_update_11005`（`import_mode=pdf`、`pdf_*` 字段、`pdf_page` 等）。

---

## 7. 常见问题

| 现象 | 处理 |
|------|------|
| PDF 标签灰色不可提交 | 安装 `poppler-utils`，`drush cr` |
| 「图片优化失败」 | 已支持优化器状态 `optimized`；仍失败则查 `xedc_image_file` / 日志 |
| 「临时图片不存在」 | 确认 private 路径可写；勿手工删 `private/xedc-comic-import/pdf/{id}/` |
| 同章不能再次导入同一 PDF | 设计行为；换章或等旧任务非 completed 策略外需新文件 |
| 进度卡在 processing | `drush queue:run xedc_comic_import` 或等待 cron |

缓存重建：

```bash
cd /var/www/drupal && ./vendor/bin/drush cr
```

---

## 8. 模块内 Markdown 文档索引

| 文件 | 说明 |
|------|------|
| **XEDC_COMIC_PDF_IMPORT.md** | 本文：PDF 功能操作与配置说明 |
| **XEDC_COMIC_PHASE3_PRODUCTION_ACCEPTANCE.md** | 第三阶段 PDF **生产验收**（依赖安装、实测 PASS/FAIL、清理记录） |
| **XEDC_COMIC_PHASE3_REPORT.md** | 第三阶段初版交付（依赖未装时的 BLOCKED 状态） |
| **XEDC_COMIC_PHASE2_REPORT.md** | 第二阶段：导入中心、多图、图片管理、统计修复 |
| **XEDC_COMIC_REPORT.md** | 模块首期总报告 |
| **XEDC_COMIC_ADMIN_UPLOAD_AUDIT.md** | 后台上传能力审计（PDF 开发前） |
| **XEDC_COMIC_CONTENT_MODEL_ACCEPTANCE.md** | 内容模型（comic / chapter / page）验收 |

**以生产为准**：PDF 是否可上线请看 **XEDC_COMIC_PHASE3_PRODUCTION_ACCEPTANCE.md** 结论章节。

---

## 9. 生产结论（摘要）

- **Poppler 已安装** 且 **1 / 10 / 50 页** 与 **中文文件名** 导入、**R2 CDN HTTP 200** 已在生产机真实验证（详见验收报告）。
- **加密 PDF**、**浏览器/手机 UI**、**转换中途取消与失败重试** 等项在验收报告中标注为 **NOT TESTED**，上线后建议补测。

---

*文档版本：2026-11-14，与 xedc_comic 第三阶段 PDF 导入同步。*