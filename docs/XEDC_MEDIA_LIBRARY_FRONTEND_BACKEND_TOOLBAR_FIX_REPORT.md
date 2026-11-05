# Media Library 弹窗：前后台工具栏统一与手机端滚动

日期：2026-11-05  
环境：Drupal 11.4.11 / PHP 8.5.12 / 后台主题 xedc_admin（Radix，Gin 色板）  
范围：会员图片投稿「添加或选择媒体」、后台 `/node/add/photo` 的同一弹窗。未改上传规则、UID 过滤、分页逻辑、核心和 config/sync。未 commit、未 push。

## 1. 真实根因

两条入口的 DOM 相同，都是 jQuery UI 模态 `.ui-dialog.media-library-widget-modal`，里面是 `#media-library-wrapper > #media-library-content > 上传表单 + .js-media-library-view`。样式不共享：会员页主题是 `xedc`，后台是 `xedc_admin`。上一轮滚动补丁只挂在 `body.xm-submission-page` 的图片投稿上，后台弹窗根本没加载那段脚本。

手机端滑不动有两层原因，都在打开的弹窗里量到了：

1. Drupal 的 `tua-body-scroll-lock` 把滚动锁加在 `.ui-dialog-content` 上。iPhone 路径里，这个元素自己不能滚时，`touchmove` 会被 `preventDefault()`。媒体列表实际在 `.view-content`（`overflow-y: auto`，`scrollHeight` 大于 `clientHeight`）。锁没有看内部滚动容器，手指滑动被取消。
2. 后台通用对话框规则把所有 `.ui-dialog` 用 `transform: translate(-50%, -50%)` 居中，高度还卡在 `min(90vh, 920px)`。iOS Safari 不会把手指滚动交给「被 transform 过的 fixed 元素」里面的 `overflow` 子节点。Chromium 模拟器没有这个限制，所以只看桌面滚轮或只看 Chromium 会误判。

工具栏不统一也是实测出来的，不是字体大小的问题：

- 会员页和后台各有一套媒体弹窗 CSS，控件高度、分段按钮、上传条互相不一致。
- 后台 `forms.css` 用六个 `:not([type])` 把 `.form-control` 设成 `width: 100%`、`min-height: 40px`。这条特异性压过普通弹窗选择器，名称框被拉满整行，输入框高 40px，排序框高 38px。
- 「仅显示自己上传的文件」是 `xedc_member` 插入的说明段落，不是可切换的筛选控件。筛选由 `hook_views_query_alter` 按 UID 固定执行。把它做成可点击开关会改变权限行为，所以只收成 14px 的只读勾选标记。
- 原生文件控件的「Choose Files / No file chosen」来自浏览器语言，不是站点文案。

## 2. 前后台 DOM 和样式怎么隔离

两边打开弹窗后的结构一致：

```
.ui-dialog.media-library-widget-modal
  .ui-dialog-titlebar
  .ui-dialog-content
    #media-library-wrapper
      #media-library-content
        #media-library-add-form-wrapper
        .js-media-library-view
          .view-header          格子 / 表格
          .view-filters         名称、排序依据、应用过滤器
          .view-content         唯一纵向滚动容器
          .pager
  .ui-dialog-buttonpane         已选数量 + 插入
```

会员页额外有 `p.xm-media-hint`。后台管理员不受限，没有这条说明。Views 暴露项只有名称和排序依据，没有第三组过滤器。

共享样式放在模块库 `xedc_member/media_library_modal`，由两个主题的 `libraries-extend: core/drupal.dialog` 各自引入。选择器全部落在 `.ui-dialog.media-library-widget-modal` 上。会员页不加载 `xedc_admin/dialog.css`，后台不加载 `xedc/dialog-fix.css`。`/admin/content/media` 不是这个弹窗，抽查 HTTP 200、50 行、没有 `.ui-dialog`。

