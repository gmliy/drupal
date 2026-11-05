# 进度

## 已完成

- 只读审计见 `AUDIT.md`。线上 Nginx、PHP、数据库和 `/root/backup.sh` 未改。
- 备份脚本会生成独立快照，加密密钥，排除私有目录中的密钥和历史 `settings.php`，并在自检失败时删除未完成目录。
- 仓库外成功快照：`/var/backups/xedc/20261104T061746Z`，STATUS=PASS。
  - 公共文件 8951 个，833985760 字节。
  - 未加密私有文件 319 个。密钥和历史 settings 只在 `secrets.enc`。
  - MariaDB 13.0.2，utf8mb4，InnoDB 228 表。
  - R2 `public/` 765，`private/` 0。
  - PHP 8.5.12，ffmpeg 6.1.1-3ubuntu5。
  - 独立复核：项目包和私有包都不含数据库密码、hash salt、SMTP 密码和 AI 密钥。
- 作废并删除了两份不合格快照：一份私有包含 settings 副本，一份私有包含 AI 密钥。
- 域名替换单元测试通过。证书决策、私有包排除、对账异常检测、异地目录复制的单元测试通过。
- `bash -n` 和 `shellcheck -S warning` 通过。
- 定时单元已放在 `contrib/systemd/`，未在生产机启用。

## 进行中

- PHP 8.5 安装矩阵已跑完。通过：Ubuntu 22.04、24.04、26.04，Debian 12、13（8.5.12），Ubuntu 26.10（系统仓库 8.5.9）。BLOCKED：Ubuntu 20.04 没有 php8.5 包；Debian 11 的安全仓库在安装基础工具时 404。日志在 `/var/log/xedc-recovery/php85-matrix/`。
- 真实 Drupal 的 Drush、配置导入和数据库更新还没有在干净机器上跑过。夹具不是 Drupal。

## 迁移包

- `backup.sh` 在成功快照之后调用 `xedc_migration_package`。失败不会删除原快照，命令也不会报成功。
- 已从快照 `20261104T082520Z` 生成 `/var/backups/xedc/xedc-migration-20261104T082520Z.tar.zst`。
- 大小 605176048 字节。SHA-256 `96149968b29fe370dfc4ee15e6ca404f33cc7a504f22e4f32a14b85f8ec08cc5`。`sha256sum -c` 为 OK。
- 归档内没有 `age.key` 或 `r2.env`。公开 manifest 去掉了端点、桶名和密钥字段。
- 隔离 SSH 容器接收了这份包，容器内 `sha256sum -c` 为 OK。解压前需要安装 `zstd`。
- 本地私有 S3 完成了上传、回读、错误密钥和缺失对象测试。同一路径回读了这份完整迁移包，哈希一致。

## 一条命令恢复

- `recover.sh` 从 `secure/age.key` 和 `secure/r2.env` 安装密钥，列出并下载 R2 迁移包，校验后调用现有 `restore.sh --mode=full`。
- `bootstrap-recover.sh` 用只读 Deploy Key 稀疏拉取 `xedc-recovery/`，再执行 `recover.sh`。
- 没有重新生成 age 私钥。`20261104T105132Z` 的 `secrets.enc` 可以用这份私钥解密，解密结果含 `db.json`，明文已删除。
- 本机 `recover.sh --dry-run` 只做检查和 R2 列表，没有下载，也没有改本地迁移包。
- 生产机拒绝把备份恢复到自身。完整的新服务器站点恢复、Matomo 进程和 Netdata 进程没有在本轮重跑。

## R2 上传、列表和错误诊断

- `upload.sh` 直接上传已有迁移包，不重新备份、不连接生产库。
- `download.sh --source=r2 --list` 在空前缀时写明“R2 中没有找到迁移包。”，退出码 0。连接、鉴权和 Bucket 错误返回非零。
- 失败日志记录阶段、脚本、函数、行号、退出码、脱敏命令和快照目录。2026-11-04 11:01 那次生产备份的原始命令没有留下，没有为了补日志再跑生产备份。
- 2026-11-04 12:05 UTC：`upload.sh` 上传已有的 `xedc-migration-20261104T105132Z.tar.zst`。本地 604337199 字节未改。远端键 `xedc-backups/2026/11/xedc-migration-20261104T105132Z.tar.zst`，三个对象大小一致，回读 SHA-256 `9b88e6517b5612900dd3acee6b8d5c17f4d016b834454e2c04a9104d6ea4a279`。列表和隔离目录下载均为 PASS。验收写在 `XEDC_R2_UPLOAD_DOWNLOAD_FIX_REPORT.md`。

## 恢复

- PHP 只允许 8.5。`--php-version 8.4` 在安装前退出，日志为 BLOCKED。
- Ubuntu 24.04 夹具 `--mode=full` 的日志是 `8.3.6 → 8.5 → 8.5.12`，退出码 0，见 `/var/log/xedc-recovery/fixture-full-20261104.log`。
- 同一轮里 `--mode=restore` 因目录已存在而停止。错误 age 私钥在独立夹具上被拒绝。损坏的外层包不能通过校验。
- 证书仍是自签名等待。Matomo 只恢复了 compose 模板。没有 Drush。这些不是 PASS。

