# CDN 安全隔离与生产接入说明

日期：2026-11-02。

项目：Drupal 11 / XEDC。Bucket：`dclili`。公开域名：`https://cdn.gitxx.xyz`。公开前缀：`s3fs-public/`。私有前缀：`s3fs-private/`。

2026-11-02 17:49 UTC 的最终复测见 `docs/object-storage-r2-cdn.md`。正常私有路径和已测试的编码变体不再返回对象正文，公开对象仍可读取。视频缓存头仍被改成 4 小时，`r2.dev` 未能从服务器上验证，所以 CDN 仍未就绪。

这份说明记录这次安全整改和验收的结果。CDN 当前不能标成生产就绪。五个生产开关仍然关闭：

- `XEDC_STORAGE_RUNTIME`
- `STORAGE_I_UNDERSTAND_TAKEOVER`
- `STORAGE_PUBLIC_TAKEOVER`
- `STORAGE_PRIVATE_TAKEOVER`
- `STORAGE_CDN_ENABLED`

没有改 Bucket 策略，没有删除来源不明的对象，没有把私有对象改成公开对象，没有复制历史文件，也没有修改 File Entity 的 URI。

## 结论

`cdn.gitxx.xyz` 绑定的是整个 Bucket。最终复测时，私有测试对象的正常路径返回 403，响应里没有对象正文。Drupal 的 rewrite 开关仍然只决定页面地址，隔离靠的是已经生效的边缘规则。

公开前缀可以正常访问。本地文件和私有文件不会被 Drupal 改写成公共 CDN 地址。可播放的测试 MP4 已经通过 CDN 完成 Range、播放和进度拖动。视频的 CDN 缓存头与上传时设置的不一致。历史文件只做了预检查，还没有迁移。

因此生产接入保持未就绪。后台页面 `/admin/config/media/object-storage/cdn` 把私有匿名访问和生产接入标成「存在安全风险」，不根据配置开关判断 CDN 已经安全。

## 私有前缀

测试对象只写在 `_xedc_storage_test/`，测完删除。测试前后，公开和私有测试前缀里的对象数都是 0。

| 请求 | 结果 |
| --- | --- |
| 公开测试对象 | HTTP 200，正文与上传内容一致 |
| 私有测试对象 | HTTP 200，匿名请求读到了正文 |
| 私有路径把斜杠编码成 `%2F` | HTTP 200，同样读到了正文 |
| 未签名访问 S3 API 主机上的私有键 | HTTP 400，`Authorization` |

缺失对象返回的是 Cloudflare R2 的公开 404 页面。这个 404 只说明域名指向 Bucket，不能说明私有前缀已被拒绝。

这台服务器没有 Cloudflare API 令牌，不能从这里列出 Worker、WAF 或 Transform Rules。匿名读取结果说明，当前没有一条生效的规则挡住 `s3fs-private/`。泄露面是自定义域名，不是 S3 API Endpoint `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`。

隔离可以只针对私有路径做，不必关掉公开前缀，也不必改整个 Bucket 的访问策略。规则还没有添加，所以隔离还没有完成。

## 地址规则

只有下面几项同时成立时，Drupal 才返回 `https://cdn.gitxx.xyz` 上的地址：

- 运行时 CDN rewrite 已打开。
- 文件已经是远程对象。本地 `public://` 即使开关打开也不改写。
- 对象键位于 `s3fs-public/` 下。
- 不是私有协议，对象键也不以 `s3fs-private/` 开头。
- 主机是 HTTPS，并且不是 R2 的 S3 API 主机。

私有远程文件只进入 `/xedc/storage/protected/{fid}`。未配置 CDN 时保持原来的 Drupal 文件 URL，不会报错。

当前开关是关闭的。首页、`/cosplay/cos/315`、原图和带令牌的 thumbnail 都从 `gitxx.xyz` 返回，页面里没有 `cdn.gitxx.xyz`。

## 视频 Range

样本是 FFmpeg 生成的 64×64、2 秒、H.264 + AAC MP4，9829 字节，上传到公开测试前缀。验收使用的是 CDN 域名，不是预签名 S3 地址。

- 普通 GET：HTTP 200，`Content-Type: video/mp4`，正文与文件一致。
- `Range: bytes=0-15`：HTTP 206，`Content-Range: bytes 0-15/9829`，返回字节与文件一致。中段 Range 也一致。
- 响应包含 `Accept-Ranges: bytes`。
- Headless Chromium 播放该 CDN 地址，结果是 `duration=2 time=1 seek=yes`。播放和拖动是在删除测试对象之前做的。
- 第一次缓存状态是 `MISS`，第二次是 `HIT`。

