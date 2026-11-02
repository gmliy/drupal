# Cloudflare R2 第一阶段：文件处理兼容层

日期：2026-11-02。

结论先说：图片、压缩包和清理代码现在有统一的本地/远程适配层。远程地址不再交给 `realpath()`，处理用的临时文件放在 Web 根目录外。生产接管仍然关闭，新上传的图片和视频仍写入本地 `public://`。现有文件没有被复制、改 URI 或删除。

本次没有设置 `XEDC_STORAGE_RUNTIME`，没有设置 `STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`。没有修改 S3 File System 模块，也没有再实现一套 S3 SDK。读写对象继续用现有的 S3 File System 流和 AWS SDK。

活动供应商仍是 `silly`，Bucket 仍是 `dclili`。这两项没有在本阶段改动。

## 1. 这一阶段解决什么

后面的图片转换、视频读取、ZIP 导入和清理都需要本地路径。`public://` 一旦被 S3 File System 接管，`realpath()` 会返回 `FALSE`。如果业务代码把这个结果当成文件丢失，导入会中止，清理任务也可能把远程对象或仍留在磁盘上的旧文件判错。

本阶段先把这条边界收拢，再允许以后打开接管。现在 `public://` 仍是 `Drupal\Core\StreamWrapper\PublicStream`，落在 `/var/www/drupal/web/sites/default/files`。适配层对本地 URI 保持原来的真实路径行为。

## 2. 适配层

类都在 `web/modules/custom/xedc_core/src/Ops/Storage/`。服务定义在 `xedc_core.services.yml`。

| 类 | 服务 | 作用 |
| --- | --- | --- |
| `StorageUriResolver` | `xedc_core.storage_uris` | 判断地址是本地、远程、临时、普通路径，还是无法识别。远程包括 `s3://`，以及接管打开后的 `public://`、`private://`。 |
| `LocalTempFileManager` | `xedc_core.storage_temp` | 创建、校验和删除本地临时文件。 |
| `RemoteFileReader` | `xedc_core.storage_reader` | 读取对象头、分段内容和 SHA-256，并把对象流式写入临时文件。 |
| `RemoteFileWriter` | `xedc_core.storage_writer` | 把本地文件写回对象存储。用途只接受测试、迁移、处理后文件，以及 `_xedc_integration_test/` 集成测试。 |
| `StorageOperationGuard` | `xedc_core.storage_guard` | 拒绝本地清理任务删除远程对象，并在删除对象前检查文件实体和引用。 |
| `LocalFileBridge` | `xedc_core.storage_files` | 给 GD 和 `ZipArchive` 一个本地路径。远程 URI 先复制到临时目录。 |

`ImageVariantBuilder` 注册为 `xedc_core.storage_variants`。它仍通过 Drupal 文件 API 保存 WebP/AVIF 副本；目标协议是远程时，由 S3 File System 写回，不另开一条上传实现。

### 临时文件

目录是 `/tmp/xedc-storage`，属主 `www-data`，权限 `0700`。它在 `/var/www/drupal/web` 之外。若系统临时目录落在 Web 根目录内，管理器会拒绝使用，而不会改放到 `public://` 或 `private://`。

每个临时文件：

- 文件名含 32 位随机十六进制，不使用调用方传入的原始路径。
- 权限 `0600`，目录权限 `0700`。
- 只接受 `public`、`private`、`s3`、`temporary` 四种流。`file://`、带查询串或带 `#` 的地址会拒绝。
- 删除时确认真实路径仍在这个目录内。目录本身不能被删。路径中的 `..` 直接拒绝。
- 进程内第一次创建管理器时，清掉超过 6 小时的旧临时文件。

复制失败时会写一份诊断文件，文件名以 `diag-` 开头。诊断经过 `BootGuard::redact()`：签名、密钥样式和 URL 会去掉。异常抛给业务代码的文字不带密钥，也不带对象存储地址。

`LocalFileBridge::release()` 只删除它自己复制出来的那一份。本地源文件、ZIP 原件和已经写回的对象不在这里删除。

### 写回和删除

`RemoteFileWriter::putProcessed()` 把处理后的本地文件按 `processed` 用途写回。对象键必须落在公共或私有前缀下。上传前若远程已有相同 SHA-256 和大小，不覆盖。内容不同且没有明确要求覆盖时，保留远程对象。失败时只尝试删除这一次新创建、且校验没通过的对象。本地源文件不会被删除。

`delete()` 在用途是 `processed` 时先问 `StorageOperationGuard`。下面任一情况都拒绝，并且不会发删除请求：

- 没有文件实体，或实体不存在。
- 文件实体仍指向本地 URI。
- 对象键和文件实体计算出的键不一致。
- 同一个 URI 被多个文件实体使用。
- `file_usage` 里还有引用。

测试前缀和迁移回滚仍走原来的键前缀限制。本阶段的自测没有对 Bucket 发起上传或删除。

### 清理边界

`FilePathGuard::realpathIfLocal()` 看到远程 URI 时直接返回 `FALSE`，不调用 `realpath()`。临时目录里的路径被标成 `temporary`，`describe()` 不会把它当成可清理内容。

