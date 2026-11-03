# 已知限制

| 项目 | 状态 | 说明 |
| --- | --- | --- |
| 生产 Cloudflare R2 上传 | BLOCKED | 没有专用测试桶，不能写生产桶。Docker Hub 和 Quay 上的 MinIO 镜像未能拉取。本地 S3 服务和 AWS 公开签名向量已通过 |
| 正式 Let's Encrypt、DNS-01 | BLOCKED | `ssl plan` 只打印 staging 计划，不执行申请，也不修改生产证书 |
| replacement 角色启动网站 | NOT TESTED | 演练固定使用 lab，避免恢复出来的第二份网站持有生产对象存储密钥 |
| 生产机 systemd/cron | NOT TESTED | 生产主机上安装被拒绝。单元测试只把单元文件渲染到临时目录 |
| Redis 版本 | 可接受差异 | 演练容器安装的是 Ubuntu 24.04 的 Redis 7.0.15，生产机是 8.10.3。Drupal 首页返回 200。没有为了对齐版本去改生产 Redis |
| Nginx 小版本 | 可接受差异 | 演练容器是 nginx.org mainline 1.31.8，生产机是 1.31.6 |
| 增量备份的生产样本 | NOT TESTED | 生产目录只做了一份全量备份。增量链和中断后续跑在隔离夹具上通过 |
| 主题 `node_modules` | 已有备份仍包含 | 制作那份生产备份时，嵌套的 `node_modules` 被收了进去。新备份会排除。恢复时硬链接会复制成普通文件 |
| 生产 R2 上的媒体访问 | BLOCKED | 演练使用 lab，故意不连接生产 R2。包内本地图片可以访问。没有专用测试桶，不能用真实桶证明 CDN |
| 一键恢复的正式证书 | BLOCKED | 新机器只写自签证书。Let's Encrypt 和 DNS-01 仍不执行 |

PHP 低于 8.5 时安装程序退出码为 5，状态记为 BLOCKED，不会改用 8.3。
