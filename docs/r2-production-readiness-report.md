# R2 生产接管就绪报告

当前结论：**还不能开启 Public takeover**。Private takeover 继续单独关闭。

## 当前生产状态

| 项目 | 状态 |
| --- | --- |
| public:// | 本地 `PublicStream` |
| private:// | 本地 `PrivateStream` |
| 五个生产变量 | 未设置，PHP-FPM worker 环境中不存在 |
| PHP-FPM | 没有重载。抽查 worker pid 仍是整改前的 92746 |
| CDN rewrite | 关闭。首页和内容页 HTML 没有 `cdn.gitxx.xyz` |
| Drupal 文件 URI | 未改 |
| 128 个 R2 公开对象 | 仍在，Head 与清单大小、缓存一致，抽样 SHA-256 一致 |
| 9 条缺失 private 记录 | 未删除、未补文件 |
| 本地图片样式 | 保留。探针目录已删除 |
| 本地 128 个原文件 | 未删除 |

`/cosplay/cos/315` 现返回 200，页面中有 24 处本地文件地址。这是 EngagementBuilder 修复的结果，不是 R2 接管。

## 正式开启 Public takeover 之前必须满足

1. 在真实 PHP-FPM 请求里，`public://` 的类变为 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。只看到环境变量不算。
2. 一次新的公开测试上传能在 R2 的 `s3fs-public/` 下 Head 到，而本地 `sites/default/files` 没有成为那次写入的唯一副本。测试对象用测试前缀，用完删除。
3. 该文件的浏览器 URL 是 `https://cdn.gitxx.xyz/s3fs-public/...`，HTML 中不出现 R2 API 主机。
4. 现有图片样式 URL 仍是 `/sites/default/files/styles/...` 且返回 200。新样式写到本地样式目录，而不是只出现在 R2。
5. 一张真实公开视频的 Range 仍返回 206。不要为了测试覆盖那两个历史 MP4。
6. 首页、图片列表、图片详情里的旧图没有成片失效。
7. 回滚演练在维护窗口做过一次：关掉变量、用 FPM 请求重建容器、确认回到 `PublicStream`，并且不删除已复制的 128 个对象。
8. Private takeover 仍关闭，直到 9 个 ZIP 有真实字节或管理员明确废弃。

启用检查页 `/admin/config/media/object-storage/readiness` 现在会显示包装器类、HeadBucket 和“实际写入位置：未验证”。配置正确不会显示成接管成功。

## 本轮没有做的生产动作

没有设置或修改 `XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED`。没有重载 PHP-FPM，没有重建生产容器，没有迁移样式，没有重跑 128 个文件的复制。
