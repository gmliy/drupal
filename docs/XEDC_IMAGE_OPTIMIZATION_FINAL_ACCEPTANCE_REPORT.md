# XEDC 图片自动优化最后一轮专项验收

日期：2026-11-03。测试 ID：`xedc-final-20261103-3a67`。站点：https://gitxx.xyz。

这一轮只补上一轮没有完成的入口。状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。没有在浏览器里点完并保存到节点的步骤不标 PASS。

## 1. 总结

用管理员会话和普通会员 uid 311 补测了文章封面、视频封面、会员投稿、美图图集、封面替换和正文插图。封面、图集和会员投稿的图片都进了统一优化器，公开对象在 R2，MIME、大小和 SHA256 与回读一致。图片样式仍在本地，对应 R2 Key 不存在。

正文图片发现并修复了一处地址不同步：文件已经换成 WebP，编辑器却保存了已经不存在的 JPEG 地址。修复后正文地址指向 WebP，CDN 返回 200。文章页仍然不显示这张图，因为现有的 `filter_html_image_secure` 只允许本站域名，会把 `cdn.gitxx.xyz` 的图片换成 16×16 的“已移除图像”。这次没有改文本格式。

工作人员新建文章、视频和美图仍会被现有规则直接发布。前台核对后，已把中文、英文、日文和俄文全部改为未发布，没有留在栏目里。会员投稿保持未发布。测试数据已删除。

## 2. 测试环境

- Playwright 1.64.1，Chromium 156.0.8078.47。没有新装浏览器或升级依赖。
- 管理员 uid 1。会员 uid 311，用户名即测试 ID，只有 authenticated 角色。密码不记录。登录使用一次性链接，没有改管理员密码。
- Drupal 11.4.11，PHP 8.5.12。
- 存储：profile `silly`，provider `cloudflare_r2`，bucket `dclili`，公开前缀 `public/`，私有前缀 `private/`，CDN `cdn.gitxx.xyz`。抽查没有 `public/public/`。

## 3. 测试矩阵

| 测试项目 | 状态 | 真实浏览器操作 | R2 验证 | 备注 |
| --- | --- | --- | --- | --- |
| 后台 article 封面 | PASS | 上传 JPEG、选择、保存、重新打开编辑页，封面仍在。前台文章页显示红底测试图。 | 是 | 节点 549。保存后现有工作人员规则会发布，核对后已取消全部语言的发布。 |
| 后台 video 封面 | PASS | 只上传封面，没有选择视频文件。编辑页封面仍在，前台封面可见。 | 是 | 节点 550。视频文件字段为空。 |
| 会员 article 投稿 | PASS | 会员上传封面并保存，再换成第二张封面。编辑页显示第二张 WebP。不能编辑他人节点，返回 403。 | 是 | 节点 552。最终审核状态 `needs_review`，未发布。表单有“从电脑上传图片”，本轮没有在会员正文里再插一张。 |
| 会员 video 投稿 | PASS | 上传封面并提交。编辑页封面是 WebP，视频文件仍是“还没有选择文件”。 | 是 | 节点 553，`needs_review`，未发布。 |
| photo 多图图集 | PASS | 一次选择 JPEG、PNG、WebP，填写替代文本，插入并保存。编辑页三张都在，前台按这个顺序显示。 | 是 | 节点 556。PNG 四角 alpha 为 127，中心为不透明。 |
| 图集调整顺序 | NOT TESTED | 编辑页有“显示多媒体条目权重”。归档前没有改权重并保存。 | 不适用 | 没有新增排序功能。已保存的顺序就是上传顺序。 |
| 封面替换 | PASS | 在待审核会员文章上移除第一张后再选第二张，保存后引用第二张。第一张文件当时还在。 | 是 | 没有先归档再保存。 |
| 正文图片 | FAIL | 使用“从电脑上传图片”插入 JPEG 并保存。修复后编辑器地址是 WebP。前台文章页没有显示出这张图。 | 是 | 见缺陷。节点 555。 |

统计：PASS 6，FAIL 1，NOT TESTED 1，FIXED 0 作为矩阵终态。正文地址不同步已修复并复测，但整项因前台不显示记为 FAIL。

## 4. 缺陷和修复

### 正文图片保存了已经不存在的 JPEG 地址

复现：管理员在文章正文点“从电脑上传图片”，选择 JPEG。文件实体变成 `public://inline-images/body-….webp`，正文 HTML 却是 `https://cdn.gitxx.xyz/public/inline-images/body-….jpg`。前台是一个约 16 像素的破图。JPEG Key 不存在。

原因：`CKEditor5ImageController` 在上传结束后用手里的文件对象生成 URL。`hook_entity_insert` 会先清掉实体缓存，优化器再 `load()` 到另一份对象并改掉 URI。调用方这份对象仍是 JPEG，所以响应 URL 是旧地址。

修复：`web/modules/custom/xedc_core/src/Image/ImageOptimizeApplier.php` 的 `accept()`。同步优化结束后，把存储里的 URI、文件名、MIME 和大小写回调用方对象。没有改优化器选择逻辑、R2 或文本格式。

复测：新文章正文地址为 `https://cdn.gitxx.xyz/public/inline-images/body-xedc-final-20261103-3a67.webp`。CDN `200`，`image/webp`，720×420，1524 字节。同名 JPEG 为 404。

### 前台仍不显示正文图片

