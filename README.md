# Drupal 站点备份与恢复

备份是一个不加密的 `tar.zst`。新服务器不需要解密口令。恢复会安装 nginx、PHP、MariaDB、Redis、Node、ffmpeg、Docker、Netdata 和 Certbot，建库导数据，还原代码和文件，按新域名改配置，并处理 HTTPS。

过程中你只做两件事：输入 R2 凭据，看到软件清单后输入 `y`。这两件事都在进入后台会话之前完成。默认安装 PHP 8.5，要用别的版本时在恢复命令末尾加 `--php 8.4`。软件源里没有这个版本会直接停止，不会改用更低版本。

## 一、备份（旧服务器）

在站点服务器上，进入已检出的仓库：

```bash
cd /var/www/drupal
```

备份到本地：

```bash
bash xedc-recovery/backup.sh --target local
```

备份到 R2：

```bash
bash xedc-recovery/backup.sh --target r2
```

同时备份到本地和 R2：

```bash
bash xedc-recovery/backup.sh --target both
```

第一次配置 R2，并可选每天 07:00 和 19:00 自动备份。日志在 `/var/log/xedc-backup.log`。

```bash
bash xedc-recovery/backup.sh --setup-r2
```

凭据写在 `/root/.config/xedc-recovery/r2.env`，权限 600，不会进入 Git。字段见 `xedc-recovery/r2.env.example`。

列出备份：

```bash
bash xedc-recovery/backup.sh --list
```

本地目录是 `/var/backups/xedc/`，目录权限 700，文件权限 600。默认保留本地 7 份、R2 30 份。

数据库里的 `cache`、`cache_*`、`cachetags`、`watchdog`、`sessions`、`flood`、`semaphore` 只保存表结构，不保存数据。表前缀从 `settings.php` 读取，不写死。`queue` 里的待处理任务仍会备份。已经打好的旧包里如果还带着这些表的数据，恢复时会自动丢掉，不会因为重复的缓存主键中断。

## 二、一条命令恢复（新服务器，全新空系统）

先创建 GitHub 只读令牌：打开 GitHub → Settings → Developer settings → Fine-grained tokens。只勾选仓库 `gmliy/drupal`，权限只给 Contents: Read-only。下面命令里的 `xxxx` 换成这个令牌。

从 R2 最新备份恢复：

```bash
# 把 xxxx、新域名、邮箱换成你的值
GH_TOKEN=xxxx bash <(curl -fsSL -H "Authorization: token xxxx" https://raw.githubusercontent.com/gmliy/drupal/main/xedc-recovery/install.sh) --from r2:latest --domain 新域名 --email 邮箱
```

从本机已经放好的备份文件恢复：

```bash
GH_TOKEN=xxxx bash <(curl -fsSL -H "Authorization: token xxxx" https://raw.githubusercontent.com/gmliy/drupal/main/xedc-recovery/install.sh) --from local:/root/drupal-backup-YYYYmmddTHHMMSS.tar.zst --domain 新域名 --email 邮箱
```

指定某一份 R2 备份。时间版本就是文件名里的 `YYYYmmddTHHMMSS`：

```bash
GH_TOKEN=xxxx bash <(curl -fsSL -H "Authorization: token xxxx" https://raw.githubusercontent.com/gmliy/drupal/main/xedc-recovery/install.sh) --from r2:20261106T120000 --domain 新域名 --email 邮箱
```

上一份完整备份：

```bash
GH_TOKEN=xxxx bash <(curl -fsSL -H "Authorization: token xxxx" https://raw.githubusercontent.com/gmliy/drupal/main/xedc-recovery/install.sh) --from r2:latest-1 --domain 新域名 --email 邮箱
```

询问结束之后会自动进入 tmux 会话，进度直接显示在当前终端。退出查看但不停止恢复：按 `Ctrl-b` 然后按 `d`。SSH 断了之后重新登录：

```bash
tmux attach -t xedc-restore
```

日志在 `/var/log/xedc-restore.log`，权限 600。查看用 `cat` 或 `tail -f`：

```bash
cat /var/log/xedc-restore.log
tail -f /var/log/xedc-restore.log
```

失败时终端会打印退出码。数据库导入失败时只打印出错的表名和行号，不会把缓存里的 HTML 刷到终端。把原来的整条命令照抄一遍，在末尾加上 `--resume`。已经导入成功的库不会再导一次。没有交互终端时，域名、`--yes` 和 R2 环境变量要一次写全，脚本不会停下来等输入。

```bash
GH_TOKEN=xxxx bash <(curl -fsSL -H "Authorization: token xxxx" https://raw.githubusercontent.com/gmliy/drupal/main/xedc-recovery/install.sh) --from r2:latest --domain 新域名 --email 邮箱 --resume
```

域名要带 www 作为主站时，加上 `--www yes`。只要自签证书时加 `--https self`。先不要证书时加 `--https off`。PHP 版本用 `--php 8.4` 覆盖，默认 8.5。

Matomo 服务和 Drupal 模块都不恢复。旧备份里即使带有 Matomo，也不会还原容器、nginx 配置或证书。要临时保留模块（服务仍不恢复）：

```bash
bash /opt/xedc-recovery/restore.sh --from r2:latest --domain 新域名 --email 邮箱 --keep-matomo-module --yes
```

