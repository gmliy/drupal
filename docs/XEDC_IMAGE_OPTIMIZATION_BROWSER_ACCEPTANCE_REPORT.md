# XEDC 图片自动优化全入口浏览器验收报告

日期：2026-11-03。测试 ID：`bacc7ad0f2`。站点：https://gitxx.xyz。

状态只用 PASS、FIXED、FAIL、NOT TESTED、BLOCKED。浏览器里没有点完的步骤不标 PASS。Drush 读取配置、队列执行和 R2 HEAD 用来核对结果，不代替浏览器操作。

## 1. 总结

已用真实管理员会话和真实会员会话走过媒体库上传、后台美图封面、会员投稿封面、会员头像、快速发布、超过 3MB 的排队图片，以及 ZIP / 7z 导入。这些路径上的新图片都进了统一优化器，公开对象在 Cloudflare R2，File Entity、MIME、大小和 SHA256 与回读一致，CDN 返回 200。图片样式仍在本地 `sites/default/files/styles/`，对应 R2 Key 不存在。

不能认为全入口验收已经完成。后台文章封面、视频封面、美图图集、GIF、正文图片和封面替换没有在浏览器里保存到节点。损坏图片、空文件、非法 SVG、安全 SVG、媒体库超过 20MB、优化失败重试，以及 R2、数据库、磁盘故障没有做。

生产上发现并修复了一处导入校验：对象存储上的正式图片 `realpath()` 为空，ZIP / 7z 虽然优化并写入了 R2，任务仍被记成失败，节点没有挂上图片。修复后两条导入都变成“等待审核”，节点未发布。测试数据已删除。

本轮没有留下未发布的测试内容。验收过程中，只归档当前语言时，英文、日文和俄文译本仍是发布状态，视频详情的相关推荐曾出现测试标题。补归档全部译本后页面不再包含测试 ID。这不是优化器缺陷，内容已删除。

## 2. 测试环境

- 浏览器：Playwright 自带 Chromium 156.0.8078.47（`chromium-1248`）。系统里已有 snap Chromium 156.0.8078.47，没有新装浏览器或升级 Playwright、Node、PHP。
- Playwright：1.64.1，路径沿用机器上已有的 npx 缓存。
- 账号：uid 1，管理员。另建 uid 310，用户名 `xedc-bacc7ad0f2`，只有 authenticated 角色。密码不记录。登录使用站点已有的一次性登录链接，没有改密码。
- Drupal 11.4.11。PHP 8.5.12。
- 存储：profile `silly`，provider `cloudflare_r2`，bucket `dclili`，公开前缀 `public/`，私有前缀 `private/`。CDN 域名 `cdn.gitxx.xyz`。抽查的公开 Key 都是 `public/...`，没有 `public/public/`。
- 测试 ID：`bacc7ad0f2`。素材在 `/tmp/xedc-bacc-work/assets/`，验收后连同会话文件删除。截图在 `docs/image-optimize-browser/`。

运行中的优化配置由 `ImageOptimizeRepository::settings()` 读出，不是照任务文字填写：

| 项 | 实际值 |
| --- | --- |
| JPEG 质量 | 84 |
| WebP 质量 | 82 |
| AVIF 质量 | 70 |
| 允许 WebP | 是 |
| AVIF | 关 |
| 自动选择更小格式 | 是 |
| 清理隐私元数据 | 是 |
| 同步阈值 | 3145728（3MB） |
| 单文件上限 | 20971520（20MB） |
| 最大像素 | 12000000 |
| 最大处理时间 | 20 秒 |
| 重试 | 2 |
| 队列并发 | 1 |

配置里只保存了 `sync_bytes`，其余使用代码默认值。读到的有效值与基线一致。

## 3. 测试素材

文件名都含测试 ID。宽高来自生成时的图片头。这些文件没有被发布到正式栏目。

