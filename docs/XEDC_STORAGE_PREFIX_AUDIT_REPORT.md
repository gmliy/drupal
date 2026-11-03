# 对象存储路径配置只读检查

日期：2026-11-03  
站点：https://gitxx.xyz  
范围：只读。没有改配置、代码、`active.json`、S3FS 数据库、`config/sync`，没有上传、移动或删除对象，没有切换存储，没有执行 `drush cr`。

## 结论

保存后的 Public Prefix 已经生效，现有公开原图仍在 `s3fs-public/`。两边不一致。

新渲染出来的公开地址指向 `public/`，这批对象不在那里，所以会 404。已经缓存的页面还在输出旧的 `s3fs-public/` 地址，这些地址里本来就在 R2 上的原图仍然可以访问。S3FS 模块自己的数据库配置没有被改掉，仍是 `s3fs-public` / `s3fs-private`；真正改变读取路径的是对象存储管理器写进运行时文件后，由启动过程覆盖到内存里的值。

不需要迁移那 133 个现有对象。要恢复访问，应先把对象存储管理器的公共文件夹改回 `s3fs-public` 并保存。不要先改 S3FS 模块配置。

## 1. 对象存储管理器

当前供应商是 `silly`，类型 `cloudflare_r2`。

| 来源 | Public | Private | Root |
| --- | --- | --- | --- |
| 活动配置 `xedc_core.storage` | `public` | `private` | 空 |
| 运行时文件 `private/xedc-storage-keys/silly/runtime.json` | `public` | `private` | 空 |
| `config/sync/xedc_core.storage.yml` | `s3fs-public` | `s3fs-private` | 空 |

保存的值不带斜杠。对象键使用时会变成 `public/` 和 `private/`。`runtime.json` 的修改时间是 2026-11-03T05:02:42Z，和这次保存一致。

保存供应商表单时会调用 `writeMeta()`，把文件夹写入 `runtime.json`。PHP 每次请求都读这个文件，所以不必执行 `drush cr` 就会生效。环境变量没有设置 `STORAGE_PUBLIC_FOLDER`、`STORAGE_PRIVATE_FOLDER`、`STORAGE_ROOT_FOLDER`，没有第二套文件夹环境变量盖住它。`config/sync` 仍是旧值，但运行时不读这份同步配置。

`runtime.json` 里的 `cdn_enabled` 是 false。这是保存元数据时写入的固定值。实际 CDN 仍由 `active.json` 和 PHP-FPM 的 `STORAGE_CDN_ENABLED=1` 打开，当前有效状态是开。

## 2. S3 File System

数据库里的 `s3fs.settings` 没有被这次保存改掉：

| 项 | 数据库原值 | 本次请求内存中的有效值 |
| --- | --- | --- |
| Root Folder | 空 | 空 |
| Public Folder | `s3fs-public` | `public` |
| Private Folder | `s3fs-private` | `private` |
| 使用 S3 存放公开文件 | 关 | 开，来自运行时接管 |
| 使用 S3 存放私有文件 | 关 | 关 |

`config/sync/s3fs.settings.yml` 也仍是 `s3fs-public` / `s3fs-private`。

冲突在于：S3FS 模块配置仍指向旧前缀，对象存储管理器在启动时用 `runtime.json` 覆盖内存中的 `s3fs.settings`。Drupal 实际读写公开对象时用的是覆盖后的 `public`，不是数据库里的 `s3fs-public`。两边没有互相写回。

## 3. R2 对象

只做了 `ListObjectsV2`，没有改对象。

| 前缀 | 对象数 | 总字节 |
| --- | --- | --- |
| `s3fs-public/` | 133 | 79802162 |
| `public/` | 1 | 2813838 |
| `s3fs-private/` | 0 | 0 |
| `private/` | 0 | 0 |

`public/` 下唯一的对象是 `public/2026-11/IMG_6077.png`，2813838 字节。对应 `file_managed` fid 799，`public://2026-11/IMG_6077.png`，status 为 0（临时文件），创建于 2026-11-03T05:17:30Z，晚于前缀保存。它不在 `s3fs-public/`，本地磁盘也没有这份文件。

## 4. 运行状态

`private/xedc-storage-runtime/active.json` 仍是 Cloudflare R2，文件修改时间 2026-11-03T00:08:29Z。这个文件不保存文件夹前缀。

| 项 | 值 |
| --- | --- |
| public_mode | `cloudflare_r2` |
| public_provider | `silly` |
| cdn_enabled | true |
| cdn_base | `https://cdn.gitxx.xyz` |
| writes_paused | false |
| status | active |
| 公开包装器 | `XedcPublicS3fsStream` |
| 私有包装器 | `PrivateStream` |
| 公开接管 | 开 |
| 私有接管 | 关 |

