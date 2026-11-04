# XEDC R2 上传、列表、下载与错误诊断验收

验收时间：2026-11-04 12:10 UTC。工作区在 `main`，基线提交 `22f1027`。本轮没有 commit，没有 push。

状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。

## 1. 发现的问题

| 项 | 现象 | 状态 |
| --- | --- | --- |
| A | `download.sh --source=r2 --list` 在没有迁移包时可能没有任何输出并以 0 退出 | FIXED |
| B | 没有直接上传已有 `.tar.zst` 的入口。`--upload-r2` 会重做完整备份 | FIXED |
| C | 2026-11-04 11:01 的生产备份失败后，日志只剩下“删除未完成的备份”，没有失败命令 | FIXED（新机制）；当次命令 NOT TESTED |
| D | 上传成功不能只看 rclone 退出码，必须核对三个对象的大小并回读 SHA-256 | FIXED |
| E | 下载需要同时取回校验文件和公共 manifest，并拒绝覆盖已有文件 | FIXED |

## 2. 根本原因

列表函数把 `rclone lsf` 的退出码 0 直接当成有输出。空前缀也是 0，调用方再无条件 `exit 0`，所以空结果和成功列表无法区分。网络错误如果被当成空列表，也会被写成成功。现实现在先确认 Bucket 根可访问，再列 `xedc-backups/`。根目录不存在记为 Bucket 不存在并返回 rclone 的退出码。前缀不存在记为空列表，退出码 0，并打印“R2 中没有找到迁移包。”

上传只挂在 `backup.sh --upload-r2` 和 `--package-snapshot` 后面。前者重做生产备份，后者从快照重新打包。已有迁移包需要单独入口，并调用同一个 `xedc_r2_upload_package`。

11:01 的备份当时没有安装 ERR trap。EXIT trap 只负责删除未完成目录，所以日志停在两条 Matomo 数据卷导出信息之后。未完成目录已被删除，原始 stderr 不在了。本轮没有重跑这次生产备份。新的 ERR trap 在隔离脚本里核对了行号、退出码、阶段和脱敏。

`set -e` 下，ERR 处理函数里如果先执行别的命令再读 `$?`，退出码会变成 0。管道的 `PIPESTATUS` 必须在 trap 字符串里传入。命令替换会吃掉标准输出，所以诊断写到标准错误和日志。失败后的 DEBUG 仍会看到同一个命令且 `$?` 为 1；只有上一条命令成功时才清除“已经记录”的标记，避免父子进程各记一次。

配置加载会继承调用环境里的 `R2_*`。本轮第一次真实上传停在一个已经删除的测试配置路径，第二次把测试用的本地 endpoint 当成了显式 endpoint。两次都没有上传，本地三个文件的大小和 mtime 没变。`xedc_r2_load` 现在先清掉这六个变量，再读取配置文件。

## 3. 修改文件

- `xedc-recovery/lib/common.sh`
- `xedc-recovery/lib/r2.sh`
- `xedc-recovery/lib/backup.sh`
- `xedc-recovery/lib/package.sh`
- `xedc-recovery/lib/services.sh`
- `xedc-recovery/backup.sh`
- `xedc-recovery/download.sh`
- `xedc-recovery/upload.sh`（新）
- `xedc-recovery/contrib/r2.env.example`
- `xedc-recovery/tests/unit/error_trap.sh`（新）
- `xedc-recovery/tests/integration/r2-transfer.sh`（新）
- `xedc-recovery/tests/integration/r2-minio.sh`
- `xedc-recovery/README.md`
- `README.md`
- `xedc-recovery/docs/KNOWN_ISSUES.md`
- `xedc-recovery/docs/PROGRESS.md`
- `xedc-recovery/docs/PACKAGE_DELIVERY.md`
- `xedc-recovery/docs/REQUIREMENTS.md`
- 本报告

没有改 Drupal 核心、contrib、`config/sync`、`/root/backup.sh`、Nginx、PHP、MariaDB、Docker，也没有改 `/root/.config/xedc-recovery/r2.env`。

## 4. 每个文件改了什么

