# R2 隔离测试报告

脚本：`web/modules/custom/xedc_core/tests/manual/r2-architecture-self-test.php`。

执行时五个生产变量在 PHP-FPM 中都不存在。脚本没有打开接管。结果：`RESULT PASS failures=0`。

另有 `tests/manual/storage-self-test.php`：`RESULT passed`。它确认完整配置只设置 `settings_accepted`，`runtime_applied` 仍为 false。

## 结果

| 测试 | 结果 | 说明 |
| --- | --- | --- |
| 本地 PublicStream | PASS | 类名是 `Drupal\Core\StreamWrapper\PublicStream`。 |
| 本地 PrivateStream | PASS | 类名以 `PrivateStream` 结尾。 |
| 未接管不误报成功 | PASS | `runtime_applied=false`，公开接管标志为 false，写入位置是未验证。 |
| 容器编译不替换包装器 | PASS | 内存中的临时容器在接管关闭时保持 `PublicStream`。没有重建生产容器。 |
| 接管编译目标 | PASS | 目标类是 `XedcPublicS3fsStream`。这是类选择测试，不是生产切换。 |
| 公开 URL | PASS | `public://test/example.jpg` 变为 `https://cdn.gitxx.xyz/s3fs-public/test/example.jpg`。 |
| 不含 API 主机和 Bucket 段 | PASS | 没有 `r2.cloudflarestorage.com`，没有 `/dclili/`。 |
| 不重复前缀、保留子目录、编码空格 | PASS | |
| API 主机被拒绝 | PASS | |
| 样式不进 CDN | PASS | 地址留在 `/sites/default/files/styles/`。 |
| 私有 URI 不改写 | PASS | `private://` 返回空。 |
| 当前真实文件 URL | PASS | `IMG_3009.jpeg` 仍是本站 `/sites/default/files/`。 |
| 样式探针写入本地后删除 | PASS | 只创建并删除 `_xedc_architecture_probe`。 |
| 临时 AVIF 衍生 | PASS | 用真实原图和 `max_325x325` 写到 `temporary://` 后删除。 |
| WebP 样式 | NOT TESTED | 本轮只生成了 AVIF 临时文件。 |
| 样式并发 | NOT TESTED | 没有做并行请求。 |
| HeadBucket | PASS | 只证明 API 和 Bucket。 |
| 隔离 PNG 上传、回读、SHA-256、MIME、Cache-Control | PASS | 键在 `s3fs-public/_xedc_storage_test/`，缓存 `public, max-age=3600`。这是 R2 API 隔离测试，不是 PHP-FPM 生产上传。 |
| 独立 MP4、CDN Range 206 | PASS | ffmpeg 生成的 160x120 测试片，不是生产视频。 |
| 测试片浏览器播放 | NOT TESTED | 只验证了 Range 和正文，没有开浏览器播放这个测试片。 |
| 私有测试对象匿名访问 | PASS | CDN 返回 403，响应不含测试标记。 |
| 删除本轮测试对象 | PASS | 三个测试键已删除，图片键 Head 为不存在。 |
| 128 个历史对象仍在 | PASS | HeadObject 128/128，大小和 `public, max-age=3600` 一致。 |
| 清单抽样 SHA-256 | PASS | 前 3 个与清单一致。没有重传，没有改元数据。 |
| EngagementBuilder 可实例化 | PASS | 构造函数必填参数为 5，第 6 个可选。 |
| S3 子类自身的 stream_open | NOT TESTED | 构造 `S3fsStream` 会初始化 S3 客户端并注册协议。样式本地写入用的是 `PublicStream` 探针，URL 规则用的是 `PublicUrl`。 |

隔离测试通过不能写成“PHP-FPM 生产接管已通过”。
