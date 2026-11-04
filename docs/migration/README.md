# XEDC 备份、迁移与灾难恢复

`xedc-backup` 和 `xedc-restore` 的操作说明在 [legacy/README-xedc-recover.md](../../legacy/README-xedc-recover.md) 和 [QUICK_RESTORE.md](../../QUICK_RESTORE.md)。仓库根目录的 README 现在介绍另一套带 age 加密的 `xedc-recovery/`。

本目录按用途分开：

| 类型 | 文件 |
| --- | --- |
| 用户操作 | legacy/README-xedc-recover.md、QUICK_RESTORE.md |
| 开发架构 | ARCHITECTURE.md、BACKUP_FORMAT.md、ENVIRONMENT_AUDIT.md、DEPENDENCY_INVENTORY.md、BACKUP_GUIDE.md、FULL_RESTORE.md、DOMAIN_MIGRATION.md、SSL_GUIDE.md、CREDENTIALS_GUIDE.md |
| 测试和安全审计 | TEST_PLAN.md、TEST_REPORT.md、SECURITY_REVIEW.md、KNOWN_ISSUES.md、CHANGELOG.md、PROGRESS.md |

下面保留原有维护说明，不作为恢复网站的必读步骤。

这套工具的日常命令是 `xedc-backup` 和 `xedc-restore`。新备份不需要单独解密密钥。旧的 AES-256-GCM 备份仍然需要原来的 `primary.key`。

程序位置：`tools/xedc-recover`。

```bash
python3 tools/xedc-recover/bin/xedc-recover --version
python3 tools/xedc-recover/bin/xedc-recover --help
```

当前版本：2.2.0。验收记录见 [TEST_REPORT.md](TEST_REPORT.md)，限制见 [KNOWN_ISSUES.md](KNOWN_ISSUES.md)。

旧服务器离线时，用完整恢复包恢复，不要把 GitHub 当作数据库和媒体文件的来源。命令见 [QUICK_RESTORE.md](QUICK_RESTORE.md)。

## 先读哪一份

| 场景 | 文档 |
| --- | --- |
| 原服务器已经损坏，要尽快把网站拉起来 | [QUICK_RESTORE.md](QUICK_RESTORE.md) |
| 需要理解每一步在做什么 | [FULL_RESTORE.md](FULL_RESTORE.md) |
| 制作、校验、上传备份 | [BACKUP_GUIDE.md](BACKUP_GUIDE.md) |
| 更换域名 | [DOMAIN_MIGRATION.md](DOMAIN_MIGRATION.md) |
| 证书 | [SSL_GUIDE.md](SSL_GUIDE.md) |
| 密钥和管理员应急入口 | [CREDENTIALS_GUIDE.md](CREDENTIALS_GUIDE.md) |
| 失败了 | [TROUBLESHOOTING.md](TROUBLESHOOTING.md) |

## 生产服务器上的限制

当前生产网站目录是 `/var/www/drupal`。只要这个目录还在，工具会拒绝：

- 安装或升级软件包
- 修改 Nginx、PHP、数据库、证书和定时任务
- 向本机 3306 或数据库套接字写入
- 把恢复目标设成生产目录
- 用 `--force` 跳过上述限制

生产主机上允许的操作是只读审计，以及带 `--allow-live-read` 的加密备份导出。备份写到 `/var/lib/xedc-recover`，不进入 Git。

## 退出码

| 码 | 含义 |
| --- | --- |
| 0 | 成功 |
| 1 | 失败 |
| 2 | 用法错误 |
| 3 | 生产环境保护拒绝了操作 |
| 4 | 解密或校验失败 |
| 5 | 依赖无法按要求安装 |

`--json` 输出结构化结果。`--dry-run` 只检查，不写备份。`--verbose` 保留给排障。`--force` 会被接受，但不会关闭生产保护。
