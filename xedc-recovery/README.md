# xedc-recovery

这是 v2 的备份和恢复实现。命令从本目录的 `backup.sh` 和 `restore.sh` 进入。产物在 `/var/backups/xedc`，状态在 `/var/lib/xedc-recovery`，日志在 `/var/log/xedc-recovery`。这些目录都不进 Git。

下面每节只写代码里已经存在的行为。没跑过的测试会写明。

## 1. 备份

```bash
sudo xedc-recovery/backup.sh
sudo xedc-recovery/backup.sh --dry-run
sudo xedc-recovery/backup.sh --output /var/backups/xedc
```

每次运行新建一个 UTC 时间戳目录。自检失败时退出处理会删除未完成目录。成功目录保留 `STATUS=PASS`。默认保留策略留下最近的成功快照，并且不删除最后一份 PASS。

快照包含数据库压缩包、公共文件、私有文件、脱敏项目源码、服务配置、媒体信息、GitHub 软件清单、`manifest.json`、SHA256 和 `secrets.enc`。本机成功快照是 `/var/backups/xedc/20261104T061746Z`。

`xedc-recovery/contrib/systemd/` 里有 03:00 和 15:00 的 timer。生产机没有 enable。生产 crontab 里的 `/root/backup.sh` 没有被这个目录修改。

设置 `XEDC_OFFSITE_DEST` 后才会复制到另一个目录，复制内容不含 age 私钥。生产机没有设置它。单元测试只写到临时目录。

## 2. 恢复

在干净机器上，脚本先安装 `jq`、`zstd`、`age` 和 `python3`，再读清单。来源机器的 machine-id 哈希与本机相同，且不是测试开关时，恢复会拒绝执行，避免把快照倒回生产机。

之后的顺序是：基础软件包、PHP、项目源码、Composer、解密密钥、数据库、公共和私有文件、`settings.local.php`、Nginx、证书、可选的配套服务、媒体冒烟、域名替换、Drush、凭据文件、HTTP 检查和对账。某一步已经记在状态文件里时，重复恢复会跳过它。

没有 systemd 时，MariaDB、Nginx、PHP-FPM 和 Redis 由 service 或直接启动。Ubuntu 24.04 容器里这样启动过。

夹具不是 Drupal。那次恢复安装了空的 Composer 项目，并明确跳过了不存在的 Drush。不能把夹具 HTTP 200 当成 Drupal 恢复成功。

## 3. 参数

`restore.sh --help` 会打印参数。常用的有：

- `--snapshot`、`--domain`、`--old-domain`、`--new-domain`
- `--php-version`，默认 8.5
- `--versions balanced|pinned|latest`
- `--force`，目标目录已存在时先做回滚快照再覆盖
- `--dry-run`
- `--keep-db-credentials` 沿用快照里的数据库密码；默认生成新密码
- `--dns-provider`、`--dns-provider list`、`--dns-credentials`、`--dns-manual`、`--auto-dns-record`、`--wildcard`
- `--ssl-method`、`--retry-ssl`、`--renew-ssl`、`--ssl-status`
- `--reset-admin`、`--login-link`、`--show-credentials`
- `--change-domain`、`--compare`、`--rollback`
- `--with-matomo`、`--without-matomo`、`--with-netdata`、`--without-netdata`
- `--with-docker-services`、`--without-docker-services`、`--with-media`、`--without-media`
- `--step` 只跑某一步

DNS 凭据文件权限必须是 600 或 400。

## 4. 版本策略

`--versions` 接受 `balanced`、`pinned`、`latest`。PHP 目标默认 8.5。安装前会检查第三方源的 Release 文件；源不支持当前发行版时退回系统仓库并记 WARN。8.5 没有软件包而 8.4 有时，先运行 `composer why-not php 8.5`，再安装 8.4，并打印「当前版本 → 目标版本 → 实际安装版本」。两种都没有就停止，不会悄悄装另一个版本。

