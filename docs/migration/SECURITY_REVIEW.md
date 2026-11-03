# 安全审查

审查范围：`feature/migration-v2` 新增的 `tools/xedc-recover` 和 `docs/migration`。审查时没有把生产口令、对象存储密钥或 GitHub 令牌写进文档、测试输出或提交说明。

## 生产边界

以下操作在生产网站目录存在时退出码为 3，`--force` 不会改变结果：

- 安装软件包
- 向 `/etc/systemd` 安装定时任务
- 重置管理员密码
- 连接本机 3306 或数据库套接字做写入
- 把恢复目标设为 `/var/www/drupal`
- 在生产机上使用 replacement 角色

依据是 `tests/test_core.py` 的安全测试，连续两轮通过。

数据库导出通过 `0600` 的临时 defaults 文件调用 `mysqldump --single-transaction`，口令不出现在进程参数里。日志经过脱敏。远程删除函数直接拒绝，避免误删对象存储里的备份。

## 加密和备份内容

- 算法是 AES-256-GCM。密钥文件单独存放，模式 `600`。
- manifest 记录哈希和计数，不记录口令、`hash_salt` 或对象存储密钥。
- 解包拒绝绝对路径、`..`、设备文件和指向目标外的符号链接。包内硬链接复制成普通文件。
- lab 角色删除 `xedc-storage-keys`，并把运行配置改成本地文件、暂停写入。
- 恢复改写 `settings.php` 时保留 `hash_salt`，只替换最后一处真实数据库配置。

生产只读备份 `xedc-20261103T135450Z-3cd3bf` 的 `backup verify` 为 PASS。备份和密钥位于 `/var/lib/xedc-recover`，不在 Git 工作区。

## 演练容器

容器非特权，没有 docker.sock，没有 host 网络，没有生产目录挂载。端口只绑定 `127.0.0.1:18080`。演练结束后删除该容器，避免一份含有站点数据的恢复结果继续对外服务。加密备份仍留在宿主机的备份目录。

## 扫描

对工具和迁移文档搜索了 `ghp_`、`AKIA` 和私钥开头。命中三处，都不是生产凭据：

- `redact.py` 里的脱敏规则本身包含这些前缀。
- `tests/test_lab.py` 使用 AWS 文档公开的签名示例 `AKIAIOSFODNN7EXAMPLE`。

新文档和工具代码里没有生产数据库口令，也没有 GitHub 个人访问令牌。

## 2026-11-03 最终审计

再次确认生产 `settings.php`、`/etc/nginx/conf.d/drupal.conf` 和 `/root/backup.sh` 的修改时间没有变化。新的演练容器仍是非特权、无 docker.sock、无 host 网络，只读挂载密钥、工具和备份，端口只绑定 `127.0.0.1:18080`。审计结束后容器已删除。

恢复程序原先把所有普通文件改成 `644`，Drush 启动脚本因此不能执行。现已保留归档中的可执行位，`settings.php` 仍是 `440`。该修复有隔离测试，并在随后两轮全新恢复里确认 `vendor/bin/drush` 为 `755`。

正式 R2 上传和正式证书申请仍然没有执行。审计日志位于 `/var/lib/xedc-recover/audit/`，里面没有写入 Git。

## 仍须人工保管

- `/var/lib/xedc-recover/keys/primary.key` 不进入 Git。丢失后已有备份无法解密。
- 主分支历史上已经跟踪 `web/sites/default/settings.php`。这次提交不新增其中的值，也不改写历史。
- 正式 R2 上传和正式证书申请没有执行。
