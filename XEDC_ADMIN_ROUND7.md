# XEDC Admin 第七轮：下拉菜单、表格滑动、弹窗

状态：[ ] 未做 / [x] 已完成并看过真实页面截图 / [-] 没打开或没看截图 / [!] 仍有明显问题

后台主题仍是 `xedc_admin`。只改了 `web/themes/custom/xedc_admin/`。没有改前台主题，没有提交表单，没有新建或删除内容。没有跑「运行所有审核」。

截图目录：`/root/xedc-admin-round7/`。没有看过截图的项不标 [x]。

## 下一批从这里继续

A、B 已看过图。从 **C 弹窗和视图编辑页** 接着做。然后 D 进度条（真实批处理页最多再打开 2 次）、E `/node/add`、F 扫描器、G 第六轮遗留。红线不变。

## A 表格里的下拉操作菜单

### 根因（改之前量过）

打开 `/admin/structure/types` 第一行「管理字段」。当时菜单还在表格里面。

1. 菜单的 `<ul class="dropbutton">` 留在单元格里。旧脚本只把每一条 `.secondary-action` 设成 `position: fixed`，节点仍是表格的后代（`inBody: false`）。
2. `.xedc-admin-table-wrap` 写了 `overflow-x: auto`。一条轴不是 `visible` 时，另一条会被算成 `auto`，所以卡片实际是 `overflow: auto`。再加 `border-radius: 12px`，菜单会被卡片裁切。外层 `.xedc-admin-content`、`.xedc-admin-shell`、`.xedc-admin` 还有 `overflow-x: clip`，菜单只要没挂到 `body`，就越不过这些盒子。
3. 操作列是 `position: sticky; right: 0`。鼠标还停在按钮上时，`tr:hover td:has(.dropbutton-wrapper)` 把单元格涂成 `#F4F4F5`。量到的背景是 `rgb(244, 244, 245)`。
4. 层级不统一：顶栏 30，抽屉 50，菜单条目曾经写成 400。
5. 这一页表格很矮，菜单刚好还在卡片里面，所以改之前那张图没有被切掉一半。最后一行、短视口时同一套固定定位会伸出卡片被裁切。改之前的图：`a-before-types-1366-open.png`。测量：`diag-types-open.json`。

### 改法

`js/admin.js` 里用 `Drupal.behaviors` 和 `once()`。核心给包装加上 `open` 之后，把原来的 `<ul>` 挪到 `body` 末尾的 `#xedc-admin-float`（移动原节点，不复制）。关掉时按原来的顺序放回 `.dropbutton-widget`。主操作和 ▾ 留在单元格里，做成一颗按钮。手机上主操作也进面板。`use-ajax`、对话框、带 token 的链接还是原来的节点。

`detach` 只在 `unload` 时把浮层放回去并拆掉空容器。Views 重新渲染会先 detach 再 attach。

同一时间只开一个。桌面滚动或窗口尺寸变化就关闭。手机是底部面板：遮罩、拖拽条、行标题（该行第一个不是复选框、不是操作列的文字）、48px 选项、含「删除」的项红色并在上面加分割线、取消、`env(safe-area-inset-bottom)`、锁定背景滚动。Esc、点遮罩、点取消都会关，焦点回到 ▾。方向键在打开后移动。▾ 上有 `aria-haspopup="menu"` 和 `aria-expanded`。焦点环仍只在 `data-input="keyboard"` 时出现。

层级：顶栏 100，抽屉 200，下拉 300，弹窗里的下拉 450（否则会被 400 的弹窗盖住）。

按钮：桌面高 32px，手机 44px，圆角 8px，白底 1px 边框。展开时操作单元格背景保持表面色，不再变灰。

### 看过的图

测量在 `a-measure.json`。打开后 `portaled: true`，父节点是浮层；Esc 之后列表回到 `.dropbutton-widget`。单元格背景浅色 `rgb(255,255,255)`，深色 `rgb(32,32,36)`。

- [x] 桌面浮层，第一行：`a-types-first-1366.png`、`a-types-danger-1366.png`（删除是红色）。分类、媒体、视图、内容、人员、字段、区块、菜单、文本格式、图像样式的第一行和最后一行都量过，菜单在视口内，没有被父级裁切。
- [x] 靠近页面底部翻到按钮上方：`a-content-bottom-1366.png`（`flip: up`）。视图列表最后一行、图像样式最后一行、人员页底部也是 `flip: up`。
- [x] 手机底部面板：`a-types-sheet-390.png`。标题「基本页面」，删除红色，上面有分割线，最下面是取消，背后有遮罩。内容列表标题是那一行的标题，不再是「更新此项」。
- [x] 深色：`a-types-dark-1366.png`。手机深色内容列表面板标题正常。
- [x] 表格滑到右侧再展开：手机视图和内容类型是底部面板，量到 `sheet: true`，没有被表格裁切。`a-views-scroll-390.png`。
- [!] 工作区 `/admin/config/workflow/workspaces` 页面上没有 `.dropbutton-toggle`（200，但是没有这种操作菜单）。
- [-] 方向键移动焦点没有单独截图。打开时读到 `aria-expanded=true`、`aria-haspopup=menu`。Esc 关闭后列表回到原处，这一步有测量。

