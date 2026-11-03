# R2 Prefix 统一迁移：只读审计

日期：2026-11-03  
站点：https://gitxx.xyz  
Bucket：dclili  
状态：审计和备份已完成。正式对象没有复制，生产 Prefix 没有再改，Drupal 文件 URI 没有改。

历史数字已重新核对，不是沿用旧报告。完整 SHA256 在 `/root/drupal-backup/r2-prefix-migration/r2-objects.jsonl`。

## 当前实际状态

运行中的公开 Prefix 已经是 `public`，私有 Prefix 是 `private`，Root 为空。Provider 仍是 `silly`，Region 仍是 `auto`，模式仍是 `cloudflare_r2`，CDN 仍是 `https://cdn.gitxx.xyz`。

对象还在旧目录。公开原图的新地址因此 404，旧地址仍然 200。这是路径不一致，不是文件被删。

| 前缀 | 对象数 | 总字节 | SHA256 |
| --- | ---: | ---: | --- |
| `s3fs-public/` | 133 | 79802162 | 133 个全部读完并校验，失败 0 |
| `public/` | 1 | 2813838 | 1 个完成 |
| `s3fs-private/` | 0 | 0 | 无对象 |
| `private/` | 0 | 0 | 无对象 |

`file_managed` 共 153 条：公开 143，私有 10，永久 152，临时 1。临时文件只有 fid 799。没有 URI 含 `s3fs-public` 或 `public://public/`。

本地 `sites/default/files/styles/` 有 824 个派生文件、19361747 字节。它们不是原件。R2 上只有 1 个历史派生对象，不进入复制清单。

## 运行配置

| 来源 | Public | Private | Root |
| --- | --- | --- | --- |
| Storage Manager 活动配置 `silly` | `public` | `private` | 空 |
| `runtime.json` | `public` | `private` | 空 |
| 本次请求的有效 S3FS 配置 | `public` | `private` | 空 |
| S3FS 数据库原值 | `s3fs-public` | `s3fs-private` | 空 |
| `config/sync` | 仍是旧的 `s3fs-public` / `s3fs-private` | | |

公开包装器是 `XedcPublicS3fsStream`。私有包装器是 `PrivateStream`。公开接管开，私有接管关。

PHP-FPM、Cron、Drush 都没有 `STORAGE_PUBLIC_FOLDER`。三者都从 `runtime.json` 读文件夹。Cron 与 PHP-FPM 使用同一组五个运行开关，CDN 为开。`active.json` 只记录模式和 CDN，不记录文件夹。`active.json` 的 `public_mode` 是 `cloudflare_r2`。

CDN 生成结果是 `https://cdn.gitxx.xyz/` 加上当前 Prefix 和文件相对路径。没有双重 Prefix。

图片样式仍由现有 `ImageStyleFileSystem`（装饰优先级 -10）和 `ImageStyleRouteSubscriber` 留在本地。本轮没有改这些文件，也没有把样式上传到 R2。

## 不一致文件

没有同一相对路径在新旧 Prefix 下内容不同的冲突。`public/2026-11/IMG_6077.png` 在 `s3fs-public/` 没有同名对象。

| 处理 | 数量 | 说明 |
| --- | ---: | --- |
| 复制 `s3fs-public/{相对路径}` 到 `public/{相对路径}` | 130 | 有永久 File Entity，字节与对象一致。尚未执行 |
| 从本地上传到 `public/` | 5 | 正式原图，有 File Entity 和引用，R2 两个前缀都没有。尚未执行 |
| 保留已在 `public/` 的对象 | 1 | fid 799，临时，无引用 |
| 排除，不复制 | 1 | `s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp` |
| 保留在旧位置，不自动复制 | 2 | `.htaccess`、`_xedc_image_style_probe/pixel.png`，没有 File Entity |

本地正式原图，实体字节与磁盘一致：

