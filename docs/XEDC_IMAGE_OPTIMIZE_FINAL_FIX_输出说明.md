# 正文 CDN 图片显示修复：输出说明

日期：2026-11-03  
站点：https://gitxx.xyz  
项目：/var/www/drupal  
测试 ID：`xedc-body-cdn-final-20261103-7c2e`

本文件整理上一轮验收结论。完整记录在 `docs/XEDC_IMAGE_OPTIMIZE_FINAL_FIX_REPORT.md`。进度在 `docs/XEDC_PROGRESS.md` 文末「正文 CDN 图片显示修复」。截图在 `docs/image-optimize-cdn-fix/`。

没有关闭图片安全过滤，没有改 Drupal 核心，没有改文本格式配置，没有改 R2 和优化器，没有提交 Git。

## 结论

正文 CDN 图片已经能在文章页显示。图集权重调整后的顺序与前台一致。上一轮挡住全入口验收的两项都已补上，图片自动优化专项验收可以通过。

`basic_html` 的 `filter_html_image_secure` 仍然启用。这个过滤器没有可信域名配置，原来会把 `cdn.gitxx.xyz` 换成 16×16 占位图。现在只放行主机正好是 `cdn.gitxx.xyz`、路径留在 `/public/` 的 HTTPS 图片。其他域名、伪装域名、用户信息、非 HTTPS、`javascript:`、`data:` 和 `/private/` 仍走原来的占位图。

统计：PASS 10，FIXED 1，FAIL 0。

| 项目 | 状态 |
| --- | --- |
| 正文图片优化 | PASS |
| 正文图片保存 | PASS |
| 正文 CDN 图片安全过滤 | FIXED |
| 正文图片前台显示 | PASS |
| 恶意外部图片拦截 | PASS |
| 图集权重保存 | PASS |
| 图集前台排序 | PASS |
| R2 对象一致性 | PASS |
| 本地图片样式 | PASS |
| 现有图片优化回归 | PASS |
| 测试数据清理 | PASS |

恶意 HTML 只在内存里经过过滤器，没有写入生产文章。封面和会员投稿没有重新建内容。本轮正文 JPEG 和图集 JPEG、PNG、WebP 走的是同一套优化器。

## 浏览器结果

文章正文保存的是 WebP CDN 地址。前台图片 720×420，响应 200，类型 `image/webp`，没有占位图。

图集权重从 JPEG、PNG、WebP 改成 WebP、PNG、JPEG。前台尺寸依次是 360、480、640。PNG 四角仍然全透明。四个对象的 SHA256 与 R2 回读一致，原 JPEG Key 不存在。样式文件在本地，没有上传到 R2。

## 改过的文件

| 文件 | 作用 |
| --- | --- |
| `web/modules/custom/xedc_core/xedc_core.module` | 把图片安全过滤器的实现换成自定义类，过滤器本身仍启用 |
| `web/modules/custom/xedc_core/src/Filter/TrustedHtmlImageSecure.php` | 在核心判断之前放行可信 CDN 图片 |
| `web/modules/custom/xedc_core/src/Filter/TrustedCdnImageUrl.php` | 精确校验主机、协议、端口和 `/public/` 路径 |
| `web/modules/custom/xedc_core/tests/src/Unit/TrustedCdnImageUrlTest.php` | 允许和拒绝用例 |
| `docs/XEDC_IMAGE_OPTIMIZE_FINAL_FIX_REPORT.md` | 完整验收报告 |
| `docs/XEDC_PROGRESS.md` | 文末追加本轮进度 |
| `docs/XEDC_IMAGE_OPTIMIZE_FINAL_FIX_输出说明.md` | 本文件 |

## 截图

| 文件 | 内容 |
| --- | --- |
| `docs/image-optimize-cdn-fix/01-body-editor.png` | 正文编辑器 |
| `docs/image-optimize-cdn-fix/02-body-inserted.png` | 图片已插入正文 |
| `docs/image-optimize-cdn-fix/03-body-edit.png` | 保存后重新打开 |
| `docs/image-optimize-cdn-fix/04-body-front.png` | 前台文章显示图片 |
| `docs/image-optimize-cdn-fix/05-gallery-dialog.png` | 图集上传 |
| `docs/image-optimize-cdn-fix/06-gallery-alts.png` | 填写替代文本 |
| `docs/image-optimize-cdn-fix/07-gallery-selected.png` | 已选择三张图 |
| `docs/image-optimize-cdn-fix/08-gallery-widget.png` | 插入后的图集字段 |
| `docs/image-optimize-cdn-fix/09-gallery-edit-before.png` | 调整权重前 |
| `docs/image-optimize-cdn-fix/10-gallery-weights.png` | 权重 2、1、0 |
| `docs/image-optimize-cdn-fix/11-gallery-edit-after.png` | 保存后的顺序 |
| `docs/image-optimize-cdn-fix/12-gallery-front.png` | 前台按 WebP、PNG、JPEG 显示 |

## 清理和 Git

已删除节点 557、558 及中文、英文、日文、俄文译本，媒体 587–589，文件 870–873 和对应 R2 对象。首页、美图、视频、文章栏目不含测试 ID，节点地址为 404。uid 1 的原头像还在。

当前分支是 `main`。没有提交。工作区里还有本轮之前的未提交修改。