排除规则在 `xedc-recovery/exclude.list`，一行一个关键字。只打印服务器上的残留：

```bash
bash /opt/xedc-recovery/restore.sh --clean-excluded
```

确认后真正删除：

```bash
bash /opt/xedc-recovery/restore.sh --clean-excluded --yes
```

已经恢复出来、又想手工卸掉模块：

```bash
drush pm:uninstall matomo matomo_reports -y
drush config:delete matomo.settings -y
composer remove drupal/matomo drupal/matomo_reports
drush cr
```

## 三、恢复前准备

1. 把新域名的 DNS 指到新服务器。指好之后才能申请正式证书；还没指好也会继续，先用自签证书。
2. 准备上一节的只读 `GH_TOKEN`。
3. 准备 R2 的 Account ID、Access Key、Secret、桶名和前缀。Secret 输入时不会显示。
4. 确认桶是私有的。媒体文件在桶里，不在备份包里，恢复后继续用同一个桶。

## 四、恢复时每一步做什么

1. 安装 git、curl、tmux 和官方 rclone，取回工具。
2. 下载备份并校验 sha256。校验失败会改用上一份带 `.complete` 的备份。
3. 列出要安装的软件，等你输入 `y`。测试可以加 `--yes` 跳过。
4. 安装并配置 nginx、PHP、MariaDB、Redis、Composer、Node、ffmpeg、7z、Docker、Netdata、Certbot。
5. 用随机密码建库建用户。目标库里已经有表时，先删除再导入。导入前去掉缓存表、`cachetags`、`watchdog`、`sessions`、`flood`、`semaphore` 的数据行。失败时只打印表名、行号和一行错误，不会把缓存里的 HTML 刷到终端。导入成功后会记下状态。
6. 还原代码和文件，执行 `composer install --no-dev`，构建主题，权限交给 `www-data`。
7. 改 `settings.php` 里的数据库、域名和 Redis，保留原来的 hash salt。
8. 还原 cron、systemd、Docker Compose 和 Netdata 配置。
9. 执行 `drush updb`、`cim`、`cr`。
10. 按 DNS 申请证书或改用自签，然后自检。

已有站点或数据库时默认停止。确认要覆盖时加 `--force`，覆盖前会先导出旧数据库。

## 五、恢复后检查

- DNS 是否已经指向新机器，浏览器证书是不是正式证书。
- Cloudflare 代理时，SSL 模式用 Full (strict)。
- 打开 Netdata，重新 claim。旧令牌不会带走。
- 看邮件 SPF、DKIM，以及 Google、Telegram 等回调地址是否改成新域名。
- 看定时任务是否在跑，`/var/log/xedc-backup.log` 有没有新备份。
- 抽查一个视频页面和 `/sitemap.xml`。

## 六、目录

```text
xedc-recovery/install.sh      新服务器的引导脚本
xedc-recovery/backup.sh       备份
xedc-recovery/restore.sh      恢复
xedc-recovery/lib/            每个软件一个模块
xedc-recovery/templates/      nginx、PHP、MariaDB、Redis 模板
xedc-recovery/manifest.json   当前站点的恢复清单，不含密钥
xedc-recovery/SPEC.md         验收标准
xedc-recovery/REPORT.md       测试结果和限制
xedc-recovery/tests/          测试与依赖发现报告
```

单独安装某个组件：

```bash
bash xedc-recovery/lib/mariadb.sh install
```

## 七、常见问题

**R2 提示没有完整备份。** 上传中断的包没有 `.complete`，不会被当成最新。等下一份备份完成，或用 `bash xedc-recovery/restore.sh --list-r2` 看哪一份是完整的。

**证书是自签的。** 域名还没指到这台机器。DNS 生效后执行：

```bash
certbot --nginx -d 新域名 -d www.新域名 --email 邮箱
```

**PHP 不是 8.5。** 恢复只安装清单里的版本，默认 8.5。软件源里没有这个版本会停止，不会改用 8.3 或 8.4。要临时指定别的版本：

```bash
bash xedc-recovery/restore.sh --from r2:latest --domain 新域名 --email 邮箱 --php 8.4 --yes
```

**恢复到一半断了。** `tmux attach -t xedc-restore` 看画面。确认进程没了之后，用原来的命令加 `--resume`。已成功的步骤不会重做，已经导入成功的数据库也不会再导一次。你亲手改过的配置，哈希和脚本上次写入的不同，会保留并提示，不会被备份覆盖。失败时看 `cat /var/log/xedc-restore.log`。数据库导入失败时，日志里是表名和行号。

**想先看系统是否正常，又不想改任何东西。**

```bash
bash /opt/xedc-recovery/restore.sh --verify --domain 新域名
```

## 八、支持的系统

Ubuntu 22.04、24.04、26.04、26.10 和 Debian 12。测试矩阵、耗时和 Docker 里无法验证的项目写在 `xedc-recovery/REPORT.md`。镜像拉不下来的系统记为跳过，不算通过。

## 九、用 iPhone 的 SSH 时

整条命令一次粘贴，不要拆开。恢复开始后如果画面不动，先断开，再执行：

```bash
tmux attach -t xedc-restore
```

看日志。文件权限是 600：

```bash
tail -n 50 /var/log/xedc-restore.log
```

或：

```bash
cat /var/log/xedc-restore.log
```
