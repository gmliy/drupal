# 第八轮：发布内容表单

只改 `web/themes/custom/xedc_admin/`。不改表单显示配置、不改模块、不改 `#parents` / `#group`。

## 调查

真实 theme hook 是 `node_form`（`#theme` 为 `node_article_form`、`node_form` 这一列）。核心 `node-edit-form.html.twig` 不会被用到。主题原先没有节点表单模板。

`#group` 在渲染前把子元素收进父级，模板里要打印父级，不能再打印子级，否则会丢或重复：

- `advanced`（垂直标签）：状态 `meta`、菜单 `menu`、XML 站点地图 `simple_sitemap`、修订 `revision_information`、URL 别名（path 小部件）、编著 `author`、推荐选项 `options`、评论（美图 `field_comment` 小部件）
- `footer`：保存为 `moderation_state`、已发布 `status`、IndexNow
- `scheduler_settings`（文章；同一次请求里连续构建会变成 `_2` `_3`，真实页面只有一个）：定时发布，里面是 `publish_on`
- `actions`：保存、预览
- 隐藏字段：`changed`、`form_build_id`、`form_token`、`form_id`，以及垂直标签的 `advanced__active_tab`

未分组、按内容类型排在主栏的字段：

| 类型 | 主栏顺序 |
| --- | --- |
| article | title，field_cover，field_summary，field_body，field_categories，field_tags，field_source，field_source_url |
| video | title，field_video_cover，field_video_file，field_video_url，field_duration，field_body，field_categories，field_tags |
| photo | title，field_cover_image，field_images，xedc_import，field_summary，field_categories，field_model，field_tags，field_featured，field_slideshow |
| 其他 | title，field_body |

没点名的字段进「更多选项」。文章的发布时间、作者、阅读量、正文页显示封面走这里。

现有节点：article 7，video 6，photo 1，page 49。

基线（改模板前，控件 name / id / data-drupal-selector）：

- `/root/xedc-admin-round8/baseline-article.json` 123
- `/root/xedc-admin-round8/baseline-video.json` 120
- `/root/xedc-admin-round8/baseline-photo.json` 115

`form_build_id` 每次打开都会变，对比时不计这一条。

## 实现

- `templates/form/node-form.html.twig`
- `xedc_admin_theme()` 注册 `node_form`
- `xedc_admin_form_node_form_alter()` 只改折叠是否默认打开、开关类名。垂直标签的 `#type` 保持不变，预渲染里去掉 `core/drupal.vertical-tabs`，避免详情被收成单开标签；`advanced__active_tab` 仍在
- `css/node-form.css`

## 进度

- [x] 调查写进本文件
- [x] 三种添加页控件基线
- [x] 文章添加页桌面 1366 浅色看过：`/root/xedc-admin-round8/article-1366-top.png`。左栏封面、摘要、正文；右栏发布卡片在上，定时发布和其余折叠在下。标题输入实测 56px、22px、字重 600、底边 1px（空的时候截图说明容易漏掉）
- [x] 文章手机 390 看过：`article-390-top.png`。主栏在内部滚动，发布卡片在视口里（保存、预览都在）。卡片大约 189px，比要求的 56px 高，因为里面还有「保存为」和说明
- [ ] 文章：滚到中部和底部、1920、深色、展开折叠、媒体库、明天按钮、继续添加。控件对比要在这版模板上再导一次
- [ ] 视频、美图、基本页面、三个编辑页
- [ ] 报告

文章控件对比（改模板后那一版，不计 form_build_id / form_token）是空的。后来只动了排版，没有再对过，收尾前要重导。

## 下一批从这里继续

1. 看 `article-390-top.png`：发布卡片压在「文章摘要简介」标签下面，确认内部滚动能把正文和「更多选项」滚出来，卡片不要挡住正文。
2. 侧栏「编著信息」如果「息」还单独换行，把摘要改成省略号（`details.xedc-fold > summary` 已设 nowrap）。
3. 设置面板用了 `margin-top: 210px` 让开发布卡片，卡片一变高就会叠住。改成不靠魔法数字的侧栏。
4. 然后视频、美图（美图要看日期框还溢不溢出）、`/node/add/page`、编辑页 `/node/7/edit`、`/node/6/edit`、`/node/1/edit`。
5. 不要点保存、预览、移除、删除。不要改模块和配置。
