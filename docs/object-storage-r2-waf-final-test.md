# WAF 私有前缀补测

## 本轮：2026-11-02 18:18 UTC

没有修改 Cloudflare。管理员尚未部署上一轮建议的规则。本轮新建测试对象，先用 HeadObject 确认存在，再发 CDN 请求。大写前缀返回 200 后停止后续暴露面测试。8 个本轮对象都已删除。

| 变体 | 本轮 | 证据 |
| --- | --- | --- |
| 正常 `s3fs-private/` | 已阻止 | 对象存在。403，4542 字节拦截页，无标记。`cf-ray` 存在 |
| `%2F` | 已阻止 | 规范对象和字面量 `%2F` 键都存在。403 拦截页，无标记 |
| `%2f` | 已阻止 | 同上 |
| 查询参数 `?x=1` | 已阻止 | 规范对象存在。403 拦截页，无标记 |
| 查询参数 `?x=%2Fs3fs-private` | 已阻止 | 规范对象存在。403 拦截页，无标记 |
| 多余斜杠 | 已阻止 | 规范对象和字面量 `//` 键都存在。403 拦截页，无标记 |
| 点段 `/./` | 已阻止 URL，键本身尚未验证 | 字面量 `./` 键签名失败，没有创建成功。URL 仍返回 403 拦截页，不是 404。不能把不存在的键算成已证明不可读 |
| `../` | 已阻止 | 规范对象存在。403 拦截页，无标记 |
| 大写 `S3FS-PRIVATE` | 未通过 | 该键存在。HTTP 200，`text/plain`，正文就是这个对象。`CF-Cache-Status: DYNAMIC`，缓存头是上传时的 `private, max-age=0, no-store` |
| 混合大小写 `s3fs-Private` | 尚未验证 | 对象已创建，但在大写前缀返回 200 后没有请求。对象已删除 |
| `%252F` | 尚未验证 | 本轮没有请求。18:07 的 200 仍是历史结果 |
| `%25252F` | 尚未验证 | 本轮没有请求 |

现有规则能拦住小写规范前缀、单次编码斜杠、查询参数、多余斜杠和 `../`。它区分大小写。`S3FS-PRIVATE/` 下的对象可以从 CDN 匿名读取。这是本轮新的失败，不是 404。

最小规则仍由管理员手工部署，程序没有改 Cloudflare：

```txt
(http.host eq "cdn.gitxx.xyz" and (
  lower(http.request.uri.path) contains "s3fs-private" or
  lower(http.request.uri) contains "s3fs-private" or
  lower(raw.http.request.uri) contains "s3fs-private"
))
```

动作是 Block。`lower()` 用来盖住 `S3FS-PRIVATE`。原始 URI 里的 `s3fs-private%252F` 也包含 `s3fs-private`。不要改 Bucket Policy。部署后再做完整复测，包括本轮没发的混合大小写、`%252F` 和 `%25252F`。预期全部是 403 拦截页，正文不含测试标记。

## 历史：2026-11-02 18:07 UTC

没有修改 WAF、Bucket Policy、域名或缓存规则。测试对象测完已删除，删除后 HeadObject 确认不存在。`s3fs-private/` 对象数回到 0。

## 结论

规范私有路径仍然被拒绝。双重编码 `%252F` 匿名读到了另一个测试对象，判定为安全失败。发现 200 之后停止了其余变体，避免继续扩大读取。

| 变体 | 本轮结果 |
| --- | --- |
| 正常私有路径 | 已阻止 |
| `%2F` | 已阻止 |
| `%2f` | 已阻止 |
| `%252F` | 未通过。HTTP 200，正文是测试对象 |
| 大写前缀 `S3FS-PRIVATE` | 尚未执行。对象已创建，请求在 200 之后被停掉 |
| 混合大小写 `s3fs-Private` | 尚未执行。对象已创建，请求被停掉 |
| 多余斜杠 | 尚未执行。对象已创建，请求被停掉 |
| `/./` | 尚未执行。创建这个键时签名失败，没有发 CDN 请求 |
| `../` 归一化 | 尚未执行 |
| 查询参数 | 尚未执行 |
| 带编码字符的查询参数 | 尚未执行 |

历史复测里，大小写变体在对象只存在于小写键时返回 404。那不能证明 WAF 拦住了大写键。本轮本来准备对真实存在的大写键发请求，但在 `%252F` 返回 200 后按规则停止。

## 请求结果

每个目标键都先用 S3 HeadObject 确认存在。响应正文只记录是否包含测试标记，不记录标记本身。403 响应长度为 4542 字节，正文含 Cloudflare 拦截页特征，不含测试标记。

| 请求 | HTTP | Content-Type | 标记 | Cloudflare |
| --- | --- | --- | --- | --- |
| `/s3fs-private/_xedc_storage_test/.../marker.txt` | 403 | `text/html` | 无 | 拦截页，`server: cloudflare` |
| `/s3fs-private%2F_xedc_storage_test/.../marker.txt` | 403 | `text/html` | 无 | 拦截页 |
| `/s3fs-private%2f_xedc_storage_test/.../marker.txt` | 403 | `text/html` | 无 | 拦截页 |
| `/s3fs-private%252F_xedc_storage_test/.../marker.txt` | 200 | `text/plain` | 有 | `CF-Cache-Status: DYNAMIC`，不是拦截页 |

200 的正文长度是 19 字节，匹配键名中带有字面量 `%2F` 的那个对象，不匹配 `s3fs-private/` 目录下的规范对象，也不匹配键名中带有字面量 `%252F` 的对象。CDN 返回的 `Cache-Control` 是该对象上传时的 `private, max-age=0, no-store`。

因此：

- 规范前缀 `s3fs-private/` 的正常路径、单次 `%2F` 和 `%2f` 仍被 WAF 拒绝。
- `%252F` 被解码一次后，请求落到键 `s3fs-private%2F...`。这条键不在 `s3fs-private/` 目录下，现有规则没有拦住，自定义域名把对象公开了出来。
- 这次没有证明规范私有文件可以被双重编码直接读出。
- 桶一旦出现这种字面量键，匿名用户可以读到它。私有隔离不能标成已通过。

`/./` 键没有测到 CDN。S3 客户端返回 `SignatureDoesNotMatch`，多半是签名前把点段归一化了。这不是拦截证据。

## 人工修改建议

在 Cloudflare 控制台为 `cdn.gitxx.xyz` 收紧已有 Block 规则，不要只匹配后面紧跟 `/` 或单次 `%2F` 的路径。原始 URI 里的 `s3fs-private%252F` 仍然包含字符串 `s3fs-private`。

建议表达式：

```txt
(http.host eq "cdn.gitxx.xyz" and (
  lower(http.request.uri.path) contains "s3fs-private" or
  lower(http.request.uri) contains "s3fs-private" or
  lower(raw.http.request.uri) contains "s3fs-private"
))
```

动作使用 Block。不要改 R2 Bucket Policy，不要取消自定义域名。大小写变体也会被不区分大小写的这段表达式覆盖。

回滚：停用或删除这一条新规则。停用后，私有前缀会重新依赖原来的规则；原来的规则挡得住规范路径，挡不住这次的 `%252F`。

改完之后需要重新创建专用测试对象再测，至少包括规范路径、`%2F`、`%252F`、大写前缀、多余斜杠和查询参数。预期全部是 403，且响应不含测试标记。本轮没有部署这条规则。
