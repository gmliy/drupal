# 完整恢复手册

快速步骤见 [QUICK_RESTORE.md](QUICK_RESTORE.md)。本文说明每个阶段实际做什么，以及可以单独重跑的命令。

## 一键恢复在做什么

`xedc-restore install --backup 恢复包 --target 目录 --domain 域名 --yes` 的顺序是：

1. 生产目录存在时拒绝。没有 `--yes` 时拒绝。
2. 读取恢复包旁边的 `.sha256`。不一致就停止，不解密。
3. 检查磁盘和内存。目标里已有 `code/web/core`，且没有安装状态文件、也没有成功报告时，拒绝覆盖。
4. 解出恢复包。成员里的符号链接和逃出目录的路径会被拒绝。
5. 用原来的 `backup verify` 解密并核对 manifest。明文临时目录不放在备份目录里。
6. 安装 PHP 8.5、Nginx、MariaDB、Redis 和其余固定依赖。APT 公钥写成 `644`，避免 root 的 `umask 077` 让 APT 读不到公钥。
7. 调用原来的 `restore run`。数据库用户在 `localhost` 和 `127.0.0.1` 上都会更新密码。
8. 停止已经占用 80 端口的 Nginx，再写入新配置并启动。配置包含新域名和一份自签证书。正式证书和 Cloudflare DNS 留待人工确认。
9. 请求 `/user/login`，并以 Web 用户运行 Drush。报告写到目标目录的 `INSTALL_REPORT.json`。

失败时，状态写在目标目录的上一级，文件名是 `.目标名.xedc-install.json`。排除故障后对同一目标再执行一次。成功报告的 SHA256 与当前包一致时，再次执行不会重写，这不算第二次完整恢复。要重做，先删除目标目录和那个状态文件。

旧的目录备份仍然用下面的 `restore run`。完整恢复包只是把同一份密文、清单和恢复程序包在一起。

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
