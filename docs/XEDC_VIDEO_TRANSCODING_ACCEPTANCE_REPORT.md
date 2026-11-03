# 视频转码与兼容性管理验收

日期：2026-11-03。站点 https://gitxx.xyz。模块 `xedc_core`。播放器仍是 Plyr + hls.js 1.7.3。

状态只使用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。

## A. 服务器环境

| 项目 | 结果 | 状态 |
| --- | --- | --- |
| FFmpeg | 6.1.1-3ubuntu5，`/usr/bin/ffmpeg` | PASS |
| FFprobe | 6.1.1-3ubuntu5 | PASS |
| libx264 | 编码器可用 | PASS |
| AAC | 编码器可用 | PASS |
| yuv420p、+faststart | 样本输出已复查，`moov` 在 `mdat` 之前 | PASS |
| CPU | 4 核 | PASS |
| 内存 | 7.6 GiB，测试时可用约 4.6 GiB | PASS |
| 磁盘 | `/` 29G，已用 70%，剩余约 8.6G | PASS |
| PHP-FPM | 主进程 root，工作进程 www-data | PASS |
| 资源限制 | 并发 1，FFmpeg 线程 2，preset veryfast，超时 180 秒，最长边超过 1920 才缩小，剩余磁盘低于 512MB 不转码，最多 3 次 | PASS |

没有编译 FFmpeg，没有安装显卡编码包。

## B. 编码兼容性

判断只在 `MediaProbe`。页面渲染不再调用 FFprobe。

直接播放：H.264，容器是 MP4/MOV，像素格式为空或 `yuv420p`，音频为空、AAC 或 MP3，Profile 不是 10-bit、4:2:2、4:4:4。

需要转码：HEVC/H.265，其他不能直接播放的视频编码，以及 H.264 但像素格式、Profile 或容器不满足上面的条件。

需要复查、不自动转码：AV1、VP9、VP8。容器名包含 `hls` 的流保持 HLS，不送进 MP4 转码。

无法处理且不无限重试：文件不存在、FFprobe 失败、没有视频流。私有文件，以及本地没有字节的远程/S3 文件，标记为 blocked，不复制到公开目录。

| 检查 | 状态 |
| --- | --- |
| 文件 5：H.264、AAC、yuv420p、720×1280，状态 skipped | PASS |
| 文件 86：HEVC、AAC、1080×1920，沿用已有副本 | PASS |
| 损坏样本：状态 failed，attempts 为 3 | PASS |
| 私有 URI：状态 blocked，公开目录没有对应副本 | PASS |
| AV1、VP9、真实 m3u8 | NOT TESTED |

## C. 转码系统

状态和文件关系不能只放在 State 或文件名里，所以新增表 `xedc_video_transcode`。主键是源文件 ID。一个文件可以被多个节点引用。更新钩子是 `xedc_core_update_10011`，没有删除旧数据。

队列是 `xedc_video_transcode`。时长队列只负责探测并入队，不再在请求或时长任务里直接跑 FFmpeg。

状态：pending、processing、completed、failed、skipped、cancelled。

同一源文件、同一修改时间、已经有有效副本时直接返回。源文件修改时间变化后，旧副本不再当作新源文件的播放地址；新副本验证成功后才替换。取消等待中的任务不会删除已有副本。

临时文件在系统临时目录，名称 `xedc-transcode-{fid}.part.mp4`。FFprobe 确认编码、像素格式和时长后才改名。失败时删除临时文件。测试结束时没有残留 part 文件。

| 检查 | 状态 |
| --- | --- |
| 两个队列进程同时领取任务时，第二个收到 “already running”，期间 FFmpeg 进程数最多为 1 | PASS |
| 重复探测文件 5、86、803，队列仍是 0 | PASS |
| 替换样本 801 的源文件后状态变为 pending，播放器不再返回旧副本，旧文件字节保持不变 | PASS |
| 取消后队列消费没有覆盖旧副本；重新转码后输出变为新的 320×180 H.264 + AAC | PASS |
| 历史视频没有被批量入队 | PASS |

