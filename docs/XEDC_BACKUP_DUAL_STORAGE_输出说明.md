# 本地备份与 R2 双存储说明

日期：2026-11-03  
分支：`feature/migration-v2`  
提交：`b0b2231`  
工具版本：2.3.0

本地备份和 Cloudflare R2 保存同一份完整包。新备份不再生成用户单独保管的 `primary.key`。2026-11-03 之前用 AES-256-GCM 加密的旧包仍然要原来的密钥才能恢复。

没有合并 `main`，没有部署，没有改生产数据库、生产媒体和已有生产备份。

## 命令

| 需求 | 命令 |
| --- | --- |
| 本地 + R2 备份 | `xedc-backup` |
| 仅本地备份 | `xedc-backup --local-only` |
| 仅 R2 备份 | `xedc-backup --r2-only` |
| 查看本地备份 | `xedc-backup list` |
| 从 R2 恢复 | `xedc-restore --domain 新域名` |
| 从本地恢复 | `xedc-restore --source local --backup 备份编号 --domain 新域名` |
| 指定 R2 备份恢复 | `xedc-restore --source r2 --backup 备份编号 --domain 新域名` |

本地包还可以：

```bash
xedc-backup verify --backup 备份编号
xedc-backup delete --backup 备份编号
xedc-backup upload --backup 备份编号
```

`upload` 重新上传已经打好的本地包，不重新导出数据库和文件。

## 备份行为

默认目录是 `/var/lib/xedc-recover/backups/`。配置里的 `package_dir` 可以改成别的目录；留空时使用 `backup_root`。目录权限 `0700`，备份文件 `0600`。请用 root 执行，网站运行用户不能读取数据库和配置。

`xedc-backup` 按这个顺序做：

1. 在本地生成完整包 `xedc-full-*.tar.zst`。
2. 计算并核对 SHA256，同时核对 Manifest。
3. 把同一份文件上传到 R2。
4. 从 R2 下载回来，再核对 SHA256、备份编号和 Manifest。
5. 打印本地路径、R2 对象位置、备份编号、大小和两边的校验结果。

三种模式：

| 模式 | 结果 |
| --- | --- |
| 默认，本地 + R2 | 先完成本地包，再上传。R2 失败时保留本地包，状态是 `PARTIAL`，退出码不是 0 |
| `--local-only` | 不读取 R2 凭据，不连接 R2。只生成本地包并核对 SHA256 |
| `--r2-only` | 先写临时文件。上传成功并且远端下载校验通过之后，才删除临时文件。失败时临时文件留下 |

R2 上传中断不会改写已经完成的本地包。稍后执行 `xedc-backup upload --backup 备份编号` 即可重试。

`xedc-backup list` 显示编号、创建时间、大小、SHA256 和 R2 状态。保留份数用 `retention.keep`，保留天数用 `retention.days`。`days` 为 0 表示不按天数删除。每次清理都写在备份目录的 `prune.log`。自动清理不会删除唯一一份备份，也不会删除尚未成功上传到 R2 的唯一完整备份。磁盘空间不够放下完整包时，程序在写入前拒绝。

本地文件和 R2 对象使用同一种包。同一份备份的编号、Manifest、SHA256、Drupal 版本、Git 提交、数据库清单和文件清单相同。指定本地文件恢复时，必须同时通过旁边的 `.sha256` 和包内 Manifest。

## 安全边界

新包的 `encryption.alg` 是 `none`，`PACKAGE.json` 的 schema 是 4。包内没有 `primary.key`，也没有 `key_id` 或 `key_b64`。

本地包没有客户端加密。保护方式是目录权限、服务器登录权限和磁盘本身的安全。

R2 使用私有桶、HTTPS 和服务端静态加密。这不是端到端加密。拿到服务器登录权限或 R2 凭据的人可以读取备份内容。R2 凭据只用来访问桶，不参与 SHA256 和 Manifest 校验。

