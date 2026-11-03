# R2 生产接入报告

接入窗口：2026-11-02 20:07:58Z 至 20:16Z。站点仍使用本地文件系统对外服务。128 个历史公开文件已复制到 R2 并完成校验。Public 接管、Private 接管和 CDN URL 改写都没有开启。

## 结论

| 标记 | 结果 |
| --- | --- |
| PUBLIC_MIGRATION_PASS | 是 |
| PUBLIC_TAKEOVER_PASS | 否，未开启 |
| PRIVATE_RECOVERY_PASS | 否 |
| PRIVATE_TAKEOVER_PASS | 否，未开启 |
| CDN_REWRITE_PASS | 否，未开启 |
| FULL_INTEGRATION_PASS | 否 |
| 总评 | PARTIAL |

网站可以继续按现在的本地存储运行。R2 上的 128 个对象是副本，前台还没有改用它们。

## 1. 生产接入时间

复制开始 2026-11-02T20:07:58Z，复制和二次 SHA-256 结束 2026-11-02T20:10:03Z。之后完成 CDN 读取、视频 Range、浏览器显示和接管前检查。没有重载 PHP-FPM。

## 2–5. 迁移

- 对象数量：128。
- 总大小：75,177,632 字节。
- SHA-256：上传校验 128，随后重新下载正文再计算 128，不一致 0。
- 失败对象：无。
- 清单：`docs/r2-public-migration-manifest.csv`。
- 迁移说明：`docs/r2-public-migration-report.md`。

`file_managed` 仍是 137 行。fid 合计 28272。URI CRC32 合计 300810938969。没有把 `public://` 改成 `s3://`。

## 6. Public 接管

未开启。PHP-FPM 中 `STORAGE_PUBLIC_TAKEOVER` 不存在。

停止原因：S3FS 在 Public 接管并且 CDN CNAME 关闭时，会把 `public://` 的对外地址生成到 R2 API 主机 `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`，路径形如 `/dclili/s3fs-public/...`。对该地址做匿名请求返回 400，不能代替现在的网站图片地址。若只打开 Public 接管并重建容器，已有图片和视频链接会离开 `/sites/default/files/`，造成大面积打不开。按门禁，没有进入“先打开再观察”的步骤。

另外，现有容器是在接管关闭时编译的。只给 PHP-FPM 加上环境变量、不在 FPM 请求里重建容器，stream wrapper 仍会是本地 `PublicStream`，新文件不会写入 R2。因此不能把“变量已设置”当成接管成功。

## 7–8. Private 文件

9 条 `private://` 记录的本地文件都不存在，R2 `s3fs-private/` 对象数是 0。在项目目录、`/var/backups`、`/root` 和 `/tmp` 中没有找到这些 ZIP。没有创建空文件，也没有把它们标成已恢复。

| FID | 文件名 | 类型 | 记录大小 | 本地 |
| --- | --- | --- | --- | --- |
| 166 | member-2707556.zip | application/zip | 5047 | 缺失 |
| 219 | member-2707995.zip | application/zip | 5047 | 缺失 |
| 272 | member-2718523.zip | application/zip | 5047 | 缺失 |
| 326 | member-2726783.zip | application/zip | 5047 | 缺失 |
| 379 | member-2727113.zip | application/zip | 5047 | 缺失 |
| 481 | member-2806776.zip | application/zip | 5047 | 缺失 |
| 491 | member-2807293.zip | application/zip | 5039 | 缺失 |
| 505 | member-2807605.zip | application/zip | 5035 | 缺失 |
| 520 | member-2807749.zip | application/zip | 5037 | 缺失 |

`STORAGE_PRIVATE_TAKEOVER` 保持关闭。会员权限、预签名 URL 和私有下载没有在接管状态下测试，不能记为通过。

## 9. CDN 改写

未开启。首页 HTML 没有 `cdn.gitxx.xyz`。直接访问已复制对象的 CDN 地址可以读到文件，但这是对象地址，不是 Drupal 页面改写。

## 10–12. 图片、视频、样式

公开原图和历史视频的 CDN 读取见迁移报告。浏览器能显示 CDN 上的 JPEG，也能识别历史 H.264 视频的 720x1280 画面和 12.72 秒时长。

图片样式：15 个样式目录，当前磁盘上 768 个衍生文件。R2 上样式对象为 0。本地样式 URL 返回 200；同一路径放到 CDN 前缀下返回 404。用真实图片 `public://pictures/IMG_2664.jpeg` 和现有样式 `max_325x325` 在临时目录生成了 8,965 字节的 AVIF，读完后已删除，没有写入 R2，也没有改公开目录。

