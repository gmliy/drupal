# 视频专区第二轮验收

日期：2026-11-03  
站点：https://gitxx.xyz

状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。没有把未测项目写成通过。

截图目录：`docs/video-second-round/`

## A. UI

视频首页、分类页和标签页使用同一套留白、下划线选中态和 16:9 卡片。桌面端 3 列，平板 2 列，手机 1 列。详情页播放器在主栏，相关推荐在右侧；窄屏改为上下排列。续播提示移到播放器下方，不再盖住画面。

| 页面 | 结果 |
| --- | --- |
| 视频首页 | PASS。标题、介绍、分类、最新/热门、搜索和卡片都在。 |
| 视频详情 | PASS。播放器、标题、播放量、日期、收藏、点赞、分享、简介、相关推荐分层清楚。 |
| 分类页 | PASS。标题为「短视频」，卡片与首页一致。 |
| 标签页 | PASS。仍是独立视频模板，只显示视频。 |
| 播放器外观 | PASS。黑底、16:9、画面 `contain`、控制栏使用浅色控件。 |
| 桌面截图 | PASS。`list-1440.png`、`detail-1440.png`、`category-1440.png`、`tag-1440.png` |
| 移动截图 | PASS。`list-375.png`、`detail-375.png`，以及 768 宽的列表、分类、标签。 |
| 暗黑模式截图 | PASS。`list-1440-dark.png`、`detail-1440-dark.png`，分类和标签也有暗色截图。文字色为 `rgb(228, 228, 231)`。 |
| 390 和 1280 截图 | NOT TESTED。这两个宽度测了列数、封面比例和溢出，没有另存图片。 |

图片标签 `/tags/haibian` 仍是原来的「组美图」模板。

## B. 播放器

原始故障有两层。

1. 第一轮在 `preload=none` 且媒体还没有 `canplay` 时读取时间和倍速，所以时间停在 0，倍速读回 1。媒体一旦就绪，同一播放器可以前进、跳转和变速。
2. nid 54、116、129 共用的文件是 HEVC。Chromium 的 `canPlayType` 对 HEVC 为空，`videoWidth` 为 0，时间轴在走但没有画面。文件访问不是原因：CDN 返回 200，Range 返回 206，`Content-Type` 是 `video/mp4`。

修复：

- 播放器在 `loadedmetadata` 之后再应用倍速、音量和静音。预加载改为 `metadata`。
- HEVC 源文件保留。文件 86 额外生成 H.264 副本 `web/sites/default/files/xedc-playback/86.mp4`。详情页只在副本存在时使用站点本地地址，不改对象存储的 URL 规则。
- 播放量脚本改为识别实际的 `node-type--video`。

| 项 | 结果 |
| --- | --- |
| 媒体链路 | PASS。本地公开文件经 CDN 或本站都能 Range。私有文件没有样本。 |
| Range | PASS。原 CDN 视频和本地 H.264 副本都是 206，`Content-Range: bytes 0-1023/...`。 |
| 真实播放 | FIXED。H.264 视频宽高 720×1280，时间持续增加。HEVC 节点改播副本后宽高 1080×1920，时间从 0.31 增到 0.66。 |
| 暂停和继续 | PASS。暂停后 `paused=true`，继续后时间大于暂停点。 |
| 倍速 | FIXED。0.5、0.75、1、1.25、1.5、2 的 `playbackRate` 与设置一致。2 倍速约 1 秒前进 1.9 秒。`xedcVideoRate` 有值。 |
| 进度 | PASS。跳到 4 秒后读数为 4.01，随后继续播放。 |
| 音量 | PASS。`video.volume` 可到 0.2 和 0.3，静音和取消静音有效。0.2 写入了 `xedcVideoVolume`。 |
| 全屏 | BLOCKED。无头浏览器允许全屏，但进入后状态没有变成 active。 |
| 快捷键 | PASS。右方向键在失焦时从 1 秒到 6 秒。输入框获焦时停在 1 秒。`M` 切换静音。 |
| 错误 | PASS。不存在的地址产生错误码 4，并显示「视频文件不存在，或当前无法播放。」和重试按钮。 |
| 重试恢复、断网、播放结束 | NOT TESTED。 |

