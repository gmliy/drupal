# 真实新服务器核对

下面这些不能在 Docker 里标成通过。Docker 没有 systemd 主进程，申请不到公网证书，MinIO 不是 Cloudflare R2，脚本喂给伪终端的输入也不是人手在 tmux 里操作。

- systemd 的 enable、daemon-reload 和开机自启
- Let's Encrypt 正式证书
- 向真实 Cloudflare R2 第一次上传不出现 501
- 亲手在 SSH 里输入凭据后 `tmux attach -t xedc-restore`，断线再接上

在一台全新 VPS 上亲手做一遍：

1. 用 Ubuntu 24.04 或 Debian 12 的空机器，root 登录。
2. DNS 的 A 记录先指到这台机器。Cloudflare 橙云开启时，SSL 模式用 Full (strict)。
3. 按 README 的一条命令恢复。中途只做两件事：输入 R2 凭据（Secret 不回显），看到软件清单后输入 `y`。
4. 断线后执行 `tmux attach -t xedc-restore`。失败后用原来的命令加上 `--resume`。
5. 浏览器打开首页、`/user/login`、`/admin`、`/models`、`/articles`、`/sitemap.xml`，确认是正式证书而不是自签。
6. `drush status` 能看数据库和文件路径。Redis `PONG`。`ffmpeg -encoders` 有 libx264。
7. 上传一个短视频，确认转码队列会动，页面能播放。
8. 打开 Netdata 并重新 claim。旧令牌不会带过来。
9. 看 `/var/log/xedc-backup.log` 是否有定时备份。邮件、SPF/DKIM、Google 和 Telegram 回调要按恢复报告逐项改。
10. 确认 R2 桶是私有的，媒体对象仍在原来的桶里，不在备份包里。
