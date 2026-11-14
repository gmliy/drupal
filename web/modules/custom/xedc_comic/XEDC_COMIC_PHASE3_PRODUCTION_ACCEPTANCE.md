# XEDC Comic 第三阶段 PDF 导入 — 生产验收报告

**日期**：2026-11-14  
**环境**：`/var/www/drupal`（生产机）  
**Drupal 模块**：`xedc_comic`（仅本模块改动 + `apt install poppler-utils`）

---

## 1. 系统依赖

| 项 | 结果 |
|----|------|
| 安装 | `apt install -y poppler-utils`（未装 GS/Imagick/imagick） |
| pdftoppm | **24.02.0** |
| pdfinfo | **24.02.0** |
| www-data 执行 | `pdftoppm -v` / `pdfinfo -v` **PASS**（sudo -u www-data） |
| Drupal `isReady()` | **PASS** |
| 磁盘 `/` | 67% 使用（安装前已检查） |

---

## 2. 本阶段修改文件（FIXED）

| 文件 | 说明 |
|------|------|
| `ComicPdfConverter.php` | **FIXED** `pdftoppm` 参数顺序（PDF 路径在前）；**新增** `estimatePagePixels()` 渲染前像素预估 |
| `ComicPdfImportManager.php` | **FIXED** 转换后不再 `unlink` 已写入的 JPEG；**FIXED** `pdf_converted` 统计；并发含 `pending`；`cancelCleanup()` |
| `ComicImportManager.php` | **FIXED** `resolveSourcePath()`（private:// `realpath`）；取消时 PDF 临时目录清理；重试保留 `success_count` |
| `ComicStorageManager.php` | **FIXED** 接受优化状态 `optimized`（与 ImageOptimizer 生产行为一致） |

未改 Core/Contrib/config/sync/全局 Nginx·PHP·R2。

---

## 3. 数据库

- 已有 `xedc_comic_update_11005`（PDF 字段 + 默认 `pdf.*` 配置）
- 测试期间产生导入任务 ID 7–20（审计日志保留）；**未动既有生产漫画**

---

## 4. 实现审计摘要

| 检查项 | 结果 |
|--------|------|
| MIME + `%PDF-` + 大小 | **PASS** |
| 页数 `pdfinfo` | **PASS** |
| 加密拒绝 | **NOT TESTED**（无可靠加密样例生成工具；逻辑 `Encrypted: yes` 已实现） |
| 损坏 PDF | **PASS** |
| 中文文件名 | **PASS**（导入 job 19） |
| 页序 / `pdf_page` | **PASS**（1…N 连续） |
| Symfony Process 数组调用 | **PASS** |
| 渲染前像素限制 | **FIXED + PASS**（预估 612×792 @150DPI < 25M） |
| `max_concurrent` | **FIXED + PASS**（含 pending/queued/processing） |
| 临时目录清理 | **PASS**（完成后 `afterJobComplete`） |
| 同章重复 PDF 任务 | **PASS**（SHA-256 + 章节） |
| 异章同内容 PDF | **PASS**（job 20，10 页 completed） |

---

## 5. 资源限制验证

| 配置 | 实测 |
|------|------|
| 100MB 上限 | **PASS**（`too-big.pdf` 拒绝） |
| 500 页上限 | **PASS**（501 页拒绝） |
| DPI 白名单 | 代码白名单（测试用 150） |
| convert_batch=2 | 队列分批 **PASS** |
| 单页超时 120s | 配置存在；50 页 job 总时长 ~200s **PASS** |
| 内存 | 逐页转换，未一次性载入全部页 **PASS** |

---

## 6. 真实 PDF 导入（测试漫画 `[PDF-ACCEPT]` nid=1021，**已清理**）

| 用例 | Job | 状态 | 页数 | ComicPage | CDN 抽样 |
|------|-----|------|------|-----------|----------|
| 1 页 | 16 | completed | 1 | 1 | HTTP **200** |
| 10 页 | 17 | completed | 10 | 10 | 10/10 URL **200** |
| 50 页 | 18 | completed | 50 | 50 | p1 HTTP **200** |
| 中文文件名 PDF | 19 | completed | 10 | 10 | HTTP **200** |
| 异章同 PDF | 20 | completed | 10 | 10 | **PASS** |

