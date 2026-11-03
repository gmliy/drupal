# 域名迁移手册

不要对数据库做全库 `REPLACE`、`sed` 或整文件字符串替换。PHP 序列化字符串带有字节长度，直接替换会损坏数据。

工具按列读取文本，并按下面的顺序处理：

1. 整个值是 PHP 序列化数据时，只改字符串内容并重写长度。
2. JSON 文本替换 URL，并保持可以被 `json_decode` 解析。
3. 普通 HTML、URL 和纯文本做精确替换。
4. PHP 自定义序列化（`C:`）如果载荷里含有域名，保持原样并记入 `unhandled`。

默认替换完整 URL：

- `https://旧主机`
- `http://旧主机`
- `//旧主机`

不会替换裸主机名，以免改掉邮箱地址。

## 命令

先在隔离数据库上演练。生产库的 3306 端口和本机套接字不能作为写入目标。

```bash
export XEDC_DB_PASSWORD='隔离库密码'
python3 tools/xedc-recover/bin/xedc-recover domain self-test
python3 tools/xedc-recover/bin/xedc-recover --dry-run domain migrate \
  --from https://gitxx.xyz \
  --to https://new.example \
  --host 127.0.0.1 --port 13306 \
  --user xedc_lab --database xedc_lab
```

`--dry-run` 只统计会改的行。确认数字合理后去掉 `--dry-run`。

迁移前先导出回滚文件。隔离测试使用 `dump_for_rollback` / `restore_rollback`。失败时用这份导出恢复，而不是重装站点。

迁移后再次执行同一命令。第二次的 `updated_rows` 应为 0。再搜索旧 URL，结果应为 0。仍出现在 `unhandled` 里的项目需要人工查看，通常是自定义序列化载荷。

同时要改、但不在数据库里自动改的项目：

- `settings.php` 的 trusted host：恢复命令的 `--trusted-host`
- Nginx `server_name`
- HTTPS 证书域名
- Cookie 域，如果站点配置里单独写了
- CDN 和 R2 公开域名。对象存储桶本身不要自动改名
- Cloudflare DNS、缓存和防火墙。本工具只给出建议，不调用 Cloudflare API

建议的 DNS 变更：把新服务器的地址指到新域名，确认 HTTP 站点可用后，再按 SSL 手册申请证书。先降低 TTL。不要在演练机器上修改 `gitxx.xyz` 的现有 DNS。
