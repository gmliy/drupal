# R2 Prefix 统一迁移报告

日期：2026-11-03  
站点：https://gitxx.xyz  
Bucket：dclili  
Provider：silly  
CDN：https://cdn.gitxx.xyz

最终状态：**PARTIAL**

135 个正式公开对象已经在 `public/`，SHA256 与源一致，Drupal 能读到这些对象，新的 CDN 地址也返回同样的内容。真实浏览器没有跑，Cloudflare 控制台规则没有凭据可核对。这两项未验证，所以不能标 PASS。

执行细节：`docs/XEDC_R2_PREFIX_MIGRATION_EXECUTION_LOG.md`  
逐对象日志：`/root/drupal-backup/r2-prefix-migration/execution-20261103-061203/objects.jsonl`

## 1. 迁移结果

| 类别 | 数量 | 字节 | 结果 |
| --- | ---: | ---: | --- |
| `s3fs-public/` 复制到 `public/` | 130 | 79752252 | 成功，SHA256 不一致 0 |
| 本地正式原图上传 | 5 | 21616094 | 成功，SHA256 不一致 0 |
| 合计 | 135 | 101368346 | 失败 0，冲突 0，源丢失 0，目标丢失 0 |

复制前，130 个源对象和 5 个本地文件都重新计算了 SHA256，并与备份清单一致。复制或上传之后，每个目标又读回重算了一次。

fid 799 `public://2026-11/IMG_6077.png` 仍是临时文件，状态 0，大小 2813838。对象 `public/2026-11/IMG_6077.png` 哈希未变。没有删除、覆盖或改成永久文件。

`s3fs-public/` 仍是 133 个对象、79802162 字节。`.htaccess`、图片样式探测文件和那一个历史样式对象都还在旧前缀，没有复制到 `public/`。

`IMG_2714.png` 与 `IMG_2714_0.png`（以及 2715、2716、2717 这三对）字节相同，但分别位于各自的 Key。带 `_0` 的是本地上传，不带 `_0` 的是从旧前缀复制。

桶里另有一个 0 字节对象 `and/`。它不在迁移清单里，没有复制，也没有删除。

## 2. Drupal 文件

| 项 | 结果 |
| --- | --- |
| 文件实体 | 153。公开 143，私有 10，永久 152，临时 1 |
| URI | 没有 `s3fs-public`，没有 `public://public/` |
| 135 个清单实体的对象键 | 全部解析为对应的 `public/{相对路径}` |
| 引用和状态 | 没有修改 |
| 原图读取 | `IMG_3026.jpeg`、`IMG_3480.png` 本地不存在，经公开 Wrapper 读到的 SHA256 与新对象一致 |
| 本地仍在的原图 | `IMG_2398.png`、`IMG_2714_0.png` 和抽查的 MP4，Wrapper 读取结果与新对象一致 |
| 私有文件 | 9 条历史 ZIP 和 fid 805 仍在实体表中，磁盘上仍然缺失。没有迁移，也没有删除 |

这次没有执行 SQL，也没有通过文件 API 保存这些实体。

## 3. 运行环境

| 项 | 结果 |
| --- | --- |
| Storage Manager / runtime | silly，cloudflare_r2，`public` / `private`，根前缀空，CDN `https://cdn.gitxx.xyz` |
| active.json | 公开模式 cloudflare_r2，CDN 开启 |
| 公开 Wrapper | XedcPublicS3fsStream |
| 私有 Wrapper | PrivateStream |
| Drush | 带 Cron 那组环境变量和不带这组变量，公开前缀都是 `public`，样例地址都是 CDN 的 `public/` |
| Cron | crontab 使用 `XEDC_STORAGE_RUNTIME=1`、`STORAGE_PUBLIC_TAKEOVER=1`、`STORAGE_PRIVATE_TAKEOVER=0`、`STORAGE_CDN_ENABLED=1`，没有单独的前缀变量 |
| PHP-FPM | 池配置里是同一组五个变量。进程的 `/proc/environ` 读出来是空的，不能用它证明环境变量。缓存未命中的图片详情页由网站进程输出了 `https://cdn.gitxx.xyz/public/...`，没有 `s3fs-public` |

活动数据库里的 S3FS 文件夹已经是 `public` / `private`。`config/sync` 里仍是 `s3fs-public` / `s3fs-private`，region 仍是 `us-east-1`。这次没有修改这两处。运行时实际使用的是 `public` / `private` 和 region `auto`。没有执行 Storage Switch，没有 `drush cr`，没有清空全站缓存。

## 4. 图片样式

本地 `sites/default/files/styles/` 仍是 824 个文件、19361747 字节，与迁移前相同。

