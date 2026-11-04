# XEDC Drupal 自动化备份与跨服务器迁移系统 v2

这套工具在 `xedc-recovery/`。它把 Drupal 站点的数据库、文件、配置和密钥打成仓库外的快照，用 age 加密密钥，再在另一台机器上安装依赖并恢复。它要解决的是：换服务器时不用手抄数据库密码、文件目录、Nginx 和 PHP 版本。

仓库里还有一套已发布的工具 `tools/xedc-recover`。那套工具的新备份不做客户端加密，说明在 [legacy/README-xedc-recover.md](legacy/README-xedc-recover.md)。两套代码都保留。下面的命令只属于 `xedc-recovery/`。

当前没有创建 `.MIGRATION_DONE`。下面标成「未验收」的功能不能当成已经完成。

## 快速恢复

在旧服务器备份。快照写到 `/var/backups/xedc`，不进入 Git。

```bash
sudo xedc-recovery/backup.sh
```

在新服务器取得脚本。仓库是公开的。

```bash
git clone https://github.com/xxedc/drupal.git
```

在新服务器恢复。把快照目录和域名换成自己的。

```bash
sudo xedc-recovery/restore.sh --snapshot /var/backups/xedc/快照时间 --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

只换已经恢复过的站点的域名。这需要上次恢复留下的数据库配置。

```bash
sudo xedc-recovery/restore.sh --change-domain --old-domain 旧域名 --new-domain 新域名
```

查看恢复后写入的数据库凭据。不要把输出贴到工单或仓库。

```bash
sudo xedc-recovery/restore.sh --show-credentials
```

对照备份清单和当前机器，生成对账报告。

```bash
sudo xedc-recovery/restore.sh --compare --snapshot /var/backups/xedc/快照时间
```

把站点目录按回滚快照写回。校验失败时状态是 FAIL。

```bash
sudo xedc-recovery/restore.sh --rollback
```

## 准备条件

新机器需要是脚本认识的系统：Ubuntu 20.04、22.04、24.04、26.04、26.10，或 Debian 11、12、13。代码会拒绝这份名单以外的系统。到目前为止，干净系统上的恢复只在 Ubuntu 24.04 容器里跑过。

事先准备：

- 一份 `STATUS` 为 PASS 的快照目录，以及备份时的 age 私钥。默认私钥是 `/root/.config/xedc-recovery/age.key`，权限 600。公钥在备份根目录的 `age.pub`。私钥不要放进快照目录，也不要提交到 Git。
- 新域名。DNS API 凭据只有在要自动改解析时才需要，放在权限 600 的文件里，用 `--dns-credentials` 传入。
- 云服务器安全组放行 80 和 443。脚本发现云环境时只会提示，不会改安全组。

恢复默认安装 PHP 8.5。系统源和第三方源都没有 8.5、但有 8.4 时，脚本会先打印 Composer 的阻塞信息，再安装 8.4。这个回退路径还没有实际跑过。数据库软件包安装的是 MariaDB。恢复命令不会改用 MySQL 服务器软件包。

## 恢复后检查

完整恢复结束时会做 HTTP 检查，并写对账报告。报告在可写的 `xedc-recovery/docs/`，代码目录只读时改写到 `/var/lib/xedc-recovery/docs/PARITY_REPORT.md`。

夹具测试里，首页返回 200，`/admin` 返回 302。那次对账的异常差异是 0，但报告还没有模块、主题、逐表行数和配套服务，不能据此说与生产站一致。

## 这个工具做什么

备份一次生成一个新的时间戳目录，不覆盖上一份成功快照。目录里有数据库压缩包、公共文件、私有文件、脱敏后的项目源码、服务配置、清单和 `secrets.enc`。数据库密码、hash salt、对象存储密钥和 AI 密钥进加密包。未加密的项目包和私有文件包会在标 PASS 前扫描这些值。

age 私钥和快照分开存放。清单记录 SHA256。自检会看压缩包、SQL 结尾、`composer.lock` 和清单。失败的半成品目录会删掉。成功快照会保留，保留策略不删除最后一份 PASS。

生产机上的成功快照是 `/var/backups/xedc/20261104T061746Z`。它不在 Git 里。公共文件 8951 个，私有归档 319 个，MariaDB 228 张 InnoDB 表。对象存储清单是 public 765、private 0。Matomo 的两个数据卷做了运行中复制，清单里记为 WARN。

## 已经验证和尚未验证

| 项目 | 状态 |
| --- | --- |
| 生产备份、脱敏和 age 加密 | 快照 `20261104T061746Z` 自检通过 |
| Ubuntu 24.04 干净容器上的 PHP 8.5.12、MariaDB、Nginx、PHP-FPM | 用不含生产数据的夹具跑通一次，并重复恢复一次 |
| 序列化域名替换 | 单元测试通过；夹具库替换后可以 unserialize |
| 自签名证书和 DNS 未生效时的等待模式 | 容器里实际走到等待模式，没有向证书机构申请 |
| 站点目录回滚 | 临时目录核对哈希通过；空快照标 FAIL。运行中的 Nginx 和数据库没有做回滚 |
| Drupal 的 Drush、配置导入、管理员重置、一次性登录 | 未验收。夹具里没有 Drush |
| HTTP-01、DNS-01、续期 | 未向证书机构申请 |
| Matomo、Netdata、Docker 数据卷恢复 | 备份有配置和卷。测试容器里没有 Docker，容器没有拉起 |
| Ubuntu 22.04、26.04、26.10 和 Debian 12 的恢复 | 未跑 |
| Docker A 级 24 个场景、B 级子集、连续两轮 | 未跑 |

夹具恢复命令是 `bash xedc-recovery/tests/integration/restore-docker.sh`。它使用 `ubuntu:24.04`，内存限制 4G，CPU 限制 2，不挂载生产站、生产快照或生产私钥。

## 命令和配置

备份：

```bash
sudo xedc-recovery/backup.sh
sudo xedc-recovery/backup.sh --dry-run
sudo xedc-recovery/backup.sh --output /var/backups/xedc
```

恢复常用参数还有 `--php-version 8.5`、`--force`、`--versions balanced|pinned|latest`、`--ssl-method`、`--dns-provider`、`--dns-provider list`、`--retry-ssl`、`--renew-ssl`、`--reset-admin`、`--login-link`、`--with-media` 和 `--without-docker-services`。完整列表执行：

```bash
xedc-recovery/restore.sh --help
```

数据库密码写到 `/root/.drupal-credentials`，权限 600。恢复默认生成新的数据库密码，不改 Drupal 管理员密码。`--reset-admin` 会要求输入 yes；夹具没有 Drush，这条命令还没有在 Drupal 上执行。

定时单元在 `xedc-recovery/contrib/systemd/`，时间是 03:00 和 15:00。这台生产机没有启用它。现有的 `/root/backup.sh` 仍由原来的 crontab 调用，本工具没有改那个脚本。

异地复制只在设置 `XEDC_OFFSITE_DEST` 时发生。生产环境没有配置这个变量。

## 安全

不要提交备份、`*.sql`、`*.zst`、`*.age`、私钥、`settings.local.php` 或 `.drupal-credentials`。

公开 Git 历史里已经有数据库转储，以及被跟踪的 `settings.php` 和 `config/sync/smtp.settings.yml` 中的真实口令。标签 `backup-before-migration-v2` 指向包含这些内容的提交。历史没有改写，也不能把「当前文件已脱敏」说成「泄露已经消失」。数据库密码、hash salt 和 SMTP 密码需要在各自的系统里轮换。轮换还没有做，所以不能标成完成。对象存储和 AI 密钥在当前成功快照里只进入 `secrets.enc`；是否已在云上轮换，没有验证。

详细说明、参数和限制见 [xedc-recovery/README.md](xedc-recovery/README.md)。进度和需求对照在 `xedc-recovery/docs/PROGRESS.md` 与 `xedc-recovery/docs/REQUIREMENTS.md`。