PHP-FPM 池只设置了 `XEDC_STORAGE_RUNTIME=1`、`STORAGE_I_UNDERSTAND_TAKEOVER=1`、`STORAGE_PUBLIC_TAKEOVER=1`、`STORAGE_PRIVATE_TAKEOVER=0`、`STORAGE_CDN_ENABLED=1`。没有文件夹变量，也没有密钥环境变量。

Cron 每 15 分钟用同一组五个变量执行 `drush cron`。不带这组变量直接运行的 Drush 也会读到 `active.json`，公共文件夹同样是 `public`，包装器与上面一致。

三处的文件夹都来自 `runtime.json`。CDN 开关来自 `active.json` 和上述环境变量。凭证来自管理配置文件，本报告不记录密钥。

## 5. 生产访问

没有请求图片样式，没有上传文件。

| 文件 | Drupal 现在生成的 CDN 路径 | 该路径 | 旧路径 `s3fs-public/` | Drupal 直接读取 |
| --- | --- | --- | --- | --- |
| `public://2026-11/IMG_3026.jpeg`，fid 797，628587 字节，永久文件，不在本地 | `/public/2026-11/IMG_3026.jpeg` | 404 | 206，`image/jpeg`，总长度 628587 | 打不开。元数据仍有记录，对象不在新前缀 |
| `public://2026-11/IMG_6077.png`，fid 799，临时文件 | `/public/2026-11/IMG_6077.png` | 206，`image/png`，总长度 2813838 | 404 | 对象只在新前缀 |
| `public://2026-11/IMG_2714_0.png`，fid 793，4391633 字节 | 会指向 `/public/2026-11/IMG_2714_0.png` | 404 | 404 | 能从本地磁盘读到 PNG。两个 R2 前缀都没有它 |

公开内容页 `/cosplay/cos/531` 返回 200。源站页面缓存是 HIT，`Cache-Control: max-age=86400, public`。HTML 里 6 个 CDN 原图仍是 `https://cdn.gitxx.xyz/s3fs-public/...`，其中包含 `IMG_3026.jpeg`。这是保存前缀之前渲染并缓存的地址，所以现在还能打开。缓存失效后重新渲染，会改成 `public/`。

私有文件没有走到 CDN。抽样生成的地址在 `/system/files/` 下。9 条 `private://` 管理记录在本地私有目录中都没有对应文件；两个私有 R2 前缀也都是空的。这不是这次前缀修改搬动的结果。

## 6. 七个问题

1. **当前读取公开文件使用哪个 Prefix？**  
   `public`。对象键形如 `public/2026-11/IMG_3026.jpeg`，CDN 主机仍是 `cdn.gitxx.xyz`。

2. **现有 R2 原图实际在哪个 Prefix？**  
   在 `s3fs-public/`，133 个对象，79802162 字节。`public/` 里只有保存之后新出现的临时文件 `IMG_6077.png`。

3. **保存 `public/` 之后，现有图片是否路径不匹配？**  
   是。旧原图的新地址返回 404，Drupal 也不能再打开只存在于 `s3fs-public/` 的 `IMG_3026.jpeg`。只有尚未过期的页面缓存还在使用旧地址。`IMG_2714_0.png` 两个前缀都没有，它本来就只在本地，不能算进这 133 个 R2 对象。

4. **S3FS Public Folder 是否需要调整？**  
   不需要。数据库和 `config/sync` 里已经是 `s3fs-public`。需要改回的是对象存储管理器覆盖上去的有效值。

5. **Private Prefix 是否保留 `private/`？**  
   当前私有读取不使用 R2。私有接管关闭，包装器是本地 `PrivateStream`，`private/` 和 `s3fs-private/` 都没有对象。保留 `private/` 不会改变现有私有文件的读取，也不要把它当成私有文件的当前位置。现在不需要为私有文件迁移对象。

6. **是否需要恢复 `s3fs-public/`？**  
   需要恢复对象存储管理器中的公共文件夹。恢复后，现有 133 个对象不用迁移。不要把这 133 个对象复制或移动到 `public/`。

7. **应该先调整哪里？**  
   先在对象存储管理器把公共文件夹改回 `s3fs-public` 并保存。保存会重写 `runtime.json`，下一次请求就会按旧前缀读取。不要先改 S3FS 模块配置，也不要执行对象迁移。  
   临时文件 fid 799 已经写在 `public/2026-11/IMG_6077.png`。改回之后，这个临时对象和新的公开前缀不再一致。它不是已发布原图。本轮没有删除它。改回前缀之前，不要对它做清理或删除。

## 安全核对

本轮没有修改 `active.json`、S3FS 配置、活动配置、`config/sync`、代码或数据库结构，没有对 R2 做除 List 和读取以外的操作，没有切换 Local / R2，没有执行 `drush cr` 或清空 Redis。
