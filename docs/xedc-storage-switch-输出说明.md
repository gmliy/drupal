# 存储切换：输出说明

日期：2026-11-03  
站点：https://gitxx.xyz  
项目：/var/www/drupal

本文件说明这次任务交出了什么、数字是什么意思、结论为什么不是 PASS。架构、实现、验收清单和生产步骤分别在：

- `docs/xedc-storage-switch-preflight.md`
- `docs/xedc-storage-switch-architecture.md`
- `docs/xedc-storage-switch-implementation-report.md`
- `docs/xedc-storage-switch-acceptance-report.md`
- `docs/xedc-storage-switch-production-runbook.md`

## 结论

**PARTIAL。** 后台可以真正检查并准备切换，生产公开存储仍是 Cloudflare R2。没有把生产从 R2 切到本地，也没有把本地独有文件补上 R2。

运行文件是 `private/xedc-storage-runtime/active.json`。当前为版本 1、供应商 `silly`、模式 `cloudflare_r2`、状态 `active`。公开地址仍是 `https://cdn.gitxx.xyz`。私有文件仍在本地。

## 这次做了什么

公开包装器固定为 `Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`。本地还是 R2，由上面的运行文件决定，不再靠五个环境变量碰运气，也不再靠重新编译两个不同的类。

入口仍是 `/admin/config/media/object-storage`，运行模式页是 `/admin/config/media/object-storage/runtime`。页面上的“检查文件一致性”会比较真实文件。目标就是当前供应商时，服务端直接拒绝。确认文字是“切换公开存储”。这次没有提交“确认并切换”。

测试前缀 `s3fs-public/_xedc_storage_test/switch/` 上完成了本地与 R2 的往返复制和 SHA-256 校验，测试对象随后删除，前缀对象数回到 0。本地模式只在一个独立 CLI 进程里写过一个测试文件，写完已删除，PHP-FPM 没有进入本地模式。

## 生产文件现在是什么情况

核对时间约 2026-11-03 00:11 UTC。旧报告里的 130 个 R2 对象、143 条 `file_managed` 已经不能当当前数量。

| 项目 | 数量 |
| --- | ---: |
| R2 公开对象 | 133 个，79,802,162 字节 |
| R2 私有对象 | 0 |
| `file_managed` | 144 |
| 需要核对的公开原件 | 135 |
| 本地和 R2 都有，且大小一致 | 128 |
| 只在本地 | 5 |
| 只在 R2 | 2 |
| 两边都没有 | 0 |
| 大小不一致 | 0 |
| 本地图片样式 | 15 个一级目录，792 个文件 |

144 减 135 等于 9，这 9 条是原来的私有 ZIP。它们的 `changed` 时间戳没有变。

只在 R2、本地没有的两个文件：

- `s3fs-public/2026-11/IMG_3026.jpeg`，628,587 字节
- `s3fs-public/2026-11/IMG_3480.png`，3,946,033 字节

只在本地、R2 没有的五个文件：

- `s3fs-public/2026-11/IMG_2398.png`
- `s3fs-public/2026-11/IMG_2714_0.png`
- `s3fs-public/2026-11/IMG_2717_0.png`
- `s3fs-public/2026-11/IMG_2716_0.png`
- `s3fs-public/2026-11/IMG_2715_0.png`

另外三个 R2 对象不在公开原件清单里，没有删除：`.htaccess`、探测图 `_xedc_image_style_probe/pixel.png`，以及已经存在的样式对象 `styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp`。新的缩略图、WebP、AVIF 仍在本地生成。

这些数字的含义：现在再点“切换到 R2”不会发生任何事，因为当前就是 R2，也不会把那 5 个本地 PNG 补传上去。如果以后授权切到本地，切换前要复制的是上面那 2 个只在 R2 的文件，大约 4.6 MB。R2 上的原件会保留。

## 访问是否还正常

照片页 `/cosplay/cos/315` 返回 200。原图走 CDN，样式路径仍在本地。样本图 `https://cdn.gitxx.xyz/s3fs-public/2026-10/IMG_3009.jpeg` 返回 200。

视频 `public://2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` 的 CDN 地址，Range `bytes=0-1023` 返回 206，总长度 4,627,386 字节。没有在浏览器里点开播放器。

部署刚开始时，包装器在类还没加载时被调用，照片页短时间返回过 500。启动代码改为直接加载运行状态类之后恢复。运行文件是在恢复之后才写成当前的 R2 状态。

## 几个进程是否一致

没有带存储环境变量的 Drush 读到的也是 `cloudflare_r2`，公开类是 Xedc 类，私有类是 `PrivateStream`。

为了证明容器缓存丢失后不会编回本地包装器，删除过当时的 Redis 容器键，让 PHP-FPM 重新编译。新的创建时间是 `1793665575.961`，结果仍是 Xedc 类。照片页仍走 CDN。这不是切换步骤，正常切换不删 Redis、不执行 `drush cr`、不 reload PHP-FPM。

Cron 没有单独跑。它的入口仍带原来的五个环境变量，并且同样会读这份运行文件。

## 后台看到了什么

匿名打开运行模式页是 403。管理员页面显示实际模式 `cloudflare_r2`、版本 1、公开包装器和私有包装器，以及“检查文件一致性”。桌面和手机截图在 `/root/xedc-storage-shots/runtime-desktop.png`、`runtime-mobile.png`。

管理员提交检查后，页面上的数量与上面的清单一致，并出现“确认并切换”。没有点这个按钮。页面里没有 R2 API 主机，也没有密钥。

只查看权限的临时账号能打开运行模式页，看不到检查和切换按钮；新增供应商和迁移页是 403。账号和角色已删除。

暗色模式沿用主题里已有的 `html[data-theme="dark"]` 变量。没有改管理员的主题偏好，所以没有暗色截图。`/root/backup.sh` 对网页进程不可读，页面仍显示“不能读取”，这是原来的显示。

## 为什么不是 PASS

下面几项没有在生产上发生，所以不能打勾：

- 那 2 个只在 R2 的文件没有下载到本地，那 5 个本地 PNG 也没有上传。
- 没有观察“切换完成之后”旧图片是否仍能打开，因为没有切换。
- 没有故意制造一次复制失败，所以 `rolled_back` 没有被生产任务带出过。回滚代码会写回旧状态，并且不删刚复制的文件。
- 没有一次成功切换，因此没有对应的成功审计记录。
- 只读检查比的是文件大小。SHA-256 用在测试前缀和将来真正复制的那些文件上，没有对 133 个正式对象逐个下载校验。

没有删除 R2 对象、本地原图或私有文件。没有改 Bucket、Endpoint、密钥、配置 ID `silly` 和第三方 S3FS。没有提交或推送 Git。生产切换仍要单独授权，步骤在 `docs/xedc-storage-switch-production-runbook.md`。
