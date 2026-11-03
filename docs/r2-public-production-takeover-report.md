# R2 公开存储生产接管报告

时间：2026-11-02 22:30–22:53 UTC。生产站点：https://gitxx.xyz。

总评：**PASS**。公开原文件已由 PHP-FPM 使用 `XedcPublicS3fsStream` 提供，CDN 为 `https://cdn.gitxx.xyz/s3fs-public/`。私有包装器仍是本地 `PrivateStream`。128 个正式对象和本地原文件都还在。没有回滚接管。

## 1. 部署前生产状态

| 项目 | 状态 |
| --- | --- |
| Git | `main`，与 `origin/main` 一致的提交是 `e2cd0e5`。工作区已有大量未提交改动，本任务没有覆盖、还原或提交 |
| 公开包装器 | `Drupal\Core\StreamWrapper\PublicStream` |
| 私有包装器 | `Drupal\Core\StreamWrapper\PrivateStream` |
| 五个开关 | PHP-FPM master `32897` 和当时的 worker 环境里都不存在 |
| 容器缓存 | Redis 键 `gitxx:container:service_container:prod:…`，created `1793657307.341`（2026-11-02 22:08:27 UTC）。内容是本地包装器，不含存储凭证。数据库 `cache_container` 有 2 行旧键，不是当前容器 |
| CDN | 页面没有 `cdn.gitxx.xyz`，也没有 R2 API 主机 |
| R2 | `s3fs-public/` 128 个对象，75,177,632 字节，与清单一致；样式对象 0；`s3fs-private/` 0 |
| 本地原文件 | `file_managed` 137 行，fid 合计 28272，URI CRC32 合计 300810938969。公开 128 个都在，大小一致 |
| 图片样式 | 15 个目录、774 个文件。WebP 与 AVIF 样例返回 200 |
| 页面 | 首页、`/cosplay`、`/cosplay/cos/315`、`/video`、视频详情、文章详情均为 200。`/member` 对未登录用户是 302 |
| 9 条私有 ZIP | fid 166、219、272、326、379、481、491、505、520，文件仍缺失，`changed` 未变 |

供应商运行时配置里的公开地址是 `https://cdn.gitxx.xyz`。`STORAGE_CDN_ENABLED` 只在精确为 `1` 时打开 URL 改写。包装器类只在容器编译时注册，改环境变量不会让已有 Redis 容器失效。

## 2. 备份位置

目录：`/root/drupal-backup/r2-public-takeover/`，权限 700。

| 文件 | 内容 |
| --- | --- |
| `drupal.sql.gz` | 切换前数据库，gzip 校验通过 |
| `settings.php` | 切换前的 Drupal 设置，权限 600 |
| `www.conf` | 切换前的 PHP-FPM pool |
| `php-fpm.conf` | PHP-FPM 主配置 |
| `nginx-drupal.conf` | Nginx 站点配置，本任务没有改它 |
| `redis-container.dump` | 切换前容器缓存，已用临时键做过恢复演练并删除临时键 |
| `redis-container.meta` | 容器键、TTL 和 created |
| `xedc-traffic.cron`、`root.crontab`、`backup.sh` | 修改 Drush 环境之前的副本 |
| `config.sha256` | 上述配置文件的校验值 |

报告和备份说明里没有写入 R2 凭证。

## 3. 实际修改

- `/etc/php/8.5/fpm/pool.d/www.conf`：增加五个 `env[...]`。`php-fpm8.5 -t` 通过后执行了 `systemctl reload php8.5-fpm`。
- `/etc/cron.d/xedc-traffic`、root crontab、`/root/backup.sh`：每分钟采样、15 分钟 `drush cron`、每日备份里的 Drush 使用同一组五个开关。原因见第 16 节。
- `web/modules/custom/xedc_home/src/EventSubscriber/SecurityHeaderSubscriber.php`：`img-src` 和 `media-src` 增加 `https://cdn.gitxx.xyz`。
- 删除并重建了 Redis 里的那一条容器缓存。没有执行 `drush cr`，没有清空全部缓存。
- 为了让已缓存的 HTML 使用新地址，清空了 `page`、`dynamic_page_cache` 和 `render` 这三个缓存 bin。CSP 修改后再清了前两个。容器 created 仍是 `1793659803.216`。

没有修改 `settings.php`、Nginx、`config/sync`、第三方模块、私有文件记录或 128 个正式对象。

