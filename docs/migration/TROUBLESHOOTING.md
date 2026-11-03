# 故障排查

| 现象 | 原因 | 处理 |
| --- | --- | --- |
| 退出码 3，提示生产网站目录 | 这台机器仍有 `/var/www/drupal/web/core` | 停止。到新机器上操作，或只做只读备份 |
| `--force` 之后仍然拒绝 | `--force` 不能绕过生产保护 | 这是预期行为 |
| 退出码 4，解密失败 | 密钥不对或文件损坏 | 换正确密钥。不要删除本地备份。再跑 `backup verify` |
| manifest 校验失败 | 文件被改过或不完整 | 重新下载。父备份缺失时增量备份不能用 |
| 已有备份任务在运行 | 锁文件被占用 | 等当前任务结束。不要并行跑两份全量备份 |
| 磁盘空间不足 | 需要大约源数据 2.2 倍的临时空间 | 换磁盘或清理本工具自己的旧备份。不要清理生产文件 |
| 增量备份失败，提示没有完整备份 | 链上没有父备份 | 先做 `backup create --type full` |
| 数据库导入失败 | 目标不是空库，或版本不兼容 | 看命令输出的最后一部分。不要在生产库上重试 |
| 首页 400 或 trusted host | Host 头不在 trusted host 里 | 恢复时传入 `--trusted-host`，或修正 Nginx `server_name` |
| 图片 404 | public 目录没有链接到 `sites/default/files` | 确认恢复报告里的 public 解包数量，并检查符号链接 |
| 私有文件不可用 | 路径仍指向旧服务器 | `file_private_path` 应指向新机器上的 private 目录 |
| 对象存储被第二份网站改写 | 演练使用了 replacement | 演练必须用 `--role lab` |
| 域名迁移后页面异常 | 有 `unhandled` 记录 | 用迁移前的 SQL 回滚，再处理未支持的字段 |
| Nginx 测试失败 | 新配置语法错误 | `ssl rollback`，再执行 `ssl test` |
| PHP 不是 8.5 | 软件源没有 ondrej 的 PHP 8.5 | 安装程序返回退出码 5，状态是 BLOCKED。不要静默改用别的主版本 |
| 上传对象存储失败 | 前缀、区域或密钥不对 | 本地备份仍在。修正配置后重新 `backup upload` |
| Nginx 报 pid 目录不存在 | `ssl render` 的 `--prefix` 还没创建 | `mkdir -p` 该目录后再执行 `nginx -t` |
| 502，连接 PHP 套接字被拒绝 | Nginx 用户是 `nginx`，套接字属于 `www-data` | 把 `listen.group` 设为 `nginx`，模式 `0660`，然后重启 PHP-FPM |
| 数据库 Access denied，用户是恢复用户 | 用户已存在，密码仍是上一次的 | 重新执行带 `--import-database` 的恢复。程序会 `ALTER USER` |
| 备份包包含不安全路径 | 成员名有 `..`、绝对路径，或符号链接指向包外 | 不要解包。这份备份不能当可信备份使用 |
| APT 报 `NO_PUBKEY`，退出码 5 | 仓库公钥被 `umask 077` 写成 `600`，APT 的非 root 用户读不到 | 安装程序会把公钥改成 `644`。不要把 `umask 077` 包住整次安装 |
| Nginx 报 `Address already in use` | 旧进程的 pid 不在 `service nginx stop` 查找的位置 | 程序会按配置里的 pid 发送 quit，再启动。不要同时留着一个手工启动的 Nginx |
| 一键恢复拒绝覆盖 | 目标目录已有 `web/core`，且没有这次安装的状态文件 | 这是预期行为。确认目录可以清空后，删除目标和状态文件再执行 |
| 第二次一键恢复立刻返回 | 成功报告的 SHA256 与当前包一致 | 这是重复执行保护。要重做完整恢复，先删除目标目录 |

恢复失败时保留目标目录和 `RESTORE_REPORT.json`。不要删除数据库后执行 Drupal 安装程序，那会制造一个空站点并让人误以为恢复成功。
