# 生产复制前两项阻塞说明

日期：2026-11-02，18:18 UTC。

这份说明解释同一轮整改复测。原始记录在：

- `docs/object-storage-r2-final-acceptance.md`
- `docs/object-storage-r2-waf-final-test.md`
- `docs/object-storage-r2-cache-investigation.md`

本轮没有修改 Cloudflare，没有修改上传代码，没有复制历史文件，没有改 File Entity URI，也没有打开五个生产开关。`public://` 仍由本地 `PublicStream` 处理。测试对象写在专用前缀里，测完已删除。

## 结论

还不能开始阶段 A，也不能进入生产接管。

两件要先由管理员在 Cloudflare 控制台完成：

1. 私有前缀的 WAF 规则改成不区分大小写，并覆盖编码后的原始 URI。
2. 核对 Browser Cache TTL。图片和视频的 CDN GET 仍把 R2 上的 60 秒改成 14400 秒。

`xedc_core` 没有需要修改的上传错误。环境里没有 Cloudflare API Token，程序读不到控制台里的当前值。

## 私有路径

测试前先用 S3 HeadObject 确认对象存在。403 且正文是 Cloudflare 拦截页、不含测试标记，才记成拦住。404 不算拦住。一旦某个变体匿名读到对象正文，就停止后面的暴露面请求。

| 请求 | 本轮结果 |
| --- | --- |
| 正常 `s3fs-private/` | 拦住。403 拦截页 |
| `%2F` 和 `%2f` | 拦住。对应对象存在 |
| 查询参数，以及查询参数里的 `%2F` | 拦住 |
| 多余斜杠、`../` | 拦住。多余斜杠的字面量键也存在 |
| `/./` | URL 返回 403 拦截页。字面量 `./` 键没有创建成功，这个键本身还不能算已验证 |
| 大写 `S3FS-PRIVATE/` | 失败。HTTP 200，正文就是这个对象 |
| 混合大小写 `s3fs-Private` | 本轮没请求。对象创建后，因上一行失败而删除 |
| `%252F`、`%25252F` | 本轮没请求 |

现有规则能拦住小写规范前缀。它区分大小写，所以 `S3FS-PRIVATE/` 下的对象可以从 `cdn.gitxx.xyz` 匿名读取。

18:07 的历史测试里，`%252F` 也曾返回 200，读到的是键名中带有字面量 `%2F` 的测试对象。那次结果不能当成 18:18 的复测。管理员还没有部署当时建议的规则。

建议在 Cloudflare 为 `cdn.gitxx.xyz` 使用 Block，匹配不区分大小写的 `s3fs-private`，同时看路径、完整 URI 和原始 URI。不要改 Bucket Policy。部署后再测大写、混合大小写、`%252F` 和三层编码。通过标准是全部为 403 拦截页，正文不含测试标记。

## 缓存头

新的测试 PNG 和测试 MP4 上传时都写成 `Cache-Control: public, max-age=60`。

| 来源 | PNG | MP4 |
| --- | --- | --- |
| R2 HeadObject | `public, max-age=60`，`image/png`，70 字节 | `public, max-age=60`，`video/mp4`，9829 字节 |
| 第一次 CDN GET | `public, max-age=14400`，`MISS` | `public, max-age=14400`，`MISS` |
| 第二次 CDN GET | `public, max-age=14400`，`HIT`，`Age: 2` | `public, max-age=14400`，`HIT`，`Age: 2` |

两边 ETag 相同，CDN 返回的是刚上传的那个对象。响应里没有 Cache Rule 名称，也没有 `CDN-Cache-Control`。`Age: 2` 说明第二次 GET 来自边缘缓存。

代码只把调用方传入的缓存头写入对象，没有 `14400`，上传后也没有再改 Metadata。本机 nginx 同样没有这个值。所以没有改 `xedc_core`。在 Cloudflare 覆盖浏览器缓存头时，改上传代码不能让 GET 变回 60 秒。

14400 等于 4 小时。本轮没有读到控制台，不能把某个开关的当前值写成已经核实。管理员需要打开托管 `cdn.gitxx.xyz` 的区域，进入 Caching、Configuration，把 Browser Cache TTL 设为 Respect Existing Headers。不要新建 Cache Rule，不要把缓存时间设成 4 小时或一年 immutable。如果这里已经是 Respect Existing Headers，再查看 Cache Rules 和 Edge Cache TTL，并把看到的值记下来。

改完后用新的测试 PNG 和 MP4 复测。两次 GET 的 `Cache-Control` 都应仍是 `public, max-age=60`。

## 还不能复制的原因

128 个公开文件的本地副本和哈希之前已经盘点过，但复制会把对象放到当前这个自定义域名后面。私有前缀的大小写变体还能匿名读取，图片和视频的边缘缓存头也还没核对。阶段 A 要等这两项复测通过后再开始。生产接管继续关闭。

`r2.dev` 是否已禁用，仍要管理员在 R2 控制台确认。配置里没有保存这个主机名。
