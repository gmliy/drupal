# 架构

`xedc-recover` 是生产网站旁边的一个 Python 3.12 程序。它不安装进 Drupal，不改模块、主题和 `config/sync`。

```text
tools/xedc-recover/
  bin/xedc-recover          分步命令入口
  bin/xedc-backup           完整恢复包。create --full 会打包已有加密备份
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

完整备份包含代码、public、private、数据库和加密的 settings 摘要。增量备份只打包变化的文件，并记录删除；数据库仍然完整导出。恢复时从完整备份开始叠增加量。父备份缺失则校验失败。

加密使用 AES-256-GCM，8 MB 分块，密钥文件与备份目录分开。上传的是密文。本地密文在远端列表和大小核对完成之前不会删除。

## 恢复

目标目录由调用者指定。lab 角色切断对象存储，避免演练站点写生产 R2。replacement 角色保留存储配置，只应在旧服务器已经不存在时使用。

`xedc-restore install` 先核对恢复包，再调用原来的解密和恢复。生产目录存在时，只要没有同时跳过服务安装和数据库导入，就直接拒绝。目标里已有网站、且没有这次安装的状态文件时，也拒绝覆盖。同一份成功报告再次执行会返回，不重写文件。

对象存储沿用原来的 S3 客户端。`xedc-backup upload` 上传恢复包、SHA256 和加密凭据文件。`xedc-restore fetch` 下载并核对。密钥文件被拒绝上传。远端删除仍然直接拒绝。

一键恢复会写 Nginx 和一份自签证书。它不修改 Cloudflare DNS，也不申请正式证书。新机器的 IP、主机名、域名和目录由命令参数决定，不从旧服务器复制。

## 测试环境

单元测试使用临时目录。域名测试使用独立容器 `xedc-recover-db`，发布在 `127.0.0.1:13306`。对象存储测试使用进程内的 S3 兼容服务 `127.0.0.1:19000`，并对照 AWS 公开的 SigV4 示例。全新安装演练使用独立容器，不挂载生产目录，不使用 host 网络，不使用 Docker 套接字。

生产 R2 桶没有用于测试。没有单独的测试桶凭据时，真实 Cloudflare 上传记为 BLOCKED。