颜色用各自已有变量：后台 `--xedc-admin-*`，前台回退到 Bootstrap 变量。深色由 `html[data-theme="dark"]` 和 `html[data-bs-theme="dark"]` 切换。

## 3. 工具栏改了什么

上传条：说明勾标单独一行（高 16px）。「上传文件」和文件控件同一行（都是 top 一致、高 30px）。限制说明用 11px，完整换行，不再截成省略号。长文件名靠 `min-width: 0` 和 `text-overflow: ellipsis` 留在控件内。允许类型、数量和 10 MB 限制的文案仍是 Drupal 原来的 description。

格子 / 表格：合成一个 30px 高的分段控件，1px 分隔，当前项用实心底，不用粗边框。链接仍是 Drupal 的 `views-display-link`。

名称和排序：桌面同一行。名称框 180×32，排序框 148×32，应用按钮 30px。手机两行：名称通栏，排序和应用同一行。手机输入字号在粗指针下仍是 16px，避免 iOS 聚焦放大；控件高度保持 32px。

底部：已选数量在左，插入按钮在右，间距 12px。

缩略图 `object-fit: contain`。列数：手机 2，480–899px 为 3，900–1199px 为 4，1200–1399px 为 5，≥1400px 为 6。表格视图不套格子网格。

`!important` 只用于两处：压过 jQuery 行内高度和后台 `transform` 居中；压过后台那条 `:not([type])` 的 40px / 100% 宽度。其余控件尺寸用普通声明。

## 4. 手机端滚动容器

唯一滚动的是 `.js-media-library-view .view-content`。

外层 `.ui-dialog`、`.ui-dialog-content`、`#media-library-wrapper`、`#media-library-content`、`.js-media-library-view` 都是 `overflow: hidden` 且 `min-height: 0`。弹窗高度用 `--xedc-ml-h`（`visualViewport`，上限 920，回退 `100dvh`），`transform: none`。工具栏和分页不进滚动区。底栏在 `.ui-dialog-buttonpane`。

脚本 `media-library-modal.js` 在弹窗打开、AJAX 替换和视口变化后：

- 把 `.view-content` 登记进同一套 body scroll lock，让 iPhone 的触摸处理先看到真正的滚动元素。
- 在弹窗上用捕获阶段放行「列表还能滚」的触摸，并在列表边缘把事件交回文档级 `preventDefault`，避免带动背景。
- 滑动超过 8px 后的那一次合成 click 不切换选中。
- 视口高度低于 520px 时收起上传条，搜索框和列表留在视口里。

关闭弹窗后不调用 `unlock()`（iOS 上它会拆掉仍开着的锁）。Drupal 自己的 `clearBodyLocks()` 在关闭时恢复页面。

iPhone 13 模拟（390×664）实测：列表 `clientHeight` 278、`scrollHeight` 2354，触摸后 `scrollTop` 0→593，文档滚动位置保持 619。滚到末尾 `scrollTop` 2075，最后一项在列表视口内。

## 5. 样式隔离

见第 2 节。其他 Dialog、其他 Views 工具栏没有使用 `.media-library-widget-modal` 选择器。后台非媒体对话框仍走 `dialog.css` 里原来的壳。

## 6. 修改文件

- `web/modules/custom/xedc_member/css/media-library-modal.css`（新）
- `web/modules/custom/xedc_member/js/media-library-modal.js`（新）
- `web/modules/custom/xedc_member/xedc_member.libraries.yml`（库 `media_library_modal` 1.1）
- `web/themes/custom/xedc/xedc.info.yml`（dialog 库扩展）
- `web/themes/custom/xedc_admin/xedc_admin.info.yml`（同上）
- `web/themes/custom/xedc/css/dialog-fix.css`（去掉媒体弹窗规则，只留关闭按钮）
- `web/themes/custom/xedc/js/dialog-fix.js`（只留关闭按钮文案）
- `web/themes/custom/xedc/xedc.libraries.yml`（版本号）
- `web/themes/custom/xedc_admin/css/dialog.css`（去掉重复的媒体弹窗块）
- `web/themes/custom/xedc_admin/xedc_admin.libraries.yml`（版本号）

