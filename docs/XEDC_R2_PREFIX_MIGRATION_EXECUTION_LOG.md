# R2 Prefix 正式迁移执行记录

日期：2026-11-03  
站点：https://gitxx.xyz  
Bucket：dclili  
执行目录：`/root/drupal-backup/r2-prefix-migration/execution-20261103-061203/`

这次复制已经完成。逐对象记录在该目录的 `objects.jsonl`。130 条状态是 `copied`，5 条状态是 `uploaded`。源 SHA256 和目标 SHA256 全部相同。没有失败、没有内容冲突、没有源对象丢失。

## 时间

| 时间（UTC） | 事件 |
| --- | --- |
| 06:09:49 | 第一次复核。发现桶里多出一个 0 字节对象 `and/`。这次没有写入任何迁移对象。 |
| 06:10:32 | 第二次复核。130 个源对象的现场 SHA256 与备份一致。随后因四个 `_0` 原图与同名无 `_0` 对象字节相同而停止。当时还没有复制。 |
| 06:12:03 | 正式执行开始。`and/` 只记录、不迁移。同字节的不同文件名分别写入各自的 Key。 |
| 06:13:16 | 源对象复核通过，开始复制。 |
| 06:15:54 | 135 个目标对象写完并完成目标 SHA256。 |
| 06:18:30 | Drupal 文件系统复核通过。 |
| 06:23:34 | 135 个新 CDN 地址的完整内容哈希通过。130 个旧地址的范围请求通过。 |

## 复核

Drupal 11.4.11，PHP 8.5.12，数据库已连接。相关队列项数为 0。

当时有效配置：

| 项 | 值 |
| --- | --- |
| Provider | silly |
| 类型 | cloudflare_r2 |
| Bucket | dclili |
| Region | auto |
| Public Prefix | public |
| Private Prefix | private |
| Root | 空 |
| CDN | https://cdn.gitxx.xyz |
| 公开接管 | 开 |
| 私有接管 | 关 |
| 公开 Wrapper | XedcPublicS3fsStream |
| 私有 Wrapper | PrivateStream |

样例地址是 `https://cdn.gitxx.xyz/public/2026-11/IMG_3026.jpeg`。图片样式地址留在本地 `sites/default/files/styles/`。

活动数据库里的 S3FS `public_folder` / `private_folder` 在复制前已经是 `public` / `private`。`config/sync/s3fs.settings.yml` 仍是 `s3fs-public` / `s3fs-private`。这次没有改数据库配置，也没有改 `config/sync`。

130 个源对象在复制前重新下载并计算 SHA256，与备份清单一致，合计 79,752,252 字节。5 个本地原图重新计算 SHA256，与文件实体大小一致，合计 21,616,094 字节。fid 799 的对象哈希仍是 `4871b1ba46e219f3c66f447e2e9c5fe25acc71565754e5bf56ca64d0db75374c`，文件状态仍是临时。

`IMG_2714_0.png`、`IMG_2715_0.png`、`IMG_2716_0.png`、`IMG_2717_0.png` 与 R2 上不带 `_0` 的同名文件字节相同。两边都按各自的 Key 保留，没有用其中一个代替另一个。

未迁移：

| 对象 | 原因 |
| --- | --- |
| `public/2026-11/IMG_6077.png` | fid 799，原样保留 |
| `s3fs-public/.htaccess` | 非正式原图 |
| `s3fs-public/_xedc_image_style_probe/pixel.png` | 探测对象 |
| `s3fs-public/styles/.../IMG_3026.jpeg.webp` | 图片样式，不上传到新前缀 |
| `and/` | 0 字节，不属于清单，不自动加入 |
| `_xedc_storage_test/` 下 4 个对象 | 既有测试对象，不删除 |
| 私有文件 | 不迁移 |

## 写入

复制使用 R2 的服务端 CopyObject，保留 Content-Type。每个目标在写入后重新流式计算 SHA256，并与源哈希、大小、MIME 比较。本地原图用源文件上传，Cache-Control 采用当时源对象上的值。本地原图没有删除。File Entity 的 URI、状态和 file_usage 没有修改。

| 类别 | 数量 | 字节 | 结果 |
| --- | ---: | ---: | --- |
| 旧对象复制 | 130 | 79752252 | 全部 copied，SHA256 一致 |
| 本地原图上传 | 5 | 21616094 | 全部 uploaded，SHA256 一致 |
| 合计 | 135 | 101368346 | 失败 0，冲突 0，源丢失 0 |

五个上传：

| fid | 目标 | 字节 |
| --- | --- | ---: |
| 792 | `public/2026-11/IMG_2398.png` | 4590367 |
| 793 | `public/2026-11/IMG_2714_0.png` | 4391633 |
| 794 | `public/2026-11/IMG_2717_0.png` | 4105189 |
| 795 | `public/2026-11/IMG_2716_0.png` | 4249159 |
| 796 | `public/2026-11/IMG_2715_0.png` | 4279746 |

完整的源 Key、目标 Key、两端 SHA256、MIME、开始和完成时间在 `objects.jsonl`。

## 写入后的桶

| 前缀 | 对象 | 字节 |
| --- | ---: | ---: |
| `public/` | 136 | 104182184 |
| `s3fs-public/` | 133 | 79802162 |
| `_xedc_storage_test/` | 4 | 198 |
| `and/` | 1 | 0 |

`public/` 的 136 个对象是这次 135 个加上 fid 799。`public/styles/` 没有对象。`s3fs-public/` 的数量和总字节与迁移前相同。
