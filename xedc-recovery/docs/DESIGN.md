# 设计

实现放在 `xedc-recovery/`。备份产物只写 `/var/backups/xedc`，不进 Git。恢复默认拒绝在备份来源机器上执行；测试必须显式设置 `XEDC_RECOVERY_TEST=1`，或运行在容器里。

## 备份

每次运行重新扫描，生成一个新的时间戳目录，不覆盖上一份成功快照。核心文件失败时退出码非 0，退出陷阱删除未完成目录。成功后写 `STATUS=PASS`。保留最近 14 份 PASS，并且永远留下至少一份 PASS。

数据库用 `mariadb-dump`（没有该命令时才用 `mysqldump`），`--single-transaction`，再压成 zstd。密码通过 defaults file 传入，不放在命令参数里。

项目源码排除 `.git`、vendor、core、contrib、上传目录和原始配置。归档后再追加脱敏的 `settings.php` 和 `config/sync`。私有目录里的 `xedc-storage-keys`、`xedc-ai-keys`、`*.key` 和 `settings.php*` 复制到 age 明文暂存区，不进入 `files-private.tar.zst`。打包后用这些明文和数据库密码、hash salt 扫描未加密归档，命中则整份备份失败。

对象存储用站点里的 AWS SDK 做只读列举，只记录数量和模式。Docker 卷在容器运行时复制，manifest 写明这不是事务快照。`xedc-recover-db-data` 排除。

age 公钥可以放在备份根目录。私钥只放在 `/root/.config/xedc-recovery/age.key`。加密后立刻解密，确认包里有 `db.json`，否则不能标 PASS。

异地副本由环境变量 `XEDC_OFFSITE_DEST` 触发，用 rsync 复制快照目录。未设置时不失败。私钥不会被这条路径复制。生产机当前没有设置该变量。

定时任务文件在 `contrib/systemd/`，每天 03:00 和 15:00。失败时写 syslog。不要在这台仍运行 `/root/backup.sh` 的机器上启用。

## 恢复

顺序是：软件包、PHP、源码、解密、数据库、文件、`settings.local.php`、Nginx、证书、配套服务、媒体冒烟、域名、Drush、凭据、HTTP 验收、对账。状态记在 `/var/lib/xedc-recovery/restore-state.json`。指定 `--step` 时只跑该步；不指定时跳过已完成的步骤。

PHP 默认 8.5。先检查 Ondřej 或 Sury 源的 Release 文件，没有再退回系统仓库，并明确打印当前版本、目标版本和实际版本。8.5 已内置 OPcache 时不重复安装。

数据库用户默认使用新的随机密码。`--keep-db-credentials` 才沿用密文里的密码。导入前不删除目标机上与本次无关的库。

域名替换由独立 PHP 工具处理序列化、JSON 和普通文本，替换前先做数据库快照，失败再导回。Nginx 在旧域名上保留 HTTP 301。

证书只用 acme.sh 3.1.6。已解析到本机用 HTTP-01；有 DNS API 用 DNS-01；否则自签名并每 5 分钟重试。Let's Encrypt 失败再试 ZeroSSL。DNS 凭据只从权限 600 的文件读入，不放在命令行。通配符证书拒绝 HTTP-01。真实 CA 不在本机生产恢复和 Docker 测试范围内，测试要用 staging 或 Pebble。

版本策略默认 balanced。只有 `--versions latest` 才升级 GitHub 发布物，并且下载前必须有 SHA256。

## 尚未当作完成的部分

恢复主流程还没有在干净系统里跑通。对账目前只比较少数字段，不能把单元测试当成完整 `PARITY_REPORT`。Docker 矩阵还没有开始。因此这些项在 `REQUIREMENTS.md` 里保持 TODO。
