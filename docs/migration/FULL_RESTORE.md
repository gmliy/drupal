# 完整恢复手册

快速步骤见 [QUICK_RESTORE.md](QUICK_RESTORE.md)。本文说明每个阶段实际做什么，以及可以单独重跑的命令。

## 恢复顺序

`restore run` 按 manifest 的父链从最旧的完整备份开始，把文件一层层叠加上去，并应用增量里记录的删除。数据库只用你指定的那一份备份，因为每一份备份都含有完整数据库导出。

内部顺序：

1. 确认目标不是当前生产目录。
2. 用密钥解密代码、public、private 和数据库。明文哈希必须和 manifest 一致。
3. 解包时拒绝绝对路径、`..` 和设备文件。指向备份外部的符号链接会被拒绝。包内硬链接会复制成普通文件，不在目标目录外建立链接。
4. lab 角色删除 `xedc-storage-keys`，并把 `active.json` 改成本地文件模式。
5. 把 public 文件链接到 `web/sites/default/files`。
6. 改写 `settings.php` 里最后一处真实的数据库账号、主机、私有文件目录和 trusted host。文件前面的示例注释保持原样。`hash_salt` 保持不变，会员密码哈希才能继续有效。
7. 调整权限：代码和 public 可被 Web 进程读取，private 只允许属主读取，`settings.php` 为 `440`。
8. `--import-database` 时，在已有恢复标记且不存在生产目录的机器上创建空库并导入。重复执行会把数据库用户密码改成这次环境变量里的新密码。

不会自动执行 `drush cim`、`drush updb` 或 `drush cr`。导入的是原库的完整数据，不需要用配置导入覆盖它。

## 角色

| 角色 | 何时使用 | 对象存储 |
| --- | --- | --- |
| `lab` | 演练，旧站还在 | 强制本地文件，去掉密钥 |
| `replacement` | 旧服务器已经损坏 | 保留备份中的存储配置 |

当前生产目录存在时，`replacement` 会被拒绝。

## 手工恢复

自动命令失败时，可以停在已解密的材料上继续：

```bash
python3 tools/xedc-recover/bin/xedc-recover --config /root/xedc-recover.json backup verify --backup-id BACKUP_ID
```

校验通过后，仍然优先用 `restore run`。不要用文本替换改 SQL。域名变更用 `domain migrate`。

数据库导入使用：

```bash
gzip -dc database.sql.gz | mariadb DATABASE
```

只在新机器的空库上执行。工具在发现生产目录时不会代你执行这一步。

## 权限

```bash
chown -R www-data:www-data /var/www/drupal/web/sites/default/files /var/www/drupal/private
find /var/www/drupal/web/sites/default/files /var/www/drupal/private -type d -exec chmod 775 {} +
find /var/www/drupal/web/sites/default/files /var/www/drupal/private -type f -exec chmod 664 {} +
chmod 440 /var/www/drupal/web/sites/default/settings.php
```

## 健康检查

```bash
php -v
nginx -t
systemctl status php8.5-fpm nginx mariadb --no-pager
curl -sS -D - -o /dev/null -H 'Host: gitxx.xyz' http://127.0.0.1/user/login
cd /var/www/drupal && vendor/bin/drush status
```

Drush 能显示 bootstrap 成功后，再看用户数、节点数和 `file_managed` 行数是否与 manifest 的 `database.counts` 一致。

## 断点

恢复中断后，对同一 `--target` 再执行一次 `restore run --yes`。文件解包会覆盖同名文件。数据库导入不要在导入成功后再重复，除非你先清空目标库。命令失败时退出码不是 0，报告里的 `database_imported` 只有导入成功才为真。
