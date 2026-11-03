# 这次 R2 生产接入做了什么

时间：2026-11-02 20:07:58Z 至约 20:16Z。站点是 https://gitxx.xyz，Drupal 11，模块 `xedc_core`。对象存储供应商是 `silly`，Bucket 是 `dclili`，公开 CDN 是 https://cdn.gitxx.xyz。公开对象前缀 `s3fs-public/`，私有对象前缀 `s3fs-private/`。

这次任务实际执行了生产接入，不是只写方案。结果是一部分完成：128 个历史公开文件已经复制到 R2 并校验通过。网站页面仍从本地磁盘读文件。Public 接管、Private 接管、CDN 地址改写都没有打开。

总评：**PARTIAL**。网站可以继续按现在的本地存储运行。

## 这次留下的文件

| 文件 | 内容 |
| --- | --- |
| `docs/r2-production-integration.md` | 本说明。读这一份就能知道这次做了什么、为什么停、现在能不能用。 |
| `docs/r2-public-migration-manifest.csv` | 128 行迁移清单。每行有 fid、字节、状态、SHA-256、对象键和原来的 URI。没有文件正文，也没有凭证。 |
| `docs/r2-public-migration-report.md` | 公开文件复制和 CDN 抽样读取的详细结果。 |
| `docs/r2-production-integration-report.md` | 按任务要求的 20 项写出的接入报告，含各阶段通过标记。 |
| `docs/r2-production-rollback-runbook.md` | 以后如果打开接管，怎样关回去。本轮没有需要撤销的开关。 |

更早的 CDN 复测在 `docs/r2-cdn-final-retest-report.md`。复制前又做了一次新的抽查，没有只凭那份旧报告放行。

## 结论标记

| 标记 | 结果 | 含义 |
| --- | --- | --- |
| PUBLIC_MIGRATION_PASS | 通过 | 128 个公开文件已在 R2，哈希、大小、类型都对。 |
| PUBLIC_TAKEOVER_PASS | 未通过 | 没有开启。单独开启会让图片地址变成 R2 API 地址。 |
| PRIVATE_RECOVERY_PASS | 未通过 | 9 个私有 ZIP 找不到真实文件。 |
| PRIVATE_TAKEOVER_PASS | 未通过 | 没有开启。 |
| CDN_REWRITE_PASS | 未通过 | 没有开启。页面 HTML 仍是本站地址。 |
| FULL_INTEGRATION_PASS | 未通过 | 上面几项没有全部完成。 |
| 总评 | PARTIAL | 复制完成，接管停在安全门禁前。 |

## 现在网站怎么存文件

五个生产变量都没有写进 PHP-FPM：

- `XEDC_STORAGE_RUNTIME`
- `STORAGE_I_UNDERSTAND_TAKEOVER`
- `STORAGE_PUBLIC_TAKEOVER`
- `STORAGE_PRIVATE_TAKEOVER`
- `STORAGE_CDN_ENABLED`

BootGuard 是 `boot=ok`，没有请求运行时，也没有应用运行时，`runtime_error` 为空。

`public://` 仍是 Drupal 本地 `PublicStream`，文件在 `sites/default/files`。`private://` 仍是本地 `PrivateStream`。S3FS 的 public/private takeover 都是关的。活动供应商仍是 `silly`。PHP-FPM 和 Nginx 正常。没有重载 PHP-FPM，没有改 `config/sync`，没有改数据库里的文件 URI，没有执行 git 提交。

已接管的功能：没有。

仍在本地的功能：公开读写、私有读写、图片样式、页面上的文件地址、ZIP 导入用的工作目录。

R2 上多出来的，是 128 个公开文件的副本。前台还没有改用这些副本。

## 阶段 A：复制前检查

通过，然后才开始复制。

- 数据库能查，首页返回 200，PHP-FPM 和 Nginx 都是 active。
- Bucket `dclili` 的 HeadBucket 成功。
- 新放上去的一张测试 PNG，源站和 CDN 的 Cache-Control 都是 `public, max-age=60`，正文一致。
- 新的私有前缀测试对象，匿名访问返回 403，响应里没有测试标记。
- 这两个测试对象在复制历史文件之前已删除。
- 当时远程 `s3fs-public/` 和 `s3fs-private/` 还没有这批历史对象。
- R2.dev 公网开发地址只记为管理员确认已禁用，程序没有单独证明。

