# 备份格式

## 当前完整包（2.3.0）

`xedc-backup` 生成一个 `xedc-full-YYYYMMDDTHHMMSSZ-6位.tar.zst`。文件名里的时间和末 6 位与备份编号 `xedc-YYYYMMDDTHHMMSSZ-6位` 对应。旁边有同名 `.sha256` 和 `.meta.json`。新包没有 `.credentials.enc`，也没有 `primary.key`。

默认目录是 `/var/lib/xedc-recover/backups/`，可用 `package_dir` 或 `backup_root` 改掉。目录 `0700`，包和校验文件 `0600`。

`PACKAGE.json` 的 `schema` 为 4。`encryption.alg` 为 `none`。同一份 tar.zst 放在本地和 R2 时，备份编号、Manifest、SHA256、Drupal 版本、Git 提交以及数据库和文件清单相同。`.meta.json` 只在本地记录 R2 状态，不改变包的 SHA256。

包内 payload 是明文压缩文件：

```text
payload/database.sql.gz
payload/code.tar.gz
payload/public.tar.gz
payload/private.tar.gz
payload/secrets.json
```

`secrets.json` 仍在权限受控的包内，不单独再复制一份到包外。Manifest 记录每个文件的 SHA256，不记录口令、hash salt 或对象存储密钥的值。

本地列表命令是 `xedc-backup list`。清理记录在备份目录的 `prune.log`。自动清理不会删除唯一一份备份，也不会删除尚未成功上传 R2 的唯一完整备份。

## 旧的目录格式和加密包

每个旧备份也可以是一个目录，权限 `700`。

```text
BACKUP_ID/
  manifest.json
  manifest.sha256
  state.json
  payload/
    code.tar.gz.enc
    public.tar.gz.enc
    private.tar.gz.enc
    database.sql.gz.enc
    secrets.json.enc
```

`manifest.json` 的 `schema` 为 1。它包含备份 ID、类型、`parent_id`、创建时间、工具版本、数据库名和行数、密钥编号、每个密文文件的明文 SHA256 和密文 SHA256、文件清单、删除清单和恢复步骤。它不包含密码、hash salt 或对象存储密钥。

加密文件以 `XEDCENC1` 开头，随后是 JSON 头。每个分块是 12 字节 nonce、4 字节长度和 AES-256-GCM 密文。附加数据是 `密钥编号:分块序号`，因此分块不能被调换。文件以 32 字节明文 SHA256 结尾。

增量备份的 `files` 只列出变化文件，`deleted` 列出删除路径。校验会沿着 `parent_id` 走到完整备份。出现循环或断链就是失败。

代码包排除 `.git`、`_server`、`node_modules`、public files 和 private files。public 与 private 单独打包。解包拒绝绝对路径、`..` 和符号链接成员。

## 完整恢复包

旧的 `xedc-backup` 还会把加密目录打成 `xedc-full-*.tar.zst`。旁边有 `.sha256` 和 `.credentials.enc`。凭据文件是 `secrets.json.enc` 的副本，仍然是密文。解密密钥不在包里。旧文件名 `xedc-full-YYYYMMDD-6位.tar.zst` 仍可恢复。恢复这种包必须提供原来的 `primary.key`。

旧包内是 schema 3 的 `PACKAGE.json`。schema 2 的旧包仍可恢复。清单包含：

```text
PACKAGE.json
environment.json
RESTORE.txt
manifest.json
manifest.sha256
payload/*.enc
service/nginx.conf.template
service/NOTES.txt
tool/
```

`environment.json` 记录 PHP、Nginx、MariaDB、Composer、`/usr/bin/node` 和 `/usr/bin/npm` 的版本，以及 PHP 扩展、`composer.lock` 的 SHA256、存储模式和无关服务的容器名。它只记录口令和 hash salt 是否存在，不记录值。Matomo、Netdata、VPN 和代理的数据不会打进这个包。

`PACKAGE.json` 记录 Git 提交、分支、远端地址（不含凭据）、工作区是否有未提交改动、Drupal 版本、PHP 版本和 `composer.lock` 的 SHA256。远端地址里的用户名和令牌会去掉。

`tool/` 是恢复程序副本，不含测试和密钥。GitHub 仓库保存这份程序和对应提交的代码。网站数据库和上传文件以恢复包为准。提交取不到，或备份时工作区不干净时，恢复使用包内代码快照。
