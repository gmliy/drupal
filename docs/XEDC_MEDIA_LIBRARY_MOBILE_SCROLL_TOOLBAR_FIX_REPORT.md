# XEDC 默认图片集媒体选择弹窗：手机端滚动与工具栏修复报告

**日期：** 2026-11-05  
**分支：** `main`  
**HEAD：** `61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96`  
**页面：** 会员 uid=2（普通会员，无 `administer nodes`）打开 `/node/add/photo`，字段 `field_images` 的「添加或选择媒体」弹窗  
**主题：** 前台 `xedc`（`body.xm-submission-page` + `.xm-submission-form--photo`）。后台 `xedc_admin` / Gin 不参与这个弹窗。  
**未 commit / 未 push**

真机 iPhone Safari 与 Android Chrome **没有**。下面的手机结果是 Playwright + Chromium，使用 iPhone 13 与 Pixel 5 的视口、UA 和触摸。iPhone UA 会走进 Drupal 滚动锁的 iOS 分支，这是本次能复现「手指滑不动」的条件。

---

## 1. 手机端无法滚动的真实根因

上一轮把列表滚动交给 `.view-content`，并把 `.ui-dialog-content` 设成 `overflow: hidden`。桌面滚轮因此正常。会员页实际加载的是前台 `xedc/dialog_fix`，不是 `xedc_admin/dialog`。

Drupal `dialog.js` 在模态打开时执行 `bodyScrollLock.lock(.ui-dialog-content)`（`tua-body-scroll-lock` 1.6.0）。

- **iPhone UA：** 在被锁定的元素上挂 `ontouchmove`。这个元素自己滚不动（`scrollHeight === clientHeight`）时，处理函数对触摸调用 `preventDefault()`。文档上还有一个非 passive 的 `touchmove` 监听，同样 `preventDefault()`。手指在缩略图上滑动时，事件被取消，`.view-content` 的 `scrollTop` 保持 0。页面背后的滚动位置也不变，所以表现为「列表完全滑不动」，不是背景在滑。
- **Android UA / 桌面：** 同一库只锁 `html/body` 的滚动，不 `preventDefault` 内部的 `overflow: auto`。所以 Android 模拟和桌面滚轮在修复前就能滚动，不能用来证明 iPhone 正常。

修复前实测（iPhone 13，390×664，触摸手势）：

| 元素 | clientHeight | scrollHeight | 手势后 scrollTop |
|---|---:|---:|---:|
| `.view-content` | 372 | 2332 | **0** |
| 文档 | 844 视口外已滚到 529 | 2571 | 529（手势期间不变） |

`.view-content` 的 `overflow-y` 已经是 `auto`，高度也是有界的。缺的不是再加一层 `overflow: auto`，而是 iOS 滚动锁取消了触摸。

修复后同一手势：列表 `scrollTop` 0 → 391，文档仍停在 619。连续上滑可到 `scrollTop === scrollHeight - clientHeight`（2077），最后一项进入列表可视区。

## 2. 工具栏显示异常的真实根因

在 390px 宽、修复前的真实 DOM 上量到：

| 控件 | 宽 × 高 | 问题 |
|---|---|---|
| 排序 `<select>` | **91 × 34** | 文字「由新到旧」被裁切，并与 Bootstrap 下拉箭头重叠 |
| 名称输入 | 180 × 34 | 被挤在半行里，没有占满可用宽度 |
| 上传区 | 高 **95** | `.xm-media-hint` 在 `member.css` 里带 `margin-bottom: 10px`，前台弹窗样式没有收它 |
| 筛选区 | 高 **74** | `flex-wrap` 拆成两行，第二行把 select 再压窄 |

选择器原因：移动端规则写了 `min-width: 0` 和 `width: min(100%, 180px)`。表单是可换行的 flex，select 被缩到比文案还窄。`form-select` 右侧还有约 2.25rem 的箭头内边距，剩余文字区大约 40px。

另外两条让「改了 CSS 却看不到」的原因：

1. 会员投稿走 `MemberSubmissionThemeNegotiator`，主题是 `xedc`。`html.xedc-admin-active` 下的 `dialog.css` / `dialog-media.js` **不会加载**。修复前探测里 `contentPatched` 与列表上的 touch 处理函数都是 false。
2. 通用规则 `.ui-dialog:has(#media-library-wrapper)` 的 `:has(#id)` 特异度高于只写 class 的覆盖。它的 `max-height: min(90vh, 920px) !important` 一直赢，弹窗被卡在 90vh。`var()` 的回退值里如果再写一个逗号（`min(920px, calc(...))`），整条 `max-height` 声明会被解析掉，90vh 上限继续生效。

## 3. 修改文件

