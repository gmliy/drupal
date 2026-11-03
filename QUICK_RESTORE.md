# 备份与恢复操作说明

按顺序做。命令都可以直接复制。把尖括号里的内容换成你自己的值，例如 `<新服务器IP>`、`<新域名>`。

自动安装只支持 Ubuntu 24.04。Ubuntu 26.04 会直接停止，并显示 BLOCKED。不要在 Ubuntu 26.04 上继续手工添加 PHP 软件源。

生产网站还在这台机器上时，不要运行 `xedc-restore`。恢复必须在另一台机器上做。

## A. 旧服务器备份

安装工具后执行：

```bash
xedc-backup
```

程序会检查系统和磁盘，导出数据库，打包 Drupal 代码、public 文件、private 文件和恢复所需配置，计算 SHA256，写 manifest，保存到本机，再上传到 R2。上传后会重新下载并核对 SHA256。

完成后看这几行：

- 编号，例如 `xedc-20261103T183111Z-18ce30`
- 大小
- SHA256
- 本地路径，在 `/var/lib/xedc-recover/backups/`
- R2 路径，在 `xedc-recover/packages/`
- 状态是“备份完成”，并且 R2 校验是 PASS

只想留在本机、不联系 R2：

```bash
xedc-backup --local-only
```

查看已经做好的备份：

```bash
xedc-backup list
```

如果最后写着“本地备份成功，R2 上传失败”，本机文件仍然在。看完失败原因后执行：

```bash
xedc-backup upload --backup 备份编号
```

不要删除那份本地文件，也不要重新打包。

## B. 新服务器从 R2 恢复

新机器使用 Ubuntu 24.04。先安装工具：

```bash
sudo apt-get update
sudo apt-get install -y git python3 python3-cryptography ca-certificates zstd
git clone -b feature/migration-v2 --depth 1 https://github.com/xxedc/drupal.git /opt/xedc-drupal
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-backup /usr/local/bin/xedc-backup
sudo ln -sfn /opt/xedc-drupal/tools/xedc-recover/bin/xedc-restore /usr/local/bin/xedc-restore
```

然后直接运行：

```bash
xedc-restore
```

程序会依次询问：

1. R2 Access Key
2. R2 Secret
3. R2 Endpoint，形如 `https://账户编号.r2.cloudflarestorage.com`
4. Bucket，当前生产桶是 `dclili`
5. 从清单里选择备份编号
6. 新网站域名
7. 输入 `yes` 确认

它会说明：这次按测试恢复，网站装到 `/srv/xedc`，会安装 Nginx、PHP 8.5 和 MariaDB，不会修改 Cloudflare DNS，也不会删除旧备份。

前缀固定是 `xedc-recover/`，不需要填写。

不需要自己解压，也不需要自己导入 SQL。

已经知道编号时，可以不进入问答，直接执行：

```bash
export XEDC_S3_ACCESS_KEY="从密码管理器读取"
export XEDC_S3_SECRET_KEY="从密码管理器读取"
xedc-restore --source r2 --backup xedc-20261103T183111Z-18ce30 --domain <新域名> --role lab --yes
```

如果这台机器还没有保存过 R2 地址，先写一份不含密钥的配置 `/var/lib/xedc-recover/config.json`：

```json
{
  "remote": {
    "endpoint": "https://账户编号.r2.cloudflarestorage.com",
    "bucket": "dclili",
    "region": "auto",
    "prefix": "xedc-recover/"
  }
}
```

然后：

```bash
xedc-restore --config /var/lib/xedc-recover/config.json --source r2 --backup xedc-20261103T183111Z-18ce30 --domain <新域名> --role lab --yes
```

旧的加密备份会再要一次原来的 `primary.key`。新备份不会要这把钥匙。

## C. 旧服务器直接传到新服务器

不想经过 R2 时，用这个办法。

### 新服务器先准备接收目录

在新服务器上查看自己的 IP：

```bash
ip -4 addr show scope global
```

也可以从云服务商的控制台看公网 IP。

创建目录：

```bash
sudo mkdir -p /var/lib/xedc-recover/backups
sudo chown root:root /var/lib/xedc-recover/backups
sudo chmod 700 /var/lib/xedc-recover/backups
```

### 旧服务器发送

默认 SSH 端口 22、使用当前用户的默认密钥：

```bash
scp /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst.sha256 \
    root@<新服务器IP>:/var/lib/xedc-recover/backups/
```

把文件名换成 `xedc-backup list` 里看到的实际文件。编号 `xedc-20261103T183111Z-18ce30` 对应的文件名是：

```text
xedc-full-20261103T183111Z-18ce30.tar.zst
xedc-full-20261103T183111Z-18ce30.tar.zst.sha256
```

SSH 不是 22 端口时，加上大写 `-P`：

```bash
scp -P <端口> \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst.sha256 \
    root@<新服务器IP>:/var/lib/xedc-recover/backups/
```

指定密钥：

```bash
scp -i ~/.ssh/id_ed25519 -P <端口> \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst.sha256 \
    root@<新服务器IP>:/var/lib/xedc-recover/backups/
```

