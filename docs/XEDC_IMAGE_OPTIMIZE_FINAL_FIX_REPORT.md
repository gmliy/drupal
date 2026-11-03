# XEDC 正文 CDN 图片显示修复与最终验收

日期：2026-11-03。测试 ID：`xedc-body-cdn-final-20261103-7c2e`。站点：https://gitxx.xyz。Drupal 11.4.11。

状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。浏览器里没有点完并保存的步骤不标 PASS。CDN 返回 200 本身不算正文显示成功。

## 1. 正文图片不显示的原因

`basic_html` 启用了核心过滤器 `filter_html_image_secure`，权重 9。实现在 `web/core/modules/filter/src/Plugin/Filter/FilterHtmlImageSecure.php`。

它先用 `FileUrlGenerator::transformRelative()` 处理图片地址。这个方法只去掉当前请求主机。`cdn.gitxx.xyz` 和页面主机 `gitxx.xyz` 不同，地址保持绝对 URL。随后过滤器要求地址以本站 `base_path` 开头，并且 `getimagesize()` 能在 Drupal 根目录下读到文件。CDN 地址两项都不满足，于是调用 `hook_filter_secure_image_alter()`。核心实现把图片换成 16×16 的 `error.svg`，标题是“已移除此图像。由于安全原因，仅允许来自本地域的图像”。

这个过滤器没有可信域名配置。文件本身已经是 WebP，R2 对象也存在。前台不显示不是优化器写错文件。

## 2. 修复方案

没有关闭 `filter_html_image_secure`，没有放宽 `filter_html` 的标签白名单，没有改 Drupal 核心，也没有改文本格式配置。

`xedc_core_filter_info_alter()` 只把这个过滤器的插件类换成 `TrustedHtmlImageSecure`。过滤器 ID、权重和启用状态不变。执行顺序仍是：

1. `filter_html`（权重 -10）先按原白名单去掉不允许的标签和属性。
2. `filter_html_image_secure`（权重 9）逐张检查图片。
3. 只有原始 `src` 通过下面的主机和路径校验时，保留原来的 CDN 地址。
4. 本站相对路径仍必须通过原来的 `getimagesize()`。
5. 其余地址仍进入原来的 `hook_filter_secure_image_alter()`，由核心换成 16×16 占位图。

同一张图片只在这个过滤器里判断一次，没有第二套过滤器再改写。

## 3. 修改的文件

- `web/modules/custom/xedc_core/xedc_core.module`
- `web/modules/custom/xedc_core/src/Filter/TrustedHtmlImageSecure.php`
- `web/modules/custom/xedc_core/src/Filter/TrustedCdnImageUrl.php`
- `web/modules/custom/xedc_core/tests/src/Unit/TrustedCdnImageUrlTest.php`

没有改优化器、R2 配置、`config/sync` 或文本格式 YAML。`basic_html` 的 `filter_html_image_secure` 仍然是启用状态。`full_html` 原本就没有启用这个过滤器，这次没有打开它。

## 4. CDN 域名校验

允许的主机只有 `cdn.gitxx.xyz`，与 `CdnUrlPolicy::PRODUCTION_BASE` 的主机一致。判断不是字符串包含。

必须同时满足：

- 方案是 `https`。`http`、协议相对 URL、`javascript:` 和 `data:` 都拒绝。
- 主机经 `parse_url()` 得到，并且 URL 里的 authority 去掉可选的 `:443` 后也正好是这个主机。大小写不敏感。
- 没有用户信息、`@`、反斜杠、空白、控制字符或百分号编码的主机。
- 端口只允许省略或 443。
- 路径解码最多五次后，每一层都必须位于 `/public/` 下面，并且不能有 `.` 或 `..` 路径段。
- `/private/`、`/s3fs-public/` 以及编码后会逃出 `/public/` 的路径都拒绝。

因此下面这些地址不会被放行：`cdn.gitxx.xyz.attacker.example`、`attacker.example/cdn.gitxx.xyz`、`evil.cdn.gitxx.xyz`、带用户信息的 URL、非 443 端口、尾点主机。

## 5. 安全反向测试

恶意 HTML 没有写入生产文章。用启用后的过滤器和 `check_markup('basic_html')` 在内存中处理。

| 输入 | 结果 |
| --- | --- |
| `https://cdn.gitxx.xyz/public/test.webp` | 保留 |
| `https://CDN.GITXX.XYZ/public/test.webp` | 保留 |
| `https://cdn.gitxx.xyz:443/public/test.webp` | 保留 |
| 本站存在的 `/core/misc/help.png` | 保留 |
| 本站不存在的图片路径 | 16×16 占位图 |
| `https://evil.example/a.png` | 占位图 |
| `https://cdn.gitxx.xyz.attacker.example/public/a.webp` | 占位图 |
| `https://attacker.example/cdn.gitxx.xyz` | 占位图 |
| `http://cdn.gitxx.xyz/public/a.webp` | 占位图 |
| `javascript:` 和 `data:` | 占位图 |
| 带用户信息的 CDN 地址 | 占位图 |
| `https://cdn.gitxx.xyz/private/secret.webp` | 占位图 |
| `/public/../private/` 和双重编码 `..` | 占位图 |
| `<script>alert(1)</script>` 经过 `basic_html` | `<script>` 被去掉 |

环境里没有 PHPUnit 可执行文件，没有新装。上面的允许和拒绝用例另外用同一套 `TrustedCdnImageUrl` 规则直接跑过。

## 6. 正文图片浏览器验收

管理员一次性登录后，在 `/node/add/article` 把正文格式选为 `basic_html`，用「从电脑上传图片」上传 JPEG，等编辑器里的地址变成 WebP CDN 地址，然后保存。

