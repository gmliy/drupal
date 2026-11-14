# 章节导入故障诊断报告

**日期**：2026-11-14 · **环境**：生产库只读审计 + CLI 抽检

## 审计结论摘要

| # | 检查项 | 结果 | 根因 |
|---|--------|------|------|
| 1 | ZIP 表单上传 | **PARTIAL** | `ComicImportHubForm` 有 `managed_file`；任务创建后依赖 **Queue/cron**，后台无即时处理 |
| 2 | PDF 上传 | **PASS**（历史任务） | `job 19/20` completed；Poppler 环境需运行时 `environmentReport` |
| 3 | 任务 comic/chapter ID | **PASS** | `xedc_comic_import.comic_nid/chapter_nid` 有值 |
| 4 | Queue Worker | **PASS**（代码） | `ComicImportWorker` 分 phase；**未在提交后自动跑队列** |
| 5 | ComicPage 创建链路 | **PASS**（代码） | `ComicStorageManager::createPageFromPath` → ImagePipeline/Optimizer/Hub |
| 6 | 页数同步 | **PASS**（代码） | `syncChapterPageCount` 在 job 完成时调用 |
| 7 | 导入成功 vs 当前页实体 | **FAIL** | `job 19/20` item `page_id` 66–80，**当前 `comic_page` 仅 id 1、3**（历史页已被删或库回滚，任务未清） |
| 8 | 章节→导入工作流 | **FAIL** | 无 `/chapters/{id}/import`；节点创建后回章节列表 |
| 9 | 章节上下文丢失 | **FAIL** | 全局导入中心须每次选手动漫画/章节 |
| 10 | `processing` 悬挂 | **RISK** | Worker 崩溃无超时回收 |

## 代码断点

1. **用户体验断点**：`createJob()` 仅 `queue->createItem()`；生产若 cron 稀疏或未 `queue:run`，任务长期 **queued**，表现为「上传没反应」。  
2. **工作流断点**：`ComicChapterBatchForm` / 节点 `comic_chapter` 创建后无导入工作台跳转。  
3. **数据一致性**：`xedc_comic_import_item.page_id` 不随 ComicPage 删除失效（审计信息，不删生产数据）。

## 修复方向（第八阶段）

1. 新增 `ComicImportRunner`：提交后在许可时间内同步推进队列（管理员请求内）。  
2. 路由 `xedc_comic.chapter_import` + `ComicChapterImportForm`（章节实体参数、自动绑 comic/chapter）。  
3. `hook_form` 新章节保存后跳转导入工作台。  
4. 章节列表/图片管理增加「导入内容」链。  
5. 陈旧 `processing` 任务回收（`update hook` 或 cron 旁路）。  
6. E2E：独立测试漫画/章节 + 清理。