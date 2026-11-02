# 图片、WebP、AVIF 和图片样式

日期：2026-11-02。

## 状态

| 项 | 结论 |
| --- | --- |
| GD 能写 JPEG、PNG、WebP、AVIF | 已完成。本机 `imagejpeg`、`imagepng`、`imagewebp`、`imageavif` 以及对应的读取函数都存在 |
| Imagick | 未安装。不是必需依赖，没有假装可用 |
| 上述四种样本上传到 R2 并校验 MIME 与 SHA-256 | 真实 R2 测试通过 |
| JPEG 转 WebP 后原 JPEG 仍在 | 真实 R2 测试通过。转换失败不会删源文件 |
| `thumbnail`、`photo_gallery_650` 由 Drupal Image Style 生成后再上传 | 真实 R2 测试通过 |
| 其余样式（`photo_gallery_1300`、`photo_card_640`、`video_cover`、`xa_hero` 等） | 配置里存在。尚未对 R2 逐个生成 |
| 生产原图进入 R2、页面访问、CDN 上的生产图片 | 尚未完成。接管和 CDN rewrite 都关着 |
| 浏览器打开图片页 | 浏览器实际验证未做 |
| 同一图片并发请求、样式缓存失效后再生 | 尚未完成。没有做并发压测 |

## 服务器能力

就绪检查读到的格式：JPEG、PNG、WebP、AVIF 都可写。Imagick 为 false。AVIF 样本上传后的 Content-Type 校验是 `image/avif`。如果以后 GD 不能写 AVIF，流水线会把 `avif_encoder` 标为失败，不会记成成功。

## 隔离测试怎么做

`MediaObjectPipeline` 用 GD 生成 48×32 样本，写到 `/tmp/xedc-storage`，再上传到 `_xedc_integration_test/`。不把远程 URL 交给 GD。样式用 `ImageStyle::createDerivative()`，源是本地 JPEG 路径，目标先放 `temporary://xedc-r2-style`，再上传。样式生成失败时本地 JPEG 必须仍在。

第二次上传同一 JPEG 返回 `exists`。这只说明隔离前缀上的幂等，不是生产文件去重。

## 生产路径现在怎样

`ImagePipeline` 和 `ImageVariantBuilder` 已能把远程 URI 先落到临时目录再交给 GD。接管关闭时，新图片仍写入本地 `public://`。WebP/AVIF 副本记在 `xedc_storage_variant`，这张表刚刚建好，还没有生产行。

原图不被转换结果替换。转换异常只记日志，不删除已保存的原文件。

现有图片样式至少包括：`thumbnail`、`medium`、`wide`、`media_library`、`photo_gallery_650`、`photo_gallery_1300`、`photo_card_640`、`meitu_card_cover`、`video_cover`、`xa_cover`、`xa_hero`、`xa_hero_840`、`images_450x800`，以及若干 `max_*`。只有 `thumbnail` 和 `photo_gallery_650` 在这次 R2 测试里生成过。

## 2026-11-02 业务链路

创建了一条未公开的测试图片内容、一个图片媒体和一张 64×40 PNG。`ImagePipeline::validate` 识别为 PNG。`thumbnail` 和 `photo_gallery_650` 在本地生成，URL 不是 CDN。WebP 和 AVIF 转换后原 PNG 还在。测试内容已删除，图片节点数回到 54，文件实体回到 137。没有浏览器打开详情页或列表页。原图没有上传到 R2。

## 不能写成已经完成的事

没有在生产内容上生成样式。没有用浏览器确认页面 `<img>`。没有打开 `s3fs.use_s3_for_public` 来让样式控制器走 R2。CDN 上取回的是测试 JPEG 的对象键，见 CDN 文档，不是站点现有图片。