Nginx 没有修改。

## C. 业务

| 项 | 结果 |
| --- | --- |
| 游客续播 | PASS。保存 6 秒，刷新后出现「上次看到 00:06」，继续播放后时间为 6.06。 |
| 会员续播 | PASS（接口）。uid 81 保存并读回 6 秒。未登录为 403。测试数据已删除。浏览器里的会员提示 NOT TESTED。 |
| 从头播放 | NOT TESTED。按钮点过，没有再读时间。 |
| 播放量去重 | FIXED。同一标签页打开并刷新 nid 8，计数 13 变为 14。修复前每次打开都加，因为 body 类名对不上。 |
| 时长 | PASS。格式未改，已有时长没有被本轮覆盖。新上传、替换、私有、S3/R2 为 NOT TESTED。 |
| 点赞、收藏、分享、下载 | BLOCKED。按钮可见，点击会写正式数据，没有专用内容。 |
| VIP | BLOCKED。没有带 VIP 角色的测试账号。 |
| 私有文件 | BLOCKED。这批视频没有私有文件。 |

## D. 响应式

| 宽度 | 列表 / 分类 / 标签 | 溢出 | 详情 |
| --- | --- | --- | --- |
| 375 | 1 列，封面 1.778 | 无 | 无溢出 |
| 390 | 1 列，封面 1.778 | 无 | 无溢出 |
| 768 | 2 列，封面 1.778 | 无 | 无溢出 |
| 1280 | 3 列，封面 1.778 | 无 | 无溢出 |
| 1440 | 3 列，封面 1.778 | 无 | 无溢出 |

暗黑模式在上述四个页面切换后，文字变为浅色。列表暗色截图里页面主体是深色，页脚仍是站点原有的浅色，没有改全站页脚。

## E. 工程

修改文件：

- `web/modules/custom/xedc_core/src/Video/PlaybackTranscoder.php`
- `web/modules/custom/xedc_core/src/Video/DurationManager.php`
- `web/modules/custom/xedc_core/src/Video/VideoCards.php`
- `web/modules/custom/xedc_core/xedc_core.services.yml`
- `web/themes/custom/xedc/xedc.theme`
- `web/themes/custom/xedc/xedc.libraries.yml`
- `web/themes/custom/xedc/js/video-detail.js`
- `web/themes/custom/xedc/js/statistics-lite.js`
- `web/themes/custom/xedc/css/video-list.css`
- `web/themes/custom/xedc/css/video-card.css`
- `web/themes/custom/xedc/css/video-detail.css`
- `web/themes/custom/xedc/templates/node/node--video--full.html.twig`
- `web/themes/custom/xedc/templates/video/video-card.html.twig`

数据库：没有新表，没有新字段。会员进度测试写入已删除。时长字段没有改。

新增文件：`web/sites/default/files/xedc-playback/86.mp4`。原 HEVC 文件大小仍是 4627386 字节。

Nginx：无变化。

PHP：相关文件 `php -l` 通过。容器重建时出现过一次 `DurationManager` 参数个数错误，那是旧容器和新构造函数交错的瞬间。重建完成后服务可用，之后的严重日志没有新的视频错误。

JavaScript：播放测试里没有媒体错误码。故意使用坏地址时得到错误码 4，并被页面接住。

缓存：`drush cr` 已执行。匿名进度接口保持不可缓存。列表仍按查询参数区分。没有为了测试去关权限。

Git：分支 `main`。没有 commit，没有 push，没有 reset。`config/sync` 无改动。工作区里与本轮无关的会员和存储改动仍在。
