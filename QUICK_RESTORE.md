# 快速恢复

旧服务器不需要在线。准备：

- 一台新的 Ubuntu 24.04
- 一份完整备份。它在本地目录，或在 Cloudflare R2 的私有桶里
- 从 R2 恢复时，还需要 R2 的 Access Key 和 Secret
- 新域名，或继续使用原域名

2026-11-03 之后的新备份没有单独的解密密钥。更早的 AES 备份仍然需要原来的 `primary.key`。

```bash
sudo apt-get update
sudo apt-get install -y git python3 ca-certificates
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
xedc-restore --domain 新域名 --yes
```

私有仓库要先完成 Git 登录。不要把令牌写进命令。

从本地备份、本地文件，或指定的 R2 备份恢复：

```bash
xedc-restore --source local --backup 备份编号 --domain 新域名 --yes
xedc-restore --file /var/lib/xedc-recover/backups/xedc-full-备份.tar.zst --domain 新域名 --yes
xedc-restore --source r2 --backup 备份编号 --domain 新域名 --yes
```

本地恢复不访问 R2。指定文件时会核对旁边的 `.sha256` 和包内 Manifest。

默认不会把新网站的写入指向生产 R2。旧服务器确认下线后，才把命令加上 `--role replacement`。

确认：

```bash
curl -sI -H "Host: 新域名" http://127.0.0.1/
curl -s -H "Host: 新域名" http://127.0.0.1/user/login | grep -o user-login-form
```

首页和登录页为 200，未登录的 `/admin` 为 403。用原来的密码登录。报告在 `/srv/xedc/INSTALL_REPORT.json`。新数据库口令在 `/var/lib/xedc-recover/restore-db.password`。

更完整的说明在仓库根目录 `README.md`。
