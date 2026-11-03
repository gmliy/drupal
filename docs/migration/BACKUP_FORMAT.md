# 备份格式

每个备份是一个目录，权限 `700`。

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