旧包仍然是 AES-256-GCM。恢复这种包时，程序会要求原来的 `primary.key`。没有这把密钥会停止，不会当成新格式去读。

## 验收

隔离测试连续两轮通过。每轮 33 项。测试只用临时目录和 `127.0.0.1` 上的内存对象存储。

| 轮次 | 时间 (UTC) | 结果 |
| --- | --- | --- |
| 1 | 18:06:18–18:06:30 | 33 tests OK，10.821 秒 |
| 2 | 18:06:49–18:07:00 | 33 tests OK，10.680 秒 |

| 项 | 结果 |
| --- | --- |
| 仅本地备份 | 通过。不连接对象存储。目录 `0700`，包 `0600`。没有生成密钥 |
| 仅 R2 备份 | 通过。远端 SHA256 一致后删除临时文件。上传失败时临时文件仍在 |
| 默认双存储 | 通过。本地包和下载包的编号、SHA256、Manifest 一致 |
| R2 不可达 | 通过。状态 `PARTIAL`，本地包保留 |
| 重新上传 | 通过。没有重新打包。本地文件修改时间不变 |
| 本地完整性 | 通过。`xedc-backup verify` 核对 SHA256 和 Manifest |
| R2 下载 SHA256 | 通过 |
| 本地恢复 | 通过。在临时目录解出数据库压缩包和文件 |
| R2 恢复 | 通过。同一夹具，不依赖本地那一份 |
| 两处恢复结果 | 通过。`database.sql.gz`、public、private 和代码文件的 SHA256 相同 |
| 自动清理 | 通过。唯一备份留下。尚未上传成功的唯一完整备份留下。删除写进 `prune.log` |
| 旧加密包 | 通过。有原来的密钥可以恢复文件。没有密钥时要求 `primary.key` |

另外用夹具，而不是生产库，向桶 `dclili` 的 `xedc-recover/lab-dual/` 上传了一份新格式包。

| 项目 | 值 |
| --- | --- |
| 时间 | 2026-11-03 18:07:40 UTC |
| 编号 | `xedc-20261103T180740Z-50c92a` |
| 大小 | 64592 字节 |
| SHA256 | `4a91f81523dd4504536614249663a0b80f9fc047d23f3b08cd8a4dbf64004d5d` |
| 下载核对 | 与上面的 SHA256 相同 |
| 文件权限 | `0600` |

核对完成后，这两个测试对象已从 `xedc-recover/lab-dual/` 删除，该前缀已空。上传前、上传后和删除之后，`xedc-recover/packages/` 都是同样的 6 个对象，Content-Length 没有变化：

| 对象 | Content-Length |
| --- | ---: |
| `xedc-full-20261103T170644Z-764a29.tar.zst` | 621434331 |
| `xedc-full-20261103T170644Z-764a29.tar.zst.sha256` | 108 |
| `xedc-full-20261103T170644Z-764a29.credentials.enc` | 878 |
| `xedc-full-20261103T172512Z-445dba.tar.zst` | 639875087 |
| `xedc-full-20261103T172512Z-445dba.tar.zst.sha256` | 108 |
| `xedc-full-20261103T172512Z-445dba.credentials.enc` | 878 |

没有写入 `public/` 或 `private/`。

## 这次没有做的事

本机仍有生产网站目录，`xedc-restore` 会直接拒绝。这次没有为新格式再导出生产数据库，也没有在 Ubuntu 容器里把新格式包装成一个对外网站。上面的本地恢复和 R2 恢复，是在隔离目录里解出数据库压缩包和文件，并比较哈希。

更早的 Ubuntu 容器整站恢复仍然只证明旧的加密包。正式证书、DNS、replacement 角色的整站启动、生产 systemd，以及从 github.com 克隆代码，维持原有记录，这次没有新的通过结果。

## 文档

同一提交里更新了根目录 `README.md`、`QUICK_RESTORE.md`，以及 `docs/MIGRATION_MASTER_SPEC.md`、`docs/migration/` 下的架构、备份格式、安全审查、测试报告、进度和变更记录。
