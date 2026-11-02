# CDN

日期：2026-11-02。

公开域名：`https://cdn.gitxx.xyz`。

S3 API Endpoint 主机：`409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`。代码不会把 Endpoint 当成 CDN。

生产开关 `XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED` 仍是关闭。CDN 不能标成生产就绪。

## 当前结论

最终复测时间：2026-11-02 17:49 UTC。测试对象是这次新写入的，测完已删除。生产开关没有打开。

| 项 | 结论 |
| --- | --- |
| 私有匿名访问 | 已阻止。正常路径返回 HTTP 403，响应不是对象正文 |
| 编码路径绕过 | 没有发现能读回正文的绕过。`%2F`、`%2f`、百分号编码的前缀、多余斜杠、点段和查询参数都是 403 |
| 双重编码和大小写 | 返回普通 404，正文不是测试对象。这不能当成隔离成功，也没有读到对象 |
| 公开测试对象 | 可用。HTTP 200，正文与上传内容一致 |
| r2.dev | 未能验证。配置里没有这个主机名，不能猜测它是否关闭 |
| 文本缓存头 | 符合上传值。`max-age=60`、`3600` 和一年 `immutable` 都被 CDN 原样返回，`CF-Cache-Status` 为 `DYNAMIC`，没有 `Age` |
| MP4 缓存头 | 不符合。上传是 `max-age=60`，CDN 两次都是 `public, max-age=14400`。第一次 `MISS`，第二次 `HIT`，`Age: 0` |
| MP4 Range 和播放 | 通过。主机是 `cdn.gitxx.xyz`。200、206、`Content-Range`、`Accept-Ranges: bytes`，浏览器 `duration=2 time=1 seek=yes` |
| Drupal URL 策略 | 通过。PHPUnit 与手动地址测试都通过 |
| 生产就绪 | 否。视频缓存头仍被覆盖，`r2.dev` 未从这里证实关闭，历史文件也还没有迁移 |

自定义域名仍然面向整个 Bucket。现在挡住私有前缀的是边缘拒绝，不是 Drupal 开关。拒绝页大约 4542 字节。对象不存在时的公开 404 页大约 27150 字节。只有 403 且响应里没有测试正文，才记成隔离成功。

## 最终复测的路径

私有对象先经 S3 API 确认存在，再从 CDN 匿名读取。下面的路径都属于同一次创建的对象。

| 变体 | HTTP | 判定 |
| --- | --- | --- |
| 正常路径 `/s3fs-private/_xedc_storage_test/...txt` | 403 | 已阻止 |
| 斜杠编码 `%2F` | 403 | 已阻止 |
| 斜杠编码 `%2f` | 403 | 已阻止 |
| 双重编码 `%252F` | 404 | 未证明。请求落到了另一个不存在的键，正文不是测试对象 |
| 前缀逐字百分号编码 | 403 | 已阻止 |
| `%2e%2e` 点段 | 403 | 已阻止 |
| `S3FS-PRIVATE` 和 `s3fs-Private` | 404 | 未证明。对象键区分大小写，这两个键不存在 |
| 开头或中间多余斜杠，以及 `/./` | 403 | 已阻止 |
| 查询参数 `?retest=1` 和带 `%2F` 的查询 | 403 | 已阻止 |
| 公开测试对象 | 200 | 正文一致 |

没有一种变体返回测试正文，所以没有记录到可复现的绕过。双重编码和大小写不要写成“规则已覆盖”。

## 缓存复测

不带 `Cache-Control: no-cache` 的两次 GET：

| 上传头 | 第一次 CDN | 第二次 CDN | CF-Cache-Status | Age |
| --- | --- | --- | --- | --- |
| `public, max-age=60` | 相同 | 相同 | 两次都是 `DYNAMIC` | 无 |
| `public, max-age=3600` | 相同 | 相同 | 两次都是 `DYNAMIC` | 无 |
| `public, max-age=31536000, immutable` | 相同 | 相同 | 两次都是 `DYNAMIC` | 无 |
| MP4 `public, max-age=60` | `public, max-age=14400` | `public, max-age=14400` | `MISS`，然后 `HIT` | 第二次为 0 |

