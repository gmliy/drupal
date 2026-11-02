# 生产启用检查

日期：2026-11-02。下面的环境变量当前都没有打开。不要为了验证去改 PHP-FPM 环境。

2026-11-02 18:10 UTC 最终验收再次确认五个开关关闭，`public://` 仍是本地 `PublicStream`。生产接管判定为未通过。缓存和 WAF 的最新证据在 `docs/object-storage-r2-final-acceptance.md`。

## 状态

| 开关 | 现在 | 结论 |
| --- | --- | --- |
| `XEDC_STORAGE_RUNTIME` | 关 | 需要人工确认后才能设为 `1` |
| `STORAGE_I_UNDERSTAND_TAKEOVER` | 关 | 同上 |
| `STORAGE_PUBLIC_TAKEOVER` | 关 | 生产启用风险 |
| `STORAGE_PRIVATE_TAKEOVER` | 关 | 生产启用风险 |
| `STORAGE_CDN_ENABLED` | 关 | 与接管独立。需要人工确认 |

`TakeoverReadiness` 把 `public_ready` 和 `private_ready` 固定为 false。页面不能把检查通过写成已经接管。

## 已经满足的依赖

隔离前缀上已经通过：JPEG、PNG、WebP、AVIF、`thumbnail`、`photo_gallery_650`、视频 Range 和 `video/mp4`、ZIP 解压后写入、上传校验、重复上传不覆盖、删除测试对象、CDN 按对象键取回测试 JPEG。

本地与远程适配层已接进图片管道、压缩包、下载、路径守卫和清理扫描。清理不会因为 `realpath()` 为 false 就认为远程对象不存在。

GD 四类格式可写。Imagick 没有安装，不作为条件。

## 2026-11-02 迁移预检查

只扫描，没有复制。文件实体仍是 137 行，URI 校验和在扫描前后一致。

| 项 | 数量 |
| --- | --- |
| 可复制的公开文件 | 128 |
| 公开文件体积 | 75,177,632 字节 |
| 本地文件缺失 | 9，全部是私有记录，数据库里的大小合计 45,393 字节 |
| 已在 R2 且 SHA-256 一致 | 0 |
| 对象校验失败 | 0 |
| 超过 32MB、清单未哈希 | 0 |
| `s3fs-public/` 现存对象 | 0 |
| `s3fs-private/` 现存对象 | 0 |
| 本地图片样式目录 / 衍生文件 | 15 / 766 |

128 个公开文件都在本地，对象键按 `s3fs-public/` 加原相对路径。R2 上还没有这些对象。9 个私有记录没有本地文件，不能迁移，也还不能做远程校验。

图片样式衍生文件在站点磁盘上。它们不在 R2。首页、`/cosplay/cos/315` 和一张原图、一个带令牌的 `thumbnail` AVIF 都从 `gitxx.xyz` 返回，地址里没有 `cdn.gitxx.xyz`。

## 2026-11-02 不能启用的原因

下面任何一条成立，都不能标成生产就绪。当前仍然成立。

1. 公开接管未就绪。128 个公开文件都还在本地，R2 上没有对应对象。接管后 `public://` 会指向 R2，现有图片 URL 会失效。
2. 私有接管未就绪。9 个私有文件的数据库记录还在，磁盘文件不在。`cdn.gitxx.xyz` 仍能匿名读到 `s3fs-private/` 下的测试对象，包括把斜杠编码成 `%2F` 的路径。
3. CDN 重写未就绪。测试对象可以按键取回，生产文件没有映射。视频的 CDN `Cache-Control` 与上传头不一致。私有前缀也走了同一公开域名。
4. 历史文件没有复制。复制次数是 0。
5. 可播放测试片已经在 CDN 上完成 Range 和浏览器拖动。这不表示会员视频已经在 R2 上。
6. 现有文件名没有内容哈希。一年 immutable 没有写到生产文件，以后复制也不会对未版本化的键使用它。
7. ZIP 导入和会员浏览器下载页不在这次验收里重做。
8. 回滚目前不需要执行。本地公开文件都在，URI 没有改。私有 9 个文件已经不在磁盘上，回滚不能把它们变出来。

风险：在私有前缀被边缘规则拒绝之前复制私有文件，会把它们放到一个匿名可读的域名后面。公开文件在复制完成前打开接管或 CDN rewrite，现有图片地址会指向还不存在的对象。

## 还没有满足的依赖

公开接管前还缺：

- 历史公开文件复制到 `s3fs-public/` 并校验。未做。
- 生产图片样式在 R2 上生成。本地已经有 766 个衍生文件，R2 上还没有。
- 可播放测试片的 CDN 播放已经完成。生产视频文件还没有复制。
- 生产 ZIP 导入创建内容和文件实体。未做。
- 打开接管后，现有本地文件是否仍可通过 `public://` 读到。S3 File System 接管后 `public://` 指向对象存储。对象还没复制时，现有 128 个公开文件会从 URL 上消失，尽管磁盘文件还在。这是生产启用风险。

私有接管前还缺：

- 9 个私有文件的本地副本已经缺失，无法复制。
- `cdn.gitxx.xyz` 对 `s3fs-private/` 测试对象返回了 200。私有前缀还没有隔离。
- 会员等级已在 CLI 验证。签名过期已在隔离前缀验证为 403。浏览器里的下载页未做。

## 授权之后的操作顺序

只有在明确同意复制生产文件并接受接管窗口后，才按这个顺序。现在不要执行。

1. 做迁移预检查，确认目标是 `silly`。
2. 分批复制公开文件。确认 URI 没变、本地文件还在、对象键是 `s3fs-public/` 加原相对路径、SHA-256 一致。
3. 用一个已复制对象的 CDN 或 S3 URL 核对类型和大小。不要用首页代替这个核对。
4. 确认 Bucket 里那 1 个未知对象的归属，避免回滚时误删。
5. 在 PHP-FPM 环境里同时设置 `XEDC_STORAGE_RUNTIME=1`、`STORAGE_I_UNDERSTAND_TAKEOVER=1`、`STORAGE_PUBLIC_TAKEOVER=1`。私有开关先不要开。
6. 重载 PHP-FPM，让 `BootGuard` 重新读环境。只改配置页不会打开接管。
7. 看一张已知图片和一条视频。失败则立刻把三个变量改回未设置并再次重载，本地文件还在，可以回到本地流。
8. 私有文件单独复制到 `s3fs-private/`，证明匿名访问被拒绝后，才考虑 `STORAGE_PRIVATE_TAKEOVER=1`。
9. CDN rewrite 用 `STORAGE_CDN_ENABLED=1` 单独打开。公开域名已经能取回测试对象，但生产文件复制完成前打开 rewrite 没有对应对象。

`BootGuard` 在凭证、Bucket、Endpoint 或前后缀无效时会保持本地存储，并写入 `xedc_storage.runtime_error`。不要用改模块源码的方式绕过这个判断。

## 后台在哪里看

- `/admin/config/media/object-storage`
- `/admin/config/media/object-storage/cdn`
- `/admin/config/media/object-storage/readiness`
- `/admin/config/media/object-storage/migration`

匿名访问就绪页返回 403。权限是 `administer xedc object storage`。密钥字段保持脱敏。
