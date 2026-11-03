# 进度

工作树：`/opt/xedc-migration`，分支 `feature/migration-v2`。生产仓库 `/var/www/drupal` 仍在 `main`，原有未提交修改没有被移动或清理。

最后更新：2026-11-03 15:23 UTC。最终审计日志在 `/var/lib/xedc-recover/audit/`。

| 阶段 | 状态 |
| --- | --- |
| 总规范和进度文件 | 完成 |
| 工作区保护 | 完成。独立 worktree，不改生产目录 |
| 只读环境审计 | 完成，见 ENVIRONMENT_AUDIT.md |
| 依赖清单 | 完成，见 DEPENDENCY_INVENTORY.md |
| 备份、加密、manifest、增量链 | 完成。隔离测试连续两轮 PASS |
| S3 客户端 | 协议测试 PASS。生产 R2 上传 BLOCKED |
| 域名迁移 | 隔离 MariaDB 连续覆盖。含回滚和第二次零变更 |
| SSL 自签、nginx -t、回滚 | PASS。正式证书 BLOCKED |
| 凭据命令 | 生产主机上重置管理员被拒绝 |
| 定时任务 | 渲染到临时目录 PASS。生产 systemd 安装被拒绝 |
| 生产只读加密备份 | PASS。`xedc-20261103T135450Z-3cd3bf`，verify PASS |
| 断点续跑 | 修复后的两轮单元测试包含中断后继续，结果 PASS |
| 全新容器恢复演练 | PASS。修复可执行位之后连续两轮 `DRILL_OK`，Drush 和管理页已检查 |
| 连续两轮关键测试 | PASS。见 TEST_REPORT.md 的 15:17–15:22 记录 |
| 文档 | 审计结果已写入 TEST_REPORT.md、KNOWN_ISSUES.md、SECURITY_REVIEW.md |
| Git 推送 | 审计修复和报告更新后推送 `feature/migration-v2`。不合并 main，不部署 |

中断后续跑时：先读 `docs/MIGRATION_MASTER_SPEC.md` 和本文件。不要在 `/var/www/drupal` 里切分支。不要删除 `/var/lib/xedc-recover`。不要把密钥、备份或生产数据提交到 Git。