## B 表格左右滑动并显示全部列

### 根因

手机打开 `/admin/structure/views`。改之前的图：`b-before-views-390.png`。测量：`diag-views-390.json`。

1. 外层 `.views-list-section` 其实可以滑：`scrollWidth` 1175，`clientWidth` 358。不是整页被 `overflow: hidden` 锁死。
2. 操作列 `td:has(.dropbutton-wrapper)` 是 `position: sticky; right: 0`，宽约 109px，盖在「机读名称」上面。表头不是粘性的，所以看得到「机读名称」四个字，格子里的机器名被「编辑」挡住。
3. 这一页没有 `display: none` 的 `priority-low` / `priority-medium`，也没有出现「显示所有列」按钮。Radix 没有给表格加 Bootstrap 的 `.table-responsive` 隐藏规则。主题里原先还有一条把 `priority-*` 改成 `table-cell` 的规则，但粘性列才是挡住内容的原因。
4. 另外有规则把带 `.table` 的表强制 `width: 100%`，分类总表用了 `table-layout: fixed`，窄屏区块表被压成 100% 宽。这些会让列变窄而不是往右长出去。

### 改法

操作列不再粘性。手机上表宽 `max-content`，最小 640px，外层 `overflow-x: auto`、`-webkit-overflow-scrolling: touch`、`overscroll-behavior-x: contain`。圆角在外层容器上。`priority-low` 和 `priority-medium` 强制显示，Drupal 的「显示所有列」开关隐藏。

名称列最小 140px。机读名称最小 160px、不换行、等宽 13px。说明最小 220px，文字放进 `.xedc-clamp`，最多两行。操作列最小 120px。

右边还有内容时，容器加上 `has-more-x`，用遮罩在右缘做 28px 淡出。滑到头后类名去掉，淡出消失。搜索框占位文字 `text-overflow: ellipsis`（视图页量到 `textOverflow: ellipsis`）。

分类、角色、区块、文本格式里仍有 `display: none` 的格子，但它们不是 `priority-low` / `priority-medium`（这两类隐藏数是 0）。那些是表格拖拽的权重列，本来就要等「显示行权重」才出现。

### 看过的图

手机 390。测量：`b-measure.json`。滑到最右后 `scrollLeft` 等于最大可滑距离，粘性列数是 0。

- [x] 视图，未滑，右缘有淡出：`b-views-390-desc.png` 能看到说明全文（「查找和管理媒体。」「文章列表页设计」）和路径的开头，右缘发白。`b-views-390-start.png` 能看到名称和机读名称的前半。
- [x] 视图，滑到能看全机读名称：`b-views-390-machine.png`。`media_library`、`article_list`、`content_recent`、`comments_recent` 都是整段，不再是「schedu」。
- [x] 视图，滑到最右：`b-views-390-end2.png`。路径结尾和「编辑」都在。淡出类名变为 false。
- [x] 内容类型、分类、媒体、人员、角色、菜单、区块、文本格式、图像样式、模块、日志都量过：容器能滑，`priority-*` 没有被藏起来，「显示所有列」没有显示。日志滑到表上的图：`b-dblog-390-table.png`。
- [-] 淡出在深色下没有单独截图。遮罩是透明的，露出页面背景，深色应同样能看出右缘变淡。
- [-] 搜索框占位在视图页量到省略号样式，没有单独截一张「省略号已经出现」的图。占位句子比输入框长，起始图里句子停在框的右缘。

## C 弹窗和视图编辑页

- [ ] 未做。先看 `/admin/structure/views/view/...` 里「行样式选项」一类弹窗，以及关闭按钮文字重叠、复选框竖排、左边约 90px 空白。

## D 进度条

根因已经从源码对上，还没打开真实批处理页（本轮授权最多 2 次，还一次都没用）。

Radix 用 `libraries-extend` 换掉了核心的 `Drupal.theme.progressBar`。真实批处理画出来的是：

```html
<div class="progress-wrapper">
  <div class="progress__label"></div>
  <div class="progress">
    <div class="progress-bar">65%</div>
  </div>
  <div class="progress__description"></div>
</div>
```

没有 `data-drupal-progress`，也没有 `.progress__track`、`.progress__percentage`。第六轮的 `progress.css` 只匹配 `.progress[data-drupal-progress]`，所以真实页面只有三段文字。百分比被写进 `.progress-bar` 的文字里。后台没有加载整份 Bootstrap，`.progress` 没有高度和轨道。

非 JS 的核心模板 `progress-bar.html.twig` 才带 `data-drupal-progress`。批处理走的是上面的 JS 主题函数。

- [ ] 样式还没按这个结构改。
- [ ] 真实「运行所有审核」还没打开。地址是 `/admin/reports/audit/run-all`，要 CSRF。每次打开会写一条审计记录。

## E `/node/add`

- [ ] 未做。

## F 扫描器

- [ ] 未做。脚本应放在 `/root/xedc-admin-round7/scan.js`，不要放进项目。

## G 第六轮遗留

- [ ] 未做。前台圆按钮、本地任务、44px 按钮、第六轮没看完的图、`/node/add/article` 底部保存按钮、分类到 404 那些页。
