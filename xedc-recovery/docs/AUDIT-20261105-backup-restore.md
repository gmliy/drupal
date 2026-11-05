# 2026-11-05 备份与恢复审计

生产站在审计期间保持可访问。恢复写入只发生在本次创建的 Docker 容器、网络和数据卷里。这些资源在审计结束时已删除。生产数据库、网站目录、Nginx、PHP-FPM、MariaDB、现有 Matomo 容器和 `matomo_db-data`、`matomo_matomo-app-data` 没有被修改。

## 1. 当前网站

- 系统：Ubuntu 24.04，PHP 8.5.12，MariaDB 13.0.2，Nginx 1.31.6，Composer 2.10.3。
- Drupal 11.4.11。`/usr/bin/node` 为 v20.20.2。
- 自定义模块目录：`xa_views`、`xedc_admin_nav`、`xedc_admin_ui`、`xedc_ai`、`xedc_audit`、`xedc_auto_translate`、`xedc_core`、`xedc_home`、`xedc_mail`、`xedc_member`、`xedc_models`、`xedc_moderation`、`xedc_notify`、`xedc_quick`、`xedc_scheduler`、`xedc_site_audit`、`xedc_tags`、`xedc_ui_locale`。
- 主题：`xedc`、`xedc_admin`。
- 数据库 245 张表，全部 InnoDB。用户 18，节点 354（文章 49、页面 16、图片 257、视频 32），`file_managed` 384，分类 328，评论 13，媒体 629。自定义表 61 张。
- 公共文件模式为 Cloudflare R2。审计时 `public/` 有 875 个对象、295902540 字节；`private/` 前缀为 0。本地还留着少量未上传的 mp4 样本。
- 服务：Nginx、PHP-FPM、MariaDB、Redis、Docker、Matomo（`127.0.0.1:8088`，卷 `matomo_db-data` 与 `matomo_matomo-app-data`）、Netdata v2.12.2（`/opt/netdata`，systemd）。
- 启用模块里没有独立的支付或 OAuth 模块。

## 2. 本次备份

- 快照：`/var/backups/xedc/20261105T214600Z`，`STATUS=PASS`。
- 迁移包：`/var/backups/xedc/xedc-migration-20261105T214600Z.tar.zst`。
- 大小 853670528 字节。SHA-256 `bfe7233f8d091507364b20cbe48a323ed627eabb0f92be3e4c6b2f12837406f3`。
- 包内校验通过：归档 66 项，解压后 59 个文件。
- 已上传到备份桶对象 `xedc-backups/2026/11/xedc-migration-20261105T214600Z.tar.zst`，大小已核对。没有写入网站媒体桶。
- 数据库导出为 MariaDB 13.0.2，245 张表。公共文件 10325 个、940369580 字节。私有文件 44 个、42022886 字节。
- R2 镜像：公共对象 875 个，与列出的数量和 SHA-256 一致。私有前缀为空。

文件、Docker 卷和 R2 下载都是运行中的副本，manifest 里有这三条警告。

## 3. 发现并修复的问题

1. R2 上多数媒体不在本地磁盘。只备份本地文件会丢掉这些对象。备份现在只读下载对象，写入 `files-r2-public.tar.zst` 和 SHA-256。恢复时在本地文件之后解压，同名路径以 R2 副本为准。
2. 第一次导入在磁盘较紧时少了表，脚本仍把数据库步骤标成完成。导入后会数 `CREATE TABLE` 和实际表数，少了就停止，不把这次导入标成成功。
3. `settings.php` 解压后属主是 root，PHP-FPM 读不到，站点跳到安装页。恢复现在把 `settings.php` 和 `settings.local.php` 交给 `www-data` 组并设为 640。
4. 不完整导入留下的容器缓存和 Redis 缓存会让 Drupal 继续用旧模块列表。进入 Drush 前会删除 `sites/default/files/php`，并在本机 Redis 有响应时执行 `FLUSHALL`。这只发生在恢复目标里。
5. `config:import` 会删除只存在于数据库里的密钥、存储设置和 VIP 角色。默认恢复不再导入 `config/sync`。活动配置以恢复后的数据库为准。需要导入时设置 `XEDC_CONFIG_IMPORT=1`。
6. 验收请求如果走公网 DNS，会打到旧服务器并误判成功。现在用 `--resolve` 强制访问本机 Nginx。匿名访问 `/admin` 在生产站就是 403，验收把这种情况算通过。
7. 恢复脚本以前只保存 Matomo 卷，不导入、不启动。现在只在同名卷不存在时创建并导入。生产机上卷和容器都已存在，脚本会跳过，测试确认没有改动这两份生产卷。目标机上 8088 空闲且没有 `/opt/matomo` 配置时才启动 `db` 和 `matomo`，不启动会访问公网地址的 cron。
8. Netdata 的 MySQL DSN 含密码，原先在未加密的服务配置包里。收集时会改成 `XEDC_REDACTED`。本次快照和迁移包已按这个规则重新打包，包内不再含这枚密码。

