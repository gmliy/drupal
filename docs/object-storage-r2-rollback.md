# 回滚

日期：2026-11-02。生产接管没有打开，生产文件没有复制，所以当前不需要数据回滚。

2026-11-02 18:10 UTC 的验收同样没有需要回滚的数据变更。下文里“WAF 规则还没有添加”指的是更早一次记录；后来管理员已经加过私有前缀规则。最终补测见 `docs/object-storage-r2-waf-final-test.md`。那次补测没有再改规则。若之后按该文收紧表达式，回滚方式仍是停用这一条规则。

## 状态

| 项 | 结论 |
| --- | --- |
| 关掉 Runtime / takeover 即回到本地流 | 已完成的设计。现在已经是这个状态 |
| 本地历史文件还在 | 已完成。本次没有删除 |
| 测试对象已从 `_xedc_integration_test/` 删除 | 真实 R2 测试通过 |
| 已迁移对象的按任务回滚 | 代码已完成。这次只有预检查清单，没有已复制对象，所以没有执行 |
| 删除 Bucket 里剩余的 1 个对象 | 需要人工确认。它不在测试前缀，禁止当成测试垃圾删除 |
| 浏览器验证回滚后的页面 | 浏览器实际验证未做。页面本来就还在本地文件上 |

## 2026-11-02 CDN 验收时的回滚点

没有执行生产回滚，也没有需要回滚的数据变更。

- 五个生产开关仍然关闭。PHP-FPM 和 nginx 配置里没有把它们设为 `1`。
- 文件实体仍是 137 行。扫描前后的 URI 校验和一致。
- 公开 128 个文件的本地副本都在。私有 9 个文件的本地副本本来就不在，这次没有删除它们。
- 没有复制生产文件。`s3fs-public/` 和 `s3fs-private/` 下的对象数是 0。
- 测试对象只写在 `_xedc_storage_test/`，测完已删除，两个前缀的测试对象数回到 0。
- Bucket 策略、自定义域名和来源不明的对象都没有改。
- Cloudflare WAF 规则还没有添加，所以没有一条新规则需要撤销。

如果以后按 `docs/object-storage-r2-cdn.md` 加上私有前缀的 WAF 规则，回滚就是停用或删除那一条规则。公开前缀不受这条回滚影响。停用之后，私有前缀会重新从 `cdn.gitxx.xyz` 匿名可读。

## 2026-11-02 回滚检查

没有执行生产回滚。公开 128 个文件的本地副本都在。私有 9 个文件的本地副本已经不在，这不是这次操作删除的。文件 URI 没有修改记录，因为没有改过。迁移清单可以定位每个计划中的对象键，但远程对象还没写入。关掉 Runtime 和 CDN 重写的方法没有变化，现在这两项本来就是关的。`public://` 仍是本地公开流。

## 现在的回滚点

- `XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED` 都未启用。
- `s3fs.use_s3_for_public` 和 `s3fs.use_s3_for_private` 为 false。
- `public://` 是 `PublicStream`。
- `file_managed` 仍是 137 行，URI 未改。
- `xedc_storage_migration_item` 里有这次预检查的清单。没有一行是已复制。不需要按这些行删除远程对象，也不需要 DROP。

## 如果以后已经复制但还没改 URI

1. 停止继续复制。
2. 不要删除站点磁盘上的源文件。
3. 用该任务在 `xedc_storage_migration_item` 里状态为 `verified` 的行做回滚。执行器只删除记录中的键，并且要哈希匹配。
4. 确认文件实体 URI 仍是原来的 `public://` 或 `private://`。
5. 接管如果已经打开，先清掉环境变量并重载 PHP-FPM，让流回到本地，再处理远程对象。

## 如果以后接管已经打开且页面异常

1. 取消 `XEDC_STORAGE_RUNTIME` 和全部 takeover 变量。CDN 变量一并取消，避免本地 URL 仍被改写。
2. 重载 PHP-FPM。
3. 确认 `public://` 重新是 `PublicStream`，并打开一张接管前就存在的图片。
4. 磁盘文件应还在 `web/sites/default/files` 和 `/var/www/drupal/private`。若有人在接管之后执行了“删除本地副本”，那一步不可自动恢复，只能从备份或已校验的 R2 对象拷回。那一步现在被禁止自动执行。

## 明确不回滚的东西

- Drupal Key 里的现有凭证。不要轮换，除非密钥本身泄露。
- S3 File System 模块文件。
- Bucket 中不属于本次任务记录、也不属于 `_xedc_integration_test/` 的对象。
- 用户已保存的 `xedc_core.storage` 配置。更新 10005 和 10007 都没有覆盖已有配置。