Ubuntu 24.04 容器的实测记录是 `8.3.6 → 8.5 → 8.5.12`。8.4 回退没有被触发。

GitHub 发布包没有 SHA256 时不会下载。`pinned` 仍会查询最新发布说明，还没有锁死到清单里的单个 tag。

## 5. 配套服务和媒体

备份收集 root 与 www-data 的 crontab、导入队列的 systemd 单元、Matomo compose（密码换成占位符）、Netdata 配置和 Docker 容器清单。Matomo 的 `matomo_db-data` 和 `matomo_matomo-app-data` 在容器继续运行时只读导出，清单写明不是事务一致快照。`xedc-recover-db-data` 不导出。

恢复会写回单元文件和 compose 模板。目标机没有 Docker 守护进程时只保存配置并 WARN，不把「未拉起」写成成功。嵌套 Docker 在测试容器里没有运行。

媒体安装缺少的 ffmpeg，并用一条无版权的 lavfi 样本做 libx264 和 aac 转码。夹具那次安装了 `6.1.1-3ubuntu5`，冒烟步骤没有告警。播放器页面没有单独验收。ImageMagick 和 yt-dlp 在生产清单里是不存在。

## 6. 更换域名

替换覆盖普通文本、序列化字符串和 JSON，并按新域名重写序列化长度。编码后的点保持 `%2E` 或 `%2e`。自定义对象原样复制。缓存、watchdog、sessions 等表跳过。

夹具使用 `old.example.test` 和 `newer.example.test`。替换后的字段可以 unserialize，库里不再有旧域名。失败时用替换前的压缩快照回滚；空快照会直接停止。失败回滚这一支还没有单独跑过。

`--change-domain` 依赖 `/var/lib/xedc-recovery/restore.cnf` 和已解密的 `db.json`。

## 7. SSL

没有指定方式时：域名已经指向本机公网地址用 HTTP-01；提供了 DNS 服务商用 DNS-01；否则安装 30 天自签名证书并进入等待。等待 timer 每 5 分钟一次，但只在 systemd 真的作为 init 运行时启用。容器里的 `systemctl` 不能用，脚本只提示事后执行 `--retry-ssl`。

DNS 服务商列表来自 `lib/acme-dns-providers.json`。`--dns-provider list` 会打印 Cloudflare、阿里云和 Hetzner 等变量名。通配符证书不使用 HTTP-01。ZeroSSL 失败不会被当成成功。acme.sh 固定到 3.1.6，并核对 SHA256。

证书机构签发、续期和 DNS 自动改 A 记录都还没有在测试里调用。夹具只验证了自签名等待模式。

## 8. 账户与密码

凭据文件是 `/root/.drupal-credentials`，权限 600。里面有新的数据库密码。管理员密码文字写的是与原站一致；恢复流程本身不改 uid 1。

`--reset-admin` 在交互里要求输入 yes，或设置 `XEDC_ASSUME_YES=1`。它通过 Drush 改密码。`--login-link` 也依赖 Drush。这两条都还没有在 Drupal 站点上执行。

数据库口令不放在命令行参数里。客户端使用权限 600 的 defaults 文件。

## 9. 一致性对账

`--compare` 和每次完整恢复结束都会写 `PARITY_REPORT.md`。相同、预期差异、需要用户处理和异常差异是分开的。只有异常差异让命令返回非零；恢复主流程把这个非零变成 WARN，不把异常藏掉。

现在比较的字段是 Drupal 版本、PHP、公共文件数、表数、ffmpeg 和对象数量。Drupal 版本缺失不会拿 PHP 版本冒充。对象存储未使用且两边都没有数量时记 SKIP。

夹具二次恢复要求异常差异为 0。这不是完整对账。模块、主题、逐表行数、用户数、节点数、Composer 包、Nginx、PHP 扩展、systemd、cron 和编码器清单还没有采集。

