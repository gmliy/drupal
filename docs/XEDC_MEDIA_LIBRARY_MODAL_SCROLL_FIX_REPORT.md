# XEDC 默认图片集媒体选择弹窗：滚动、分页与工具栏联合修复报告

**日期：** 2026-11-05  
**分支：** `main`  
**HEAD：** `61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96`  
**结论：** **PASS**  
**未 commit / 未 push**

---

## 1. 本次滚动失效的真实原因

会员投稿页主题为 **`xedc_admin`**（非前台 `xedc`）。

根因不是「没有 overflow:auto」，而是**滚动容器冲突 + jQuery UI 内联高度**：

1. 通用规则曾写：  
   `html.xedc-admin-active .ui-dialog .ui-dialog-content { overflow: auto !important; }`  
   与媒体弹窗需要的 `overflow: hidden`（把滚动留给 `.view-content`）冲突；一旦通用规则抢到滚轮，列表表现为「滚不动」。
2. jQuery UI Dialog 打开后给 `.ui-dialog-content` 写内联 **`height: 0px`**，配合 `height: auto` 的弹窗壳，Flex 链在部分重排/AJAX 后高度计算不稳定。
3. 此前工具栏过高时列表可视区过矮，进一步放大「无法浏览」的观感。

**真正列表滚动容器：** `.ui-dialog .js-media-library-view .view-content`  
父链：`#media-library-wrapper` → `#media-library-content` → `#media-library-view` → `.js-media-library-view`（CSS Grid）均 `min-height:0` + `overflow:hidden`。

修复后实测（1440×900）：`clientHeight=588`，`scrollHeight≈994`，鼠标滚轮可滚到末项；`contentOverflow=hidden`。

---

## 2. 工具栏显示异常的真实原因

1. Radix/Bootstrap `.form-item` 默认 `margin-bottom:16px` + label 在上，筛选区曾高达 ~140px。  
2. 上传区 description 多行 `<br>` + 会员提示「仅显示自己上传的文件」再抬高。  
3. `.ui-dialog-buttonset { width:100% }` 把「已选数量」挤到下一行，底栏 ~97px。  
4. 格子/表格与筛选不在同一水平行。  
5. 通用 titlebar/button 规则（尤其窄屏 44px 按钮）盖过媒体弹窗紧凑目标。

---

## 3. 分页数量过少的真实原因

- 活动配置 `views.view.media_library` **widget/default pager `items_per_page: 24`**（已 ≥16）。  
- 「看起来一页很少」主要来自**工具栏占高 + 无法滚动**，不是 pager 配成个位数。  
- 仍增加 `xedc_member_views_pre_view()`：**若某 display 配置 &lt;20，则抬到 20**；当前 24 保持不变。

实测每页 DOM 项目数：**24**（管理员与会员 uid=2）；搜索后仍 24；翻页后仍可滚。

---

## 4. 实际媒体列表滚动容器

```
.ui-dialog.media-library-widget-modal
  height: min(90vh, 920px) !important   ← 确定高度，避免壳随内容无限长高
  .ui-dialog-content
    overflow: hidden !important         ← 不抢滚轮
    #media-library-wrapper / content / #media-library-view
      .js-media-library-view            ← grid: 工具栏行 | 列表行 | 分页行
        .view-content                   ← ★ overflow-y: auto（唯一列表滚动）
```

配套 JS：`xedc_admin/js/dialog-media.js` 在 `dialog:aftercreate` / AJAX 后清除 jQuery UI 内联 height。

注意：`xedc_admin/dialog` **不能**再依赖 `core/drupal.dialog`（`libraries-extend` 已反向附加），否则库依赖环导致 **PHP OOM 500**（已踩坑并修复）。

---

## 5. 每页最终分页数量及实际查询结果

| 来源 | 值 |
|------|-----|
| View 配置（default/widget） | **24** |
| hook 下限 | 若 &lt;20 则升为 **20** |
| 浏览器实测（admin / member） | **24** 项/页 |
| 搜索 `gachig` 后 | **24**（本库匹配页） |

未改为一次加载全部；仍走 Drupal Media Library AJAX 分页。

---

## 6. 工具栏最终布局和尺寸（桌面 1440×900）

| 区域 | 高度 |
|------|------|
| 标题栏 | **40px**（字号 14px） |
| 上传条 | **58px** |
| 格子/表格 + 筛选同行 | 各 **30px** |
| 列表 `.view-content` | **588px**（约 73% 弹窗） |
| 分页 | **28px** |
| 底栏（已选 + 插入） | **44px** |