| 文件 | 字节 | SHA256 | MIME | 尺寸 |
| --- | ---: | --- | --- | --- |
| jpeg-bacc7ad0f2.jpg | 75811 | 0e1a7516b720d22e4f8304a91f742e603000d59aee3cf064fa9843ab34d11955 | image/jpeg | 640×480 |
| jpeg-b-bacc7ad0f2.jpg | 46253 | 52948224fbc0985b3c7d4a4e2c25e46fab6ed0a7806d112fa6564c05284e3750 | image/jpeg | 520×360 |
| png-bacc7ad0f2.png | 4922 | e497ae66f689a362781a568772454f6237dd4f77e6d1fd958050aca3f41bbb36 | image/png | 480×360，含透明 |
| png-b-bacc7ad0f2.png | 7831 | 0c3f731cb1e9372aad7b80b9972d2a10e7443165ababb34f4d9e4b5321a88c2b | image/png | 400×280 |
| webp-bacc7ad0f2.webp | 8960 | 517016d6177c2271baf6b88ee6e4954fbc504ca9d71363bd7d03687de510253b | image/webp | 360×240 |
| gif-bacc7ad0f2.gif | 74661 | 40f978398f7a7c3f80d1e7994da9e26dfb395aed4a820eb56f5e365ed8b17f68 | image/gif | 320×200，4 帧 |
| safe-bacc7ad0f2.svg | 297 | a730f078fb6b3a554155d44252511b3e6a352282178085b90b225c95a3f42408 | image/svg+xml | 240×160 |
| bad-bacc7ad0f2.svg | 135 | 26a5731fd16e1a87442c14dcbc1c97ff243ac4f47ed64d19622af49194949b9c | image/svg+xml | 含 script |
| broken-bacc7ad0f2.jpg | 146 | dcebb977c42747e89c499347d3aaf8452d7078da793b229f8a56a310f9dd81cf | application/octet-stream | 无 |
| empty-bacc7ad0f2.jpg | 0 | e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855 | 空 | 无 |
| queue-bacc7ad0f2.jpg | 4716233 | ac92bf753af3e82119327deccc9b2fc50f4d8f556980039fc960d204e7d7d5d1 | image/jpeg | 1800×1400 |
| huge-bacc7ad0f2.jpg | 27371250 | 5a4820ad35040960f78c3812053e1ed8b0eb6b490caf92cf56b0a66de55803fd | image/jpeg | 4000×3000 |
| pack-bacc7ad0f2.zip | 67406 | 40a5d17f014384e43a341180c0b892b2e404a3318a22bec704fd15373f4d8a3d | application/zip | 两张图 |
| pack-bacc7ad0f2.7z | 46986 | f9bb2b2c71ae406e84c87ff8588e1ce68fdbca3607dc0a35641356ebb9f53c0b | application/x-7z-compressed | 两张图 |
| evil-bacc7ad0f2.zip | 78952 | e4b4c42163274412b9b7bba2b855a38522396abfd4424c549eb62a2ea1985c0e | application/zip | 含 `../` |
| over-bacc7ad0f2.zip | 27373507 | 6a469671291a7eb80d459b145b3a8c895d099c7c9a35944f49e56d49f55f1907 | application/zip | 含超限 JPEG |

## 4. 测试明细