只动会员图片投稿弹窗会命中的前台资源。文章/视频投稿不带 `.xm-submission-form--photo`，不走这组规则。未改媒体权限、查询、上传、分页配置、Drupal 核心和 `config/sync`。

| 文件 | 改动 |
|---|---|
| `web/themes/custom/xedc/js/dialog-fix.js` | 弹窗打开和 AJAX 替换列表后，对 `.view-content` 调用 `bodyScrollLock.lock()`。包住 `.ui-dialog-content` 上已有的 iOS `ontouchmove`：触摸起点在列表内时 `stopPropagation()`，不再把滑动取消掉。用 `--xedc-ml-h` / `--xedc-ml-top` 跟 `visualViewport` 对齐高度（jQuery UI 会改写 `style.height`，自定义属性不会被清掉）。视口高度 &lt; 520 时加上 `xedc-ml-short`，收起上传条和格子/表格切换，把高度留给搜索框和列表。滑动超过 8px 后的 click 在捕获阶段拦住，避免滑动误选。 |
| `web/themes/custom/xedc/css/dialog-fix.css` | 仅 `body.xm-submission-page:has(.xm-submission-form--photo)` 下的媒体弹窗。高度改为 `var(--xedc-ml-h)`，选择器带 `:has(#media-library-wrapper)` 以压过 90vh。手机筛选改成两行网格：名称输入通栏；排序 select 占据剩余宽度（实测 214px），应用按钮在右侧。控件高 32px。上传提示去掉 10px 下边距。短视口隐藏上传区和视图切换。 |
| `web/themes/custom/xedc/xedc.libraries.yml` | `dialog_fix` 版本 1.8 → 1.9。聚合文件名仍按文件内容哈希更新。 |

`xedc_admin/css/dialog.css` 与 `xedc_admin/js/dialog-media.js` 这次没有再改。它们只在 `xedc-admin-active` 下生效，会员投稿页加载不到。

为让聚合 CSS/JS 使用新哈希，执行过 `drush cr`（缓存重建，没有清库、没有迁移）。

## 4. 手机端滚动容器最终结构

```
.ui-dialog.media-library-widget-modal
  position: fixed
  height / max-height: var(--xedc-ml-h)     视口高度 − 8px，上限 920px
  .ui-dialog-titlebar                       不滚动
  .ui-dialog-content                        overflow: hidden（不是滚动容器）
    #media-library-wrapper
      #media-library-content
        #media-library-add-form-wrapper     不滚动；键盘矮视口时 display:none
        #media-library-view
          .js-media-library-view            grid，min-height: 0，overflow: hidden
            .view-header                    格子 / 表格
            .view-filters                   搜索与排序
            .view-content                   ★ 唯一列表滚动容器
                                            overflow-y: auto
                                            overscroll-behavior: contain
                                            touch-action: pan-y
                                            iOS：bodyScrollLock.lock(此元素)
            .pager                          不滚动，留在列表下方
  .ui-dialog-buttonpane                     已选数量 + 插入，不滚动
```

父级保持 `overflow: hidden` + `min-height: 0`，没有给每一层都加 `overflow: auto`。没有在 `body` 上禁止全部触摸。

iPhone 13 实测（390×664）：

| 层 | 高 | overflow-y | 说明 |
|---|---:|---|---|
| `.ui-dialog` | 656 | hidden | top 4，bottom 660，视口 664 |
| 上传区 | 77 | visible | |
| 筛选 | 70 | visible | 两行，控件 32px |
| `.view-content` | **303** | **auto** | scrollHeight 2380 |
| 分页 | 44 | visible | 在列表下方 |
| 底栏 | 48 | visible | 插入按钮 32×91 |

## 5. 手机端与桌面端测试

环境：Chromium（Playwright 1248），站点 `https://gitxx.xyz`，会员一次登录链接。插入只更新未保存的投稿表单，没有提交节点。

### 手机（模拟）

| 项 | iPhone 13 UA 390×664 | Pixel 5 UA | 结果 |
|---|---|---|---|
| 打开弹窗 | 高 656，未超出视口 | 高 719，未超出视口 | PASS |
| 手指上滑列表 | scrollTop 0→391，文档仍为 619 | scrollTop 0→806，文档仍为 0 | PASS |
| 滑到最后一行 | scrollTop = 2077 = 最大可滚，最后一项在列表盒内 | 同结构，列表可滚 | PASS |
| 背景不跟随 | 手势前后文档 scrollTop 不变 | 同 | PASS |
| 关闭后页面可滚 | 插入后 `scrollTo(0, 200)` 成功，html 未保持滚动锁 | — | PASS |
| 每行缩略图 | 2 | 2 | PASS |
| 横向滚动 | 无 | 无 | PASS |
| 控件重叠 / 溢出 | 无 | 无 | PASS |
| 软键盘（视口压到高 420） | 搜索框在视口内；列表高 174；上传条隐藏 | — | PASS |

