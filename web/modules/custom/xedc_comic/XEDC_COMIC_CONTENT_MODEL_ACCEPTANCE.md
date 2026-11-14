# XEDC Comic — 后台内容模型专项验收报告

**模块**：`xedc_comic`  
**验收时间**：2026-11-14  
**环境**：当前生产 Drupal 站点（Entity API / Drush，非 config/sync YAML）  
**执行命令**：`drush updb -y`（无待执行更新）· `drush cr`

---

## 一、验收方法

1. `entity_field.manager` 列出 `node:comic`、`node:comic_chapter` 全部 `field_*` 定义。
2. 逐字段核对 `FieldStorageConfig`、`FieldConfig`。
3. 加载 `entity_form_display.node.{bundle}.default`、`entity_view_display.node.{bundle}.default`。
4. `entity.form_builder->getForm()` 验证表单子元素是否存在。
5. `renderer->renderRoot($form)` 验证 HTML 中是否出现对应 widget 字段名。
6. 创建分类/标签 + 漫画 + 章节，保存后回读并删除（不保留测试内容）。
7. 未登录 `curl /node/add/*` 返回 403；表单验收以管理员身份 API 渲染为准。

---

## 二、漫画字段验收结果（`comic`）

| 字段机器名称 | 字段存储（类型） | Bundle 绑定 | 表单显示 | Widget | 状态 |
|-------------|------------------|-------------|----------|--------|------|
| field_comic_cover | field_comic_cover (image) | yes | visible | image_image | PASS |
| field_comic_author | field_comic_author (string) | yes | visible | string_textfield | PASS |
| field_comic_category | field_comic_category (entity_reference) | yes | visible | options_select | PASS |
| field_comic_tags | field_comic_tags (entity_reference，基数 -1) | yes | visible | entity_reference_autocomplete_tags | PASS |
| field_comic_description | field_comic_description (text_long) | yes | visible | text_textarea | PASS |
| field_comic_status | field_comic_status (list_string) | yes | visible | options_select | PASS |
| field_comic_update_time | field_comic_update_time (timestamp) | yes | hidden | — | PASS（系统维护） |
| field_comic_sort | field_comic_sort (integer) | yes | visible | number | PASS |
| field_comic_views | field_comic_views (integer) | yes | hidden | — | PASS（系统维护） |
| field_comic_favorites | field_comic_favorites (integer) | yes | hidden | — | PASS（系统维护） |

**Form Display**：`entity_form_display.node.comic.default` 存在，`status = 1`。

**View Display（default）**：含封面、作者、分类、标签、简介、连载状态；阅读量/收藏量/排序/更新时间在默认查看中未配置（指标字段，有意不全贴前台）。

---

## 三、章节字段验收结果（`comic_chapter`）

| 字段机器名称 | 字段存储（类型） | Bundle 绑定 | 表单显示 | Widget | 状态 |
|-------------|------------------|-------------|----------|--------|------|
| field_comic_parent | field_comic_parent (entity_reference → comic) | yes | visible | entity_reference_autocomplete | PASS |
| field_comic_number | field_comic_number (decimal) | yes | visible | number | PASS |
| field_comic_vip | field_comic_vip (boolean) | yes | visible | boolean_checkbox | PASS |
| field_comic_free | field_comic_free (boolean) | yes | visible | boolean_checkbox | PASS |
| field_comic_publish_time | field_comic_publish_time (timestamp) | yes | visible | datetime_timestamp | PASS |
| field_comic_page_count | field_comic_page_count (integer) | yes | hidden | — | PASS（导入/同步维护） |
| field_comic_chapter_status | field_comic_chapter_status (list_string) | yes | visible | options_select | PASS |

**说明**：需求文档中的章节「field_comic_status」在实现中为 **field_comic_chapter_status**，与漫画连载 `field_comic_status` 分离，避免共用 `list_string` 存储导致选项冲突。

**Form Display**：`entity_form_display.node.comic_chapter.default` 存在，`status = 1`。

**View Display（default）**：所属漫画、序号、VIP、免费、发布时间、章节状态。

---

## 四、分类与实体引用