- 节点 557。保存格式是 `basic_html`。
- 编辑器地址和重新打开后的正文都是 `https://cdn.gitxx.xyz/public/inline-images/body-xedc-body-cdn-final-20261103-7c2e.webp`。
- 匿名打开文章页，这张图 `naturalWidth` 720、`naturalHeight` 420。
- 图片响应 200，`image/webp`。
- 页面上没有 `filter-image-invalid`，也没有 16×16 占位图。
- 正文只有这一张内容图片。原 JPEG 对象不存在。
- 源 JPEG 18333 字节，优化后 WebP 3446 字节。文件流 SHA256 与 R2 回读一致：`4e4f136ce7d54c126a4a3c4f9607b00d5aeb91e7838abf8feab8b744d113cfa7`。

工作人员新建文章仍会被现有规则直接发布。前台截图之后，中文、英文、日文、俄文都已取消发布，`status=1` 的译本数量为 0。

## 7. 图集权重浏览器验收

管理员新建美图，在 `field_images` 的媒体库里一次上传 JPEG、透明 PNG 和 WebP，填写替代文本，保存并插入，然后保存节点 558。

重新打开编辑页，点现有的「显示多媒体条目权重」。三个权重从上传顺序的 0、1、2 改成 2、1、0，也就是 JPEG、PNG、WebP 改成 WebP、PNG、JPEG。保存后再次打开，媒体 ID 顺序是 589、588、587，权重重新编号为 0、1、2。

匿名图集页的实际顺序和尺寸：

| 顺序 | 地址 | 浏览器原始尺寸 | 响应 |
| --- | --- | --- | --- |
| 1 | `public/2026-11/gal-webp-xedc-body-cdn-final-20261103-7c2e.webp` | 360×240 | 200 `image/webp` |
| 2 | `public/2026-11/gal-png-xedc-body-cdn-final-20261103-7c2e.png` | 480×240 | 200 `image/png` |
| 3 | `public/2026-11/gal-jpeg-xedc-body-cdn-final-20261103-7c2e.webp` | 640×240 | 200 `image/webp` |

前台顺序与保存后的权重一致。没有新增排序模块。四种语言在截图后都已取消发布。

PNG 四角 alpha 为 127，中心为 0，透明度还在。JPEG 5767 字节变成 WebP 804 字节，原 JPEG Key 不存在。PNG 1690 字节变成 1247 字节，仍是 PNG。源 WebP 保持 786 字节和 WebP。四个对象的流式 SHA256 都与 R2 回读相同，没有 `public/public/`。

## 8. 原图 R2 和本地样式

当时的存储配置：profile `silly`，provider `cloudflare_r2`，bucket `dclili`，公开前缀 `public`，私有前缀 `private`。优化参数没有改：JPEG 84、WebP 82、AVIF 70，允许 WebP，不允许 AVIF，自动格式和去除元数据开启，同步阈值 3145728 字节。

用正文图生成了一次 `photo_gallery_650`。衍生文件在本地，2598 字节。对应 R2 Key `public/styles/photo_gallery_650/public/inline-images/body-xedc-body-cdn-final-20261103-7c2e.webp` 不存在。没有删除其他生产样式。

## 9. 回归

本轮没有重建上一轮已经通过的文章封面、视频封面、会员投稿和封面替换内容。正文 JPEG 和图集的 JPEG、PNG、WebP 仍走同一个 `ImageOptimizeApplier`，上传后的地址已经是优化结果，R2 前缀和 CDN 主机没有变化。图片样式没有上传到 R2。

上一轮的正文地址回写仍在生效：编辑器直接保存了 WebP，而不是已经删除的 JPEG。

## 10. 清理结果

删除前确认节点标题、文件 URI 和引用只属于这次测试。文件只被节点 557 或媒体 587–589 引用。没有删除 fid 522。

已删除节点 557、558 及其四种语言，媒体 587–589，文件 870–873，这些 fid 的优化记录，以及对应 R2 对象。原 JPEG Key 和样式 Key 都不存在。优化队列没有剩余任务。

清理后首页、美图、视频、文章栏目都不含测试 ID。`/node/557`、`/node/558` 和 `/558` 都是 404。临时登录会话和测试图片已删除。

## 11. 未完成项目

这一轮列出的阻断项都已完成。

没有单独再测会员正文插图。上一轮会员文章表单里有「从电脑上传图片」，但没有插入。那不是这次 CDN 显示失败的原因。

`full_html` 没有启用图片限制过滤器。使用 `full_html` 的正文本来就不会被这个过滤器替换。

## 12. 风险和回滚

风险：`TrustedHtmlImageSecure::process()` 复制了 Drupal 11.4.11 的核心循环，并在调用占位图之前加了 CDN 判断。以后核心如果改变这个方法，自定义类不会自动跟上。当前站点版本固定为 11.4.11。

回滚：删除 `xedc_core_filter_info_alter()` 以及 `src/Filter/` 下的两个类，再重建缓存。文本格式配置没有改动，不需要导入配置。回滚后 CDN 正文图会重新变成 16×16 占位图，其他外部图片的拦截不变。

## 13. 最终状态

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

上一轮挡住全入口验收的两项已经补上：正文 CDN 图片能在文章页显示，图集权重调整后的顺序也在前台生效。图片自动优化专项验收可以通过。

## 14. 截图

`docs/image-optimize-cdn-fix/`

- 正文：`01-body-editor.png`、`02-body-inserted.png`、`03-body-edit.png`、`04-body-front.png`
- 图集：`05-gallery-dialog.png`、`06-gallery-alts.png`、`07-gallery-selected.png`、`08-gallery-widget.png`、`09-gallery-edit-before.png`、`10-gallery-weights.png`、`11-gallery-edit-after.png`、`12-gallery-front.png`