**链路**：PDF → pdftoppm → JPG → ImagePipeline → ImageOptimizer（`optimized`→webp）→ `public://comics/1021/{chapter}/` → Storage Hub → `cdn.gitxx.xyz` → ComicPage → `field_comic_page_count` 同步 **PASS**

**xedc_image_file.optimization_status**：`success` **PASS**

---

## 7. 取消 / 重试 / 去重

| 测试 | 结果 |
|------|------|
| 任务取消（job 创建后立即 cancel） | **PASS**（`cancelled`） |
| 转换中取消 / 部分成功后取消 | **NOT TESTED**（未做 UI 中途取消） |
| 失败重试不重复成功页 | **NOT TESTED**（本次无失败页可重试；逻辑仅重置 `failed`） |
| 同章重复导入 | **PASS** |
| 异章同 PDF | **PASS** |

---

## 8. R2 / CDN

- URI 示例：`public://comics/1021/1027/p0001-312be858.webp`
- CDN：`https://cdn.gitxx.xyz/public/comics/...` **HTTP 200**（10 页逐页验证）

---

## 9. 阅读器

- HTTP 公网路由探测 **400**（主机名限制，非功能缺陷）
- 通过 `ComicPageRepository` + `storage_delivery` 10 页全部 **200** **PASS**
- 浏览器阅读器 UI **NOT TESTED**（未用手机/桌面浏览器点读）

---

## 10. 后台 UI / 手机

| 项 | 结果 |
|----|------|
| Poppler 环境 JSON / 未就绪禁用 | 依赖就绪后 **PASS**（`isReady`） |
| ZIP/多图/单图 入口 | 未回归 **NOT TESTED**（本次未破坏相关代码） |
| 手机布局 | **NOT TESTED** |

---

## 11. 测试数据清理

- 已删除漫画 **1021** 及下属 `[PDF-ACCEPT*]` / `[PDF-NEG*]` 章节（含 ComicPage 与关联文件，走实体删除）
- `/tmp/xedc-pdf-test/` 生成 PDF **保留**（非站点数据，可手工 `rm -rf`）
- 未删除既有生产漫画 **1018** 等

---

## 12. 遗留问题 / 风险

1. **加密 PDF**：实现存在，**NOT TESTED**（未安装 qpdf/pdftk，未构造加密样例）。
2. **取消运行中进程**：状态置 `cancelled` + 清临时目录；**未验证**强制终止进行中的 `pdftoppm` 子进程（下一批检查取消位停止）。
3. **重试**：**NOT TESTED** 端到端。
4. **ImageOptimizer `optimized` 状态**：已在 `ComicStorageManager` 放行；若优化器新增其他成功状态需同步。
5. **生产漫画 1021 测试残留**：已删除；CDN 上已上传对象可能仍缓存（R2 生命周期由 Storage Hub 管理，**未做对象删除审计**）。

---

## 13. 总状态表

| 类别 | 状态 |
|------|------|
| Poppler 安装 | **PASS** |
| 1/10/50 页导入 | **PASS** |
| 中文文件名 | **PASS** |
| 超限/损坏拒绝 | **PASS** |
| R2/CDN | **PASS** |
| 加密 PDF | **NOT TESTED** |
| 取消 | **PASS**（基础） |
| 重试 / 转换中取消 | **NOT TESTED** |
| 阅读器浏览器 | **NOT TESTED** |
| 手机后台 | **NOT TESTED** |

---

## 14. 结论

**PDF 导入可投入生产**：在已安装 **poppler-utils** 的本机上，1/10/50 页与中文文件名、R2/CDN、ComicPage、页序与章节页数字段均已 **真实跑通**。

**未执行**：加密样例、浏览器/手机 UI、转换中途取消、失败重试实机、ZIP/多图回归。

**人工建议**：观察首批运营 PDF 体积与页数；若需加密 PDF 支持需另备样例与策略；定期 `apt` 安全更新 poppler。