| 检查项 | 结果 |
|--------|------|
| 词汇表 `comic_category` | PASS |
| field_comic_category → comic_category | PASS（handler: default:taxonomy_term） |
| field_comic_tags → tags | PASS（handler: default:taxonomy_term） |
| field_comic_parent → comic | PASS（handler: default:node，target_bundles: comic） |

分类维护入口：`/admin/structure/taxonomy/manage/comic_category/overview`

---

## 五、表单显示验收结果

| 检查项 | 方法 | 结果 |
|--------|------|------|
| Form Display 存在且启用 | Entity API | PASS |
| 漫画可编辑字段在 `$form` 中 | `entity.form_builder`（uid 1） | PASS |
| 章节可编辑字段在 `$form` 中 | 同上 | PASS |
| 渲染 HTML 含业务 widget | `renderRoot($form)` | PASS |

**漫画渲染 HTML 中出现的 field_*（应编辑）**  
`field_comic_author`、`field_comic_category`、`field_comic_cover`、`field_comic_description`、`field_comic_sort`、`field_comic_status`、`field_comic_tags`

**章节渲染 HTML 中出现的 field_*（应编辑）**  
`field_comic_chapter_status`、`field_comic_free`、`field_comic_number`、`field_comic_parent`、`field_comic_publish_time`、`field_comic_vip`

**HTTP 页面**

| URL | 未登录 curl | 说明 |
|-----|-------------|------|
| /node/add/comic | 403 | NOT TESTED（需登录）；与 API 表单验收不矛盾 |
| /node/add/comic_chapter | 403 | NOT TESTED（同上） |

请在后台以具备「创建漫画 / 管理章节」权限的账号强刷后肉眼确认上述页面。

---

## 六、保存与回显

- 创建 `comic_category` 术语、`tags` 术语 → 创建漫画（作者、分类、标签、简介、连载、排序）→ 创建章节（所属漫画、序号、VIP/免费、发布时间、章节状态）→ 回读校验 → **删除节点与术语**。
- 结果：**SAVE_ECHO: PASS**（验收后无残留测试节点）。

---

## 七、历史缺陷与修复（仅标题表单）

**根因**：字段已创建，但 `entity_form_display.node.comic.default` / `comic_chapter.default` 缺失；旧 `ContentInstaller` 仅在 Display 已存在时追加组件。

**修复**：

- `ContentInstaller::repairContentModel()` — 幂等补齐字段 + `syncFormDisplay()` / `syncViewDisplay()`
- `xedc_comic_update_11001()` — 已执行；当前 `drush updatedb:status` 无 pending

---

## 八、修复相关文件清单

| 文件 | 说明 |
|------|------|
| `src/Install/ContentInstaller.php` | 字段与 Form/View Display 幂等修复 |
| `xedc_comic.install` | `xedc_comic_update_11001()` |

本次专项验收 **未再修改代码**（运行状态已满足验收表）。

---

## 九、测试结果汇总

| 项 | 状态 |
|----|------|
| Entity API 字段完整性 | PASS |
| Field Storage / Field Config | PASS |
| Entity Form Display + Widget | PASS |
| Entity View Display（default） | PASS |
| comic_category + 引用 | PASS |
| 表单 API 构建 | PASS |
| 表单 HTML 渲染 | PASS |
| 字段保存与回显 | PASS |
| drush updb / drush cr | PASS |
| 匿名 HTTP /node/add/* | NOT TESTED |

---

## 十、未解决问题

1. **章节状态机读名**：规格 `field_comic_status` vs 实现 `field_comic_chapter_status`（有意分离）；若强制更名需单独迁移方案，验收未执行。
2. **时间字段**：规格 Datetime；实现 **timestamp** + `datetime_timestamp` widget。
3. **默认 View** 未展示阅读量/收藏量/页数等维护字段（设计选择）。
4. **浏览器登录态**新增页未做 HTTP 全页验收，请管理员本地点检。

---

## 十一、维护命令

```bash
cd /var/www/drupal
drush updb -y
drush cr
# 若表单再次被清空，可手动触发修复：
drush php:eval "echo \Drupal::service('xedc_comic.content_installer')->repairContentModel();"
```

---

*关联文档：`XEDC_COMIC_REPORT.md`（模块总体交付说明）*