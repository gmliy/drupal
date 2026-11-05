# XEDC SEO 分析中心：ALT 零 Token 与后台 UI

日期：2026-11-05。生产库未写入，未执行生产 `updb`，未调用 DeepSeek，未做 Git 提交。

## 1. 修改前架构

SEO 仍是原链路：`SeoSuggestForm` 入队，`ReviewRunner` 调现有文字客户端，`ReviewParser::seo` 解析，`SeoResult::score` 在本地计分。结果存在 `xedc_ai_review.result` JSON。采纳走 `SeoApplyForm` 和 `SeoAdvisor`，权限、CSRF、修订冲突和已撤下不自动发布都沿用上一轮。

图片 ALT 存在媒体字段 `field_media_image.alt`，文件在该字段的 `target_id`。内容引用图片的字段是：

| 类型 | 图片字段 |
| --- | --- |
| photo | `field_cover_image`、`field_images` |
| article | `field_cover` |
| video | `field_video_cover` |
| page | 无图片字段 |

`TextExtractor` 只读这些字段上带 `field_media_image` 的媒体。视觉审核接口仍是关闭状态，这次没有打开。

修改前的 ALT 分只看文字长度是否不少于 2。没有缺失、占位、重复、装饰性或文件名规则。详情页没有 ALT 管理区。列表标题仍是「SEO 运营工作台」，统计和筛选把新建分析表单顶在列表前面。

## 2. SEO 分析逻辑变化

文字分析请求没有改字段，也没有更换模型。提示词仍不接收图片二进制。

新分析在成功写入结果时多保存两样东西，都在原 JSON 里：

- `alt_report`：本地检查结果，`tokens` 固定为 0。
- 模型没有提出的 ALT 候选，补进 `items`，键为 `alt:{mid}`，来源 `local_alt`。已有同键建议不重复添加。

旧分析没有 `alt_report` 时，详情页按当前内容当场算一次，并写明这不是当时模型看到的内容。列表打开不会发起新的分析。

## 3. ALT 本地检查

实现在 `AltInspector`。不读文件字节，不访问数据库。

同一内容里同一个 `fid` 只计一次；没有 fid 时按媒体行计。检查结果：

- 缺失：NULL、空字符串、只有空格。
- 异常：占位词、只有扩展名、只有数字、短于 2、长于 125、无意义字符、控制字符。
- 重复：同一内容里后面再次出现的有效 ALT。第一条保留为有效。
- 装饰性：显式标记，或空 ALT 且文件名主干是 spacer、pixel、blank、decoration、divider、icon。装饰性不生成候选，也不进评分分母。

统计有总数、有效、缺失、异常、重复、装饰性、候选数。

## 4. 自动生成规则

已有有效 ALT 不生成候选。候选等于当前文字时也不再生成。

否则：

- video：只用内容标题，不拼序号。
- 文件名有中文，或有至少 3 个字母的实词：标题加文件名主干。
- `IMG_`、`DSC_`、`image`、`photo`、纯数字、长十六进制：不用文件名，改为「标题 - 图片 N」。
- 不拼接标签、栏目、关键词，不描述画面里的人物或场景。

标题改变后，已经有效的 ALT 仍是保留，不会因为新标题重写。

## 5. 图片入口覆盖

读取入口就是上一节的四个字段。ALT 写回 `field_media_image`，并且只写当前内容确实引用、当前用户有权更新的媒体。

分析时用最多 4 条分组查询统计其他内容对同一媒体的引用，写进建议理由。采纳成功后会再数一次，并写进变更记录。共享媒体不会被静默跳过，但必须人工确认后才写入。

实验室里对真实节点 44 做了一次提取，读到 2 条 ALT，没有异常。没有把 500 张图片做成 Drupal 媒体。

## 6. 评分

权重没改：标题 20、描述 20、完整性 20、关键词 15、标签 10、ALT 10、链接 5。模型自报分仍不进入分数。

ALT 仍是 10 分，由本地规则计算：

- 没有 `alts` 键：未检查，不给满分。
- 空列表且不是美图：不适用，不进分母。美图空列表仍是已检查 0 分。这两条保持原测试。
- 有图片时按应填写图片中的有效比例。装饰性不进分母。全部缺失为 0。

