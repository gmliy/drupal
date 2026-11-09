# fix/workdir-and-exclude 验收报告

更新时间：2026-11-09（UTC）

## 问题一：工作目录与 /tmp（已实现，Docker 专项已通过）

- `XEDC_WORKDIR` 默认 `/var/lib/xedc-recovery/work`，小文件在 `/run/xedc`；`mktemp -p` / `xedc_mktemp` / `xedc_work_mktemp`
- tmpfs 自动避让、`--workdir`、恢复前磁盘/inode 预检、tar 错误摘要、流式解压与 DB 导入（`tar -xO` 管道）
- `restore.sh` tmux 显式传入 `XEDC_WORKDIR`/`TMPDIR`
- 单元测试 + `guest.sh disk`（lite）+ 恢复容器 `--tmpfs /tmp:size=64m`

## 问题二：备份排除与 manifest 体积（已实现，备份源用例已通过）

- exclude.list、Docker 卷按站点挂载筛选、备份日志体积明细、`backup_manifest.php`
- **修复**：`backup_manifest.php` 须在打 tar **之前** 写入 `stage/manifest.json`，否则包内无 `unpacked_bytes`（此前导致五系统 backup 阶段全败）

## 问题三：xedc-test-* 命名空间（已实现）

- `run-all.sh` / `cleanup.sh` 使用 `xedc-test-*` 卷；`exclude.list` 已列入

## 测试状态

| 项目 | 状态 |
|------|------|
| `tests/unit.sh` | 通过 |
| disk / workdir 专项 | 通过 |
| `guest.sh source`（单容器验证） | 通过（含 manifest 体积字段） |
| 五系统 `run-all.sh` | 见 `tests/logs/run-all-final5.log` 与 `status.tsv` |

## 需真实服务器验证（Docker 不能代替）

- 真机 `/tmp` 为 818M tmpfs 下完整恢复（与容器 `--tmpfs` 等价但建议再跑一遍）
- 真人 tmux 交互与 R2 生产桶
- 合并后在本机执行 `backup.sh --target both` 更新 bootstrap

## 合并

全部 `status.tsv` 无「失败」、工作区干净后合并 `main` 并推送；否则停留本分支。
