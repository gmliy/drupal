# XEDC 视频双推荐机制报告

日期：2026-11-05。站点：https://gitxx.xyz。Drupal 11.4.11 / PHP 8.5.12 / Gin 5.0.16。

没有 Git commit / push，没有改 `config/sync/`，没有新建模块，没有安装第三方模块，没有改 Drupal 核心或 contrib。

截图与浏览器结果：`docs/tasks/screenshots/xedc-video-dual-recommend/`（`results.json`：27 PASS / 0 FAIL）。

## 1. 图片推荐机制原有实现

图片内容类型实际是 `photo`（不是 `image`）。

| 字段 | 类型 | 用途 |
| --- | --- | --- |
| `field_slideshow` | boolean | 首页轮播。`HomeCards::slideshow()` 只读 `type=photo` + `status=1` + `field_slideshow=1`，按 `created DESC`。 |
| `field_featured` | boolean | 分类栏目置顶。`image_gallery` 的 `page_3` 通过 `_xedc_home_featured_sort()` 把 `field_featured=1` 排到分类列表前面，不是独立推荐区块。 |

相关代码：

- `web/modules/custom/xedc_home/src/HomeCards.php`
- `web/modules/custom/xedc_home/xedc_home.module`（presave 默认 0、views sort）
- 后台表单：`xedc_admin` 的「展示选项」卡片打印两个复选框
- 会员表单：`xedc_member` 对这两个字段 `#access = FALSE`

结论：图片已有两个独立布尔字段。视频不得把其中一个字段同时当作首页推荐和分类推荐。最低风险扩展是复用同一套 field storage，只给 `video` bundle 挂上独立 field config，并写视频自己的查询/展示逻辑。

## 2. 两个视频推荐字段的机器名称

| 机器名称 | 视频语义 | 默认 |
| --- | --- | --- |
| `field_slideshow` | 设为首页推荐 | 0（不勾选） |
| `field_featured` | 设为当前分类栏目推荐 | 0（不勾选） |

通过 `xedc_core_update_10020()` 挂到 `node.video`：

- 复用已有 `field.storage.node.field_slideshow` / `field_featured`
- 新建 `node.video.*` FieldConfig（视频文案）
- 写入 active form display（checkbox + display_label）
- **未写入** `config/sync/`

## 3. 视频创建和编辑表单接入情况

- Field API 实体字段真实存在，创建/编辑表单都显示。
- `xedc_core_video_recommend_form_alter()` 提供 fieldset「推荐设置」，复选框文案：
  - 设为首页推荐
  - 设为当前分类栏目推荐
- `xedc_admin` 节点表单把该 fieldset 放在「推荐设置」卡片中。
- 浏览器验收：`/node/add/video` 可见两个未勾选复选框；`/node/129/edit` 正确回显。

## 4. 两个字段的独立保存结果

矩阵实测（nid 129/116/54/55）：

| 首页推荐 | 分类推荐 | 结果 |
| --- | --- | --- |
| 开 | 开 | 首页推荐区 + 所属分类推荐区 |
| 关 | 开 | 仅分类推荐区 |
| 开 | 关 | 仅首页推荐区 |
| 关 | 关 | 两个推荐区都不出现 |

浏览器中单独取消首页推荐后重开编辑：首页=关、分类=开，互不影响。PASS。

## 5. 首页推荐查询和展示结果

查询：`VideoRecommend::homeNids()`  
条件：`type=video` + `status=published` + `field_slideshow=1` + entity accessCheck，按 `created DESC`。  
不读仅开启分类推荐的视频。

展示：

1. 站点首页 `/`：在「热门视频」前增加「推荐视频」区块；无数据时整块隐藏。
2. 视频首页 `/video`：推荐视频 → 热门视频 → 最新视频。

实测首页推荐：`129`、`54`。`116`（仅分类推荐）不出现。PASS。

## 6. 分类栏目推荐查询和展示结果

查询：`VideoRecommend::categoryNids($tid)`  
条件：已发布 + `field_featured=1` + `field_categories=$tid` + accessCheck，`created DESC`。

展示：分类页独立「分类推荐视频」区块，下方「全部视频」列表。

`VideoListing` 在无搜索时用 SQL 排除：

- `/video` 排除 `field_slideshow=1`
- 分类视图排除 `field_featured=1`

避免推荐区与普通列表重复，且分页每页条数不因前端隐藏变少。

实测 `/video/category/meinushipin`：推荐区含 129、116；不含仅首页推荐的 54。PASS。

## 7. 多分类兼容情况

`field_categories` 当前 cardinality = **1**（单分类）。  
查询已按多值 join 写法实现；若以后改为多值，开启分类推荐后会在所有所属分类展示。本次未改字段结构。

## 8. 审核工作流兼容情况

推荐字段与 `moderation_state` / `status` 独立保存。公开推荐查询强制 `status=published`，因此：

| 状态 | 首页推荐 | 分类推荐 |
| --- | --- | --- |
| 草稿 / 待审核 / 退回 / 拒绝 / 撤下 | 不公开 | 不公开 |
| 已发布 | 看首页开关 | 看分类开关 |

实测：草稿节点即使两旗都为 1，也不进入 `homeNids` / `categoryNids`。PASS。

## 9. 缓存失效机制