## 7. UI 改造

列表页改名为「SEO 分析中心」。顶部有说明和「刷新数据」。五个数字只统计当前这一页：已分析、待处理建议、已采纳、低于 80 分、平均分。

新建分析收进折叠区，打开页面不会开始分析。筛选、搜索、类型、评分和状态仍用原来的 GET 表单。列表默认藏起修订、模型、Token 和费用，避免宽表溢出；这些信息仍在详情里。768 以下表格改成纵向行。

详情增加「图片 ALT」区，文案固定为「本地检查 · 0 Token」，并写明不会识别画面。有缩略占位、文件名、当前值、结果、候选和字段位置。按钮可看全部、只看缺失、只看异常。候选如果还没有对应建议，会出现在下面的建议表里，用原来的采纳和忽略按钮。

深色模式继续用 `html.xedc-admin-active[data-theme="dark"]` 的现有变量。重新发布提示去掉了写死的浅黄底。样式都在 `.xedc-ai` 下。库版本改为 1.5。手机输入框在 767px 以下仍是 16px。

## 8. 布局和组件

没有新的前端主题或 Gin 全局覆盖。列表的主区域是统计和表格，新建分析不再挡在表格前面。ALT 区不用原图 URL，避免大图撑开页面。全选仍是 `type="button"`，不提交表单。

没有做列表批量勾选，也没有新增排序控件。列表顺序仍是分析编号倒序。问题严重程度分组和历史记录沿用原详情结构，没有再拆一套。

## 9. 深色模式

详情页在实验室里把 `data-theme` 设为 dark 后截图。背景、表格、边框和文字跟随管理主题变量。ALT 过滤按钮在深色下是浅底深字，对比足够。没有改 `xedc_admin` 的全局深色规则。列表页没有单独截深色图。

## 10. 视口

实验室 Chromium 实测，页面本身没有横向溢出：

| 视口 | 结果 |
| --- | --- |
| 1440 浅色列表 | 通过 |
| 1280 浅色列表 | 截图无整页横条；修订、模型、Token、费用已隐藏 |
| 768 浅色列表 | 通过 |
| 430 浅色列表 | 通过 |
| 390 浅色列表 | 通过，搜索框 16px |
| 1280 浅色详情 ALT | 截图 |
| 1280 深色详情 ALT | 截图 |
| 390 深色详情 | 通过 |

截图目录：`docs/tasks/screenshots/xedc-ai-seo-alt/`。

## 11. 浏览器交互

隔离站点 `http://127.0.0.1:8096`，登录后实际点击：

- 打开 SEO 分析中心，标题和五张统计卡存在。
- 在 `form.xedc-ai-filters` 里填写不存在的标题并点击「筛选」。页面出现「这个筛选条件下没有 SEO 分析。」
- 打开分析 203。ALT 区显示本地检查、0 Token、4 行。
- 点击「只看缺失」，只剩缺失行，其余 3 行隐藏；再点「全部」。
- 点击「全选待处理建议」，5 条待处理被勾上，已采纳为 0，按钮变为「取消全选」；再点一次，勾选为 0。

没有在这次浏览器里提交采纳、忽略或恢复。注入的 ALT 候选使用媒体编号 9001 起，这些媒体不存在，提交会造成失败写入。标题和简介的采纳、忽略、恢复已在上一份报告里用真实表单提交验证过，这次没有重做。

## 12. 全选

见上一节。按钮类型是 `button`，行为 `xedcAiApply` 已加载。默认不勾选。第一次只勾待处理和采纳失败，已采纳不勾。第二次清空。没有因此产生 POST。

## 13. 批量采纳和恢复

这次浏览器没有提交批量采纳、忽略或恢复。代码路径没改：普通采纳仍保持发布状态和工作流；已撤下没有回到已发布的工作流迁移，确认重新发布仍会拒绝。成功的重新发布仍然做不到，因为 `xedc_editorial` 没有从已撤下出去的迁移，这次也没有改工作流配置。

## 14. ALT 覆盖保护

单元测试覆盖：有效 ALT 无候选、改标题后仍保留、候选与当前值相同时不重复生成、装饰性空 ALT 不填充、同一文件只计一次。

