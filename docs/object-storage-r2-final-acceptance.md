# Cloudflare R2 最终验收

## 本轮整改复测：2026-11-02 18:18 UTC

没有部署 WAF，没有改缓存配置，没有改上传代码，没有复制历史文件。五个开关仍关闭，`public://` 仍是 `PublicStream`，文件实体仍是 137。

| 问题 | 本轮 |
| --- | --- |
| 私有路径编码变体是否已有拦截证据 | 部分有。规范路径、`%2F`、`%2f`、查询参数、多余斜杠和 `../` 是 403 拦截页。大写前缀是 200，读到了对象正文 |
| 图片 CDN GET 是否与 R2 一致 | 否。R2 是 `max-age=60`，GET 是 `max-age=14400` |
| 视频 CDN GET 是否与 R2 一致 | 否。同样是 R2 60 秒、GET 14400 秒，`Age: 2` |
| 是否还要 Cloudflare 人工操作 | 要。收紧不区分大小写的 WAF，并把 Browser Cache TTL 核对为 Respect Existing Headers |
| 是否可以开始阶段 A | 否 |

混合大小写、`%252F` 和三层编码本轮没有请求，因为大写前缀已经匿名可读。`%252F` 的 200 仍只属于 18:07 的历史结果。`r2.dev` 仍是待人工确认。没有代码缺陷需要修改。

## 历史验收：2026-11-02 18:07–18:10 UTC

站点 `https://gitxx.xyz`。活动供应商 `silly`，桶 `dclili`，region `auto`，S3 API 主机 `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`，CDN `https://cdn.gitxx.xyz`，前缀 `s3fs-public/` 与 `s3fs-private/`。

本轮没有打开生产接管，没有复制历史文件，没有修改 URI、WAF、Bucket Policy 或生产对象 Metadata。开始时工作区已有大量未提交修改，没有执行 reset、checkout、clean、commit 或 push。

## 总判定

| 项目 | 判定 |
| --- | --- |
| `r2.dev` 已禁用 | 待人工确认。程序不能读取 Cloudflare 控制台。活动配置里没有 `r2.dev` 主机名 |
| 五个生产开关全部关闭 | 已通过 |
| `public://` 仍是本地 PublicStream | 已通过 |
| S3FS 与 xedc 双重接管 | 已通过。两边接管都是关闭，实际流是 Drupal 核心类 |
| MP4 Cache-Control 异常找到明确层 | 已通过。层是 Cloudflare 的 GET 边缘缓存。控制台里的具体开关待人工确认 |
| 文本缓存头被保留 | 已通过 |
| PNG 同样被改成 14400 | 存在风险。历史记录只写了 MP4，本轮确认图片一样 |
| WAF 规范路径、`%2F`、`%2f` | 已通过。403，拦截页，不含测试标记 |
| WAF `%252F` | 未通过。200，读到了字面量 `%2F` 键上的测试对象 |
| 大小写、多余斜杠、点段、查询参数 | 尚未执行。`%252F` 返回 200 后停止 |
| 私有前缀匿名访问 | 存在风险。规范路径被拒绝，双重编码仍能读到桶内对象 |
| 9 个私有文件找到恢复来源 | 未通过。任务行、本地副本和远程对象都没有 |
| 128 个公开文件具备迁移条件 | 已通过。本地都在，哈希已记录，远程键不存在 |
| 766 个图片样式有处理策略 | 已通过。保留本地，不列入必须复制，接管前再做一次生成验证 |
| CDN 视频 Range 和播放 | 已通过。本轮安全脚本重新观察到 200、206、Accept-Ranges 和浏览器拖动。这是新的测试 MP4，不是生产视频 |
| 进入历史公开文件复制 | 尚未执行，且当前不允许开始 |
| 进入生产接管 | 未通过 |

## 配置和开关

Drush 读到的运行值：

- `xedc_storage.runtime_applied` = false
- `s3fs.use_s3_for_public` = false
- `s3fs.use_s3_for_private` = false
- `xedc_storage.cdn_enabled` = false
- 五个环境变量均未设为 `1`
- `public://` = `Drupal\Core\StreamWrapper\PublicStream`
- `private://` = `PrivateStream`
- `temporary://` = `TemporaryStream`

S3FS 8.x-3.12 的数据库配置仍是 region `us-east-1`、hostname 为空、接管关闭。xedc 保存的才是 R2 endpoint 和 region `auto`。两套配置没有同时接管文件流。本轮没有调整它们。

