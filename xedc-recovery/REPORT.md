# fix/backup-silent-exit 验收报告

更新时间：2026-11-10（UTC）

## 根因与修复

- **静默退出**：`du | sort | head` 在 `pipefail` 下 SIGPIPE(141)。改为 `xedc_du_top_bytes`（先排序落盘再 `awk` 取行）、`xedc_head_lines`，并对其余风险管道做 `set +o pipefail` 或 `xedc_safe_pipe`。
- **可观测性**：`backup.sh` 增加 `ERR`/`EXIT` trap，失败打印步骤、行号、退出码；成功以 `备份完成: 路径 大小 sha256=… 耗时` 收尾。
- **输出**：终端按 `[n/8]` 阶段一行；排除项与 top15 明细仅写 `/var/log/xedc-backup.log`，`--verbose` 才上屏。
- **体积**：默认不同步 `files/{js,css,php,styles,ctools,tmp}`（`--include-styles` 保留 styles）；`docs` 仅匹配代码根目录；`include.list` 强制包含 Docker 卷；未挂载大数据卷终端 `※` 提示。
- **客户端**：优先 `mariadb` / `mariadb-dump`；非交互下 `zstd -q`、rclone `--stats-one-line`。
- **清理**：启动清理 `stage.*` 残留；`EXIT` 删本次 stage；暂存须在磁盘分区且可用空间 ≥ 估算 1.5 倍。

## 测试

| 项目 | 状态 |
|------|------|
| `tests/unit.sh` | 通过（含 SIGPIPE/du top、docs 路径规则） |
| 五系统 `backup` | **全部通过**（`tests/logs/run-all-backup-fix3.log`） |
| 五系统 `restore` | **全部失败**（R2 下载包内缺少 `db/site.sql.zst`，待查 MinIO/latest 与并行前缀） |
| 真机 `backup.sh --target local` | 通过（约 125MB 包，排除节省约 1.6GB，见日志 02:05） |

## 真实服务器

- 分支合并后执行：`bash xedc-recovery/backup.sh --target both`，更新 R2 bootstrap。

## 合并

- 按 SPEC §14：restore 全败时**不合并**到 `main`，停留 `fix/backup-silent-exit` 直至 restore 根因排除；备份相关需求已满足并可单测验收。
