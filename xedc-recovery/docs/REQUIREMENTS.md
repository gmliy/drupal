# 需求对照

状态只用 DONE、TODO、WARN、N/A。没有测试证据的项不是 DONE。

| ID | 要求 | 位置 | 测试 | 状态 |
| --- | --- | --- | --- | --- |
| R001 | 新代码只在 xedc-recovery，备份在仓库外 | backup.sh | 快照路径 `/var/backups/xedc/20261104T061746Z` | DONE |
| R002 | 不修改生产 Nginx、PHP、数据库和 /root/backup.sh | 操作记录 | settings.php 与 backup.sh 时间戳未被本次改写 | DONE |
| R003 | 独立时间戳快照且失败不留半成品 | lib/backup.sh | 前两次失败或作废快照已不在目录中，成功快照 STATUS=PASS | DONE |
| R004 | 数据库完整导出且 root 密码不进 manifest | lib/database.sh | 自检看到 Dump completed；manifest 表数 228，root_password=not-stored | DONE |
| R005 | public/private 文件与数量 | lib/files.sh | 公共 8951；私有归档 319；自检比较数量 | DONE |
| R006 | 项目源码、脱敏 settings 和 config | lib/files.sh lib/backup.sh | 归档中的 settings.php 与 smtp.settings.yml 含占位符且不含原密码 | DONE |
| R007 | R2/S3 只读清点，密钥单独加密 | object_inventory.php | manifest status PASS，public 765，private 0；密钥不在未加密包 | DONE |
| R008 | Docker 卷只读导出并声明一致性 | lib/services.sh | 两个 Matomo 卷在快照中，WARN 写入 manifest | WARN |
| R009 | 不导出、不停止无关的 13306 数据库 | lib/services.sh | 快照没有 xedc-recover-db-data | DONE |
| R010 | age 加密且私钥与密文分离 | lib/backup.sh | 解密可见 db.json；私钥不在快照目录 | DONE |
| R011 | 未加密包不得含数据库密码、hash salt、SMTP 密码和密钥文件 | lib/backup.sh | 对 20261104T061746Z 的独立扫描 HITS=0 | DONE |
| R012 | SHA256、zstd、composer.lock、manifest schema | lib/backup.sh | 备份日志 checksum OK，进程退出码 0 | DONE |
| R013 | 保留上限且不删除唯一 PASS，FAIL 不计入 | xedc_backup_retain | shell_functions.sh 的 retain 用例 | DONE |
| R014 | 03:00 与 15:00 timer，生产机不启用 | contrib/systemd | 单元文件存在；systemctl 未 enable | WARN |
| R015 | 异地复制 | xedc_backup_offsite | 单元测试复制到临时目录；生产未配置 | WARN |
| R016 | 磁盘与内存预检、锁、日志 | lib/common.sh | 备份日志记录了内存和磁盘；锁被成功运行占用过 | DONE |
| R017 | 恢复支持列出的发行版并拒绝来源机 | lib/common.sh restore.sh | 发行版判断和来源机拒绝未在目标系统执行 | TODO |
| R018 | PHP 8.5，失败才明确降到 8.4 | lib/environment.sh | 生产机已是 8.5.12；安装路径未在干净系统执行 | TODO |
| R019 | 恢复数据库、文件、Composer、Nginx、cron、权限 | restore.sh | 未在隔离环境执行 | TODO |
| R020 | Matomo、Netdata、Docker、ffmpeg、播放器恢复 | services.sh media.sh | 备份含配置和卷；恢复未执行 | TODO |
| R021 | 域名替换保留 PHP 序列化 | domain_replace.php | domain_replace_test.php OK | DONE |
| R022 | 域名替换前快照，失败回滚 | lib/domain.sh | 未对真实库执行 | TODO |
| R023 | HTTP-01、DNS-01、手动 DNS、等待模式 | lib/ssl.sh | 决策函数单元测试 PASS；未向 CA 发证 | TODO |
| R024 | DNS 凭据不进命令行，列出现有服务商变量 | ssl.sh acme-dns-providers.json | provider 列表含 CF_Token、Ali_Key、hetzner 别名 | DONE |
| R025 | 凭据文件、管理员不自动改密、一次性登录 | credentials.sh | 未在隔离站点执行 | TODO |
| R026 | 回滚后验证原站仍可用 | rollback.sh | 未执行 | TODO |
| R027 | PARITY_REPORT 能标出异常差异 | compare.sh | 单元测试能发现文件数差异；完整现场报告未生成 | TODO |
| R028 | Docker A 级 24 场景与 B 级子集，连续两轮 | tests | 未运行 | TODO |
| R029 | 公开仓库敏感扫描 | tests/scripts/secret-scan.py | 对本轮暂存差异执行，忽略路径、变量名和 not-stored 占位符 | DONE |
| R030 | 根 README 与 legacy 归档 | 根目录 | 留到 M6，本次未移动 README.md 和 site-migrate.sh | TODO |
| R031 | .MIGRATION_DONE | 根目录 | 条件未满足，未创建 | N/A |
