# XEDC 会员中心投稿页面面包屑修复报告

日期：2026-11-05  
环境：Drupal 11.4.11 / PHP 8.5.12 / 前台主题 xedc / 后台 xedc_admin + Gin  
分支：`main` @ `61ccc79ab5887b7cc1e6319ed0f4110d9eec6c96`（未 commit / 未 push）

## 1. 错误原因

会员投稿页复用 Drupal 核心路由 `node.add` / `entity.node.edit_form`。`MemberSubmissionThemeNegotiator` 虽把普通会员切到前台主题 `xedc`，但面包屑仍由 **Easy Breadcrumb**（`priority: 1003`）按后台内容添加路径生成，典型轨迹为：

`首页 > 添加 内容项 > 创建 美图/文章/视频`

因此前台会员投稿页出现后台操作名与内容类型名。不是布局重复输出两套面包屑，也不是 CSS 问题。

## 2. 修改文件

| 文件 | 作用 |
|------|------|
| `web/modules/custom/xedc_member/src/Breadcrumb/MemberSubmissionBreadcrumbBuilder.php` | 新建；仅 `xedc_member_is_submission_page()` 为真时生效 |
| `web/modules/custom/xedc_member/xedc_member.services.yml` | 注册 `xedc_member.submission_breadcrumb`，`priority: 2000`（高于 Easy Breadcrumb 1003） |

未改：核心 / 第三方模块、`config/sync`、Gin、全局 `xedc_preprocess_breadcrumb`、投稿 Controller / Twig。

Builder 逻辑：

1. `投稿中心` → `Link::createFromRoute(..., 'xedc_member.submissions')`（Route API，经既有 `MemberAliasPathProcessor` 出站改写）
2. 当前类型文案 → `<none>`（非链接）
3. `photo` + `upload=archive` → `压缩包投稿`，否则 `图片投稿`；`article` / `video` 对应 `文章投稿` / `视频投稿`

## 3. 投稿中心路由

- Route 名：`xedc_member.submissions`
- 路由 path：`/member/submissions`
- 测试账号 uid 2（slug `12shh`）出站规范 URL：`/12shh/submissions`（既有别名处理器，非硬编码）

## 4. 四个投稿页显示结果

| 页面 | URL | 面包屑 | 结果 |
|------|-----|--------|------|
| 文章投稿 | `/node/add/article` | 投稿中心 → `/12shh/submissions`；文章投稿（文本） | PASS |
| 图片投稿 | `/node/add/photo` | 投稿中心 → `/12shh/submissions`；图片投稿（文本） | PASS |
| 压缩包投稿 | `/node/add/photo?upload=archive` | 投稿中心 → `/12shh/submissions`；压缩包投稿（文本） | PASS |
| 视频投稿 | `/node/add/video` | 投稿中心 → `/12shh/submissions`；视频投稿（文本） | PASS |

四个页面均只出现 **1** 个 `.breadcrumb`；无「添加 内容项」、无内容类型后台名。

## 5. 「投稿中心」点击

登录会员打开 crumb 链接 `/12shh/submissions`：

- HTTP 200，标题「我的投稿」
- 会员中心 `xm-page` 投稿首页
- 非后台内容管理、非个人资料首页

**PASS**

## 6. 回归

| 场景 | 结果 |
|------|------|
| 会员中心其他页（如 `/12shh/favorites`） | 未出现「投稿中心」crumb；该区本就隐藏系统面包屑。**PASS**（投稿 Builder 未误伤） |
| 后台 uid 1 `xedc_admin` `/node/add/photo` | 仍为 `首页 > 添加 内容项 > 创建 美图`；无「投稿中心」。**PASS** |
| 前台已发布 photo `/node/804` | 普通详情 crumb，无「投稿中心」。**PASS** |
| 前台 article `/node/146`、video `/node/129` | 页面无「投稿中心」；article/video 详情本身常无可见 breadcrumb（既有主题行为）。**PASS**（未引入投稿 crumb） |
| 桌面 / 手机布局 | 沿用现有 radix `breadcrumb` + 投稿页布局，未改 CSS。结构验收 **PASS**；像素级多端截图 **NOT TESTED** |
| 深色模式 | 未改面包屑样式变量；逻辑与浅色相同。视觉 **NOT TESTED** |

## 7. Git 修改清单（本任务）

```
M  web/modules/custom/xedc_member/xedc_member.services.yml
?? web/modules/custom/xedc_member/src/Breadcrumb/MemberSubmissionBreadcrumbBuilder.php
?? docs/XEDC_MEMBER_BREADCRUMB_FIX_REPORT.md
```

工作区其他未提交改动均保留，未 reset / checkout / clean / commit / push。

## 8. 未完成 / NOT TESTED

- 桌面端、手机端、深色模式的截图像素验收：**NOT TESTED**（HTML 结构已验）
- 会员编辑已有稿件 `entity.node.edit_form` 面包屑：Builder 已按同一 `xedc_member_is_submission_page()` 覆盖，**未做浏览器实点**
- Drupal 核心 Gin 默认主题下的 breadcrumb 模板未单独打开；本站后台为 `xedc_admin`，已验其仍显示后台轨迹
- 页面标题块仍可能显示核心「创建 美图/文章/视频」（非面包屑）；本次未改 page title，表单内 `xm-submit__title` 仍为投稿文案
