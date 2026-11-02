# 对象存储中心实施结果

日期：2026-11-02

对象存储中心已经接进现有的 `xedc_core`。网站仍在使用本地文件系统。没有真实对象存储凭证，所以没有做真实上传，也没有切换供应商。

后台入口是 **配置 → 媒体 → 对象存储**，地址为 `/admin/config/media/object-storage`。架构说明在 `docs/object-storage.md`，环境变量占位符在 `.env.example`。

## 1. 基础环境

- Drupal `11.4.11`，PHP `8.5.12`。
- S3 File System `8.x-3.12`（Composer `drupal/s3fs` `3.12.0`），已启用。
- AWS SDK for PHP `3.402.5`。
- 没有安装第二套 S3 模块，也没有改 S3 File System 的代码。
- 当前 `s3fs.settings` 的 Bucket 仍是空的。`public://`、`private://`、`temporary://` 都还在本地。
- 文件实体共 131 个：公共 122 个，私有 9 个，`s3://` 为 0。这是 Drupal 文件表统计，不是对象存储账单。

## 2. 配置架构

已实现供应商无关配置。配置对象 `xedc_core.storage` 可以保存多份供应商资料，并指定当前选中的一份。选中配置不会改 `settings.php`，也不会移动文件。

运行时连接只在 `XEDC_STORAGE_RUNTIME=1` 且 Bucket、Region、Endpoint、密钥都有效时写入 S3 File System 的运行时设置。缺任何一项时，网站继续使用本地文件，并给出配置错误。Access Key 和 Secret Key 只从环境变量读取，不进入配置、页面或日志。

`public://` 和 `private://` 默认不接管。接管必须同时设置对应开关和 `STORAGE_I_UNDERSTAND_TAKEOVER=1`。当前这三项都是关闭的。

## 3. 平台支持

八个供应商都有独立的 Endpoint、Region、路径风格和文件夹参数，没有把 Cloudflare R2 写死。

| 平台 | 状态 |
| --- | --- |
| Cloudflare R2 | 理论兼容，待凭证验证 |
| Amazon S3 | 理论兼容，待凭证验证 |
| Wasabi | 理论兼容，待凭证验证 |
| Backblaze B2 | 理论兼容，待凭证验证 |
| DigitalOcean Spaces | 理论兼容，待凭证验证 |
| Vultr Object Storage | 理论兼容，待凭证验证 |
| MinIO | 理论兼容，待凭证验证 |
| 自定义 S3 Endpoint | 理论兼容，待凭证验证 |

限制：S3 File System 3.12 是单 Bucket；普通上传走 `PutObject`，不是分片上传；不能把 ETag 当成所有平台通用的 MD5。

## 4. 文件安全

- 一张现有公共图片返回 `200`。
- 匿名访问 `/xedc/storage/protected/1` 返回 `403`。
- 新建并随即删除了一个临时私有文件 `private://_xedc_storage_test/access-check.txt`。匿名访问返回 `403`，响应里没有文件内容。
- 公共文件和本地私有文件都不会被这个入口签成对象存储地址。
- 预签名 URL 的代码上限是 300 秒，默认 120 秒。没有凭证，过期签名没有真实测试。
- 网站没有 VIP 角色。VIP 下载策略已记为私有，权限 `download xedc protected files` 没有授给任何角色。
- 现有 9 条私有文件记录在磁盘上没有对应文件，因此没有拿它们做下载测试。
- CDN 域名只保存在配置里，没有替换线上图片地址。

## 5. Drupal 兼容

以下匿名访问均返回 `200`：美图详情、视频详情、文章详情、一张已生成的 WebP 图片样式。匿名访问 Media 编辑页返回 `403`。匿名访问 `/member` 返回 `302`。文件管理中心页面返回 `200`。

Redis 缓存仍是 `Drupal\redis\Cache\PhpRedis`。`s3://` 在文件管理中心被标为远程文件，不按本地孤立文件清理，也不计入本地重复文件的丢失数。隔离期仍是 14 天。

## 6. 测试结果

| 项目 | 结果 |
| --- | --- |
| 对象存储上传、读取、下载、删除 | 待凭证验证 |
| 对象存储 SHA-256 | 待凭证验证 |
| 内存中的 SHA-256、大小和 MIME 比较 | 真实通过 |
| 错误配置保持本地存储 | 真实通过 |
| 无凭证连接测试 | 真实通过，状态为等待凭证 |
| 迁移预检查不复制、不改 URI、不删源文件 | 真实通过 |
| 实际文件迁移 | 未执行 |
| 匿名公共文件、匿名私有测试文件、受保护入口 | 真实通过 |
| VIP 与过期签名 | 未执行；过期签名待凭证验证 |
| PHP 语法检查 | 真实通过 |
| 配置结构检查 | 真实通过，0 个结构错误 |
| 缓存重建 | 真实通过 |
| PHPUnit、PHP_CodeSniffer、PHPStan | 未执行；项目没有对应测试和配置 |
| 完整文件清理脚本 | 未执行 |

页面通过 HTTPS 请求验证了状态码、正文和样式聚合。没有用浏览器逐项点击，也没有单独做移动端视口操作。

## 7. 生产环境保护

- 没有修改文件表中的 URI，没有移动或删除生产文件。
- 没有修改仓库里的 `config/sync`，也没有改 `s3fs.settings`。
- 数据库新增了配置对象 `xedc_core.storage`，更新编号 `10005`。没有新增数据表。
- `settings.php` 只在末尾增加了运行时文件的引入。原有内容未改。备份在 `/var/www/drupal/private/backups/settings.php.before-object-storage`。
- 原有未提交改动都还在，没有执行提交、推送、重置或清理。
- 没有启用运行时连接，没有执行生产迁移，没有开启自动删除。

## 8. 剩余工作

要做真实连接，需要在服务器环境中提供：Bucket 名称、Region、S3 API Endpoint、Access Key、Secret Key。Cloudflare R2 通常是 `https://ACCOUNT_ID.r2.cloudflarestorage.com`、Region `auto`、Path-style。这些值不要写入 Git。Bucket 需要在供应商控制台创建。

在迁移校验完成前保持 `XEDC_STORAGE_RUNTIME=0`。`img.gitxx.xyz` 和 `media.gitxx.xyz` 需要单独配置 DNS 和证书，目前没有生效。

尚未完成，也不能视为已完成的部分：

- 真实上传、分片上传和未完成分片清理的线上验证。
- 文件复制、断点续传、批量改 URI。
- 供应商账单同步。
- Bucket 生命周期规则和对象锁。
- 公共或私有文件系统接管。接管前，图片处理和压缩包逻辑仍依赖本地真实路径。
