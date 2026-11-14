# XEDC Comic 第六阶段 — 开发前审计（简短）

**时间**：2026-11-14  
**路径**：`/var/www/drupal`  
**分支**：`main` @ `a726ee8`（工作区有未提交修改，已保护，未 reset/checkout/clean）

## Git / 工作区

| 项 | 结果 |
|----|------|
| 分支 | `main` |
| 未提交 | `xedc_admin_ui`、`xedc` 主题导航、`xedc_comic/` 整个模块为新增或修改 |
| 约束 | 仅在本阶段改 `xedc_comic` + 必要 `xedc` 主题 |

## 模块与前台（第五阶段基线）

- **路由**：`/comic`、分类/标签、`/comic/{comic}`、`/comic/{comic}/{chapter}`、图片 API、收藏/进度 API；后台 `/admin/xedc/comic/*`
- **服务**：`ComicManager`、`ComicAccessManager`、`ComicReaderManager`、`ComicFavoriteStore`（表 `xedc_comic_favorite`）、`ComicReadingStore`（表 `xedc_comic_reading`）
- **主题模板**：`comic-list` / `comic-card` / `comic-detail` / `comic-reader` + `comic.css` / `reader.js`
- **会员中心**：`xedc_member` 已有 `/member/favorites`、`/member/history`（**不含 comic bundle**）；漫画收藏/阅读史在 **xedc_comic 独立表**

## 菜单 / 导航

- Header：`xedc` 主题 `xedc_nav_build()` 读取 Drupal 菜单 **`main`**
- 漫画入口：`xedc_comic.links.menu.yml` → `xedc_comic.front`（route `xedc_comic.home`）

## Views（审计时）

- 配置安装 YAML 三份；`update_11006` 已执行
- 实体 **`xedc_comic_list` / `_category` / `_tag` 已落库**；当时仅 **default** display，**无 block**

## 生产数据（Drush 抽样）

- 已发布漫画：`nid=1018`（示例）
- 章节：`1019`；标签 `tid=15`
- `comic_category` 术语可能为空（分类页需有数据再验）
- **`system.maintenance_mode=1`** 时匿名 HTTP **503**（站点级，非漫画专属）

## 本阶段实施缺口（审计结论）

1. Views **Block Display** — 待补  
2. 会员 **漫画收藏 / 阅读历史** 前台页 — 待建在 `xedc_comic`（`/member/comic-favorites`、`/member/comic-history`）  
3. 阅读器 UI（章节序号、全屏/重试等）— 部分已有，待统一验收  
4. 真机/暗色目视 — **NOT TESTED**