`VideoRecommend` 使用 cache tags：

- `xedc_video_home_recommend`
- `xedc_video_category_recommend:{tid}`
- `xedc_video_hot`（15 分钟 TTL，因播放量变化不触发 node save）
- `xedc_video_latest`
- 另合并 `node_list:video` / 节点 tags

视频 insert/update/delete 调用 `invalidateNode()`：首页旗变化失效首页；分类旗或分类变化失效新旧分类；发布/撤下/删除同步失效。

站点首页匿名页仍保留原有 1 小时过期（`HomeCacheSubscriber`），与热门排序一致。

## 10. 数据库查询性能

- 推荐 nid 列表一次 entityQuery / SQL，再 `VideoCards::build()` 批量 load，避免 Twig 查库。
- 热门用 `node_counter` LEFT JOIN + `COALESCE(totalcount,0)`，再按 `created`。
- 列表排除用一次 LEFT JOIN，不在 Twig 循环过滤。
- Twig 中无数据库查询。

## 11. 图片推荐回归结果

- 首页轮播仍由 `HomeCards::slideshow()` 读 `photo` + `field_slideshow`；浏览器仍见 5 张 slide。PASS。
- `field_featured` 分类置顶逻辑未改。PASS（代码路径保留；本轮未逐页点开全部图片分类）。
- 未删除任何图片字段或数据。

## 12. 真实浏览器截图路径

目录：`docs/tasks/screenshots/xedc-video-dual-recommend/`

| 文件 | 内容 |
| --- | --- |
| `admin-video-create-recommend.png` | 后台创建视频：两个独立复选框默认未勾选 |
| `admin-video-edit-both-checked.png` | 编辑 129：两项均勾选 |
| `admin-video-edit-home-off-cat-on.png` | 仅取消首页推荐后回显 |
| `front-recommended-videos.png` | 站点首页推荐视频 |
| `video-index-sections.png` | `/video` 推荐 / 热门 / 最新 |
| `video-index-mobile.png` | `/video` 移动端 |
| `video-index-dark.png` | `/video` 暗色尝试截图 |
| `category-meinushipin-recommend.png` | 美女视频分类推荐 |
| `category-duanshipin-no-category-recommend.png` | 短视频无分类推荐（仅首页旗） |
| `member-video-create-no-recommend.png` | 普通会员看不到推荐选项 |
| `results.json` | 27 PASS / 0 FAIL |

脚本：`web/modules/custom/xedc_core/tests/manual/browser-video-dual-recommend.mjs`

## 13. 通过、失败和未测试项目

### PASS

- 后台创建/编辑两个独立真实 Field API 复选框，默认不勾选，可分别保存与回显
- 首页推荐只读首页旗；分类推荐只读分类旗 + 当前分类
- 草稿不公开；取消推荐后从对应区域消失（缓存 tag 失效）
- `/video` 三区结构；无旧「最新」大卡标记、无「观看」按钮；卡片统一 16:9
- 分类推荐与列表去重；短视频分类不混入其他分类推荐
- 热门按播放量；最新按时间；与推荐逻辑独立
- 图片轮播回归
- 会员表单隐藏推荐项；`hook_entity_field_access` 对 edit own 返回 forbidden；`presave` 锁定非员工构造写入

### FAIL

无。

### NOT TESTED / BLOCKED

- 未测 JSON:API / REST 端到端伪造（本环境未作为会员 API 入口验收）；已用同权限账号的 Entity save + field access + presave 覆盖构造写入。
- 未对全部图片分类页逐个点开验证 `field_featured` 置顶排序；仅确认 slideshow 查询与首页轮播仍正常。
- 未测视频转码/播放器回归的完整播放链路（本任务未改播放器代码）；列表与详情入口页面可打开。
- 暗色模式截图为强制 `colorScheme`/`data-bs-theme` 尝试，站点若另有主题切换逻辑，视觉一致性以实际主题开关为准。

## 关键文件

| 文件 | 作用 |
| --- | --- |
| `web/modules/custom/xedc_core/src/Video/VideoRecommend.php` | 双推荐查询与缓存失效 |
| `web/modules/custom/xedc_core/src/Video/VideoListing.php` | 列表排除推荐项；热门 COALESCE 排序 |
| `web/modules/custom/xedc_core/xedc_core.install` | `update_10020` 挂字段 |
| `web/modules/custom/xedc_core/xedc_core.module` | 表单文案、字段权限、presave 锁定、缓存失效 |
| `web/modules/custom/xedc_core/xedc_core.services.yml` | 注册服务 |
| `web/modules/custom/xedc_home/xedc_home.module` | video 也默认推荐旗为 0 |
| `web/themes/custom/xedc_admin/*` | 后台推荐设置卡片 |
| `web/themes/custom/xedc/xedc.theme` | 首页/视频页/分类页数据 |
| `web/themes/custom/xedc/templates/views/*` | 推荐区块模板 |
| `web/themes/custom/xedc/templates/includes/home-sections.html.twig` | 站点首页推荐视频 |
| `web/themes/custom/xedc/css/video-list.css` | 区块间距 |
| `web/themes/custom/xedc/xedc.libraries.yml` | library 版本 |
| `web/modules/custom/xedc_core/tests/manual/browser-video-dual-recommend.mjs` | 浏览器验收 |
