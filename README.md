# 网站备份和恢复

用 root 执行。把命令里的 `新域名` 换成真实域名。网站在 `/var/www/drupal`。恢复工具放在 `/opt/xedc/drupal`。

克隆仓库只下载恢复工具和密钥。网站、数据库、图片来自备份包。

当前仓库是私有的：<https://github.com/gmliy/drupal>。私有地址是 `git@github.com:gmliy/drupal.git`。

## 快速备份

在现在的网站服务器上执行。一条命令完成备份，并上传到 R2：

```bash
sudo /var/www/drupal/xedc-recovery/backup.sh --upload-r2
```

只备份、先不上传：

```bash
sudo /var/www/drupal/xedc-recovery/backup.sh
```

备份成功时，`/var/backups/xedc/时间戳/STATUS` 里面是 `PASS`。上传成功时，日志里有 `result=PASS`：

```bash
sudo cat /var/backups/xedc/20261105T214600Z/STATUS
sudo grep result= /var/log/xedc-recovery/upload-xedc-migration-20261105T214600Z.log
```

把时间戳换成 `ls /var/backups/xedc` 里看到的那一份。

## 新服务器单独拉取工具

只把恢复工具下载到 `/opt/xedc/drupal`，不恢复网站，不下载 R2 备份。私有和公有各用一组命令。

### 私有拉取

新服务器要先拿到 Deploy Key 并保存，再执行后面的命令。保存位置固定是 `/root/.config/xedc-recovery/github-deploy.key`。

从旧服务器上传后保存。旧服务器执行：

```bash
sudo scp /root/.config/xedc-recovery/github-deploy.key root@新服务器:/root/github-deploy.key
```

新服务器执行，把钥匙保存好：

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /root/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
```

或者用令牌把仓库里的钥匙拉下来再保存。把 `令牌` 换成实际令牌，不要写进仓库。新服务器执行：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse https://x-access-token:令牌@github.com/gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /opt/xedc/drupal/xedc-recovery/secure/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo git -C /opt/xedc/drupal remote set-url origin git@github.com:gmliy/drupal.git
```

钥匙保存好之后，用这把钥匙拉取工具。仓库还没克隆时：

```bash
sudo GIT_SSH_COMMAND="ssh -i /root/.config/xedc-recovery/github-deploy.key -o IdentitiesOnly=yes" \
  git clone --depth 1 --filter=blob:none --sparse git@github.com:gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
```

已经克隆过，只更新工具：

```bash
sudo GIT_SSH_COMMAND="ssh -i /root/.config/xedc-recovery/github-deploy.key -o IdentitiesOnly=yes" \
  git -C /opt/xedc/drupal pull --ff-only
```

钥匙保存好之后要恢复网站，再执行：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

### 公有拉取

仓库是 Public 时，新服务器不需要 Deploy Key，也不需要令牌：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse https://github.com/gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo git -C /opt/xedc/drupal pull --ff-only
```

仓库现在是 Private。没登录 GitHub 时，这组 `https://github.com/gmliy/drupal.git` 命令会失败。改成 Public 之后再执行。

## 快速恢复

新服务器二选一。现在仓库是私有的，用第一节。仓库改成 Public 之后，用第二节的 HTTPS 命令。

### 私有仓库

新服务器一开始没有 `/root/.config/xedc-recovery/github-deploy.key`。直接执行 `git clone git@github.com:gmliy/drupal.git` 会失败，因为私有仓库不认没有钥匙的机器，而这把钥匙又在仓库里面。

下面两种做法都能恢复。不要在新服务器执行 `recover.sh --init-deploy-key`，那会另造一把 GitHub 不认识的钥匙。

#### 把旧服务器的钥匙上传到新服务器

钥匙已经在旧服务器上，路径是 `/root/.config/xedc-recovery/github-deploy.key`。在旧服务器执行，把它传到新服务器：

```bash
sudo scp /root/.config/xedc-recovery/github-deploy.key root@新服务器:/root/github-deploy.key
```

然后在新服务器执行。先把钥匙放到固定位置，再用这把钥匙克隆仓库并恢复：

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /root/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo GIT_SSH_COMMAND="ssh -i /root/.config/xedc-recovery/github-deploy.key -o IdentitiesOnly=yes" \
  git clone --depth 1 --filter=blob:none --sparse git@github.com:gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

