# XEDC Drupal 一键备份与恢复

这是一个 Drupal 网站的一键完整备份、本地保存、Cloudflare R2 异地保存和跨服务器恢复系统。

GitHub 保存网站程序和可以版本管理的配置。完整备份包同时可以放在服务器本地和 Cloudflare R2。两处保存的是同一份文件，互不依赖。新服务器从其中一处取回备份，自动安装环境并恢复网站。

## 命令

| 需求 | 命令 |
| --- | --- |
| 本地 + R2 备份 | `xedc-backup` |
| 仅本地备份 | `xedc-backup --local-only` |
| 仅 R2 备份 | `xedc-backup --r2-only` |
| 查看本地备份 | `xedc-backup list` |
| 从 R2 恢复 | `xedc-restore --domain 新域名` |
| 从本地恢复 | `xedc-restore --source local --backup 备份编号 --domain 新域名` |
| 指定 R2 备份恢复 | `xedc-restore --source r2 --backup 备份编号 --domain 新域名` |

本地备份还可以：

```bash
xedc-backup verify --backup 备份编号
xedc-backup delete --backup 备份编号
xedc-backup upload --backup 备份编号
```

`upload` 重新上传已经打好的本地包，不会重新导出数据库或文件。

## 架构

```text
旧服务器
  xedc-backup
      |                         |
      v                         v
  本地目录                      Cloudflare R2
  /var/lib/xedc-recover/backups 私有桶中的同一份 tar.zst
      |                         |
      +-----------+-------------+
                  v
            新服务器
            xedc-restore
            安装 PHP、Nginx、MariaDB
            按备份记录的提交取代码
            恢复数据库和文件
```

新备份没有单独的解密密钥。本地包靠目录权限保护。R2 上的同一份包放在私有桶里，通过 HTTPS 传输，并使用服务端静态加密。这不是端到端加密。拿到服务器登录权限或 R2 凭据的人可以读取备份内容。R2 凭据只用来访问桶，不参与内容校验。内容校验是 SHA256 和 Manifest。

2026-11-03 之前用 `primary.key` 做 AES-256-GCM 加密的旧包仍然可以恢复，而且仍然需要原来的那把密钥。

## 旧服务器：备份

仓库如果是私有的，先用 GitHub CLI 或 SSH 完成登录。不要把令牌写进命令或文件。

```bash
sudo apt-get update
sudo apt-get install -y git python3 python3-cryptography zstd
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-backup /usr/local/bin/xedc-backup
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
sudo mkdir -p /var/lib/xedc-recover/backups
sudo chmod 700 /var/lib/xedc-recover /var/lib/xedc-recover/backups
xedc-backup
```

请用 root 执行。备份目录是 `0700`，备份文件是 `0600`，网站运行用户不能读取数据库和配置。

默认会先在 `/var/lib/xedc-recover/backups/` 写完整包并核对 SHA256，再把同一份文件上传到 R2，然后下载回来再核对一次。成功时打印本地路径、R2 对象位置、备份编号、大小和两边的校验结果。

对象存储使用环境变量 `XEDC_S3_ACCESS_KEY` 和 `XEDC_S3_SECRET_KEY`。如果本机 Drupal 已经配置了 Cloudflare R2，程序会读取现有密钥，但只把备份写到 `xedc-recover/` 前缀，不改网站正在使用的对象。

只做本地备份时不需要这些凭据，也不会连接 R2：

```bash
xedc-backup --local-only
```

只上传 R2、不保留长期本地副本：

```bash
xedc-backup --r2-only
```

这种方式会先写临时文件。上传成功并且远端下载校验通过之后，才删除临时文件。中途失败会留下临时文件，不会把它报告为成功。

R2 上传失败时，默认模式保留本地完整包，并明确报告本地成功、R2 失败。整个任务的退出码不是 0。稍后执行：

```bash
xedc-backup upload --backup 备份编号
```

也可以把备份目录写进 `/var/lib/xedc-recover/config.json`。`package_dir` 为空时，包就放在 `backup_root`。`key_file` 只给旧的加密包使用，新备份不会读取或生成它。

```json
{
  "backup_root": "/var/lib/xedc-recover/backups",
  "package_dir": "",
  "key_file": "/var/lib/xedc-recover/keys/primary.key",
  "source_root": "/var/www/drupal",
  "remote": {
    "endpoint": "https://ACCOUNT.r2.cloudflarestorage.com",
    "bucket": "YOUR_BUCKET",
    "region": "auto",
    "prefix": "xedc-recover/",
    "access_key_env": "XEDC_S3_ACCESS_KEY",
    "secret_key_env": "XEDC_S3_SECRET_KEY"
  },
  "retention": {"keep": 7, "days": 0, "daily": 7, "weekly": 4}
}
```

