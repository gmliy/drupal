# 视频专区第三轮验收

日期：2026-11-03。站点 https://gitxx.xyz。播放器仍是 Plyr + hls.js 1.7.3。转码表和队列没有重做。

状态只使用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。

截图在 `docs/video-third-round/`。

## A. CDN

上一轮 06:15:19 对文件 5 的 CDN 地址做 HEAD，返回 404。对象的 `Last-Modified` 是 06:15:30。当时公开前缀已经切到 `public/`，这份对象还在从 `s3fs-public/` 复制过来，所以播放器拿到的地址是对的，对象晚了 11 秒。

现在两边都在：

| 键 | 结果 |
| --- | --- |
| `public/2026-10/plel0….mp4` | HEAD 200，2255011 字节，`video/mp4`，`Accept-Ranges: bytes`，`Last-Modified: Tue, 03 Nov 2026 06:15:30 GMT` |
| `s3fs-public/2026-10/plel0….mp4` | HEAD 200，同样大小，`Last-Modified: Mon, 02 Nov 2026 20:08:02 GMT` |

没有改全站 Prefix，没有重新上传，没有重转码。SHA256 `e6335363255565ccc6cf1349423e229b1c758ec3c8971bb0f5a6174bf2c372d0` 与本地原文件一致。`Range: bytes=0-1023` 为 206，`Content-Range: bytes 0-1023/2255011`。

| 检查 | 状态 |
| --- | --- |
| 文件 5 的 URL 使用 File 实体和当前 `public` 前缀 | PASS |
| CDN GET 200，Range 206，类型和大小与本地一致 | PASS |
| Chromium 从 `cdn.gitxx.xyz` 播放：720×1280，时长 12.72 秒，约 0.8 秒后时间到 0.95，再跳到 4.38，1.5 倍速，暂停为 true。状态是 original | PASS |
| 文件 86 原片 CDN Range 206，总长度 4627386 | PASS |
| 播放副本 `86.mp4`、样本 `801.mp4` Range 206。任务仍是 skipped / completed，没有新副本 | PASS |
| nid 54 仍播放 `xedc-playback/86.mp4`，画面 1080×1920 | PASS |

## B. 续播

进度仍按节点保存，不按播放 URL。设置进度前会等 `loadedmetadata`。继续播放会再等 `seeked`。`position=0` 会清掉会员进度，用来表示从头播放。

| 检查 | 结果 | 状态 |
| --- | --- | --- |
| 游客 | nid 6 播过 6 秒后本地记录 position 6。刷新后提示「上次看到 00:06」。点击继续后 `currentTime` 为 6，并且在播放 | PASS |
| 游客从头播放 | 本地记录被清掉。点击后视频从开头继续播，约 0.8 秒时时间为 1.08，没有停在 6 秒。提示不在播放器内部 | PASS |
| 多视频 | nid 8 不显示 nid 6 的续播 | PASS |
| 会员 | uid 81。服务端 `progress:6` 为 position 6。刷新后提示「上次看到 00:06」。点击继续后 `currentTime` 为 6，画面 720×720 宽，并且在播放。测试数据已删除 | PASS |
| 播放结束 | nid 54 播到结束。本地进度被清掉，续播提示隐藏 | PASS |
| 离开页面 | `pagehide` 会写本地进度。没有单独截一次离开瞬间 | NOT TESTED |

## C. 播放器

| 检查 | 状态 |
| --- | --- |
| H.264 原文件，CDN | PASS |
| HEVC 副本，竖屏类名 `is-portrait`，不拉伸进 16:9 | PASS |
| HLS | NOT TESTED。没有可播放的 m3u8 |
| 倍速 1.5、跳到约 4 秒、暂停 | PASS |
| 音量 | NOT TESTED。本轮没有再拨音量 |
| 全屏 | BLOCKED。有头 Chromium 156 在 Xvfb 上 `fullscreenEnabled` 为 true，`fullscreen.enter()` 没有异常，但 `fullscreenElement` 为空。需要在普通桌面浏览器里点全屏、再按 Esc 退出 |
| 断网文案和恢复 | 上一轮已测。本轮没有重做 |
| 播放结束清进度 | PASS |
| 控制台 | 自动化里出现一条 `Uncaught (in promise)`，没有堆栈。继续播放的 `play()` 已加上失败捕获，这条日志没有再跑一遍 |

## D. 前台

列表、分类、标签和详情沿用原来的数据和导航。首页最新一条真实视频放大，不写死推荐。卡片整块进入详情，作者名字单独可点。作者不存在时不占位。

| 页面 | 结果 | 状态 |
| --- | --- | --- |
| `/video` 桌面浅色 | 8 张卡片，有搜索和最新/热门，无横向滚动 | PASS |
| `/video` 桌面暗色 | 标题 `rgb(228, 228, 231)` | FIXED |
| `/video` 390 宽 | 8 张卡片，无横向滚动 | PASS |
| `/video/category/duanshipin` | 标题「短视频」，1 张卡片 | PASS |
| `/video/tag/71` | 标题「标签」，1 张卡片 | PASS |
| `/tags/haibian` | 仍是图片标签，正文有「组美图」，没有视频网格 | PASS |
| 详情桌面、手机、暗色 | 播放器在上，390 宽无横向滚动，暗色标题 `rgb(228, 228, 231)` | PASS |
| SEO | 详情 title、description、一条 `og:title`、canonical 正常。分类和视频首页 title 正常 | PASS |
| 点赞、收藏、分享、下载 | 区域仍在模板里。没有点击，避免改正式计数 | NOT TESTED |

截图：

- `video-home-desktop-light.png`
- `video-home-desktop-dark.png`
- `video-home-mobile.png`
- `video-category.png`
- `video-tag.png`
- `video-detail-desktop.png`
- `video-detail-mobile.png`
- `video-detail-dark.png`
- `player-playing.png`

没有单独截 430、768、1024、1280。390 和 1440 测过。其余宽度 NOT TESTED。

## E. 性能

卡片作者按用户 ID 一次 `loadMultiple`，没有逐条查用户。列表仍走原来的 View 分页。封面 `loading="lazy"`。页面渲染不跑 FFmpeg 或 FFprobe。缓存标签仍包括节点和封面。没有改 `config/sync`。

## F. 工程

改动集中在视频卡片、详情模板、三份视频 CSS、`video-detail.js`、进度保存和 `VideoCards`。没有新表，没有动转码队列。`position=0` 现在会删除该节点的会员进度。

Git 没有提交或推送。`config/sync` 没有变更。工作区里原来的未提交文件还在。

PHP 严重日志仍停在上一轮容器重建时的 22180，本轮没有新的严重错误。

## 仍未通过

- 全屏在当前 Xvfb 里进不去，记 BLOCKED。
- 没有 m3u8，HLS 记 NOT TESTED。
- 点赞、收藏、分享、下载没有点击。
- 375、430、768、1024、1280 没有逐档打开。