| fid | URI | 字节 | 引用 |
| --- | --- | ---: | ---: |
| 792 | `public://2026-11/IMG_2398.png` | 4590367 | 1 |
| 793 | `public://2026-11/IMG_2714_0.png` | 4391633 | 1 |
| 794 | `public://2026-11/IMG_2717_0.png` | 4105189 | 1 |
| 795 | `public://2026-11/IMG_2716_0.png` | 4249159 | 1 |
| 796 | `public://2026-11/IMG_2715_0.png` | 4279746 | 1 |

R2 上另有不带 `_0` 的 `IMG_2714.png`、`IMG_2715.png`、`IMG_2716.png`、`IMG_2717.png`。它们是另一批对象，不能代替上面五个本地文件。

fid 799：`public://2026-11/IMG_6077.png`，status 0，2813838 字节，`image/png`，创建于 2026-11-03T05:17:30Z，无 `file_usage`。对象已在 `public/2026-11/IMG_6077.png`。不删除、不覆盖、不改成永久文件。

复制清单里有两条没有 `file_usage`，但 File Entity 仍在，所以保留并计划复制，不删除：

- fid 31 `gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-002.jpg`
- fid 37 `gallery/2026-10/quzhongfudeceshitu-002.jpg`

私有文件 10 条全部不在本地磁盘，也不在 R2。其中 9 条是历史导入 ZIP，fid 166、219、272、326、379、481、491、505、520。另有 fid 805 `private://xedc-samples/secret-hevc.mp4`，记录大小为 0。本次不迁移私有文件，不删除这些记录，不改 `/system/files/`。

另外 824 个本地样式、`xedc-playback`、`xedc-samples` 和没有实体的 `media-icons` 不作为正式原件上传。

## 访问核对

| URL | 结果 |
| --- | --- |
| `https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_3026.jpeg` | 200，`image/jpeg`，628587 字节 |
| `https://cdn.gitxx.xyz/public/2026-11/IMG_3026.jpeg` | 404 |
| `https://cdn.gitxx.xyz/public/public/2026-11/IMG_3026.jpeg` | 404 |
| `https://cdn.gitxx.xyz/s3fs-public/public/2026-11/IMG_3026.jpeg` | 404 |
| 旧视频 `s3fs-public/2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` Range `0-15` | 206，`video/mp4`，总长度 4627386 |

CDN 路径与对象键一致，没有把 `public/` 或 `s3fs-public/` 重写成另一层。页面缓存仍可能输出旧 URL。本轮没有清 CDN。

## 迁移风险

1. 有效 Prefix 已是 `public`，130 个旧对象还没复制。未缓存的新渲染会指向不存在的新键。
2. 五个本地原图如果只复制 R2 旧对象，仍不会出现在 `public/`。
3. 复制时如果目标已有不同内容，必须停。当前没有这种冲突。
4. 样式对象和探针对象不能当成原件复制到 `public/styles/` 或覆盖 fid 799。
5. 私有边缘规则历史上拦的是 `s3fs-private/`。`private/` 是另一个前缀。本次不把私有文件放上 R2。以后若要接管私有文件，必须先单独确认边缘拒绝，不能假设旧规则仍然有效。
6. `config/sync` 仍是旧 Prefix。运行时不读它。不能靠导入同步配置来完成本次迁移，也不能在这次改 `config/sync`。
7. File URI 本身不含桶内 Prefix，复制对象后不必改 `file_managed`。禁止只改 URI 而不复制字节。

因为 Prefix 和对象位置不一致，正式迁移不能在这份审计里直接宣布完成。下一步是按清单复制，而且要等明确确认后才对正式对象执行。

## 备份

目录 `/root/drupal-backup/r2-prefix-migration/` 权限 700，文件权限 600。

