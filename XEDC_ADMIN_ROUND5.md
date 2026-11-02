# XEDC Admin 第五轮：导航间距和网站配置菜单

状态：[ ] 未做 / [x] 已完成并看过真实页面截图 / [-] 没打开或没看截图 / [!] 仍有明显问题

后台主题仍是 `xedc_admin`。本轮只改 `web/themes/custom/xedc_admin/`。

截图目录：`/root/xedc-admin-round5/`。没有看过截图的项不标 [x]。

第一轮截图里，三级文字和二级标题左缘相同。原因是二级行的左内边距盖过了三级。改成三级左内边距 50 像素（再加外层 8 像素，文字距面板左缘 58 像素）后重新截了配置菜单。

## 验证清单

- [x] 手机一级菜单：`phone-l1-390-light.png`、`phone-l1-390-dark.png`。抽屉宽 232。箭头宽 14，颜色 `#A1A1AA`，离文字 10 像素，垂直居中。行高、字号、图标没有改。
- [x] 手机「内容管理」二级：`phone-content-390-light.png`、`phone-content-390-dark.png`。圆角行。文字左缘 46，和一级文字对齐。返回箭头和标题「内容管理」左缘都是 20，和一级图标对齐。右上角有关闭。
- [x] 手机「快捷方式」二级：`phone-shortcuts-390-light.png`、`phone-shortcuts-390-dark.png`。「管理快捷方式」上方有分隔线，文字是弱化灰。
- [x] 手机「网站配置」全部收起：`phone-config-collapsed-390-light.png`。顶部是「全部配置」，下面是人员、系统、内容写作、用户界面、媒体、开发、搜索及元数据、区域和语言、Web服务、工作流。没有「现场审核报告实体」。
- [x] 展开「系统」：`phone-config-open-one-390-light.png`、`phone-config-open-390-dark.png`。箭头朝上。三级比二级标题再缩进 12 像素。「SMTP(邮件传输)认证支持」折成两行，没有溢出。
- [x] 再展开「人员」，「系统」收起：`phone-config-open-other-390-light.png`。同时只开着一组。
- [x] 点「帐户设置」后进入该页，抽屉关掉：`phone-config-after-click-390-light.png`。页面没有横向滚动条。本地任务标签在 390 宽里显示不全，见下面的 [!]。
- [x] 跳转后再打开菜单，当前页高亮：`phone-config-active-390-light.png`。「网站配置」「人员」「帐户设置」为当前项，「人员」自动展开。
- [x] 手机配置页内部滚动，返回栏固定：`phone-config-scrolled-390-light.png`。展开「系统」后内容 856、可视 664，滚到 160。返回和关闭仍在顶部，标题滚走了，背后页面没有动。
- [x] 桌面「内容管理」飞出：`desk-content-1366-light.png`、`desk-content-1366-dark.png`。抽屉仍是 256。二级文字距飞出面板左缘 46。标题距左缘 20。右上角有关闭。返回按钮在桌面不显示。
- [x] 桌面「网站配置」收起：`desk-config-collapsed-1366-light.png`。面板宽 260。
- [x] 桌面展开一组：`desk-config-open-1366-light.png`、`desk-config-open-1366-dark.png`。展开的是子项最多的「媒体」。三级再缩进 12 像素。深色下文字可读。
- [!] 桌面面板内部滚动：`desk-config-scroll-1366-light.png`。看过这张图，它和展开图一样。「媒体」展开后面板高 733，上限 744（窗口 800 减去顶栏 56），滚动位置仍是 0，背景页面也是 0。内容没有超出，所以不能把「已经滚过」标成完成。超出时会在面板内滚动的样式已经写上（`max-height`、`overflow: auto`、`overscroll-behavior: contain`）。
- [x] 长标题不溢出，整页没有横向滚动。手机 SMTP 标题高 38，折成两行。各次测量里文档横向滚动宽度都不大于可视宽度。
- [!] 手机账户设置页的本地任务在 390 宽里被切掉一截（`phone-config-after-click-390-light.png`）。整页没有横向滚动条。这轮没有改标签样式。
