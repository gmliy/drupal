# 会员互动第二轮验收说明

日期：2026-11-02

站点：https://gitxx.xyz

这是第二轮整改的验收说明。功能用法见 [engagement-round2.md](engagement-round2.md)，第一轮说明见 [engagement-membership-downloads.md](engagement-membership-downloads.md)。

本轮没有新做一套点赞、下载或收藏，也没有改 Cloudflare R2、第三方模块或在线支付。

## 问题与修复

| 问题 | 根因 | 修复内容 | 验收结果 |
| --- | --- | --- | --- |
| 游客点赞 | 点击后先禁用按钮，等 token 和接口返回才尝试打开登录框 | 点击立即提示登录并打开现有登录框，不写入点赞 | 通过。登录框 18–21ms 内打开；伪造 POST 返回 401 |
| 点赞速度 | 界面要等整次请求结束才变化 | 登录后先改按钮和数量，失败再回滚 | 通过。界面约 2ms 变化，接口约 64–106ms |
| 下载界面 | 来源不清，ZIP 点击后直接离开页面 | 对话框和底部面板显示格式、大小、来源、权限；ZIP 先查状态 | 通过。375px 底部面板可 ESC 关闭 |
| 分享界面 | 标题和长链接不清晰 | 标题为「分享内容」，链接可换行，无系统分享能力时隐藏按钮 | 通过。桌面宽 420px。系统分享成功回调未测 |
| ZIP 下载 | `private://xedc-zips` 属于 root，网站进程写不了 ZipArchive 临时文件 | 目录改为 www-data、0775。读失败不保留半成品。队列完成才标已生成 | 通过。本地原图可解压。R2 和超大包未测 |
| 点赞明细 | 后台只有次数，取消会删掉记录 | 仍用 `xedc_like`。取消改为失效。标题按节点现查，排行带封面 | 通过 |
| 下载明细 | 日志看不出内容、资源和当时等级 | 仍用 `xedc_download_log`，补来源、等级、交付方式和说明 | 通过。已授权不等于浏览器收完 |
| 后台菜单 | 左侧导航是主题里写死的，不读 Drupal 菜单父级。会员入口原来挂在 `system.admin` | 加到现有「用户管理」（id 为 `users`）下面，并停用那个一级菜单项 | 通过。浏览器已点进页面 |

## 后台菜单

左侧「用户管理」定义在 `web/themes/custom/xedc_admin/includes/navigation.theme` 的 `_xedc_admin_navigation_definitions()`，id 是 `users`。它不是 Drupal 默认的「人员」菜单。

当前顺序：

1. 会员管理
2. 内容审核
3. 会员与互动，地址 `/admin/xedc/vip`，图标沿用现有 `users`

`xedc_core.links.menu.yml` 里原来挂在 `system.admin` 上的同名项已停用，避免再出现一个一级菜单。

菜单是否显示跟路由权限一致。没有 `administer xedc vip` 时，直接打开 `/admin/xedc/vip` 会被拒绝。`content_editor`、匿名用户和普通登录用户都没有这项权限。

## 数据库

菜单和封面没有再改表。

已经执行的更新钩子是 `xedc_core_update_10008`：

- `xedc_like` 增加 `status`、`changed`。旧记录保持有效。取消点赞把 `status` 设为 0，不计入有效数量。
- `xedc_download_log` 增加 `source`、`tier`、`delivery`、`detail`。旧记录这些字段为空。
- 新建 `xedc_zip_job`。一个资源只保留最近一次状态。
- 没有新增索引，没有删除原有点赞或下载记录。
- 没有自动回滚钩子。若要撤回，应再写更新钩子删除这些字段和新表，不要手工删业务数据。

## 本轮改动的文件

菜单和排行封面：

- `web/themes/custom/xedc_admin/includes/navigation.theme`
- `web/modules/custom/xedc_core/xedc_core.links.menu.yml`
- `web/modules/custom/xedc_core/src/Engagement/EngagementReport.php`
- `web/modules/custom/xedc_core/src/Controller/EngagementAdminController.php`

点赞、ZIP、下载记录、后台明细、前台面板和会员中心的改动仍在 `xedc_core`、`xedc_member` 和前台主题 `xedc` 中。没有改第三方模块。

## 测试

```bash
vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/engagement-self-test.php
```

结果：通过 36，失败 0，未测 1。未测项是 R2。日志里的两条 `InvalidArgumentException` 是脚本故意提交非法地址。

通过：

- 游客点赞打开登录框，不能写入记录。登录用户点赞、取消、再次点赞。两个并发首次点赞只有一条有效记录。
- 公开、登录、VIP、到期、取消、外链、原图，以及私有文件直链拒绝。
- 4 张本地原图 ZIP：HTTP 200，16,743,233 字节，4 个 PNG，解压通过。
- 现有内容「对象的储存测试文件一」的压缩包：5 个 PNG，20,737,375 字节，解压通过，文件属主 www-data。
- 重名文件为 `image.jpg`、`image_2.jpg`。队列任务能被消费并标成已生成。
- 1280、375、390、430 下图片、视频、文章操作栏。下载底部面板和分享框。
- 后台总览和点赞明细使用真实数据。
- 左侧「用户管理」下有「会员与互动」。没有同名一级菜单。点击后打开 `/admin/xedc/vip`，当前项高亮。
- 匿名用户和普通登录用户访问 `/admin/xedc/vip`、`/admin/xedc/overview` 都是 403。

未测：

- R2 原图。文件记录仍是 public 130、private 11，没有 `s3://` 或 `s3fs://`。
- 超过 80 张或超过 256MB 的 ZIP。
- 手机系统分享成功回调。无头浏览器没有该能力，按钮会隐藏。
- 浏览器里打开一条已有记录的「我的下载」页面。状态文案已经按授权、生成中和失败分开。

## 性能

- 游客点赞接口：401，约 100ms。登录框反馈 18–21ms，这期间不发点赞请求。
- 登录用户：界面约 2ms 切换，服务器确认约 64–106ms。
- 后台列表每页 40 条。标题和用户名在同一次查询里取出。
- ZIP：4 张原图同步生成约 0.77–0.82 秒，队列 0.66 秒。5 张原图的现有压缩包已核对可以解压，没有重新计时。
- R2：没有可读取的对象，没有耗时。

## 安全

- VIP 每次下载都重新检查。到期和取消后，原 ZIP 地址返回 403。
- 压缩包仍在私有目录 `private://xedc-zips/`，权限 0775，属主 www-data。没有把私有文件改成公开。
- R2 的 Bucket、Endpoint、密钥和迁移配置没有改。
- 第三方模块没有改。收藏没有新建第二套。
- 原有点赞和下载记录没有删。
- 没有执行 git reset 或 git clean。
- 菜单显示和路由权限一致。

## 结论

本地文件这一套可以正式使用：游客点赞有登录引导，会员点赞先更新界面，本机原图 ZIP 可以下载并解压，点赞和下载统计能看到具体内容和资源，左侧「用户管理」下面有「会员与互动」。

还不能当成已经验证的部分是：R2 原图打包、超过 80 张或 256MB 的队列，以及手机系统分享成功回调。不需要改 R2 配置，也不需要再调整压缩目录权限。
