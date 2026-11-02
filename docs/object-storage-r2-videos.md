# 视频存储和 Range

日期：2026-11-02。

## 状态

| 项 | 结论 |
| --- | --- |
| 隔离前缀上传、SHA-256、Content-Type `video/mp4` | 真实 R2 测试通过 |
| S3 API Range 0–15 和 1000–1015 与本地字节一致 | 真实 R2 测试通过 |
| 预签名 URL 的 HTTP Range | 真实 R2 测试通过。状态 206，`Content-Type: video/mp4`，`Accept-Ranges: bytes` |
| 浏览器播放、拖动进度 | 浏览器实际验证未做 |
| 可播放的视频文件 | 尚未使用。样本是 256KB 随机字节，不是转码结果，不能播放 |
| FFmpeg | 没有引入，也不是依赖 |
| 现有两条视频改到私有前缀 | 尚未完成，需要人工确认。当前 URI 仍是 `public://` |
| 私有视频签名 | 代码已完成。接管关闭时不会签发。自动化测试确认本地私有文件没有得到签名 URL |

## 这次测了什么

`MediaObjectPipeline` 把随机字节写成 `.mp4`，以 `video/mp4` 上传。`RemoteFileReader::readRange()` 单次最多 1MB，测试只读 16 字节。预签名请求带 `Range: bytes=0-15`，比对响应体前 16 字节。签名 URL 不写入报告。

修复 MIME 正则之前，同一次上传会被存成 `application/octet-stream`。复测后存储类型和 HTTP 类型都是 `video/mp4`。

## 现有内容

文件实体里有 2 个视频、126 个图片。视频字段现在使用 `public://`。策略配置里的 `video_file = private` 不会自动改字段或移动文件。若以后要把视频改成私有，必须先保留现有 URI，复制到 `s3fs-private/`，校验后再单独决定是否改字段。本次没有做这件事。

公开视频在接管和 CDN rewrite 打开后，才会由 `s3fs-public/` 加 CDN 域名提供。现在新上传仍在本地。

私有视频不允许放进公开前缀。`DeliveryUrl` 对 `private://` 不生成永久 CDN 地址。远程私有文件只给出路由 `xedc_core.storage_protected_download`，由 `ProtectedDownloader` 在权限通过后签发短时 URL。

## 2026-11-02 容器复查

服务器没有 FFmpeg，没有安装它。测试写入了一个能被 `file` 识别为 ISO MP4 容器的小文件，没有可解码画面。预签名地址返回 206、`video/mp4`、`Accept-Ranges: bytes`。过期 1 秒的签名返回 403。`cdn.gitxx.xyz` 对同一对象返回 206 和 `video/mp4`，Cache-Control 为 `public, max-age=14400`。对象已删除。浏览器播放和进度拖动没有做。

## 播放还缺什么

Range 和类型已经在 HTTP 层对随机样本验证。要证明浏览器能播放并拖动进度，需要一个真实编码的视频、接管或 CDN 打开后的地址，以及一次浏览器操作。这三件事都还没有做。
