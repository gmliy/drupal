# SSL 手册

当前生产证书由 Certbot 管理，路径在 `/etc/letsencrypt/live/gitxx.xyz/`。本任务不会修改这份证书，也不会改生产 Nginx。

## 演练证书

在隔离目录生成 30 天自签证书：

```bash
python3 tools/xedc-recover/bin/xedc-recover ssl self-sign \
  --domain lab.xedc.test --out-dir /tmp/xedc-certs
```

生成配置、测试、失败回滚：

```bash
python3 tools/xedc-recover/bin/xedc-recover ssl render \
  --server-name lab.xedc.test \
  --webroot /srv/xedc/code/web \
  --prefix /tmp/xedc-nginx \
  --port 80 \
  --cert /tmp/xedc-certs/fullchain.pem \
  --key /tmp/xedc-certs/privkey.pem \
  --output /tmp/xedc-nginx/nginx.conf

python3 tools/xedc-recover/bin/xedc-recover ssl test \
  --config /tmp/xedc-nginx/nginx.conf \
  --prefix /tmp/xedc-nginx
```

`ssl render` 会先把已有文件复制为 `.bak`。测试失败时：

```bash
python3 tools/xedc-recover/bin/xedc-recover ssl rollback --config /tmp/xedc-nginx/nginx.conf
python3 tools/xedc-recover/bin/xedc-recover ssl test --config /tmp/xedc-nginx/nginx.conf --prefix /tmp/xedc-nginx
```

只有 `nginx -t` 成功后，才在新机器上执行 `nginx -s reload`。生产主机上的 `/etc/nginx` 写入会被拒绝。

查看到期时间：

```bash
python3 tools/xedc-recover/bin/xedc-recover ssl expiry --cert /tmp/xedc-certs/fullchain.pem
```

## 正式证书

不要在演练中申请正式 Let's Encrypt 证书。先看计划，它不会执行：

```bash
python3 tools/xedc-recover/bin/xedc-recover ssl plan \
  --domain gitxx.xyz \
  --webroot /var/www/drupal/web \
  --email you@example.com
```

计划使用 `certbot certonly --webroot --staging`。确认域名已经指向新服务器、80 端口可从公网访问后，再由管理员手工去掉 `--staging` 申请正式证书。DNS-01 需要单独的最小权限 API 令牌，令牌只放在 Certbot 的凭据文件里，不能进 Git 或日志。

续期沿用系统已有的 `certbot.timer`。新机器安装 Certbot 后执行：

```bash
systemctl enable --now certbot.timer
```

续期失败时查看 `systemctl status certbot.timer` 和 `/var/log/letsencrypt/letsencrypt.log`。不要为了“修复”而改掉仍在提供服务的旧证书。
