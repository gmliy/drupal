# 测试记录

日期：2026-11-02。执行用户 `www-data`。

命令：

```bash
sudo -u www-data vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/r2-integration-self-test.php
```

结果：`all passed`。这是 CLI 里的真实 R2 调用，不是 PHPUnit 套件，也不是浏览器测试。

## 状态

| 检查 | 结论 |
| --- | --- |
| `BootGuard::contentType('video/mp4')` | 自动化测试通过 |
| 非法 MIME 变成 `application/octet-stream` | 自动化测试通过 |
| Runtime 前后都关闭，`public://` 仍是 `PublicStream` | 自动化测试通过 |
| 活动配置 `silly`，就绪标记不是可接管 | 自动化测试通过 |
| 本地公开 URL 不改写，私有 URL 不是永久 CDN | 自动化测试通过 |
| `file_managed` 137 不变 | 自动化测试通过 |
| JPEG、PNG、WebP、派生 WebP、AVIF、两种样式、视频、ZIP | 真实 R2 测试通过 |
| 视频 206、`video/mp4`、`Accept-Ranges: bytes` | 真实 R2 测试通过。样本不可播放 |
| CDN 200、`image/jpeg` | 真实 HTTP 通过。对象是测试 JPEG，不是生产文件 |
| 集成前缀删除后为 0 | 真实 R2 测试通过 |
| 本地私有文件不签名 | 自动化测试通过 |
| 浏览器 | 浏览器实际验证未做 |
| 未登录会员、VIP、过期签名、坏 ZIP、迁移复制 | 尚未完成 |

## 清理

本次创建的键都以 `_xedc_integration_test/` 开头，删除结果都是 true。之后该前缀对象数为 0，`_xedc_storage_test/` 为 0。`/tmp/xedc-storage` 为空。

Bucket 列表在删除后还有 1 个对象。计数没有打印键名。它不在两个测试前缀里，所以没有删。需要人工确认它是什么。

## 2026-11-02 验收脚本

`r2-acceptance-precheck.php` 通过。它只做普查、HEAD 和清单，不上传生产文件。

`r2-acceptance-business.php` 有一项失败：`s3fs-private/` 测试对象能从 `https://cdn.gitxx.xyz` 匿名读取。其余检查通过，测试节点和文件已删回原数量。这是 CLI 和 HTTP，不是浏览器。

PHPUnit 和 PHPCS 没有装在 `vendor/bin`。改过的 PHP 文件用 `php -l` 通过。没有安装这两套工具。

## 没有覆盖的测试

没有把测试文件写入生产节点。没有并发打同一张图。没有对整个 Bucket 做重复哈希扫描，避免把无关对象下载下来。没有用浏览器播放视频或打开相册。没有对损坏 ZIP、压缩炸弹做生产导入器演练。没有跑迁移复制和回滚。

第一阶段适配层的脚本 `storage-adapter-self-test.php` 在更早的会话里通过。本次又跑了上面的集成脚本。PHPUnit 用例没有新增；模块测试目前是 Drush 脚本。
