# XEDC Comic 第三阶段交付报告（PDF 导入）

**日期**：2026-11-14  
**环境**：`/var/www/drupal`

## 1. PDF 转换工具及版本（实测）

| 工具 | 状态 |
|------|------|
| `pdftoppm` (Poppler) | **未安装** |
| `pdftocairo` | **未安装** |
| `pdfinfo` (Poppler) | **未安装** |
| `gs` (Ghostscript) | **未安装** |
| `convert` / `magick` (ImageMagick) | **未安装** |
| PHP `imagick` 扩展 | **未加载** |

`ComicPdfConverter::isReady()`：**false**（需要 `pdftoppm` + `pdfinfo`）

### 依赖安装方案（未经确认前未在生产执行）

在 Ubuntu/Debian 上推荐（需运维确认维护窗口）：

```bash
sudo apt update && sudo apt install -y poppler-utils
```

可选（与 Poppler 二选一或互补，本次代码主路径为 Poppler）：

```bash
sudo apt install -y ghostscript imagemagick
```

安装后验证（单行）：

```bash
pdftoppm -v && pdfinfo -v
```

然后：`cd /var/www/drupal && ./vendor/bin/drush cr`，在 `/admin/xedc/comic/import` 打开「PDF 转漫画页」标签，确认「转换环境」JSON 中 `pdftoppm`/`pdfinfo` 非 null。

## 2. 新增 / 修改文件

| 文件 | 说明 |
|------|------|
| `src/Service/ComicPdfConverter.php` | **新增** 校验、页数、逐页 `pdftoppm`（Process 参数数组） |
| `src/Service/ComicPdfImportManager.php` | **新增** 任务创建、`pdf_prepare` / `pdf_convert` 阶段 |
| `src/Form/ComicImportHubForm.php` | PDF 标签页、DPI/格式/质量、环境检测 |
| `src/Service/ComicImportManager.php` | PDF 进度、重试、完成清理钩子 |
| `src/Plugin/QueueWorker/ComicImportWorker.php` | 阶段 `pdf_prepare`、`pdf_convert` |
| `src/Controller/ComicAdminController.php` | PDF 任务详情、中文状态 |
| `src/Form/ComicSettingsForm.php` | PDF 限额配置 |
| `xedc_comic.services.yml` | `xedc_comic.pdf_converter`、`xedc_comic.pdf_import` |
| `xedc_comic.install` | 字段 + `xedc_comic_update_11005` |
| `config/install/xedc_comic.settings.yml` | `pdf.*` 默认 |
| `config/schema/xedc_comic.schema.yml` | `pdf` 配置 schema |
| `css/admin.css` | PDF 环境展示样式 |

## 3. 新增服务 / 队列

- `xedc_comic.pdf_converter`
- `xedc_comic.pdf_import`
- 队列仍为 `xedc_comic_import`，新增 phase：
  - `pdf_prepare`：写入 `convert_pending` 页项
  - `pdf_convert`：逐批渲染 JPG 到 `private://.../pdf/{id}/`
  - `process`：复用既有 `ImagePipeline` → `ComicStorageManager`

## 4. 数据表变更

**`xedc_comic_import`**：`archive_bytes`、`pdf_dpi`、`pdf_format`、`pdf_quality`、`pdf_page_count`、`pdf_converted`、`pdf_keep_source`

**`xedc_comic_import_item`**：`pdf_page`；`status` 支持 `convert_pending`

## 5. PDF 导入流程

```
上传 PDF（private://xedc-comic-import/pdf）
→ validatePdfFile + assertNotEncrypted + countPages
→ 创建任务 import_mode=pdf
→ pdf_prepare：每页一条 item（convert_pending）
→ pdf_convert：pdftoppm 逐页 → temp_uri → pending
→ runProcessBatch：createPageFromPath（ImagePipeline/Optimizer/Storage Hub）
→ 更新章节页数；可选删除源 PDF；清理临时渲染目录
```

## 6. 安全与资源限制

| 限制 | 实现 |
|------|------|
| MIME + `%PDF-` 头 | `validatePdfFile` |
| 大小 | `pdf.max_bytes`（默认 100MB） |
| 最大页数 | `pdf.max_pages`（500） |
| 单页像素 | `pdf.max_page_pixels` |
| DPI 白名单 | 96 / 150 / 200 |
| 格式白名单 | jpg / webp（渲染 JPG，优化器出最终格式） |
| 质量 | 50–95 |
| 加密 PDF | `pdfinfo` 检测 `Encrypted: yes` 拒绝 |
| 并发 | `pdf.max_concurrent`（默认 2） |
| 批大小 | `pdf.convert_batch`（默认 2 页/次） |
| 单页超时 | `pdf.page_timeout`（默认 120s） |
| 进程调用 | Symfony `Process` 参数数组，无 shell 拼接 |
| 源文件 | 默认 `private://`，进度页不公开；完成后可配置不保留 |

## 7. R2 实际上传

**BLOCKED** — 无 Poppler，无法生成真实漫画页；既有阶段 2 路径（`createPageFromPath`）未变，依赖安装后复测。

## 8. ComicPage / 页码

**NOT TESTED**（转换阶段阻塞）。

设计：PDF 第 *n* 页 → `weight = maxPage + n`，文件名 `p000{n}.jpg`。

## 9. 测试结果

| # | 测试项 | 结果 |
|---|--------|------|
| 1–3 | 1/10/多页 PDF 导入 | **BLOCKED** |
| 4 | 中文文件名 PDF | **NOT TESTED** |
| 5 | 大文件限制 | **PASS**（`validatePdfFile` 超限抛错，`drush` 可测） |
| 6 | 加密 PDF | **NOT TESTED**（需 `pdfinfo`） |
| 7 | 损坏/非 PDF | **PASS**（`bad_pdf: 文件内容不是有效的 PDF`） |
| 8–12 | 顺序/质量/优化/R2/页数 | **BLOCKED** |
| 13 | 取消 | **NOT TESTED**（UI+逻辑沿用，PDF `pending` 已纳入取消） |
| 14 | 失败重试 | **NOT TESTED**（失败项可回到 `convert_pending`） |
| 15 | 重复 PDF | **NOT TESTED**（`archive_sha256` 同章去重已实现） |
| 16 | 手机后台 | **NOT TESTED** |

## 10. 未完成 / 阻塞

- **生产部署 PDF 转换功能**：阻塞于 **poppler-utils 未安装**（未擅自 `apt install`）。
- 代码已部署至模块；**UI 中 PDF 标签在未就绪时禁用提交**，并展示环境 JSON。
- 安装 Poppler 后需完整回归 §十一 全部用例并更新本报告状态。

## 11. 总评

| 项 | 状态 |
|----|------|
| PDF UI / 任务模型 / 队列 / 安全限额 | **PASS**（代码 + DB） |
| 实际 PDF→图→R2 | **BLOCKED** |
| 生产启用 | **等待运维安装 poppler-utils 后复测** |