## D. 转码结果

文件 86 的原文件仍是 4627386 字节，修改时间没有变。已有副本 `/sites/default/files/xedc-playback/86.mp4` 仍是 2253272 字节，H.264、yuv420p、1080×1920、AAC，`faststart` 仍在。没有重新转码。

| 源 | 输入 | 输出 | 耗时 | 状态 |
| --- | --- | --- | --- | --- |
| 801 横屏有声 | HEVC + AAC，320×180，23429 字节 | H.264 + AAC，yuv420p，320×180，36424 字节 | 队列 0.58 秒，记录为不到 1 秒 | PASS |
| 802 竖屏无声 | HEVC，无音轨，180×320，5773 字节 | H.264，无音轨，yuv420p，180×320，2946 字节 | 队列 0.58 秒 | PASS |
| 803 横屏 H.264 | H.264 + AAC，320×180 | 没有副本 | 0 | PASS |
| 文件 5 | H.264 + AAC，720×1280，2255011 字节 | 没有副本 | 0 | PASS |

Chromium 播放了 801 的副本：320×180，时间增加到约 0.99 秒，错误提示随后隐藏。

样本放在 `sites/default/files/xedc-samples/`，没有挂到正式节点。正式视频内容没有改。

## E. 后台管理

路由：`/admin/reports/xedc-files/transcode`。权限沿用 `access xedc file statistics`。本地任务挂在现有文件统计下。

实测概览：视频 8，已检测 7，直接兼容 2，需要转码 3，等待 0，处理中 0，成功 3，失败 2，播放副本 3。这些数字来自节点表和任务表。

列表有搜索、状态、编码和分页。操作有检测、重新转码、取消、引用、原文件、播放副本、清理临时文件。危险操作走确认表单。没有未实现的按钮。

| 检查 | 状态 |
| --- | --- |
| uid 1 打开页面，标题为「视频转码」，表格 7 行 | PASS |
| 页面正文没有服务器绝对路径、`public://` 或密钥 | PASS |
| 游客访问返回 403 | PASS |
| uid 81 路由访问被拒绝 | PASS |

## F. 播放器

播放器按任务记录选择地址，不在浏览器里识别编码。

| 场景 | 结果 | 状态 |
| --- | --- | --- |
| nid 54、116、129 | 状态 copy，地址都是 `/sites/default/files/xedc-playback/86.mp4`。画面 1080×1920，时长 5.17 秒。约 0.7 秒后时间到 0.64–0.87。暂停为 true，1.5 倍速生效，跳到约 1.5 秒 | PASS |
| 三个节点的源 | 数据库引用同一个文件 86，不是按 nid 猜的。默认语言标题分别是三个节点 | PASS |
| 文件 5 的页面 | 状态 original，地址是 CDN 上的原文件，没有播放副本 | PASS |
| CDN 上的文件 5 | `curl -I` 返回 404。Chromium 的 `networkState` 为 3，12 秒内没有画面 | FAIL |
| 同一文件的本地地址 | HTTP 206。Chromium 中 720×1280，时长 12.72 秒，跳到 4 秒后时间为 4.35，1.5 倍速，暂停为 true | PASS |
| 801 副本 | 在线恢复后时间约 0.99 秒，320×180 | PASS |
| HLS | 原有 `.m3u8` 分支没改。这次没有可播放的 m3u8 | NOT TESTED |
| 全屏 | 有头 Chromium 156，Xvfb。`fullscreenEnabled` 为 true，按钮存在。点击和 `fullscreen.enter()` 都没有异常，但 `fullscreenElement` 为空。移动端模拟同样没有进入 | BLOCKED |
| 移动端控制栏 | 390 宽下有播放、静音、倍速、画中画、全屏，页面没有横向溢出 | PASS |
| 暗黑模式 | 原先暗色背景下标题算出 `rgb(28, 25, 23)`。暗色规则改为明确的 `#e4e4e7` 后，标题和正文都是 `rgb(228, 228, 231)` | FIXED |
| 游客续播提示 | nid 6 刷新后显示「上次看到 00:06」，提示不在播放器内部。nid 8 不显示这条进度 | PASS |
| 游客继续播放后的时间 | 点击后 `currentTime` 仍是 0。页面媒体地址是上面的 CDN 404 | FAIL |
| 会员保存 | uid 81 播放后 `user.data` 中 `progress:6` 为 position 6。测试后已删除 | PASS |
| 会员提示 | 刷新后显示「上次看到 00:06」 | PASS |
| 会员继续播放后的时间 | 点击后时间仍是 0，原因同 CDN 404 | FAIL |
| 文件不存在 | 文案「视频文件不存在，或当前无法播放。」 | PASS |
| 断网 | `navigator.onLine` 为 false，即使错误码是 4，也显示「网络异常，暂时无法加载视频。」 | FIXED |
| 网络恢复 | 恢复后播放 801，时间增加，错误隐藏 | PASS |
| 播放结束 | 代码会清本地进度。这次没有把一条视频播到结束 | NOT TESTED |

