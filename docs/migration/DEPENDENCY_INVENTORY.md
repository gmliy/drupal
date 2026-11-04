# 依赖清单

版本以 2026-11-03 的生产主机为准。策略是 `pinned`：恢复时优先同一主版本。许可证不允许再分发的软件只记录官方来源，不把二进制打进 Git。

| 软件 | 用途 | Drupal | 版本 | 来源 | 恢复方式 | 再分发 |
| --- | --- | --- | --- | --- | --- | --- |
| Ubuntu | 操作系统 | 必需 | 24.04.4 | ubuntu.com | 安装同系列 LTS | 否，使用官方镜像 |
| PHP | 运行 Drupal | 必需 | 8.5.12 | ppa:ondrej/php | `php8.5-*` 包 | 否 |
| Nginx | Web | 必需 | 1.31.6 | nginx.org mainline noble | apt | 否 |
| MariaDB | 数据库 | 必需 | 13.0.2 | dlm.mariadb.com 13.rolling | apt | 否，GPL |
| Redis | 缓存 | 增强 | 8.10.3 | 发行版/Redis 源 | apt。没有扩展时设置会跳过 | 否 |
| Composer | 依赖 | 增强 | 2.10.3 | getcomposer.org | 官方安装脚本 | 否 |
| Drush | 管理 | 增强 | 13.8.0 | Composer 包 `drush/drush` | 随 vendor 恢复 | 随项目许可证 |
| ffmpeg / ffprobe | 视频 | 增强 | 6.1.1-3ubuntu5 | Ubuntu | apt `ffmpeg` | 否，GPL |
| 7-Zip | 压缩包导入 | 增强 | 23.01 | Ubuntu `p7zip-full` | apt | 否，LGPL |
| unzip | 压缩包 | 增强 | 6.00 | Ubuntu | apt | 否 |
| Certbot | 证书 | 增强 | 2.9.0 | Ubuntu | apt | 否 |
| Python cryptography | 备份加密 | 必需（工具） | 41.0.7 | Ubuntu `python3-cryptography` | apt | 否 |
| Node / npm | 主题构建 | 可选 | 构建机 Node ≥ 18 | NodeSource 或发行版 | 只在重新编译主题时安装 | 否 |

二进制 SHA256（审计当时的文件内容，升级后会变）：

| 文件 | SHA256 |
| --- | --- |
| `/usr/bin/php` | `eb85560c0bea6c5d1c06a93a602697efe94ad9803da400211469529f041929fa` |
| `/usr/sbin/nginx` | `8a27bd4b1960fafff9fa7a47828f1042fd968e3e682772ac0d5a5254e0cb0e30` |
| `/usr/bin/mariadb` | `0b9c98f1e76864f8e60a7563abb6c81ca25c4ce1e57ff38de6e39ec1defa5765` |
| `/usr/bin/ffmpeg` | `ed16af623947494a72e284b6eb8ff225f2da22b38b5d5069c2fd4b4ba3384e41` |
| `/usr/bin/7z` | `60fc00b4e1ed37668972c51f03426973d8006db3c7224075878f6d66196d7c27` |
| `/usr/bin/certbot` | `12de05ecf109e44769a3b9d954a857af29620167b42964c86291a7b6e614b84b` |
| `/usr/bin/redis-server` | `246d19b472e615e0491b51b95d3f533fc033c5d43e73c380b88c4ad49eb8a128` |
| `/usr/local/bin/composer` | `7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6` |
| `/usr/local/bin/drush` | `cb951b407b92ffd396ce0f9bfa12da1113081c4cc3f7acba5eb5f3b2a866d19c` |

架构是 x86_64。这些包同时提供 arm64 的，以各自软件源为准；本次没有在 arm64 上演练。

Composer 锁定的 Drupal 相关包包括 `drupal/core` 11.4.11、`drush/drush` 13.8.0，以及 colorbox、gin、redis、s3fs、pathauto、metatag、redirect、scheduler、smtp 等。完整列表在 `composer.lock`，共 119 个生产包。恢复时优先使用备份里的 `vendor`，这样不依赖当时的 Composer 仓库是否还能解析 dev 分支。

主题 `web/themes/custom/xedc/package.json` 的 npm 依赖用于编译前端。已编译资源随主题备份。没有重新设计主题时，不需要在新服务器执行 `npm install`。

自定义代码调用的外部程序：

- `ffmpeg` / `ffprobe`：视频探测和转码
- `7z`：相册压缩包
- `curl` 扩展：对象存储
- `file` 命令只出现在手工测试脚本里，不是站点运行必需

GitHub 上的项目代码随仓库恢复。没有发现需要按某个未锁定 commit 单独编译的第三方程序。

如果 PHP 8.5 软件源不可用，安装程序退出码为 5，并记为 BLOCKED。不会自动降到 PHP 8.3 后宣称成功。