## 阶段 B：128 个公开文件

通过。任务号 `mst_c515c90f`。

复制规则是：原 URI 保持 `public://...` 不变，R2 对象键为 `s3fs-public/` 加上该相对路径。例如 `public://2026-10/IMG_3009.jpeg` 对应 `s3fs-public/2026-10/IMG_3009.jpeg`。

每批最多 15 个。目标键已存在时不覆盖；本轮这些键原先都不存在，所以没有冲突。本地文件不存在就不会上传；这 128 个都在。

| 项目 | 结果 |
| --- | --- |
| 已验证 | 128 |
| 失败 / 冲突 / 缺文件 | 0 |
| 总字节 | 75,177,632 |
| 再次下载后的 SHA-256 | 128 个一致 |
| Content-Type | 与数据库 MIME 一致 |
| Cache-Control | 全部 `public, max-age=3600` |
| 远程公开对象 | 128 |
| 远程私有对象 | 0 |
| 远程图片样式对象 | 0 |

这些历史文件名里没有内容哈希，所以缓存是 1 小时，不是一年 immutable。复制前后数据库指纹相同：137 行文件，fid 合计 28272，URI CRC32 合计 300810938969。128 个本地原件都还在。

## 阶段 C：读到的和没读到的

直接用 CDN 地址读取，不经过 Drupal 接管，结果如下。

| 样本 | 结果 |
| --- | --- |
| `2026-10/IMG_3009.jpeg` | 200，JPEG，163,198 字节，SHA-256 一致。第二次 GET 为缓存 HIT，Age 26。浏览器能显示这张图。 |
| `2026-10/IMG_1204.png` | 200，PNG，SHA-256 一致。 |
| 一张 2026-10 WebP | 200，SHA-256 一致。 |
| `pictures/IMG_2664.jpeg` | 200，另一个目录。 |
| `2026-11/n195-001.jpg` | 200，另一个月份。 |
| fid 5 历史 H.264 视频 | 200，`video/mp4`，SHA-256 一致。`Range: bytes=0-15` 返回 206，范围是 `bytes 0-15/2255011`。无头 Chrome 识别为 720x1280，时长 12.72 秒。 |
| fid 86 历史视频 | CDN 能读到，时长 5.16 秒。编码是 HEVC。当前无头 Chrome 没有解出画面。 |

历史文件里没有 AVIF 原图。现有 AVIF 是图片样式生成的衍生文件，还在本地。把衍生路径放到 CDN 前缀下会 404，因为没有复制这批样式。本地样式地址仍然 200。

图片样式：15 个目录，磁盘上当时数到 768 个衍生文件。R2 上是 0。用真实图片 `public://pictures/IMG_2664.jpeg` 和样式 `max_325x325` 在临时目录生成过 8,965 字节的 AVIF，确认生成程序能读这张原图。临时文件随后删除，没有写入公开目录，也没有写入 R2。

ZIP：只在 `/tmp` 做了隔离测试。带 `../` 的压缩包被拒绝，没有把文件写到目标目录外面。正常的 `ok/a.png` 能解开。测试目录已删除。没有把测试包导入成前台内容，所以完整的上传、入库、文件实体流程不能算通过。

## 为什么没有打开 Public 接管

任务要求先开 Public，并保持 CDN 改写关闭。这个顺序在当前代码里不安全，所以停在门禁前，没有改 PHP-FPM。

S3FS 接管 `public://` 之后，文件对外地址由 S3 流包装器生成。CDN CNAME 关闭时，这个地址指向 R2 API 主机：

`409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`

路径形如 `/dclili/s3fs-public/...`。匿名请求这个地址返回 400，不能当网站图片地址用。若这时重建 Drupal 容器，页面上的旧图片和视频会离开 `/sites/default/files/`，本地 Nginx 也就不再负责返回它们。

