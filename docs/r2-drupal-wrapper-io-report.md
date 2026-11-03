# Drupal Stream Wrapper 真实读写

验收时间：2026-11-02。写入只发生在一个 CLI 进程里，URI 前缀是 `public://xedc-r2-isolated-test/`。生产 PHP-FPM 没有注册这个包装器。

## 结论

| 项目 | 结果 |
| --- | --- |
| CLI 中通过 Drupal 文件 API 写入 R2 | PASS |
| Drupal 文件 API 读回并核对 SHA-256 | PASS |
| stat、MIME、目录列表、rename | PASS |
| Drupal 删除后 R2 对象消失 | PASS |
| 测试前缀无残留 | PASS |
| 图片样式写入留在本地且没有上传 | PASS |
| PHP-FPM 进程内的同一测试 | NOT TESTED |

总结果不是生产接管。PHP 流在这个 CLI 进程里临时注册为 `XedcPublicS3fsStream`，进程结束即消失。随后把该进程的 `public://` 注册回 `PublicStream`。生产容器类没有变。

## 父类需要覆盖的方法

父类是 `Drupal\s3fs\StreamWrapper\PublicS3fsStream`。图片样式要留在本地，所以只覆盖会碰到路径的方法：`getExternalUrl`、`stream_open`、`stream_read`、`stream_write`、`stream_eof`、`stream_seek`、`stream_flush`、`stream_tell`、`stream_stat`、`stream_close`、`unlink`、`rename`、`mkdir`、`rmdir`、`url_stat`、`dir_opendir`、`dir_readdir`、`dir_rewinddir`、`dir_closedir`。

目标以 `styles/` 开头时，这些方法委托给新的 `PublicStream` 实例。`PublicStream` 使用本地目录，不走 `public://` 注册表，因此不会递归。其余目标调用父类，父类写入 R2。样式和非样式之间的 rename 直接返回失败。

`dir_rewinddir()` 必须声明 `: bool`，与 `S3fsStream` 一致。其余覆盖方法的父签名没有返回类型。没有把父类全部方法复制一遍。

## 执行过程

进程环境只含 `XEDC_STORAGE_RUNTIME=1`、`STORAGE_I_UNDERSTAND_TAKEOVER=1`、`STORAGE_PUBLIC_TAKEOVER=1`。私有接管和 CDN 都是 `0`。这些变量没有写入 PHP-FPM。

`BootGuard` 在该进程接受配置：`settings_accepted` 为真，`runtime_applied` 为假，私有接管和 CDN 都为假。然后在内存中编译容器，确认类名后丢弃 builder。

Drupal 调用是 `file_system->prepareDirectory()`、`saveData()`、`move()`、`delete()`。没有 `File::create()`。`file_managed` 前后都是 137 行。

对象键是 `s3fs-public/xedc-r2-isolated-test/<时间戳>/pixel-renamed.png`。`xedc_core.storage_reader` 的 Head 显示字节数和 `image/png` 与本地内容一致，GetObject 的 SHA-256 与写入字节一致。删除后 Head 为空。`ListObjectsV2` 在 `s3fs-public/xedc-r2-isolated-test/` 下没有剩余键。

样式探针 `public://styles/_xedc_r2_style_probe/pixel.png` 的真实路径在本地样式目录内。对应的 R2 键 `s3fs-public/styles/_xedc_r2_style_probe/pixel.png` Head 为空。探针文件和目录已删除。

S3FS 的 `mkdir` 和写入会向 `s3fs_file` 插入元数据。测试结束后只删除 `public://xedc-r2-isolated-test%` 和 `public://styles/_xedc_r2_style_probe%`。结束后前者的行数是 0。没有改 `file_managed`，没有改 9 条 private ZIP。

## 失败过的两次尝试

第一次编译因 `dir_rewinddir` 签名致命，没有发出对象写入。修复后重跑。

第二次 `saveData()` 因目录尚未创建而失败，没有对象。补上 `prepareDirectory()` 之后第三次全部通过：`RESULT PASS failures=0`。

## 不能外推的部分

这次读写证明的是：在 CLI 进程里替换 PHP 的 `public://` 注册之后，Drupal 文件 API 可以经 `XedcPublicS3fsStream` 创建、读取、重命名和删除 R2 对象，并把样式留在本地。

它没有证明 PHP-FPM 的任何一个 worker 正在使用这个类。生产请求仍然走 `PublicStream`。
