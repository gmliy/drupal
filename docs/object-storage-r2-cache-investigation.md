# MP4 与图片 CDN 缓存头排查

## 复制前复测：2026-11-02 19:06 UTC

新的 PNG 和可播放 MP4 在 R2 上仍是 `public, max-age=60`，ETag 与 CDN 一致，正文一致。两次 CDN GET 都变成 `public, max-age=3600`。PNG 与 MP4 都是第一次 `MISS`、第二次 `HIT`，`Age: 2`。MP4 Range 为 206，`bytes 0-15/9829`，字节一致，但缓存头同样是 3600，所以缓存验收失败。没有改上传代码，没有复制历史文件。

18:18 的历史值是 14400。本轮秒数变成 3600，仍然不是 60。通过标准不变：Browser Cache TTL 设为 Respect Existing Headers 之后，两次 GET 必须读回 `public, max-age=60`。

## 历史：2026-11-02 18:18 UTC

新的测试 PNG 和 MP4 上传头都是 `Cache-Control: public, max-age=60`。对象在 `s3fs-public/_xedc_storage_test/`，测完已删除。没有改生产对象，没有改代码。环境里没有 Cloudflare API Token，读不到控制台配置。

| 项目 | PNG | MP4 |
| --- | --- | --- |
| HeadObject Cache-Control | `public, max-age=60` | `public, max-age=60` |
| HeadObject Content-Type | `image/png` | `video/mp4` |
| HeadObject Content-Length | 70 | 9829 |
| HeadObject ETag | `2cd8bde463f5d82aae0f0cec061d6b8f` | `dd976ae06c414411baab99a65dc72490` |
| 自定义 Metadata | 无 | 无 |
| 第一次 CDN GET | 200，正文一致，`public, max-age=14400`，`CF-Cache-Status: MISS`，无 Age | 200，正文一致，`public, max-age=14400`，`MISS`，无 Age |
| 第二次 CDN GET，间隔 2 秒 | 200，`public, max-age=14400`，`HIT`，`Age: 2` | 200，`public, max-age=14400`，`HIT`，`Age: 2` |
| CDN ETag | 与 R2 相同 | 与 R2 相同 |
| CDN Content-Type / Length | `image/png` / 70 | `video/mp4` / 9829 |

响应里没有 `CDN-Cache-Control`、`Cloudflare-CDN-Cache-Control`、`Expires`，也没有带出某条 Cache Rule 的名称。ETag 与 R2 相同，说明 CDN 返回的是这个对象，不是另一个文件。`Age: 2` 说明第二次 GET 来自边缘缓存，并且缓存时间在增加。

`xedc_core` 仍只把调用方传入的 `Cache-Control` 写入 PutObject。代码和本机 nginx 都没有 `14400`。上传后没有第二次修改 Metadata。因此不修改上传代码。改代码不能在 Cloudflare 固定覆盖浏览器缓存头时，让 GET 变回 60 秒。

需要管理员在 Cloudflare 控制台操作，程序没有执行：

1. 打开区域 `gitxx.xyz` 或实际托管 `cdn.gitxx.xyz` 的区域。
2. 进入 Caching、Configuration。
3. 把 Browser Cache TTL 设为 Respect Existing Headers。不要新建 Cache Rule，不要把 Edge Cache TTL 或 Browser Cache TTL 设成 4 小时，不要设一年 immutable。
4. 保存后不要清生产缓存。用新的测试 PNG 和 MP4 再测。通过标准是两次 CDN GET 的 `Cache-Control` 仍是 `public, max-age=60`，并与 HeadObject 一致。

14400 正好是 4 小时。本轮没有读取到控制台当前值，所以不能把“已经确认是 Browser Cache TTL 的 4 小时选项”写成程序验证。上面第 3 步是要管理员打开该设置并核对；如果那里已经是 Respect Existing Headers，再检查 Cache Rules 和 Edge Cache TTL，把实际看到的项记下来。

## 历史：2026-11-02 18:07 UTC

测试对象只写在 `s3fs-public/_xedc_storage_test/`，测完已删除。没有修改生产视频或图片的 Metadata。

## 结论

异常来自 Cloudflare 对可缓存 GET 响应的边缘改写，不是 R2 对象元数据，也不是 `xedc_core` 上传代码。

本轮同时看到图片和视频被改写。文本没有被改写。具体是哪一个 Cloudflare 控制台开关，程序读不到，记为待人工确认。