还有第二层限制。现在的 Drupal 容器是在接管关闭时编译的，`public://` 的实现类被编译成了本地 `PublicStream`。只往 PHP-FPM 写入环境变量、不在 FPM 请求里重建容器，BootGuard 可以显示“已应用”，但实际上传仍会写到本地磁盘。用 CLI 临时变量做过一次模拟，没有写入池配置，也没有重载 FPM：BootGuard 报告运行时已应用、没有错误，生成出来的地址却仍是 `/sites/default/files/...`。

因此“变量设成 1”不等于接管成功。在 CDN 地址和图片样式都准备好之前，不能单独打开 Public 接管。

Nginx 现在对 `/sites/default/files/` 和 `/sites/default/files/styles/` 使用 `try_files`。本地文件还在时，本站继续返回它们。首页抽查到的一张 WebP 样式返回 200，长度 78,698 字节。这个结果只说明接管前的本地访问正常。

## Private 和 CDN 为什么也没开

Private 接管的前提是 9 条记录都有真实文件，或者管理员明确废弃其中某些记录。这次两条都不成立。

这 9 条都是 `application/zip`，状态为 1，本地文件缺失，R2 私有前缀对象数是 0。在项目目录、`/var/backups`、`/root`、`/tmp` 没有找到同名 ZIP。没有制造空文件，也没有把缺失记成已恢复。

| FID | 文件名 | 记录大小 |
| --- | --- | --- |
| 166 | member-2707556.zip | 5047 |
| 219 | member-2707995.zip | 5047 |
| 272 | member-2718523.zip | 5047 |
| 326 | member-2726783.zip | 5047 |
| 379 | member-2727113.zip | 5047 |
| 481 | member-2806776.zip | 5047 |
| 491 | member-2807293.zip | 5039 |
| 505 | member-2807605.zip | 5035 |
| 520 | member-2807749.zip | 5037 |

CDN 改写要等 Public 接管已经成功、真实对象也在 R2 上之后再开。对象已经在 R2 上，但接管没有成功，所以 `STORAGE_CDN_ENABLED` 保持未设置。首页 HTML 里没有 `cdn.gitxx.xyz`，也没有 R2 API 主机名。直接访问 CDN 对象地址能读到已复制的文件，那是对象本身的地址，不是 Drupal 把页面链接改写过去。

会员权限、预签名 URL、私有下载都没有在接管状态下测试，不能记为通过。

## 回滚

本轮没有打开任何接管开关，所以没有做“关掉开关再重载”的动作。R2 上那 128 个对象保留，不因为停在门禁前就删除。

以后如果打开过接管，回滚顺序写在 `docs/r2-production-rollback-runbook.md`：先关 CDN，再关 Private，再关 Public，再关确认开关，最后关 Runtime，然后重载 PHP-FPM。若当时重建过容器，必须在没有这些变量的 PHP-FPM 请求里再重建一次。只在 SSH 里执行 `drush cr` 会按 CLI 环境编译容器，不能代替 FPM。回滚时不删除已校验的历史副本。数据库基线仍是 137 行、fid 合计 28272、URI CRC32 合计 300810938969。

## 站点抽查里看到的另一个问题

首页、`/cosplay`、搜索、登录、注册返回 200。

`/cosplay/cos/315` 返回 500。日志是 `EngagementBuilder::__construct()` 只收到 5 个参数，当前代码构造函数有 6 个参数。这是已编译容器和当前服务定义不一致，不是这次文件复制造成的。这次没有为了接入去重建容器，也没有改互动模块。

## 还没做、也不能假装做过的事

- 没有把新上传的图片或视频写进 R2。本轮没有新增生产文件。
- 没有迁移或批量重生成图片样式。
- 没有把页面链接改成 `https://cdn.gitxx.xyz/...`。
- 没有恢复 9 个私有 ZIP，没有打开私有接管。
- 没有做完整的 ZIP 入库回归、会员下载和预签名过期测试。
- 没有做接管之后的全站回归。接管前的本地页面抽查不能代替那一项。

要继续的话，需要同时满足两件事，然后再开 Public：对外地址使用 `https://cdn.gitxx.xyz`，不能使用 R2 API 主机；图片样式要么先复制到 R2，要么确认接管后能按需生成并且不会让现有页面大面积失效。私有文件在找到真实字节或得到明确废弃决定之前，继续保持关闭。
