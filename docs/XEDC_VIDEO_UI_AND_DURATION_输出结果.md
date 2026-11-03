# 视频专区重构：输出结果

日期：2026-11-03  
站点：https://gitxx.xyz  
项目：/var/www/drupal

本文件只记录这次实际跑出来的结果。做法和原因见 `docs/XEDC_VIDEO_UI_AND_DURATION_输出说明.md`。逐条状态见 `docs/XEDC_PROGRESS.md`。验收分类见 `docs/XEDC_VIDEO_UI_AND_DURATION_ACCEPTANCE_REPORT.md`。

没有提交 Git，没有改 `config/sync`。

## 页面请求

| 地址 | HTTP | 标题 | 其他读数 |
| --- | --- | --- | --- |
| `/video` | 200 | 视频 \| DcLili | canonical `https://gitxx.xyz/video`。描述「浏览已发布的视频，按分类、热度和关键词查找。」面包屑「首页 / 视频」 |
| `/video/category/duanshipin` | 200 | 短视频 - 视频分类 \| DcLili | canonical 为该分类地址。面包屑「首页 / 视频 / 短视频」。视频数 1。时长 `01:15` |
| `/video/tag/71` | 200 | 标签 视频 \| DcLili | canonical `https://gitxx.xyz/video/tag/71`。描述「浏览与“标签”相关的视频。」面包屑「首页 / 视频 / 标签」。页面没有 `/tags/` alternate，也没有图片标签模板 |
| `/tags/haibian` | 200 | 海边 列表页 \| DcLili | 仍是图片标签模板。面包屑「首页 / 全部标签 / 海边」 |
| `/taxonomy/term/71` | 301 后 200 | 标签 列表页 \| DcLili | 落到 `/tags/biaoqian`。仍是「组美图」模板 |
| `/videos/mojierbanceshishipinbiaoti0` | 200 | 墨迹二班测试视频标题 \| DcLili | 正文有「视频文件不存在」。页面中没有 `00:00` |
| `/videos/mimimamamaimaimai` | 200 | — | 播放器 `preload=none`，有封面。时长文本 `00:23` |

列表分类入口的真实数量：短视频 1，美女视频 3。搜索「测试」为 6 条。空关键词为 0 条，并显示空状态。热门排序前几条与 `node_counter` 一致，顺序为 nid 54、55、6。已发布视频共 8 条，每页 12 条，没有第二页数据。

## 播放器

环境：Chromium 156 无头。页面为 nid 116。第一次测试结束。第二次停在等待媒体元数据，进程被终止，不计入结果。

| 项 | 结果 |
| --- | --- |
| Plyr 初始化 | 已初始化 |
| 封面 | 有 |
| `preload` | `none` |
| 进度、设置、全屏、静音按钮 | 都在 |
| `play()` | `playing=true` |
| `pause()` | `paused=true` |
| 键 `M` | `muted=true` |
| 播放时间 | 停在 0 |
| 倍速设为 1.5 | 读回仍是 1。`xedcVideoRate` 为空 |
| 音量 | 读到 0.4。`xedcVideoVolume` 为空 |
| 续播本地存储 | `xedcVideoProgress:116` 为空 |
| 390 宽控制栏 | 右缘 346，没有横向溢出 |
| 暗色相关卡片文字 | `rgb(228, 228, 231)` |

拖动、全屏、空格、方向键、`F`、加载和重试没有测出有效变化。

## 时长

`ffprobe`：`/usr/bin/ffprobe` 6.1.1。

格式函数：

| 秒 | 显示 |
| ---: | --- |
| 0 | 空 |
| 5 | `00:05` |
| 65 | `01:05` |
| 542 | `09:02` |
| 3661 | `01:01:01` |

`drush xedc:video-duration-stats`：

| 项 | 数量 |
| --- | ---: |
| 视频总数 | 8 |
| 已有时长 | 7 |
| 缺失 | 1 |
| 文件不存在 | 1 |
| 无法读取 | 1 |
| 已记录失败 | 2 |

补齐和跳过：

| nid | 结果 |
| --- | --- |
| 6、8、10 | 写成 13 秒。标题、状态、`created`、`changed`、审核快照未变 |
| 9 | 失败：`video file is missing`。时长仍空 |
| 55 | 失败：`remote address was not downloaded`。时长仍为 98 |
| 54、116、129 | 原时长保留，未覆盖 |

队列 `xedc_video_duration` 对已有时长的任务直接返回，实际处理过 1 条。游标完成后再跑，处理数是 0。

页面上的时长例子：23 秒为 `00:23`，75 秒为 `01:15`，98 秒为 `01:38`，283 秒为 `04:43`，13 秒为 `00:13`。

## 检查命令

| 命令 | 结果 |
| --- | --- |
| `php -l` 视频相关 PHP 与 `xedc.theme` | 无语法错误 |
| `drush cr` | 成功 |
| `git status -- config/sync` | 无改动 |
| 时长错误日志 | 去掉无效的 `-nostdin` 之后没有新的时长错误。更早的 20680–20685 是参数错误时的 ffprobe 横幅 |

`/r/12shh` 上的会员公开资料回调错误，以及一次 Drush `eval()` 里的 `PhpStorageFactory` 错误，都不是视频页面请求。

## 结果分类

- 已通过测试：上表里写出具体读数的页面、格式、补齐、播放、暂停、静音和手机宽度。
- 未完成：无。
- 未验证：拖动、倍速、音量滑块、全屏、其余快捷键、续播、点赞收藏下载分享的点击、VIP、审核流程、真实第二页、空分类、空标签、超过一小时的视频、私有文件、S3/R2、真实上传或替换、播放量刷新对照、查询次数。
