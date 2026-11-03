# 已知限制

| 项目 | 状态 | 说明 |
| --- | --- | --- |
| 生产 Cloudflare R2 媒体对象 | 未改动 | 备份只写入 `xedc-recover/packages/`。2026-11-03 的上传、HEAD 和回读校验为 PASS。没有删除或覆盖 `public/`、`private/` |
| 正式 Let's Encrypt、DNS-01 | BLOCKED | `ssl plan` 只打印 staging 计划，不执行申请，也不修改生产证书 |
| replacement 角色启动网站 | NOT TESTED | 演练固定使用 lab，避免恢复出来的第二份网站持有生产对象存储密钥 |
| 生产机 systemd/cron | NOT TESTED | 生产主机上安装被拒绝。单元测试只把单元文件渲染到临时目录 |
| Redis 版本 | 可接受差异 | 演练容器安装的是 Ubuntu 24.04 的 Redis 7.0.15，生产机是 8.10.3。Drupal 首页返回 200。没有为了对齐版本去改生产 Redis |
| Nginx 小版本 | 可接受差异 | 演练容器是 nginx.org mainline 1.31.8，生产机是 1.31.6 |
| 增量备份的生产样本 | NOT TESTED | 生产目录只做了一份全量备份。增量链和中断后续跑在隔离夹具上通过 |
| 主题 `node_modules` | 已有备份仍包含 | 制作那份生产备份时，嵌套的 `node_modules` 被收了进去。新备份会排除。恢复时硬链接会复制成普通文件 |
| 生产 R2 上的媒体访问 | BLOCKED | 演练使用 lab，故意不连接生产 R2。包内本地图片可以访问。没有专用测试桶，不能用真实桶证明 CDN |
| 一键恢复的正式证书 | BLOCKED | 新机器只写自签证书。Let's Encrypt 和 DNS-01 仍不执行 |
| 独立物理 VPS | NOT TESTED | 全新恢复在隔离的 Ubuntu 24.04 容器中完成。没有第二台物理服务器 |
| 对 github.com 的网络克隆 | NOT TESTED | 干净仓库的提交对齐在本地 Git 仓库通过。这次生产备份的工作区有未提交改动，恢复按设计使用包内代码快照，没有连接 GitHub |
| 新格式包的整站容器恢复 | FAIL | 2026-11-03 用 `xedc-20261103T183111Z-18ce30` 在隔离 Ubuntu 24.04 上连续两轮启动了网站，但数据库有 195 个 private 导入源文件不在备份包里，恢复报告不是成功 |
| Ubuntu 26.04 自动安装 | BLOCKED | 不会添加 ondrej/php，也不会套用 24.04 的仓库地址。没有做过完整安装测试 |
| 服务器之间的真实 SCP | NOT TESTED | 操作步骤在 QUICK_RESTORE.md。本机没有第二台可接收的服务器 |

PHP 低于 8.5 时安装程序退出码为 5，状态记为 BLOCKED，不会改用 8.3。