| 文件 | 内容 |
| --- | --- |
| `r2-objects.jsonl` | 134 个对象的 Key、MIME、字节、修改时间、SHA256 |
| `r2-summary.json` | 四个前缀的数量和字节 |
| `migration-before.json` | 迁移前统计。正式复制尚未发生 |
| `drupal-files.jsonl` | 153 条 URI、MIME、大小、状态、引用 |
| `local-files.jsonl` | 本地候选原件，不含样式 |
| `migration-manifest.jsonl` | 复制、上传、保留、排除决定 |
| `runtime-config.json` | 不含密钥的 Prefix 和包装器 |
| `active.json`、`runtime.json` | 运行状态副本，不含密钥 |
| `isolated-rollback-test.json` | 隔离测试结果 |

旧对象都还在原 Key。回滚不依赖删除新对象。

## 回滚策略

正式复制一旦开始，旧 Key 仍保留。

- 新对象写入失败或 SHA256 不一致：停止该对象，不覆盖目标，不改 URI。旧 CDN 地址继续指向 `s3fs-public/`。
- 目标已存在且哈希不同：停止并人工处理。隔离测试已证明不同内容不会被覆盖。
- 服务器中断或重复执行：按清单逐个重跑。目标哈希与源一致则记为已存在。
- Drupal URI 不计划修改。若复制后需要退回旧读取，把 Storage Manager 公共文件夹改回 `s3fs-public` 并保存，让 `runtime.json` 恢复。不删除 `public/` 里已经写好的对象，不删除本地文件。
- fid 799 不参与覆盖或删除。

隔离测试只写了 `_xedc_storage_test/prefix-migration/`。生产 Prefix 仍是 `public` / `private`，S3FS 数据库仍是 `s3fs-public` / `s3fs-private`。测试对象没有删除。

## 正式迁移尚未执行

待确认后才会做：

1. 复制 130 个对象，共 79752252 字节，保持相对路径。
2. 上传 5 个本地正式原图，共 21616094 字节。
3. 每个对象记录源、目标和 SHA256。不覆盖不同内容。不删除旧对象。
4. 不改 File URI，不改 fid 799，不迁移私有文件，不上传样式。

确认前不修改生产 Prefix，不迁移正式 R2 对象。

