# secure

这个目录放恢复所需的 age 私钥、R2 配置和只读 GitHub Deploy Key。

- `age.key`：现有备份的解密私钥。不要重新生成，否则已经上传的迁移包无法解密。
- `r2.env`：Cloudflare R2 的 S3 访问配置。
- `github-deploy.key`：读取本私有仓库的 Deploy Key。备份时从服务器副本更新。新服务器拉取仓库或解密迁移包后自动安装，不用单独拷贝。

GitHub 仓库必须保持 Private。Deploy Key 和 age 私钥不是同一把钥匙。迁移包的脚本目录不带这些文件；Deploy Key 只额外进入加密的 `secrets.enc`。

迁移包不会带上这个目录。脚本不把这些文件的内容写入日志。
