# secure

这个目录只放恢复所需的 age 私钥和 R2 配置。

- `age.key`：现有备份的解密私钥。不要重新生成，否则已经上传的迁移包无法解密。
- `r2.env`：Cloudflare R2 的 S3 访问配置。

GitHub 仓库必须保持 Private。只读 Deploy Key 的私钥放在 `/root/.config/xedc-recovery/github-deploy.key`，不要放进这个目录，也不要提交到其他仓库。

迁移包不会带上这个目录。脚本不把这些文件的内容写入日志。
