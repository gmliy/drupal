# 本轮任务摘要

日期：2026-11-03  
项目：`/var/www/drupal`  
站点：https://gitxx.xyz

## 完成内容

1. 定位全站缩略图故障：s3fs 元数据负缓存让图片样式认为 R2 原图不存在；只在本地的原图也无法生成样式；Cloudflare 缓存了旧的 HTML 404。
2. 修复公开流包装器的存在性判断和本地只读回退，并给样式 URL 加上 `xedc_style=1`，避开旧缓存键。
3. 用真实 HTTP 和 Chromium 检查前台、会员页和后台编辑/媒体库。
4. 重新统计存储差异，做了测试前缀双向复制、三文件 SHA-256、隔离回滚和锁检查。
5. 没有执行生产存储切换。

## 修改文件

- `web/modules/custom/xedc_core/src/StreamWrapper/XedcPublicS3fsStream.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/PublicUrl.php`

这两个文件在本轮开始前已经是未跟踪文件。本轮只改了缩略图读取和样式 URL。没有提交，没有推送，没有重置工作区。

新增文档：

- `docs/XEDC_THUMBNAIL_FULL_AUDIT_REPORT.md`
- `docs/XEDC_THUMBNAIL_FIX_ACCEPTANCE_REPORT.md`
- `docs/XEDC_STORAGE_SWITCH_PREFLIGHT_ACCEPTANCE_REPORT.md`
- `docs/XEDC_STORAGE_SWITCH_PRODUCTION_READINESS_REPORT.md`
- `docs/XEDC_ROUND_TASK_SUMMARY.md`

## 执行过的检查

- `php -l` 两个修改过的 PHP 文件。
- Drush 读取图片样式、文件差异和运行状态。环境变量保持 `XEDC_STORAGE_RUNTIME=1`、公开 takeover 开启、私有 takeover 关闭、CDN 开启。
- 清除了 page、dynamic page cache 和 render 缓存。没有执行 `drush cr`，没有删除 Redis 容器键。
- `curl` 通过 Cloudflare 和源站 `--resolve gitxx.xyz:443:127.0.0.1` 检查样式与原图。
- Chromium 截图：首页、图片列表手机版、图片详情、视频列表、文章列表、后台内容、编辑表单、会员中心。截图在 `/root/xedc-storage-shots/`。
- 管理员一次性登录只用于上述后台页面。登录链接和浏览器配置目录已删除。
- 隔离存储脚本使用临时运行目录和测试前缀。正式 `active.json` 的 mtime 前后一致。

## 结果

| 专项 | 判定 |
| --- | --- |
| 缩略图 | PASS。当前页面样式为 200 图片。旧的无标记 URL 在 Cloudflare 上仍可能是缓存 404。 |
| 存储切换准备 | 不通过。文件没有全量 SHA-256，5/2 差异未处理，正式回滚没有演练。 |

生产状态保持 Cloudflare R2，provider `silly`，version 1，status active。

## 未完成

- 清理 4 个旧样式 URL 的 Cloudflare 缓存。
- 128 个文件的全量 SHA-256。
- 5 个本地独有和 2 个 R2 独有文件的正式同步。
- 一次真实的切换失败回滚。
- 单独的普通会员账号和内容编辑账号浏览器会话。
- 浏览器 Console 日志。

这些都没有在本轮用切换生产存储或删除正式文件的方式处理。
