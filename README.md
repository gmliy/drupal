# XEDC 网站备份与恢复

普通操作只有三件事：备份、把备份传到新服务器、恢复。

不需要自己安装 PHP、MariaDB 或 Nginx，不需要自己解压备份，也不需要自己导入数据库。

当前工具版本 2.4.0。自动安装网站环境只支持已经测过的 Ubuntu 24.04。Ubuntu 26.04 目前是 BLOCKED，程序不会给它添加 PHP 软件源，也不会继续恢复。

## 安装工具

在要执行命令的机器上：

```bash
sudo apt-get update
sudo apt-get install -y git python3 python3-cryptography ca-certificates zstd
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-backup /usr/local/bin/xedc-backup
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
xedc-backup --version
```

必须使用上面的符号链接。不要把 `xedc-backup` 单独复制到 `/usr/local/bin`，那样程序会找不到自己的代码。

私有仓库要先完成 Git 登录。不要把令牌写进命令或文件。

## 你需要记住的命令

| 要做的事 | 命令 |
| --- | --- |
| 完整备份，并上传到 R2 | `xedc-backup` |
| 只在本机备份 | `xedc-backup --local-only` |
| 查看本机备份 | `xedc-backup list` |
| 在新服务器上从 R2 恢复 | `xedc-restore` |
| 从已经传到本机的文件恢复 | `xedc-restore --file /var/lib/xedc-recover/backups/备份文件.tar.zst --domain 新域名 --role lab --yes` |

备份默认同时保留在本机和 Cloudflare R2。R2 上传失败时，本机那份完整备份会留下，程序退出码为 1，并给出重新上传命令：

```bash
xedc-backup upload --backup 备份编号
```

程序不会因为上传失败而删除唯一的完整备份，也不会自动删除旧备份或 R2 上的历史备份。

新备份不需要单独的解密密钥。2026-11-03 之前的旧备份仍是 AES-256-GCM，恢复时需要原来的 `primary.key`。不要删除那把钥匙，程序也不会覆盖旧备份。

R2 使用私有桶、HTTPS 和服务端加密。这不是端到端加密。

## 备份放在哪里

本机完整包：

```text
/var/lib/xedc-recover/backups/
```

目录权限 `0700`，备份文件权限 `0600`，属主是 root。

R2 对象在桶 `dclili` 的 `xedc-recover/packages/` 下面。不要把网站正在使用的 `public/` 或 `private/` 前缀写进备份命令。

大文件的下载和解包使用：

```text
/var/lib/xedc-recover/downloads/
/var/lib/xedc-recover/work/
```

网站真正运行在 `/srv/xedc`，不会长期放在工作目录里，也不会解到 `/tmp`。

## 两种恢复方式

测试恢复使用 `--role lab`。它把网站装到这台新机器，并暂停写回生产对象存储。

正式替换使用 `--role replacement`。在交互终端里还要再输入 `替换正式网站`。这台机器上如果还能看到生产网站目录，两种角色都会被拒绝。`--force` 不能跳过这个保护。

程序不修改 Cloudflare DNS，也不申请正式证书。恢复完成后由人确认，再把域名指向新服务器。

数据库口令写在 `/var/lib/xedc-recover/restore-db.password`，权限 `600`。会员和管理员仍使用原来的密码。

逐步操作见 [QUICK_RESTORE.md](QUICK_RESTORE.md)。