`lib/common.sh`：`xedc_init` 安装 DEBUG、ERR、EXIT。DEBUG 记下最近几条命令，并在上一条命令成功时清除已记录标记。`xedc_on_err` 只记一次，写阶段、退出码、脚本、函数、行号、快照和脱敏命令；管道带上 `PIPESTATUS`。`xedc_die` 带上同样的位置信息。EXIT 先卸掉 trap，再清理临时文件，最后用原来的退出码退出。清理会删除 `XEDC_R2_CFG` 和状态目录里的 `.rclone.*`。

`lib/r2.sh`：配置只从文件读取。权限接受 600 和 400，拒绝符号链接。Cloudflare 的账户 ID、Access Key、Secret 按十六进制长度检查；显式 endpoint 必须是 `https://账户ID.r2.cloudflarestorage.com`。错误只点字段名。上传先做本地 SHA-256 和 `zstd -t`，再确认远端同名对象。三个对象都已存在且大小、校验文件一致时跳过复制，仍回读迁移包。任一对象存在但内容不同则停在 `overwrite-protected`，不复制、不删除。否则上传三个对象，核对大小，下载回来比对 SHA-256，日志写 `result=PASS`。临时 rclone 配置是 600。列表只显示符合 `xedc-migration-YYYYMMDDTHHMMSSZ.tar.zst` 的对象，带大小、完整键、数量和时间，按文件名倒序。下载三个文件到临时名，校验大小、SHA-256、`zstd -t` 和 manifest 对应关系，再改成最终名字。目标目录已有同名文件时不覆盖。失败只删除本次临时文件。

`lib/package.sh`：增加公共 manifest 对应检查。文件名必须带同一时间戳，schema 为 2，不含密钥类字段，并与归档内 `snapshot/manifest.public.json` 逐字节相同。

`lib/backup.sh`：各阶段调用 `xedc_set_phase`。说明里写明 `--upload-r2` 会做新备份，已有包用 `upload.sh`。上传在 `XEDC_BACKUP_OK=1` 之后调用，失败不删除已完成快照。

`lib/services.sh`：数据卷导出的 `docker run` 和 `zstd` 失败时，把脱敏后的输出写入日志再返回非零。

`backup.sh`：EXIT 处理保留原退出码。未完成且尚未标记成功的目标目录仍会删除。

`upload.sh`：root、普通文件、规定文件名、同目录 `.sha256` 和 `.manifest.public.json`。先做 SHA-256、`zstd -t`、manifest 对应和归档安全检查，再调用 `xedc_r2_upload_package`。`--dry-run` 不连接上传。成功打印远端键。日志在 `/var/log/xedc-recovery/upload-<文件名>.log`，权限 600。不改本地三个源文件。

`download.sh`：`--list` 只在列表函数成功时退出 0。帮助写明最新包、`--backup`、`--backup=` 和三个文件。

`r2.env.example`：说明填的是 S3 密钥而不是 Cloudflare API Token，账户 ID 是 32 位十六进制，endpoint 可省略且必须与账户 ID 对应，权限 600 或 400。

`tests/unit/error_trap.sh`、`tests/integration/r2-transfer.sh`：隔离失败和本地 S3 传输。`r2-minio.sh` 改为生成真实小迁移包，并把日志和状态放到测试目录。

文档改为区分本地备份、打包、独立上传、列表、下载和恢复，并写上本次真实 R2 结果。

## 5. 命令

本地备份，不上传：

```bash
sudo xedc-recovery/backup.sh
```

从已有快照重新打包，不上传：

```bash
sudo xedc-recovery/backup.sh --package-snapshot /var/backups/xedc/20261104T082520Z
```

上传已经存在的迁移包：

```bash
sudo xedc-recovery/upload.sh --file /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst
sudo xedc-recovery/upload.sh --dry-run --file /var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst
sudo xedc-recovery/upload.sh --help
```

新备份并上传刚生成的包：

```bash
sudo xedc-recovery/backup.sh --upload-r2
```

查看、下载：

```bash
sudo xedc-recovery/download.sh --source=r2 --list
sudo xedc-recovery/download.sh --source=r2
sudo xedc-recovery/download.sh --source=r2 --backup 20261104T105132Z --output /root
```