## 4. 五个开关的最终值

PHP-FPM worker 实际读到：

| 变量 | 值 |
| --- | --- |
| `XEDC_STORAGE_RUNTIME` | 1 |
| `STORAGE_I_UNDERSTAND_TAKEOVER` | 1 |
| `STORAGE_PUBLIC_TAKEOVER` | 1 |
| `STORAGE_PRIVATE_TAKEOVER` | 0 |
| `STORAGE_CDN_ENABLED` | 1 |

凭证仍只在原有的后台配置文件中，没有进入命令行、报告或新代码。

## 5. Drupal 实际包装器

通过本机 PHP-FPM 套接字启动 Drupal，不经过 Nginx，也不把脚本放到网站目录：

- `public`：`Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream`
- `private`：`Drupal\Core\StreamWrapper\PrivateStream`
- `s3fs.use_s3_for_public=1`
- `s3fs.use_s3_for_private=0`
- `xedc_storage.cdn_enabled=1`
- `settings_accepted=1`，运行时错误为空

Redis 中的容器定义包含 `XedcPublicS3fsStream` 和 `PrivateStream`。

## 6. PHP-FPM 验收

当前 pool 是 dynamic，验收时实际有两个 worker：`125139` 和 `125140`。master 仍是 `32897`。两个 worker 的连续请求都是上面的包装器，没有出现一个 worker 本地、另一个 worker 对象存储的情况。新 worker 读取同一条 Redis 容器。

没有环境变量的 `drush php:eval` 能加载这条已编译容器，公开类仍是 `XedcPublicS3fsStream`，容器 created 没有变化。该进程自己的接管 Settings 为 0，因为它没有这五个变量。定时任务已经带上这五个变量，避免以后缓存缺失时由 CLI 编译回 `PublicStream`。

## 7. 专用测试文件

前缀：`public://xedc-r2-production-verify/`。没有使用已有的 128 个对象。

| 操作 | 结果 |
| --- | --- |
| 上传 | PASS |
| 读取，SHA-256 与写入内容一致 | PASS |
| stat 大小 | PASS |
| MIME `image/png` | PASS |
| rename | PASS |
| Head 到 R2，大小和 MIME 一致 | PASS |
| 从 R2 再读，SHA-256 一致 | PASS |
| 匿名 CDN GET 200，正文 SHA-256 一致 | PASS |
| 删除后远程对象不存在 | PASS |
| `s3fs_file` 测试前缀 | 0 行 |
| `file_managed` | 行数未变 |
| 9 条私有记录 | 未变 |

最终清单里 `xedc-r2-production-verify/` 对象数为 0。

## 8. CDN

图片详情原图：

`https://cdn.gitxx.xyz/s3fs-public/2026-11/IMG_2771.png`

返回 200，`image/png`，3,920,503 字节。

已有对象 `s3fs-public/2026-10/IMG_3009.jpeg` 仍是 200，`image/jpeg`，163,198 字节。

视频详情源地址：

`https://cdn.gitxx.xyz/s3fs-public/2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4`

`Range: bytes=0-15` 返回 206，`video/mp4`，`Content-Range: bytes 0-15/4627386`。

首页、图片列表、图片详情、视频列表、视频详情、文章详情的 HTML 都没有 R2 API 主机。图片详情有 6 处 CDN 原图地址。视频详情有 CDN 上的 MP4。无头 Chromium 取回的图片详情 DOM 含 5 处 `cdn.gitxx.xyz/s3fs-public/` 和 9 处本地样式地址，日志里没有 CSP 拒绝记录。截图文件没有生成，像素级播放没有单独录像。

## 9. 图片样式

样式继续走本站 `/sites/default/files/styles/`。首页、列表和详情都是这样。

| 地址 | 结果 |
| --- | --- |
| 本站 `IMG_2771.png.webp` | 200，`image/webp`，56,418 字节 |
| 本站视频封面 AVIF | 200，`image/avif`，5,077 字节 |
| 同名样式的 CDN 地址 | 404 |
| R2 `s3fs-public/` 中的样式对象 | 0 |

样式目录仍是 15 个，文件仍是 774 个。测试包装器对样式 URL 返回本地路径。

## 10. 页面