`writeAlt` 在保护模式下会先做本地检查。当前 ALT 有效，或与建议相同，则不保存。恢复原值走同一写入函数，但关闭保护，因此可以把自动值恢复成原来的空值。采纳前仍检查修订冲突和媒体更新权限。

没有在实验室里对一张真实媒体执行「采纳 ALT」或「拒绝覆盖」。共享引用的分组查询写在分析和采纳代码里，这次没有造两个内容共用一张图的数据来点一次。

## 15. 500 张图片性能

纯内存检查 500 条不同 fid：1.272 毫秒，内存增量约 367KB，0 次数据库查询，0 Token。缺失 125，有效 375。这不是 Drupal 实体加载，也没有在生产或实验室创建 500 张媒体。

单节点提取只测了节点 44 的 2 张图。分析时的共享统计最多 4 条 `COUNT DISTINCT` 分组查询，不会按图片逐条查。

## 16. Token

ALT 检查、规则生成、重复判断和异常判断的 `tokens` 都是 0。没有视觉模型请求，没有把图片发给客户端。实验室前后 `xedc_ai_usage` 行数相同。Docker 单元测试全部通过，其中包含 Token 为 0 的断言。

DeepSeek 仍只用于原有文字分析。这次测试没有发真实请求。

## 17. 文件

新增：

- `web/modules/custom/xedc_ai/src/Seo/AltInspector.php`
- `docs/XEDC_AI_SEO_ALT_UI_OPTIMIZATION_REPORT.md`
- `docs/tasks/screenshots/xedc-ai-seo-alt/`

修改：

- `web/modules/custom/xedc_ai/src/Seo/SeoResult.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoAdvisor.php`
- `web/modules/custom/xedc_ai/src/Review/TextExtractor.php`
- `web/modules/custom/xedc_ai/src/Review/ReviewRunner.php`
- `web/modules/custom/xedc_ai/src/Controller/SeoController.php`
- `web/modules/custom/xedc_ai/xedc_ai.module`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/js/ai.js`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `web/modules/custom/xedc_ai/tests/manual/seo-ops-test.php`

## 18. 数据库

不需要升级。`alt_report` 和本地 ALT 建议都在已有 `result` JSON 中。生产 schema 仍是 `xedc_ai` 10002。未执行 `update_10003`。

## 19. 未完成

- 没有用真实媒体点击 ALT 单条采纳、批量采纳、忽略和恢复。
- 没有造共享媒体数据来验收「其他内容也会看到新 ALT」。
- 没有加载 500 个媒体实体，因此没有 Drupal 层的查询次数和缓存失效测量。
- 列表没有批量选择，也没有新的排序控件。
- 问题列表没有按「需要优先处理 / 建议优化 / 可选优化」重新分组。
- 列表深色、1440 深色没有截图。
- 已撤下内容的成功重新发布仍不可用，工作流没有对应迁移。
- 缩略图是文字占位，不是图片样式衍生图。

## 20. 上线注意

新分析才会把 ALT 报告写进结果。旧分析打开详情时会按当前内容算一次，页面会标明它不是当时的分析依据。

采纳 ALT 会改媒体实体。如果其他内容引用同一媒体，那些内容的 ALT 会一起变。有效人工 ALT 不会被保护模式下的采纳覆盖。

不要为此跑生产数据库更新。不要打开视觉模型。列表上的统计不是全站汇总。

## 测试命令和结果

单元测试：

```bash
docker compose -f web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.yml run --rm seo-test
```

结果：`OK 0 failed`。

实验室：生产端口确认为 3306 后，`docker-compose.lab.yml` 项目 `xedc-seo-apply` 在 127.0.0.1:3307 导入只读 dump，`unshare` 挂载临时 settings，关闭实验室里的自动翻译，再跑浏览器。结束后容器已删除。

浏览器结果：`BROWSER OK`。实验室收尾 `AFTER OK`：节点 13 仍是 vid 2383、标题「海边风景手机壁纸高清下载」、未发布、已撤下；用量行数未增加。回到生产后端口仍是 3306，schema 仍是 10002。

500 张内存检查：1.272 毫秒，0 Token。

未通过项：无失败断言。上面第 19 节的项目是没有执行，不能算通过。