新服务器在解压后的目录里恢复。参数来自 `restore.sh --help`：

```bash
sudo ./restore.sh --mode=full --domain 新域名 --old-domain 旧域名 --new-domain 新域名
```

## 6. 错误处理

隔离脚本 `tests/unit/error_trap.sh` 在本轮结束前重新执行，退出码 0。

| 用例 | 结果 |
| --- | --- |
| 普通命令失败，行号、函数、阶段、退出码、只记一次 | PASS |
| 管道失败，`管道=1 0`，只记一次 | PASS |
| 命令替换失败，命令为 `false`，只记一次 | PASS |
| 子函数失败 | PASS |
| 阶段 `r2-upload` | PASS |
| 清理函数返回非零，原退出码仍是 3 | PASS |
| 替换 `xedc_log` 后不递归 | PASS |
| 命令里的测试秘密被记成 `***` | PASS |

11:01 那次生产备份的原始失败命令没有被这次机制重新捕获。状态是 NOT TESTED。没有为了验证再执行完整生产备份。

## 7. 隔离测试

`tests/integration/r2-transfer.sh` 于 2026-11-04 12:09 UTC 重新执行，退出码 0，结束于 `PASS r2-transfer`。服务是本机 `rclone serve s3`，不是 Cloudflare，也不是生产 Docker。

| 用例 | 结果 |
| --- | --- |
| 文件不存在、非法文件名、目录、符号链接 | PASS |
| SHA-256 缺失或错误、zstd 损坏、manifest 缺失或不匹配 | PASS |
| 配置缺失、权限不是 600/400 | PASS |
| dry-run 不调用 rclone | PASS |
| 空列表有明确文字且退出码 0 | PASS |
| 正常上传、相同对象不覆盖 | PASS |
| 列表数量、倒序、大小，不显示 sha256 和 manifest | PASS |
| 下载最新包和指定包，校验和大小正确 | PASS |
| 无关文件保留，同名文件拒绝覆盖且原文件不变 | PASS |
| 鉴权失败、网络失败、Bucket 不存在，且不写成空列表成功 | PASS |
| 远端 manifest 缺失、SHA-256 错误、zstd 损坏、大小不符，临时文件被删除 | PASS |
| 上传后远端大小不符、回读 SHA-256 不符 | PASS |
| 临时 rclone 配置被删除，日志不含测试秘密，原包保留 | PASS |

`tests/integration/r2-minio.sh` 于 12:09 UTC 重新执行，退出码 0：`PASS r2-roundtrip`、`PASS bad-key-cleaned`、`PASS missing-object`。

`tests/unit/shell_functions.sh` 于 12:10 UTC 重新执行，退出码 0，包含异地目录复制和保留策略。

这些 PASS 都是本次实际执行的结果。

## 8. 真实 R2

使用现有 `/var/backups/xedc/xedc-migration-20261104T105132Z.tar.zst`。没有重新备份，没有重新打包。

前两次尝试没有上传：第一次是调用环境里的 `XEDC_R2_ENV` 指向已删除的测试文件；第二次是调用环境里残留的本地 endpoint。本地三个文件的大小和 mtime 在这两次之后不变。修正加载顺序后的第三次：

| 步骤 | 结果 |
| --- | --- |
| 本地 SHA-256、`zstd -t`、归档成员 | PASS。604337199 字节，57 个条目，50 个文件 |
| 上传三个对象 | PASS。`manifest=uploaded` |
| 远端大小 | PASS。迁移包 604337199，校验文件 106，manifest 3436 |
| 回读 SHA-256 | PASS。`sha256=PASS`，`result=PASS` |
| 列表 | PASS。对象数量 1，只有这个迁移包 |
| 隔离目录下载后再校验 | PASS |
| 本地原文件 | PASS。大小和 mtime 与上传前相同 |

远端键：`xedc-backups/2026/11/xedc-migration-20261104T105132Z.tar.zst`。同一目录有 `.sha256` 和 `.manifest.public.json`。列表没有把后两个显示成迁移包。

