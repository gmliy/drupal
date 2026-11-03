# 对象存储路径配置检查：任务输出

日期：2026-11-03  
站点：https://gitxx.xyz  
详细证据：`docs/XEDC_STORAGE_PREFIX_AUDIT_REPORT.md`

只读检查。没有改配置、代码、`active.json` 或 S3FS 数据库，没有迁移或删除 R2 对象，没有切换存储，没有执行 `drush cr`。

## 结论

保存后的 Public Prefix 已经生效，现有公开原图仍在 `s3fs-public/`。两边不一致。

新渲染的公开地址指向 `public/`，对象不在那里，会 404。尚未过期的页面缓存仍输出旧的 `s3fs-public/` 地址，这些地址上本来就在 R2 的原图还可以访问。

不需要迁移现有 133 个对象。应先把对象存储管理器的公共文件夹改回 `s3fs-public` 并保存。不要先改 S3FS 模块配置。

## 配置现在各是什么

当前供应商是 `silly`，类型 `cloudflare_r2`。`active.json` 仍是 Cloudflare R2，公开包装器是 `XedcPublicS3fsStream`，私有包装器是 `PrivateStream`。公开接管开，私有接管关，CDN 开。

| 来源 | Public | Private | Root |
| --- | --- | --- | --- |
| 对象存储管理器活动配置 | `public` | `private` | 空 |
| `runtime.json`，2026-11-03T05:02:42Z | `public` | `private` | 空 |
| S3FS 数据库 `s3fs.settings` | `s3fs-public` | `s3fs-private` | 空 |
| `config/sync` | 管理器仍是 `s3fs-public` / `s3fs-private`；S3FS 同步文件也是这两个值 | | |

保存的值不带斜杠，对象键会变成 `public/` 和 `private/`。

PHP-FPM、Cron 和 Drush 都没有 `STORAGE_PUBLIC_FOLDER` 一类环境变量。它们每次从 `runtime.json` 读取文件夹，并在内存里覆盖 S3FS 配置。数据库里的 S3FS Public Folder 没有被改掉，所以模块配置本身不用调整。`config/sync` 运行时不读取。

## R2 上实际有什么

只做了 List，没有改对象。

| 前缀 | 对象数 | 总字节 |
| --- | --- | --- |
| `s3fs-public/` | 133 | 79802162 |
| `public/` | 1 | 2813838 |
| `s3fs-private/` | 0 | 0 |
| `private/` | 0 | 0 |

`public/` 里唯一的对象是临时文件 `public/2026-11/IMG_6077.png`，fid 799，status 0，创建于 2026-11-03T05:17:30Z。它不是已发布原图。

## 现有图片是否还能访问

| 文件 | 新 CDN 路径 `public/` | 旧 CDN 路径 `s3fs-public/` | Drupal 读取 |
| --- | --- | --- | --- |
| `IMG_3026.jpeg`，永久原图，只在 R2 | 404 | 206，`image/jpeg` | 打不开 |
| `IMG_6077.png`，临时文件 | 206，`image/png` | 404 | 只在新前缀 |
| `IMG_2714_0.png` | 404 | 404 | 本地磁盘有这份 PNG，两个 R2 前缀都没有 |

`/cosplay/cos/531` 的页面缓存仍是 HIT，`max-age=86400`。HTML 里的 6 个 CDN 原图还是 `s3fs-public/`。缓存失效后重新渲染会改成 `public/`。

私有文件不走 CDN，地址在 `/system/files/`。`private/` 和 `s3fs-private/` 都是空的。9 条 `private://` 记录在本地私有目录也没有对应文件。这次前缀修改没有搬动它们。

## 应该怎么处理

1. 当前公开读取前缀是 `public`。
2. 现有 R2 原图在 `s3fs-public/`。
3. 保存 `public/` 之后，现有图片路径不匹配。
4. S3FS Public Folder 不用改，它已经是 `s3fs-public`。
5. Private Prefix 不参与当前 R2 读取。保留 `private/` 不会改变本地私有文件的访问，也不要把它当成私有文件的位置。现在不用为私有文件迁移。
6. 需要把对象存储管理器的公共文件夹恢复为 `s3fs-public`。不要迁移那 133 个对象。
7. 先改对象存储管理器并保存，让 `runtime.json` 回到 `s3fs-public`。不要先改 S3FS 模块配置。改回之前不要清理或删除 fid 799，它已经写在 `public/`。
