# 安全审查

审查范围：`feature/migration-v2` 新增的 `tools/xedc-recover` 和 `docs/migration`。审查时没有把生产口令、对象存储密钥或 GitHub 令牌写进文档、测试输出或提交说明。

## 生产边界

以下操作在生产网站目录存在时退出码为 3，`--force` 不会改变结果：

- 安装软件包
- 向 `/etc/systemd` 安装定时任务
- 重置管理员密码
- 连接本机 3306 或数据库套接字做写入
- 把恢复目标设为 `/var/www/drupal`
- 在生产机上使用 replacement 角色

依据是 `tests/test_core.py` 的安全测试，连续两轮通过。

数据库导出通过 `0600` 的临时 defaults 文件调用 `mysqldump --single-transaction`，口令不出现在进程参数里。日志经过脱敏。按目录删除远端备份仍然直接拒绝。保留策略只删除 `xedc-recover/packages/` 下过期的 `xedc-full-*` 对象，名称不符合这个格式的对象不会删除。

## 加密和备份内容

- 算法是 AES-256-GCM。密钥文件单独存放，模式 `600`。
- manifest 记录哈希和计数，不记录口令、`hash_salt` 或对象存储密钥。
- 解包拒绝绝对路径、`..`、设备文件和指向目标外的符号链接。包内硬链接复制成普通文件。
- lab 角色删除 `xedc-storage-keys`，并把运行配置改成本地文件、暂停写入。
- 恢复改写 `settings.php` 时保留 `hash_salt`，只替换最后一处真实数据库配置。

生产只读备份 `xedc-20261103T135450Z-3cd3bf` 的 `backup verify` 为 PASS。备份和密钥位于 `/var/lib/xedc-recover`，不在 Git 工作区。

## 演练容器

容器非特权，没有 docker.sock，没有 host 网络，没有生产目录挂载。端口只绑定 `127.0.0.1:18080`。演练结束后删除该容器，避免一份含有站点数据的恢复结果继续对外服务。加密备份仍留在宿主机的备份目录。

## 扫描

对工具和迁移文档搜索了 `ghp_`、`AKIA` 和私钥开头。命中三处，都不是生产凭据：

- `redact.py` 里的脱敏规则本身包含这些前缀。
- `tests/test_lab.py` 使用 AWS 文档公开的签名示例 `AKIAIOSFODNN7EXAMPLE`。

新文档和工具代码里没有生产数据库口令，也没有 GitHub 个人访问令牌。

## 2026-11-03 最终审计

再次确认生产 `settings.php`、`/etc/nginx/conf.d/drupal.conf` 和 `/root/backup.sh` 的修改时间没有变化。新的演练容器仍是非特权、无 docker.sock、无 host 网络，只读挂载密钥、工具和备份，端口只绑定 `127.0.0.1:18080`。审计结束后容器已删除。

恢复程序原先把所有普通文件改成 `644`，Drush 启动脚本因此不能执行。现已保留归档中的可执行位，`settings.php` 仍是 `440`。该修复有隔离测试，并在随后两轮全新恢复里确认 `vendor/bin/drush` 为 `755`。

正式 R2 上传和正式证书申请仍然没有执行。审计日志位于 `/var/lib/xedc-recover/audit/`，里面没有写入 Git。

## 2026-11-03 完整恢复包

恢复包使用原来的 AES-256-GCM 密文。`PACKAGE.json` 的 `key_included` 为 false。旁边的 `.credentials.enc` 仍是密文，模式 `600`。明文清单记录口令和 hash salt 是否存在，不记录值。对包内说明文件和 manifest 搜索 `ghp_`、`AKIA` 和私钥开头，没有命中。

上传恢复包时拒绝文件名以 `.key` 结尾的对象。本地 S3 测试确认下载结果里没有密钥文件。远端删除函数仍然直接拒绝。

一键恢复在生产目录存在时退出码 3。目标已有网站且没有本次安装状态时拒绝覆盖。成功后的重复执行不会重写。自签私钥模式 `600`。程序不改 Cloudflare DNS，也不申请正式证书。

APT 仓库公钥写成 `644`。这是公钥，不是站点密钥。原因是 `umask 077` 会让 APT 的非 root 用户读不到 `signed-by` 文件。

生产文件的修改时间在这次测试后没有变化。演练容器在取回证据后删除。

## 仍须人工保管

- `/var/lib/xedc-recover/keys/primary.key` 不进入 Git。丢失后已有备份无法解密。
- 主分支历史上已经跟踪 `web/sites/default/settings.php`。这次提交不新增其中的值，也不改写历史。
- 正式证书申请没有执行。备份上传只发生在 `xedc-recover/` 前缀。

## 2026-11-03 一键命令

`xedc-backup` 把 621434331 字节的加密包上传到桶 `dclili` 的 `xedc-recover/packages/`。上传前该前缀为空。上传后前缀里只有这一份包、SHA256 和 `.credentials.enc`。HEAD 的 `Content-Length` 与本地文件一致。清单和日志里没有 `ghp_`、`key_b64` 或私钥。Git 远端被写成 `https://github.com/xxedc/drupal.git`，没有用户名和令牌。

保留策略在生产前缀上没有删除任何对象。单元测试在本地 S3 上确认：`public/keep.jpg` 和 `packages/public-image.jpg` 不会被删除，直接删除 `public/` 对象会拒绝。

从 R2 下载并恢复的容器没有挂载本地备份包，没有生产目录，没有 docker.sock，不是特权容器。恢复后的对象存储密钥目录不存在，R2 检查只列出备份前缀，`writes` 为 false。容器和临时凭据文件已删除。

生产 `settings.php` 的修改时间仍是 2026-11-02 09:33:14 UTC，`/root/backup.sh` 仍是 2026-11-02 22:50:02 UTC。首页在测试前后都是 HTTP 200。