测试结束后再次确认：四个 Settings 开关仍是关闭，`public://` 仍是 `PublicStream`，文件实体仍是 137 行，fid 合计仍是 28272。

## 本轮实际执行的测试

| 时间 (UTC) | 命令 | 结果 |
| --- | --- | --- |
| 18:07 | 一次性探针：确认配置，上传文本和 PNG，做 WAF 变体，只读盘点文件，删除本次测试对象 | 文本缓存头保持 60 秒。PNG GET 变成 14400。规范私有路径 403。`%252F` 200。公开 128 都在，私有 9 都缺失。前缀对象数前后都是 0 |
| 18:08 | 单独的测试 MP4。`PlayableMp4` 需要 `.mp4` 后缀；前一次临时文件没有后缀，所以没有当成编码器缺失 | HeadObject 为 `max-age=60`。GET 为 14400，MISS 然后 HIT。HEAD 仍是 60。Range 206 `bytes 0-15/9829`。对象已删除 |
| 18:09 | `php /tmp/phpunit.phar -c web/modules/custom/xedc_core/phpunit.xml` | 16 测试、52 断言通过。2 条 PHPUnit 弃用提示，没有断言失败 |
| 18:09 | `cdn-delivery-self-test.php` | PASS，20 项。本地地址没有改写成 CDN。脚本在进程内临时改 Settings 做规则推演，结束时恢复，没有写生产配置 |
| 18:09 | `cdn-security-self-test.php` | PASS。公开对象 200。私有和编码路径 403。文本和版本化对象的缓存头保持原值。MP4 200/206，浏览器 `duration=2 time=1 seek=yes`。测试前缀回到 0。视频 GET 缓存头仍是 14400 |
| 18:10 | `cdn-migration-precheck.php` | 137 个文件，可复制 128，缺失 9，远程一致 0，校验失败 0，样式 15 目录 / 766 文件，两个前缀对象数 0，URI 未变，复制 0 |
| 18:09 | `php -l` 覆盖 `xedc_core` 的 src 与 tests，共 148 个文件 | 全部通过 |
| 18:09 | 服务容器解析 storage config、credentials、delivery、inspector、migration | 容器正常 |
| 18:09 | `composer show drupal/s3fs`、`aws/aws-sdk-php` | 3.12.0 与 3.402.5 |

探针和安全脚本只删除了本次创建、且位于测试前缀或本次大小写变体键上的对象。没有删除其他对象。

副作用：交付脚本和安全脚本写入了存储日志；预检查写入了迁移清单行。没有复制文件，没有改 URI。文件实体指纹在预检查前后一致。

## 分项说明

缓存、WAF、文件清单和后续步骤分别写在：

- `docs/object-storage-r2-cache-investigation.md`
- `docs/object-storage-r2-waf-final-test.md`
- `docs/object-storage-r2-migration-inventory.md`
- `docs/object-storage-r2-production-runbook.md`

视频 Range 的代码仍在 `CdnInspector::inspectVideo()`。本轮安全脚本实际跑了 GET、起始 Range、Accept-Ranges 和浏览器拖动。缓存探针里的 Range 只用于确认 MP4 响应，不单独当成播放回归。历史播放记录和本轮脚本结果一致，都是测试对象，不是生产文件。

## 需要在 Cloudflare 控制台做的事

1. 收紧 `cdn.gitxx.xyz` 的 WAF Block 规则，让原始 URI 中只要包含 `s3fs-private` 就拒绝，覆盖 `%252F` 和大小写。表达式见 WAF 报告。不要改 Bucket Policy。
2. 把 Browser Cache TTL 设为 Respect Existing Headers，并检查有没有把图片、视频改成 `max-age=14400` 的缓存配置。Cache Rules 页面以前是空的，那是历史观察，本轮没有再打开控制台。
3. 确认 R2 Public Development URL 已禁用。

改完后用新的测试对象复测。PNG 和 MP4 的 GET 应与 HeadObject 一致。`%252F` 应为 403，且正文不是测试对象。

## 下一阶段

不允许进入历史公开文件复制，也不允许生产接管。128 个公开文件已经可以生成复制清单，但要等 WAF 和缓存复测通过后再按运行手册的阶段 A 开始。9 个私有文件继续排除。
