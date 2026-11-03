# 对象存储切换修复与验收

日期：2026-11-03  
站点：https://gitxx.xyz  
页面：`/admin/config/media/object-storage/runtime`  
判定：**PASS**，附带未实切换的警告。没有执行生产存储切换。

## 1. 原始错误的根本原因

后台点击「检查文件一致性」时，Nginx 记录的异常是：

`Typed property Drupal\xedc_core\Form\StorageSwitchForm::$switcher must not be accessed before initialization`

发生在 `StorageSwitchForm.php` 的 `inspect()`。表单第一次打开时由容器创建，`$switcher` 有值。Drupal 把表单对象放进表单缓存。提交时反序列化这个对象，父类 `DependencySerializationTrait::__sleep()` 只能看见 `protected` 属性。原来的 `$switcher` 是 `private`，而且 `StorageSwitchManager` 不是容器服务，缓存恢复后属性仍是未初始化状态。访问它就变成整页错误。检查逻辑本身还没有开始跑。

修法是把管理器注册为 `xedc_core.storage_switch`，表单改为注入这个服务，并把属性改成 `protected`。缓存恢复时会按服务 ID 取回新实例。隔离测试里序列化再反序列化后，属性仍是 `StorageSwitchManager`。

第二个会让切换走错位置的问题是：`StorageSwitchManager` 把对象前缀写死成 `s3fs-public/`。当前生效前缀是 `public/`。若在修复前缀之前执行切换，检查会把现有对象看成缺失，并可能往旧前缀复制。这次改为读取 `StorageUriResolver` 的当前文件夹。

## 2. 当前架构（只核对，未改）

| 项 | 实际值 |
| --- | --- |
| 运行模式 | `cloudflare_r2` |
| Provider | `cloudflare_r2` |
| Drupal 配置 ID | `silly` |
| Bucket | `dclili` |
| Region | `auto` |
| 公开前缀 | `public/` |
| 私有前缀 | `private/` |
| CDN | `https://cdn.gitxx.xyz` |
| 公开包装器 | `XedcPublicS3fsStream` |
| 私有包装器 | `PrivateStream` |
| 运行状态 | `private/xedc-storage-runtime/active.json` |
| 文件夹来源 | `private/xedc-storage-keys/silly/runtime.json`，由 `BootGuard` 写入 Settings |

Drupal URI、本地磁盘路径、R2 对象键和 CDN URL 分开处理。`public://2026-11/a.webp` 的磁盘路径是公开文件目录下的 `2026-11/a.webp`，对象键是 `public/2026-11/a.webp`，CDN 是 `https://cdn.gitxx.xyz/public/2026-11/a.webp`。检查本地文件时不调用 `public://` 流包装器，避免把 R2 包装器当成磁盘。相对路径里如果带 `://`，会被拒绝。

图片样式仍由本地 Drupal 生成。`public://styles/...` 不进入这次公开原件清单。现有样式 `https://gitxx.xyz/sites/default/files/styles/xa_cover/public/2026-10/IMG_3009.jpeg.webp` 返回 200。`https://cdn.gitxx.xyz/public/styles/thumbnail/public/example.jpg` 返回 404。

私有文件不走公开 CDN。`PublicUrl::cdnUrl('private://...')` 为空。`https://cdn.gitxx.xyz/private/not-a-real-secret.txt` 返回 403。这次公开切换不迁移 `private://` 记录。页面上的私有安全结果是「通过」，并会列出本地缺失数量。

旧前缀 `s3fs-public/` 这次列出 132 个非样式对象。它们只在「当前前缀没有、本地也没有」时作为下载来源。不会把旧对象复制到 `public/`，也不会删除。没有发现 `public/public/` 这种由代码再加一层前缀的写入计划。

## 3. 修改文件

