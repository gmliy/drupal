# CDN 私有隔离最终复测

日期：2026-11-02，17:49 UTC。

项目：Drupal 11 / XEDC。Bucket：`dclili`。公开域名：`https://cdn.gitxx.xyz`。公开前缀：`s3fs-public/`。私有前缀：`s3fs-private/`。

这次复测发生在 Cloudflare 上已经人工加入私有前缀 WAF Block 规则之后。复测没有修改 Cloudflare，没有修改 Bucket 策略，没有删除来源不明的对象，没有迁移历史文件，也没有修改 File Entity URI。

五个生产开关仍然关闭：

- `XEDC_STORAGE_RUNTIME`
- `STORAGE_I_UNDERSTAND_TAKEOVER`
- `STORAGE_PUBLIC_TAKEOVER`
- `STORAGE_PRIVATE_TAKEOVER`
- `STORAGE_CDN_ENABLED`

CDN 不能标成生产就绪。

## 结论

| 问题 | 结果 |
| --- | --- |
| 私有对象能否被匿名读取 | 不能。正常路径返回 HTTP 403，响应不是对象正文 |
| 编码路径有没有绕过 | 没有读回正文的绕过 |
| 公开对象是否还能访问 | 能。HTTP 200，正文与上传内容一致 |
| `r2.dev` 是否关闭 | 未能验证，需要人工确认 |
| 缓存头是否符合上传值 | 文本符合。MP4 被改成 `public, max-age=14400` |
| MP4 Range 是否通过 | 通过，主机是 `cdn.gitxx.xyz` |
| Drupal URL 策略是否通过 | 通过 |
| 生产就绪 | 否 |

私有隔离靠的是已经生效的边缘拒绝，不是 Drupal 的 CDN 开关。拒绝页大约 4542 字节。对象不存在时的公开 404 页大约 27150 字节。只有返回 403 且响应里没有测试正文，才记成隔离成功。普通 404 不算成功。

## 私有路径

测试对象是这次新创建的，键在 `s3fs-private/_xedc_storage_test/` 下。上传后先用 S3 API 确认对象存在，再从 CDN 匿名读取。测完只删除了这次创建的对象。

| 变体 | HTTP | 判定 |
| --- | --- | --- |
| 正常路径 | 403 | 已阻止 |
| 斜杠编码 `%2F` | 403 | 已阻止 |
| 斜杠编码 `%2f` | 403 | 已阻止 |
| 双重编码 `%252F` | 404 | 未证明。请求落到了另一个不存在的键，正文不是测试对象 |
| 前缀逐字百分号编码 | 403 | 已阻止 |
| `%2e%2e` 点段 | 403 | 已阻止 |
| `S3FS-PRIVATE` | 404 | 未证明。对象键区分大小写，这个键不存在 |
| `s3fs-Private` | 404 | 未证明。同上 |
| 开头多余斜杠 | 403 | 已阻止 |
| 中间多余斜杠 | 403 | 已阻止 |
| `/./` | 403 | 已阻止 |
| 查询参数 | 403 | 已阻止 |
| 查询参数里带 `%2F` | 403 | 已阻止 |

没有任何变体返回测试正文。双重编码和大小写变形没有绕过，也不能写成规则已经覆盖了这两种拼法。

公开测试对象在同一次复测中返回 HTTP 200，正文一致。

## r2.dev

供应商配置、代码和已保存状态里都没有 `r2.dev` 主机名。现有权限读不到 R2 控制台的 Public Development URL 开关。没有猜测 `pub-*.r2.dev`。这一项仍要在 Cloudflare 或 R2 控制台人工确认。

## 缓存

下面的文本请求没有带 `Cache-Control: no-cache`。两次 GET 的响应头相同。

| 上传头 | CDN 实际返回 | CF-Cache-Status | Age |
| --- | --- | --- | --- |
| `public, max-age=60` | 相同 | 两次都是 `DYNAMIC` | 无 |
| `public, max-age=3600` | 相同 | 两次都是 `DYNAMIC` | 无 |
| `public, max-age=31536000, immutable` | 相同 | 两次都是 `DYNAMIC` | 无 |
| MP4 `public, max-age=60` | `public, max-age=14400` | 第一次 `MISS`，第二次 `HIT` | 第二次为 0 |

文本头没有被覆盖，也没有进入边缘缓存。视频头被改成 4 小时，并且第二次命中缓存。这些测试对象已经删除，没有改现有生产文件的缓存策略。

现有生产文件名没有内容哈希。未版本化对象仍应使用 `public, max-age=3600`。只有文件名里已经有内容哈希时，才适合一年 `immutable`。同一对象键被覆盖后，要按完整 URL 手动清除 CDN。

## 视频

样本是 64×64、2 秒、H.264 + AAC 的 MP4，9829 字节。请求地址的主机是 `cdn.gitxx.xyz`，不是预签名 S3 API 地址。

- 普通 GET：HTTP 200，`Content-Type: video/mp4`，正文与文件一致。
- 起始 Range：HTTP 206，`Content-Range: bytes 0-15/9829`，字节与文件一致。
- 中间 Range：HTTP 206，`Content-Range: bytes 40-71/9829`，字节与文件一致。
- `Accept-Ranges: bytes`。
- Headless Chromium：`duration=2 time=1 seek=yes`。播放和拖动是在删除对象之前做的。

## Drupal 地址规则

`CdnDeliveryPolicyTest` 所在的 PHPUnit 套件通过。已启动站点上的 `cdn-delivery-self-test.php` 也通过：

- 本地 `public://` 不会改写成 CDN。
- 私有文件不会得到公共 CDN 地址，远程私有文件只进入 `/xedc/storage/protected/{fid}`。
- 公开远程对象才会使用 `https://cdn.gitxx.xyz`。
- CDN 开关关闭时保持原来的 Drupal 文件 URL。
- S3 API Endpoint 不会被当成 CDN。

`cdn-security-self-test.php` 的结果与复测一致：公开对象 200，私有正常路径和整段编码路径都是 403。

文件实体在复测前后都是 137 条。

## 还要人工处理

1. 在 R2 控制台确认 Bucket `dclili` 的 Public Development URL 已关闭。
2. 找出把 MP4 的 `Cache-Control` 改成 `public, max-age=14400` 的缓存规则。文本对象没有被这样改写。
3. 历史文件还没有迁移。128 个公开文件在本地，9 个私有记录没有本地副本。

WAF 规则的回滚方法是停用或删除那一条私有前缀规则。公开前缀不受这条回滚影响。停用之后，私有对象会重新能从 `cdn.gitxx.xyz` 匿名读取。

相关记录见 `docs/object-storage-r2-cdn.md` 和 `docs/object-storage-r2-cdn-security.md`。
