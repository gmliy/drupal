# 快速恢复

旧服务器不需要在线。准备：

- 一台新的 Ubuntu 24.04
- 单独保存的解密密钥 `primary.key`
- Cloudflare R2 的 Access Key 和 Secret
- 新域名，或继续使用原域名

```bash
sudo apt-get update
sudo apt-get install -y git python3 ca-certificates
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
sudo mkdir -p /var/lib/xedc-recover/keys
sudo install -m 600 /从密码管理器取出的/primary.key /var/lib/xedc-recover/keys/primary.key
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
xedc-restore --domain 新域名 --yes
```

私有仓库要先完成 Git 登录。不要把令牌写进命令。

指定历史备份，或使用已经放在本机的包：

```bash
xedc-restore --backup xedc-full-20261104 --domain 新域名 --yes
xedc-restore --file /path/to/xedc-full.tar.zst --domain 新域名 --yes
```

默认不会把新网站的写入指向生产 R2。旧服务器确认下线后，才把上一行的命令加上 `--role replacement`。

确认：

```bash
curl -sI -H "Host: 新域名" http://127.0.0.1/
curl -s -H "Host: 新域名" http://127.0.0.1/user/login | grep -o user-login-form
```

首页和登录页为 200，未登录的 `/admin` 为 403。用原来的密码登录。报告在 `/srv/xedc/INSTALL_REPORT.json`。新数据库口令在 `/var/lib/xedc-recover/restore-db.password`。

更完整的说明在仓库根目录 `README.md`。