## 4. 隔离环境

- 容器 `xedc-audit-restore`：独立网络，端口 `127.0.0.1:18080` 和 `127.0.0.1:18443`。没有 Docker CPU 或内存上限。没有挂载生产数据库或生产网站的可写目录。
- 容器内：PHP 8.5.12，MariaDB 12.3.3，Nginx。`XEDC_FORCE_LOCAL_STORAGE=1`，图片样式写在容器本地，不向生产 R2 上传。
- Matomo：卷 `xedc-audit-matomo-db`、`xedc-audit-matomo-app`，端口 `127.0.0.1:18088`。生产 Matomo 仍在 `127.0.0.1:8088`。
- 测试结束后删除了带 `xedc-audit=1` 标签的容器、上述两个卷和 `xedc-audit-net`、`xedc-audit-matomo_internal`、`xedc-audit-matomo_public`。

生产首页在测试期间保持 200，响应约 10 毫秒。生产库节点数保持 354。

## 5. Drupal 恢复结果

容器内 Drush：bootstrap 成功，Drupal 11.4.11，数据库已连接。

HTTP（经容器内 `--resolve`，没有打到公网）：

| 路径 | 结果 |
| --- | --- |
| `/` | 200 |
| `/user/login` | 200 |
| `/admin` 匿名 | 403，与生产站一致 |
| 一次性登录后的 `/admin` | 200 |
| `/articles` 和一篇文章 | 200 |
| `/meitu`、`/bizhi/1` | 200 |
| `/video` 和一条视频 | 200 |
| `/models` | 200 |
| `/tags` | 200 |
| `/search?keys=测试` | 200 |
| `/member` | 302 到登录页 |
| `/sitemap.xml` | 200 |
| R2 镜像中的图片 | 200，SHA-256 与清单一致 |

875 个 R2 对象在恢复后的公共目录里全部通过 SHA-256。图片页的地址是本机 `/sites/default/files/...`，隔离测试没有使用生产 CDN。

在容器里执行了 `xedc_ai` 的 `10003` 和 `10004` 更新。生产库没有执行更新。

`queue:run xedc_file_scan` 处理了 0 条并成功返回。没有运行会调用外部接口的 Cron。

## 6. 数据对比

恢复库和生产库都是 245 张表，表名一致。用户、节点、文件、分类、评论、媒体的行数一致。

备份之后生产站仍在写入，只有这些运行表不同：

| 表 | 恢复 | 审计时生产 |
| --- | ---: | ---: |
| key_value | 1953 | 1952 |
| locales_location | 6564 | 6572 |
| locales_source | 17835 | 17843 |
| redirect_404 | 2502 | 2503 |
| sessions | 259 | 257 |
| watchdog | 1053 | 1043 |
| xedc_traffic_sample | 4539 | 4569 |

内容表没有差值。私有文件里有一批导入压缩包在备份前就已经不在磁盘上，也不在 R2。备份无法把已经不存在的文件找回来。数据库里的记录还在。

## 7. Matomo

从快照卷解压到独立卷后，MariaDB 12.3.3 健康，Matomo 登录页返回 200，标题为 Sign in - Matomo。使用的是官方 `matomo:5-apache` 镜像，镜像自带 PHP 8.4。这和当前生产 Matomo 是同一镜像，不是把站点 PHP 降到 8.4。

生产 `127.0.0.1:8088` 在测试后仍返回 200。

## 8. Netdata

备份含 `netdata.conf` 和 go.d 配置，版本记录为 v2.12.2。监控历史数据库不在备份里。本机磁盘在安全线附近，且生产 Netdata 正在运行，隔离环境没有再安装或启动第二套 Netdata。新服务器上如果还没有 Netdata，恢复只解出配置，不覆盖已有配置，也不重启正在运行的服务。

## 9. 新增功能

会员、标签、模特、搜索、文章、图片、视频页面在恢复站可打开。收藏、点赞、投稿、通知、AI、翻译和文件任务的表在 245 张表的对比里与生产一致。对应队列已注册。隔离环境没有调用支付、邮件或 AI 接口。

## 10. 历史备份

`20261104T220707Z` 的 `secrets.enc` 可以用现有 age 私钥解密，里面有 `db.json`。没有更换密钥。错误的 age 私钥会失败。截断的归档无法解压。

