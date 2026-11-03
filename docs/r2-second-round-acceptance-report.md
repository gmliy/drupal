# R2 第二轮验收

时间：2026-11-02 21:15–21:25 UTC。没有执行生产接管。

总评：**PARTIAL**。CLI 里的容器编译和 Drupal 文件读写已经通过。独立 PHP-FPM 没有做成。生产站点仍使用本地包装器。

## 分项

| 专项 | 结果 |
| --- | --- |
| CLI 容器编译 | PASS |
| 独立 PHP-FPM 容器编译 | BLOCKED |
| 生产 PHP-FPM 仍是本地包装器 | PASS |
| Drupal 文件 API 写入、读取、删除测试对象 | PASS，仅 CLI 进程 |
| PHP-FPM 使用目标包装器读写 | NOT TESTED |
| CDN 开关语义 | PASS |
| 生产 CDN rewrite | 未启用 |
| 128 个对象和本地原文件 | PASS，只读 |
| 图片样式仍在本地 | PASS |
| 后台状态不把配置说成接管 | PASS，浏览器未登录，页面返回 403 |
| EngagementBuilder 代码 | PASS |
| `/cosplay/cos/315` | PASS，源站 200 |
| 生产环境保持原状 | PASS |

详细记录在：

- `docs/r2-fpm-container-lifecycle-report.md`
- `docs/r2-drupal-wrapper-io-report.md`
- `docs/r2-cdn-switch-semantics-report.md`

## 本轮代码

- `PublicUrl` 把 `STORAGE_CDN_ENABLED` 定义为公开原图 URL 改写开关。关闭时不生成 CDN，也不回退到 API 主机。
- `XedcPublicS3fsStream::getExternalUrl()` 使用同一规则。`dir_rewinddir(): bool` 与 S3FS 父签名对齐，否则接管编译会致命。
- `RuntimeStatus` 分开显示配置、当前容器类、目标包装器、CDN 开关和“实际 Drupal 写入 R2：未验证”。
- 新增 `tests/manual/r2-fpm-wrapper-io-test.php`。
- 架构自测增加开关矩阵，以及临时 WebP、AVIF 衍生物。

没有修改五个生产开关、PHP-FPM、Nginx、`config/sync`、`file_managed` 或 9 条 private ZIP。没有 `git commit`。

## 保护核对

- `cache_container` 两行在两次内存编译前后 checksum 与 created 相同。
- `file_managed` 137。公开 128 条本地文件都在。私有 9 条仍然缺失。
- 架构自测再次 Head 全部 128 个对象：大小和 Cache-Control 一致，3 个样例 SHA-256 一致。没有改元数据。
- 测试前缀 `s3fs-public/xedc-r2-isolated-test/` 结束后没有对象。`s3fs_file` 里该前缀行数为 0。
- 本地样式目录现有 774 个文件、15 个样式目录。上一轮记录是 768。本轮探针目录 `_xedc_r2_style_probe` 和 `_xedc_architecture_probe` 已不在。没有删除生产样式。多出来的文件与页面访问时生成衍生物相符，没有迁移到 R2。
- FPM master 32897，worker 73147、92746、103200 都没有五个存储变量。日志中最后一次 reload 是 17:05:27。
- 源站 `/cosplay/cos/315` 返回 200，34763 字节。24 处本地文件 URL，其中样式 URL 仍是 `/sites/default/files/styles/`。没有 `cdn.gitxx.xyz`，没有 R2 API 主机，没有“网站遇到意外错误”。
- 首页经 Cloudflare 返回 200，同样没有 CDN 主机和 API 主机。
- 就绪页未登录返回 403。CLI 状态行是：配置未接受、容器是本地包装器、目标包装器未注册、CDN 已关闭、写入 R2 未验证、图片样式本地、两项接管都未接管。

## 最终十问

1. Drupal 能在独立 CLI 进程里完成容器编译。接管标志只存在于该进程时，编译结果注册 `XedcPublicS3fsStream`。编译结果没有写入生产缓存。
2. PHP-FPM 能否在隔离环境使用目标包装器：**BLOCKED**。要做到这一点必须改 pool 或新建 pool 并重载，本轮不允许。生产 FPM 仍使用 `PublicStream`。
3. Drupal 能通过包装器写入 R2。证据是 CLI 进程里的 `file_system->saveData()`，对象随后能被 Head 到。生产 FPM 没有做这次写入。
4. Drupal 能从 R2 读回该测试文件，SHA-256 与写入内容一致。
5. Drupal 的 `file_system->delete()` 能删掉该测试对象，前缀列表为空。
6. CDN URL 规则正确，但生产页面没有启用。打开开关且包装器为对象存储时，原图应为 `https://cdn.gitxx.xyz/s3fs-public/...`。现有样例对象的这个 URL 返回 200。API URL 返回 400。
7. 图片样式保持本地。页面、WebP、AVIF 都走 `/sites/default/files/styles/`。同名 CDN 对象返回 404。临时衍生物生成通过。
8. EngagementBuilder 已修复。构造函数必需参数是 5，服务定义仍是 6 个参数。内容页源站返回 200。本轮中曾看到 `xedc_preprocess_html()` 重复声明导致的 500；当前 `xedc.theme` 不再声明这个函数，页面已恢复。没有为它清理缓存或重启 FPM。
9. 生产环境保持原状。五个开关未改，容器缓存未改，128 个对象还在，本地原文件还在，9 条缺失 ZIP 未改，Nginx 和 `config/sync` 未改。
10. 距离 Public takeover 仍差这些条件：一次真实 PHP-FPM 请求里的 `public://` 必须是 `XedcPublicS3fsStream`；同一次 FPM 请求用测试前缀写入并确认对象在 R2、样式仍在本地；确认打开 `STORAGE_CDN_ENABLED` 后 HTML 使用 CDN 且不出现 API 主机；维护窗口做过关闭变量并重建容器的回滚；Private takeover 继续关闭，直到 9 个 ZIP 有真实文件或被明确废弃。CLI 通过不能代替第 1 和第 2 条。