## 测试

- `bash xedc-recovery/tests/unit/shell_functions.sh`：PASS，含无对象存储时对账不报异常。
- `php xedc-recovery/tests/unit/domain_replace_test.php`：OK。
- `php xedc-recovery/tests/unit/object_inventory_test.php`：PASS。
- 真实备份日志：`/var/log/xedc-recovery/backup-keys.log`，结束于 `备份完成：/var/backups/xedc/20261104T061746Z`。
- 夹具备份：`/var/lib/xedc-recovery/fixture-backups/20261104T070508Z`，不在 Git 里。构建时设置 `XEDC_SQL_DUMP_FILE` 和 `XEDC_SKIP_DOCKER=1`，没有连接生产库。
- 夹具恢复命令：`bash xedc-recovery/tests/integration/restore-docker.sh`，镜像 `ubuntu:24.04`，`--memory=4g --cpus=2`。第二次退出码 0，日志结束于 `FIXTURE_RESTORE_OK`。
- 容器日志记录 `8.3.6 → 8.5 → 8.5.12`。HTTPS 首页 200，`/admin` 302。序列化字段可 unserialize，且不含旧域名。二次恢复的对账要求 `异常差异: 0`。
- 同一次日志有 WARN：没有 Docker 守护进程，没有 drush，证书是自签名等待模式。这些不是 PASS。
- Docker A/B 矩阵和连续两轮：未运行。

## 修复

- 私有包排除 `settings.php*`、`xedc-storage-keys`、`xedc-ai-keys` 和 `*.key`，并在标 PASS 前扫描明文。
- 数据库事实改为带标签的输出，表数不再误用触发器计数。
- 导出改用 `mariadb-dump`。
- 证书方式、DNS 凭据文件、通配符和版本策略已接到 `restore.sh`。ZeroSSL 失败不再被当成成功。
- 对象存储配置目录改为读取 active.json 里的 provider，不再写死目录名。本地存储和缺密钥有测试；线上只读清点仍是 public 765、private 0。
- 干净系统先安装 jq、zstd、age 和 python3，否则恢复在读 manifest 前就退出。
- 没有 systemd 时直接启动 MariaDB、Nginx、PHP-FPM 和 Redis。SSL 等待 timer 只在 systemd 可用时启用。
- 域名替换前的 zstd 快照会覆盖 mktemp 留下的空文件，空快照会中止替换。
- 重复恢复时重新读取数据库名，避免对账把表数报成 0。未使用对象存储时不再把对象数量报成缺失。
- 回滚先保存站点文件哈希。写回后哈希不一致，或快照里没有可核对的内容时，状态是 FAIL。
- 夹具恢复提交是 `df0e912`。回滚校验提交是 `3c61d3c`。
- 根 README 已重写。`site-migrate.sh` 归档到 `legacy/`。原来的首页是 `tools/xedc-recover` 的说明，保存在 `legacy/README-xedc-recover.md`。`docs/migration/` 里的链接已改到新路径。

## 决定

- 不改写 `3f56c01`，不移动已有标签。
- 生产恢复继续拒绝在来源机器执行。
- 异地复制只在设置 `XEDC_OFFSITE_DEST` 时发生。

## 风险

- 历史 Git 凭据仍然公开。
- 运行中复制的文件和 Docker 卷不是冻结快照。
- 推送 main 会把已经打过标签的 `3f56c01` 快进到 origin/main。该提交本身已有数据库转储。

## 提交

- 祖先：`3f56c01`。标签 `backup-before-migration-v2` 仍指向它。
- 备份里程碑：`7056b90c77d327e11032abae1eb6195718c73e99`。

## 推送

- 第一次推送被拒绝：origin/main 已前进到 `be32fd1`，不能快进。
- 没有改写 `3f56c01`。用 merge 保留该提交和标签，并保留远端 `tools/xedc-recover/`。
- `docs/MIGRATION_MASTER_SPEC.md` 的冲突采用远端已发布文本。`/root/TASK.md` 仍要求 age，本目录的实现不改成无加密包。
- 已推送 `493fd507a68466cc1174862e75a974a7fdc2637d` 到 origin/main。
- 其后对象存储读取修复已推送，当前起点是 `2e347e3`。

## 2026-11-05 全站恢复审计

- 新快照 `20261105T214600Z`，迁移包已上传备份桶。R2 媒体对象有只读镜像。
- 隔离容器里用这份快照恢复了 Drupal 11.4.11，MariaDB 12.3.3 导入 245 张表。Matomo 用独立卷在 `127.0.0.1:18088` 启动。
- 生产站文件、数据库、容器和端口没有被恢复测试改写。
- 报告：`xedc-recovery/docs/AUDIT-20261105-backup-restore.md`。
