# XEDC Search API

前缀：`/api/xedc/search`。成功和失败都是 JSON。

```json
{"ok": true, "data": {}, "error": null}
{"ok": false, "data": null, "error": {"code": "invalid", "message": "关键词过长"}}
```

读接口要求权限 `access content`。关键词最长 100 个字符，更长返回 400，`error.code` 为 `invalid`。搜索关闭时返回 403，`disabled`。同一 IP 在 `flood_window` 秒内超过 `flood_limit` 次读请求返回 429，`rate_limit`。默认 180 次 / 60 秒。页码小于 0 会按 0 处理，大于 1000 会按 1000 处理。输出经 JSON 编码；关键词会去掉标签和控制字符。

查询使用参数绑定，并带 `node_access`。只返回当前用户可看的已发布内容。

## GET /api/xedc/search/suggest

参数：`q`、`type`。`type` 可以是 `all`、`photo`、`video`、`article`、`model`、`category`、`tag`。

`data.groups` 每组最多 `group_limit` 条，默认 4，限制在 3 到 5。组名只有这些：搜索关键词、人物、分类、标签、热门建议。没有命中的组省略。`data.completion` 为 `{ "text": "墨迹2", "suffix": "2" }` 或 `null`。

缓存约 30 秒的私有响应，另有服务端缓存，默认 120 秒。

## GET /api/xedc/search/complete

参数与联想相同。`data.completion` 只含一条真实补全。没有合适前缀时为 `null`。

## GET /api/xedc/search

参数：`q`、`type`、`sort`、`page`、`category`、`tag`、`model`、`from`、`to`。

`sort` 为 `relevance`（默认）、`latest`、`hot`。`from` 和 `to` 用 `Y-m-d`。每页条数来自配置 `page_size`，默认 12，最大 24，不接受客户端随意放大。

内容类型返回 `total`、`counts`（all、photo、video、article）和 `items`。条目含 `id`、`type`、`title`、`url`。人物、分类、标签返回术语 `items`，不返回节点。

实测 `q=墨迹`：`total` 21，其中图片 16、视频 3、文章 2。`q=' OR 1=1 --` 的 `total` 为 0。未发布标题「确认后的标题」的 `total` 为 0。

## GET /api/xedc/search/hot

参数：`window` 为 `today`、`week`、`month`。返回真实统计。停用的管理员关键词不会出现。权重参与排序，不制造假词。

## 历史

| 方法 | 路径 | 谁可以调用 |
| --- | --- | --- |
| GET | `/api/xedc/search/history` | 游客得到 `storage: local` 和空列表。登录用户得到自己的 `storage: account` |
| POST | `/api/xedc/search/history` | 必须登录，并带 `X-CSRF-Token`。正文 `{"q":"","type":"all"}` |
| DELETE | `/api/xedc/search/history/{id}` | 必须登录并带令牌。只能删自己的记录，别人的返回 404 |
| DELETE | `/api/xedc/search/history` | 必须登录并带令牌，清空自己的历史 |

令牌来自 `GET /session/token`。匿名 POST、DELETE 返回 403。登录后不带令牌的 POST 也返回 403，这一支是 Drupal 的 HTML 拒绝页，不是 JSON。

游客不要在页面加载时请求 `/session/token`，否则会创建会话并绕过页面缓存。前台只在登录用户提交时取令牌。

## POST /api/xedc/search/track

记录一次搜索。正文：`q`、`type`、`visitor`、`history`。结果数由服务端重算。`Sec-Fetch-Site` 为 `cross-site` 时返回 403。匿名可以记统计，但不能把 `history` 写成别人的账号历史。同一关键词 60 秒内重复提交时 `counted` 为 false。

## 后台

`/admin/config/xedc-search` 及子页要求 `administer xedc search`。匿名和普通会员实测返回 403。这些页面是 Drupal 表单，不是 JSON API。

## 设置如何生效

`/admin/config/xedc-search/settings` 写入 `xedc_search.settings`。开关、最小长度、防抖、每组条数、缓存秒数、保留天数、热门窗口、页大小和频率限制都会被接口或前台脚本读取。前台通过 `drupalSettings.xedcSearch` 拿到当前值，缓存上下文包含该配置标签。