控件：输入/下拉/按钮高度 **30px**，字号 **12px**；格子/表格与名称/排序/应用同一行垂直居中；已选数量与插入按钮同一行。

---

## 7. 多列缩略图布局

| 视口 | 实测列数 |
|------|----------|
| 1440 desktop | **6** |
| iPhone 13 模拟 | **2** |

图片：`object-fit: contain`，正方形舞台，不拉伸不裁切。

---

## 8. 会员自己上传媒体的显示情况

- `xedc_member_views_query_alter` 对受限会员仍按 `media_field_data.uid = 当前用户` 过滤（未放开权限）。  
- 会员 uid=2：弹窗 **24** 项，可滚，插入后 `field_images` **0→1**。  
- 无媒体会员（如 uid=81）显示「没有可用媒体」属预期，不是滚动/分页故障。

---

## 9. 桌面端测试结果

| 项 | 结果 |
|----|------|
| 滚轮滚动 / 滚到末项 | PASS |
| content 不抢滚动（overflow:hidden） | PASS |
| 每页 ≥16（实际 24） | PASS |
| 翻页后仍可滚 | PASS |
| 搜索后仍可滚 | PASS |
| 多选「2 项目已选择」 | PASS |
| 工具栏同行、控件 30px | PASS |

截图：`docs/tasks/screenshots/xedc-media-library-scroll/joint-desktop.png`

---

## 10. 手机端测试结果

Playwright iPhone 13：列表 `scrollBy` 可动；列数 2；工具栏允许换行。  
**NOT TESTED：** 真机 iPhone Safari / Android。

---

## 11. 搜索、筛选、分页、多选和确认添加

| 流程 | 结果 |
|------|------|
| 名称/排序/应用过滤器 | PASS（保留） |
| 格子/表格链接 | PASS |
| 分页下一页 | PASS（24 项，可滚） |
| 会员插入添加 | PASS |

---

## 12. Gin 后台回归

`/admin/content/media`：有 listing、无误弹 Dialog → **PASS**。  
通用 Dialog 的 `overflow:auto` 已改为排除 media-library modal。

---

## 13. 未测试项目

1. 真机触控滚动。  
2. 「表格」视图列布局专项。  
3. 超大图库性能。

---

## 14. 修改文件清单

| 文件 | 目的 |
|------|------|
| `web/themes/custom/xedc_admin/css/dialog.css` | 确定弹窗高度；content overflow:hidden；排除通用 overflow:auto；进一步压缩工具栏；view Grid；scrollbar-gutter |
| `web/themes/custom/xedc_admin/js/dialog-media.js` | 清除 jQuery UI 内联 height（新建） |
| `web/themes/custom/xedc_admin/xedc_admin.libraries.yml` | dialog 1.8 + JS；避免依赖环 |
| `web/themes/custom/xedc/css/dialog-fix.css` | 前台备用同步 height |
| `web/themes/custom/xedc/xedc.libraries.yml` | dialog_fix 1.8 |
| `web/modules/custom/xedc_member/xedc_member.module` | `hook_views_pre_view` 分页下限 20 |
| `docs/tasks/screenshots/xedc-media-library-scroll/joint-*` | 验收截图/JSON |
| `docs/XEDC_MEDIA_LIBRARY_MODAL_SCROLL_FIX_REPORT.md` | 本报告 |

---

## 15. 最终 Git 状态（相关）

```
分支: main
HEAD: 61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96

M  web/themes/custom/xedc_admin/css/dialog.css
M  web/themes/custom/xedc_admin/xedc_admin.libraries.yml
M  web/themes/custom/xedc/xedc.libraries.yml
M  web/modules/custom/xedc_member/xedc_member.module
?? web/themes/custom/xedc_admin/js/dialog-media.js
?? web/themes/custom/xedc/css/dialog-fix.css
?? web/themes/custom/xedc/js/dialog-fix.js
?? docs/XEDC_MEDIA_LIBRARY_MODAL_SCROLL_FIX_REPORT.md
?? docs/tasks/screenshots/xedc-media-library-scroll/
```

其他工作区改动原样保留。未执行 destructive git / commit / push。

---

## 最终结论

**PASS**

媒体列表可独立滚到末项；每页实际 **24** 项（≥16，hook 保底 20）；工具栏显著缩小且格子/筛选同行、控件对齐；缩略图 contain 多列保留；会员自有媒体与插入添加正常；Gin 媒体列表页未受影响。
