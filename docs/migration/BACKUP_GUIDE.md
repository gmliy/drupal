# 备份操作手册

备份命令：

```bash
python3 tools/xedc-recover/bin/xedc-recover --config /var/lib/xedc-recover/config.json backup create --allow-live-read
```

在当前生产主机上，这是只读导出。它使用 `mysqldump --single-transaction`、`ionice -c 3` 和 `nice -n 19`。它不修改数据库、不执行 Drush、不改 Nginx，也不改 `/root/backup.sh`。

## 配置

示例见 `tools/xedc-recover/config.example.json`。数据库密码不要写进配置文件。需要密码时，用 `password_env` 指向环境变量。当前生产库可以由本机 root 通过套接字读取，因此配置里可以只有套接字和数据库名。

解密密钥：

```bash
python3 tools/xedc-recover/bin/xedc-recover credentials init --out /var/lib/xedc-recover/keys/primary.key
```

密钥文件权限是 `600`。丢失密钥就无法恢复。密钥不要和备份放在同一个对象存储桶里，也不要提交到 Git。

## 命令

| 命令 | 作用 |
| --- | --- |
| `backup create --dry-run` | 估算体积并检查磁盘，不写备份 |
| `backup create --type full` | 完整备份 |
| `backup create --type incremental` | 文件增量。数据库每次仍是完整导出。没有父备份时会失败 |
| `backup create --resume --backup-id ID` | 从上次中断的步骤继续 |
| `backup list` | 列出备份 |
| `backup show --backup-id ID` | 查看 manifest。其中没有密码和 hash salt |
| `backup verify --backup-id ID` | 校验 manifest、密文，并解密核对明文 SHA256 |
| `backup upload --backup-id ID` | 上传到配置里的 S3/R2 前缀。本地文件在上传核对完成前不会删除 |
| `backup download --backup-id ID` | 下载并核对 manifest |
| `backup prune --dry-run` | 按保留策略列出将删除的本地备份。增量备份的父备份会被留下 |
| `backup status` | 同 list |

磁盘空间不足时，创建会在写入前失败。同一时间第二份备份会因为锁而失败，避免两份大型任务叠在一起。

## 备份里有什么

- 代码、vendor、自定义模块和主题。不包含 `.git`，因此不会把仓库凭据打进去
- public files 和 private files
- 数据库的 gzip 导出，包含结构和数据
- 一份加密的 settings 摘要，供恢复时取回 hash salt 和原数据库连接信息
- `manifest.json` 记录版本、文件哈希、父备份、加密密钥编号和恢复步骤

对象存储密钥如果存在于 private 目录，会进入加密的 private 包。实验室恢复默认把它们去掉，并强制本地文件模式。

## 定时任务

不要在当前生产主机安装定时器。旧的 `/root/backup.sh` 保持原样。

在新机器上，把单元写到一个明确目录：

```bash
python3 tools/xedc-recover/bin/xedc-recover schedule install \
  --prefix /etc/systemd/system \
  --binary /opt/drupal/tools/xedc-recover/bin/xedc-recover \
  --config /root/xedc-recover.json
```

当前生产主机执行这条命令会返回退出码 3。卸载：

```bash
python3 tools/xedc-recover/bin/xedc-recover schedule uninstall --prefix /etc/systemd/system
```

默认建议每天一份完整备份，保留 7 份每日和 4 份每周。增量备份只有在父链完整时才算有效。