最后一条会安装 age 私钥和 R2 凭据，核对 Deploy Key，从 R2 下载最新备份并恢复网站。

#### 用 GitHub 令牌克隆后再恢复

新服务器没有这把钥匙时，用能读取 `gmliy/drupal` 的令牌先把钥匙拉下来并保存。把 `令牌` 换成实际令牌，不要写进仓库：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse https://x-access-token:令牌@github.com/gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /opt/xedc/drupal/xedc-recovery/secure/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo git -C /opt/xedc/drupal remote set-url origin git@github.com:gmliy/drupal.git
```

钥匙保存到 `/root/.config/xedc-recovery/github-deploy.key` 之后，再执行恢复：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

这条命令会安装 age 私钥和 R2 凭据，核对已经保存的 Deploy Key，再从 R2 下载最新备份并恢复网站。

指定某一份备份时，两种做法都把最后的恢复命令换成：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --backup 20261105T214600Z --domain 新域名
```

### 公有仓库拉取

仓库是 Public 时，新服务器用 HTTPS 拉取，不需要先放 Deploy Key：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse https://github.com/gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo git -C /opt/xedc/drupal pull --ff-only
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

只要更新工具：

```bash
sudo git -C /opt/xedc/drupal pull --ff-only
```

仓库现在是 Private。没登录 GitHub 时，上面的 `https://github.com/gmliy/drupal.git` 会失败。改成 Public 之后再执行这组命令。

## 恢复后怎么查看密码

恢复结束后，在新服务器执行：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --show-credentials
```

如果人还在解压出来的备份目录里：

```bash
sudo ./restore.sh --show-credentials
```

屏幕上会显示 `/root/.drupal-credentials`。这个文件只有 root 能看。

| 屏幕上的一行 | 是什么 |
| --- | --- |
| `url` | 网站地址 |
| `admin_user` | 后台管理员账号 |
| `admin_password` | 后台密码。写成「与原站一致」时，还是原来的管理员密码 |
| `database` | 数据库名 |
| `database_user` | 数据库账号 |
| `database_password` | 新服务器的数据库密码。默认是这次恢复新生成的 |
| `database_host` | 一般是 `localhost` |
| `database_port` | 一般是 `3306` |

要换一个新的后台密码。命令会要求输入 `yes`。新密码写进上面的文件，不在屏幕上显示。然后再看一次：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --reset-admin --domain 新域名
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --show-credentials
```

要一条点开就能进入后台的临时链接：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --login-link --domain 新域名
```

## 私有仓库怎么配置 GitHub 密钥

这把钥匙只用来拉取恢复工具，不能改仓库。它和加密备份的 age 私钥不是同一把。

这把钥匙已经固定，并且已经加到 `gmliy/drupal` 的 Deploy keys，只允许读取。私钥在旧服务器和仓库的这两个位置，内容相同：

```text
/root/.config/xedc-recovery/github-deploy.key
xedc-recovery/secure/github-deploy.key
```

备份时会把旧服务器上的这一把写进仓库副本，并放进 R2 加密包。新服务器可以先从旧服务器拷来这一把再克隆，也可以用令牌先克隆，再由恢复命令把它装到本机。两种做法都不会另造一把钥匙。

`recover.sh --init-deploy-key` 只在这台机器上还没有私钥文件时才会生成新钥匙。现在不要执行它。

以后更新私有仓库里的工具：

```bash
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

`--dry-run` 只安装密钥并列出 R2 上的备份，不下载，也不恢复网站。

## 公有仓库恢复

