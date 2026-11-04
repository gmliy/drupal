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

- M2 恢复核心。Ubuntu 24.04 干净容器已经恢复一份不含生产数据的夹具，并重复执行一次。
- 还没有 Drupal drush、回滚验证、真实证书、其余发行版和 24 个场景。

## 下一步

- 补回滚验证、证书 staging，以及完整对账字段。
- 继续 Docker A 级其余场景和 B 级发行版。不把这一次夹具结果写成全矩阵通过。

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