传输中断后，不要换文件名。在旧服务器上重新执行同一条 `scp`，它会覆盖新服务器上未传完的文件。

如果希望从中断处继续，而不是整份重传，改用：

```bash
rsync -a --partial --progress -e "ssh -p <端口> -i ~/.ssh/id_ed25519" \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst.sha256 \
    root@<新服务器IP>:/var/lib/xedc-recover/backups/
```

`ssh` 和 `rsync` 的端口是小写 `-p`。`scp` 的端口是大写 `-P`。

### 新服务器核对并恢复

```bash
sudo chmod 600 /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst \
    /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst.sha256
cd /var/lib/xedc-recover/backups
sudo sha256sum -c xedc-full-20261103T183111Z-18ce30.tar.zst.sha256
```

输出必须是 `OK`。不是 `OK` 就不要恢复，回到旧服务器重新传输。

核对通过后：

```bash
xedc-restore --file /var/lib/xedc-recover/backups/xedc-full-20261103T183111Z-18ce30.tar.zst --domain <新域名> --role lab --yes
```

## D. 恢复完成后检查网站

在新服务器上执行：

```bash
systemctl is-active nginx php8.5-fpm mariadb
curl -sI -H "Host: <新域名>" http://127.0.0.1/ | head -n 1
curl -s -H "Host: <新域名>" http://127.0.0.1/user/login | grep -o user-login-form
runuser -u www-data -- /srv/xedc/code/vendor/bin/drush --root=/srv/xedc/code/web status --uri=http://<新域名>
```

通过的标准：

- `nginx`、`php8.5-fpm`、`mariadb` 都是 `active`
- 首页第一行包含 `200` 或 `301`
- 登录页能看到 `user-login-form`
- Drush 能显示 Drupal 版本，并且数据库连接正常
- `/srv/xedc/code/web/modules/custom` 和 `/srv/xedc/code/web/themes/custom` 里有本站模块和主题
- `/srv/xedc/public` 里能找到图片和视频文件
- `/srv/xedc/private` 存在，并且其他用户不能读取
- `/srv/xedc/INSTALL_REPORT.json` 里的 `status` 是 `PASS`
- 报告里没有“数据库里有文件在磁盘上不存在”。首页能打开但文件对不上时，恢复仍然失败

用原来的管理员账号登录后台，打开一张图片和一个视频页。确认没问题后，再由人把域名指向这台服务器。不要让两台服务器同时往生产对象存储里写文件。

## E. 常见错误

磁盘空间不足。程序会在下载或解包前停止，并写明还需要多少空间。到根分区腾出空间后再执行同一条恢复命令。已经成功的本地备份不会被删掉。

`/tmp` 空间不足。大文件不会再解到 `/tmp`。如果仍看到 `No space left on device`，先看是不是根分区满了：`df -h / /var/lib/xedc-recover`。不要把 `TMPDIR` 指回小的 `/tmp` 来“修好”它。

R2 Access Key 或 Secret 错误。提示是“R2 拒绝访问”。回到密码管理器核对两把钥匙，Secret 不要多空格。

Endpoint 错误。提示是“连不上 R2”。地址必须以 `https://` 开头，主机名是 Cloudflare R2 的账户地址，不是网站域名。

Bucket 错误。提示是没有找到 Bucket 或备份。当前桶名是 `dclili`。备份编号必须和 `xedc-backup list` 或 R2 清单里的一致。

Ubuntu 软件源不支持。Ubuntu 26.04 会显示 BLOCKED 并停止。换 Ubuntu 24.04。不要把 24.04 的仓库地址改去给 26.04 用。

PHP 版本不兼容。恢复需要 PHP 8.5。安装结果不是 8.5 时，程序退出码是 5，并且不会继续导入网站。

MariaDB 启动失败。程序会停止，不会把网站标成已恢复。看 `journalctl -u mariadb --no-pager -n 50`。把磁盘和内存腾出来后，重新执行同一条 `xedc-restore`。

Nginx 配置失败。程序会停止。看命令输出里的 nginx 错误。不要手工把网站目录改到 `/tmp`。

数据库连接失败。Drush 或恢复报告会写明连接失败。确认 MariaDB 是 active，并且 `/var/lib/xedc-recover/restore-db.password` 还在。不要把生产库的口令拿来覆盖这份新口令。

恢复中断后如何重试。工作文件留在 `/var/lib/xedc-recover/work/`。先看失败原因并处理，然后重新执行刚才那条 `xedc-restore`。确认不再需要中间文件时，才用 root 删除提示里的那个目录：

```bash
sudo rm -rf /var/lib/xedc-recover/work/提示中的目录名
```

不要删除 `/var/lib/xedc-recover/backups/` 里的备份包，也不要删除 R2 上的旧对象。

退出码：`0` 成功，`1` 失败，`2` 命令用法不对，`3` 这台机器仍有生产网站，`4` 旧备份的解密密钥不对，`5` 操作系统或 PHP 不支持。