未改 `xedc_member.module` 的 UID 过滤、上传校验和分页。

## 7. 测试结果

浏览器是本机 Playwright Chromium。视口和 UA 使用 iPhone 13、Pixel 5 和 1440×900。没有真机。

| 项目 | 结果 |
| --- | --- |
| 会员手机：打开弹窗，列表触摸上滑，背景不跟着动 | PASS（列表 0→593，文档停在 619） |
| 会员手机：滚到最后一项 | PASS（scrollTop 2075，最后一项可见） |
| 会员手机：搜索 `gachig` | PASS（仍 24 项，列表可滑） |
| 会员手机：分页 | PASS（下一页 24 项，列表可滑） |
| 会员手机：多选两项并插入 | PASS（文案「2 项目已选择」，弹窗关闭，图片字段出现 2 个媒体；未保存节点） |
| 会员手机：关闭后页面可滚动 | PASS（scrollTop 可到 200） |
| 会员手机：视口压到 420px | PASS（上传条隐藏，列表高 142，搜索框在视口内） |
| 会员手机：无横向滚动、控件无重叠 | PASS |
| 会员桌面：鼠标滚轮 | PASS（列表 0→198，页面停在 400） |
| 会员桌面：格子/表格 | PASS（出现 table，再切回格子） |
| 会员桌面：工具栏一行，6 列，名称 180×32，排序 148×32 | PASS |
| 会员深色 | PASS（弹窗底 `#202024`，文字浅色，分段和按钮可辨） |
| 后台手机 iPhone 尺寸：触摸滚动，背景不动 | PASS（0→664，文档保持 0，末尾 2076） |
| 后台 Android Pixel 5 尺寸：触摸滚动 | PASS（0→481，文档保持 0） |
| 后台桌面：搜索、表格切换、6 列、控件 32px | PASS（搜索 24 项） |
| 后台深色 | PASS |
| `/admin/content/media` 不被弹窗样式改掉 | PASS（200，50 行，无弹窗） |
| 真机 iPhone Safari | NOT TESTED |
| 真机 Android Chrome | NOT TESTED |

手机控件（会员，390px）：勾标 128×16 / 11px；文件控件 283×30；格子与表格各 48×28；名称输入 326×32；排序 209×32；应用和插入按钮高 30。后台手机同一套高度。桌面上传条高 57px（后台，无勾标）和 84px（会员，多一行勾标加一行说明）。

## 8. 截图

目录：`docs/tasks/screenshots/xedc-media-library-unified/`

1. `member-desktop.png` 会员桌面工具栏
2. `member-phone.png` 会员手机工具栏
3. `admin-desktop.png` 后台桌面工具栏
4. `admin-phone.png` 后台手机工具栏
5. `member-phone-end.png` 列表滚到最后一行
6. `member-desktop-dark.png`、`admin-desktop-dark.png` 深色

同目录还有搜索、分页、选中、软键盘、表格和 Android 视口图。全部来自 `/node/add/photo` 上打开的真实弹窗。

## 9. 未测和遗留

- 真机 iPhone Safari、真机 Android Chrome：NOT TESTED。模拟器里的 PASS 不能写成真机通过。
- 文件按钮英文「Choose Files」是 Chromium 界面语言，没有改 Drupal 的上传字段。
- 「仅显示自己上传的文件」是只读状态，不是可关闭的筛选。UID 过滤没有改。
- 软键盘用例是把视口改成 390×420，不是系统键盘本身。
- 插入只更新未保存的投稿表单，没有提交节点。

## 10. Git

分支 `main`，HEAD `61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96`。未 commit，未 push。工作区里还有本次之外的未提交改动，没有纳入、也没有回滚。