## G. 安全与性能

| 检查 | 状态 |
| --- | --- |
| 原 HEVC 文件保留 | PASS |
| 私有文件不复制到公开目录 | PASS |
| 只有本地可读的 public/private 文件才会探测。没有本地字节的远程文件不下载 | PASS |
| 对象存储配置和 `config/sync` 没有改 | PASS |
| 播放副本有独立文件实体和 `file_usage`，类型 `video_playback`，id 是源文件。下载逻辑没有改 | PASS |
| 并发、磁盘检查、临时文件清理、重试上限 | PASS |
| 游客和普通会员不能打开管理页 | PASS |
| 转码日志只记退出码，不记 FFmpeg 的路径输出 | PASS |

## H. 工程

新增或修改：

- `web/modules/custom/xedc_core/src/Video/MediaProbe.php`
- `web/modules/custom/xedc_core/src/Video/TranscodeSchema.php`
- `web/modules/custom/xedc_core/src/Video/TranscodeManager.php`
- `web/modules/custom/xedc_core/src/Video/PlaybackTranscoder.php`
- `web/modules/custom/xedc_core/src/Video/DurationManager.php`
- `web/modules/custom/xedc_core/src/Plugin/QueueWorker/VideoTranscodeWorker.php`
- `web/modules/custom/xedc_core/src/Controller/VideoTranscodeController.php`
- `web/modules/custom/xedc_core/src/Form/VideoTranscodeConfirmForm.php`
- `web/modules/custom/xedc_core/src/Form/VideoTranscodeFilterForm.php`
- `web/modules/custom/xedc_core/src/Form/VideoTranscodeEnqueueForm.php`
- `xedc_core.install`、`xedc_core.services.yml`、`xedc_core.routing.yml`、`xedc_core.links.menu.yml`、`xedc_core.links.task.yml`
- 主题 `xedc.theme`、`video-detail.js`、`video-detail.css`、`node--video--full.html.twig`、`xedc.libraries.yml`

数据库只新增 `xedc_video_transcode`。

缓存已重建。容器重建过程中出现过一次 DurationManager 参数类型错误，编号 22177–22180。重建完成后没有新的严重 PHP 错误。浏览器测试没有留下未处理的页面异常。

Git 工作区原来就有大量未提交修改。这次没有 reset、clean、commit 或 push。`config/sync` 没有变更。

## 仍未通过

- 文件 5 的 CDN 地址返回 404，所以这个 H.264 节点在浏览器里不能从正式地址播放。本地文件本身可以播放，系统也没有给它生成副本。
- 全屏没有在当前 Xvfb 里真正进入。
- 会员和游客的续播提示已经出现，但继续播放后的时间因为同一个 CDN 404 没有增加。
- 没有 AV1、VP9 和 m3u8 样本，这两类没有做播放测试。
