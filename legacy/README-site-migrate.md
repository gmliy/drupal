# 旧迁移脚本

`site-migrate.sh` 已移到本目录。它按 Git 提交号检出代码，再迁移数据库和文件。当前入口不是这个脚本。

归档时，仓库根目录的 README 并不是这份脚本的手册，而是 `tools/xedc-recover` 的使用说明。那份说明保存在 [README-xedc-recover.md](README-xedc-recover.md)，避免把它错标成 `site-migrate.sh` 的文档。

新的备份和恢复入口是仓库根目录 README，以及 [xedc-recovery/README.md](../xedc-recovery/README.md)。
