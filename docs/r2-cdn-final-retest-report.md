# R2 CDN 缓存最终复测

测试时间：2026-11-02 19:33:24–19:33:35 UTC。

结论：**PASS**。

PNG 和 MP4 的 CDN GET 都保留了 `Cache-Control: public, max-age=60`。正文 SHA-256 与源文件一致，ETag 与 R2 一致。MP4 Range 返回 206，缓存头仍是 60 秒。本轮要求的私有前缀变体都是 403 拦截页，正文没有测试标记。

本轮没有复制 128 个历史文件，没有打开生产接管。PASS 只说明这项复测通过。历史文件复制要等管理员另行授权。

## 运行状态

| 项 | 结果 |
| --- | --- |
| 活动供应商 | `silly` |
| Bucket | `dclili` |
| Region | `auto` |
| Endpoint 主机 | `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com` |
| CDN | `https://cdn.gitxx.xyz` |
| `public://` | `Drupal\Core\StreamWrapper\PublicStream` |
| `XEDC_STORAGE_RUNTIME` | 未设置 |
| `STORAGE_I_UNDERSTAND_TAKEOVER` | 未设置 |
| `STORAGE_PUBLIC_TAKEOVER` | 未设置 |
| `STORAGE_PRIVATE_TAKEOVER` | 未设置 |
| `STORAGE_CDN_ENABLED` | 未设置 |
| S3FS public/private takeover | 关闭 |

测试结束后这五项仍是关闭，`public://` 仍是本地 `PublicStream`。

测试目录标识：`final-20261102T193324Z-089e0bc9`。对象都在 `_xedc_storage_test/` 下。

## R2 源站

上传时指定 `Cache-Control: public, max-age=60`。HeadObject 读回的就是这个值，因此继续做了 CDN 验收。

| 对象 | Content-Type | 大小 | Cache-Control | ETag | 文件 SHA-256 |
| --- | --- | --- | --- | --- | --- |
| PNG | `image/png` | 70 | `public, max-age=60` | `2cd8bde463f5d82aae0f0cec061d6b8f` | `c414cd0e204de974f73753c7e28d7638e7b3691bb8b1a2bab6b25bb7fed7ce77` |
| MP4 | `video/mp4` | 9829 | `public, max-age=60` | `dd976ae06c414411baab99a65dc72490` | `d15a7a315293a65d1b59f150077867d408cd5f3ad2d994124d0c8eb1af15cfc7` |

MP4 由 FFmpeg 生成，64×64、2 秒、H.264 + AAC，不是随机字节。

## CDN GET

两次 GET 间隔 2 秒。没有使用 HEAD 代替 GET。

| 对象 | 次序 | HTTP | Cache-Control | CF-Cache-Status | Age | ETag | CF-Ray | 正文 SHA-256 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| PNG | 1 | 200 | `public, max-age=60` | MISS | 无 | 与 R2 相同 | `a5459222bbd1fe09-SIN` | 与源文件相同 |
| PNG | 2 | 200 | `public, max-age=60` | HIT | 2 | 与 R2 相同 | `a5459230c801bcf3-SIN` | 与源文件相同 |
| MP4 | 1 | 200 | `public, max-age=60` | MISS | 无 | 与 R2 相同 | `a5459231f90589a1-SIN` | 与源文件相同 |
| MP4 | 2 | 200 | `public, max-age=60` | HIT | 2 | 与 R2 相同 | `a54592406e13fd73-SIN` | 与源文件相同 |

没有出现 `max-age=3600` 或 `max-age=14400`。19:06 UTC 的 3600 秒是上一轮结果，本轮新对象没有复现。响应里没有 Cache Rule 名称，也没有 `CDN-Cache-Control`。程序没有读取 Cloudflare 控制台，不能代替管理员确认那一项设置的界面值；本轮证据是实际 GET 头与 R2 一致。

## 视频 Range

请求 `Range: bytes=0-15`。

| 项 | 结果 |
| --- | --- |
| HTTP | 206 |
| Content-Range | `bytes 0-15/9829` |
| Content-Type | `video/mp4` |
| 长度 | 16 字节，与文件开头一致 |
| Cache-Control | `public, max-age=60` |
| CF-Cache-Status | HIT |
| Age | 2 |
| CF-Ray | `a5459240afd0f884-SIN` |

## WAF 私有前缀

每个目标键都先用 HeadObject 确认存在。匿名响应都是 403，长度 4542 字节，含 Cloudflare 拦截页文字，不含测试标记。

| 变体 | HTTP | 对象存在 | CF-Ray |
| --- | --- | --- | --- |
| 正常 `s3fs-private/` | 403 | 是 | `a54592508f303f5e-SIN` |
| `%2F` | 403 | 规范键和字面量 `%2F` 键都在 | `a5459250dca7ff91-SIN` |
| `%252F` | 403 | 规范键、`%2F` 键、`%252F` 键都在 | `a54592511d0dfe01-SIN` |
| 大写 `S3FS-PRIVATE` | 403 | 是 | `a54592515cd7fd94-SIN` |
| 多余斜杠 | 403 | 规范键和 `//` 键都在 | `a5459251981e91c0-SIN` |
| 查询参数 `?x=1` | 403 | 是 | `a5459251d947ce01-SIN` |

没有无法创建的键，因此本轮这些变体没有记成未验证。没有用 404 充当通过。

## 清理

本轮 7 个对象已删除。删除后 HeadObject 都确认不存在：1 个 PNG、1 个 MP4、5 个私有测试文本。没有删除其他对象。

## 失败项

没有失败项。

## 下一阶段

缓存头、数据一致性、Range 和本轮 WAF 变体都通过，可以进入历史文件复制的准备。复制本身没有执行，生产接管也没有打开。开始复制前仍需要管理员明确授权。
