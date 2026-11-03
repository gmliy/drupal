# 生产存储切换执行计划

日期：2026-11-03  
站点：https://gitxx.xyz  
状态：等待明确授权。不要按这份计划自行执行。

## 当前状态

公开存储正在使用 Cloudflare R2。

- 运行文件：`private/xedc-storage-runtime/active.json`
- 版本 1，供应商 `silly`，模式 `cloudflare_r2`，CDN `https://cdn.gitxx.xyz`
- 公开包装器：`Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`
- 私有包装器：`Drupal\Core\StreamWrapper\PrivateStream`
- Redis 容器创建时间：`1793665575.961`，类是 Xedc，不是 `PublicStream`
- 五个环境变量保持原值，Private takeover 仍是 0
- R2 公开对象 133 个，79,802,162 字节；私有前缀 0 个

再点一次“切换到 Cloudflare R2”会被服务端拒绝，因为目标就是当前供应商。这次拒绝不会把 5 个本地独有 PNG 补传到 R2。

## 如果授权切到本地

目标状态：`public_mode=local`，`public_provider=local`，CDN 关闭，版本变为 2。新的公开原件写入本地公开目录。已有 `public://` URI 不变。图片样式仍在本地。私有文件不变。

切换前要复制的文件只有 R2 上有、本地没有的 2 个公开原件：

- `s3fs-public/2026-11/IMG_3026.jpeg`，628,587 字节
- `s3fs-public/2026-11/IMG_3480.png`，3,946,033 字节

合计约 4.6 MB。复制后比较大小和 SHA-256。R2 对象保留。

下面这些对象不在复制清单里，也不会删除：

- 5 个只在本地的 PNG
- `s3fs-public/.htaccess`
- `s3fs-public/_xedc_image_style_probe/pixel.png`
- `s3fs-public/styles/meitu_card_cover/public/2026-11/IMG_3026.jpeg.webp`
- 9 条私有 ZIP 和整个私有目录

影响：检查和复制期间，非样式公开写入会暂停。样式生成不停。完成后新原图不再上传 R2，公开 URL 不再使用 CDN。只存在于 R2、又没有被这次清单覆盖的对象，如果有漏网文件，本地模式会读不到。当前清单里两边都没有的公开原件是 0 个。

如果以后再从本地切回 R2，那时会把本地有而 R2 没有的原件上传，包括现在这 5 个 PNG。那是另一次授权，不是这次操作的一部分。

## 备份

执行前确认：

- 数据库备份已有最近一份，不在这次任务里新跑 `backup.sh`
- 本地公开目录 `web/sites/default/files` 仍在
- R2 不删除对象，因此 R2 本身就是原图的保留副本
- 运行文件先复制一份到私有目录外的管理员可读位置，例如：

```bash
install -m 0640 -o www-data -g www-data \
  /var/www/drupal/private/xedc-storage-runtime/active.json \
  /var/www/drupal/private/xedc-storage-runtime/active.json.before-switch
```

不要把这份文件提交进 git。里面没有密钥，但也不需要进仓库。

## 执行

授权之后才做：

1. 打开 `/admin/config/media/object-storage/runtime`。
2. 确认实际模式仍是 `cloudflare_r2`，版本仍是 1。
3. 选择“本地文件系统”。
4. 点击“检查文件一致性”。确认仅 R2 是 2，两边都没有是 0，大小不一致是 0。
5. 输入“切换公开存储”，点击“确认并切换”。
6. 不要同时开第二个切换。锁会拒绝后一个。

失败时页面应显示回滚。运行文件应回到 `cloudflare_r2` 和原来的版本，状态为 `rolled_back`。已下载到本地的副本保留，R2 对象保留。

## 回滚

如果切换已经完成但访问不对：

1. 再走一次后台切换，目标选 Cloudflare R2（`silly`）。它会先补本地独有原件，再把运行文件写回 R2。
2. 如果后台不可用，由能读私有目录的管理员把 `active.json.before-switch` 原子替换回 `active.json`。替换前确认校验和仍是备份时的值。
3. 替换后不要 `drush cr`。下一个 PHP-FPM 请求会按新的文件时间读到 R2。
4. 用下面的验收命令确认照片页重新出现 CDN，并且容器类仍是 Xedc。

不要删除本地下载件，不要删除 R2 对象，不要打开 Private takeover。

## 验收命令

在带五个环境变量的 `www-data` Drush 里看运行文件，或直接看文件内容。确认 `public_mode`、`version` 和 `status`。

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://gitxx.xyz/cosplay/cos/315
curl -s https://gitxx.xyz/cosplay/cos/315 | grep -o 'https://cdn.gitxx.xyz[^" ]*' | head
curl -s -D - -o /dev/null -r 0-1023 \
  https://cdn.gitxx.xyz/s3fs-public/2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4 \
  | head -n 15
```

切到本地之后，照片页不应再把原图指到 CDN，样式路径仍应在 `/sites/default/files/styles/`。切回 R2 之后，原图应重新出现 `https://cdn.gitxx.xyz/s3fs-public/`。

Redis 只在容器类意外变成 `PublicStream` 时才需要处理。正常切换不删容器键，不 reload PHP-FPM。