文本头没有被覆盖，也没有进入边缘缓存。视频头被改成 4 小时，并且第二次命中缓存。这个差异没有写到现有生产文件上。

## r2.dev

供应商配置、代码和已保存的状态里都没有 `r2.dev` 主机名。现有权限不能读取 R2 控制台里的 Public Development URL 开关。这次没有猜测 `pub-*.r2.dev`。该项保持人工确认。

## 这次怎么测的

测试对象只写在 `s3fs-public/_xedc_storage_test/` 和 `s3fs-private/_xedc_storage_test/`。测完删除。两个前缀的对象数在测试前后都是 0。没有改 Bucket 策略，没有删除来源不明的对象，没有把私有对象改成公开。

视频是本地用 FFmpeg 生成的 64×64、2 秒、H.264 + AAC 的 MP4，共 9829 字节。Range 使用 `bytes=0-15` 和文件中段。`Content-Range` 为 `bytes 0-15/9829`。预签名 S3 地址没有拿来当 CDN 验收。

文本对象上传头 `public, max-age=60`，CDN 返回同一条。带内容哈希的测试文件名上传头是 `public, max-age=31536000, immutable`，CDN 也原样返回。视频上传头是 `public, max-age=60`，CDN 第一次是 `cf-cache-status: MISS`，第二次是 `HIT`，`Cache-Control` 变成 `public, max-age=14400`。所以 Cloudflare 对视频另有缓存覆盖。这个覆盖没有写到现有生产文件上。

## 地址规则

`DeliveryUrl` 和 `CdnUrlPolicy` 只在同时满足这些条件时返回 CDN URL：

- `STORAGE_CDN_ENABLED` 使运行时 rewrite 打开。
- URI 已经是远程对象。本地 `public://` 即使开关打开也不改写。
- 对象键位于公开前缀下。
- 不是私有协议，对象键也不以私有前缀开头。
- CDN 主机是 HTTPS，并且不是 API Endpoint，也不是 `*.r2.cloudflarestorage.com`。

当前配置的公开域名是 `https://cdn.gitxx.xyz`。未配置 CDN 时返回空，交给原来的 Drupal 文件 URL，不抛异常。

## 缓存和更新

现有生产文件名没有内容哈希。兼容做法是保持原文件名，不把 `public, max-age=31536000, immutable` 写到这些对象上。

- 未版本化对象：`public, max-age=3600`。
- 文件名含 12 位以上内容哈希，或路径含 `/v/{hash}/`、`/h/{hash}/`：才使用一年 `immutable`。
- 私有对象：`private, max-age=0, no-store`。
- 以后的迁移复制使用上面的规则。保存后台草稿不会改远程对象，也不会改文件名。
- 同一对象键被覆盖后，一年缓存会继续提供旧内容。刷新方式仍是手动：在 Cloudflare 按完整 URL 清除。自动清除没有接上，`purge_mode` 仍是 `manual`。
- 视频要先让 CDN 尊重对象自己的 `Cache-Control`，再考虑长缓存。

## 仍要人工处理

WAF Block 规则已经生效，这次没有改 Cloudflare，也没有改 Bucket 策略。回滚仍然是停用或删除那一条私有前缀规则。停用后，私有对象会重新能从 `cdn.gitxx.xyz` 匿名读取。

还没有完成的是：

1. 在 R2 控制台确认 Bucket `dclili` 的 Public Development URL 已关闭。这里无法替你验证。
2. 找出把 MP4 的 `Cache-Control` 改成 `public, max-age=14400` 的 Cache Rule 或 Browser Cache TTL。文本对象没有被改写，视频会被边缘缓存 4 小时。
3. 历史文件仍未迁移。128 个公开文件在本地，9 个私有记录没有本地副本。复制和接管都还不能开始。

这些事项完成之前，CDN 保持未就绪。五个生产开关继续关闭。