| 项 | 状态 | 结果 |
| --- | --- | --- |
| 后台 photo 新建封面 | PASS | 媒体库对话框上传 JPEG。保存前文件名已是 `.webp`。节点 537 封面指向该媒体。预览可见。 |
| 后台 photo 替换封面 | BLOCKED | 编辑页曾选中替换后的 WebP，随后保存被“从已撤下到已撤下的状态转换无效”拦住。原因是本轮先把节点归档，归档到归档不是合法转换。节点仍保留第一张封面。替换文件本身已优化。 |
| 后台 photo 图集 | NOT TESTED | 第二张 PNG 的媒体库控件等待超时，编辑页图集仍是空的。 |
| 后台 photo GIF | NOT TESTED | 同样超时，没有产生 GIF 文件实体。 |
| 后台 article 封面 | NOT TESTED | 上传生成了已优化的媒体，编辑页封面仍是“没有选中多媒体条目”。节点封面为空。正文图片没有上传。 |
| 后台 article 正文图片 | NOT TESTED | 只确认编辑器有按钮，没有插入图片。 |
| 后台 video 封面 | NOT TESTED | 与文章相同，编辑页封面为空。节点没有封面。 |
| Media Library | PASS | 打开对话框、选择文件、填写替代文本、保存并插入。正式 URI 是 `public://2026-11/...webp`，不是 `temporary://`。同一文件只有一条 success 审计。 |
| 会员投稿 photo | PASS | 节点 546，封面已挂上，审核状态 `needs_review`，未发布。 |
| 会员投稿 article 图片 | NOT TESTED | 节点 547 进入待审核，封面没有挂上。 |
| 会员投稿 video 封面 | NOT TESTED | 节点 548 进入待审核，封面没有挂上。 |
| 会员权限 | PASS | 投稿页状态只有 `needs_review` 和 `draft`。会员打开快速发布得到拒绝访问。会员打开他人节点编辑得到 403。 |
| 会员头像 | PASS | 资料页上传 JPEG。头像 URI 为 `public://pictures/jpeg-bacc7ad0f2.webp`。会员中心和 `/r/bacc7ad0f2` 都能显示，图片没有破损。显示地址是本地样式，不是 CDN 原图。 |
| 快速发布 | PASS | 页面上传后可预览。入库对象是 WebP。页面脚本会先把 JPEG 转成 WebP 再提交，服务器收到的已是 WebP，并再次优化（20592 → 19314）。没有额外的 JPEG Key。 |
| ZIP 导入 | FIXED | 见缺陷。复测后任务 141 为 review，节点 542 两张图，`needs_review`，未发布。 |
| 7z 导入 | FIXED | 与 ZIP 同一缺陷。复测后任务 142 为 review，节点 543 两张图，未发布。其中 PNG 优化后仍是 PNG。 |
| 路径穿越 ZIP | PASS | 浏览器提交后，Worker 失败信息是“压缩包包含不安全路径或目录过深。”没有公开图片。节点 544 保持草稿且未发布。 |
| 超过 20MB 的导入 | PASS | 包内 JPEG 为 27371250 字节。条目失败信息是“图片超过安全大小。”任务“没有成功导入的图片。”节点 545 保持草稿且未发布。 |
| 超过 3MB 的排队 | PASS | 浏览器上传耗时 1670ms，当时状态 `queued`，URI 仍是 `.jpg`。没有在这次请求里同步压完。 |
| 排队时发布 | PASS | 保存后出现“有图片还在优化或优化失败，内容已保持为草稿。”节点 540 为 draft，未发布。 |
| Worker | PASS | 队列里当时只有这一条优化任务。`drush queue:run xedc_image_optimize --items-limit=1` 处理 1 条，约 4.31 秒。完成后审计 success，对象变为 WebP。没有同时启动第二个 Worker。 |
| 重试与并发 | NOT TESTED | 成功任务的 attempts 为 0。没有制造失败来看重试，也没有并行启动多个 Worker。 |
| 前台页面 | PASS | 首页、美图列表、美图详情、视频列表、视频详情、文章列表、文章详情均为 200，可见图片没有 naturalWidth 为 0 的破图。截图 `40-*.png`。 |
| 会员中心和公开主页 | PASS | `/member` 落到 `/bacc7ad0f2`，标题“会员中心”。`/r/bacc7ad0f2` 标题“会员”。两页头像都正常。截图 `41-member-center.png`、`42-member-public.png`。 |
| 已压缩 WebP | PASS | 8960 字节的 WebP 优化后仍是 WebP，大小不变，没有强行换格式。 |
| 重复上传同一 JPEG | PASS | 同一次验收里多次上传同一 JPEG，每次新建一个文件，各有一条 success 审计，没有对同一个 fid 压两次。系统不按内容合并重复文件。 |
| 损坏图片、空文件 | NOT TESTED | 素材已准备，没有通过浏览器提交。 |
| 安全 SVG、非法 SVG | NOT TESTED | 媒体图片字段允许的扩展名没有 SVG，浏览器入口到不了优化器。没有改字段配置。 |
| 媒体库超过 20MB | NOT TESTED | 该字段上限是 10MB。20MB 文件到不了优化器。导入路径的 27MB 样本已测。 |
| 优化结果大于原文件 | NOT TESTED | 没有单独构造“候选文件更大所以必须退回原图”的样本。WebP 保持原大小只说明没有更小结果时不替换。 |
| 优化失败后的源文件 | NOT TESTED | 没有在浏览器里提交损坏图片。导入拒绝的超限包没有留下公开图片。 |
| R2、数据库、磁盘故障 | NOT TESTED | 不在生产上制造这些故障。 |

