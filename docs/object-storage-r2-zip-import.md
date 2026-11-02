# ZIP 批量导入

日期：2026-11-02。

## 状态

| 项 | 结论 |
| --- | --- |
| 临时目录里创建 ZIP、拒绝 `../` 条目、解压 PNG、上传到隔离前缀、保留原 ZIP | 真实 R2 测试通过 |
| ZipArchive 只收到本地路径 | 已完成。远程压缩包先由 `LocalFileBridge` 落到 `/tmp/xedc-storage` |
| 生产导入创建节点、文件实体和页面图片 | 尚未完成。本次测试没有写 `file_managed`，没有建内容 |
| 损坏包、压缩炸弹、超大包、中断后续跑的完整自动化 | 尚未完成。生产导入已有任务表和重试字段，这次没有用坏包打生产导入器 |
| 浏览器上传 ZIP | 浏览器实际验证未做 |

## 隔离测试

流水线在 `/tmp/xedc-storage` 生成 PNG，打成 `album/one.png`，只解压不含 `../` 和绝对路径的条目，再把 PNG 上传到 `_xedc_integration_test/{run}/zip/one.png`。另一个 ZIP 含 `../escape.png`，被识别为路径穿越，没有解压到目标目录。源 ZIP 在上传后仍然存在，随后随临时目录清理删除。这个 ZIP 不是用户的生产压缩包。

解压目录不在 Web Root。Web Root 是 `/var/www/drupal/web`。集成测试用 `/tmp/xedc-storage`。生产 `ArchiveManager` 的工作目录仍是 `private://`，对应 `/var/www/drupal/private`，也在 Web Root 外。

## 2026-11-02 解压器

`ArchiveManager::openAndExtract` 用私有目录里的测试 ZIP 解出 1 张 PNG，并判定 `../escape.png` 为非法路径。解压目录随后删除，没有写入 `xedc_import_task`，没有新建图片内容。后台表单上传和标签、人物关联没有在浏览器里操作。接管关闭，解压结果不能写进 R2。

## 生产导入器

`ArchiveManager` 继续使用现有导入任务表 `xedc_import_task`、`xedc_import_item`、`xedc_import_log`。远程压缩包会先复制到临时文件，ZipArchive 不打开远程 URL。处理结束释放临时副本，不删除原始 ZIP 记录。

现有限制（数量、总大小、深度、类型）仍由导入器执行。本次没有修改这些阈值，也没有拿生产 ZIP 跑一遍。因此不能把隔离前缀上的一个 PNG 写成“ZIP 导入已经在 R2 上创建了内容”。

接管关闭时，导入产生的图片仍进入本地 `public://`。要让导入结果进入 R2，需要人工打开公开接管，或单独执行已确认的迁移复制。两件事都还没做。