| 文件 | 修改 |
| --- | --- |
| `web/modules/custom/xedc_core/src/Form/StorageSwitchForm.php` | 注入切换服务。页面分成选择、检查、结果、确认执行、取消。检查未通过、令牌缺失或目标不一致时不渲染执行按钮。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchManager.php` | 用当前 `public/` 前缀做清单、大小和复制计划。令牌绑定目标模式和文件摘要，15 分钟过期。失败时不把对象清单当成空。 |
| `web/modules/custom/xedc_core/xedc_core.services.yml` | 新增 `xedc_core.storage_switch`。原有的图片优化装饰服务保留。 |
| `web/modules/custom/xedc_core/src/Ops/Storage/MigrationExecutor.php` | `probeOne()` 改为使用当前公共/私有文件夹，不再要求 `s3fs-public/`。没有对生产迁移任务调用它。 |
| `web/modules/custom/xedc_core/templates/xedc-core-storage.html.twig` | 迁移页说明改为「当前公共文件夹或私有文件夹」。 |
| `web/modules/custom/xedc_core/xedc_core.libraries.yml` | 新增 `xedc_core/storage_switch`。 |
| `web/modules/custom/xedc_core/js/storage-switch.js` | 提交后禁用本表单按钮，避免连点。服务端锁仍然是真正的互斥。 |
| `web/modules/custom/xedc_core/css/ops.css` | 只给切换表单加步骤和结果颜色。 |
| `web/modules/custom/xedc_core/tests/manual/storage-switch-self-test.php` | 隔离用例。会读取生产清单，不切换。 |

没有改 Drupal 核心、第三方模块、`config/sync`、Bucket、Endpoint、密钥、CDN 域名或前缀配置。没有改数据库结构。

## 4. 后台流程

1. 选择「本地存储」或「Cloudflare R2」。页面同时显示当前运行模式。
2. 点击「检查文件一致性」。这一步只读，并写入 15 分钟检查令牌。
3. 结果表来自实际检查：当前模式、目标模式、本地目录、文件数量、缺失文件、大小、哈希、读取、权限、私有安全和切换条件。
4. 只有检查通过，且页面上的目标仍是这次检查的目标，才显示确认文字和「确认并执行切换」。
5. 执行前再次核对确认文字、令牌、目标、摘要、锁和权限。复制成功并用 SHA-256 核对后，才更新 `active.json`。
6. 「重新检查」作废旧令牌。「取消操作」删除令牌，不复制、不切换。

哈希在检查阶段固定显示「未执行」。全量下载约一百个对象做 SHA-256 不在这次只读检查里。执行切换时，只对实际复制的文件做 SHA-256。大小不一致会禁止切换。

## 5. 双向切换在代码里怎么走

当前模式是 Cloudflare R2。

**R2 → 本地：** 公开原件以 `file_managed` 的 `public://` 为准。本地缺失、当前 `public/` 上有对象时，执行阶段会下载到公开文件目录。当前前缀没有而 `s3fs-public/` 有时，才从旧键下载。下载不删除 R2 对象。大小不一致或两边都没有则停止，不改运行模式。

**本地 → R2：** 当前前缀缺少对象且本地可读时，执行阶段上传到 `public/{相对路径}`，不上传到 `s3fs-public/`，不加 `public/public/`。对象只存在于旧前缀、本地没有副本时，禁止切换，避免在两个前缀之间自动搬迁。站点现在已经是 Cloudflare R2，所以选择 R2 时检查会完成，但切换条件是「禁止执行」。

复制失败或哈希不一致时恢复原来的运行状态，不删除源文件。已有非空本地文件不会被下载覆盖。上传遇到内容不同的现有对象时，现有写入器会拒绝覆盖。

## 6. 这次读到的生产清单

检查以 www-data 运行，没有写 `active.json`。文件哈希在检查前后都是 `d03cc9eaa125d934c65c3c57463abceb67094f60cdc30f7783df37ddf2ef3aa6`。模式仍是 `cloudflare_r2` / `silly` / `active` / 版本 1。

| 项 | 结果 |
| --- | --- |
| 公开原件 | 147 |
| 当前前缀非样式对象 | 147 |
| 旧前缀非样式对象 | 132 |
| 两边都没有 | 0 |
| 大小不一致 | 0 |
| 本地不可读 | 0 |
| 本地目录、权限、私有安全 | 通过 |
| 哈希 | 未执行 |

切到本地时会下载这 7 个，来源都是 `public/`：

- `public/2026-11/1ae28ce6682d450469903ba287aa9c28.png`
- `public/2026-11/5bd2152b13562eac4e83c1b8e63fa58f.jpeg`
- `public/2026-11/IMG_2452.jpeg`
- `public/2026-11/IMG_3026.jpeg`
- `public/2026-11/IMG_3480.png`
- `public/2026-11/IMG_6077.png`
- `public/2026-11/f383ceca5596106baf315d73e1777047.png`