统计：PASS 16，FIXED 2，FAIL 0，BLOCKED 1，NOT TESTED 17。

## 5. 上传入口矩阵

| 入口 | 状态 | 说明 |
| --- | --- | --- |
| 后台 photo | PASS | 新建封面已验证。图集、GIF、替换封面没有完成。 |
| 后台 article | NOT TESTED | 打开了编辑页并产生过已优化媒体，节点没有保存封面或正文图片。 |
| 后台 video | NOT TESTED | 打开了编辑页并产生过已优化媒体，节点没有保存封面。 |
| Media Library | PASS | 上传、选择、插入和正式文件都核对过。 |
| 会员投稿 | PASS | photo 封面和待审核状态已验证。article、video 的图片字段没有挂上。 |
| 会员头像 | PASS | 优化后进入 `public://pictures/`，页面样式能显示。 |
| 快速发布 | PASS | 预览和正式 WebP 对象已验证。浏览器在提交前已把 JPEG 转成 WebP。 |
| ZIP 导入 | FIXED | 完整任务复测通过，内容未发布。 |
| 7z 导入 | FIXED | 完整任务复测通过，内容未发布。 |

## 6. R2 证据

核对方式：对象 Key 用存储 URI 解析，HEAD 取远端大小和 MIME，远端 SHA256 与流式 `hash_file` 比较。CDN 用 `https://cdn.gitxx.xyz/{key}` 的响应状态。原始 JPEG/PNG Key 在成功后 HEAD 为不存在。

同步上传在移入 `public://` 之前优化，正式 Key 从一开始就是结果格式。排队那张在请求结束时 URI 还是 `public://2026-11/queue-bacc7ad0f2.jpg`，本地还生成过名为 `queue-bacc7ad0f2.jpg.avif` 的样式文件；Worker 完成后 JPEG Key 不存在，WebP Key 存在。排队期间没有单独做一次 JPEG Key 的 HEAD，因此不能把“当时一定已经写进 R2”说成已观察事实。