`StorageOperationGuard::localCleanupBlockReason()` 会挡住：

- 远程 URI 和 `s3://`。
- `temporary://`。
- 本地 `public://` 或 `private://` 已经有同目标 `s3://` 文件实体的情况。本地文件和远程对象都保留。

重复扫描、引用复查、未引用扫描、隔离和永久清理都改为走 `realpathIfLocal()`。隔离和清理计划在移动或删除前再查一次操作守卫。远程文件不能按本地路径隔离。

## 3. 接到哪些业务

| 位置 | 改动 |
| --- | --- |
| `ImagePipeline` | 注入 `LocalFileBridge` 和 `ImageVariantBuilder`。导入结束时释放远程临时副本。远程原图先落到临时文件，再交给 GD 生成图片样式。本地导入仍使用原来的真实路径。 |
| `ArchiveManager` | 远程压缩包先复制到临时目录，再交给 `ZipArchive` 或 7z。解压目录仍是 `private://xedc-import/`。失败时保留诊断，并只删除临时副本，不删除原 ZIP。本地压缩包仍直接使用真实路径。 |
| `ProtectedDownloader` | 受保护对象的键改由 `StorageUriResolver` 计算。运行时未开启时仍返回 503，不给本地私有文件签发对象地址。 |
| `FilePathGuard`、`DuplicateFileScanner`、`UnusedFileScanner`、`FileReferenceAnalyzer`、`FileCleanupManager`、`FileQuarantineManager` | 区分本地、远程和临时文件。远程 URI 不进入 `realpath()`。 |

视频没有单独的转码服务。字段仍是公开协议，播放仍使用本地文件 URL。`RemoteFileReader::readRange()` 已经限制单次读取不超过 1MB，留给后面的 Range 播放使用。本阶段没有改视频字段，也没有把“视频私有”从策略配置变成实际协议。

## 4. 验证

命令：

```bash
sudo -u www-data vendor/bin/drush cr
sudo -u www-data vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/storage-adapter-self-test.php
```

自测结果全部通过。检查了这些事实：

- 临时目录是 `/tmp/xedc-storage`，位于 Web 根目录外，文件权限 `0600`。
- 穿越到 `/etc/passwd` 的删除被拒绝，该文件内容不变。
- `file:///etc/passwd` 不能被物化。
- 诊断文件里没有测试密钥、`X-Amz-Signature` 和 URL。
- `s3://` 被标为远程，`temporary://` 被标为临时，当前 `public://` 仍是本地。
- `public://styles/...` 在接管关闭时仍是图片样式，不是远程对象。
- 临时文件不会被 `describe()` 当成可清理内容。
- 远程 URI 和临时 URI 的本地清理被拒绝。
- 没有对应远程实体的普通本地 URI 不会被“并存保护”误伤。
- 没有文件实体时，对象删除被拒绝。
- 非法对象键的写回失败后，本地源文件还在。
- `RemoteFileReader` 拒绝把对象写到临时目录以外，`/etc/passwd` 不变。
- 图片流水线和压缩包管理器都已接上适配层。
- 运行时、`s3fs.use_s3_for_public`、`s3fs.use_s3_for_private` 仍是关闭。
- `public://` 的流类仍是 `PublicStream`。
- 已有文件 `public://2026-11/IMG_2771.png` 仍在本地磁盘。

另外用一张 8×8 的临时 PNG 调用了 `ImagePipeline::validate()`，结果是 `image/png`，8×8。这张图在临时目录里创建，验证后删除，没有进入文件表。

自测结束时 `/tmp/xedc-storage` 是空目录。自测没有上传到 R2，没有改生产文件表。

## 5. 现在还不能做的事

不要打开下面三个开关：

- `STORAGE_I_UNDERSTAND_TAKEOVER`
- `STORAGE_PUBLIC_TAKEOVER`
- `STORAGE_PRIVATE_TAKEOVER`

适配层只保证远程 URI 出现时，图片、ZIP 和清理不会误用本地真实路径。它没有把新上传改道到 R2。CDN 改写也仍然关闭，避免把还在本地的文件地址换成 `https://cdn.gitxx.xyz`。

后面的阶段才处理这些事：

1. 用测试图验证远程原图、WebP、AVIF 和 Drupal 图片样式写回。
2. 确认视频不转码、URL 可播放，以及 Range 或播放器访问方式。视频私有必须落到字段协议上，不能只留在策略配置里。
3. ZIP 仍在本地解压；处理成功后再写入 R2。失败不删原 ZIP，部分失败可以重试。
4. 本地旧文件和远程新文件并存时，清理任务继续跳过双方。对象校验使用元数据或 SHA-256，而不是 `realpath()`。
5. 公开访问和 CDN 要等新文件确实在 R2 上之后再打开。

旧文件迁移不在本阶段。128 个公开文件和 9 个私有文件仍留在原来的磁盘路径。这个数量来自上一份上传排查，本阶段没有重新统计，也没有改动它们。