抽查：

| 文件 | 结果 |
| --- | --- |
| `styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp` | 本地 49354 字节。`https://gitxx.xyz/...webp` 返回 200，`image/webp` |
| `styles/video_cover/public/2026-10/IMG_1204.png.avif` | 本地 10964 字节。站点地址返回 200，`image/avif` |

样式 URL 带 `xedc_style=1`，主机是站点本身，不是 `cdn.gitxx.xyz`。R2 的 `public/styles/` 为空。样式代码没有改。

## 5. Cloudflare 和 CDN

没有 Cloudflare API 凭据，也没有 Wrangler 登录凭据。Origin Rules、Rewrite、Cache Rules、Workers 和 WAF 都没有读取，也没有修改。

`cdn.gitxx.xyz` 现在解析到 Cloudflare 的地址 `104.21.60.76` 和 `172.67.193.220`。这次查询没有返回 CNAME 目标，控制台里的记录内容未验证。

CDN 行为：

| 检查 | 结果 |
| --- | --- |
| 135 个新 URL 完整下载 | 全部 200，MIME、大小和 SHA256 与目标对象一致，合计 101368346 字节 |
| 130 个旧 `s3fs-public/` URL | 范围请求全部 200 或 206 |
| `IMG_3026.jpeg` 新旧地址 | 都返回 `image/jpeg`，总长度 628587 |
| `IMG_3480.png` 和 5 个上传原图 | 范围请求 206，总长度与对象一致 |
| MP4 `oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` | `Range: bytes=0-15` 返回 206，`video/mp4`，总长度 4627386 |
| `public/public/...` 和 `s3fs-public/public/...` | 404。路径没有被去掉一层 `public/` |
| 缓存 | 全量下载时 133 个 MISS、1 个 EXPIRED、1 个 HIT。没有执行 Purge Everything |

## 6. 页面

这些是 HTTP 响应，不是浏览器验收。

| 页面 | HTTP | 观察到的地址 |
| --- | --- | --- |
| 首页 `/` | 200 | 封面走本地样式，没有 `s3fs-public` |
| 美图列表 `/meitu` | 200 | 本地样式 |
| 图片详情 `/cosplay/cos/531` | 200，页面缓存未命中 | `IMG_3026.jpeg`、`IMG_3480.png` 和 4 个 `_0` 原图都是 `cdn.gitxx.xyz/public/...` |
| 视频列表 `/video` | 200 | 本地样式 |
| 视频详情 | 200 | MP4 为 `cdn.gitxx.xyz/public/2026-10/...mp4` |
| 文章列表和一篇文章详情 | 200 | 没有旧前缀 |
| 模特列表 `/models`、模特页 `/models/81` | 200 | 本地样式 |
| 会员公开主页 `/r/aa` | 200 | 本地样式，没有旧前缀 |
| 会员中心、收藏、投稿 | 200，登录后 | 没有旧前缀 |
| 后台内容 `/admin/content` | 200 | 没有 CDN 旧前缀 |
| 后台文件库 `/admin/content/files` | 200 | 45 个 `cdn.gitxx.xyz/public/`，0 个 `s3fs-public` |
| 内容编辑 `/node/531/edit` | 200 | 预览图走本地样式，没有旧前缀 |

站点没有 `/admin/content/media` 这条路由。文件库使用 `/admin/content/files`。

迁移开始之后，watchdog 里没有新增的紧急、错误或警告记录。

真实浏览器没有打开这些页面。没有可用的浏览器自动化工具，不能把上面的 HTTP 结果写成浏览器验收。

## 7. 保留和回滚

仍然保留：全部 `s3fs-public/` 对象、全部 `public/` 对象、本地正式原图、fid 799、9 条私有 ZIP 记录、fid 805、824 个图片样式、测试前缀里的 4 个对象、`and/`。

私有文件仍在本地私有目录的实体记录中，没有进 R2。私有接管保持关闭。

如果读路径需要退回旧对象：保留两边的对象，把 Storage Manager 的公共文件夹改回 `s3fs-public`，让 `runtime.json` 回到旧前缀，然后确认公开地址重新指向 `s3fs-public/`。不要删除对象，也不要批量改 File URI。改之前使用执行目录里的 `runtime.json` 和 `active.json`。这次没有改 URI，不需要恢复 `file_managed`。

## 8. 未验证

- 真实浏览器里的点击、播放和样式生成。
- Cloudflare 控制台中的 DNS 记录目标、Rewrite、Origin Rules、Cache Rules、Workers、WAF。
- PHP-FPM 进程内存中的环境变量。池配置和实际输出的页面地址已经核对，进程环境文件本身不可读。
