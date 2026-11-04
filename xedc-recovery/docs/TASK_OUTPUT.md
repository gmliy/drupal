# 本次任务输出说明

说明范围是 XEDC Drupal 自动化备份与跨服务器迁移系统 v2 在本轮的实际交付。依据是 `/root/TASK.md`、仓库代码、测试和仓库外快照。没有写入密钥、邮箱、内网地址或对象存储 endpoint。

最新代码在 `main`，已与 `origin/main` 一致，提交 `2e347e3`。没有创建 `/var/www/drupal/.MIGRATION_DONE`。

## 已交付

代码在 `xedc-recovery/`。备份产物在仓库外的 `/var/backups/xedc/`，没有进入 Git。

可用快照：

`/var/backups/xedc/20261104T061746Z`

该目录权限是 `700`，`STATUS` 为 PASS。自检通过了 SHA256、zstd、SQL 结尾、manifest schema 2、公共文件数量和 `composer.lock`。另一次独立扫描确认：未加密的项目包和私有包不含数据库密码、hash salt、SMTP 密码、对象存储密钥和 AI 密钥。这些内容只在 `secrets.enc` 解密后出现。age 私钥在 `/root/.config/xedc-recovery/age.key`，权限 `600`，不在快照目录里。

| 项目 | 结果 |
| --- | --- |
| 公共文件 | 8951 个，833985760 字节 |
| 未加密私有文件 | 319 个，147920426 字节 |
| 数据库 | MariaDB 13.0.2，utf8mb4，InnoDB 228 表，存储过程 0，触发器 0 |
| 对象存储 | `cloudflare_r2`，`public/` 765，`private/` 0 |
| PHP | 8.5.12 |
| ffmpeg | 6.1.1-3ubuntu5，含 libx264、libx265、libvpx、libvpx-vp9、aac、libopus |
| 警告 | 文件和 Matomo 卷都是运行中复制，不是冻结快照 |

本轮还做了这些实现：

- 备份失败时删除未完成目录，成功快照不覆盖上一份。保留策略不删除唯一的 PASS，FAIL 不计入保留数。
- 项目归档里的 `settings.php` 和 `smtp.settings.yml` 使用占位符。
- 私有目录中的密钥、`*.key` 和历史 `settings.php` 进入 age 密文，并在标 PASS 前扫描未加密包。
- 对象存储目录从站点配置读取 provider，不再写死目录名。本地存储和缺密钥有测试；线上只读清点结果与上表一致。
- 域名替换能处理 PHP 序列化、JSON、URL 编码和大小写，并避免误改相似域名。这项有单元测试。
- 证书方式可以在 HTTP-01、DNS-01、手动 DNS 和自签名等待之间选择。DNS 服务商变量来自 acme.sh 3.1.6。尚未向证书机构发证。
- 每天 03:00 和 15:00 的 systemd 单元在 `xedc-recovery/contrib/systemd/`。生产机没有启用，避免和现有 `/root/backup.sh` 抢资源。
- 设置 `XEDC_OFFSITE_DEST` 时才 rsync 快照。生产机没有设置。私钥不会被这条路径复制。

## 本轮修复

1. 第一份快照的未加密私有包含有历史 `settings.php`，里面有数据库密码和 hash salt。
2. 第二份快照的未加密私有包含有 AI 密钥文件。
3. 数据库表数曾经可能读成触发器数量。现已改为带标签的字段，228 是表数。
4. 数据库导出改为优先使用 `mariadb-dump`。
5. Let's Encrypt 失败后改用 ZeroSSL 时，失败不再被记成成功。

前两份不合格快照已删除。线上 Nginx、PHP、数据库和 `/root/backup.sh` 没有修改，也没有重启生产服务。

## 测试

已通过：

- `bash xedc-recovery/tests/unit/shell_functions.sh`
- `php xedc-recovery/tests/unit/domain_replace_test.php`
- `php xedc-recovery/tests/unit/object_inventory_test.php`
- `bash -n` 与 `shellcheck -S warning`
- 真实备份日志 `/var/log/xedc-recovery/backup-keys.log`
- 提交前的暂存差异敏感扫描

未运行：

- 干净系统上的完整恢复
- 域名替换对真实库的执行和回滚
- 真实证书申请
- 管理员重置和一次性登录
- 回滚后的原站验证
- 完整 `PARITY_REPORT.md`
- Docker A 级 24 个场景、B 级子集，以及连续两轮

因此 `REQUIREMENTS.md` 里恢复、证书实发、对账和 Docker 矩阵仍是 TODO。Docker 卷一致性和未启用的定时任务是 WARN。不能把这些写成 PASS。

## Git

直接在 `main` 上工作。`3f56c01` 没有被改写，标签 `backup-before-migration-v2` 仍指向它。

第一次推送被拒绝，因为远端已经前进到 `be32fd1`。随后用普通合并保留本地备份提交和远端的 `tools/xedc-recover/`，没有强制推送。`docs/MIGRATION_MASTER_SPEC.md` 的冲突采用了远端已发布文本。该文本写明另一套工具的新备份不做客户端加密；`/root/TASK.md` 仍要求 age，`xedc-recovery/` 没有改成无加密包。

本轮相关提交：

| 提交 | 内容 |
| --- | --- |
| `7056b90` | 加入 v2 备份工具，并把站点密钥排除出公开归档 |
| `177a1b6` | 在进度里记下备份里程碑 |
| `493fd50` | 合并 origin/main，不改写已有备份提交 |
| `96ee0ce` | 记录合并结果，以及两套工具并存 |
| `f6f77df` | 对象存储 provider 改为读取站点配置 |
| `2e347e3` | 把上述测试补进进度 |

当前 `HEAD` 和 `origin/main` 都是 `2e347e3`。

## 仍需处理

- 公开历史里已有数据库转储，跟踪的 `settings.php` 含数据库密码和 hash salt，`config/sync/smtp.settings.yml` 含 SMTP 密码。这些值应视为已暴露并轮换。本轮不改写历史。
- 恢复还不能在新机器上视为已验收。来源机器会拒绝恢复，避免覆盖生产站。
- 对账脚本能发现部分异常，但还没有采集模块、主题、行数和编码器的完整现场数据。
- Matomo 数据卷是容器运行时的文件级复制。
- 远端 `tools/xedc-recover/` 的测试使用 AWS 文档示例访问键和虚构的 GitHub 令牌。敏感扫描会报这些示例，它们不是本站点的生产凭据。

## 相关文件

- 审计：`xedc-recovery/docs/AUDIT.md`
- 设计：`xedc-recovery/docs/DESIGN.md`
- 需求对照：`xedc-recovery/docs/REQUIREMENTS.md`
- 进度：`xedc-recovery/docs/PROGRESS.md`
- 已知问题：`xedc-recovery/docs/KNOWN_ISSUES.md`
- 版本：`xedc-recovery/docs/VERSIONS.txt`
- 备份入口：`xedc-recovery/backup.sh`
- 恢复入口：`xedc-recovery/restore.sh`
