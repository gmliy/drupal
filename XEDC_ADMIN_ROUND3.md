# XEDC Admin 第三轮：浅色克制外观

状态：[ ] 未做 / [x] 已完成并看过真实页面截图 / [-] 没打开或没看截图 / [!] 仍有明显问题

后台主题仍是 `xedc_admin`。本轮只改了 `web/themes/custom/xedc_admin/`。没有换回 Gin。

截图目录：`/root/xedc-admin-round3/`。标 [x] 的图都在无头浏览器里打开真实后台后看过。视口是 390×664 和 1366×800，浅色和深色都有。

颜色按这一轮的规定：浅色底 `#F7F7F8`，卡片白，主按钮 `#3F3F46`。深色底 `#18181B`，卡片 `#202024`，主按钮是浅灰底、深色字。链接和键盘焦点在浅色是 `#2563EB`。深色里为了能看清，链接和焦点改成 `#93C5FD`，没有继续用 `#2563EB`。页面上没有纯黑大块。

## 侧栏和顶栏

- [x] 品牌只剩文字「DcLili」，没有 X，没有黑块。见 `drawer-390-light.png`、`drawer-390-dark.png`。
- [x] 菜单一行一个。电脑上当前组展开，其他组收起。手机抽屉同样。见 `drawer-390-light.png`、`dropbutton-1366-light.png`。
- [x] 「系统」是底部一行。点开后在它上方列出扩展、外观、配置、日志与报告、系统状态、Drupal 管理首页。见 `system-390-light.png`。
- [x] 电脑收成细条后，停在「内容管理」上会在右侧飞出子菜单。见 `collapsed-flyout-1366-light.png`。
- [x] 顶栏面包屑单行。手机只留最后两级，第一级前面没有斜杠。见 `account-fields-390-light.png`（帐户设置 / 管理字段）、`config-390-light.png`（管理 / 配置）。
- [x] 手机上「查看网站」只留图标，头像旁不显示 admin。见 `dropbutton-390-light.png`。
- [x] 太阳 / 月亮切换。深色页打开时已经是深色。见 `admin-390-dark.png`、`quick-390-dark.png`。
- [x] 头像菜单有用户名、我的账户、退出登录。见 `avatar-390-light.png`、`avatar-1366-light.png`。

## 页面

- [x] /admin/xedc：`xedc-390-light.png`、`xedc-1366-light.png`、`xedc-1366-dark.png`。浅底、白卡片、站点名是文字。
- [x] /admin/content：`content-390-light.png`、`content-1366-dark.png`。「添加内容」是灰按钮，没有圆点。表格可横滑。
- [x] 表格「编辑」展开：`dropbutton-390-light.png`、`dropbutton-1366-light.png`、`dropbutton-1366-dark.png`。菜单贴在按钮右下，翻译、删除、查看三个字都完整。不再被下一行的按钮切掉，也不再压住语言列。菜单会盖住下一行的操作按钮，这是下拉展开后的正常位置。
- [x] /node/1/edit：`node-edit-390-light.png`、`node-edit-390-dark.png`。输入框和封面可读。这篇没有正文编辑器。
- [x] /node/7/edit 深色编辑器：`ckeditor-1366-dark.png`、`ckeditor-390-dark.png`。工具栏是深色、字能看清；写作区保持白底黑字。
- [x] /admin/structure/taxonomy：`taxonomy-390-light.png`。添加按钮没有圆点。「显示行的权重」在表格上方。
- [x] /admin/config：`config-390-light.png`、`config-1366-light.png`、`config-390-dark.png`。分组是整行链接，组与组分开。「隐藏描述」是小按钮。
- [!] /admin/config 顶部仍有 Drupal 自己的红色状态提示「检测到您的 Drupal 有一个或更多的问题」。没有把它藏起来。
- [x] /admin：`admin-390-light.png`、`admin-1366-light.png`、`admin-390-dark.png`。单列分组列表，不再是小卡片网格。
- [x] /admin/modules：`modules-390-light.png`。
- [x] /admin/people：`people-390-light.png`。「添加用户」是灰按钮。页面宽度已回到 390，不再多出 2 像素。
- [!] /admin/config/people/accounts/fields：`account-fields-390-light.png`。「创建一个新字段」正常，没有圆点。手机上标签行能横滑，但第一屏会把「管理显示」切掉一截。
- [x] /admin/content/image-imports：`imports-390-light.png`。「新建导入」是 `#3F3F46`，不再是近黑。
- [x] /admin/quick-gallery：`quick-390-light.png`、`quick-390-dark.png`。主按钮浅色是深灰，深色是浅灰底深字。

## 没做或仍旧不好

- [-] 媒体库弹窗没有打开。抽查时没有去删已选图片，也没有提交表单。
- [!] /admin/reports/dblog 这一轮没有重拍。上一轮的问题还在：消息右侧要在表格里横滑。
- [!] Site Audit 标题仍是模块自己输出的「Site Auditgitxx.xyz」，这一轮没有改模块，也没有重拍。