## 10. 常见问题

内存或磁盘不足时，备份和测试脚本会停止。测试容器限制是 4G 内存和 2 个 CPU。宿主机可用内存低于约 1.5GB 或根分区剩余低于 8GB 时，测试脚本不启动容器。

Composer 使用系统包或已验证的安装脚本。Ubuntu 24.04 的发行版 Composer 在 PHP 8.5 上会打印弃用提示；夹具项目没有依赖，安装仍然退出码 0。真实 Drupal 项目还没有在干净机器上执行 `composer install`。

GitHub 限流重试写在下载逻辑里，还没有用 mock 跑过。PHP 8.5 不可用时的 8.4 回退也还没有跑过。

DNS 没生效时保持自签名，不把等待模式报成正式证书。云安全组未放行 80/443 时，脚本只提示。

SMTP 密码不进未加密项目包。恢复不会发真实邮件。

文件权限：备份和状态目录 700，凭据和密钥 600。公共文件和私有文件恢复后属主改为 www-data。

数据库事实优先用 `mariadb-dump`。夹具没有连接生产数据库。生产快照的服务器版本是 MariaDB 13.0.2。

没有 Docker 时，配套服务恢复停止在配置和 WARN。断点续跑依赖状态文件；已完成的数据库步骤不会再次导入。中途杀进程后的续跑还没有单独测试。

## 11. 回滚

覆盖已有站点前，脚本记录站点文件的 SHA256，并把目录移到回滚目录。若要覆盖的数据库已经存在，会先导出。写回之后核对哈希。没有可核对的内容，或哈希不一致，`ROLLBACK_STATUS` 是 FAIL。

单元测试覆盖了两件事：临时目录里的文件能回到旧内容；空回滚目录不能标成 PASS。运行中的 Nginx 配置和数据库回滚还没有执行。

```bash
sudo xedc-recovery/restore.sh --rollback
```

## 12. Docker 验证

隔离测试：

```bash
bash xedc-recovery/tests/integration/build-fixture.sh
bash xedc-recovery/tests/integration/restore-docker.sh 快照目录 age私钥
```

构建夹具时设置 `XEDC_SQL_DUMP_FILE` 和 `XEDC_SKIP_DOCKER=1`，不读取生产库，不导出生产卷。恢复容器只挂载夹具快照、夹具私钥和本目录。

已通过的是 Ubuntu 24.04 上的一次全新恢复加一次重复恢复。A 级还有中断续跑、管理员重置、HTTP-01、DNS-01、低内存、PHP 8.4 回退等场景没跑。B 级的 Ubuntu 22.04、26.04、26.10 和 Debian 12 没跑。连续两轮也没跑。官方镜像拿不到时要记 BLOCKED，不能换一个镜像还说原来的系统通过了。

单元测试：

```bash
bash xedc-recovery/tests/unit/shell_functions.sh
php xedc-recovery/tests/unit/domain_replace_test.php
php xedc-recovery/tests/unit/object_inventory_test.php
```

## 13. 新增软件

再跑一次 `backup.sh` 会重新采集包清单、ffmpeg、crontab、systemd 单元和 Docker 容器列表。它不能发现手动编译进非标准路径的程序、备份范围以外的数据目录，或没有写进站点配置的外部 API。这些要单独放进恢复说明。

## 14. 公开仓库

可以提交的是脚本、测试和文档。不可以提交备份、数据库转储、压缩包、age 私钥、对象存储密钥、日志和 `.MIGRATION_DONE`。

历史提交里的数据库转储、`settings.php` 中的数据库密码和 hash salt，以及 SMTP 配置中的密码，仍然可以从公开历史读到。处理方式是轮换这些凭据，而不是改写 Git 历史。轮换尚未验证。新的成功快照不再把这些值放进未加密包。

提交前对暂存差异执行 `python3 xedc-recovery/tests/scripts/secret-scan.py`。它不打印命中的原值。
