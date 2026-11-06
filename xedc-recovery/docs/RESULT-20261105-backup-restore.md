# 2026-11-05 备份与恢复结果说明

任务原文见 [TASK-20261105-backup-restore-audit.md](TASK-20261105-backup-restore-audit.md)。技术记录见 [AUDIT-20261105-backup-restore.md](AUDIT-20261105-backup-restore.md)。

## 结论

当前网站已经重新打了一份完整备份，并在 Docker 隔离环境里用这份真实备份把 Drupal 和 Matomo 恢复起来。恢复过程中发现的备份遗漏和脚本错误已经修改，相关测试通过后推送到了私有仓库 `main`。

生产网站的数据库、文件、配置、Nginx、PHP-FPM、MariaDB、正在运行的容器和已有数据卷都没有被这次测试改写。生产首页在测试期间和测试结束后都返回 200。

这不是“所有历史备份都自带全部远程媒体”的结论。2026-11-05 的新包带有 R2 对象副本。更早的包可以解密，但只有对象清单，远程文件仍要靠原来的 R2 桶。

## 使用的备份

| 项目 | 值 |
| --- | --- |
| 快照 | `/var/backups/xedc/20261105T214600Z` |
| 状态 | `PASS` |
| 迁移包 | `/var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst` |
| 大小 | 853670528 字节 |
| SHA-256 | `bfe7233f8d091507364b20cbe48a323ed627eabb0f92be3e4c6b2f12837406f3` |
| 备份桶对象 | `xedc-backups/2026/11/xedc-migration-20261105T214600Z.tar.zst` |

数据库导出来自 MariaDB 13.0.2，245 张表。公共文件 10325 个，私有文件 44 个。R2 `public/` 镜像 875 个对象、295902540 字节，SHA-256 与清单一致。`private/` 前缀为空。

文件、Docker 卷和 R2 下载是网站运行期间的副本，不是同一瞬间的冻结快照。

## 网站实际范围

- Drupal 11.4.11，PHP 8.5.12，Nginx 1.31.6，Composer 2.10.3，系统 Node v20.20.2。
- 生产数据库是 MariaDB 13.0.2。新服务器按支持矩阵安装 MariaDB 12，这次把 13 的导出导入了 12.3.3。生产库没有降级。
- 自定义模块：`xa_views`、`xedc_admin_nav`、`xedc_admin_ui`、`xedc_ai`、`xedc_audit`、`xedc_auto_translate`、`xedc_core`、`xedc_home`、`xedc_mail`、`xedc_member`、`xedc_models`、`xedc_moderation`、`xedc_notify`、`xedc_quick`、`xedc_scheduler`、`xedc_site_audit`、`xedc_tags`、`xedc_ui_locale`。
- 主题：`xedc`、`xedc_admin`。
- 数据：用户 18，节点 354（文章 49、页面 16、图片 257、视频 32），文件 384，分类 328，评论 13，媒体 629，自定义表 61 张。
- 服务：Nginx、PHP-FPM、MariaDB、Redis、Docker、Matomo、Netdata v2.12.2。
- 公共媒体走 Cloudflare R2。当时 875 个公共对象里，742 个只在 R2、本地没有。
- 没有启用独立的支付或 OAuth 模块。

## 隔离测试

测试容器名 `xedc-audit-restore`，端口只绑定 `127.0.0.1:18080` 和 `127.0.0.1:18443`。没有设置 CPU 或内存上限，没有挂载生产数据库，也没有把生产网站目录当成可写目录。

容器里是 PHP 8.5.12、MariaDB 12.3.3 和 Nginx。环境变量 `XEDC_FORCE_LOCAL_STORAGE=1` 让图片样式写在容器本地，避免测试向生产 R2 上传。

Matomo 使用另外两个卷，端口 `127.0.0.1:18088`。生产 Matomo 一直在 `127.0.0.1:8088`。

测试结束后，只删除了带 `xedc-audit=1` 的容器、这两个测试卷，以及 `xedc-audit-net`、`xedc-audit-matomo_internal`、`xedc-audit-matomo_public`。

## Drupal 恢复结果

Drush 显示 bootstrap 成功，版本 11.4.11，数据库已连接。页面检查都打到容器自己的 Nginx。

| 检查 | 结果 |
| --- | --- |
| 首页、登录、文章、图片、视频、模特、标签、搜索 | 200 |
| 匿名后台 | 403，和生产站一致 |
| 一次性登录后的后台 | 200 |
| 会员中心 | 302 到登录页 |
| 站点地图 | 200 |
| 875 个 R2 对象 | SHA-256 全部一致，图片从恢复后的本地文件打开 |

容器里执行了 `xedc_ai` 的更新 `10003` 和 `10004`。生产库没有执行更新。本地空队列 `xedc_file_scan` 可以运行。会调用邮件、支付或 AI 接口的 Cron 没有执行。

恢复库和生产库都是 245 张表，表名相同。内容、用户、文件、分类、评论和媒体的行数相同。备份之后生产站仍在写入，所以会话、日志、流量样本和少量翻译表有差值。内容表没有差值。

## Matomo 与 Netdata

Matomo 的数据库卷和应用卷解压到独立卷后可以启动。登录页返回 200。官方镜像自带 PHP 8.4，和生产环境使用的是同一个镜像。站点 PHP 仍是 8.5.12。

