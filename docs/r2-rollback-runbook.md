# R2 接管回滚手册

本轮没有打开生产开关，也没有重载 PHP-FPM。网站现在就在本地包装器上。回滚不删除 R2 上已校验的 128 个公开对象，不删除本地原文件，不改文件 URI。

## 如果只部署了这次代码、还没有接管

`hook_file_url_alter()` 在包装器仍是 `PublicStream` 时不会改地址。确认方法：打开首页，文件地址仍以 `/sites/default/files/` 开头，HTML 里没有 R2 API 主机。不需要为这个状态重载 PHP-FPM。

## 如果将来已经打开 Public 接管

按这个顺序退回，不要先删对象。

1. 从 PHP-FPM 池去掉 `STORAGE_CDN_ENABLED`。
2. 去掉 `STORAGE_PRIVATE_TAKEOVER`。
3. 去掉 `STORAGE_PUBLIC_TAKEOVER`。
4. 去掉 `STORAGE_I_UNDERSTAND_TAKEOVER`。
5. 去掉 `XEDC_STORAGE_RUNTIME`。
6. `php-fpm8.5 -t` 通过后 `systemctl reload php8.5-fpm`。
7. 用一个新的 PHP-FPM 请求重新编译容器。没有这些变量的 CLI `drush cr` 不能代表 FPM。
8. 确认 `public://` 是 `Drupal\Core\StreamWrapper\PublicStream`，`private://` 是 `PrivateStream`。
9. 确认 BootGuard `runtime_error` 为空，启用检查页的 Public takeover 为“未接管”。
10. 打开首页和一张已知图片，地址回到 `/sites/default/files/...` 且 HTTP 200。
11. 核对 `file_managed` 仍是 137 行、fid 合计 28272、URI CRC32 合计 300810938969。

接管期间新出现的 R2 对象先核对本地是否还有副本、数据库是否引用，再决定是否保留。测试前缀 `_xedc_storage_test/` 里由当次测试创建的对象可以删除。`s3fs-public/` 下那 128 个历史对象不删除。

更早的操作记录在 `docs/r2-production-rollback-runbook.md`。那次同样没有打开开关。
