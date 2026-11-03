# 凭据与账号手册

## 要分开保存的东西

| 物品 | 放在哪里 | 不要放在哪里 |
| --- | --- | --- |
| 备份解密密钥 | 密码管理器，文件权限 600 | Git、备份压缩包旁边的同一个对象存储桶、聊天记录 |
| R2/S3 访问密钥 | 环境变量或 600 配置 | 命令行历史、manifest、日志 |
| 新数据库密码 | `/root/xedc-restore-db.password` | 恢复报告 |
| Drupal `hash_salt` | 只存在于加密备份 | 文档和 manifest |
| 会员密码 | 不保存明文。数据库里的哈希原样恢复 | 不要批量重置 |

正常恢复不会修改管理员密码，也不会修改会员密码。`hash_salt` 必须与备份一致，否则现有密码哈希无法验证。

## 密钥轮换

生成新密钥后，用新密钥重新执行一次完整备份。旧备份仍用旧密钥解密。不要覆盖旧密钥文件，直到确认新备份可以 `verify`。

## 应急管理员

仅在新机器上、并且你明确需要时：

```bash
python3 tools/xedc-recover/bin/xedc-recover credentials reset-admin --reset-admin --json
```

没有 `--reset-admin` 时命令拒绝执行。生产目录存在时同样拒绝。输出的密码只在这次 JSON 里出现，请立刻写入 `600` 文件，不要贴到工单。

一次性登录材料：

```bash
python3 tools/xedc-recover/bin/xedc-recover credentials login-link \
  --login-link --target /root/xedc-login --user admin
```

它写入的是令牌哈希和过期时间，不是可重复使用的口令。生产目录存在时拒绝生成。

## 日志

日志会把 `password`、`secret`、`token`、`hash_salt`、`access_key` 和私钥块替换成 `***`。这不能代替“不要把密钥写进命令行参数”。数据库客户端使用临时的 `600` defaults 文件，用完即删。
