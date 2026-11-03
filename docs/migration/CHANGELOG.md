# 变更记录

## 2.3.0

- 新的 `xedc-backup` 同时支持本地目录和 Cloudflare R2。默认先写本地包并核对 SHA256，再上传同一份文件，下载回来再次核对。
- `--local-only` 不连接 R2。`--r2-only` 在远端校验通过后才删除临时文件。R2 失败时保留本地包，退出码不是成功，并用 `xedc-backup upload --backup` 重新上传。
- `xedc-backup list`、`verify` 和 `delete` 管理本地包。保留策略不会删除唯一备份，也不会自动删除尚未上传成功的唯一完整包。
- 新包 `encryption.alg` 为 `none`，schema 为 4，不生成单独的解密密钥。本地靠目录权限，R2 靠私有桶和 HTTPS。旧的 AES-256-GCM 包仍要原来的 `primary.key`。

## 2.2.0

- `xedc-backup` 和 `xedc-restore` 成为两条完整命令。备份会加密、上传、回读并解密校验后才报告成功。
- 恢复可以指定 `--backup` 或 `--file`。代码优先对齐备份记录的 Git 提交；提交不可用或工作区不干净时使用包内快照。
- 远端保留策略只清理 `xedc-recover/packages/` 下过期的完整包。
- 根目录 README 和 QUICK_RESTORE 改为面向这两条命令。

## 2.1.0

- 增加 `xedc-backup create --full` 和 `xedc-restore install`。完整恢复包包含原有密文、环境清单、服务模板和恢复程序。
- 解密密钥与恢复包分开。对象存储只上传包、SHA256 和加密凭据，不上传密钥。
- 一键恢复会安装固定依赖、恢复网站、写入新域名和自签证书，并在重复执行时避免覆盖已有站点。
- APT 公钥改为 `644`。Nginx 启动前会退出仍占用 80 端口的旧进程。
- 环境清单记录发行版的 Node 和 npm，不用 PATH 里排在前面的其他 Node。

## 2.0.0

- 新增 `xedc-recover`：全量和增量备份、AES-256-GCM、manifest、校验、保留策略。
- 恢复支持 lab 和 replacement。lab 移除对象存储密钥并停用 CDN 写入。
- 域名迁移使用 PHP 处理序列化、JSON 和普通文本，并提供回滚。
- 提供自签证书、Nginx 配置渲染、语法测试和回滚。正式证书只生成计划。
- 生产网站目录存在时，安装、定时任务、管理员重置、本机数据库写入和生产目录恢复一律拒绝。
- 在隔离的 Ubuntu 24.04 容器中完成连续两轮全新恢复演练。