| 页面 | 结果 |
| --- | --- |
| `/` | 200，样式在本站，无 API 主机 |
| `/cosplay` | 200，样式在本站 |
| `/cosplay/cos/315` | 200，原图在 CDN，样式在本站 |
| `/video` | 200，封面样式在本站，含 AVIF |
| `/videos/shipindianhuadejicunjiufujidejide` | 200，视频在 CDN，样式在本站 |
| 文章详情 | 200 |
| `/member`、`/member/downloads` | 未登录 302 到 `/user/login`，登录页 200 |
| `/xedc/download/27/archive` | 200，`application/zip`，20,877,968 字节，`no-store` |

切换后的 Nginx 错误日志没有新的 PHP Fatal。PHP-FPM 日志只有 22:41:40 的 reload 记录。

## 11. PHP-FPM 和 Nginx

两者都是 `active`。PHP-FPM 配置测试通过，reload 成功，master 32897 继续运行。Nginx 配置没有修改。

## 12. 128 个正式对象

切换后再次列出 `s3fs-public/`：128 个，缺失 0，多余 0，大小不一致 0，合计 75,177,632 字节。没有改这些对象。

## 13. 本地原始文件

128 个公开原文件都还在本地目录，大小与 `file_managed` 一致。`file_managed` 指纹仍是 137 / 28272 / 300810938969。没有删除本地文件。

## 14. Private takeover

`STORAGE_PRIVATE_TAKEOVER=0`。运行中的私有包装器是 `PrivateStream`。`s3fs-private/` 对象数是 0。9 条缺失 ZIP 的 fid、大小和 `changed` 与切换前相同。

## 15. EngagementBuilder

这是独立核实，没有改点赞、收藏、下载或分享逻辑。

| 项目 | 结果 |
| --- | --- |
| 构造函数 | 共 6 个参数，前 5 个必需，最后的 `GuestIdentity $guests` 可选 |
| `xedc_core.services.yml` | 6 个参数，顺序与构造函数一致，最后是 `@xedc_core.guest_identity` |
| 实例化 | 当前容器里 `xedc_core.engagement_builder` 可以创建，类名正确 |
| `xedc_preprocess_html()` | 当前只在 `includes/auth.inc` 声明一次，`xedc.theme` 用 `require_once` 引入它，主题文件本身不再声明 |

上一份报告里的“必需 5 个、服务定义 6 个”并不矛盾：第 6 个是可选参数。21:56 的 Nginx 日志里有一次重复声明导致的旧 Fatal，发生在本次切换之前。22:41 之后没有新的同类 Fatal，相关页面返回 200。

## 16. 回滚

没有执行回滚。接管后的环境变量、R2 对象和本地原文件都保留着。

中间发生过一次容器被写回本地包装器：22:43:53 的 Redis 容器是 `PublicStream`，而 PHP-FPM 的开关已经是 1。原因是容器缓存键不包含这五个变量；缓存缺失时，没有这些变量的 Drush 会按本地包装器重新编译并覆盖同一条 Redis 记录。当时每分钟都有一次 `drush xedc:traffic-sample`。处理方式是让采样、`drush cron` 和备份脚本里的 Drush 带上相同的五个开关，然后只删除这一条容器缓存，由带开关的 PHP-FPM 请求重新编译。之后的验收都基于重新编译的 `XedcPublicS3fsStream`。

如果以后需要回到本地公开文件，按备份恢复，不要删除 R2 上的 128 个对象，也不要删除本地原文件：

1. 用备份里的 `www.conf` 覆盖 `/etc/php/8.5/fpm/pool.d/www.conf`。
2. 用备份里的 cron 和 `backup.sh` 去掉 Drush 上的五个开关。
3. `php-fpm8.5 -t` 通过后 `systemctl reload php8.5-fpm`。
4. 用 `redis-container.dump` 恢复原来的容器键，或在五个开关都已去掉之后，由 PHP-FPM 请求重新编译容器。
5. 确认公开类回到 `PublicStream`、私有类仍是 `PrivateStream`，再看首页和一张图片。

CSP 若要一起退回，去掉 `SecurityHeaderSubscriber` 里新增的 `https://cdn.gitxx.xyz`，并清 `page` 与 `dynamic_page_cache`。

## 17. 结论

**PASS**。

公开原文件的生产请求使用 `XedcPublicS3fsStream`，私有文件仍在本地。CDN 原图和视频可以访问，图片样式仍在本站，正式对象和本地原文件完整。未登录会员中心会去登录页，这是原有访问控制；已登录会员中心没有用账号再点一次。无头浏览器没有产出截图，页面 DOM、响应头和资源响应已经核对。