上传后的列表里没有其他迁移包。这次没有覆盖或删除别的远端对象。本地另外四个包仍在：`082520Z`（605176048 字节）、`102729Z`（604341076）、`103953Z`（604339279）、`104936Z`（604338714）。没有执行保留策略。

上传日志 `/var/log/xedc-recovery/upload-xedc-migration-20261104T105132Z.log` 权限 600。用配置文件里的值做存在性检查：账户 ID、Access Key、Secret、Provider 和测试秘密都不在日志里。日志含 `临时配置权限=600`、`stage=verified`、`result=PASS`。

## 9. SHA-256

本地校验文件和上传前回读、远端回读、隔离下载文件一致：

`9b88e6517b5612900dd3acee6b8d5c17f4d016b834454e2c04a9104d6ea4a279`

`sha256sum -c` 为 OK。状态 PASS。

## 10. 文件大小

| 文件 | 本地 | 远端 | 隔离下载 |
| --- | --- | --- | --- |
| 迁移包 | 604337199 | 604337199 | 604337199 |
| `.sha256` | 106 | 106 | 106 |
| `.manifest.public.json` | 3436 | 3436 | 3436 |

状态 PASS。

## 11. manifest

外部 manifest 3436 字节。归档成员 `xedc-migration-20261104T105132Z/snapshot/manifest.public.json` 同样 3436 字节，逐字节相同。上传前的对应检查通过。状态 PASS。

## 12. 下载回读

下载到新建的 `/var/lib/xedc-recovery/r2-readback.*`，退出码 0。三个路径都打印出来。迁移包权限 600。`zstd -t` 退出码 0。校验完成后删除了这个回读目录，没有删除 `/var/backups/xedc` 中的原文件。状态 PASS。

## 13. Git 安全检查

2026-11-04 12:11 UTC，在 `main`、基线 `22f1027` 上执行 `git status --short`、`git diff --check` 和 `git diff --stat`。`git diff --check` 退出码 0。未跟踪文件只有本报告、`upload.sh`、`error_trap.sh` 和 `r2-transfer.sh`。

工作区没有 `r2.env`、`age.key`、`.sql`、`.tar.zst` 或 `settings.php`。用生产配置里长度不少于 6 的值扫描 diff：账户 ID、Access Key 和 Secret 都不在 diff 里。`R2_PROVIDER` 的值是文档里的 `Cloudflare`。`r2.env.example` 和帮助文字只有占位符。`xedctestsecret` 只出现在本地 S3 测试夹具里。状态目录没有留下 `r2transfer.*`、`r2test.*`、`r2-readback.*` 或 `.rclone.*`。

没有 commit，没有 push。

## 14. 未完成

| 项 | 状态 |
| --- | --- |
| 重跑 11:01 生产备份以找回当时的失败命令 | NOT TESTED |
| `backup.sh --upload-r2` 的完整生产备份加上传 | NOT TESTED |
| 上传另外四个本地迁移包 | NOT TESTED |
| 把这份生产包在新服务器上执行 `restore.sh` | NOT TESTED |
| 正式 CA、生产 Drush、运行中的服务回滚 | NOT TESTED |

## 15. BLOCKED

本轮 R2 上传、列表和下载没有 BLOCKED。

更早的 PHP 安装矩阵仍是：Ubuntu 20.04 和 Debian 11 为 BLOCKED。那两项不是这次 R2 修复的结果，也没有在本轮重跑。

## 16. 生产环境安全

- 没有修改 Drupal 核心、contrib、`config/sync` 和生产数据库。
- 没有重新导出生产库，没有重做完整生产备份。
- 没有重启 Nginx、PHP-FPM、MariaDB、Docker，没有停止 Matomo。
- 没有修改 `/root/backup.sh` 和生产服务配置。
- 没有删除或覆盖 `/var/backups/xedc` 里的迁移包、校验文件、manifest 和快照。
- 没有修改或打印 `r2.env` 的值。Bucket 权限没有被脚本改动。
- 回读副本是本轮创建的，校验后已删除。
- 没有 commit，没有 push。
