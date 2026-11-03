# 快速恢复手册

假设旧服务器已经不能使用。你手头有：

- 一台新的 x86_64 服务器，建议至少 4 核、8 GB 内存、40 GB 可用磁盘
- 这个 Git 仓库的 `feature/migration-v2` 分支
- 加密备份（本机目录或 Cloudflare R2 / S3）
- 单独保管的解密密钥文件
- 要使用的域名

支持的系统：Ubuntu 24.04 是本次实际演练的系统。Ubuntu 22.04、20.04 以及 Debian 12/13 可以使用同一流程，但必须仍能安装 PHP 8.5。Ubuntu 20.04 和 Debian 11 已接近或超过常规维护窗口，不作为默认目标。不要为了省事改用发行版自带的 PHP 8.3，除非接受版本漂移并在报告里写明。

## 1. 准备新机器

用 root 登录。不要把仓库直接克隆到一台仍在运行旧站的机器上执行恢复。

```bash
apt-get update
apt-get install -y git python3 ca-certificates
git clone https://github.com/xxedc/drupal.git /opt/drupal
cd /opt/drupal
git checkout feature/migration-v2
```

确认这台机器没有旧站：

```bash
test ! -d /var/www/drupal/web/core && echo "可以继续"
```

如果上一行失败，说明这台机器仍是生产机。停止。不要继续。

## 2. 放入解密密钥和对象存储凭据

密钥文件权限必须是 `600`。不要把它放进 Git。

```bash
install -m 600 /path/from/your-password-manager/primary.key /root/xedc-recover.key
```

```bash
cat > /root/xedc-recover.json <<'EOF'
{
  "backup_root": "/var/lib/xedc-recover/backups",
  "key_file": "/root/xedc-recover.key",
  "public_files": "web/sites/default/files",
  "private_files": "/var/www/drupal/private",
  "remote": {
    "enabled": true,
    "endpoint": "https://ACCOUNT.r2.cloudflarestorage.com",
    "bucket": "YOUR_BUCKET",
    "region": "auto",
    "prefix": "xedc-recover/",
    "access_key_env": "XEDC_S3_ACCESS_KEY",
    "secret_key_env": "XEDC_S3_SECRET_KEY",
    "path_style": true
  }
}
EOF
chmod 600 /root/xedc-recover.json
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
```

只在本机磁盘上已有备份、不走对象存储时，把 `remote.enabled` 设为 `false`。

## 3. 查看并校验备份

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover \
  --config /root/xedc-recover.json backup list
```

选择时间最新、类型为 `full`，或者一条父备份都还在的 `incremental`。然后：

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover \
  --config /root/xedc-recover.json backup download --backup-id BACKUP_ID

python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover \
  --config /root/xedc-recover.json backup verify --backup-id BACKUP_ID
```

`verify` 的结果必须是 `"status": "PASS"`。校验失败就换上一份备份，不要继续恢复。

## 4. 安装运行环境并恢复

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover install --yes
```

这条命令会写入 `/etc/xedc-recover-lab`，并安装 PHP 8.5、Nginx、MariaDB、Redis、ffmpeg、7z 和证书工具。它在发现 `/var/www/drupal/web/core` 时会直接退出。

生成一个新的数据库密码，只放在 `600` 文件里：

```bash
umask 077
python3 -c 'import secrets; print(secrets.token_hex(16))' > /root/xedc-restore-db.password
export XEDC_RESTORE_DB_PASSWORD="$(cat /root/xedc-restore-db.password)"
```

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover \
  --config /root/xedc-recover.json restore run \
  --backup-id BACKUP_ID \
  --target /var/www/drupal \
  --role replacement \
  --yes \
  --import-database \
  --db-name drupal \
  --db-user drupal \
  --trusted-host '^gitxx\.xyz$' \
  --trusted-host '^www\.gitxx\.xyz$'
```

旧服务器还活着、这只是演练时，使用 `--role lab`。lab 会改成本地文件存储，并移除恢复目录里的对象存储密钥，避免第二份网站写回生产 R2。

## 5. 配置 Web 并检查

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover ssl render \
  --server-name "gitxx.xyz www.gitxx.xyz" \
  --webroot /var/www/drupal/web \
  --prefix /tmp/xedc-nginx \
  --port 80 \
  --output /etc/nginx/nginx.conf
mkdir -p /tmp/xedc-nginx
nginx -t
chown www-data:www-data /var/www/drupal/private /var/www/drupal/web/sites/default/settings.php
chmod 440 /var/www/drupal/web/sites/default/settings.php
if id nginx >/dev/null 2>&1; then
  sed -i -E 's/^;*[[:space:]]*listen\.group[[:space:]]*=.*/listen.group = nginx/' /etc/php/8.5/fpm/pool.d/www.conf
  sed -i -E 's/^;*[[:space:]]*listen\.mode[[:space:]]*=.*/listen.mode = 0660/' /etc/php/8.5/fpm/pool.d/www.conf
fi
systemctl restart php8.5-fpm
systemctl enable --now php8.5-fpm mariadb redis-server nginx
systemctl reload nginx
```

`ssl render` 把 pid 和日志写到 `--prefix`。这个目录必须先存在，否则 `nginx -t` 会报 pid 文件无法打开。官方 Nginx 包的工作进程用户是 `nginx`，PHP-FPM 套接字默认只允许 `www-data` 连接，所以上面把 `listen.group` 改成 `nginx`。恢复程序会把代码和 public 设成目录 `755`、文件 `644`，把 private 设成目录 `750`、文件 `640`，把 `settings.php` 设成 `440`。

然后逐项看：

| 检查 | 做法 |
| --- | --- |
| 首页 | `curl -I -H 'Host: gitxx.xyz' http://127.0.0.1/` |
| 登录页 | 打开 `/user/login`，应出现登录表单 |
| 后台 | 使用原账号登录 `/admin`。正常恢复不会重置会员或管理员密码 |
| 用户和内容 | 对照 manifest 里的 `database.counts` |
| 图片 | 打开一条带图的内容，确认 public files 可访问 |
| 视频 | 确认 ffmpeg 存在：`ffmpeg -version` |
| 私有文件 | `settings.php` 的 `file_private_path` 指向新目录，且目录属主是 `www-data` |
| 数据库 | `mariadb -e 'SHOW TABLES' drupal` 能列出业务表 |
| 日志 | `tail /var/log/nginx/error.log` 和 Drupal watchdog |
| 证书 | 见 SSL 手册。演练使用自签证书；正式证书用 staging 计划 |
| 对象存储 | replacement 角色保留原配置。lab 角色强制本地文件，不会连接生产 R2 |

管理员进不去时，只在你明确要应急入口时执行：

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover credentials reset-admin --reset-admin --json
```

生产网站目录仍在时，这条命令会被拒绝。

## 6. 失败时

不要删掉数据库重装 Drupal。保留 `/var/www/drupal/RESTORE_REPORT.json` 和命令输出，按 [TROUBLESHOOTING.md](TROUBLESHOOTING.md) 处理。换一份通过 `verify` 的备份再恢复。