和上面的公有拉取是同一组命令。仓库成为 Public 之后，新服务器从这里开始：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse https://github.com/gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --domain 新域名
```

拉下来的目录里如果带有 `xedc-recovery/secure/`，这条恢复命令会安装 age 私钥、R2 凭据和 Deploy Key，再从 R2 下载最新备份并恢复。

指定备份：

```bash
sudo /opt/xedc/drupal/xedc-recovery/recover.sh --backup 20261105T214600Z --domain 新域名
```

## 备份文件是什么

备份写在 `/var/backups/xedc`。传给新服务器或上传到 R2 的是这三份，要一起走：

| 文件 | 干什么用 |
| --- | --- |
| `xedc-migration-时间戳.tar.zst` | 备份包本体 |
| `xedc-migration-时间戳.tar.zst.sha256` | 校验码。对不上就不要恢复 |
| `xedc-migration-时间戳.manifest.public.json` | 不含密码的清单，用来确认包没有下错 |

包里面有数据库、网站文件、R2 上的媒体副本、站点代码，以及加密的 `secrets.enc`。age 私钥不在包里，在仓库的 `xedc-recovery/secure/age.key`。不要重新生成这把私钥，否则旧备份解不开。

查看 R2 上有哪些备份：

```bash
sudo /var/www/drupal/xedc-recovery/download.sh --source=r2 --list
```

已经有备份包、只上传、不重新备份：

```bash
sudo /var/www/drupal/xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst
```

## 旧服务器直接把备份拷到新服务器

在旧服务器执行。三个文件都要传：

```bash
scp /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst root@新服务器:/root/
scp /var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst.sha256 root@新服务器:/root/
scp /var/backups/xedc/xedc-migration-20261105T214600Z.manifest.public.json root@新服务器:/root/
```

新服务器先拿到恢复工具。用旧服务器的钥匙时，先在旧服务器执行 `sudo scp /root/.config/xedc-recovery/github-deploy.key root@新服务器:/root/github-deploy.key`，再在新服务器执行：

```bash
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /root/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo GIT_SSH_COMMAND="ssh -i /root/.config/xedc-recovery/github-deploy.key -o IdentitiesOnly=yes" \
  git clone --depth 1 --filter=blob:none --sparse git@github.com:gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

或者用令牌先把钥匙拉下来保存，再执行后面的命令。把 `令牌` 换成实际令牌：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse https://x-access-token:令牌@github.com/gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo install -d -m 700 /root/.config/xedc-recovery
sudo install -m 600 /opt/xedc/drupal/xedc-recovery/secure/github-deploy.key /root/.config/xedc-recovery/github-deploy.key
sudo git -C /opt/xedc/drupal remote set-url origin git@github.com:gmliy/drupal.git
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

公有仓库改用：

```bash
sudo git clone --depth 1 --filter=blob:none --sparse https://github.com/gmliy/drupal.git /opt/xedc/drupal
sudo git -C /opt/xedc/drupal sparse-checkout set xedc-recovery
sudo /opt/xedc/drupal/xedc-recovery/bootstrap-recover.sh --dry-run
```

然后在新服务器核对并恢复：

```bash
cd /root
sha256sum -c xedc-migration-20261105T214600Z.tar.zst.sha256
sudo apt-get update
sudo apt-get install -y zstd
tar --zstd -xf xedc-migration-20261105T214600Z.tar.zst
cd xedc-migration-20261105T214600Z
sudo ./restore.sh --mode=full --domain 新域名
```

`sha256sum` 显示 `OK` 再解压。新机器是空的，用 `--mode=full`。机器上已经有网站或同名数据库时，用 `--mode=restore`，它会停下来，不会覆盖。

看密码：

```bash
sudo ./restore.sh --show-credentials
```

## 恢复完检查一下

```bash
php -v
cd /var/www/drupal && ./vendor/bin/drush --uri=https://新域名 status --fields=bootstrap,drupal-version,db-status
curl -sk --resolve 新域名:443:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://新域名/
```

PHP 要是 8.5。Drush 显示 bootstrap 成功、数据库已连接。首页返回 `200`。

证书还没签好时：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --ssl-status
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --retry-ssl --domain 新域名
```

恢复出错想退回新服务器上恢复前的副本：

```bash
sudo /opt/xedc/drupal/xedc-recovery/restore.sh --rollback
```

## 做的时候记住

- 新网站确认能打开之前，保留旧服务器，也保留 `/var/backups/xedc` 里的备份包。
- 恢复不会改公网 DNS。网站正常后，再把域名指到新服务器。
- 域名还没指过来时，证书是临时的。指过来之后执行上面的 `--retry-ssl`。
- 邮件密码在加密备份里。邮箱服务商那边的授权要仍然有效。
- 新服务器安装 PHP 8.5 和 MariaDB 12。装不到 PHP 8.5 会停止。
- 支持 Ubuntu 20.04、22.04、24.04、26.04、26.10 和 Debian 11、12、13。