| 层 | 判断 |
| --- | --- |
| R2 Metadata | 排除。HeadObject 读回的就是上传时写入的 `public, max-age=60` |
| xedc_core 上传 | 排除。代码里没有 `14400`，也没有按视频单独改缓存头 |
| DeliveryUrl / CdnUrlPolicy | 排除。这次请求的是对象 URL，不经过 Drupal 改写 |
| Cloudflare GET 边缘响应 | 成立。PNG 和 MP4 的 GET 都变成 `public, max-age=14400` |
| 控制台里的具体规则名称 | 待人工确认。本轮没有打开 Cloudflare 控制台 |

## 代码

`CachePolicy` 只按对象是否私有、键里是否已有内容哈希来选择缓存头：

- 私有：`private, max-age=0, no-store`
- 已带内容哈希：`public, max-age=31536000, immutable`
- 其他公开对象：`public, max-age=3600`

没有针对 `video/mp4` 的分支。`RemoteFileWriter` 只在调用方传入 `Cache-Control` 时把它放进 `PutObject` 或分片上传的 `before_initiate`。上传成功后不会再改 Metadata。`DeliveryUrl` 只决定 URL，不设置缓存头。

全库搜索 `14400`，业务代码和 `/etc/nginx` 都没有这个值。它只出现在以往的 CDN 测试记录里。供应商表单上的默认一年 `immutable` 是配置草稿，这次 PutObject 没有使用它，也没有写到生产对象。

线上视频和图片仍由本地 `public://` 保存。生产上传不经过这次测试使用的 PutObject。

## 本轮对象对比

同一条上传头：`public, max-age=60`。请求没有带 `Cache-Control: no-cache`。

| 对象 | HeadObject | CDN 第一次 GET | CDN 第二次 GET | CDN HEAD |
| --- | --- | --- | --- | --- |
| 文本 | `text/plain`，`public, max-age=60`，39 字节，ETag `9986917c6acf3354c9867e15ff4c372f`，无自定义 Metadata | 200，头保持 60，`CF-Cache-Status: DYNAMIC`，无 Age，正文一致 | 200，头保持 60，`DYNAMIC` | 200，头保持 60，`DYNAMIC` |
| PNG | `image/png`，`public, max-age=60`，70 字节，ETag `2cd8bde463f5d82aae0f0cec061d6b8f` | 200，`public, max-age=14400`，`MISS`，无 Age，正文一致 | 200，`public, max-age=14400`，`HIT`，`Age: 0` | 200，`public, max-age=60`，`DYNAMIC` |
| MP4 | `video/mp4`，`public, max-age=60`，9829 字节，ETag `dd976ae06c414411baab99a65dc72490` | 200，`public, max-age=14400`，`MISS`，`Accept-Ranges: bytes`，正文 SHA-256 一致 | 200，`public, max-age=14400`，`HIT`，`Age: 0` | 200，`public, max-age=60`，`DYNAMIC`，`Accept-Ranges: bytes` |

MP4 的 `Range: bytes=0-15` 返回 206，`Content-Range: bytes 0-15/9829`，缓存头也是 `public, max-age=14400`，`CF-Cache-Status: HIT`。这只说明缓存测试时 Range 可用。浏览器播放没有记在这次缓存请求里；同轮安全脚本另行观察到播放和拖动，见总报告。

HEAD 仍返回源站的 60 秒，GET 命中边缘缓存后变成 14400。所以改写发生在 Cloudflare 缓存的 GET 响应上。文本保持 `DYNAMIC`，源站头被保留。

服务器是 `cloudflare`，响应带 `cf-ray`。

## 和历史测试的差别

2026-11-02 早先的复测只记录了文本保持原头、MP4 变成 14400。本轮补了 PNG，PNG 和 MP4 一样被改写。因此不能再说这是视频专用逻辑。

## 建议的缓存策略

| 对象 | Cache-Control |
| --- | --- |
| 未版本化公开图片 | `public, max-age=3600` |
| 未版本化公开视频 | `public, max-age=3600` |
| 内容哈希版本化对象 | `public, max-age=31536000, immutable` |
| 私有对象 | `private, max-age=0, no-store` |

现有生产文件名没有内容哈希。在 Cloudflare 仍把图片和视频 GET 改成 14400 时，即使对象上写成 3600，浏览器仍可能按 4 小时缓存。覆盖同一个键之后，旧边缘缓存还会保留。不要把现有生产对象批量改成一年 `immutable`，也不要批量改 Metadata。

代码不需要为了去掉 `14400` 而修改。`xedc_core` 没有写出这个值。等 Cloudflare 改为尊重源站头之后，再用新的测试 PNG 和 MP4 确认 GET 与 HeadObject 一致。新上传若要使用一年缓存，应使用 `CachePolicy::versionedFilename()` 生成带哈希的新键，不改已有文件名。
