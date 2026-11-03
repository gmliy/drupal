# 公开文件迁移报告

时间：2026-11-02 20:07:58Z 至 20:10:03Z。

任务编号：`mst_c515c90f`。供应商 `silly`，Bucket `dclili`。对象键为 `s3fs-public/` 加原来的 `public://` 相对路径。数据库 URI 没有修改。

## 结果

| 项目 | 结果 |
| --- | --- |
| 计划复制 | 128 |
| 已验证 | 128 |
| 失败 | 0 |
| 冲突 | 0 |
| 缺失源文件 | 0 |
| 字节 | 75,177,632 |
| 上传后再次下载并计算 SHA-256 | 128，不一致 0 |
| HeadObject 大小 | 128 个与数据库和本地一致 |
| Content-Type | 128 个与 `file_managed.filemime` 一致 |
| Cache-Control | 128 个均为 `public, max-age=3600` |
| 远程 `s3fs-public/` 对象 | 128 |
| 其中图片样式对象 | 0 |
| 远程 `s3fs-private/` 对象 | 0 |

复制前先做了一次新的 CDN 抽查：新的测试 PNG 在源站和 CDN 上都是 `public, max-age=60`，正文一致。私有前缀测试对象匿名访问返回 403，响应里没有测试标记。这两个测试对象在复制历史文件之前已删除。

历史文件名没有内容哈希，所以使用 `CachePolicy::forObject()` 的未版本化值 `public, max-age=3600`，没有写成一年 immutable。已存在的对象不覆盖。本轮目标键原先不存在，因此没有发生冲突。

## 数据库和本地文件

复制前后 `file_managed` 指纹相同：137 行，fid 合计 28272，URI CRC32 合计 300810938969。128 个本地源文件在复制后仍然存在。

清单：`docs/r2-public-migration-manifest.csv`。

## 抽样读取

CDN `https://cdn.gitxx.xyz/s3fs-public/...`：

| 样本 | 结果 |
| --- | --- |
| `2026-10/IMG_3009.jpeg` | 200，`image/jpeg`，163,198 字节，SHA-256 一致；第二次 GET 为 HIT，Age 26 |
| `2026-10/IMG_1204.png` | 200，`image/png`，SHA-256 一致 |
| 一张 2026-10 WebP | 200，`image/webp`，SHA-256 一致 |
| `pictures/IMG_2664.jpeg` | 200，另一目录，SHA-256 一致 |
| `2026-11/n195-001.jpg` | 200，另一月份，SHA-256 一致 |
| fid 5 历史 H.264 MP4 | 200，`video/mp4`，SHA-256 一致；`Range: bytes=0-15` 返回 206，`Content-Range: bytes 0-15/2255011`，前 16 字节一致 |

历史文件里没有 AVIF 原图。现有 AVIF 都是本地图片样式衍生文件。对应 CDN 地址返回 404，因为这 766 个以上的衍生文件没有复制。本地样式地址仍然返回 200，例如 `image/avif`。

浏览器打开 CDN 上的 `IMG_3009.jpeg` 能显示图片。fid 5 的 H.264 视频在无头 Chrome 中识别为 720x1280，时长 12.72 秒，与本地 ffprobe 一致。fid 86 是 HEVC，CDN 能读到元数据，时长 5.16 秒；当前无头 Chrome 没有解出 HEVC 画面。

结论：`PUBLIC_MIGRATION_PASS`。
