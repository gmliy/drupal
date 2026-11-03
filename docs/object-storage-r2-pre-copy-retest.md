# 复制前最终复测

时间：2026-11-02 19:06 UTC。供应商 `silly`，桶 `dclili`，region `auto`，endpoint 主机 `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`，CDN `https://cdn.gitxx.xyz`。凭证状态为已配置，来源是后台凭证，密钥没有输出。

本轮没有修改 Cloudflare，没有改上传代码，没有复制历史文件，没有改 File Entity URI。五个生产开关保持关闭。`public://` 仍是 `Drupal\Core\StreamWrapper\PublicStream`。测试对象测完已删除，9 个删除确认都成功。

`r2.dev`：Disabled（管理员人工确认，未通过程序独立验证）。

## 门禁

| 项 | 结果 |
| --- | --- |
| WAF | 通过。已确认存在的测试对象，CDN 都是 403 拦截页，正文没有测试标记 |
| PNG 缓存 | 失败。R2 是 `max-age=60`，两次 GET 都是 `public, max-age=3600` |
| MP4 缓存 | 失败。同样是 R2 60 秒、GET 3600 秒。Range 206 正常，但不能抵消缓存头失败 |
| R2 连接 | 通过 |
| 128 个公开文件可读且有 SHA-256 | 通过。远程公开对象仍是 0，没有目标键冲突 |
| 历史文件复制 | 未执行。缓存门禁失败后停止 |

## WAF

每个能创建的键都先通过 HeadObject。字面量 `./` 键签名失败，没有创建，记为未验证。该 URL 仍然返回了 403 拦截页，不是 404，响应里没有测试标记。

| 请求 | HTTP | 正文长度 | 拦截页 | 测试标记 | 目标对象存在 |
| --- | --- | --- | --- | --- | --- |
| `/s3fs-private/.../marker.txt` | 403 | 4542 | 是 | 无 | 是 |
| `/S3FS-PRIVATE/.../marker.txt` | 403 | 4542 | 是 | 无 | 是 |
| `/s3fs-Private/.../marker.txt` | 403 | 4542 | 是 | 无 | 是 |
| `%2F` | 403 | 4542 | 是 | 无 | 规范键和字面量 `%2F` 键都在 |
| `%2f` | 403 | 4542 | 是 | 无 | 同上 |
| `%252F` | 403 | 4542 | 是 | 无 | 规范键、`%2F` 键、`%252F` 键都在 |
| `%25252F` | 403 | 4542 | 是 | 无 | 上述键和 `%25252F` 字面量键都在 |
| `?x=1` | 403 | 4542 | 是 | 无 | 是 |
| `?x=%2Fs3fs-private` | 403 | 4542 | 是 | 无 | 是 |
| 多余斜杠 | 403 | 4542 | 是 | 无 | 规范键和 `//` 字面量键都在 |
| `../` | 403 | 4542 | 是 | 无 | 是 |
| `/./` | 403 | 4542 | 是 | 无 | 规范键在。字面量 `./` 键未创建 |

12 个实际发出的请求全部是 Cloudflare 拦截响应。没有匿名读到对象正文，所以没有提前停止。18:18 的大写前缀 200 和 18:07 的 `%252F` 200 是历史结果，本轮这两项已复测为 403。

## PNG

上传头 `Cache-Control: public, max-age=60`。

| 检查 | 结果 |
| --- | --- |
| HeadObject | `image/png`，70 字节，`public, max-age=60`，ETag `2cd8bde463f5d82aae0f0cec061d6b8f` |
| 第一次 GET | 200，正文一致，ETag 相同，`public, max-age=3600`，`CF-Cache-Status: MISS` |
| 第二次 GET | 200，正文一致，`public, max-age=3600`，`HIT`，`Age: 2` |

不通过。3600 不是 60。

## MP4

可播放的 64×64 H.264 MP4，上传头同样是 `public, max-age=60`。

| 检查 | 结果 |
| --- | --- |
| HeadObject | `video/mp4`，9829 字节，`public, max-age=60`，ETag `dd976ae06c414411baab99a65dc72490` |
| 第一次 GET | 200，正文 SHA-256 一致，ETag 相同，`public, max-age=3600`，`MISS`，`Accept-Ranges: bytes` |
| 第二次 GET | 200，正文一致，`public, max-age=3600`，`HIT`，`Age: 2` |
| Range `bytes=0-15` | 206，`Content-Range: bytes 0-15/9829`，16 字节与文件一致，缓存头仍是 `public, max-age=3600` |

Range 通过。缓存头不通过。

18:18 的历史结果是 GET `max-age=14400`。本轮变成 3600，说明边缘覆盖的秒数变了，但仍然不是对象上的 60 秒。响应里没有 Cache Rule 名称。没有改 Drupal 上传代码，也没有改生产对象 Metadata。

要让这项通过，Browser Cache TTL 需要设为 Respect Existing Headers，然后用新的测试 PNG 和 MP4 再测。两次 GET 必须仍是 `public, max-age=60`。设成 120 秒、1 小时或 4 小时都不算通过。
