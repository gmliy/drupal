# XEDC Admin 第二轮：视觉修复清单

状态：[ ] 未做 / [x] 已完成并有真实页面截图 / [-] 已做但未看截图 / [!] 仍有明显问题

后台主题现在是 `xedc_admin`。本轮只改了 `web/themes/custom/xedc_admin/`。

截图目录：`/root/xedc-admin-round2/`。修之后的图带 `-after`。标 [x] 的文件都在无头浏览器里打开真实后台后看过。

## 通用组件

- [x] 二级标签：分段按钮，手机上横向滚动。见 `admin-390-after.png`、`admin-modules-390-after.png`、`admin-content-390-after-y520.png`
- [x] 管理索引：白卡片，标题加说明，手机单列。见 `admin-390-after.png`、`admin-1366-after.png`、`admin-structure-390-after.png`、`admin-config-390-after.png`、`admin-reports-390-after.png`
- [x] 表格：外层可横向滑动，行高正常，编辑按钮可见。见 `admin-content-390-after-y520.png`、`admin-people-390-after.png`、`admin-structure-taxonomy-390-after.png`
- [x] 表单：输入框、下拉、按钮有统一高度。保存按钮约 40–44px。见 `node-7-edit-390-after-y1250.png`、`admin-structure-taxonomy-390-after.png`
- [x] 折叠面板、黄色警告、筛选条。见 `admin-modules-390-after.png`、`node-7-edit-390-after-y2100.png`
- [x] 页头上误显示的「配置区块 / 移除区块」灰框已隐藏。见 `admin-390-after.png`、`admin-modules-390-after.png`

## 页面

- [x] /admin：`admin-390-after.png`、`admin-1366-after.png`。手机是单列卡片，电脑是三列。
- [x] /admin/xedc：`admin-xedc-390-after.png`。欢迎卡和统计卡正常，没有灰圆块。
- [x] /admin/content：`admin-content-390-after-y520.png`、`admin-content-1366-after.png`。手机上标题和「编辑」可见；作者、时间要在表格里向左滑。
- [x] /node/1/edit：`node-1-edit-390-after.png`、`node-1-edit-390-after-y700.png`。封面、单选、上传按钮正常。
- [x] /node/7/edit：`node-7-edit-390-after-y1250.png`、`node-7-edit-390-after-y2100.png`、`node-7-edit-390-after-y2500.png`。保存、预览、删除尺寸正常。编辑器工具栏在手机上折成两行。
- [x] /admin/structure：`admin-structure-390-after.png`
- [x] /admin/structure/taxonomy：`admin-structure-taxonomy-390-after.png`、`admin-structure-taxonomy-1366-after.png`。名称、描述、操作都在屏内。
- [x] /admin/structure/taxonomy/manage/photo_category/overview：`admin-structure-taxonomy-manage-photo_category-overview-390-after.png`
- [x] /admin/modules：`admin-modules-390-after.png`、`admin-modules-1366-after.png`。描述在名称旁边，警告条没有灰圆点。
- [x] /admin/appearance：`admin-appearance-390-after.png`
- [x] /admin/config：`admin-config-390-after.png`
- [x] /admin/people：`admin-people-390-after.png`。用户名、状态、「编辑」可见；后面的列要横向滑。
- [x] /admin/reports：`admin-reports-390-after.png`
- [!] /admin/reports/dblog：`admin-reports-dblog-390-after.png`。类型、日期、消息开头能看见，消息右侧和用户名仍在表格滑动区里，没有完整落在第一屏。
- [x] /admin/content/image-imports：`admin-content-image-imports-390-after.png`。统计卡是两列，说明文字会换行。
- [x] /admin/quick-gallery：`admin-quick-gallery-390-after.png`
- [x] /admin/reports/audit：`admin-reports-audit-390-after.png`。名称、分数和「运行并查看」可见。
- [!] /admin/reports/site-audit：`admin-reports-site-audit-390-after.png`、`admin-reports-site-audit-390-after-y1400.png`。标题仍是「Site Auditgitxx.xyz」，中间没有空格，这是模块自己输出的。缓存类名能换行，但页面仍比手机略宽。

## 没打开的弹窗

- [!] 媒体库弹窗。文章封面已经选满，添加按钮是禁用的。没有点「移除」，所以没有打开弹窗，也没有改内容。
- [-] 编辑器按钮只看了工具栏，没有逐个点开下拉。见 `node-7-edit-390-after-y2100.png`。

## 仍看得出的小问题

- 「显示行的权重」贴在表格卡片右上角，和表头靠得很近。分类页和标签字段都有。
- 内容列表、人员列表、日志在手机上不能一屏放下所有列，多出来的列在表格内部横滑。操作按钮保持在右侧可见。