| 用途 | Key | 原始 | 结果 | MIME | SHA256 | CDN | 原 Key |
| --- | --- | --- | --- | --- | --- | --- | --- |
| photo 封面 | public/2026-11/jpeg-bacc7ad0f2_5.webp | JPEG 75811 | WebP 20118 | image/webp | 9f54456f5c56ed184b635915c16e3d737b22606ae1e12b7c7279a06a7adbb880 | 200 | 不存在 |
| 已压缩 WebP | public/2026-11/webp-bacc7ad0f2.webp | WebP 8960 | WebP 8960 | image/webp | 517016d6177c2271baf6b88ee6e4954fbc504ca9d71363bd7d03687de510253b | 200 | 不存在额外副本 |
| 透明 PNG | public/2026-11/png-bacc7ad0f2.webp | PNG 4922 | WebP 3330 | image/webp | 5cb5665d33352e32ec8e272388adf10a232c40cfe9f90669fa4c67293fa4dd4c | 200 | PNG Key 不存在 |
| 排队大图 | public/2026-11/queue-bacc7ad0f2.webp | JPEG 4716233 | WebP 1786214 | image/webp | 360933a30e1e38641648a8936fca5854a768920a44197ca0e5beb185896e36c3 | 200 | `.jpg` 不存在 |
| 快速发布 | public/gallery/2026-11/bacc7ad0f2-quick-001.webp | WebP 20592 | WebP 19314 | image/webp | 25186c4f6c87e8f2d7aaca3dfcfacc849cb727024c33367a49e13792ed03d943 | 200 | `.jpg` 不存在 |
| 会员头像 | public/pictures/jpeg-bacc7ad0f2.webp | JPEG 75811 | WebP 20118 | image/webp | 9f54456f5c56ed184b635915c16e3d737b22606ae1e12b7c7279a06a7adbb880 | 200 | `.jpg` 不存在 |
| ZIP 第 1 张 | public/2026-11/n542-001.webp | JPEG 75811 | WebP 20118 | image/webp | 9f54456f5c56ed184b635915c16e3d737b22606ae1e12b7c7279a06a7adbb880 | 200 | `.jpg` 不存在 |
| ZIP 第 2 张 | public/2026-11/n542-002.webp | PNG 4922 | WebP 3330 | image/webp | 5cb5665d33352e32ec8e272388adf10a232c40cfe9f90669fa4c67293fa4dd4c | 200 | `.png` 不存在 |
| 7z 第 1 张 | public/2026-11/n543-001.webp | JPEG 46253 | WebP 15152 | image/webp | b0d1688732e9cc612c333661b5e3d4d5477ec8363ffd6dc6d2890d4b7c0e548c | 200 | `.jpg` 不存在 |
| 7z 第 2 张 | public/2026-11/n543-002.png | PNG 7831 | PNG 7515 | image/png | b503589f615f18f70bec5dc8a07e64c04ad64530a719247d1e2dd0a308c332da | 200 | `.webp` 不存在 |

同一张 JPEG 在探测上传里还产生过 `_0` 到 `_8` 等 Key，大小、MIME 和 SHA256 与封面那条相同。它们都已删除。私有导入包在 `private/xedc-import/tasks/{141-144}/source/`，不走公开 CDN。

图片样式：头像的 `thumbnail` 与 `max_325x325`、封面和快速发布图的 `medium`、`media_library`、`meitu_card_cover` 都在 `web/sites/default/files/styles/` 下，文件名含测试 ID。对应的 `public/styles/...` Key HEAD 不存在，CDN 为 404。没有删除其他图片的样式缓存。验收结束时，含测试 ID 的样式文件也已删除。

## 7. 前台页面

| 页面 | 结果 | 截图 |
| --- | --- | --- |
| 首页 `/` | 200，可见图片无破损，页面不含测试 ID | `docs/image-optimize-browser/40-home.png` |
| 美图列表 `/meitu` | 200，无破损，不含测试 ID | `40-photo-list.png` |
| 美图详情 | 200，无破损 | `40-photo-detail.png` |
| 视频列表 `/video` | 200，无破损，不含测试 ID | `40-video-list.png` |
| 视频详情 | 200，无破损。清理前相关推荐曾链到未完成归档的测试视频，补归档后不再出现 | `40-video-detail.png` |
| 文章列表 `/articles` | 200，无破损，不含测试 ID | `40-article-list.png` |
| 文章详情 | 200，无破损 | `40-article-detail.png` |
| 会员中心 `/bacc7ad0f2` | 200，头像样式正常 | `41-member-center.png` |
| 公开主页 `/r/bacc7ad0f2` | 200，头像样式正常。用户删除后该地址为 404，正文只剩下请求路径本身 | `42-member-public.png` |

后台操作截图也在同一目录，包括媒体库对话框、上传后的 WebP 预览、排队保存、优化中心、快速发布和导入表单。

## 8. 缺陷及修复

### 导入完成后被判“正式图片文件缺失”

复现：浏览器在 photo 表单上传 `pack-bacc7ad0f2.zip` 和 `pack-bacc7ad0f2.7z`，保存后得到任务 141、142 和草稿节点 542、543。Worker 解压、优化并写入 R2，两条图片记录都是 success。任务随后失败，错误是“正式图片文件缺失。”节点图片数是 0，仍未发布。

