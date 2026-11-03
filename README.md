# XEDC Drupal 一键备份与恢复

这是一个 Drupal 网站的一键完整备份、Cloudflare R2 异地存储和跨服务器一键恢复系统。

GitHub 保存网站程序和可以版本管理的配置。Cloudflare R2 保存数据库、上传文件和加密后的完整备份包。新服务器从这两处取回资源，自动安装环境并恢复网站。

## 架构

```text
旧服务器
  xedc-backup
      |                         |
      v                         v
   GitHub                      Cloudflare R2
   代码、composer.lock、        加密的完整备份包
   config/sync、恢复程序         数据库、public/private 文件、
                                 manifest、SHA256
      |                         |
      +-----------+-------------+
                  v
            新服务器
            xedc-restore
            安装 PHP、Nginx、MariaDB
            按备份记录的提交取代码
            解密并恢复数据库和文件
```

解密密钥不在 GitHub 里，也不在 R2 里。请单独保存。

## 旧服务器：一键备份

仓库如果是私有的，先用 GitHub CLI 或 SSH 完成登录。不要把令牌写进命令或文件。

```bash
sudo apt-get update
sudo apt-get install -y git python3 python3-cryptography zstd
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-backup /usr/local/bin/xedc-backup
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
xedc-backup
```

第一次运行会做两件需要你保管的事：

1. 如果还没有解密密钥，程序会生成 `/var/lib/xedc-recover/keys/primary.key`。立刻复制到密码管理器或其他机器。没有这个文件就无法恢复。
2. 对象存储使用环境变量 `XEDC_S3_ACCESS_KEY` 和 `XEDC_S3_SECRET_KEY`。如果本机 Drupal 已经配置了 Cloudflare R2，程序会读取现有密钥，但只把备份写到 `xedc-recover/` 前缀，不改网站正在使用的对象。

也可以自己写一份不含密钥的配置 `/var/lib/xedc-recover/config.json`：

```json
{
  "backup_root": "/var/lib/xedc-recover/backups",
  "key_file": "/var/lib/xedc-recover/keys/primary.key",
  "source_root": "/var/www/drupal",
  "remote": {
    "endpoint": "https://ACCOUNT.r2.cloudflarestorage.com",
    "bucket": "YOUR_BUCKET",
    "region": "auto",
    "prefix": "xedc-recover/",
    "access_key_env": "XEDC_S3_ACCESS_KEY",
    "secret_key_env": "XEDC_S3_SECRET_KEY"
  }
}
```

然后：

```bash
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
xedc-backup --config /var/lib/xedc-recover/config.json
```

成功时会打印备份编号、大小、SHA256、远端位置和用时。上传后会重新下载并解密校验。校验失败不会显示成功。中断后执行 `xedc-backup --resume`。

备份期间网站继续服务。数据库使用单事务导出。正在被写入的文件会记入漂移清单，不会为了备份去停机或清空缓存。

## 新服务器：一键恢复

新机器只需要 Ubuntu 24.04、上面的解密密钥，以及 R2 的访问凭据。旧服务器不需要在线。

```bash
sudo apt-get update
sudo apt-get install -y git python3 ca-certificates
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo install -m 755 /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
sudo mkdir -p /var/lib/xedc-recover/keys
sudo install -m 600 /从密码管理器取出的/primary.key /var/lib/xedc-recover/keys/primary.key
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
xedc-restore --domain 新域名 --yes
```

交互执行 `xedc-restore` 时，程序会在开始前询问还缺少的域名、R2 凭据和解密密钥路径。其余安装、下载、校验、解密和导入都会自动完成。

默认 `--role lab`：网站文件恢复到本机，对象存储写入保持暂停，避免在旧服务器还在线时写回生产 R2。确认旧服务器已经下线、要让新网站继续使用原来的对象存储时，才使用：

```bash
xedc-restore --domain 新域名 --yes --role replacement
```

程序会安装与备份匹配的 PHP 8.5、Nginx、MariaDB 和必要扩展，恢复数据库、public 文件和 private 文件，并写好站点配置。它不修改 Cloudflare DNS，也不申请正式证书。新机器上会放一份仅供检查的自签证书。

数据库口令写在 `/var/lib/xedc-recover/restore-db.password`，权限是 `600`。会员和管理员仍使用原来的密码。

## 指定备份

恢复最新一份：

```bash
xedc-restore --domain 新域名 --yes
```

恢复某一天或某一个编号：

```bash
xedc-restore --backup xedc-full-20261104 --domain 新域名 --yes
```

使用已经下载到本机的包：

```bash
xedc-restore --file /path/to/xedc-full.tar.zst --domain 新域名 --yes
```

`--file` 旁边必须有同名的 `.sha256` 文件。本地恢复不需要旧服务器，也不需要在恢复过程中访问 GitHub 上的数据库。代码优先按备份记录的提交从 GitHub 取出。提交不存在，或备份时工作区有未提交改动时，使用备份包里的代码快照。

## 怎样确认已经恢复

在新服务器上：

```bash
curl -sI -H "Host: 新域名" http://127.0.0.1/
curl -s -H "Host: 新域名" http://127.0.0.1/user/login | grep -o user-login-form
runuser -u www-data -- /srv/xedc/code/vendor/bin/drush --root=/srv/xedc/code/web status
```

首页和登录页应返回 200。未登录访问 `/admin` 应返回 403。用原来的管理员账号登录后台。打开一张图片和一个视频页。`/srv/xedc/private` 不应允许其他用户读取。完整结果在 `/srv/xedc/INSTALL_REPORT.json`。

确认无误后，再由人把域名指到新服务器。不要让两台服务器同时向生产 R2 写入。

## 安全

- 解密密钥、数据库口令、R2 密钥和 SSL 私钥不要提交到 GitHub。
- 备份包是加密的。只有密钥和备份包在一起才能恢复。
- 生产网站目录还在这台机器上时，`xedc-restore` 会直接拒绝。`--force` 不能跳过。
- 恢复目标里如果已经有别的网站，程序也会拒绝覆盖。
- 远端清理只删除 `xedc-recover/` 前缀下过期的 `xedc-full-*` 备份，不会删除网站媒体对象。
- 正式证书和 Cloudflare DNS 需要你确认后再做。

## 常见问题

| 现象 | 处理 |
| --- | --- |
| 退出码 3 | 这台机器仍有生产网站，或目标路径被保护。换一台没有该网站的机器。 |
| 退出码 4 | 解密密钥和这份备份不是一对。 |
| 退出码 5 | 没能安装 PHP 8.5。不要改用别的 PHP 版本继续。 |
| `远端没有完整恢复包` | R2 前缀不是 `xedc-recover/`，或凭据指向了别的桶。 |
| `磁盘空间不足` | 可用空间需要达到恢复包大小的大约 3 倍。 |
| GitHub 克隆失败 | 私有仓库需要先登录。恢复仍可使用备份包里的代码快照。 |
| 再次执行没有变化 | 同一份成功报告会直接返回。要重做，先删除 `/srv/xedc`。 |

开发和审计记录在 `docs/migration/`。恢复网站不需要阅读那些文件。
