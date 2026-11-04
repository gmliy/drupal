# 架构

普通用户使用两条命令。`xedc-backup` 在旧服务器生成本地完整包，并可以把它上传到 R2，再下载回来核对 SHA256。`xedc-restore` 可以从本地或 R2 安装运行环境并恢复网站。分步命令仍然保留。

GitHub 保存程序、`composer.lock`、`config/sync` 和恢复工具。R2 只在 `xedc-recover/` 前缀保存完整备份包。新包没有客户端加密。本地目录是 `0700`，文件是 `0600`。R2 使用私有桶、HTTPS 和服务端静态加密，不是端到端加密。旧的 AES-256-GCM 包仍把密钥留在本机的 `primary.key`。

备份记录 Git 提交。工作区干净时，恢复会尝试取出这个提交，并核对 `composer.lock`。提交不可用，或备份时工作区有未提交改动时，使用包内代码快照，避免用另一份代码去配这套数据库。

`xedc-recover` 是生产网站旁边的一个 Python 3.12 程序。它不安装进 Drupal，不改模块、主题和 `config/sync`。

```text
tools/xedc-recover/
  bin/xedc-recover          分步命令入口
  bin/xedc-backup           本地包、R2 上传、列表、校验、删除和重新上传
  bin/xedc-restore          一键恢复和对象存储下载
  xedc_recover/             备份、恢复、加密、S3、域名、证书、保护规则
  php/domain_migrate.php    域名替换。序列化长度由 PHP 按字节重写
  lab/drill.sh              只在没有生产目录的机器里运行
  tests/                    隔离测试
```

## 保护边界

生产目录存在时，写入 `/var/www/drupal`、`/etc/nginx`、`/etc/php`、数据库 3306、systemd 和 `/root/backup.sh` 都会失败。`--force` 不参与这些判断。

新机器没有生产目录时，必须先有 `/etc/xedc-recover-lab` 才能写系统配置。`install` 会在确认没有生产目录后创建这个标记。

数据库写入还有第二层判断：生产目录存在时，拒绝本机套接字和 3306。隔离库使用别的端口，并核对 `@@hostname` 不是生产主机名。

## 备份

完整备份包含代码、public、private、数据库和 settings 摘要。增量备份只打包变化的文件，并记录删除；数据库仍然完整导出。恢复时从完整备份开始叠增加量。父备份缺失则校验失败。

`xedc-backup` 的新包把这些内容放进同一个 `tar.zst`，`encryption.alg` 为 `none`。默认先写本地目录，再上传 R2，然后下载核对 SHA256。R2 失败时保留本地包并返回失败，不会把任务报成完全成功。`xedc-backup upload --backup` 只上传已有的包。仅 R2 模式在远端校验通过后才删除临时文件。

旧包仍使用 AES-256-GCM，8 MB 分块，密钥文件与备份分开。恢复旧包时才读取 `primary.key`。

## 恢复

目标目录由调用者指定。lab 角色切断对象存储，避免演练站点写生产 R2。replacement 角色保留存储配置，只应在旧服务器已经不存在时使用。

`xedc-restore` 可以指定 `--source local`、`--source r2` 或 `--file`。它先核对 SHA256 和 Manifest。新包直接读出文件；旧包才解密。生产目录存在时，只要没有同时跳过服务安装和数据库导入，就直接拒绝。目标里已有网站、且没有这次安装的状态文件时，也拒绝覆盖。同一份成功报告再次执行会返回，不重写文件。

对象存储沿用原来的 S3 客户端。上传的是完整包和它的 SHA256。旧包还会带上加密的凭据副本。密钥文件被拒绝上传。按目录删除远端备份仍然直接拒绝。保留策略只删除名称符合 `xedc-full-*` 的过期包。

一键恢复会写 Nginx 和一份自签证书。它不修改 Cloudflare DNS，也不申请正式证书。新机器的 IP、主机名、域名和目录由命令参数决定，不从旧服务器复制。

## 测试环境

单元测试使用临时目录。域名测试使用独立容器 `xedc-recover-db`，发布在 `127.0.0.1:13306`。对象存储测试使用进程内的 S3 兼容服务 `127.0.0.1:19000`，并对照 AWS 公开的 SigV4 示例。全新安装演练使用独立容器，不挂载生产目录，不使用 host 网络，不使用 Docker 套接字。

生产 R2 桶没有用于测试。没有单独的测试桶凭据时，真实 Cloudflare 上传记为 BLOCKED。