入口：现有图片导入页的 ZIP / 7Z 字段，队列 `xedc_image_import`。

原因：`ImportProcessor::assertStored()` 用 `realpath()` 得到本地路径，再 `is_file()` 和 `hash_file()`。公开原图在 R2 上，`realpath()` 为空，于是被当成文件缺失。用 URI 打开同一个对象时，`is_file()`、`filesize()` 和 `hash_file()` 都正常，哈希与已保存的 SHA256 一致。

修复文件：`web/modules/custom/xedc_core/src/ImportProcessor.php`。本地 `realpath()` 可用时仍用本地路径；否则用文件 URI。没有改优化器、R2 配置或导入状态机。

复测：把任务 141、142 放回导入队列后再跑 Worker。两条都是“导入完成，等待审核”。节点 542、543 各有 2 张图和封面，审核状态 `needs_review`，`isPublished()` 为 0。路径穿越包和超限包没有重跑，它们本来就应该失败。

### 观察，不是优化器缺陷

管理员新建 photo、article、video 和快速发布时，现有逻辑会把新内容发布，并带上英文、日文、俄文译本。本轮为了不把测试内容留在前台，对当前语言做了归档。归档只把中文设为未发布，其他语言的 `status` 仍为 1。视频相关推荐按 `status = 1` 查询，因此公开视频页出现了测试标题；直接打开该节点是 403。随后把这些译本也改为未发布，公开页面不再含测试 ID。清理时节点已全部删除。这个问题不在图片优化器里，本轮没有改翻译或视频推荐代码。

快速发布页未勾选直接发布，工作人员保存仍会发布。这也是现有规则。图片本身已优化。测试节点最后是未发布，并已删除。

## 9. 清理结果

删除前核对了标题、文件名或 URI 含 `bacc7ad0f2`，以及这些节点和媒体引用的文件。文件引用只允许来自本轮节点、媒体、uid 310 和导入任务 141–144。uid 1 的原头像 fid 522（`public://pictures/IMG_9619.jpeg`）不在删除名单里，清理后仍在。

已删除：

- 节点 535–548，含全部语言译本。
- 媒体 560–575。
- 文件 828–855，共 28 个，含公开图片和 4 个私有压缩包。
- 导入任务 141–144、对应条目和日志。
- 这些 fid 的优化审计和导入文件记录。
- uid 310 及其 `/bacc7ad0f2` 别名。
- `sites/default/files/styles/` 下文件名含测试 ID 的衍生图。
- `private://xedc-import/tasks/141` 到 `144`。
- `/tmp/xedc-bacc-work/`，其中有登录会话，不进入仓库。

删除后抽查：上述公开和私有 Key HEAD 为不存在；`file_managed`、导入任务和优化审计里不再有测试 ID；优化队列和导入队列为 0；首页、美图、视频、文章和原先出现过测试标题的视频详情都不含测试 ID。没有枚举或删除其他历史对象。

## 10. 最终结论

不能把新图片自动优化视为已经完成全入口验收。

已经核实的入口是：后台 photo 新建封面、Media Library、会员 photo 投稿、会员头像、快速发布、ZIP 导入、7z 导入，以及超过同步阈值的队列。这些入口的新图片经过统一优化器，结果在 R2，Drupal 引用和 CDN 访问正常，样式仍在本地。

仍未验证：后台 article 封面和正文图片、后台 video 封面、photo 图集、GIF、封面替换的保存、会员 article 和 video 的图片字段、浏览器中的损坏图片、空文件、SVG、媒体库 20MB 上限、失败重试，以及 R2、数据库、磁盘故障。

上线前需要处理的是已经修复的导入校验。不修的话，ZIP / 7z 会优化成功但任务失败，草稿节点挂不上图片。文章、视频和多图字段没有浏览器证据，不能靠这次验收放行。失败注入按要求没有在生产上做。