历史原图中没有 AVIF。现有 AVIF 都是本地衍生文件。

在接管前，Nginx 对 `/sites/default/files/` 和 `/sites/default/files/styles/` 使用 `try_files`，本地文件还在时继续由本站返回。接管并重建容器后，新的页面地址不再走这些本地 URL，所以上面的本地 200 不能证明接管后的样式仍然可用。安全做法是先迁移或可重复生成这批衍生文件，并且让对外地址使用 `https://cdn.gitxx.xyz`，而不是 R2 API 主机。这一步还没有做。

## 13. ZIP

在 `/tmp` 做了隔离测试，没有写入 `public://`、`private://` 或文件实体。包含 `../` 的 ZIP 被拒绝，没有写出目标目录之外的文件。正常条目 `ok/a.png` 解压成功，临时目录已删除。

没有把测试 ZIP 导入成前台内容，因此完整的上传、私有工作目录、图片入库和文件实体流程不能记为通过。Private 接管保持关闭时，现有导入代码的私有工作目录仍会走本地 `private://`。

## 14. 会员和私有权限

未做接管后的权限测试。9 个私有 ZIP 没有可恢复的字节。

## 15–16. BootGuard 和 PHP-FPM

当前 Web 和 CLI 在未设置接管变量时：

- `boot=ok`
- `runtime_requested=no`
- `runtime_applied=no`
- `runtime_error` 为空
- `s3fs.use_s3_for_public=false`
- `s3fs.use_s3_for_private=false`

PHP-FPM 和 Nginx 均为 active。抽查的 FPM worker 环境中没有五个生产变量。活动供应商仍是 `silly`。

用 CLI 临时环境做过一次不重载 FPM 的模拟：三个接管变量为 1、Private 和 CDN 显式为 0 时，BootGuard 会报告 `runtime_applied=yes` 且没有 `runtime_error`。同一进程里的容器仍是旧的 `PublicStream`，生成的地址还是 `/sites/default/files/...`。这次模拟没有写入池配置。

## 17–18. 本地文件和 URI

128 个已复制公开文件的本地副本都在。数据库 URI 未变。

## 19. 回滚

没有打开过开关，所以没有执行关闭开关的回滚。当前本地访问已经复核，见 `docs/r2-production-rollback-runbook.md`。R2 上的 128 个对象保留。

## 20. 尚未解决

- Public 接管不能在 CDN 关闭时单独开启，否则对外地址会变成不可匿名读取的 R2 API 地址。
- 图片样式衍生文件还没有复制，CDN 上对应对象是 404。
- 9 个私有 ZIP 没有找到源字节。
- 完整 ZIP 入库、新上传落入 R2、会员预签名和全站接管后回归都还没有执行。
- 内容页 `/cosplay/cos/315` 返回 500。日志是 `EngagementBuilder::__construct()` 只收到 5 个参数，而当前代码构造函数有 6 个参数。这与本次文件复制无关，容器也没有为了这次任务重建。首页、`/cosplay`、搜索、登录和注册返回 200。

## 阶段 A

复制前的即时检查通过，没有沿用旧报告单独放行：

- 数据库可查询，站点首页 200，PHP-FPM 和 Nginx 为 active。
- 活动供应商 `silly`，Bucket `dclili`，HeadBucket 成功。
- 新测试 PNG 的源站和 CDN Cache-Control 都是 `public, max-age=60`，正文一致。
- 新的私有前缀对象匿名返回 403，没有泄露标记。
- 五个生产变量在 FPM 中未设置。
- `public://` 为本地 PublicStream。
- BootGuard 无 `runtime_error`。
- R2.dev 仍只记为管理员确认禁用，程序没有单独证明。

## 五个变量和实际存储

| 变量 | PHP-FPM |
| --- | --- |
| `XEDC_STORAGE_RUNTIME` | 未设置 |
| `STORAGE_I_UNDERSTAND_TAKEOVER` | 未设置 |
| `STORAGE_PUBLIC_TAKEOVER` | 未设置 |
| `STORAGE_PRIVATE_TAKEOVER` | 未设置 |
| `STORAGE_CDN_ENABLED` | 未设置 |

`public://`：`Drupal\Core\StreamWrapper\PublicStream`，本地 `sites/default/files`。

`private://`：`Drupal\Core\StreamWrapper\PrivateStream`，本地私有目录。

本轮没有新的生产图片或视频上传。已有公开文件仍在本地，同时在 R2 有一份已校验副本。私有文件仍只有数据库记录。

已接管：无。

仍在本地：公开读写、私有读写、图片样式、页面 URL、ZIP 工作目录。
