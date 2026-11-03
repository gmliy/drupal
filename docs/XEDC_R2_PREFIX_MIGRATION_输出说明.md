# R2 Prefix 统一迁移说明

日期：2026-11-03  
站点：https://gitxx.xyz  
Bucket：dclili

这份说明合并了审计和阶段报告。逐条对象清单在 `docs/XEDC_R2_PREFIX_MIGRATION_AUDIT.md`，SHA256 和迁移清单在 `/root/drupal-backup/r2-prefix-migration/`。

正式生产迁移还没有执行。没有复制正式对象，没有改生产 Prefix，没有改 File URI，没有做 Storage Switch，没有删除对象。最终验收不能标 PASS。

## 现在是什么状态

运行配置已经指向新目录，对象还在旧目录。

| 项目 | 实际值 |
| --- | --- |
| Provider | `silly`，类型 `cloudflare_r2`，Region `auto` |
| 有效 Public / Private / Root | `public` / `private` / 空 |
| CDN | `https://cdn.gitxx.xyz` |
| 公开包装器 | `XedcPublicS3fsStream` |
| 私有包装器 | `PrivateStream`，私有接管关闭 |
| S3FS 数据库 | 仍是 `s3fs-public` / `s3fs-private` |
| `config/sync` | 仍是旧 Prefix，运行时不读它 |

PHP-FPM、Cron 和 Drush 都没有文件夹环境变量，三者都从 `runtime.json` 读取，所以有效 Prefix 一致。`active.json` 只记录模式 `cloudflare_r2` 和 CDN，不记录文件夹。

图片样式继续留在本地 `sites/default/files/styles/`。本地有 824 个派生文件。本轮没有改样式代码，也没有把派生图上传到 R2。

## 重新清点的对象

| 前缀 | 对象 | 字节 | SHA256 |
| --- | ---: | ---: | --- |
| `s3fs-public/` | 133 | 79802162 | 全部完成，失败 0 |
| `public/` | 1 | 2813838 | 完成 |
| `s3fs-private/` | 0 | 0 | 无对象 |
| `private/` | 0 | 0 | 无对象 |

`file_managed` 153 条：公开 143，私有 10，永久 152，临时 1。没有 URI 含 `s3fs-public`，也没有 `public://public/`。复制对象后不必改 URI。

`IMG_3026.jpeg` 的旧地址 `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` 是 200，`image/jpeg`，628587 字节。新地址 `/public/2026-11/IMG_3026.jpeg` 是 404。`public/public/` 和 `s3fs-public/public/` 也是 404，没有被重写成真实对象。旧视频 Range 请求返回 206 `video/mp4`。

## 确认后要做的事

没有同路径、内容不同的冲突。fid 799 在旧目录没有同名对象。

| 动作 | 数量 | 字节 | 现在 |
| --- | ---: | ---: | --- |
| 把 `s3fs-public/{相对路径}` 复制到 `public/{相对路径}` | 130 | 79752252 | 暂缓 |
| 把只在本地的正式原图上传到 `public/` | 5 | 21616094 | 暂缓 |
| 保留 fid 799 `public/2026-11/IMG_6077.png` | 1 | 2813838 | 已在新目录，临时文件，无引用 |
| 不复制样式对象 | 1 | 49354 | 留在 `s3fs-public/styles/.../IMG_3026.jpeg.webp` |
| 不自动复制无实体对象 | 2 | 556 | `.htaccess` 和 `_xedc_image_style_probe/pixel.png` |

五个本地原图的磁盘大小与 File Entity 一致，R2 两个前缀都没有它们：

| fid | 文件 |
| --- | --- |
| 792 | `IMG_2398.png` |
| 793 | `IMG_2714_0.png` |
| 794 | `IMG_2717_0.png` |
| 795 | `IMG_2716_0.png` |
| 796 | `IMG_2715_0.png` |

R2 上不带 `_0` 的 `IMG_2714.png` 等是另一批对象，不能代替这五个文件。

复制清单里 fid 31 和 fid 37 没有 `file_usage`，但实体还在，计划复制，不删除。

私有文件不迁移。10 条 `private://` 记录都不在本地磁盘，也不在 R2。其中 9 条是历史导入 ZIP，另有 fid 805 的空 MP4 记录。不删除这些记录，不改 `/system/files/`。

`xedc-playback`、`xedc-samples` 和没有实体的 `media-icons` 不作为正式原件上传。

## 隔离测试

只在 `_xedc_storage_test/prefix-migration/` 里写了测试对象，没有删除。

- 第一次复制后，源和目标 SHA256 都是 `bfee097bde03f213792e4a830265920ecc0c9aca52f3d9f2bbfb83656ac484c5`，86 字节。
- 再次执行时，目标已存在且哈希相同，记为已存在。
- 目标内容不同时停止，不覆盖。
- 源不存在时不创建目标。
- 测试结束后，生产有效 Prefix 仍是 `public` / `private`，S3FS 数据库仍是 `s3fs-public` / `s3fs-private`。

## 备份和回滚

备份目录权限 700。里面有对象清单、SHA256、文件实体、本地候选、运行配置副本和迁移清单。旧对象都还在原 Key。

若正式复制中途失败：停在该对象，不覆盖不同内容，不改 URI。旧 CDN 地址继续指向 `s3fs-public/`。若复制完成后要改回旧读取方式，把 Storage Manager 的公共文件夹改回 `s3fs-public` 并保存即可，不删除 `public/` 里的新对象，也不删除本地文件。

## Cloudflare

没有 API 凭据，没有改 DNS、缓存、WAF、重写或 Workers。现有访问行为表明自定义域名按 URL 路径直接对应对象键。不要新建会删掉 `public/` 的重写，也不要清空整个 CDN。

需要人工核对控制台里是否还有只匹配 `s3fs-public` 或 `s3fs-private` 的规则。旧地址在切换完成前应继续可用。历史上拦截私有前缀的规则针对 `s3fs-private/`，不会自动覆盖 `private/`。本次不上传私有文件。

## 还没验证

迁移后的新 CDN 地址、页面缓存更新、会员页、后台媒体库和真实浏览器都还没有验收。当前 Prefix 已经是 `public`，所以未缓存的新页面会指向尚未复制的对象。确认后才会复制这 130 个对象，并上传这 5 个本地原图。