## 全部对象
| 对象 | 字节 | MIME | 修改时间 |
| --- | ---: | --- | --- |
| `public/2026-11/IMG_6077.png` | 2813838 | image/png | 2026-11-03T05:18:07Z |
| `s3fs-public/.htaccess` | 486 | application/octet-stream | 2026-11-02T23:00:03Z |
| `s3fs-public/2026-10/IMG_1090.png` | 4037606 | image/png | 2026-11-02T20:08:05Z |
| `s3fs-public/2026-10/IMG_1204.png` | 4922910 | image/png | 2026-11-02T20:08:01Z |
| `s3fs-public/2026-10/IMG_1468.jpeg` | 1722143 | image/jpeg | 2026-11-02T20:08:03Z |
| `s3fs-public/2026-10/IMG_3008.jpeg` | 1039999 | image/jpeg | 2026-11-02T20:07:59Z |
| `s3fs-public/2026-10/IMG_3009.jpeg` | 163198 | image/jpeg | 2026-11-02T20:07:58Z |
| `s3fs-public/2026-10/IMG_3224.webp` | 111884 | image/webp | 2026-11-02T20:09:18Z |
| `s3fs-public/2026-10/o0O7zibQArbnthjBIwaiPZMBEA2AcRJCcAPAO~tplv-dy-vqe2-v2_1440_1901_q80.webp` | 351788 | image/webp | 2026-11-02T20:08:15Z |
| `s3fs-public/2026-10/o0Q9MeqA3JSihEU2DEIdGuFFfAAEcwfsE5AA2g~tplv-dy-vqe2-v2_1440_1937_q80.webp` | 329480 | image/webp | 2026-11-02T20:08:14Z |
| `s3fs-public/2026-10/o8g5niBkeAEMiIAJTsiEGhCAEARwJ4AOV1ftJC~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | 132964 | image/webp | 2026-11-02T20:08:11Z |
| `s3fs-public/2026-10/oMQqAQDZAAAiA9wxgEFzqMNDwfkhfPTEAACCpI~tplv-dy-aweme-images-v2_1440_2560_q75.webp` | 39808 | image/webp | 2026-11-02T20:08:04Z |
| `s3fs-public/2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` | 4627386 | video/mp4 | 2026-11-02T20:09:17Z |
| `s3fs-public/2026-10/oQrhnAnB1CE4JoRiiAAgcAE5AEOsfVPwGeIJCJ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | 118246 | image/webp | 2026-11-02T20:08:10Z |
| `s3fs-public/2026-10/oY2BrhbAPEjOiBRAACOnAIO7nMPAa4zwLSict~tplv-dy-vqe2-v2_1440_1913_q80.webp` | 361090 | image/webp | 2026-11-02T20:08:13Z |
| `s3fs-public/2026-10/ogMDfABVsECEJieO15JJhAnAwE3sCGPIAA4giR~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | 126884 | image/webp | 2026-11-02T20:08:11Z |
| `s3fs-public/2026-10/okJREuiPAGnJFJWsEABAIhV4e51wECCAAgiOfQ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | 128898 | image/webp | 2026-11-02T20:08:12Z |
| `s3fs-public/2026-10/oo3X94ssqECAAAAmiCVFCBkEItDAjSA00gfyTe~tplv-dy-vqe2-v2_1440_2560_q75.webp` | 108828 | image/webp | 2026-11-02T20:08:07Z |
| `s3fs-public/2026-10/plel0QtzdBBA16i0w6fjpggSrVXB8c8c5IOZ7IXwP_EFYxKKyT_8jFphUD8zgyqvTe8QNyBHNK4VbYSWl1_v1rDOyl-d1eGjJWMzLyGwiDxyE8ozgVIVx2RXY1qkPJ3vkHolGA5tDI9vSm6iKtSvQoRLqxYHfdOqOpl1vIQUXI2TSmJ7bUGdc3EIZGRMQAir.mp4` | 2255011 | video/mp4 | 2026-11-02T20:08:02Z |
| `s3fs-public/2026-11/IMG_2714.png` | 4391633 | image/png | 2026-11-02T20:10:00Z |
| `s3fs-public/2026-11/IMG_2715.png` | 4279746 | image/png | 2026-11-02T20:10:01Z |
| `s3fs-public/2026-11/IMG_2716.png` | 4249159 | image/png | 2026-11-02T20:09:59Z |
| `s3fs-public/2026-11/IMG_2717.png` | 4105189 | image/png | 2026-11-02T20:09:58Z |
| `s3fs-public/2026-11/IMG_2771.png` | 3920503 | image/png | 2026-11-02T20:10:02Z |
| `s3fs-public/2026-11/IMG_2964.png` | 3851719 | image/png | 2026-11-02T20:09:57Z |
| `s3fs-public/2026-11/IMG_3026.jpeg` | 628587 | image/jpeg | 2026-11-02T23:27:30Z |
| `s3fs-public/2026-11/IMG_3480.png` | 3946033 | image/png | 2026-11-02T23:33:52Z |
| `s3fs-public/2026-11/n195-001.jpg` | 100356 | image/jpeg | 2026-11-02T20:09:19Z |
| `s3fs-public/2026-11/n195-002.jpg` | 116344 | image/jpeg | 2026-11-02T20:09:20Z |
| `s3fs-public/2026-11/n195-003.jpg` | 116966 | image/jpeg | 2026-11-02T20:09:21Z |
| `s3fs-public/2026-11/n195-004.jpg` | 116257 | image/jpeg | 2026-11-02T20:09:22Z |
| `s3fs-public/2026-11/n195-005.jpg` | 115647 | image/jpeg | 2026-11-02T20:09:22Z |
| `s3fs-public/2026-11/n195-006.jpg` | 141379 | image/jpeg | 2026-11-02T20:09:23Z |
| `s3fs-public/2026-11/n195-007.jpg` | 136263 | image/jpeg | 2026-11-02T20:09:24Z |
| `s3fs-public/2026-11/n195-008.jpg` | 191206 | image/jpeg | 2026-11-02T20:09:25Z |
| `s3fs-public/2026-11/n195-009.jpg` | 214484 | image/jpeg | 2026-11-02T20:09:26Z |
| `s3fs-public/2026-11/n195-010.jpg` | 180518 | image/jpeg | 2026-11-02T20:09:27Z |
| `s3fs-public/2026-11/n195-011.jpg` | 155500 | image/jpeg | 2026-11-02T20:09:28Z |
| `s3fs-public/2026-11/n195-012.jpg` | 152170 | image/jpeg | 2026-11-02T20:09:29Z |
| `s3fs-public/2026-11/n195-013.jpg` | 168079 | image/jpeg | 2026-11-02T20:09:30Z |
| `s3fs-public/2026-11/n195-014.jpg` | 146303 | image/jpeg | 2026-11-02T20:09:30Z |
| `s3fs-public/2026-11/n195-015.jpg` | 211887 | image/jpeg | 2026-11-02T20:09:31Z |
| `s3fs-public/2026-11/n195-016.jpg` | 201272 | image/jpeg | 2026-11-02T20:09:32Z |
| `s3fs-public/2026-11/n195-017.jpg` | 187501 | image/jpeg | 2026-11-02T20:09:33Z |
| `s3fs-public/2026-11/n195-018.jpg` | 210339 | image/jpeg | 2026-11-02T20:09:34Z |
| `s3fs-public/2026-11/n195-019.jpg` | 183427 | image/jpeg | 2026-11-02T20:09:35Z |
| `s3fs-public/2026-11/n195-020.jpg` | 201463 | image/jpeg | 2026-11-02T20:09:36Z |
| `s3fs-public/2026-11/n195-021.jpg` | 173002 | image/jpeg | 2026-11-02T20:09:37Z |
| `s3fs-public/2026-11/n195-022.jpg` | 150501 | image/jpeg | 2026-11-02T20:09:38Z |
| `s3fs-public/2026-11/n195-023.jpg` | 172388 | image/jpeg | 2026-11-02T20:09:39Z |
| `s3fs-public/2026-11/n195-024.jpg` | 203587 | image/jpeg | 2026-11-02T20:09:40Z |
| `s3fs-public/2026-11/n195-025.jpg` | 171057 | image/jpeg | 2026-11-02T20:09:41Z |
| `s3fs-public/2026-11/n195-026.jpg` | 175981 | image/jpeg | 2026-11-02T20:09:42Z |
| `s3fs-public/2026-11/n195-027.jpg` | 187544 | image/jpeg | 2026-11-02T20:09:43Z |
| `s3fs-public/2026-11/n195-028.jpg` | 182408 | image/jpeg | 2026-11-02T20:09:44Z |
| `s3fs-public/2026-11/n195-029.jpg` | 165848 | image/jpeg | 2026-11-02T20:09:45Z |
| `s3fs-public/2026-11/n195-030.jpg` | 144775 | image/jpeg | 2026-11-02T20:09:46Z |
| `s3fs-public/2026-11/n195-031.jpg` | 161231 | image/jpeg | 2026-11-02T20:09:47Z |
| `s3fs-public/2026-11/n195-032.jpg` | 151547 | image/jpeg | 2026-11-02T20:09:47Z |
| `s3fs-public/2026-11/n195-033.jpg` | 148017 | image/jpeg | 2026-11-02T20:09:48Z |
| `s3fs-public/2026-11/n195-034.jpg` | 182075 | image/jpeg | 2026-11-02T20:09:49Z |
| `s3fs-public/2026-11/n195-035.jpg` | 212245 | image/jpeg | 2026-11-02T20:09:50Z |
| `s3fs-public/2026-11/n195-036.jpg` | 163893 | image/jpeg | 2026-11-02T20:09:51Z |
| `s3fs-public/2026-11/n195-037.jpg` | 145095 | image/jpeg | 2026-11-02T20:09:52Z |
| `s3fs-public/2026-11/n195-038.jpg` | 177929 | image/jpeg | 2026-11-02T20:09:53Z |
| `s3fs-public/2026-11/n195-039.jpg` | 173945 | image/jpeg | 2026-11-02T20:09:54Z |
| `s3fs-public/2026-11/n195-040.jpg` | 202032 | image/jpeg | 2026-11-02T20:09:55Z |
| `s3fs-public/_xedc_image_style_probe/pixel.png` | 70 | image/png | 2026-11-02T23:58:18Z |
| `s3fs-public/comments/2026-10/IMG_2154_0.png` | 173184 | image/png | 2026-11-02T20:08:09Z |
| `s3fs-public/comments/2026-10/okJREuiPAGnJFJWsEABAIhV4e51wECCAAgiOfQ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | 72140 | image/webp | 2026-11-02T20:08:08Z |
| `s3fs-public/gallery/2026-10/ceshidierciquzhong-001.jpg` | 398627 | image/jpeg | 2026-11-02T20:08:24Z |
| `s3fs-public/gallery/2026-10/ceshidierciquzhong-003.jpg` | 394105 | image/jpeg | 2026-11-02T20:08:24Z |
| `s3fs-public/gallery/2026-10/ceshidierciquzhong-004.jpg` | 376458 | image/jpeg | 2026-11-02T20:08:25Z |
| `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-001.jpg` | 398627 | image/jpeg | 2026-11-02T20:08:17Z |
| `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-002.jpg` | 405135 | image/jpeg | 2026-11-02T20:08:18Z |
| `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-003.jpg` | 376458 | image/jpeg | 2026-11-02T20:08:16Z |
| `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-004.jpg` | 394105 | image/jpeg | 2026-11-02T20:08:19Z |
| `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-001.jpg` | 398627 | image/jpeg | 2026-11-02T20:08:20Z |
| `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-002.jpg` | 405135 | image/jpeg | 2026-11-02T20:08:20Z |
| `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-003.jpg` | 376458 | image/jpeg | 2026-11-02T20:08:22Z |
| `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-004.jpg` | 394105 | image/jpeg | 2026-11-02T20:08:22Z |
| `s3fs-public/gallery/2026-10/ceshitupiandaxiaobiaotideng-001.jpg` | 288726 | image/jpeg | 2026-11-02T20:09:12Z |
| `s3fs-public/gallery/2026-10/ceshitupiandaxiaowenjiangeshihetupiandaxiaowenjian-001.jpg` | 382152 | image/jpeg | 2026-11-02T20:09:11Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-001.jpg` | 316760 | image/jpeg | 2026-11-02T20:08:27Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-002.jpg` | 358135 | image/jpeg | 2026-11-02T20:08:28Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-003.jpg` | 287678 | image/jpeg | 2026-11-02T20:08:29Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-004.jpg` | 317816 | image/jpeg | 2026-11-02T20:08:30Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-005.jpg` | 322379 | image/jpeg | 2026-11-02T20:08:31Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-006.jpg` | 328219 | image/jpeg | 2026-11-02T20:08:32Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-007.jpg` | 330526 | image/jpeg | 2026-11-02T20:08:33Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-008.jpg` | 325460 | image/jpeg | 2026-11-02T20:08:34Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-009.jpg` | 325573 | image/jpeg | 2026-11-02T20:08:35Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-010.jpg` | 311111 | image/jpeg | 2026-11-02T20:08:36Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-011.jpg` | 316001 | image/jpeg | 2026-11-02T20:08:37Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-012.jpg` | 314666 | image/jpeg | 2026-11-02T20:08:37Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-013.jpg` | 302447 | image/jpeg | 2026-11-02T20:08:38Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-014.jpg` | 325207 | image/jpeg | 2026-11-02T20:08:39Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-015.jpg` | 281701 | image/jpeg | 2026-11-02T20:08:46Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-016.jpg` | 284361 | image/jpeg | 2026-11-02T20:08:45Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-017.jpg` | 293570 | image/jpeg | 2026-11-02T20:08:47Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-018.jpg` | 268425 | image/jpeg | 2026-11-02T20:08:48Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-019.jpg` | 308003 | image/jpeg | 2026-11-02T20:08:49Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-020.jpg` | 307578 | image/jpeg | 2026-11-02T20:08:50Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-021.jpg` | 307962 | image/jpeg | 2026-11-02T20:08:52Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-022.jpg` | 304174 | image/jpeg | 2026-11-02T20:08:51Z |
| `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-023.jpg` | 316518 | image/jpeg | 2026-11-02T20:08:52Z |
| `s3fs-public/gallery/2026-10/chengshudeaaaa-001.jpg` | 87996 | image/jpeg | 2026-11-02T20:09:13Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-001.jpg` | 310336 | image/jpeg | 2026-11-02T20:08:53Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-002.jpg` | 302170 | image/jpeg | 2026-11-02T20:08:54Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-003.jpg` | 350394 | image/jpeg | 2026-11-02T20:08:55Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-004.jpg` | 297065 | image/jpeg | 2026-11-02T20:08:56Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-028.jpg` | 346403 | image/jpeg | 2026-11-02T20:08:57Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-029.jpg` | 365859 | image/jpeg | 2026-11-02T20:08:59Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-030.jpg` | 310014 | image/jpeg | 2026-11-02T20:08:58Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-031.jpg` | 307735 | image/jpeg | 2026-11-02T20:09:00Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-032.jpg` | 353940 | image/jpeg | 2026-11-02T20:09:01Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-033.jpg` | 411745 | image/jpeg | 2026-11-02T20:09:02Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-034.jpg` | 409221 | image/jpeg | 2026-11-02T20:09:03Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-035.jpg` | 415302 | image/jpeg | 2026-11-02T20:09:04Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-036.jpg` | 371131 | image/jpeg | 2026-11-02T20:09:05Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-037.jpg` | 307372 | image/jpeg | 2026-11-02T20:09:06Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-038.jpg` | 326349 | image/jpeg | 2026-11-02T20:09:07Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-039.jpg` | 335300 | image/jpeg | 2026-11-02T20:09:07Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-040.jpg` | 307612 | image/jpeg | 2026-11-02T20:09:08Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-041.jpg` | 397470 | image/jpeg | 2026-11-02T20:09:09Z |
| `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-042.jpg` | 272414 | image/jpeg | 2026-11-02T20:09:10Z |
| `s3fs-public/gallery/2026-10/piyiercishangchuandian-001.jpg` | 201957 | image/jpeg | 2026-11-02T20:09:15Z |
| `s3fs-public/gallery/2026-10/piyiercishangchuandian-002.jpg` | 200651 | image/jpeg | 2026-11-02T20:09:14Z |
| `s3fs-public/gallery/2026-10/piyiercishangchuandian-003.jpg` | 296272 | image/jpeg | 2026-11-02T20:09:16Z |
| `s3fs-public/gallery/2026-10/quzhongfudeceshitu-002.jpg` | 405135 | image/jpeg | 2026-11-02T20:08:26Z |
| `s3fs-public/media-icons/generic/video.png` | 4156 | image/png | 2026-11-02T20:08:02Z |
| `s3fs-public/pictures/IMG_2664.jpeg` | 1604155 | image/jpeg | 2026-11-02T20:09:18Z |
| `s3fs-public/pictures/IMG_9619.jpeg` | 1422633 | image/jpeg | 2026-11-02T20:09:56Z |
| `s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp` | 49354 | image/webp | 2026-11-02T23:47:55Z |