## 缓存

现有生产文件名没有内容哈希，这次没有改名，也没有把一年缓存写到这些文件上。

| 对象 | 上传时的 Cache-Control | CDN 实际返回 |
| --- | --- | --- |
| 普通测试文本 | `public, max-age=60` | 相同 |
| 文件名含内容哈希的测试文本 | `public, max-age=31536000, immutable` | 相同 |
| 测试 MP4 | `public, max-age=60` | `public, max-age=14400` |

以后的复制规则是：

- 未版本化的公开对象使用 `public, max-age=3600`。
- 文件名里已有至少 12 位内容哈希，或路径含 `/v/{hash}/`、`/h/{hash}/`，才使用 `public, max-age=31536000, immutable`。
- 私有对象使用 `private, max-age=0, no-store`。

同一对象键被新内容覆盖后，长缓存会继续提供旧文件。清除方式仍是手动，在 Cloudflare 按完整 URL 清除。自动清除没有接上。视频要先让 CDN 尊重对象自己的缓存头，再考虑长缓存。

## 历史文件预检查

137 条 File Entity 只做了扫描。URI 在扫描前后一致，复制数量是 0。

| 项 | 结果 |
| --- | --- |
| 可复制的公开文件 | 128，共 75,177,632 字节 |
| 本地文件缺失 | 9，全部是私有记录，数据库大小合计 45,393 字节 |
| 已在 R2 且 SHA-256 一致 | 0 |
| 对象校验失败 | 0 |
| `s3fs-public/` 和 `s3fs-private/` 里的现存对象 | 都是 0 |
| 本地图片样式 | 15 个目录，766 个衍生文件，不在 R2 |

9 个私有文件没有本地副本，不能迁移。公开文件复制完成之前，不能打开公开接管或 CDN rewrite，否则现有图片地址会指向还不存在的对象。私有前缀被拒绝之前，不能把私有文件复制到这个 Bucket。

## 回滚

这次没有需要回滚的数据。本地公开文件还在。测试对象已经删除。Cloudflare 上没有新增规则。

如果以后加上面的 WAF 规则，回滚方法是停用或删除那一条规则。公开前缀不受影响。停用之后，私有前缀会重新能从 `cdn.gitxx.xyz` 匿名读取。

如果以后已经开始复制但还没有改 URI：停止复制，保留本地文件，只按该任务里校验通过的对象键处理远程对象。接管如果已经打开，先清掉运行时变量并重载 PHP-FPM，让文件流回到本地。

## 需要在 Cloudflare 控制台人工执行

这些步骤还没有执行。

1. 区域 `gitxx.xyz`，进入 Security → WAF → Custom rules，新增一条规则，动作是 Block：

```txt
(http.host eq "cdn.gitxx.xyz" and (http.request.uri.path eq "/s3fs-private" or starts_with(http.request.uri.path, "/s3fs-private/") or http.request.uri.path contains "s3fs-private%2f" or http.request.uri.path contains "s3fs-private%2F"))
```

这条规则只作用于 `cdn.gitxx.xyz` 的私有前缀。`s3fs-public/` 继续公开。S3 API 上的预签名下载不受影响。不要修改 Bucket 策略，不要删除对象，不要解除自定义域名。

2. 确认 Bucket `dclili` 的 Public Development URL（`r2.dev`）是关闭的。那条主机不经过上面的规则。

3. Caching → Configuration → Browser Cache TTL 设为 Respect Existing Headers。检查有没有把 MP4 改成 4 小时的 Cache Rule。不要对现有生产文件启用一年 immutable。

规则保存后，需要再做一次对照：私有测试对象应不再返回正文，公开测试对象仍应返回原文。

## 相关代码和测试

地址判断在 `CdnUrlPolicy` 和 `DeliveryUrl`。缓存判断在 `CachePolicy`。CDN 探测在 `CdnInspector`。后台状态在 `CdnStatus`，页面模板是 `xedc-core-storage.html.twig`。

PHPUnit：`web/modules/custom/xedc_core/tests/src/Unit/CdnDeliveryPolicyTest.php`，9 项通过。已启动站点上的地址检查、真实 HTTP 检查和迁移预检查分别是：

- `web/modules/custom/xedc_core/tests/manual/cdn-delivery-self-test.php`
- `web/modules/custom/xedc_core/tests/manual/cdn-security-self-test.php`
- `web/modules/custom/xedc_core/tests/manual/cdn-migration-precheck.php`

更细的记录见 `docs/object-storage-r2-cdn.md`、`docs/object-storage-r2-production-guide.md` 和 `docs/object-storage-r2-rollback.md`。
