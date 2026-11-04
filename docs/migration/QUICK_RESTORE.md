# 快速恢复

`xedc-backup` / `xedc-restore` 的操作说明：

- [legacy/README-xedc-recover.md](../../legacy/README-xedc-recover.md)
- [QUICK_RESTORE.md](../../QUICK_RESTORE.md)

新服务器执行 `xedc-restore`。旧服务器执行 `xedc-backup`。新备份不需要单独的解密密钥；2026-11-03 之前的 AES 备份仍要原来的 `primary.key`。本目录其余文件是架构、测试和安全审计记录，恢复网站时不必逐份阅读。
