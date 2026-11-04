# XEDC 上传系统修复报告

日期：2026-11-04。站点 https://gitxx.xyz。Drupal 11.4.11。模块 `xedc_core`、`xedc_quick`。页面 `/admin/content/image-imports` 和 `/admin/quick-gallery`。

没有提交，没有推送。没有改 `config/sync`、Drupal 核心、第三方模块、Nginx、PHP 配置、R2 Bucket、密钥、前缀、CDN 或存储模式。没有新建第二个上传系统或第二个导入 worker。

状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。

## 原因

| 现象 | 原因 | 状态 |
| --- | --- | --- |
| 大文件 HTTP 500 | 2026-11-03 Nginx 不能写 `client_temp` / `fastcgi_temp`。目录现为 `www-data` 可写。本次错误日志没有新的拒绝记录。 | FIXED |
| 一次选择出现两行 | 页面先画本地「等待上传」，会话接口再插入一行，恢复列表又画同一会话。 | FIXED |
| 相同图片仍整文件上传 | 去重发生在导入之后，上传前没有查询。 | FIXED |
| 相同图片共用一个媒体 | 旧逻辑复用媒体，两篇内容会共用 ALT。 | FIXED |
| 容量显示成 MB | 部分页面用了 Drupal 的十进制 `ByteSizeMarkup`。 | FIXED |

## 数据

`xedc_core` schema 从 10015 升到 10016。

- `xedc_upload_session.client_upload_id`、`reimport`
- 索引 `uid_client`（uid, client_upload_id）
- 新表 `xedc_blob_claim`，主键是 SHA-256

已有图片、媒体和导入行没有被改写。测试产生的 claim 已删掉，表在验收后是空的。

## 路由

上传中心仍用原来的会话接口：

- `POST /admin/content/image-imports/upload/probe`
- `POST /admin/content/image-imports/upload/session`
- `GET /admin/content/image-imports/upload/sessions`
- `POST /admin/content/image-imports/upload/session/{id}/chunk/{index}`
- `POST /admin/content/image-imports/upload/session/{id}/complete`
- `POST /admin/content/image-imports/upload/session/{id}/cancel`

快速发布增加了同样的会话路径，前缀是 `/admin/quick-gallery/upload/`，另有 `POST /admin/quick-gallery/reuse`。权限仍是 `administer nodes`。整文件 `POST /admin/quick-gallery/upload` 保留，分块失败时才用它。

写接口要登录和 CSRF。会话只返回给本人。探测不接受客户端传来的 fid。

## 行为

- 一次选择生成 32 位 `client_upload_id`。同一标识再次打开会话时返回原来的会话。
- 两次选择即使内容相同，也是两次投稿。
- 默认分块 5 MiB。合并按流计算 SHA-256。浏览器哈希失败时仍上传，由服务器校验。
- 图片复用：新的媒体指向已有文件，状态成功并标记已复用。压缩包内部重复仍记为 duplicate，不重复挂到同一篇。
- 同一用户的成功压缩包：返回原任务，不重新上传、不重新解压。失败且压缩包还在：重试原任务。
- 其他用户的成功压缩包：不返回对方的任务号。健康图片会克隆到当前用户的新任务；否则继续上传，并在写入对象时去重。
- 存储对象不存在、大小不符或哈希不符时必须重新上传。
- 删除一篇内容不会删除另一篇仍在使用的文件。
- 容量使用 1024 进位的 B、KiB、MiB、GiB、TiB、PiB。数据库和接口仍是整数字节。
- 界面状态是简体中文。接口里的状态值仍是英文。不认识的状态显示「未知状态」。
- 上传中心用现有后台主题变量，包含统计、拖放、当前任务和中文操作。历史仍用原来的导入任务表，支持筛选和分页。

## 覆盖

| 入口 | 分块 | 断点续传 | SHA-256 | 复用对象 | 不重复记录 | 中文 |
| --- | --- | --- | --- | --- | --- | --- |
| 普通图片 | PASS | PASS（脚本） | PASS | PASS | PASS | PASS |
| 批量图片 | PASS | PASS（同一脚本） | PASS | PASS | PASS | PASS |
| 快速发布 | NOT TESTED（浏览器） | NOT TESTED（浏览器） | PASS（脚本） | PASS（脚本，新媒体） | NOT TESTED（浏览器） | NOT TESTED（浏览器） |
| ZIP | PASS | PASS（脚本缺块） | PASS | PASS | PASS | PASS |
| 7z | PASS（脚本） | PASS（脚本） | PASS（脚本） | NOT TESTED | NOT TESTED（浏览器） | NOT TESTED（浏览器） |

## 验收

| 项 | 状态 |
| --- | --- |
| 去重自测 | PASS，12/12 |
| 断点续传自测 | PASS，23/23。缺块返回 409，补传字节等于未确认部分。 |
| 图片第二阶段 | PASS，19/19。重复图片媒体不同、文件相同。 |
| 浏览器上传一张图片 | PASS。733 B，一行，「排队等待处理」。 |
| 浏览器重复图片 | PASS。「文件已存在，已复用」，会话数 0 到 0。 |
| 浏览器重复 ZIP | PASS。同上。 |
| 浏览器一次选择两行 | PASS。没有出现第二行。 |
| 手机 390 宽 | PASS。没有横向溢出。 |
| 深色 | PASS。上传区背景 `rgb(32, 32, 36)`。 |
| 删除一篇后文件仍可读 | PASS。测试文件随后删除。 |
| 缺失对象不算重复 | PASS。 |
| 其他用户不能读会话 | PASS。404 和 403。 |
| 系统文件对话框 | NOT TESTED |
| 浏览器中断后只补缺块 | NOT TESTED。脚本已覆盖。 |
| 浏览器 7z | NOT TESTED |
| 浏览器快速发布 | NOT TESTED |
| 平板宽度 | NOT TESTED |
| 任务详情页点击 | NOT TESTED。详情接口已增加复用数量、压缩包 SHA-256 和上传会话进度。 |
| 两用户同时上传 | NOT TESTED |
| 大文件耗时 | NOT TESTED |

浏览器没有使用操作系统的文件对话框。文件是在真实页面里用 `File` 和 `DataTransfer` 交给现有脚本的。

## 性能

`xedc-import-worker.service` 保持活动，主进程内存约 47.8 MiB。没有增加并发，也没有第二套 worker。

本次没有测量大文件。浏览器上 733 B 图片显示出约 7 KiB/s，只说明小文件的瞬时速度，不能当作站点吞吐。

## 生产影响

- 更新 10016 已在当前库执行，只加字段和空表。
- 缓存已重建。上传页使用新的脚本和样式。
- 重复内容会新建媒体和投稿，字节相同则不再写第二份对象。
- 会员审核、配额和优化没有绕过。快速发布单张上限仍是 25 MiB。
- 验收草稿、媒体、文件、会话、任务和测试 claim 已删除。结束后没有标题以「XEDC浏览器」「XEDC去重验收」「XEDC续传验收」「XEDC上传验收」开头的内容。

## Git

改动还在工作区，没有提交。`git status` 显示 `xedc_core`、`xedc_quick` 和本报告为未提交修改。