`keep` 是本地保留份数，`days` 是保留天数。`days` 为 0 表示不按天数删除。自动清理写在备份目录的 `prune.log`。它不会删除唯一一份备份，也不会删除尚未成功上传到 R2 的唯一完整备份。磁盘空间不够放下完整包时，程序会拒绝开始，而不是写出半份文件。

备份期间网站继续服务。数据库使用单事务导出。正在被写入的文件会记入漂移清单，不会为了备份去停机或清空缓存。

`xedc-backup list` 列出编号、创建时间、大小、SHA256 和 R2 状态。

## 新服务器：恢复

新机器需要 Ubuntu 24.04。从 R2 恢复时还需要 R2 凭据。从本地包恢复时不需要旧服务器在线，也不需要在恢复过程中访问 R2。新格式的包不需要解密密钥。旧的 AES 包仍然需要原来的 `primary.key`。

```bash
sudo apt-get update
sudo apt-get install -y git python3 ca-certificates
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-backup /usr/local/bin/xedc-backup
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
xedc-restore --domain 新域名 --yes
```

不要把脚本复制到 `/usr/local/bin` 后再执行。那样会找不到同目录里的程序。请使用上面的符号链接。

从本地编号或本地文件恢复：

```bash
xedc-restore --source local --backup 备份编号 --domain 新域名 --yes
xedc-restore --file /var/lib/xedc-recover/backups/xedc-full-备份.tar.zst --domain 新域名 --yes
```

指定文件时，旁边必须有同名 `.sha256`。程序会核对这个校验值，并核对包内 Manifest。本地文件和 R2 对象使用同一种 `xedc-full-*.tar.zst`。

指定 R2 上的某一份：

```bash
xedc-restore --source r2 --backup 备份编号 --domain 新域名 --yes
```

默认 `--role lab`：网站文件恢复到本机，对象存储写入保持暂停，避免在旧服务器还在线时写回生产 R2。确认旧服务器已经下线、要让新网站继续使用原来的对象存储时，才使用：

```bash
xedc-restore --domain 新域名 --yes --role replacement
```

程序会安装与备份匹配的 PHP 8.5、Nginx、MariaDB 和必要扩展，恢复数据库、public 文件和 private 文件，并写好站点配置。它不修改 Cloudflare DNS，也不申请正式证书。新机器上会放一份仅供检查的自签证书。

数据库口令写在 `/var/lib/xedc-recover/restore-db.password`，权限是 `600`。会员和管理员仍使用原来的密码。代码优先按备份记录的提交从 GitHub 取出。提交不存在，或备份时工作区有未提交改动时，使用备份包里的代码快照。

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

- 新备份没有客户端加密，也不生成 `primary.key`。请用服务器登录权限、目录 `0700` 和磁盘本身的保护来保管本地包。
- R2 必须是私有桶。传输使用 HTTPS。服务端静态加密不等于端到端加密。
- 旧的 AES-256-GCM 备份仍然要原来的 `primary.key`。不要把这把密钥提交到 GitHub，也不要放进 R2。
- 数据库口令、R2 密钥和 SSL 私钥不要提交到 GitHub。
- 生产网站目录还在这台机器上时，`xedc-restore` 会直接拒绝。`--force` 不能跳过。
- 恢复目标里如果已经有别的网站，程序也会拒绝覆盖。
- 远端清理只删除 `xedc-recover/` 前缀下过期的 `xedc-full-*` 备份，不会删除网站媒体对象。上传中断不会改写本地已经完成的包。
- 正式证书和 Cloudflare DNS 需要你确认后再做。

## 常见问题

| 现象 | 处理 |
| --- | --- |
| 退出码 1，并写明本地成功、R2 失败 | 本地包已保留。稍后执行 `xedc-backup upload --backup 备份编号`。 |
| 退出码 3 | 这台机器仍有生产网站，或目标路径被保护。换一台没有该网站的机器。 |
| 退出码 4 | 这是旧加密包，密钥和备份不是一对。 |
| 退出码 5 | 没能安装 PHP 8.5。不要改用别的 PHP 版本继续。 |
| `远端没有完整恢复包` | R2 前缀不是 `xedc-recover/`，或凭据指向了别的桶。 |
| `磁盘空间不足` | 备份会在写入前拒绝。恢复时可用空间需要达到恢复包大小的大约 3 倍。 |
| `拒绝删除唯一一份本地备份` | 至少留下一份。自动清理也不会删除还没上传成功的唯一完整包。 |
| GitHub 克隆失败 | 私有仓库需要先登录。恢复仍可使用备份包里的代码快照。 |
| 再次执行没有变化 | 同一份成功报告会直接返回。要重做，先删除 `/srv/xedc`。 |

开发和审计记录在 `docs/migration/`。恢复网站不需要阅读那些文件。
