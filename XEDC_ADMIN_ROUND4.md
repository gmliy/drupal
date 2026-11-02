# XEDC Admin 第四轮：抽屉导航

状态：[ ] 未做 / [x] 已完成并看过真实页面截图 / [-] 没打开或没看截图 / [!] 仍有明显问题

后台主题仍是 `xedc_admin`。本轮只改 `web/themes/custom/xedc_admin/`。

截图目录：`/root/xedc-admin-round4/`。没有看过截图的项不标 [x]。

## 删除前记录：原「SEO 与网站健康」「运维中心」

这两组从导航里拿掉。路由和业务模块都不删。

| 原分组 | 名称 | 路由 | 路径 | 权限（由路由访问检查决定） |
| --- | --- | --- | --- | --- |
| SEO 与网站健康 | SEO Audit | `audit.reports` | `/admin/reports/audit` | 路由自身的访问检查 |
| SEO 与网站健康 | Site Audit | `site_audit.report` | `/admin/reports/site-audit` | 路由自身的访问检查 |
| 运维中心 | 服务器监控 | `xedc_core.monitor` | `/admin/reports/xedc-monitor` | 路由自身的访问检查 |
| 运维中心 | 文件统计 | `xedc_core.files` | `/admin/reports/xedc-files` | 路由自身的访问检查 |

运维中心里没有 Matomo 页面。全站能找到的 Matomo 后台路由只有设置页 `matomo.admin_settings_form`（`/admin/config/system/matomo`，权限 `administer matomo`）。那不是分析页，所以没有做成「Matomo 分析」菜单。

## 第七节调查：现场审核报告实体设置页

这一节先只读，结论写在改导航之前。

1. 属于模块 `site_audit_report_entity`（`web/modules/contrib/site_audit/site_audit_report_entity`）。路由名 `site_audit_report_entity.settings_form`，路径 `/admin/config/system/site-audit-report-entity`。表单类 `Drupal\site_audit_report_entity\Form\SettingsForm`。菜单链接定义在 `site_audit_report_entity.links.menu.yml`，标题 “Site Audit Report Entity”，父级 `system.admin_config_system`，所以出现在 `/admin/config` 的系统分组里，中文界面显示为「现场审核报告实体」。
2. 页面上只有一个文本框，标题是 “Example”。保存进配置对象 `site_audit_report_entity.settings` 的 `example` 键。校验还要求填进去的字必须正好是 `example`，这是模块脚手架留下的示例表单。
3. 全站 PHP 和配置里，读取这个值的只有这个表单自己的 `#default_value`。没有任何报告生成、展示或保存代码使用它。
4. 审计报告本身是另一种东西：实体类型 `site_audit_report`，列表在 Site Audit 下面的 “Saved Site Audit Reports”，实体设置在 `/admin/structure/site-audit-report`（路由 `entity.site_audit_report.settings`）。这次只打算从配置索引里藏起上面的示例设置页，不碰报告实体、字段、数据库或已有报告。

处理条件成立：没有代码读取该值，而且这个页面只是示例设置页。允许在主题里按路由名从 `/admin/config` 隐藏。设置页网址仍然可以手动打开。

## 验证清单

截图在 `/root/xedc-admin-round4/`。下面标 [x] 的都看过图。

- [x] 桌面抽屉关闭：`desk-closed-1366-light.png`。没有常驻侧栏，顶栏左侧是菜单按钮和 DcLili。
- [x] 桌面抽屉打开：`desk-open-1366-light.png`。宽 256 的白底抽屉，一级项带图标，有子菜单的右侧有箭头。
- [x] 点「内容管理」弹出二级：`desk-content-1366-light.png`。右侧面板是内容列表、创建内容、图片导入、快速发布。没有美图、视频、文章。
- [x] 切到「站点管理」：`desk-site-1366-light.png`。系统状态、服务器监控、文件统计、SEO Audit、Site Audit、日志与报告。没有 Matomo。
- [x] 「快捷方式」二级：`desk-shortcuts-1366-light.png`。列出当前用户的四条快捷方式，最后是「管理快捷方式」。
- [x] 点页面空白关闭：`desk-clickout-1366-light.png`。
- [x] 按 ESC 关闭：`desk-esc-1366-light.png`。第一次按 ESC 没关，因为焦点留在被锁住的顶栏里，键盘事件没进抽屉。改成在文档上监听 ESC 后再拍，抽屉已关上。
- [x] `/admin/content` 高亮：`desk-content-active-1366-light.png`。「内容管理」和「内容列表」都是浅灰底。
- [x] `/admin/modules` 高亮：`desk-modules-active-1366-light.png`。只有「扩展」是浅灰底。
- [x] 手机第一级：`phone-root-390-light.png`。点进「内容管理」：`phone-content-390-light.png`。返回：`phone-back-390-light.png`。点遮罩关闭：`phone-mask-390-light.png`。
- [x] 深色：`desk-content-1366-dark.png`、`phone-root-390-dark.png`、`phone-site-390-dark.png`。抽屉和二级都是深色底、浅色字。
- [x] 触摸点击汉堡和站点名：`focus-pointer-1366-light.png`。计算出的轮廓是 `none`，图上没有蓝框。
- [x] 键盘 Tab：`focus-keyboard-1366-light.png`。连按 Tab 后焦点在头像按钮上，有一圈 2 像素蓝色环。
- [x] 手机背景锁定。方法：视口 390×664，先把页面滚到 240 像素，再打开抽屉，然后滚轮再执行 `scrollTo(0, 1800)`。打开后 `body` 是 `position: fixed`，页面带 `xedc-admin-scroll-lock`。内容区域的位置从 -184 变成 -185，只差 1 像素（滚动条消失造成的），没有跟着滚走。见 `phone-scroll-lock-390-light.png`。这是无头 Chromium，不是真机 iPhone。锁定用的是 `position: fixed` 记住滚动位置，再加上抽屉 `overscroll-behavior: contain`，以及抽屉外的触摸移动被拦住。
- [x] `/admin/config` 的系统分组里不再有「现场审核报告实体」。整页文字里也搜不到这个标题。见 `config-system-1366-light.png`（系统组里是邮件、网站基本设置、统计、马托莫分析、Cron）。
- [x] Site Audit 能打开：`site-audit-1366-light.png`。已有报告还在，5 条：`site-audit-reports-1366-light.png`。
- [x] 设置页网址仍可打开：`settings-direct-1366-light.png`。页面上仍是那个「示例」输入框，没有提交。

## 处理结果

已在主题的管理索引预处理里，按路由名 `site_audit_report_entity.settings_form`（并核对路径 `/admin/config/system/site-audit-report-entity`）去掉这一条。没有改模块、路由、表单、报告实体或数据。