若将来从本地切回 R2，会上传这 7 个本地文件：

- `public/xedc-playback/801.mp4`
- `public/xedc-playback/802.mp4`
- `public/xedc-playback/86.mp4`
- `public/xedc-samples/broken.mp4`
- `public/xedc-samples/land-h264.mp4`
- `public/xedc-samples/land-hevc.mp4`
- `public/xedc-samples/port-silent.mp4`

这次没有下载，也没有上传。

## 7. 测试

| 用例 | 结果 |
| --- | --- |
| PHP 语法 | PASS |
| `drush cr` 容器重建 | PASS |
| 表单缓存反序列化后服务仍在 | PASS |
| 本地目标检查、R2 目标检查 | PASS，www-data 实读清单 |
| 目录不存在、文件两边都没有、大小不一致、不可读、双重前缀 | PASS，隔离数据 |
| 令牌过期、目标不一致、摘要变化、空令牌 | PASS |
| 第二进程抢锁 | PASS，临时目录 |
| 错误确认文字和无效令牌调用 `execute()` | PASS，运行文件未变 |
| 匿名用户权限和 HTTP 403 | PASS |
| 模式 000 文件 www-data 不可读 | PASS |
| 私有 URI 不生成 CDN；私有探测 URL 403 | PASS |
| 样式 URL 留在本站；现有样式 200；CDN 样式路径 404 | PASS |
| 已有公开原图 CDN | PASS，`IMG_3026.jpeg` 200 |
| 管理员浏览器：本地检查后出现「确认并执行切换」 | PASS，未点击 |
| 管理员浏览器：R2 目标显示禁止执行，没有执行按钮 | PASS |
| 取消操作 | PASS，检查令牌已删除 |
| 生产切换 | 未做 |
| 执行失败后的生产回滚 | NOT TESTED。隔离测试只证明失败的目标目录不会删掉源文件 |
| 浏览器里连点「确认并执行切换」 | NOT TESTED。没有点击执行按钮 |
| 全量 SHA-256 | NOT TESTED，页面明确显示未执行 |
| `MigrationExecutor::probeOne()` 对真实迁移任务 | NOT TESTED |

自测命令：`drush php:script web/modules/custom/xedc_core/tests/manual/storage-switch-self-test.php`，结果 `PASS 0`。

截图：

- `docs/storage-switch-fix/runtime-before.png`
- `docs/storage-switch-fix/runtime-local-check.png`
- `docs/storage-switch-fix/runtime-local-check-mobile.png`
- `docs/storage-switch-fix/runtime-r2-check.png`

## 8. 明确没有做的事

- 没有把生产模式切到本地，也没有在 R2 与本地之间来回切换。
- 没有上传、删除、移动或复制 R2 对象。清单使用 `ListObjectsV2`。
- 没有改 Bucket、Endpoint、Access Key、Secret Key、CDN 域名或前缀配置。
- 没有改 `config/sync`，没有改数据库结构，没有删除数据库数据。检查令牌写入 State 后已删除。
- 没有 `git commit` 或 `git push`。

## 9. 回滚

备份在 `/root/xedc-storage-switch-fix-backup/`。其中的 `xedc_core.services.yml` 是加入切换服务之前的工作区副本，仍保留图片优化装饰服务。

回滚时恢复这些备份，并删除新增的 `js/storage-switch.js` 与 `tests/manual/storage-switch-self-test.php`，然后执行 `drush cr`。不要改 `private/xedc-storage-runtime/active.json`，这次没有写它。

## 10. 生产切换前还要做的事

1. 再点一次「检查文件一致性」，确认 7 个待下载和 7 个待上传仍是预期文件。样本视频和 `broken.mp4` 不应在未确认时上传到公开 CDN。
2. 若需要全量哈希，应单独安排只读核对，不要把「未执行」当成已经逐个算过 SHA-256。
3. 确认维护窗口、`www-data` 对公开目录和 `xedc-storage-runtime` 仍可写，以及没有另一人持有切换锁。
4. 执行时输入「切换公开存储」。只在复制和 SHA-256 都成功后，运行模式才会变。
5. 不要删除 `s3fs-public/` 里的旧对象。失败时先看运行状态是否仍是原来的 `cloudflare_r2`。