`basic_html` 的 `filter_html_image_secure` 只允许本站域名。渲染时把 CDN 绝对地址换成 16×16 的“已移除图像。由于安全原因，仅允许来自本地域的图像”。这不是优化器把文件写错。直接打开 CDN 地址可以拿到图片。这次没有放宽这个过滤器，也没有改公开 URL 的生成方式。

因此正文图片不能算前台显示通过。

## 5. R2 对象验证

核对方式：对象 HEAD、远端 SHA256 与流式哈希比较。原始 JPEG Key 在成功后不存在。下面是各入口最终引用的对象。重复上传产生的同内容副本已在清理时删除。

| 用途 | Key | 原始 | 结果 | MIME | SHA256 | CDN |
| --- | --- | --- | --- | --- | --- | --- |
| 文章封面 | public/2026-11/jpeg-a-xedc-final-20261103-3a67.webp | JPEG 19269 | WebP 3588 | image/webp | 4e2622daf1e14f73795e51a61b2b4e5c8fe33d740ece318ae9c14a4cc9769c6f | 浏览器前台可见 |
| 视频封面 | public/2026-11/jpeg-a-xedc-final-20261103-3a67_0.webp | JPEG 19269 | WebP 3588 | image/webp | 同上 | 浏览器前台可见 |
| 会员文章新封面 | public/2026-11/jpeg-b-xedc-final-20261103-3a67.webp | JPEG 15067 | WebP 1694 | image/webp | 06be19f4e5b46ed2ee4a8713642c77a0e1e87c823c4b403fcba32bf7108977de | HEAD 一致 |
| 会员视频封面 | public/2026-11/jpeg-a-xedc-final-20261103-3a67_2.webp | JPEG 19269 | WebP 3588 | image/webp | 与文章封面相同 | HEAD 一致 |
| 图集 JPEG | public/2026-11/jpeg-a-xedc-final-20261103-3a67_4.webp | JPEG 19269 | WebP 3588 | image/webp | 与文章封面相同 | 浏览器 naturalWidth 640 |
| 图集 PNG | public/2026-11/png-xedc-final-20261103-3a67_0.png | PNG 3150 | PNG 2150 | image/png | d92fa7a126110ad79c9c86fbaaca010bcbd4b7054a29dc705cdfc3f4ddb2cb3e | 浏览器 naturalWidth 480 |
| 图集 WebP | public/2026-11/webp-xedc-final-20261103-3a67_0.webp | WebP 1216 | WebP 1216 | image/webp | fd1ef2c54ae36a9f1a38f6a71c4596def41980b6055f9a28fbde909ae08fbb5e | 浏览器 naturalWidth 360 |
| 正文图片 | public/inline-images/body-xedc-final-20261103-3a67.webp | JPEG 17414 | WebP 1524 | image/webp | fe0c610ee91b98fb0b9bfd5e3b1679ed181d2ff46af91bc7a3c657e5d39fd156 | 200；同名 JPEG 404 |

WebP 源文件大小没有变小。优化器按现有规则在同格式且不超过原大小时重写了元数据，所以哈希和源文件不同，格式仍是 WebP。

样式：文章封面的 `xa_hero` 衍生文件在 `web/sites/default/files/styles/`，对应 `public/styles/...` Key 不存在。图集页同时引用了 CDN 原图和本地 `photo_gallery_650` 样式。没有删除其他生产样式。

## 6. 截图目录

`docs/image-optimize-final-acceptance/`

- 文章封面：`01-article-selected.png`、`03-article-edit.png`、`04-article-front.png`
- 视频封面：`10-video-selected.png`、`12-video-edit.png`、`13-video-front.png`
- 正文：`05-article-body-widget.png`、`06-article-body-inserted.png`、`08-article-body-edit.png`、`09-article-body-front.png`
- 图集：`22-gallery-three.png`、`22-gallery-selected.png`、`24-gallery-edit.png`、`25-gallery-front.png`、`26-gallery-dialog.png`
- 会员文章：`30-member-article-selected.png` 到 `35-member-article-review.png`
- 会员视频：`40-member-video-selected.png`、`41-member-video-saved.png`、`42-member-video-edit.png`

## 7. 清理结果

删除前确认标题或文件名含测试 ID，且引用只来自这些测试节点、媒体、编辑器引用和 uid 311。全部语言都已是未发布。

已删除节点 549–556、媒体 576–586、14 个文件实体和对应 R2 对象、这些 fid 的优化审计、会员 uid 311。uid 1 的头像 fid 522 仍在。清理后首页、美图、视频、文章和一条视频详情都不含测试 ID，`/node/556` 为 404。抽查的测试 Key 已不存在。队列里没有本轮任务。`/tmp/xedc-final-work/` 已删除。

## 8. 最终结论

- 后台文章封面：通过。
- 后台视频封面：通过。
- 会员文章投稿：封面上传、替换和待审核通过。会员正文图片没有单独插入。
- 会员视频投稿：通过，封面经过优化，视频文件没有被处理。
- photo 多图图集：三张图的上传、优化、引用和前台顺序通过。权重调整没有保存。
- 封面替换：通过。旧文件没有在替换时被删除。
- 正文图片：不通过。地址不同步已修复，前台仍被图片安全过滤器挡住。
- 全入口验收还不能算完成。剩下的阻断项是正文图片在文章页不显示。图集权重调整也还没有浏览器保存证据。
