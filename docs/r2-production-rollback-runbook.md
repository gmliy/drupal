# R2 生产回滚手册

本轮没有开启接管，也没有修改 PHP-FPM 环境变量。下面是以后真正开启后的回滚顺序。回滚不删除已经复制到 R2 的 128 个公开对象。

## 当前状态

PHP-FPM 工作进程环境里没有这些变量：

- `XEDC_STORAGE_RUNTIME`
- `STORAGE_I_UNDERSTAND_TAKEOVER`
- `STORAGE_PUBLIC_TAKEOVER`
- `STORAGE_PRIVATE_TAKEOVER`
- `STORAGE_CDN_ENABLED`

`public://` 使用 `Drupal\Core\StreamWrapper\PublicStream`。`private://` 使用 `Drupal\Core\StreamWrapper\PrivateStream`。BootGuard：`boot=ok`，`runtime_requested=no`，`runtime_applied=no`，`runtime_error` 为空。

## 回滚顺序

只改 PHP-FPM 池环境，不改 S3FS 数据库配置，不改 `config/sync`。

1. 从 PHP-FPM 池配置去掉 `STORAGE_CDN_ENABLED`。
2. 去掉 `STORAGE_PRIVATE_TAKEOVER`。
3. 去掉 `STORAGE_PUBLIC_TAKEOVER`。
4. 去掉 `STORAGE_I_UNDERSTAND_TAKEOVER`。
5. 去掉 `XEDC_STORAGE_RUNTIME`。
6. `php-fpm8.5 -t` 通过后执行 `systemctl reload php8.5-fpm`。
7. 确认新的 worker 环境里不再出现上述变量。
8. 如果接管期间重建过 Drupal 容器，再在没有这些环境变量的 PHP-FPM 请求中重建一次容器。只在 CLI 里执行 `drush cr` 会按 CLI 环境编译容器，不能代替 FPM。
9. 确认 `public://` 回到 `PublicStream`，`private://` 回到 `PrivateStream`。
10. 确认 BootGuard `runtime_applied=no`，`runtime_error` 为空。
11. 打开首页和一张已知图片，确认地址仍是 `/sites/default/files/...`，HTTP 200。
12. 确认 `file_managed` 行数、fid 合计和 URI CRC32 与接管前一致。本轮基线是 137、28272、300810938969。

不要因为回滚删除 `s3fs-public/` 下已校验的 128 个对象。接管之后新出现的 R2 对象，先核对本地是否还有副本、数据库是否仍引用，再决定是否保留。

## 本轮验证

没有需要撤销的开关。复核结果：

- 首页 200，HTML 不含 `cdn.gitxx.xyz`，也不含 R2 API 主机名。
- 首页图片样式仍由本站返回 200，样本为 WebP，78,698 字节。
- 128 个本地公开文件都还在。
- 数据库 URI 指纹未变。
