# 依赖发现报告

探测时间以 `manifest.json` 的 `generated_at` 为准。探测只读，密码、对象存储密钥、SMTP 口令、Netdata claim token 都没有写入清单。

站点根目录 `/var/www/drupal`，Drupal `11.4.11`，域名 `gitxx.xyz`。nginx 同时接受 `gitxx.xyz` 和 `www.gitxx.xyz`，没有把其中一个 301 到另一个，所以清单里 `www` 为 false。`x.gitxx.xyz` 是 Matomo 用的主机名。

## 已纳入恢复清单

| 项目 | 依据 | 恢复方式 |
| --- | --- | --- |
| nginx 1.31.6（http_mp4、http_slice） | 正在监听 80/443，站点配置 `client_max_body_size 220m` | 安装 nginx，按模板渲染站点；发行版没有 mp4 模块时跳过 `mp4` 指令并记警告 |
| PHP 8.5.12 FPM 与已启用扩展 | `php -m` 与 FPM `conf.d`。内存 512M，上传 200M，POST 220M，超时 300 秒，`max_input_vars` 10000，时区 `Asia/Shanghai` | 优先 Ondřej/Sury 的 8.5，失败则用系统源最高版本并警告 |
| MariaDB 13.0.2 | 监听 3306，库名 `drupal`，主机 `localhost` | 备份用 `mysqldump`。新机器安装发行版 MariaDB，版本不同只警告，不中断 |
| Redis 8.10 | PHP redis 扩展，监听 6379，`settings.php` 使用 PhpRedis | 安装 redis，只绑定 127.0.0.1，写回 settings |
| Composer、Drush | `/usr/local/bin/composer`、`/usr/local/bin/drush` | 恢复后 `composer install --no-dev`，再接上 Drush |
| Node.js 20.20.2 | 系统 `/usr/bin/node`。主题 `package.json` 要求 Node `>=18` | 已有 18 以上则跳过，否则安装 Node 20 |
| ffmpeg 6.1.1 | 代码调用 ffmpeg/ffprobe，编码器含 libx264、libx265、libvpx、libvpx-vp9、aac | 安装 ffmpeg，缺少 libx264 或 aac 则停止 |
| 7z / 7za | 上传压缩包功能会调用 7z | 安装 `7zip` 或 `p7zip-full` |
| 播放器 plyr、hls.js | 主题 `xedc` 已带构建产物 | 随代码恢复，不单独安装 |
| Docker 上的 Matomo | `/opt/matomo/compose.yaml`，容器 `matomo:5-apache` 与 `mariadb:lts`，本机端口 8088 | 备份 compose 和 volume。安装 `docker-compose-v2` 或 `docker-compose-plugin`。容器起不来就停止，并打印日志 |
| Netdata 2.12.2 | kickstart 安装在 `/opt/netdata`，监听 19999 | 生产环境优先 kickstart，失败再改软件包。claim token 不备份 |
| cron 与 systemd | root 每天 7:00 和 19:00 备份；每 15 分钟 Drush cron；`xedc-traffic` 每分钟采样；`xedc-import-worker.service` | 只 enable 带 `[Install]` 的单元。`xedc-import-worker` 等 worker 等站点自检通过后再启动。没有 systemd 的环境只警告 |
| Certbot | 已安装，含定时续期 | DNS 指向本机才申请正式证书，否则自签 |
| 对象存储 | 配置里的提供方是 Cloudflare R2，凭据在环境变量和私有文件 | 备份不包含桶里的媒体对象。恢复后继续用原来的私有桶 |
| SMTP | 主机 `smtp.gmail.com`，当前未启用 | 配置随 Drupal 配置导出走，工具仓库不记录口令 |
| 队列 | `drush queue:list` 得到 18 个队列，含 `xedc_video_transcode`、`xedc_download_zip` | 由 Drush cron 和 import worker 继续跑 |
| 文件权限 | 公开文件在 `web/sites/default/files`，私有文件在 `/var/www/drupal/private` | 恢复后属主改为 `www-data` |

## 疑似相关但未纳入（需要我确认）

- **imagick 扩展**：当前 PHP 没有加载。Composer 也没有把它写成硬性 `ext-imagick`。安装时会尝试，软件包不存在只警告。
- **发行版版本差**：线上 nginx 来自 nginx.org，MariaDB 来自官方 13 滚动源，Redis 来自 packages.redis.io，Docker 是 docker-ce。自动恢复使用发行版软件包，避免第三方源在空机器上失败。站点能起来，但版本号不会与旧服务器逐字相同。
- **Matomo 主机名 `x.gitxx.xyz`**：换域名时会随旧域名文本替换。换完后要确认统计站点地址是否仍正确。
- **PHP 时区 Asia/Shanghai，系统时区 Etc/UTC**：按这个差异恢复，不把系统时区改成上海。

## 已判定无关

| 项目 | 依据 |
| --- | --- |
| sing-box（8443/8444/8445） | 代理端口，站点代码和 nginx 都不依赖 |
| CUPS 631 | 打印服务 |
| Chrome 调试端口 9222、9355 | 本机浏览器调试 |
| SSH 22、systemd-resolved | 系统自带，新服务器已有 |
| snap、amazon-ssm-agent、ec2-instance-connect、hibinit-agent、open-iscsi | 云主机或桌面组件，不是站点运行时 |
| 实验室容器 `xedc-drupal-lab-db`、`xedc-recover-db` | 旧迁移残留，不在 Matomo compose 里 |
| `xedc-push-gmliy.service`、`xedc-push-gmliy.timer`、`xedc-push-gmliy.sh` | 只属于旧服务器。服务没有 `[Install]`，由每天 10:00 的 timer 调用，向 GitHub 推送仓库并带有访问令牌。新服务器不还原。清单字段 `restore_skip` |
| 其他向 Git 推送的单元、cron 和脚本 | 文件内容含 `git push` 或 `xedc-push-gmliy` 时同样不还原 |
| Memcached、Solr、Elasticsearch | 未安装，配置和代码也没有客户端。colorbox 里的 elastic 是动画名 |
| reCAPTCHA、Turnstile、OAuth | 配置导出里没有这些服务 |
| wkhtmltopdf、poppler、ImageMagick 命令行 | 自定义模块没有调用 |

## 探测命令来源

自定义模块和主题里，排除 `tests/` 后，外部命令是 ffmpeg、ffprobe、7z。`chattr` 只出现在手工测试脚本里，不作为运行依赖。
