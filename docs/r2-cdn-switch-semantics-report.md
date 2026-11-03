# CDN URL 开关语义

本轮没有打开生产 CDN rewrite。`STORAGE_CDN_ENABLED` 在 PHP-FPM 中仍然不存在，当前进程的 `xedc_storage.cdn_enabled` 为假。

## 结论

| 项目 | 结果 |
| --- | --- |
| 开关关闭时不生成 CDN URL | PASS |
| 开关打开时原图使用 `https://cdn.gitxx.xyz/s3fs-public/...` | PASS（函数测试，不是生产页面） |
| 图片样式在开关打开时仍是本地路径 | PASS |
| 私有 URI 不改写 | PASS |
| API 主机不进入公开 URL | PASS |
| 生产页面已经使用 CDN，而后台显示关闭 | PASS，未发现。页面仍是本地地址 |
| 生产 CDN rewrite 已启用 | 未启用，这是本轮要求 |

## 开关的职责

`STORAGE_CDN_ENABLED` 只决定公开原图的浏览器 URL 是否改写到 CDN。它不表示 Bucket 里有没有对象，也不切换 Stream Wrapper。

`PublicUrl::enabled()` 读取 `xedc_storage.cdn_enabled`。`BootGuard` 仅在环境变量精确为 `1` 时把它设为真；未设置时使用供应商运行时文件里的 `cdn_enabled`，当前为假。

`PublicUrl::delivery()` 的规则：

- `private://` 和 `temporary://` 保持原样。
- 公开包装器还不是对象存储时，保持原样。生产现在就是这条，所以页面继续由 `PublicStream` 生成 `/sites/default/files/...`。
- 包装器已是对象存储，但开关关闭：原图使用本站 `/sites/default/files/<目标>`，不使用 CDN，也不调用会生成 API 主机的父类 `getExternalUrl()`。
- 包装器已是对象存储且开关打开：原图使用 `https://cdn.gitxx.xyz/s3fs-public/<目标>`。前缀只加一次。
- 目标以 `styles/` 开头：始终是本站 `/sites/default/files/styles/...`。

`XedcPublicS3fsStream::getExternalUrl()` 走同一函数。结果里如果出现 `r2.cloudflarestorage.com`，改回本站文件 URL。

## 与页面显示的关系

后台“CDN URL 改写”同时看开关和当前生成的样例 URL：

- 开关关且样例是本地：已关闭。
- 开关开且样例是 CDN：已开启。
- 开关开但页面仍是本地：显示“开关已开启，页面仍未改写”。
- 开关关但样例已经是 CDN：显示矛盾。

当前无接管进程的实测是：公开 URL 本地，CDN URL 改写已关闭，目标包装器未注册。不会把配置接受显示成接管成功。`/admin/config/media/object-storage/readiness` 未登录访问返回 403。后台 HTML 没有在浏览器里点开，状态行来自 CLI 的 `RuntimeStatus::observe()`。

## 隔离测试

`r2-architecture-self-test.php` 的 `RESULT PASS failures=0` 包含这些断言：开关关闭时远程原图仍是本站 URL；开关打开时地址是 `https://cdn.gitxx.xyz/s3fs-public/test/example.jpg`；样式在开关打开时仍含 `/styles/` 且不含 `s3fs-public`；当前进程开关为关；线上文件 URL 仍是本地。

包装器 IO 进程里 CDN 变量为 `0`。`getExternalUrl()` 对测试原图返回本站路径，不含 `cdn.gitxx.xyz`，不含 API 主机。样式外部 URL 留在 `/sites/default/files/styles/`。

## 现有对象的 CDN 映射

只读请求 `https://cdn.gitxx.xyz/s3fs-public/2026-10/IMG_3009.jpeg` 返回 200，`image/jpeg`，163198 字节。这与对象键 `s3fs-public/` 加公开相对路径一致，URL 中没有桶名。

同一对象的 API 地址 `https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com/dclili/s3fs-public/2026-10/IMG_3009.jpeg` 返回 400。所以不能用 S3FS 默认的 path-style CNAME。

样式对象不在这个映射上。`https://cdn.gitxx.xyz/s3fs-public/styles/max_325x325/public/pictures/IMG_9619.jpeg.avif` 返回 404。本地 `https://gitxx.xyz/sites/default/files/styles/max_325x325/public/pictures/IMG_9619.jpeg.avif` 返回 200 `image/avif`。本地 WebP `.../styles/xa_cover/public/2026-10/IMG_1204.png.webp` 返回 200 `image/webp`。