Netdata 备份了 v2.12.2 的配置。监控历史不在备份里。生产 Netdata 正在运行，隔离环境没有再启动第二套。新服务器上如果还没有 Netdata，恢复只解出配置，不覆盖已有配置，也不重启正在运行的服务。

## 修过的问题

1. 只备份本地文件会丢掉只存在于 R2 的媒体。新备份会只读下载这些对象。恢复时同名文件以 R2 副本为准。
2. 数据库导入不完整时，脚本仍可能把它标成成功。现在会核对表数量，少了就停止。
3. `settings.php` 解压后 PHP-FPM 读不到，网站跳到安装页。恢复时改为 `www-data` 可读。
4. 失败启动留下的缓存会让 Drupal 继续使用旧模块列表。进入 Drush 前会清掉恢复目标里的这份缓存。
5. 默认 `config:import` 会删掉只存在于数据库里的密钥、存储设置和 VIP 角色。默认恢复不再导入，活动配置以数据库为准。
6. 验收如果走公网 DNS，会访问到旧服务器。现在强制检查本机。
7. Matomo 卷以前只保存、不导入。现在只在同名卷不存在时导入。生产卷已存在时会跳过。目标机端口空闲时才启动 Matomo，不启动会访问公网的归档任务。
8. Netdata 的数据库口令原先出现在未加密的服务包里。现在写入占位符，本次迁移包已按这个规则重新打包。

## 历史备份和密钥

age 私钥没有更换。`20261104T220707Z` 的 `secrets.enc` 可以解密。错误私钥会失败，截断的归档无法解压。

11 月 4 日的包没有 R2 字节镜像。它们仍能恢复当时的代码、数据库和本地文件；远程对象要继续访问原来的桶。Deploy Key 和 age 私钥是两把钥匙。Deploy Key 只放在服务器上，不在仓库里。仓库保持私有。

## 仍要人工处理

- 新服务器第一次要放入只读 Deploy Key。之后 age 私钥和 R2 凭据从私有仓库取得。
- 公网 DNS 和 TLS 证书要在新服务器上重新接入。系统配置包里没有证书私钥。
- SMTP、对象存储和 DeepSeek 的凭据在加密包里。供应商账户本身不在备份中，凭据失效时要在供应商处重新发放。
- 备份前就已经不在磁盘、也不在 R2 的私有导入压缩包无法找回。数据库记录还在。
- Netdata 的历史曲线不能从这份备份找回。

## 覆盖结果

PASS 11，FIXED 5，PARTIAL 4，FAIL 0，NOT TESTED 0，BLOCKED 0。

| 项目 | 结果 | 说明 |
| --- | --- | --- |
| Drupal 核心 | FIXED | 权限和缓存修好后，11.4.11 在容器里启动 |
| Composer 依赖 | PASS | 按锁定文件安装并启动 |
| 自定义模块 | PASS | 17 个目录都在，对应页面可打开 |
| 第三方模块 | PASS | 清缓存后站点正常，包含对象存储模块 |
| 自定义主题 | PASS | 前台正常渲染 |
| 数据库 | FIXED | 245 张表导入 MariaDB 12.3.3，并增加完整性和表数核对 |
| public 文件 | PASS | 已恢复 |
| private 文件 | PARTIAL | 磁盘上的文件已恢复；备份前就缺失的导入包无法恢复 |
| R2 对象 | FIXED | 新包含 875 个对象，校验全部一致 |
| 自定义数据表 | PASS | 61 张表，行数与生产一致 |
| Matomo | FIXED | 独立端口登录页 200，脚本不再覆盖已有卷 |
| Netdata | PARTIAL | 有配置，无监控历史，隔离环境未再启动进程 |
| Nginx | PASS | 容器内站点可访问 |
| PHP | PASS | 容器为 8.5.12 |
| MariaDB | PASS | 13 的导出导入 12.3.3 |
| Docker | PASS | Matomo 独立卷可启动 |
| Cron 和 Queue | PARTIAL | 本地空队列可运行；未跑外部 Cron |
| 系统配置 | PASS | 恢复流程使用了站点配置 |
| Secrets | FIXED | 解密后可以登录后台；Netdata 口令已从明文包移除 |
| 外部服务授权 | PARTIAL | 凭据可恢复；供应商账户和 DNS 需要人工处理 |

下载中途掐断后的续传这一轮没有再做。错误密钥、截断归档、不完整导入和拒绝覆盖已有 Docker 卷已经验证。

## 代码和推送

改过的恢复代码主要在：

- `xedc-recovery/lib/object_mirror.php`
- `xedc-recovery/lib/backup.sh`
- `xedc-recovery/lib/database.sh`
- `xedc-recovery/lib/drupal.sh`
- `xedc-recovery/lib/services.sh`
- `xedc-recovery/lib/verify.sh`
- `xedc-recovery/restore.sh`
- 根目录 `README.md`

仓库确认为私有后已推送 `origin/main`。本地和 GitHub 的提交都是 `25304a6bc7b2e410b6413fed3962341c4c48fde6`。其中功能修改在 `2b46559a38d84942b7790e394305b61c382cb5c7`。当时工作区里的 SEO 改动没有进入这次提交。