11 月 4 日的迁移包没有 R2 字节镜像，只有对象清单。那些包仍然能解密和恢复数据库与当时的本地文件。远程对象要靠同一份 R2 凭据继续访问原来的桶。2026-11-05 之后的新包才自带对象副本。

## 11. 仍需人工处理的外部服务

- 新服务器的 Deploy Key 要人工放一次。之后 age 私钥和 R2 凭据从私有仓库取得。
- 公网 DNS 和 TLS 证书要在新服务器上重新签发或接入。系统配置包里没有找到私钥。
- SMTP、对象存储和 DeepSeek 的凭据在 `secrets.enc`。供应商账户本身不在备份里，凭据失效时要在供应商处重新发放。
- 没有启用独立支付或 OAuth 模块。
- Netdata 的历史曲线不能从这份备份找回。

## 12. 备份覆盖矩阵

| 项目 | 当前是否存在 | 是否纳入备份 | 是否验证恢复 | 结果 |
| --- | --- | --- | --- | --- |
| Drupal 核心 | 11.4.11 | 是 | 容器内启动成功 | FIXED |
| Composer 依赖 | 有 lock | 是 | 容器内安装并启动 | PASS |
| 自定义模块 | 17 个目录 | 是 | 已启用并可打开对应页面 | PASS |
| 第三方模块 | 有 | 是 | 站点启动，s3fs 在清缓存后可用 | PASS |
| 自定义主题 | xedc、xedc_admin | 是 | 前台页面正常渲染 | PASS |
| 数据库 | 245 表 | 是 | MariaDB 12.3.3 导入 245 表 | FIXED |
| public 文件 | 有 | 是 | 已恢复 | PASS |
| private 文件 | 有 | 磁盘上的文件已备份 | 已恢复；备份前就缺失的导入包无法恢复 | PARTIAL |
| R2 对象 | 875 个公共对象 | 新备份有镜像 | 875 个 SHA-256 全部一致 | FIXED |
| 自定义数据表 | 61 张 | 在数据库导出里 | 行数与生产一致 | PASS |
| Matomo | 有 | 两个数据卷和 compose | 独立端口登录页 200 | FIXED |
| Netdata | v2.12.2 | 只有配置 | 配置已检查，进程未在隔离环境启动 | PARTIAL |
| Nginx | 有 | 配置在系统包，恢复时重写站点配置 | 容器内站点可访问 | PASS |
| PHP | 8.5.12 | 恢复安装 8.5 | 容器为 8.5.12 | PASS |
| MariaDB | 生产 13.0.2 | 导出 | 导入 MariaDB 12.3.3 | PASS |
| Docker | 有 | Matomo 卷 | 独立卷可启动 | PASS |
| Cron 和 Queue | 有 | Cron 文件和队列表 | 空的本地队列可运行；未跑外部 Cron | PARTIAL |
| 系统配置 | 有 | 是 | 恢复流程使用了其中的站点配置 | PASS |
| Secrets | 有 | age 加密 | 解密后后台登录成功；Netdata DSN 已从明文包移除 | FIXED |
| 外部服务授权 | DNS、邮件、AI、R2 | 凭据在 secrets 或 r2.env | 未调用供应商接口 | PARTIAL |

数量：PASS 11，FIXED 5，PARTIAL 4，FAIL 0，NOT TESTED 0，BLOCKED 0。

PARTIAL 的四项不是内容丢失：私有导入包在备份前已不在磁盘上；Netdata 历史本来就不是备份范围；Cron 为了避免访问外部接口没有在隔离环境执行；供应商账户不能靠文件备份复制。

另外，下载中途掐断后的断点续传这一轮没有再做。错误密钥、截断归档、不完整导入和拒绝覆盖已有 Docker 卷已在本轮验证。

## 13. 修改的文件

- `xedc-recovery/lib/object_mirror.php`、`xedc-recovery/lib/backup.sh`：R2 只读镜像。
- `xedc-recovery/lib/database.sh`：导入后核对表数量。
- `xedc-recovery/restore.sh`：文件权限、本地存储开关、恢复前清缓存。
- `xedc-recovery/lib/drupal.sh`：默认不执行 `config:import`。
- `xedc-recovery/lib/verify.sh`：只验收本机，匿名 403 视为后台不可匿名访问。
- `xedc-recovery/lib/services.sh`：不覆盖已有卷，按条件启动 Matomo，脱敏 Netdata DSN。
- `README.md`、本报告、`PROGRESS.md`、`KNOWN_ISSUES.md`。

## 14. 推送

有代码和文档修改，恢复验收和单元测试已通过。功能提交是 `2b46559a38d84942b7790e394305b61c382cb5c7`。仓库为私有仓库，已推送到 `origin/main`。本地和远端 SHA 以推送后的 `git rev-parse HEAD` 与 `git ls-remote` 核对。
