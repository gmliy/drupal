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

- M1 文档和代码准备提交。恢复主流程尚未在干净系统执行。

## 下一步

- 提交并推送 main。
- 在隔离环境跑恢复，不用生产库和容器。
- 补齐对账采集、回滚验证和 Docker 两轮矩阵。

## 测试

- `bash xedc-recovery/tests/unit/shell_functions.sh`：PASS。
- `php xedc-recovery/tests/unit/domain_replace_test.php`：OK。
- 真实备份日志：`/var/log/xedc-recovery/backup-keys.log`，结束于 `备份完成：/var/backups/xedc/20261104T061746Z`。
- Docker 矩阵：未运行。

## 修复

- 私有包排除 `settings.php*`、`xedc-storage-keys`、`xedc-ai-keys` 和 `*.key`，并在标 PASS 前扫描明文。
- 数据库事实改为带标签的输出，表数不再误用触发器计数。
- 导出改用 `mariadb-dump`。
- 证书方式、DNS 凭据文件、通配符和版本策略已接到 `restore.sh`。ZeroSSL 失败不再被当成成功。

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

- 准备推送 origin main。不强制，不改写历史。
