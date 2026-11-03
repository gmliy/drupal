# 快速恢复手册

旧服务器不需要继续在线。GitHub 只提供恢复程序和代码版本，不保存数据库和媒体文件。

新机器需要：

- Ubuntu 24.04，x86_64，建议至少 4 核、8 GB 内存、40 GB 可用磁盘
- 完整恢复包 `xedc-full-YYYYMMDD.tar.zst` 和旁边的 `.sha256` 文件
- 单独保管的解密密钥，模式 `600`
- 如有对象存储，再准备访问密钥。密钥不要放进恢复包

恢复包可以来自 U 盘、另一台机器，或从 R2/S3 下载。本机已有文件时直接指定路径。

## 1. 确认这不是生产机

```bash
test ! -d /var/www/drupal/web/core && echo "可以继续"
```

上一行失败就停止。生产目录存在时，一键恢复会退出码 3。`--force` 不能跳过。

## 2. 取得恢复程序

恢复包里有 `tool/`。也可以只为了取得程序而克隆仓库：

```bash
apt-get update
apt-get install -y python3 ca-certificates
git clone https://github.com/xxedc/drupal.git /opt/drupal
cd /opt/drupal
git checkout feature/migration-v2
```

克隆结果不能代替恢复包。仓库里没有数据库，也没有 public/private 文件。

## 3. 放入密钥

```bash
install -m 600 /path/from/your-password-manager/primary.key /root/xedc-recover.key
cat > /root/xedc-recover.json <<'EOF'
{
  "backup_root": "/var/lib/xedc-recover/backups",
  "key_file": "/root/xedc-recover.key",
  "public_files": "web/sites/default/files",
  "private_files": "/srv/xedc/private"
}
EOF
chmod 600 /root/xedc-recover.json
umask 077
python3 -c 'import secrets; print(secrets.token_hex(16))' > /root/xedc-restore-db.password
export XEDC_RESTORE_DB_PASSWORD="$(cat /root/xedc-restore-db.password)"
```

不要把 `umask 077` 留到后面的安装过程。过严的 umask 会让 APT 读不到仓库公钥。

## 4. 从对象存储下载，或使用本地文件

下载到新目录。程序会核对 `.sha256`，并一并取回加密的凭据文件。解密密钥不会从对象存储下载。

```bash
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
python3 /opt/drupal/tools/xedc-recover/bin/xedc-restore \
  --config /root/xedc-recover.json fetch \
  --name latest \
  --output /var/lib/xedc-recover/packages
```

配置里的 `remote` 使用 `endpoint`、`bucket`、`region`、`prefix`、`access_key_env`、`secret_key_env`。本地已经有包时跳过这一步。

## 5. 一键恢复

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-restore \
  --config /root/xedc-recover.json install \
  --backup /var/lib/xedc-recover/packages/xedc-full-YYYYMMDD.tar.zst \
  --target /srv/xedc \
  --domain 新域名 \
  --yes \
  --role replacement
```

`--backup latest` 会在配置的 `backup_root/packages` 里按修改时间选择最新的 `xedc-full-*.tar.zst`。

旧站还在、这只是演练时，使用 `--role lab`。lab 会改成本地文件、关闭 CDN、暂停写入，并删除恢复目录里的对象存储密钥。

这条命令会：

1. 检查系统、磁盘和内存。可用磁盘需达到恢复包的 3 倍，可用内存低于 1 GB 时拒绝安装服务。
2. 核对恢复包 SHA256，再校验包内 manifest。
3. 用单独的密钥解密。
4. 安装 PHP 8.5、扩展、Nginx、MariaDB、Redis、Composer 所需的系统包和 ffmpeg。PHP 不是 8.5 时退出码 5。
5. 恢复代码、数据库、public、private 和 Drupal 配置。`hash_salt` 保持不变。
6. 把数据库主机、私有目录和 trusted host 改成这台机器的值。IP、主机名、域名和目录都可以与旧服务器不同。
7. 写好文件权限，配置 PHP-FPM 和 Nginx，并生成一份只在这台机器上使用的自签证书。
8. 检查登录页和 Drush。结果写在 `/srv/xedc/INSTALL_REPORT.json`。

同一份报告已经是 PASS、且 SHA256 相同，再次执行会直接返回，不会重写网站。要再做一遍完整恢复，先删除目标目录和旁边的 `.目标名.xedc-install.json`。目标里已经有 `web/core`、又没有这次安装留下的状态文件时，程序拒绝覆盖。

## 6. 域名和正式证书要人工确认

程序不会修改 Cloudflare DNS，也不会申请正式证书。自签证书在 `/etc/xedc-recover/ssl`，私钥模式 `600`。

确认网站在新机器上可用之后，再由人完成：

1. 在 Cloudflare 把域名指到新服务器的 IP。
2. 按 [SSL_GUIDE.md](SSL_GUIDE.md) 决定是否申请正式证书。`ssl plan` 只打印计划，不执行申请。
3. 确认 HTTPS 和回源都正常后，再停用旧服务器。

## 7. 检查

| 检查 | 做法 |
| --- | --- |
| 首页和登录页 | `curl -I -H 'Host: 新域名' http://127.0.0.1/`，`/user/login` 应有登录表单 |
| 自签 HTTPS | `curl -k -I --resolve 新域名:443:127.0.0.1 https://新域名/user/login` |
| 后台 | 用原账号登录 `/admin`。恢复不会重置会员或管理员密码。匿名访问应为 403 |
| 用户和内容 | 对照 manifest 的 `database.counts` |
| 图片和视频 | 打开带图的内容和视频页。public 文件应能访问 |
| 私有文件 | `file_private_path` 指向新目录，属主是 `www-data` |
| 对象存储 | lab 不会连接生产 R2。replacement 保留原配置，只在旧服务器已经不存在时使用 |
| Drush | `runuser -u www-data -- /srv/xedc/code/vendor/bin/drush status` |

## 8. 需要分步操作时

一键命令失败后，不要清空数据库再跑 Drupal 安装程序。可以继续使用原来的分步命令：`backup verify`、`restore run`、`ssl render`。步骤和权限见 [FULL_RESTORE.md](FULL_RESTORE.md)。

管理员进不去、并且明确需要应急入口时：

```bash
python3 /opt/drupal/tools/xedc-recover/bin/xedc-recover credentials reset-admin --reset-admin --json
```

生产网站目录仍在时，这条命令会被拒绝。