### 桌面与平板（模拟）

| 项 | 1440×900 | 900×1200 | 结果 |
|---|---|---|---|
| 打开 | 高 884，top 8，bottom 892 | 列表高 652 | PASS |
| 滚轮 | scrollTop 0→196 | — | PASS |
| 筛选一行 | 高 32，控件 32px，无重叠 | 筛选高 32 | PASS |
| 列数 | 6 | 4 | PASS |
| 深色（`data-bs-theme=dark`） | 弹窗、六列、已选 2 项仍可用 | — | PASS |
| 横向滚动 | 无 | 无 | PASS |

### Gin

`/admin/content/media`（uid=1）：HTTP 200，`html` 含 `xedc-admin-active`，媒体表 50 行，没有误开的 Dialog。PASS。

## 6. 真机

| 设备 | 结果 |
|---|---|
| iPhone Safari 真机 | **NOT TESTED** |
| Android Chrome 真机 | **NOT TESTED** |

模拟覆盖了 iOS 滚动锁分支和 Android 的原生溢出滚动，不能写成真机通过。

## 7. 截图

目录：`docs/tasks/screenshots/xedc-media-library-mobile/`

| 文件 | 内容 |
|---|---|
| `iphone-scrolled.png` | iPhone 视口，列表已上滑，工具栏两行对齐 |
| `iphone-end.png` | 滑到列表底部 |
| `iphone-search.png` | 搜索后 |
| `iphone-pager.png` | 翻页后 |
| `iphone-selected.png` | 多选 |
| `iphone-inserted.png` | 插入后回到投稿表单 |
| `iphone-keyboard.png` | 矮视口：上传条隐藏，搜索框与列表都可见 |
| `android.png` | Pixel 视口，列表已滚动 |
| `desktop.png` | 1440 宽，一行工具栏，6 列 |
| `desktop-dark.png` | 深色 |
| `tablet.png` | 900 宽，4 列 |
| `gin-media.png` | 后台媒体库页 |
| `accept.json` | 上述测量原文 |

工具栏实测（iPhone，无重叠、无横向溢出）：

| 控件 | 宽 × 高 | 字号 |
|---|---|---|
| 格子 / 表格 | 46 × 32 | 12px |
| 名称输入 | 328 × 32 | 16px |
| 排序 select | 214 × 32 | 16px |
| 应用过滤器 | 83 × 32 | 13px |
| 插入选中的 | 91 × 32 | 13px |

桌面同一行：名称输入 180×32（13px），排序 148×32（13px），应用 83×32，插入 91×32。

手机输入框和 select 的字号是 16px，不是 13px。`inputs.css` 在粗指针上对输入控件使用 `font-size: 16px !important`，避免 iOS 聚焦时放大页面把弹窗顶出屏幕。标签和按钮仍是 12–13px，控件高度统一 32px。

图片在方格里按 `object-fit: contain` 完整显示，截图中竖图两侧留白，没有裁切或拉伸。

## 8. 搜索、分页、多选、确认插入

| 步骤 | 结果 |
|---|---|
| 名称搜索 `gachig` 后仍是 24 项，且列表可滑 | PASS |
| 点下一页后仍是 24 项，且列表可滑 | PASS |
| 点选两项，底栏为「2 项目已选择」 | PASS |
| 确认插入：弹窗关闭，表单上出现 2 个已选媒体（未保存节点） | PASS |
| 桌面同样完成搜索、滚轮、多选 | PASS |

## 9. 未解决问题

1. 真机 iPhone Safari、Android Chrome 未测。
2. 无头 Chromium 的文件控件显示英文 “Choose Files / No file chosen”，这是浏览器语言，不是布局。中文系统会显示系统自己的文件按钮文案。
3. 「表格」视图只确认了切换按钮尺寸与可点，没有单独验收表格列。
4. 手机输入字号保持 16px，见第 7 节。

## 10. Git

```
分支: main
HEAD: 61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96
```

本次相关工作区文件（未 commit、未 push）：

```
M  web/themes/custom/xedc/xedc.libraries.yml
?? web/themes/custom/xedc/css/dialog-fix.css
?? web/themes/custom/xedc/js/dialog-fix.js
?? docs/XEDC_MEDIA_LIBRARY_MOBILE_SCROLL_TOOLBAR_FIX_REPORT.md
?? docs/tasks/screenshots/xedc-media-library-mobile/
```

其他模块里已有的未提交改动原样保留。没有执行 `git reset`、`git checkout`、`git clean`、commit 或 push。
